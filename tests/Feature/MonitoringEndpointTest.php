<?php

namespace Tests\Feature;

use App\Jobs\MonitoringQueueProbe;
use App\Models\BillingInvoice;
use App\Models\CashSession;
use App\Models\MonitoringEvent;
use App\Models\Sale;
use App\Models\Stock;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class MonitoringEndpointTest extends TestCase
{
    use LazilyRefreshDatabase;

    /** @return array{tenant: Tenant, user: User} */
    private function administrator(string $role = 'admin'): array
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'role' => $role]);

        return compact('tenant', 'user');
    }

    public function test_guests_cannot_read_health_queue_checks_or_acknowledge_incidents(): void
    {
        Queue::fake();

        $this->getJson('/api/monitoring')->assertUnauthorized();
        $this->postJson('/api/monitoring/probe')->assertUnauthorized();
        $this->postJson('/api/monitoring/incidents/1/acknowledge')->assertUnauthorized();
        $this->get('/app/monitoring')->assertRedirect(route('login'));

        Queue::assertNothingPushed();
    }

    #[TestWith(['manager'])]
    #[TestWith(['cashier'])]
    public function test_non_administrators_cannot_read_health_queue_checks_or_acknowledge_incidents(string $role): void
    {
        Queue::fake();
        $store = $this->administrator($role);

        $this->actingAs($store['user'])->getJson('/api/monitoring')->assertForbidden();
        $this->postJson('/api/monitoring/probe')->assertForbidden();
        $this->postJson('/api/monitoring/incidents/1/acknowledge')->assertForbidden();
        $this->get('/app/monitoring')->assertForbidden();

        Queue::assertNothingPushed();
    }

    public function test_paused_administrators_cannot_use_monitoring(): void
    {
        Queue::fake();
        $store = $this->administrator();
        $store['user']->update(['is_active' => false]);

        $this->actingAs($store['user'])->getJson('/api/monitoring')->assertForbidden();
        $this->actingAs($store['user'])->postJson('/api/monitoring/probe')->assertForbidden();
        $this->actingAs($store['user'])->postJson('/api/monitoring/incidents/1/acknowledge')->assertForbidden();

        Queue::assertNothingPushed();
    }

    #[TestWith(['role', 'manager'])]
    #[TestWith(['is_active', false])]
    public function test_monitoring_rechecks_current_access_instead_of_trusting_a_stale_administrator_object(string $field, mixed $value): void
    {
        Queue::fake();
        $store = $this->administrator();
        User::query()->whereKey($store['user']->id)->update([$field => $value]);

        $this->actingAs($store['user'])->getJson('/api/monitoring')->assertForbidden();
        $this->actingAs($store['user'])->postJson('/api/monitoring/probe')->assertForbidden();
        $this->actingAs($store['user'])->postJson('/api/monitoring/incidents/1/acknowledge')->assertForbidden();

        Queue::assertNothingPushed();
        $this->assertDatabaseCount('monitoring_events', 0);
    }

    public function test_health_history_is_tenant_scoped_and_ignores_injected_owners_and_status_filters(): void
    {
        $store = $this->administrator();
        $incident = MonitoringEvent::factory()->create(['tenant_id' => $store['tenant']->id]);
        $probe = MonitoringEvent::factory()->pendingProbe()->create(['tenant_id' => $store['tenant']->id]);
        $foreign = $this->administrator();
        MonitoringEvent::factory()->create(['tenant_id' => $foreign['tenant']->id]);
        MonitoringEvent::factory()->pendingProbe()->create(['tenant_id' => $foreign['tenant']->id]);
        $this->failedJob();
        $query = http_build_query([
            'tenant_id' => $foreign['tenant']->id, 'user_id' => $foreign['user']->id,
            'scope' => 'all_tenants', 'status' => 'passed', 'include_logs' => true,
        ]);

        $response = $this->actingAs($store['user'])->getJson('/api/monitoring?'.$query)->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('history_limit', 50)->assertJsonPath('retention_days', 30);

        $this->assertSame([$incident->id], array_column($response->json('incidents'), 'id'));
        $this->assertSame([$probe->id], array_column($response->json('probes'), 'id'));
        $this->assertSame('pending', $response->json('probes.0.status'));
        $this->assertEqualsCanonicalizing([
            'id', 'kind', 'status', 'summary', 'error_class', 'error_code', 'created_at', 'completed_at', 'acknowledged_at',
        ], array_keys($response->json('incidents.0')));
        $this->assertEqualsCanonicalizing([
            'id', 'kind', 'status', 'summary', 'error_class', 'error_code', 'created_at', 'completed_at', 'acknowledged_at', 'stale',
        ], array_keys($response->json('probes.0')));
        $this->assertStringNotContainsString('do-not-expose-private-failed-job', $response->getContent());
        $this->assertStringNotContainsString($probe->pending_scope, $response->getContent());
    }

    public function test_foreign_incidents_and_diagnostic_records_cannot_be_acknowledged_as_local_incidents(): void
    {
        Queue::fake();
        $store = $this->administrator();
        $foreign = MonitoringEvent::factory()->create();
        $probe = MonitoringEvent::factory()->pendingProbe()->create(['tenant_id' => $store['tenant']->id]);

        $this->actingAs($store['user'])->postJson('/api/monitoring/incidents/'.$foreign->id.'/acknowledge')->assertNotFound();
        $this->postJson('/api/monitoring/incidents/'.$probe->id.'/acknowledge')->assertNotFound();

        $this->assertNull($foreign->refresh()->acknowledged_at);
        $this->assertNull($probe->refresh()->acknowledged_at);
        $this->assertSame('pending', $probe->status);
        Queue::assertNothingPushed();
    }

    public function test_acknowledgment_is_repeat_safe_and_preserves_failures_and_jobs(): void
    {
        Queue::fake();
        $this->travelTo(now()->setDate(2026, 10, 11)->setTime(12, 0));
        $store = $this->administrator();
        $incident = MonitoringEvent::factory()->create(['tenant_id' => $store['tenant']->id, 'kind' => 'queue_failure']);
        $foreign = $this->administrator();
        $this->failedJob();
        $before = $this->businessSnapshot();

        $this->actingAs($store['user'])->postJson('/api/monitoring/incidents/'.$incident->id.'/acknowledge', [
            'tenant_id' => $foreign['tenant']->id, 'status' => 'passed', 'acknowledged_at' => '2000-01-01 00:00:00',
            'delete_job' => true, 'retry_job' => true,
        ])->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertJsonPath('incident.id', $incident->id)->assertJsonPath('incident.status', 'failed');
        $acknowledged = $incident->refresh()->acknowledged_at->toIso8601String();
        $this->assertSame(now()->toIso8601String(), $acknowledged);
        $this->travel(10)->seconds();
        $this->postJson('/api/monitoring/incidents/'.$incident->id.'/acknowledge')->assertOk()
            ->assertJsonPath('incident.acknowledged_at', $acknowledged);

        $this->assertSame($acknowledged, $incident->refresh()->acknowledged_at->toIso8601String());
        $this->assertSame($store['tenant']->id, $incident->tenant_id);
        $this->assertSame('failed', $incident->status);
        $this->assertDatabaseCount('monitoring_events', 1);
        $this->assertSame($before, $this->businessSnapshot());
        Queue::assertNothingPushed();
    }

    public function test_pending_background_checks_reuse_the_same_tenant_record_and_queue_only_one_diagnostic(): void
    {
        Queue::fake();
        $this->travelTo(now()->setDate(2026, 10, 11)->setTime(12, 0));
        $store = $this->administrator();
        $foreign = $this->administrator();
        $foreignProbe = MonitoringEvent::factory()->pendingProbe()->create(['tenant_id' => $foreign['tenant']->id]);
        $foreignBefore = $foreignProbe->refresh()->getAttributes();
        $before = $this->businessSnapshot();

        $response = $this->actingAs($store['user'])->postJson('/api/monitoring/probe', [
            'tenant_id' => $foreign['tenant']->id, 'user_id' => $foreign['user']->id,
            'status' => 'passed', 'summary' => 'do-not-accept-client-diagnostic-result', 'completed_at' => now()->toIso8601String(),
        ])->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertJsonPath('reused', false)->assertJsonPath('probe.status', 'pending');
        $id = $response->json('probe.id');
        $event = MonitoringEvent::query()->findOrFail($id);
        $this->assertSame($store['tenant']->id, $event->tenant_id);
        $this->assertNull($event->completed_at);
        $this->assertStringNotContainsString('do-not-accept-client-diagnostic-result', $event->summary);
        $this->travel(301)->seconds();
        $this->postJson('/api/monitoring/probe')->assertOk()
            ->assertJsonPath('reused', true)->assertJsonPath('probe.id', $id)->assertJsonPath('probe.status', 'pending');

        Queue::assertPushed(MonitoringQueueProbe::class, fn (MonitoringQueueProbe $job): bool => $job->tenantId === $store['tenant']->id && $job->eventId === $id);
        Queue::assertCount(1);
        $this->assertDatabaseCount('monitoring_events', 2);
        $this->assertSame($foreignBefore, $foreignProbe->refresh()->getAttributes());
        $this->assertSame($before, $this->businessSnapshot());
    }

    public function test_a_completed_background_attempt_enforces_cooldown_before_a_new_record_is_queued(): void
    {
        Queue::fake();
        $this->travelTo(now()->setDate(2026, 10, 11)->setTime(12, 0));
        $store = $this->administrator();
        $completed = MonitoringEvent::factory()->pendingProbe()->create([
            'tenant_id' => $store['tenant']->id, 'status' => 'passed', 'pending_scope' => null, 'completed_at' => now(),
        ]);

        $this->actingAs($store['user'])->postJson('/api/monitoring/probe')->assertStatus(429)->assertHeader('Retry-After', '60');
        Queue::assertNothingPushed();
        $this->assertDatabaseCount('monitoring_events', 1);
        $this->travel(61)->seconds();
        $response = $this->postJson('/api/monitoring/probe')->assertOk()
            ->assertJsonPath('reused', false)->assertJsonPath('probe.status', 'pending');

        $this->assertNotSame($completed->id, $response->json('probe.id'));
        $this->assertSame('passed', $completed->refresh()->status);
        Queue::assertPushed(MonitoringQueueProbe::class, fn (MonitoringQueueProbe $job): bool => $job->eventId === $response->json('probe.id'));
        Queue::assertCount(1);
        $this->assertDatabaseCount('monitoring_events', 2);
    }

    public function test_expired_subscription_administrators_can_monitor_without_changing_business_or_billing_records(): void
    {
        Queue::fake();
        $this->travelTo(now()->setDate(2026, 10, 11)->setTime(12, 0));
        $store = $this->administrator();
        $subscription = Subscription::factory()->create([
            'tenant_id' => $store['tenant']->id, 'status' => 'active', 'trial_ends_at' => now()->subDays(40),
            'current_period_start' => now()->subMonth(), 'current_period_end' => now()->subDays(10),
        ]);
        BillingInvoice::factory()->create(['tenant_id' => $store['tenant']->id, 'subscription_id' => $subscription->id]);
        $stock = Stock::factory()->create(['tenant_id' => $store['tenant']->id, 'quantity' => 20, 'reserved_quantity' => 3]);
        Sale::factory()->create(['tenant_id' => $store['tenant']->id, 'user_id' => $store['user']->id, 'location_id' => $stock->location_id]);
        CashSession::factory()->create(['tenant_id' => $store['tenant']->id, 'user_id' => $store['user']->id, 'location_id' => $stock->location_id]);
        $incident = MonitoringEvent::factory()->create(['tenant_id' => $store['tenant']->id]);
        $this->failedJob();
        $before = $this->businessSnapshot();

        $this->actingAs($store['user'])->getJson('/api/monitoring')->assertOk();
        $this->assertSame($before, $this->businessSnapshot());
        $this->postJson('/api/monitoring/probe')->assertOk()->assertJsonPath('probe.status', 'pending');
        $this->postJson('/api/monitoring/incidents/'.$incident->id.'/acknowledge')->assertOk();

        $this->assertSame($before, $this->businessSnapshot());
        $this->assertDatabaseCount('monitoring_events', 2);
        Queue::assertPushed(MonitoringQueueProbe::class, fn (MonitoringQueueProbe $job): bool => $job->tenantId === $store['tenant']->id);
        Queue::assertCount(1);
    }

    private function failedJob(): void
    {
        DB::table('failed_jobs')->insert([
            'uuid' => '11111111-1111-4111-8111-111111111111', 'connection' => 'database', 'queue' => 'default',
            'payload' => '{"secret":"do-not-expose-private-failed-job"}',
            'exception' => 'do-not-expose-private-failed-job /private/path password=fixture-only', 'failed_at' => now(),
        ]);
    }

    /** @return array<string, array<int, array<string, mixed>>> */
    private function businessSnapshot(): array
    {
        $result = [];
        foreach ([
            'tenants', 'products', 'stocks', 'stock_movements', 'sales', 'sale_returns', 'purchase_orders', 'purchase_receipts',
            'reorder_rules', 'cash_sessions', 'cash_adjustments', 'subscriptions', 'billing_invoices',
            'commerce_channels', 'channel_listings', 'channel_orders', 'channel_syncs', 'activity_logs', 'jobs', 'failed_jobs',
        ] as $table) {
            $result[$table] = DB::table($table)->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all();
        }

        return $result;
    }
}
