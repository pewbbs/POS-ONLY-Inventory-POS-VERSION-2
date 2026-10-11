<?php

namespace Tests\Feature;

use App\Models\Location;
use App\Models\Product;
use App\Models\Stock;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Models\User;
use App\Services\InventoryService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class PurchaseReceivingTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function store(string $role = 'manager'): array
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'role' => $role]);
        $location = Location::factory()->create(['tenant_id' => $tenant->id]);
        $product = Product::factory()->create(['tenant_id' => $tenant->id]);
        $supplier = Supplier::factory()->create(['tenant_id' => $tenant->id]);
        $stock = Stock::create(['tenant_id' => $tenant->id, 'product_id' => $product->id, 'location_id' => $location->id, 'quantity' => 10]);
        $order = app(InventoryService::class)->purchaseOrder($user, ['supplier_id' => $supplier->id, 'location_id' => $location->id, 'items' => [['product_id' => $product->id, 'quantity' => 5]]]);

        return compact('tenant', 'user', 'location', 'product', 'supplier', 'stock', 'order');
    }

    private function delivery(array $store, int $quantity = 2, string $key = 'delivery-1'): array
    {
        return ['idempotency_key' => $key, 'items' => [['product_id' => $store['product']->id, 'quantity' => $quantity]], 'note' => 'Counted at delivery'];
    }

    public function test_partial_delivery_adds_only_received_units_and_preserves_history(): void
    {
        $store = $this->store();
        $url = '/api/purchase-orders/'.$store['order']->id.'/receive';
        $this->actingAs($store['user'])->postJson($url, $this->delivery($store))->assertOk()
            ->assertJsonPath('status', 'partially_received')->assertJsonPath('items.0.received_quantity', 2)
            ->assertJsonPath('items.0.remaining_quantity', 3)->assertJsonPath('remaining_quantity', 3)
            ->assertJsonPath('received_at', null)->assertJsonCount(1, 'receipts')
            ->assertJsonPath('receipts.0.received_by_name', $store['user']->name);
        $this->assertDatabaseHas('stocks', ['id' => $store['stock']->id, 'quantity' => 12]);
        $this->postJson($url, $this->delivery($store, 3, 'delivery-2'))->assertOk()
            ->assertJsonPath('status', 'received')->assertJsonPath('received_quantity', 5)
            ->assertJsonPath('remaining_quantity', 0)->assertJsonCount(2, 'receipts');
        $this->assertDatabaseHas('stocks', ['id' => $store['stock']->id, 'quantity' => 15]);
        $this->assertDatabaseCount('stock_movements', 2);
        $this->assertNotNull($store['order']->refresh()->received_at);
        $this->assertDatabaseHas('purchase_receipts', ['purchase_order_id' => $store['order']->id, 'note' => 'Counted at delivery']);
    }

    public function test_receipt_retry_has_one_effect_and_different_payload_conflicts(): void
    {
        $store = $this->store();
        $url = '/api/purchase-orders/'.$store['order']->id.'/receive';
        $payload = $this->delivery($store);
        $this->actingAs($store['user'])->postJson($url, $payload)->assertOk();
        $this->postJson($url, $payload)->assertOk()->assertJsonCount(1, 'receipts');
        $this->postJson($url, $this->delivery($store, 3))->assertConflict();
        $this->postJson($url, array_replace($payload, ['note' => 'Different delivery']))->assertConflict();
        $this->assertDatabaseCount('purchase_receipts', 1);
        $this->assertDatabaseCount('stock_movements', 1);
        $this->assertDatabaseHas('stocks', ['id' => $store['stock']->id, 'quantity' => 12]);
    }

    public function test_full_remaining_legacy_receive_is_compatible_after_partial_delivery(): void
    {
        $store = $this->store();
        $url = '/api/purchase-orders/'.$store['order']->id.'/receive';
        $this->actingAs($store['user'])->postJson($url, $this->delivery($store))->assertOk();
        $this->postJson($url)->assertOk()->assertJsonPath('status', 'received')->assertJsonPath('received_quantity', 5);
        $this->postJson($url)->assertOk();
        $this->assertDatabaseHas('stocks', ['id' => $store['stock']->id, 'quantity' => 15]);
        $this->assertDatabaseCount('purchase_receipts', 2);
        $this->assertDatabaseCount('stock_movements', 2);
    }

    public function test_old_received_order_is_normalized_without_restoring_stock_again(): void
    {
        $store = $this->store();
        $items = $store['order']->items;
        unset($items[0]['received_quantity']);
        $store['order']->update(['status' => 'received', 'items' => $items, 'received_at' => now()]);
        $this->actingAs($store['user'])->postJson('/api/purchase-orders/'.$store['order']->id.'/receive')
            ->assertOk()->assertJsonPath('received_quantity', 5)->assertJsonPath('remaining_quantity', 0)->assertJsonCount(0, 'receipts');
        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertDatabaseHas('stocks', ['id' => $store['stock']->id, 'quantity' => 10]);
    }

    public function test_invalid_quantities_and_missing_retry_key_cannot_change_stock(): void
    {
        $store = $this->store();
        $url = '/api/purchase-orders/'.$store['order']->id.'/receive';
        $this->actingAs($store['user'])->postJson($url, $this->delivery($store, 6))->assertUnprocessable()->assertJsonValidationErrors('items.0.quantity');
        $this->postJson($url, $this->delivery($store, 0))->assertUnprocessable()->assertJsonValidationErrors('items.0.quantity');
        $this->postJson($url, ['items' => [['product_id' => $store['product']->id, 'quantity' => 1]]])->assertUnprocessable()->assertJsonValidationErrors('idempotency_key');
        $this->postJson($url, ['items' => [], 'idempotency_key' => 'empty'])->assertUnprocessable();
        $this->assertDatabaseCount('purchase_receipts', 0);
        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertDatabaseHas('stocks', ['id' => $store['stock']->id, 'quantity' => 10]);
    }

    public function test_invalid_second_line_rolls_back_the_entire_delivery(): void
    {
        $store = $this->store();
        $other = Product::factory()->create(['tenant_id' => $store['tenant']->id]);
        $payload = $this->delivery($store);
        $payload['items'][] = ['product_id' => $other->id, 'quantity' => 1];
        $this->actingAs($store['user'])->postJson('/api/purchase-orders/'.$store['order']->id.'/receive', $payload)->assertUnprocessable();
        $this->assertSame(0, $store['order']->refresh()->items[0]['received_quantity']);
        $this->assertDatabaseCount('purchase_receipts', 0);
        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertDatabaseHas('stocks', ['id' => $store['stock']->id, 'quantity' => 10]);
    }

    public function test_receiving_rejects_duplicate_product_lines(): void
    {
        $store = $this->store();
        $payload = $this->delivery($store);
        $payload['items'][] = $payload['items'][0];
        $this->actingAs($store['user'])->postJson('/api/purchase-orders/'.$store['order']->id.'/receive', $payload)->assertUnprocessable()->assertJsonValidationErrors('items.0.product_id');
        $this->assertDatabaseCount('purchase_receipts', 0);
    }

    public function test_draft_approval_does_not_move_stock_and_is_safe_to_repeat(): void
    {
        $store = $this->store();
        $store['order']->update(['status' => 'draft', 'approved_at' => null]);
        $base = '/api/purchase-orders/'.$store['order']->id;
        $this->actingAs($store['user'])->postJson($base.'/receive', $this->delivery($store))->assertConflict();
        $this->postJson($base.'/approve')->assertOk()->assertJsonPath('status', 'ordered');
        $approvedAt = $store['order']->refresh()->approved_at->toIso8601String();
        $this->travel(1)->minute();
        $this->postJson($base.'/approve')->assertOk()->assertJsonPath('approved_at', $approvedAt);
        $this->assertDatabaseCount('stock_movements', 0);
        $this->postJson($base.'/receive', $this->delivery($store))->assertOk();
    }

    public function test_cancel_outstanding_preserves_delivered_stock_and_blocks_new_receipts(): void
    {
        $store = $this->store();
        $base = '/api/purchase-orders/'.$store['order']->id;
        $payload = $this->delivery($store);
        $this->actingAs($store['user'])->postJson($base.'/receive', $payload)->assertOk();
        $this->postJson($base.'/cancel')->assertOk()->assertJsonPath('status', 'cancelled')->assertJsonPath('received_quantity', 2)->assertJsonPath('remaining_quantity', 3);
        $this->postJson($base.'/cancel')->assertOk();
        $this->postJson($base.'/receive', $this->delivery($store, 3, 'later'))->assertConflict();
        $this->postJson($base.'/approve')->assertConflict();
        $this->postJson($base.'/receive', $payload)->assertOk()->assertJsonPath('status', 'cancelled');
        $this->assertDatabaseHas('stocks', ['id' => $store['stock']->id, 'quantity' => 12]);
        $this->assertDatabaseCount('purchase_receipts', 1);
        $this->assertDatabaseCount('stock_movements', 1);
    }

    public function test_received_order_cannot_be_cancelled(): void
    {
        $store = $this->store();
        $base = '/api/purchase-orders/'.$store['order']->id;
        $this->actingAs($store['user'])->postJson($base.'/receive')->assertOk();
        $this->postJson($base.'/cancel')->assertConflict();
        $this->assertDatabaseHas('purchase_orders', ['id' => $store['order']->id, 'status' => 'received']);
    }

    public function test_order_and_receipt_actions_enforce_roles_and_tenant_isolation(): void
    {
        $store = $this->store();
        $other = $this->store();
        $base = '/api/purchase-orders/'.$other['order']->id;
        $this->actingAs($store['user'])->postJson($base.'/receive')->assertNotFound();
        $this->postJson($base.'/approve')->assertNotFound();
        $this->postJson($base.'/cancel')->assertNotFound();
        $cashier = User::factory()->create(['tenant_id' => $store['tenant']->id, 'role' => 'cashier']);
        $own = '/api/purchase-orders/'.$store['order']->id;
        $this->actingAs($cashier)->postJson($own.'/receive')->assertForbidden();
        $this->postJson($own.'/approve')->assertForbidden();
        $this->postJson($own.'/cancel')->assertForbidden();
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_create_draft_uses_server_scope_and_cannot_forge_replenishment_source(): void
    {
        $store = $this->store();
        $this->actingAs($store['user'])->postJson('/api/purchase-orders', [
            'supplier_id' => $store['supplier']->id, 'location_id' => $store['location']->id, 'status' => 'draft',
            'items' => [['product_id' => $store['product']->id, 'quantity' => 3, 'cost' => 12.25]],
            'tenant_id' => 999, 'source' => 'replenishment', 'reorder_rule_id' => 999,
        ])->assertCreated()->assertJsonPath('status', 'draft')->assertJsonPath('total', 36.75)->assertJsonPath('source', 'manual')->assertJsonPath('reorder_rule_id', null);
        $this->assertDatabaseHas('purchase_orders', ['tenant_id' => $store['tenant']->id, 'source' => 'manual', 'total_cents' => 3675]);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_stale_manager_session_cannot_mutate_orders_after_role_or_access_changes(): void
    {
        $store = $this->store();
        $base = '/api/purchase-orders/'.$store['order']->id;
        User::whereKey($store['user']->id)->update(['role' => 'cashier']);
        $this->actingAs($store['user'])->postJson($base.'/receive')->assertForbidden();
        $this->postJson($base.'/approve')->assertForbidden();
        $this->postJson($base.'/cancel')->assertForbidden();
        $this->postJson('/api/purchase-orders', ['supplier_id' => $store['supplier']->id, 'location_id' => $store['location']->id, 'items' => [['product_id' => $store['product']->id, 'quantity' => 1]]])->assertForbidden();
        User::whereKey($store['user']->id)->update(['role' => 'manager', 'is_active' => false]);
        $this->postJson($base.'/receive')->assertForbidden();
        $this->assertDatabaseCount('purchase_orders', 1);
        $this->assertDatabaseCount('purchase_receipts', 0);
        $this->assertDatabaseCount('stock_movements', 0);
    }
}
