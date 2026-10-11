<?php

namespace Tests\Feature;

use App\Jobs\CheckLowStock;
use App\Jobs\MonitoringQueueProbe;
use App\Models\MonitoringEvent;
use App\Models\Tenant;
use App\Models\User;
use App\Services\MonitoringService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Queue\Events\Looping;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class MonitoringIntegrationTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function administrator(): User
    {
        return User::factory()->create(['tenant_id' => Tenant::factory()->create()->id, 'role' => 'admin']);
    }

    public function test_public_health_performs_a_database_query_and_returns_only_minimal_uncached_status(): void
    {
        DB::shouldReceive('connection->select')->once()->with('SELECT 1')->andReturn([(object) ['1' => 1]]);

        $this->getJson('/health')->assertOk()->assertExactJson([
            'status' => 'ok', 'database' => 'connected', 'application' => 'POSONLY',
        ])->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_public_health_returns_503_without_exposing_database_error_details(): void
    {
        Exceptions::fake();
        $error = new RuntimeException('Database password=private-test-value path=/private/database.sqlite');
        DB::shouldReceive('connection->select')->once()->with('SELECT 1')->andThrow($error);

        $this->getJson('/health')->assertServiceUnavailable()->assertExactJson([
            'status' => 'unavailable', 'database' => 'unavailable', 'application' => 'POSONLY',
        ])->assertHeader('Cache-Control', 'no-store, private');

        Exceptions::assertReported(fn (RuntimeException $reported): bool => $reported === $error);
    }

    public function test_worker_loop_records_the_actual_queue_and_does_not_pretend_another_queue_is_healthy(): void
    {
        $this->freezeTime();
        $this->administrator();
        config(['queue.default' => 'database', 'queue.connections.database.queue' => 'default']);
        Storage::fake('local');

        Event::dispatch(new Looping('database', 'other-queue'));

        $this->assertDatabaseHas('system_heartbeats', ['component' => 'worker', 'connection' => 'database', 'queue' => 'other-queue']);
        $monitoring = app(MonitoringService::class);
        $worker = collect($monitoring->systemReport()['checks'])->firstWhere('key', 'worker');
        $this->assertSame('warning', $worker['status']);

        Event::dispatch(new Looping('database', 'default'));

        $this->assertDatabaseHas('system_heartbeats', ['component' => 'worker', 'connection' => 'database', 'queue' => 'default']);
        $worker = collect($monitoring->systemReport()['checks'])->firstWhere('key', 'worker');
        $this->assertSame('healthy', $worker['status']);
    }

    public function test_scheduler_registration_has_no_pulse_until_its_scheduled_callback_actually_runs(): void
    {
        $this->freezeTime();
        $this->administrator();
        Storage::fake('local');

        $scheduled = collect(app(Schedule::class)->events())->where('description', 'monitoring:scheduler-heartbeat');

        $this->assertCount(1, $scheduled);
        $this->assertDatabaseCount('system_heartbeats', 0);

        $scheduled->first()->run($this->app);

        $this->assertDatabaseHas('system_heartbeats', ['component' => 'scheduler']);
        $scheduler = collect(app(MonitoringService::class)->systemReport()['checks'])->firstWhere('key', 'scheduler');
        $this->assertSame('healthy', $scheduler['status']);
    }

    public function test_terminal_low_stock_failure_records_a_sanitized_notice_for_the_job_tenant(): void
    {
        $user = $this->administrator();
        $foreign = $this->administrator();

        (new CheckLowStock($user->tenant_id))->failed(new RuntimeException('password=private-test-value'));

        $this->assertDatabaseHas('monitoring_events', ['tenant_id' => $user->tenant_id, 'kind' => 'queue_failure']);
        $this->assertDatabaseMissing('monitoring_events', ['tenant_id' => $foreign->tenant_id]);
        $this->assertDatabaseCount('low_stock_alerts', 0);
        $this->assertDatabaseCount('purchase_orders', 0);
        $this->assertStringNotContainsString('private-test-value', MonitoringEvent::firstOrFail()->toJson());
    }

    public function test_authenticated_reported_exception_is_recorded_without_request_data_or_error_message(): void
    {
        $user = $this->administrator();
        Log::spy();
        config(['app.debug' => true]);
        $error = new RuntimeException('password=private-test-value customer=private-customer');
        Route::get('/api/monitoring/hook-test', fn () => throw $error)->middleware(['web', 'auth', 'tenant']);

        $this->actingAs($user)->getJson('/api/monitoring/hook-test?token=private-query')->assertServiceUnavailable()->assertExactJson([
            'message' => 'System health is temporarily unavailable. Refresh health or ask the system operator to check the local services.',
        ])->assertHeader('Cache-Control', 'no-store, private');

        $this->assertDatabaseCount('monitoring_events', 1);
        $this->assertDatabaseHas('monitoring_events', ['tenant_id' => $user->tenant_id, 'kind' => 'application_error', 'error_class' => 'RuntimeException']);
        $record = MonitoringEvent::firstOrFail()->toJson();
        $this->assertStringNotContainsString('private-test-value', $record);
        $this->assertStringNotContainsString('private-customer', $record);
        $this->assertStringNotContainsString('private-query', $record);
        Log::shouldHaveReceived('error')->once();
    }

    public function test_health_command_reports_warning_until_real_worker_and_scheduler_pulses_are_present(): void
    {
        $this->freezeTime();
        $this->administrator();
        Storage::fake('local');
        config(['queue.default' => 'database', 'queue.connections.database.queue' => 'default']);

        $this->artisan('monitoring:check')->expectsOutput('Overall status: warning')->assertFailed();

        Event::dispatch(new Looping('database', 'default'));
        $scheduled = collect(app(Schedule::class)->events())->firstWhere('description', 'monitoring:scheduler-heartbeat');
        $scheduled->run($this->app);

        $this->artisan('monitoring:check')->expectsOutput('Overall status: healthy')->assertSuccessful();
        $this->assertDatabaseCount('monitoring_events', 0);
    }

    public function test_retention_command_removes_expired_monitoring_events_and_keeps_business_records(): void
    {
        $this->freezeTime();
        $user = $this->administrator();
        $old = MonitoringEvent::factory()->create(['tenant_id' => $user->tenant_id, 'created_at' => now()->subDays(31)]);
        $recent = MonitoringEvent::factory()->create(['tenant_id' => $user->tenant_id]);
        config(['monitoring.retention_days' => 30]);

        $this->artisan('monitoring:prune')->expectsOutput('Removed 1 expired monitoring records. Business records were kept.')->assertSuccessful();

        $this->assertModelMissing($old);
        $this->assertModelExists($recent);
        $this->assertModelExists($user);
        $this->assertDatabaseCount('tenants', 1);
    }

    public function test_health_refreshes_do_not_consume_the_separate_diagnostic_request_limit(): void
    {
        $this->freezeTime();
        $user = $this->administrator();
        Queue::fake();
        Storage::fake('local');
        $this->actingAs($user);

        for ($request = 0; $request < 7; $request++) {
            $this->getJson('/api/monitoring')->assertOk();
        }
        $this->postJson('/api/monitoring/probe')->assertOk()->assertJsonPath('reused', false);
        for ($request = 0; $request < 5; $request++) {
            $this->postJson('/api/monitoring/probe')->assertOk()->assertJsonPath('reused', true);
        }
        $this->postJson('/api/monitoring/probe')->assertTooManyRequests();

        Queue::assertPushed(MonitoringQueueProbe::class, 1);
        $this->assertDatabaseCount('monitoring_events', 1);
    }
}
