<?php

namespace Tests\Feature;

use App\Jobs\CheckLowStock;
use App\Jobs\MonitoringQueueProbe;
use App\Models\MonitoringEvent;
use App\Models\SystemHeartbeat;
use App\Models\Tenant;
use App\Models\User;
use App\Services\MonitoringService;
use Carbon\CarbonImmutable;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Mockery;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class MonitoringServiceTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function administrator(): User
    {
        return User::factory()->create(['tenant_id' => Tenant::factory(), 'role' => 'admin']);
    }

    private function checks(array $report): array
    {
        return collect($report['checks'])->keyBy('key')->all();
    }

    public function test_live_checks_keep_existing_cache_and_private_files_and_only_verify_actual_fresh_heartbeats(): void
    {
        $this->freezeTime();
        $user = $this->administrator();
        Storage::fake('local');
        Storage::disk('local')->put('business/receipt.txt', 'Existing receipt');
        Cache::put('existing-business-value', 'Keep me', 600);
        config(['queue.default' => 'database']);
        $service = app(MonitoringService::class);
        $service->heartbeat('worker', 'database', 'default');
        $service->heartbeat('scheduler');

        $report = $service->snapshot($user);

        $this->assertSame('healthy', $report['status']);
        $this->assertSame(['database', 'cache', 'private_storage', 'log_storage', 'worker', 'scheduler'], array_keys($this->checks($report)));
        $this->assertSame(['healthy'], array_values(array_unique(array_column($report['checks'], 'status'))));
        $this->assertSame('background', $report['queue']['mode']);
        $this->assertSame('Existing receipt', Storage::disk('local')->get('business/receipt.txt'));
        Storage::disk('local')->assertDirectoryEmpty('monitoring');
        $this->assertSame('Keep me', Cache::get('existing-business-value'));
        $this->assertSame([], $report['incidents']);
        $this->assertSame([], $report['probes']);
        $this->assertDatabaseCount('monitoring_events', 0);
        $this->assertDatabaseCount('system_heartbeats', 2);
    }

    public function test_worker_heartbeat_matches_actual_connection_and_listened_queue_without_web_reads_marking_it(): void
    {
        $this->freezeTime();
        $this->administrator();
        Storage::fake('local');
        config(['queue.default' => 'database']);
        $service = app(MonitoringService::class);
        $service->heartbeat('worker', 'redis', 'default');
        $service->heartbeat('worker', 'database', 'other');

        $before = $this->checks($service->systemReport());
        $this->assertSame('warning', $before['worker']['status']);
        $this->assertNull($before['worker']['last_seen_at']);
        $this->assertDatabaseCount('system_heartbeats', 2);

        $service->heartbeat('worker', 'database', 'other,default');

        $after = $this->checks($service->systemReport());
        $this->assertSame('healthy', $after['worker']['status']);
        $this->assertSame(0, $after['worker']['age_seconds']);
        $this->assertDatabaseCount('system_heartbeats', 3);
    }

    public function test_heartbeat_writes_are_throttled_then_refresh_and_eventually_become_stale(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-11 12:00:00', 'Asia/Manila'));
        $this->administrator();
        Storage::fake('local');
        config(['queue.default' => 'database']);
        $service = app(MonitoringService::class);
        $service->heartbeat('worker', 'database', 'default');
        $this->travel(10)->seconds();
        $service->heartbeat('worker', 'database', 'default');

        $this->assertSame(10, $this->checks($service->systemReport())['worker']['age_seconds']);

        $this->travel(5)->seconds();
        $service->heartbeat('worker', 'database', 'default');
        $this->assertSame(0, $this->checks($service->systemReport())['worker']['age_seconds']);
        $this->travel(91)->seconds();

        $stale = $this->checks($service->systemReport())['worker'];
        $this->assertSame('warning', $stale['status']);
        $this->assertSame(91, $stale['age_seconds']);
        $this->assertSame(90, $stale['stale_after_seconds']);
        $this->assertStringContainsString('stale', $stale['summary']);
        $this->assertDatabaseCount('system_heartbeats', 1);
    }

    public function test_heartbeat_raw_storage_timezone_and_future_clock_skew_are_explicit(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-11 12:00:00', 'Asia/Manila'));
        $this->administrator();
        Storage::fake('local');
        config(['app.timezone' => 'UTC', 'queue.default' => 'database']);
        $heartbeat = SystemHeartbeat::factory()->worker()->create(['last_seen_at' => '2026-10-11 03:59:30']);
        $service = app(MonitoringService::class);

        $worker = $this->checks($service->systemReport())['worker'];
        $this->assertSame('healthy', $worker['status']);
        $this->assertSame(30, $worker['age_seconds']);
        $this->assertSame('2026-10-11T03:59:30+00:00', $worker['last_seen_at']);

        $heartbeat->update(['last_seen_at' => '2026-10-11 04:05:00']);

        $future = $this->checks($service->systemReport())['worker'];
        $this->assertSame('warning', $future['status']);
        $this->assertNull($future['age_seconds']);
        $this->assertStringContainsString('ahead', $future['summary']);
    }

    public static function unverifiedQueueModes(): array
    {
        return [
            'inline sync' => ['sync', 'inline', 'unverified'],
            'deferred' => ['deferred', 'background', 'unverified'],
            'background request' => ['background', 'background', 'unverified'],
            'failover' => ['failover', 'background', 'unverified'],
            'missing configuration' => ['missing', 'disabled', 'warning'],
            'null driver' => ['disabled', 'disabled', 'warning'],
        ];
    }

    #[DataProvider('unverifiedQueueModes')]
    public function test_non_dedicated_queue_modes_never_claim_a_verified_worker(string $connection, string $mode, string $status): void
    {
        $this->freezeTime();
        $user = $this->administrator();
        Storage::fake('local');
        config(['queue.default' => $connection, 'queue.connections.disabled' => ['driver' => 'null']]);
        $service = app(MonitoringService::class);
        $service->heartbeat('worker', $connection, 'default');

        $report = $service->snapshot($user);

        $this->assertSame($mode, $report['queue']['mode']);
        $this->assertSame($status, $this->checks($report)['worker']['status']);
        $this->assertSame('warning', $report['status']);
    }

    public function test_exception_recording_keeps_only_safe_class_integer_code_and_generic_summary(): void
    {
        $user = $this->administrator();
        $service = app(MonitoringService::class);
        $secret = 'password=private-token /srv/private/request.json';

        $service->recordException(new RuntimeException($secret, 7), $user->tenant_id);
        $service->recordException(new RuntimeException($secret), null);
        $service->recordException(new RuntimeException($secret, PHP_INT_MAX), $user->tenant_id);
        $service->recordException(new class($secret) extends PDOException
        {
            public function __construct(string $message)
            {
                parent::__construct($message);
                $this->code = 'HY000';
            }
        }, $user->tenant_id);

        $events = MonitoringEvent::orderBy('id')->get();
        $this->assertCount(3, $events);
        $this->assertSame('RuntimeException', $events[0]->error_class);
        $this->assertSame(7, $events[0]->error_code);
        $this->assertSame('RuntimeException', $events[1]->error_class);
        $this->assertNull($events[1]->error_code);
        $this->assertSame('Exception', $events[2]->error_class);
        $this->assertNull($events[2]->error_code);
        $this->assertSame('An application request failed.', $events[0]->summary);
        $this->assertStringNotContainsString($secret, $events->toJson());
        $this->assertStringNotContainsString(__DIR__, $events->toJson());
        $this->assertArrayNotHasKey('tenant_id', $events[0]->toArray());
        $this->assertArrayNotHasKey('pending_scope', $events[0]->toArray());
    }

    public function test_job_failure_recorder_ignores_unrecognized_job_classes(): void
    {
        $user = $this->administrator();
        $service = app(MonitoringService::class);

        $service->recordJobFailure(CheckLowStock::class, $user->tenant_id);
        $service->recordJobFailure('PrivateJobWithSecret', $user->tenant_id);

        $this->assertDatabaseCount('monitoring_events', 1);
        $this->assertDatabaseHas('monitoring_events', ['tenant_id' => $user->tenant_id, 'kind' => 'queue_failure', 'error_class' => 'CheckLowStock', 'summary' => 'A stock replenishment background job failed.']);
    }

    public function test_monitoring_recorders_fail_safely_when_monitoring_tables_are_unavailable(): void
    {
        $user = $this->administrator();
        Storage::fake('local');
        config(['queue.default' => 'database']);
        Schema::drop('monitoring_events');
        Schema::drop('system_heartbeats');
        $service = app(MonitoringService::class);

        $service->recordException(new RuntimeException('Private outage message'), $user->tenant_id);
        $service->recordJobFailure(CheckLowStock::class, $user->tenant_id);
        $service->heartbeat('worker', 'database', 'default');
        $worker = $this->checks($service->systemReport())['worker'];

        $this->assertSame('warning', $worker['status']);
        $this->assertSame('The worker heartbeat could not be checked.', $worker['summary']);
        $this->assertNull($worker['last_seen_at']);
        $this->assertStringNotContainsString('SQL', json_encode($worker, JSON_THROW_ON_ERROR));
    }

    public function test_sync_probe_completes_without_fabricating_a_worker_heartbeat(): void
    {
        $this->freezeTime();
        $user = $this->administrator();
        Storage::fake('local');
        config(['queue.default' => 'sync']);
        $service = app(MonitoringService::class);

        $result = $service->probe($user);

        $this->assertSame('passed', $result['probe']['status']);
        $this->assertFalse($result['reused']);
        $this->assertFalse($result['probe']['stale']);
        $this->assertSame(60, $result['cooldown_remaining_seconds']);
        $this->assertNotNull($result['probe']['completed_at']);
        $this->assertSame('unverified', $this->checks($service->snapshot($user))['worker']['status']);
        $this->assertDatabaseCount('system_heartbeats', 0);
        $this->assertDatabaseCount('monitoring_events', 1);
    }

    public function test_stale_pending_probe_is_reused_without_adding_jobs_and_duplicate_execution_cannot_rewrite_completion(): void
    {
        $this->freezeTime();
        $user = $this->administrator();
        Queue::fake([MonitoringQueueProbe::class]);
        config(['queue.default' => 'database']);
        $service = app(MonitoringService::class);
        $first = $service->probe($user);
        $this->travel(301)->seconds();

        $retry = $service->probe($user);

        $this->assertTrue($retry['reused']);
        $this->assertSame($first['probe']['id'], $retry['probe']['id']);
        $this->assertTrue($retry['probe']['stale']);
        $this->assertSame('pending', $retry['probe']['status']);
        Queue::assertPushed(MonitoringQueueProbe::class, 1);
        $job = new MonitoringQueueProbe($user->tenant_id, $first['probe']['id']);
        $job->handle($service);
        $completed = MonitoringEvent::findOrFail($first['probe']['id'])->getRawOriginal('completed_at');
        $this->travel(5)->seconds();
        $job->handle($service);
        $job->failed(new RuntimeException('Sensitive failure'));

        $this->assertDatabaseHas('monitoring_events', ['id' => $first['probe']['id'], 'status' => 'passed', 'completed_at' => $completed, 'pending_scope' => null]);
        $this->assertDatabaseCount('monitoring_events', 1);
    }

    public function test_dispatch_failure_is_a_safe_failed_probe_and_single_incident_with_cooldown(): void
    {
        $this->freezeTime();
        $user = $this->administrator();
        config(['queue.default' => 'database']);
        Bus::shouldReceive('dispatch')->once()->with(Mockery::type(MonitoringQueueProbe::class))->andThrow(new RuntimeException('redis://secret@private-host'));
        $service = app(MonitoringService::class);

        $result = $service->probe($user);
        $service->failProbe($user->tenant_id, $result['probe']['id']);

        $this->assertSame('failed', $result['probe']['status']);
        $this->assertSame(60, $result['cooldown_remaining_seconds']);
        $this->assertNull($result['probe']['error_class']);
        $this->assertDatabaseCount('monitoring_events', 2);
        $this->assertDatabaseHas('monitoring_events', ['kind' => 'queue_failure', 'summary' => 'A diagnostic queue probe failed.']);
        $this->assertStringNotContainsString('private-host', MonitoringEvent::all()->toJson());
    }

    public function test_probe_job_cannot_complete_or_fail_another_tenants_diagnostic(): void
    {
        $user = $this->administrator();
        $foreign = MonitoringEvent::factory()->pendingProbe()->create();
        $service = app(MonitoringService::class);

        (new MonitoringQueueProbe($user->tenant_id, $foreign->id))->handle($service);
        $service->failProbe($user->tenant_id, $foreign->id);

        $this->assertSame('pending', $foreign->refresh()->status);
        $this->assertNull($foreign->completed_at);
        $this->assertDatabaseCount('monitoring_events', 1);
    }

    public function test_live_infrastructure_failures_show_only_generic_safe_summaries(): void
    {
        $this->administrator();
        Storage::fake('local');
        config(['queue.default' => 'sync']);
        Cache::shouldReceive('put')->once()->andThrow(new RuntimeException('password=secret cache connection'));
        Cache::shouldReceive('forget')->once()->andReturn(true);
        File::shouldReceive('isDirectory')->once()->andReturn(false);

        $report = app(MonitoringService::class)->systemReport();

        $checks = $this->checks($report);
        $this->assertSame('critical', $report['status']);
        $this->assertSame('critical', $checks['cache']['status']);
        $this->assertSame('critical', $checks['log_storage']['status']);
        $this->assertStringNotContainsString('password=secret', json_encode($report, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString(storage_path(), json_encode($report, JSON_THROW_ON_ERROR));
    }

    public function test_simulation_labels_never_claim_external_provider_connectivity_and_disable_outside_local(): void
    {
        $user = $this->administrator();
        Storage::fake('local');
        config(['billing.simulations_enabled' => true, 'commerce.simulations_enabled' => true]);
        $service = app(MonitoringService::class);

        $local = $service->snapshot($user);
        $this->assertSame(['simulation', 'simulation'], array_column($local['integrations'], 'status'));
        $this->app->detectEnvironment(fn (): string => 'production');

        $production = $service->snapshot($user);
        $this->assertSame(['disabled', 'disabled'], array_column($production['integrations'], 'status'));
        $this->assertStringContainsString('No external provider is connected.', $production['integrations'][0]['summary']);
    }

    public function test_paymongo_health_reports_configuration_without_provider_requests_or_secret_exposure(): void
    {
        $user = $this->administrator();
        Storage::fake('local');
        Http::preventStrayRequests();
        Http::fake();
        config(['billing.payment_provider' => 'paymongo', 'paymongo.enabled' => true, 'paymongo.mode' => 'test',
            'paymongo.secret_key' => '', 'app.url' => 'http://127.0.0.1:8010']);

        $unconfigured = app(MonitoringService::class)->snapshot($user);
        $this->assertSame('disabled', $unconfigured['integrations'][1]['status']);
        $this->assertStringContainsString('Configure a PayMongo test secret key', $unconfigured['integrations'][1]['summary']);
        config(['paymongo.secret_key' => 'sk_test_private_fixture']);
        $configured = app(MonitoringService::class)->snapshot($user);
        $this->assertSame('test', $configured['integrations'][1]['status']);
        $this->assertStringContainsString('Provider availability is checked when a payment is requested', $configured['integrations'][1]['summary']);
        $this->assertStringNotContainsString('sk_test_private_fixture', json_encode($configured, JSON_THROW_ON_ERROR));
        Http::assertNothingSent();
    }

    public function test_prune_removes_only_expired_monitoring_events_and_keeps_boundary_and_heartbeat_evidence(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-11 12:00:00', 'Asia/Manila'));
        $user = $this->administrator();
        $expired = MonitoringEvent::factory()->create(['tenant_id' => $user->tenant_id, 'created_at' => '2026-09-11 11:59:59']);
        $boundary = MonitoringEvent::factory()->create(['tenant_id' => $user->tenant_id, 'created_at' => '2026-09-11 12:00:00']);
        $heartbeat = SystemHeartbeat::factory()->create(['last_seen_at' => '2026-09-01 12:00:00']);

        $pruned = app(MonitoringService::class)->prune();

        $this->assertSame(1, $pruned);
        $this->assertModelMissing($expired);
        $this->assertModelExists($boundary);
        $this->assertModelExists($heartbeat);
        $this->assertModelExists($user);
    }

    public function test_repaired_queue_with_acknowledged_old_failure_and_new_passed_probe_returns_healthy(): void
    {
        $this->freezeTime();
        $user = $this->administrator();
        Storage::fake('local');
        config(['queue.default' => 'database']);
        $service = app(MonitoringService::class);
        $service->heartbeat('worker', 'database', 'default');
        $service->heartbeat('scheduler');
        MonitoringEvent::factory()->create(['tenant_id' => $user->tenant_id, 'kind' => 'queue_probe', 'status' => 'failed']);
        MonitoringEvent::factory()->create(['tenant_id' => $user->tenant_id, 'kind' => 'queue_failure', 'acknowledged_at' => now()]);
        MonitoringEvent::factory()->create(['tenant_id' => $user->tenant_id, 'kind' => 'queue_probe', 'status' => 'passed', 'completed_at' => now()]);

        $report = $service->snapshot($user);

        $this->assertSame('healthy', $report['status']);
        $this->assertSame(1, $report['counts']['failed_probes']);
        $this->assertSame(1, $report['counts']['acknowledged_incidents']);
        $this->assertSame(0, $report['counts']['open_incidents']);
        $this->assertSame('passed', $report['probes'][0]['status']);
    }

    public function test_old_outstanding_probe_remains_visible_prioritized_and_reused_after_retention_window(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-11 12:00:00', 'Asia/Manila'));
        $user = $this->administrator();
        Storage::fake('local');
        Queue::fake([MonitoringQueueProbe::class]);
        config(['queue.default' => 'database']);
        $pending = MonitoringEvent::factory()->pendingProbe()->create(['tenant_id' => $user->tenant_id, 'created_at' => '2026-08-01 12:00:00']);
        MonitoringEvent::factory()->count(51)->create(['tenant_id' => $user->tenant_id, 'kind' => 'queue_probe', 'status' => 'passed']);
        $service = app(MonitoringService::class);
        $service->heartbeat('worker', 'database', 'default');
        $service->heartbeat('scheduler');

        $service->prune();
        $report = $service->snapshot($user);
        $retry = $service->probe($user);

        $this->assertSame(1, $report['counts']['pending_probes']);
        $this->assertCount(50, $report['probes']);
        $this->assertSame($pending->id, $report['probes'][0]['id']);
        $this->assertTrue($report['probes'][0]['stale']);
        $this->assertSame('warning', $report['status']);
        $this->assertTrue($retry['reused']);
        $this->assertSame($pending->id, $retry['probe']['id']);
        Queue::assertNotPushed(MonitoringQueueProbe::class);
        $this->assertModelExists($pending);
    }

    public function test_unpruned_failed_probe_outside_retention_does_not_warn_while_hidden_from_history(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-11 12:00:00', 'Asia/Manila'));
        $user = $this->administrator();
        Storage::fake('local');
        config(['queue.default' => 'database']);
        MonitoringEvent::factory()->create(['tenant_id' => $user->tenant_id, 'kind' => 'queue_probe', 'status' => 'failed', 'created_at' => '2026-08-01 12:00:00']);
        $service = app(MonitoringService::class);
        $service->heartbeat('worker', 'database', 'default');
        $service->heartbeat('scheduler');

        $report = $service->snapshot($user);

        $this->assertSame('healthy', $report['status']);
        $this->assertSame(0, $report['counts']['failed_probes']);
        $this->assertSame([], $report['probes']);
        $this->assertSame(0, $report['queue']['cooldown_remaining_seconds']);
        $this->assertDatabaseCount('monitoring_events', 1);
    }

    public function test_live_database_failure_is_critical_without_exposing_connection_details(): void
    {
        $this->administrator();
        Storage::fake('local');
        config(['queue.default' => 'sync']);
        DB::partialMock()->shouldReceive('select')->once()->with('SELECT 1')->andThrow(new RuntimeException('private-db password=secret'));

        $report = app(MonitoringService::class)->systemReport();

        $this->assertSame('critical', $report['status']);
        $this->assertSame('critical', $this->checks($report)['database']['status']);
        $this->assertStringNotContainsString('private-db', json_encode($report, JSON_THROW_ON_ERROR));
    }

    public function test_private_storage_failed_write_is_critical_and_cleans_only_its_temporary_file(): void
    {
        $this->administrator();
        config(['queue.default' => 'sync']);
        $disk = Mockery::mock(FilesystemAdapter::class);
        $disk->shouldReceive('put')->once()->with(Mockery::on(fn (string $path): bool => str_starts_with($path, 'monitoring/.probe-')), 'POSONLY diagnostic')->andReturn(false);
        $disk->shouldReceive('delete')->once()->with(Mockery::on(fn (string $path): bool => str_starts_with($path, 'monitoring/.probe-')))->andReturn(true);
        Storage::shouldReceive('disk')->once()->with('local')->andReturn($disk);

        $report = app(MonitoringService::class)->systemReport();

        $this->assertSame('critical', $report['status']);
        $this->assertSame('critical', $this->checks($report)['private_storage']['status']);
        $this->assertStringNotContainsString('.probe-', json_encode($report, JSON_THROW_ON_ERROR));
    }

    public function test_private_storage_check_never_connects_to_a_configured_remote_disk(): void
    {
        $this->administrator();
        config(['queue.default' => 'sync', 'monitoring.storage_disk' => 's3']);
        Storage::shouldReceive('disk')->never();

        $report = app(MonitoringService::class)->systemReport();

        $this->assertSame('unverified', $this->checks($report)['private_storage']['status']);
        $this->assertSame('warning', $report['status']);
    }

    public function test_new_probe_timestamp_uses_configured_storage_timezone_even_when_php_default_differs(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-11 12:00:00', 'Asia/Manila'));
        $user = $this->administrator();
        config(['app.timezone' => 'UTC', 'queue.default' => 'sync']);

        $result = app(MonitoringService::class)->probe($user);

        $this->assertSame('2026-10-11T04:00:00+00:00', $result['probe']['created_at']);
        $this->assertSame('2026-10-11T04:00:00+00:00', $result['probe']['completed_at']);
        $this->assertSame(60, $result['cooldown_remaining_seconds']);
    }
}
