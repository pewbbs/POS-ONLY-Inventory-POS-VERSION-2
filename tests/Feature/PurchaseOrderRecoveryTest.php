<?php

namespace Tests\Feature;

use App\Models\Location;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\Stock;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Models\User;
use App\Services\InventoryService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Exceptions\HttpResponseException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PurchaseOrderRecoveryTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function store(string $role = 'manager'): array
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'role' => $role]);
        $location = Location::factory()->create(['tenant_id' => $tenant->id]);
        $product = Product::factory()->create(['tenant_id' => $tenant->id, 'cost_cents' => 1234]);
        $supplier = Supplier::factory()->create(['tenant_id' => $tenant->id]);
        $stock = Stock::create(['tenant_id' => $tenant->id, 'location_id' => $location->id, 'product_id' => $product->id, 'quantity' => 10]);

        return compact('tenant', 'user', 'location', 'product', 'supplier', 'stock');
    }

    private function payload(array $store): array
    {
        return ['supplier_id' => $store['supplier']->id, 'location_id' => $store['location']->id,
            'items' => [['product_id' => $store['product']->id, 'quantity' => 5]], 'idempotency_key' => 'purchase-confirmation-001'];
    }

    public function test_creation_retry_returns_original_order_without_repricing_or_stock_changes(): void
    {
        $store = $this->store();
        $payload = $this->payload($store);
        $first = $this->actingAs($store['user'])->postJson('/api/purchase-orders', $payload)->assertCreated()->assertJsonPath('total', 61.7);
        $originalName = $store['product']->name;
        $store['product']->update(['name' => 'Changed catalog name', 'cost_cents' => 9999]);

        $this->postJson('/api/purchase-orders', $payload)->assertCreated()->assertJsonPath('id', $first->json('id'))
            ->assertJsonPath('items.0.name', $originalName)->assertJsonPath('items.0.unit_cost', 12.34)->assertJsonPath('total', 61.7);

        $this->assertDatabaseCount('purchase_orders', 1);
        $this->assertDatabaseHas('purchase_orders', ['id' => $first->json('id'), 'creation_key' => 'purchase-confirmation-001', 'user_id' => $store['user']->id, 'total_cents' => 6170]);
        $this->assertDatabaseHas('stocks', ['id' => $store['stock']->id, 'quantity' => 10]);
        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertDatabaseCount('purchase_receipts', 0);
    }

    public function test_reordering_items_and_equivalent_numbers_preserves_confirmation(): void
    {
        $store = $this->store();
        $second = Product::factory()->create(['tenant_id' => $store['tenant']->id]);
        $payload = $this->payload($store);
        $payload['items'] = [['product_id' => $store['product']->id, 'quantity' => 5, 'cost' => 12.34], ['product_id' => $second->id, 'quantity' => 2, 'cost' => 10]];
        $first = $this->actingAs($store['user'])->postJson('/api/purchase-orders', $payload)->assertCreated();
        $payload['status'] = 'ordered';
        $payload['items'] = [['product_id' => (string) $second->id, 'quantity' => '2', 'cost' => '10.00'], ['product_id' => $store['product']->id, 'quantity' => 5, 'cost' => '12.34']];

        $this->postJson('/api/purchase-orders', $payload)->assertCreated()->assertJsonPath('id', $first->json('id'))->assertJsonPath('total', 81.7);

        $this->assertDatabaseCount('purchase_orders', 1);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public static function changedOrderDetails(): array
    {
        return ['quantity' => [['items' => [['quantity' => 6]]]], 'cost' => [['items' => [['cost' => 12.34]]]], 'status' => [['status' => 'draft']]];
    }

    #[DataProvider('changedOrderDetails')]
    public function test_confirmation_reuse_with_changed_order_details_returns_409(array $changes): void
    {
        $store = $this->store();
        $payload = $this->payload($store);
        $first = $this->actingAs($store['user'])->postJson('/api/purchase-orders', $payload)->assertCreated();

        $this->postJson('/api/purchase-orders', array_replace_recursive($payload, $changes))->assertConflict()
            ->assertJsonPath('message', 'This purchase key was already used for different order details or by another team member.');

        $this->assertDatabaseCount('purchase_orders', 1);
        $this->assertDatabaseHas('purchase_orders', ['id' => $first->json('id'), 'status' => 'ordered', 'total_cents' => 6170]);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_confirmation_reuse_with_changed_supplier_returns_409(): void
    {
        $store = $this->store();
        $otherSupplier = Supplier::factory()->create(['tenant_id' => $store['tenant']->id]);
        $payload = $this->payload($store);
        $this->actingAs($store['user'])->postJson('/api/purchase-orders', $payload)->assertCreated();

        $this->postJson('/api/purchase-orders', array_replace($payload, ['supplier_id' => $otherSupplier->id]))->assertConflict();

        $this->assertDatabaseCount('purchase_orders', 1);
        $this->assertDatabaseHas('purchase_orders', ['supplier_id' => $store['supplier']->id]);
    }

    public function test_confirmation_reuse_with_changed_location_returns_409(): void
    {
        $store = $this->store();
        $otherLocation = Location::factory()->create(['tenant_id' => $store['tenant']->id]);
        $payload = $this->payload($store);
        $this->actingAs($store['user'])->postJson('/api/purchase-orders', $payload)->assertCreated();

        $this->postJson('/api/purchase-orders', array_replace($payload, ['location_id' => $otherLocation->id]))->assertConflict();

        $this->assertDatabaseCount('purchase_orders', 1);
        $this->assertDatabaseHas('purchase_orders', ['location_id' => $store['location']->id]);
    }

    public function test_confirmation_is_bound_to_original_team_member(): void
    {
        $store = $this->store();
        $otherManager = User::factory()->create(['tenant_id' => $store['tenant']->id, 'role' => 'manager']);
        $payload = $this->payload($store);
        $this->actingAs($store['user'])->postJson('/api/purchase-orders', $payload)->assertCreated();

        $this->actingAs($otherManager)->postJson('/api/purchase-orders', $payload)->assertConflict();

        $this->assertDatabaseCount('purchase_orders', 1);
        $this->assertDatabaseHas('purchase_orders', ['user_id' => $store['user']->id]);
    }

    public function test_same_confirmation_key_can_be_used_by_separate_tenants(): void
    {
        $store = $this->store();
        $otherStore = $this->store();
        $first = $this->actingAs($store['user'])->postJson('/api/purchase-orders', $this->payload($store))->assertCreated();

        $second = $this->actingAs($otherStore['user'])->postJson('/api/purchase-orders', $this->payload($otherStore))->assertCreated();

        $this->assertNotSame($first->json('id'), $second->json('id'));
        $this->assertDatabaseCount('purchase_orders', 2);
        $this->assertDatabaseHas('purchase_orders', ['id' => $second->json('id'), 'tenant_id' => $otherStore['tenant']->id]);
    }

    public function test_creation_replay_returns_received_state_without_receiving_again(): void
    {
        $store = $this->store();
        $payload = $this->payload($store);
        $first = $this->actingAs($store['user'])->postJson('/api/purchase-orders', $payload)->assertCreated();
        $this->postJson('/api/purchase-orders/'.$first->json('id').'/receive')->assertOk()->assertJsonPath('status', 'received');

        $this->postJson('/api/purchase-orders', $payload)->assertCreated()->assertJsonPath('id', $first->json('id'))
            ->assertJsonPath('status', 'received')->assertJsonCount(1, 'receipts');

        $this->assertDatabaseCount('purchase_orders', 1);
        $this->assertDatabaseCount('purchase_receipts', 1);
        $this->assertDatabaseCount('stock_movements', 1);
        $this->assertDatabaseHas('stocks', ['id' => $store['stock']->id, 'quantity' => 15]);
    }

    public function test_legacy_callers_without_confirmation_key_remain_compatible(): void
    {
        $store = $this->store();
        $payload = $this->payload($store);
        unset($payload['idempotency_key']);

        $first = $this->actingAs($store['user'])->postJson('/api/purchase-orders', $payload)->assertCreated();
        $second = $this->postJson('/api/purchase-orders', $payload)->assertCreated();

        $this->assertNotSame($first->json('id'), $second->json('id'));
        $this->assertDatabaseCount('purchase_orders', 2);
        $this->assertDatabaseHas('purchase_orders', ['id' => $first->json('id'), 'creation_key' => null, 'creation_hash' => null]);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public static function invalidConfirmationKeys(): array
    {
        return ['empty' => [''], 'too short' => ['short'], 'too long' => [str_repeat('x', 101)], 'explicit null' => [null]];
    }

    #[DataProvider('invalidConfirmationKeys')]
    public function test_invalid_confirmation_key_returns_422_without_creating_order(?string $key): void
    {
        $store = $this->store();

        $this->actingAs($store['user'])->postJson('/api/purchase-orders', array_replace($this->payload($store), ['idempotency_key' => $key]))
            ->assertUnprocessable()->assertJsonValidationErrors('idempotency_key');

        $this->assertDatabaseCount('purchase_orders', 0);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public static function revokedStaffAccess(): array
    {
        return ['demoted' => [['role' => 'cashier']], 'paused' => [['is_active' => false]]];
    }

    #[DataProvider('revokedStaffAccess')]
    public function test_confirmation_cannot_be_replayed_after_staff_access_is_revoked(array $changes): void
    {
        $store = $this->store();
        $payload = $this->payload($store);
        $this->actingAs($store['user'])->postJson('/api/purchase-orders', $payload)->assertCreated();
        User::whereKey($store['user']->id)->update($changes);

        $this->postJson('/api/purchase-orders', $payload)->assertForbidden();

        $this->assertDatabaseCount('purchase_orders', 1);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_confirmation_cannot_be_replayed_after_subscription_access_ends(): void
    {
        $this->freezeTime();
        $store = $this->store();
        $payload = $this->payload($store);
        $this->actingAs($store['user'])->postJson('/api/purchase-orders', $payload)->assertCreated();
        $this->travel(30)->days();

        $this->postJson('/api/purchase-orders', $payload)->assertStatus(423)->assertJsonPath('code', 'subscription_required');

        $this->assertDatabaseCount('purchase_orders', 1);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_service_rechecks_subscription_before_replay_for_internal_callers(): void
    {
        $this->freezeTime();
        $store = $this->store();
        $payload = $this->payload($store);
        $inventory = app(InventoryService::class);
        $inventory->purchaseOrder($store['user'], $payload);
        $this->travel(30)->days();

        try {
            $inventory->purchaseOrder($store['user'], $payload);
            $this->fail('An expired subscription must not bypass purchasing access when replaying a confirmation.');
        } catch (HttpResponseException $exception) {
            $this->assertSame(423, $exception->getResponse()->getStatusCode());
        }

        $this->assertDatabaseCount('purchase_orders', 1);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_outstanding_purchase_orders_remain_visible_beyond_processed_history_limit(): void
    {
        $store = $this->store();
        $attributes = ['tenant_id' => $store['tenant']->id, 'user_id' => $store['user']->id, 'supplier_id' => $store['supplier']->id, 'location_id' => $store['location']->id,
            'items' => [['product_id' => $store['product']->id, 'name' => $store['product']->name, 'sku' => $store['product']->sku, 'quantity' => 5, 'received_quantity' => 0, 'unit_cost' => 12.34, 'line_total' => 61.7]]];
        $draft = PurchaseOrder::factory()->draft()->create($attributes);
        $ordered = PurchaseOrder::factory()->create($attributes);
        $partial = PurchaseOrder::factory()->create($attributes + ['status' => 'partially_received']);
        $oldReceived = PurchaseOrder::factory()->create($attributes + ['status' => 'received']);
        PurchaseOrder::factory()->count(200)->create($attributes + ['status' => 'received']);
        $cancelled = PurchaseOrder::factory()->create($attributes + ['status' => 'cancelled']);

        $response = $this->actingAs($store['user'])->getJson('/api/bootstrap')->assertOk()->assertJsonCount(203, 'purchase_orders')
            ->assertJsonPath('purchase_order_history', ['processed_limit' => 200, 'total' => 205, 'outstanding_count' => 3, 'processed_count' => 202, 'loaded_processed_count' => 200, 'truncated' => true])
            ->assertJsonPath('purchase_order_stats_by_location.'.$store['location']->id, ['drafts' => 1, 'pending_deliveries' => 2, 'received' => 201, 'cancelled' => 1]);

        $ids = array_column($response->json('purchase_orders'), 'id');
        $this->assertContains($draft->id, $ids);
        $this->assertContains($ordered->id, $ids);
        $this->assertContains($partial->id, $ids);
        $this->assertContains($cancelled->id, $ids);
        $this->assertNotContains($oldReceived->id, $ids);
    }

    public function test_purchase_history_and_aggregates_are_tenant_scoped_and_hidden_from_cashiers(): void
    {
        $store = $this->store();
        $otherStore = $this->store();
        $this->actingAs($store['user'])->postJson('/api/purchase-orders', $this->payload($store))->assertCreated();
        $this->actingAs($otherStore['user'])->postJson('/api/purchase-orders', $this->payload($otherStore))->assertCreated();

        $this->actingAs($store['user'])->getJson('/api/bootstrap')->assertOk()->assertJsonCount(1, 'purchase_orders')
            ->assertJsonPath('purchase_orders.0.supplier_id', $store['supplier']->id)->assertJsonPath('purchase_order_history.total', 1)
            ->assertJsonPath('purchase_order_history.truncated', false)->assertJsonMissingPath('purchase_order_stats_by_location.'.$otherStore['location']->id);
        $cashier = User::factory()->create(['tenant_id' => $store['tenant']->id, 'role' => 'cashier']);
        $this->actingAs($cashier)->getJson('/api/bootstrap')->assertOk()->assertJsonCount(0, 'purchase_orders')
            ->assertJsonPath('purchase_order_history', null)->assertJsonCount(0, 'purchase_order_stats_by_location');

        $this->assertDatabaseCount('purchase_orders', 2);
    }
}
