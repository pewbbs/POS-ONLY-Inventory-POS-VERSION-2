<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\Location;
use App\Models\Product;
use App\Models\Stock;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Exceptions;
use PHPUnit\Framework\Attributes\TestWith;
use RuntimeException;
use Tests\TestCase;

class ActivityLogTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function actor(string $role = 'admin'): User
    {
        $tenant = Tenant::factory()->create();

        return User::factory()->create(['tenant_id' => $tenant->id, 'role' => $role]);
    }

    public function test_successful_action_records_only_actor_resource_and_timestamp_metadata(): void
    {
        $this->freezeTime();
        $actor = $this->actor();
        $response = $this->actingAs($actor)->postJson('/api/customers', ['name' => 'Private Customer Name', 'email' => 'private@example.test', 'phone' => 'private-phone', 'password' => 'never-log-this-password', 'token' => 'never-log-this-token'])->assertCreated();
        $entry = ActivityLog::firstOrFail();
        $this->assertSame($actor->tenant_id, $entry->tenant_id);
        $this->assertSame($actor->id, $entry->actor_id);
        $this->assertSame('customer.created', $entry->action);
        $this->assertSame('customer', $entry->resource_type);
        $this->assertSame($response->json('id'), $entry->resource_id);
        $this->assertSame(now()->format('Y-m-d H:i:s'), $entry->created_at->format('Y-m-d H:i:s'));
        $keys = array_keys($entry->getAttributes());
        sort($keys);
        $this->assertSame(['action', 'actor_id', 'created_at', 'id', 'resource_id', 'resource_type', 'tenant_id'], $keys);
        $serialized = json_encode($entry->getAttributes(), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('Private Customer Name', $serialized);
        $this->assertStringNotContainsString('private@example.test', $serialized);
        $this->assertStringNotContainsString('never-log-this', $serialized);
    }

    public function test_failed_or_forbidden_mutations_are_not_reported_as_successful_actions(): void
    {
        $actor = $this->actor('cashier');
        $this->actingAs($actor)->postJson('/api/customers', [])->assertUnprocessable();
        $this->postJson('/api/products', [])->assertForbidden();
        $this->assertDatabaseCount('activity_logs', 0);
        $this->assertDatabaseCount('customers', 0);
    }

    public function test_reading_a_workspace_does_not_create_activity_records(): void
    {
        $actor = $this->actor();
        $this->actingAs($actor)->getJson('/api/bootstrap')->assertOk();
        $this->getJson('/api/activity')->assertOk()->assertJsonCount(0, 'data');
        $this->assertDatabaseCount('activity_logs', 0);
    }

    public function test_activity_list_requires_authentication_as_401(): void
    {
        $this->getJson('/api/activity')->assertUnauthorized();
    }

    #[TestWith(['manager'])]
    #[TestWith(['cashier'])]
    public function test_activity_list_refuses_unprivileged_roles_as_403(string $role): void
    {
        $actor = $this->actor($role);
        $this->actingAs($actor)->getJson('/api/activity')->assertForbidden();
    }

    public function test_activity_list_and_filters_are_isolated_to_the_current_tenant(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 10)->setTime(9, 0));
        $actor = $this->actor();
        $manager = User::factory()->create(['tenant_id' => $actor->tenant_id, 'role' => 'manager']);
        $otherActor = $this->actor();
        $this->actingAs($actor)->postJson('/api/customers', ['name' => 'Own customer'])->assertCreated();
        $this->travel(1)->days();
        $this->actingAs($manager)->postJson('/api/suppliers', ['name' => 'Own supplier'])->assertCreated();
        $this->actingAs($otherActor)->postJson('/api/customers', ['name' => 'Other customer'])->assertCreated();
        $this->actingAs($actor)->getJson('/api/activity?tenant_id='.$otherActor->tenant_id)->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('meta.total', 2)->assertJsonCount(2, 'actors');
        $this->getJson('/api/activity?action=supplier.created')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.actor_id', $manager->id);
        $this->getJson('/api/activity?actor_id='.$actor->id)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.action', 'customer.created');
        $this->getJson('/api/activity?from=2026-10-11')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.action', 'supplier.created');
        $this->getJson('/api/activity?to=2026-10-10')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.action', 'customer.created');
        $this->getJson('/api/activity?actor_id='.$otherActor->id)->assertUnprocessable()->assertJsonValidationErrors('actor_id');
    }

    public function test_activity_pagination_reports_the_full_total_and_limits_each_page(): void
    {
        $actor = $this->actor();
        $this->actingAs($actor);
        for ($index = 1; $index <= 26; $index++) {
            $this->postJson('/api/customers', ['name' => 'Customer '.$index])->assertCreated();
        }
        $this->getJson('/api/activity')->assertOk()->assertJsonCount(25, 'data')->assertJsonPath('meta.total', 26)->assertJsonPath('meta.last_page', 2)->assertJsonPath('data.0.resource_id', Customer::latest('id')->first()->id);
        $this->getJson('/api/activity?page=2')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.current_page', 2);
        $this->getJson('/api/activity?per_page=101')->assertUnprocessable()->assertJsonValidationErrors('per_page');
        $this->getJson('/api/activity?action=untrusted.column')->assertUnprocessable()->assertJsonValidationErrors('action');
        $this->getJson('/api/activity?from=2026-10-11&to=2026-10-10')->assertUnprocessable()->assertJsonValidationErrors('to');
    }

    public function test_audit_storage_failure_does_not_overturn_a_successful_action(): void
    {
        $actor = $this->actor();
        Exceptions::fake([RuntimeException::class]);
        ActivityLog::creating(function (): void {
            throw new RuntimeException('Audit storage temporarily unavailable.');
        });
        try {
            $this->actingAs($actor)->postJson('/api/customers', ['name' => 'Saved customer'])->assertCreated();
            $this->assertDatabaseHas('customers', ['tenant_id' => $actor->tenant_id, 'name' => 'Saved customer']);
            $this->assertDatabaseCount('activity_logs', 0);
            Exceptions::assertReported(fn (RuntimeException $exception): bool => $exception->getMessage() === 'Audit storage temporarily unavailable.');
        } finally {
            ActivityLog::flushEventListeners();
        }
    }

    public function test_checkout_retries_are_labeled_confirmations_without_duplicate_sales(): void
    {
        $actor = $this->actor('cashier');
        $location = Location::factory()->create(['tenant_id' => $actor->tenant_id]);
        $product = Product::factory()->create(['tenant_id' => $actor->tenant_id]);
        Stock::create(['tenant_id' => $actor->tenant_id, 'product_id' => $product->id, 'location_id' => $location->id, 'quantity' => 2]);
        $payload = ['location_id' => $location->id, 'items' => [['product_id' => $product->id, 'quantity' => 1]], 'discount_percent' => 0, 'tax_percent' => 12, 'payments' => [['method' => 'cash', 'amount' => 1000]], 'idempotency_key' => 'audit-checkout-retry'];
        $sale = $this->actingAs($actor)->postJson('/api/sales', $payload)->assertCreated();
        $this->postJson('/api/sales', $payload)->assertCreated()->assertJsonPath('id', $sale->json('id'));
        $this->assertDatabaseCount('sales', 1);
        $this->assertDatabaseCount('activity_logs', 2);
        $this->assertSame([$sale->json('id'), $sale->json('id')], ActivityLog::where('action', 'checkout.confirmed')->pluck('resource_id')->all());
        $this->assertDatabaseHas('stocks', ['product_id' => $product->id, 'quantity' => 1]);
    }

    public function test_transaction_failure_leaves_no_sale_or_successful_action_record(): void
    {
        $actor = $this->actor('cashier');
        $location = Location::factory()->create(['tenant_id' => $actor->tenant_id]);
        $product = Product::factory()->create(['tenant_id' => $actor->tenant_id]);
        Stock::create(['tenant_id' => $actor->tenant_id, 'product_id' => $product->id, 'location_id' => $location->id, 'quantity' => 0]);
        $this->actingAs($actor)->postJson('/api/sales', ['location_id' => $location->id, 'items' => [['product_id' => $product->id, 'quantity' => 1]], 'payments' => [['method' => 'cash', 'amount' => 1000]], 'idempotency_key' => 'audit-failed-sale'])->assertUnprocessable();
        $this->assertDatabaseCount('sales', 0);
        $this->assertDatabaseCount('activity_logs', 0);
    }
}
