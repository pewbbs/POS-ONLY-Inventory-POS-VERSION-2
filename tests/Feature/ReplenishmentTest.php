<?php

namespace Tests\Feature;

use App\Jobs\CheckLowStock;
use App\Models\Location;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\ReorderRule;
use App\Models\Stock;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Models\User;
use App\Services\ReplenishmentService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class ReplenishmentTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function store(int $quantity = 4): array
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'role' => 'admin']);
        $location = Location::factory()->create(['tenant_id' => $tenant->id]);
        $product = Product::factory()->create(['tenant_id' => $tenant->id, 'reorder_level' => 10]);
        $supplier = Supplier::factory()->create(['tenant_id' => $tenant->id]);
        $stock = Stock::create(['tenant_id' => $tenant->id, 'product_id' => $product->id, 'location_id' => $location->id, 'quantity' => $quantity]);
        $rule = ReorderRule::factory()->create(['tenant_id' => $tenant->id, 'product_id' => $product->id, 'location_id' => $location->id, 'supplier_id' => $supplier->id, 'user_id' => $user->id, 'target_quantity' => 20]);

        return compact('tenant', 'user', 'location', 'product', 'supplier', 'stock', 'rule');
    }

    private function order(array $store, string $status, int $quantity, int $received = 0, ?int $supplierId = null): PurchaseOrder
    {
        $product = $store['product'];

        return PurchaseOrder::factory()->create([
            'tenant_id' => $store['tenant']->id, 'location_id' => $store['location']->id, 'supplier_id' => $supplierId ?? $store['supplier']->id, 'user_id' => $store['user']->id,
            'status' => $status, 'items' => [['product_id' => $product->id, 'name' => $product->name, 'sku' => $product->sku, 'quantity' => $quantity, 'received_quantity' => $received, 'unit_cost' => 210, 'line_total' => $quantity * 210]],
        ]);
    }

    public function test_low_stock_creates_one_reviewable_draft_without_stock_changes(): void
    {
        $store = $this->store();
        $job = new CheckLowStock($store['tenant']->id);
        $job->handle();
        $job->handle();
        $order = PurchaseOrder::sole();
        $this->assertSame('draft', $order->status);
        $this->assertSame('replenishment', $order->source);
        $this->assertSame($store['rule']->id, $order->reorder_rule_id);
        $this->assertSame(16, $order->items[0]['quantity']);
        $this->assertSame($store['user']->id, $order->user_id);
        $this->assertNull($order->approved_at);
        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertDatabaseCount('purchase_receipts', 0);
        $this->assertDatabaseHas('stocks', ['id' => $store['stock']->id, 'quantity' => 4]);
        $this->assertDatabaseHas('low_stock_alerts', ['product_id' => $store['product']->id, 'active' => true]);
    }

    public function test_pending_orders_across_suppliers_cover_remaining_target(): void
    {
        $store = $this->store();
        $otherSupplier = Supplier::factory()->create(['tenant_id' => $store['tenant']->id]);
        $this->order($store, 'draft', 3);
        $this->order($store, 'ordered', 4, 0, $otherSupplier->id);
        $this->order($store, 'partially_received', 7, 2);
        $this->order($store, 'cancelled', 100);
        $this->order($store, 'received', 100, 100);
        $orders = app(ReplenishmentService::class)->generateForTenant($store['tenant']->id);
        $this->assertCount(1, $orders);
        $this->assertSame(4, $orders[0]->items[0]['quantity']);
        $this->assertCount(0, app(ReplenishmentService::class)->generateForTenant($store['tenant']->id));
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_pending_coverage_uses_full_history_beyond_workspace_display_limit(): void
    {
        $store = $this->store(0);
        $store['rule']->update(['target_quantity' => 300]);
        for ($i = 0; $i < 201; $i++) {
            $this->order($store, 'ordered', 1);
        }
        $orders = app(ReplenishmentService::class)->generateForTenant($store['tenant']->id);
        $this->assertCount(1, $orders);
        $this->assertSame(99, $orders[0]->items[0]['quantity']);
        $this->assertCount(0, app(ReplenishmentService::class)->generateForTenant($store['tenant']->id));
    }

    public function test_high_stock_disabled_rule_and_sufficient_pending_orders_do_not_draft(): void
    {
        $store = $this->store(11);
        $service = app(ReplenishmentService::class);
        $this->assertCount(0, $service->generateForTenant($store['tenant']->id));
        $store['stock']->update(['quantity' => 4]);
        $store['rule']->update(['enabled' => false]);
        $this->assertCount(0, $service->generateForTenant($store['tenant']->id));
        $store['rule']->update(['enabled' => true]);
        $this->order($store, 'ordered', 16);
        $this->assertCount(0, $service->generateForTenant($store['tenant']->id));
        $this->assertDatabaseCount('purchase_orders', 1);
    }

    public function test_rules_at_same_location_do_not_duplicate_supplier_orders(): void
    {
        $store = $this->store();
        $otherSupplier = Supplier::factory()->create(['tenant_id' => $store['tenant']->id]);
        ReorderRule::factory()->create(['tenant_id' => $store['tenant']->id, 'product_id' => $store['product']->id, 'location_id' => $store['location']->id, 'supplier_id' => $otherSupplier->id, 'user_id' => $store['user']->id, 'target_quantity' => 20]);
        $this->assertCount(1, app(ReplenishmentService::class)->generateForTenant($store['tenant']->id));
        $this->assertDatabaseCount('purchase_orders', 1);
    }

    public function test_replenishment_remains_enabled_when_alerts_are_disabled(): void
    {
        $store = $this->store();
        $store['tenant']->update(['settings' => array_replace($store['tenant']->settings, ['low_stock_alerts' => false])]);
        (new CheckLowStock($store['tenant']->id))->handle();
        $this->assertDatabaseCount('purchase_orders', 1);
        $this->assertDatabaseCount('low_stock_alerts', 0);
    }

    public function test_missing_stock_row_is_zero_and_draft_does_not_increase_it(): void
    {
        $store = $this->store();
        $store['stock']->delete();
        (new CheckLowStock($store['tenant']->id))->handle();
        $this->assertSame(20, PurchaseOrder::sole()->items[0]['quantity']);
        $this->assertDatabaseHas('stocks', ['product_id' => $store['product']->id, 'location_id' => $store['location']->id, 'quantity' => 0]);
        $this->assertDatabaseHas('low_stock_alerts', ['product_id' => $store['product']->id, 'quantity' => 0, 'active' => true]);
    }

    public function test_expired_subscription_blocks_drafts_but_still_updates_alerts(): void
    {
        $store = $this->store();
        $store['tenant']->forceFill(['created_at' => now()->subDays(30)])->save();
        (new CheckLowStock($store['tenant']->id))->handle();
        $this->assertDatabaseCount('purchase_orders', 0);
        $this->assertDatabaseHas('low_stock_alerts', ['product_id' => $store['product']->id, 'quantity' => 4, 'active' => true]);
    }

    public function test_api_create_upserts_exact_rule_and_exposes_scoped_names(): void
    {
        $store = $this->store();
        $store['rule']->delete();
        $payload = ['product_id' => $store['product']->id, 'location_id' => $store['location']->id, 'supplier_id' => $store['supplier']->id, 'target_quantity' => 25, 'enabled' => true, 'tenant_id' => 999];
        $response = $this->actingAs($store['user'])->postJson('/api/reorder-rules', $payload)->assertCreated()->assertJsonPath('product_name', $store['product']->name)->assertJsonPath('location_name', $store['location']->name)->assertJsonPath('supplier_name', $store['supplier']->name);
        $this->postJson('/api/reorder-rules', array_replace($payload, ['target_quantity' => 30]))->assertOk()->assertJsonPath('id', $response->json('id'))->assertJsonPath('target_quantity', 30);
        $this->getJson('/api/reorder-rules?location_id='.$store['location']->id)->assertOk()->assertJsonCount(1, 'data');
        $this->assertDatabaseCount('reorder_rules', 1);
        $this->assertDatabaseHas('reorder_rules', ['tenant_id' => $store['tenant']->id, 'target_quantity' => 30]);
    }

    public function test_api_generate_returns_new_drafts_and_empty_replay(): void
    {
        $store = $this->store();
        $this->actingAs($store['user'])->postJson('/api/reorder-rules/generate')->assertOk()->assertJsonPath('created_count', 1)->assertJsonPath('orders.0.status', 'draft')->assertJsonPath('orders.0.items.0.quantity', 16)->assertJsonCount(0, 'orders.0.receipts');
        $this->postJson('/api/reorder-rules/generate')->assertOk()->assertJsonPath('created_count', 0)->assertJsonCount(0, 'orders');
    }

    public function test_target_requires_more_than_threshold_and_pause_survives_threshold_change(): void
    {
        $store = $this->store();
        $url = '/api/reorder-rules/'.$store['rule']->id;
        $this->actingAs($store['user'])->patchJson($url, ['target_quantity' => 10])->assertUnprocessable()->assertJsonValidationErrors('target_quantity');
        $this->patchJson($url, ['enabled' => false])->assertOk()->assertJsonPath('enabled', false);
        $store['product']->update(['reorder_level' => 30]);
        $this->patchJson($url, ['enabled' => false])->assertOk();
        $this->patchJson($url, ['enabled' => true])->assertUnprocessable();
        $this->patchJson($url, ['target_quantity' => 40, 'enabled' => true])->assertOk()->assertJsonPath('target_quantity', 40);
        $this->patchJson($url, [])->assertUnprocessable();
    }

    public function test_rules_and_generation_are_role_limited_and_tenant_scoped(): void
    {
        $store = $this->store();
        $other = $this->store();
        $this->actingAs($store['user'])->getJson('/api/reorder-rules')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $store['rule']->id);
        $this->getJson('/api/reorder-rules?product_id='.$other['product']->id)->assertNotFound();
        $this->patchJson('/api/reorder-rules/'.$other['rule']->id, ['enabled' => false])->assertNotFound();
        $this->postJson('/api/reorder-rules', ['product_id' => $store['product']->id, 'location_id' => $store['location']->id, 'supplier_id' => $other['supplier']->id, 'target_quantity' => 20])->assertNotFound();
        $cashier = User::factory()->create(['tenant_id' => $store['tenant']->id, 'role' => 'cashier']);
        $this->actingAs($cashier)->getJson('/api/reorder-rules')->assertForbidden();
        $this->postJson('/api/reorder-rules/generate')->assertForbidden();
        $this->patchJson('/api/reorder-rules/'.$store['rule']->id, ['enabled' => false])->assertForbidden();
        $this->assertDatabaseCount('purchase_orders', 0);
    }

    public function test_paused_rule_owner_falls_back_to_active_admin(): void
    {
        $store = $this->store();
        $store['user']->update(['is_active' => false]);
        $active = User::factory()->create(['tenant_id' => $store['tenant']->id, 'role' => 'admin']);
        $orders = app(ReplenishmentService::class)->generateForTenant($store['tenant']->id);
        $this->assertCount(1, $orders);
        $this->assertSame($active->id, $orders[0]->user_id);
    }

    public function test_cancelling_a_draft_releases_coverage_and_enabled_rule_can_draft_again(): void
    {
        $store = $this->store();
        $service = app(ReplenishmentService::class);
        $first = $service->generateForTenant($store['tenant']->id)->sole();
        $this->actingAs($store['user'])->postJson('/api/purchase-orders/'.$first->id.'/cancel')->assertOk();
        $this->assertCount(1, $service->generateForTenant($store['tenant']->id));
        $this->assertDatabaseHas('purchase_orders', ['id' => $first->id, 'status' => 'cancelled']);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_stale_admin_session_cannot_update_rules_or_generate_drafts_after_demotion(): void
    {
        $store = $this->store();
        User::whereKey($store['user']->id)->update(['role' => 'cashier']);
        $this->actingAs($store['user'])->patchJson('/api/reorder-rules/'.$store['rule']->id, ['enabled' => false])->assertForbidden();
        $this->postJson('/api/reorder-rules', ['product_id' => $store['product']->id, 'location_id' => $store['location']->id, 'supplier_id' => $store['supplier']->id, 'target_quantity' => 30])->assertForbidden();
        $this->postJson('/api/reorder-rules/generate')->assertForbidden();
        $this->assertDatabaseHas('reorder_rules', ['id' => $store['rule']->id, 'enabled' => true, 'target_quantity' => 20]);
        $this->assertDatabaseCount('purchase_orders', 0);
    }
}
