<?php

namespace Tests\Feature;

use App\Jobs\CheckLowStock;
use App\Models\Customer;
use App\Models\Location;
use App\Models\Product;
use App\Models\Sale;
use App\Models\Stock;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Models\User;
use App\Services\InventoryService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class PlatformTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function store(string $role = 'admin', int $quantity = 10): array
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'role' => $role]);
        $location = Location::factory()->create(['tenant_id' => $tenant->id]);
        $product = Product::factory()->create(['tenant_id' => $tenant->id]);
        $stock = Stock::create(['tenant_id' => $tenant->id, 'product_id' => $product->id, 'location_id' => $location->id, 'quantity' => $quantity]);

        return compact('tenant', 'user', 'location', 'product', 'stock');
    }

    private function checkout(array $store, array $changes = []): array
    {
        return array_replace([
            'location_id' => $store['location']->id,
            'items' => [['product_id' => $store['product']->id, 'quantity' => 2]],
            'discount_percent' => 10, 'tax_percent' => 12,
            'payments' => [['method' => 'cash', 'amount' => 1000]],
            'idempotency_key' => 'checkout-unique-001',
        ], $changes);
    }

    public function test_unauthenticated_api_is_401_and_browser_requires_login(): void
    {
        $this->getJson('/api/bootstrap')->assertUnauthorized();
        $this->get('/app')->assertRedirect('/login');
    }

    public function test_checkout_computes_totals_server_side_and_deducts_stock(): void
    {
        $store = $this->store('cashier');
        $response = $this->actingAs($store['user'])->postJson('/api/sales', $this->checkout($store) + ['total' => 1]);
        $response->assertCreated()->assertJsonPath('subtotal', 900)->assertJsonPath('discount', 90)->assertJsonPath('tax', 97.2)->assertJsonPath('total', 907.2)->assertJsonPath('change', 92.8);
        $this->assertDatabaseHas('stocks', ['id' => $store['stock']->id, 'quantity' => 8]);
        $this->assertDatabaseHas('sales', ['total_cents' => 90720, 'user_id' => $store['user']->id]);
        $this->assertDatabaseHas('stock_movements', ['product_id' => $store['product']->id, 'quantity' => -2, 'type' => 'sale']);
    }

    public function test_checkout_replay_deducts_only_once(): void
    {
        $store = $this->store();
        $data = $this->checkout($store);
        $sale = app(InventoryService::class)->sale($store['user'], $data);
        $this->actingAs($store['user'])->postJson('/api/sales', $data)->assertCreated()->assertJsonPath('id', $sale->id);
        $this->assertDatabaseCount('sales', 1);
        $this->assertDatabaseHas('stocks', ['id' => $store['stock']->id, 'quantity' => 8]);
        $this->assertDatabaseCount('stock_movements', 1);
    }

    public function test_changed_checkout_replay_is_409(): void
    {
        $store = $this->store();
        app(InventoryService::class)->sale($store['user'], $this->checkout($store));
        $data = $this->checkout($store, ['discount_percent' => 20]);
        $this->actingAs($store['user'])->postJson('/api/sales', $data)->assertConflict();
        $this->assertDatabaseCount('sales', 1);
        $this->assertDatabaseHas('stocks', ['id' => $store['stock']->id, 'quantity' => 8]);
    }

    public function test_overselling_is_422_and_rolls_back_entire_checkout(): void
    {
        $store = $this->store('cashier', 1);
        $this->actingAs($store['user'])->postJson('/api/sales', $this->checkout($store))->assertUnprocessable()->assertJsonValidationErrors('items');
        $this->assertDatabaseCount('sales', 0);
        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertDatabaseHas('stocks', ['id' => $store['stock']->id, 'quantity' => 1]);
    }

    public function test_multi_item_failure_restores_prior_stock_deduction(): void
    {
        $store = $this->store();
        $other = Product::factory()->create(['tenant_id' => $store['tenant']->id]);
        Stock::create(['tenant_id' => $store['tenant']->id, 'product_id' => $other->id, 'location_id' => $store['location']->id, 'quantity' => 0]);
        $data = $this->checkout($store, ['items' => [['product_id' => $store['product']->id, 'quantity' => 1], ['product_id' => $other->id, 'quantity' => 1]]]);
        $this->actingAs($store['user'])->postJson('/api/sales', $data)->assertUnprocessable();
        $this->assertDatabaseHas('stocks', ['id' => $store['stock']->id, 'quantity' => 10]);
        $this->assertDatabaseCount('sales', 0);
    }

    public function test_checkout_requires_sufficient_tender_and_rejects_card_overpayment(): void
    {
        $store = $this->store();
        $data = $this->checkout($store, ['payments' => [['method' => 'cash', 'amount' => 100]]]);
        $this->actingAs($store['user'])->postJson('/api/sales', $data)->assertUnprocessable()->assertJsonValidationErrors('payments');
        $data['payments'] = [['method' => 'card', 'amount' => 1000]];
        $this->postJson('/api/sales', $data)->assertUnprocessable()->assertJsonValidationErrors('payments');
        $this->assertDatabaseCount('sales', 0);
    }

    public function test_checkout_supports_split_tender(): void
    {
        $store = $this->store();
        $data = $this->checkout($store, ['payments' => [['method' => 'card', 'amount' => 500], ['method' => 'wallet', 'amount' => 200], ['method' => 'cash', 'amount' => 300]]]);
        $this->actingAs($store['user'])->postJson('/api/sales', $data)->assertCreated()->assertJsonPath('paid', 1000)->assertJsonPath('change', 92.8);
        $this->assertDatabaseHas('sales', ['paid_cents' => 100000, 'change_cents' => 9280]);
    }

    public function test_tax_changes_are_rejected_as_422(): void
    {
        $store = $this->store();
        $this->actingAs($store['user'])->postJson('/api/sales', $this->checkout($store, ['tax_percent' => 0]))->assertUnprocessable()->assertJsonValidationErrors('tax_percent');
        $this->assertDatabaseCount('sales', 0);
    }

    public function test_cross_tenant_product_checkout_is_404(): void
    {
        $store = $this->store();
        $other = $this->store();
        $data = $this->checkout($store, ['items' => [['product_id' => $other['product']->id, 'quantity' => 1]]]);
        $this->actingAs($store['user'])->postJson('/api/sales', $data)->assertNotFound();
        $this->assertDatabaseCount('sales', 0);
    }

    public function test_cross_tenant_location_and_customer_checkout_are_404(): void
    {
        $store = $this->store();
        $other = $this->store();
        $customer = Customer::factory()->create(['tenant_id' => $other['tenant']->id]);
        $this->actingAs($store['user'])->postJson('/api/sales', $this->checkout($store, ['location_id' => $other['location']->id]))->assertNotFound();
        $this->postJson('/api/sales', $this->checkout($store, ['customer_id' => $customer->id]))->assertNotFound();
        $this->assertDatabaseCount('sales', 0);
    }

    public function test_sale_return_restocks_once_and_records_return(): void
    {
        $store = $this->store();
        $inventory = app(InventoryService::class);
        $sale = $inventory->sale($store['user'], $this->checkout($store));
        $inventory->returnSale($store['user'], $sale->id);
        $this->actingAs($store['user'])->postJson('/api/sales/'.$sale->id.'/return')->assertOk()->assertJsonPath('status', 'returned');
        $this->assertDatabaseHas('stocks', ['id' => $store['stock']->id, 'quantity' => 10]);
        $this->assertSame(1, StockMovement::where('type', 'return')->count());
        $this->assertDatabaseHas('sales', ['id' => $sale->id, 'status' => 'returned']);
    }

    public function test_cashier_cannot_return_or_edit_inventory_or_team(): void
    {
        $store = $this->store('cashier');
        $this->actingAs($store['user'])->postJson('/api/sales/1/return')->assertForbidden();
        $this->postJson('/api/products', [])->assertForbidden();
        $this->postJson('/api/stock/adjust', [])->assertForbidden();
        $this->postJson('/api/team', [])->assertForbidden();
        $this->putJson('/api/settings', ['tax_percent' => 0])->assertForbidden();
        $this->getJson('/api/reports/export')->assertForbidden();
    }

    public function test_manager_cannot_change_team_settings_or_export(): void
    {
        $store = $this->store('manager');
        $this->actingAs($store['user'])->postJson('/api/team', [])->assertForbidden();
        $this->putJson('/api/settings', ['tax_percent' => 0])->assertForbidden();
        $this->getJson('/api/reports/export')->assertForbidden();
    }

    public function test_transfer_moves_stock_atomically(): void
    {
        $store = $this->store('manager');
        $destination = Location::factory()->create(['tenant_id' => $store['tenant']->id]);
        $this->actingAs($store['user'])->postJson('/api/transfers', ['product_id' => $store['product']->id, 'from_location_id' => $store['location']->id, 'to_location_id' => $destination->id, 'quantity' => 3])->assertOk();
        $this->assertDatabaseHas('stocks', ['id' => $store['stock']->id, 'quantity' => 7]);
        $this->assertDatabaseHas('stocks', ['product_id' => $store['product']->id, 'location_id' => $destination->id, 'quantity' => 3]);
        $this->assertDatabaseCount('stock_movements', 2);
    }

    public function test_transfer_to_another_tenant_is_404_without_stock_change(): void
    {
        $store = $this->store();
        $other = $this->store();
        $this->actingAs($store['user'])->postJson('/api/transfers', ['product_id' => $store['product']->id, 'from_location_id' => $store['location']->id, 'to_location_id' => $other['location']->id, 'quantity' => 3])->assertNotFound();
        $this->assertDatabaseHas('stocks', ['id' => $store['stock']->id, 'quantity' => 10]);
    }

    public function test_failed_transfer_leaves_destination_empty(): void
    {
        $store = $this->store();
        $destination = Location::factory()->create(['tenant_id' => $store['tenant']->id]);
        $this->actingAs($store['user'])->postJson('/api/transfers', ['product_id' => $store['product']->id, 'from_location_id' => $store['location']->id, 'to_location_id' => $destination->id, 'quantity' => 11])->assertUnprocessable();
        $this->assertDatabaseCount('stocks', 1);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_purchase_receipt_restocks_once(): void
    {
        $store = $this->store();
        $supplier = Supplier::create(['tenant_id' => $store['tenant']->id, 'name' => 'Supplier']);
        $inventory = app(InventoryService::class);
        $order = $inventory->purchaseOrder($store['user'], ['supplier_id' => $supplier->id, 'location_id' => $store['location']->id, 'items' => [['product_id' => $store['product']->id, 'quantity' => 5]]]);
        $inventory->receive($store['user'], $order->id);
        $this->actingAs($store['user'])->postJson('/api/purchase-orders/'.$order->id.'/receive')->assertOk()->assertJsonPath('status', 'received');
        $this->assertDatabaseHas('stocks', ['id' => $store['stock']->id, 'quantity' => 15]);
        $this->assertDatabaseCount('stock_movements', 1);
    }

    public function test_bootstrap_never_exposes_other_tenant_records(): void
    {
        $store = $this->store();
        $other = $this->store();
        $response = $this->actingAs($store['user'])->getJson('/api/bootstrap')->assertOk()->assertJsonCount(1, 'products')->assertJsonCount(1, 'locations')->assertJsonCount(1, 'team');
        $this->assertSame($store['product']->id, $response->json('products.0.id'));
        $this->assertNotContains($other['product']->id, array_column($response->json('products'), 'id'));
        $this->assertArrayNotHasKey('password', $response->json('team.0'));
    }

    public function test_cashier_bootstrap_omits_team_cost_and_other_cashier_sales(): void
    {
        $store = $this->store('cashier');
        $otherCashier = User::factory()->create(['tenant_id' => $store['tenant']->id, 'role' => 'cashier']);
        app(InventoryService::class)->sale($otherCashier, $this->checkout($store));
        $this->actingAs($store['user'])->getJson('/api/bootstrap')->assertOk()->assertJsonPath('products.0.cost', 0)->assertJsonCount(0, 'team')->assertJsonCount(0, 'sales')->assertJsonPath('stats.inventory_value', null);
    }

    public function test_product_creation_creates_scoped_opening_stock(): void
    {
        $store = $this->store();
        $response = $this->actingAs($store['user'])->postJson('/api/products', ['name' => 'Test mug', 'sku' => 'NEW-MUG', 'category' => 'Home', 'price' => 320, 'cost' => 140, 'reorder_level' => 5, 'location_id' => $store['location']->id, 'quantity' => 12, 'tenant_id' => 99])->assertCreated()->assertJsonPath('price', 320);
        $this->assertDatabaseHas('products', ['id' => $response->json('id'), 'tenant_id' => $store['tenant']->id, 'price_cents' => 32000]);
        $this->assertDatabaseHas('stocks', ['product_id' => $response->json('id'), 'location_id' => $store['location']->id, 'quantity' => 12]);
    }

    public function test_stock_adjustment_rejects_negative_stock(): void
    {
        $store = $this->store();
        $this->actingAs($store['user'])->postJson('/api/products/'.$store['product']->id.'/adjust', ['location_id' => $store['location']->id, 'quantity' => -11, 'reason' => 'Stocktake'])->assertUnprocessable();
        $this->assertDatabaseHas('stocks', ['id' => $store['stock']->id, 'quantity' => 10]);
    }

    public function test_registration_creates_empty_isolated_workspace_and_admin(): void
    {
        $this->post('/register', ['business_name' => 'New shop', 'name' => 'Shop Owner', 'email' => 'owner@example.test', 'password' => 'correct-horse-battery', 'password_confirmation' => 'correct-horse-battery', 'plan' => 'starter', 'role' => 'cashier', 'tenant_id' => 77])->assertRedirect('/app');
        $this->assertAuthenticated();
        $this->assertDatabaseHas('users', ['email' => 'owner@example.test', 'role' => 'admin']);
        $this->assertDatabaseHas('tenants', ['name' => 'New shop', 'is_demo' => false]);
        $this->assertDatabaseHas('locations', ['name' => 'Main store']);
        $this->assertDatabaseCount('products', 0);
    }

    public function test_settings_allowlist_does_not_allow_subscribing_or_tenant_changes(): void
    {
        $store = $this->store();
        $this->actingAs($store['user'])->putJson('/api/settings', ['tax_percent' => 5, 'plan' => 'scale', 'subscription_status' => 'active', 'tenant_id' => 99])->assertOk()->assertJsonPath('tax_percent', 5)->assertJsonPath('subscription_status', 'trial');
        $this->assertSame($store['tenant']->id, $store['user']->refresh()->tenant_id);
    }

    public function test_low_stock_job_persists_and_resolves_alerts(): void
    {
        $store = $this->store('admin', 4);
        $job = new CheckLowStock($store['tenant']->id);
        $job->handle();
        $this->assertDatabaseHas('low_stock_alerts', ['product_id' => $store['product']->id, 'active' => true, 'quantity' => 4]);
        $store['stock']->update(['quantity' => 20]);
        $job->handle();
        $this->assertDatabaseHas('low_stock_alerts', ['product_id' => $store['product']->id, 'active' => false, 'quantity' => 20]);
    }

    public function test_marketing_events_reject_unknown_events_and_personal_fields(): void
    {
        $this->postJson('/events', ['event' => 'landing_view', 'variant' => 'b', 'email' => 'private@example.test'])->assertCreated();
        $this->assertDatabaseHas('analytics_events', ['event' => 'landing_view', 'variant' => 'b']);
        $this->postJson('/events', ['event' => 'arbitrary', 'variant' => 'a'])->assertUnprocessable()->assertJsonValidationErrors('event');
        $this->assertDatabaseCount('analytics_events', 1);
    }

    public function test_demo_endpoint_is_disabled_when_flag_is_false(): void
    {
        config(['stockroom.demo_enabled' => false]);
        $this->post('/demo')->assertNotFound();
        $this->assertGuest();
    }

    public function test_demo_login_and_existing_sessions_are_blocked_in_production(): void
    {
        $store = $this->store();
        $store['tenant']->update(['is_demo' => true]);
        $store['user']->update(['password' => 'known-demo-password']);
        app()->detectEnvironment(fn (): string => 'production');
        $this->withSession(['_token' => 'production-test-token'])->post('/login', ['_token' => 'production-test-token', 'email' => $store['user']->email, 'password' => 'known-demo-password'])->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->actingAs($store['user'])->getJson('/api/bootstrap')->assertForbidden();
    }

    public function test_bootstrap_aggregates_complete_history_beyond_receipt_limit(): void
    {
        $this->freezeTime();
        $store = $this->store();
        Sale::factory()->count(501)->create(['tenant_id' => $store['tenant']->id, 'user_id' => $store['user']->id, 'location_id' => $store['location']->id]);
        $this->actingAs($store['user'])->getJson('/api/bootstrap')->assertOk()
            ->assertJsonCount(500, 'sales')->assertJsonPath('history_total', 501)
            ->assertJsonPath('stats.transactions', 501)->assertJsonPath('stats.sales_today', 501)
            ->assertJsonPath('stats.revenue_total', 50100)->assertJsonPath('stats.revenue_today', 50100);
        $export = $this->get('/api/reports/export')->assertOk();
        $lines = explode("\n", trim($export->streamedContent()));
        $this->assertCount(502, $lines);
        $numbers = array_map(fn (string $line): string => str_getcsv($line, ',', '"', '')[0], array_slice($lines, 1));
        $this->assertCount(501, array_unique($numbers));
    }

    public function test_demo_redirects_to_requested_workspace_section(): void
    {
        $store = $this->store();
        $store['tenant']->update(['is_demo' => true]);
        config(['stockroom.demo_enabled' => true]);
        $this->post('/demo', ['section' => 'pos'])->assertRedirect('/app/pos');
        $this->assertAuthenticatedAs($store['user']);
    }

    public function test_return_records_supplied_reason(): void
    {
        $store = $this->store();
        $sale = app(InventoryService::class)->sale($store['user'], $this->checkout($store));
        $this->actingAs($store['user'])->postJson('/api/sales/'.$sale->id.'/return', ['reason' => 'Customer changed their mind'])->assertOk();
        $this->assertDatabaseHas('stock_movements', ['type' => 'return', 'reason' => 'Customer changed their mind']);
    }

    public function test_cashier_cannot_replay_another_cashiers_checkout_key(): void
    {
        $store = $this->store('cashier');
        $data = $this->checkout($store);
        $otherCashier = User::factory()->create(['tenant_id' => $store['tenant']->id, 'role' => 'cashier']);
        app(InventoryService::class)->sale($otherCashier, $data);
        $this->actingAs($store['user'])->postJson('/api/sales', $data)->assertConflict();
        $this->assertDatabaseCount('sales', 1);
        $this->assertDatabaseHas('sales', ['user_id' => $otherCashier->id]);
        $this->assertDatabaseHas('stocks', ['id' => $store['stock']->id, 'quantity' => 8]);
    }

    public function test_location_summaries_and_export_use_complete_scoped_history(): void
    {
        $store = $this->store();
        $secondLocation = Location::factory()->create(['tenant_id' => $store['tenant']->id]);
        Sale::factory()->create(['tenant_id' => $store['tenant']->id, 'user_id' => $store['user']->id, 'location_id' => $store['location']->id]);
        Sale::factory()->create(['tenant_id' => $store['tenant']->id, 'user_id' => $store['user']->id, 'location_id' => $secondLocation->id, 'total_cents' => 20000]);
        $response = $this->actingAs($store['user'])->getJson('/api/bootstrap')->assertOk();
        $this->assertSame(100, $response->json('stats_by_location.'.$store['location']->id.'.revenue_total'));
        $this->assertSame(200, $response->json('stats_by_location.'.$secondLocation->id.'.revenue_total'));
        $export = $this->get('/api/reports/export?location_id='.$secondLocation->id)->assertOk();
        $lines = explode("\n", trim($export->streamedContent()));
        $this->assertCount(2, $lines);
        $this->assertSame((string) $secondLocation->id, str_getcsv($lines[1], ',', '"', '')[7]);
        $otherStore = $this->store();
        $this->get('/api/reports/export?location_id='.$otherStore['location']->id)->assertNotFound();
    }

    public function test_large_valid_money_inputs_reject_insufficient_tender_without_overflow(): void
    {
        $store = $this->store();
        $store['tenant']->update(['settings' => array_replace($store['tenant']->settings, ['tax_percent' => 100])]);
        $products = Product::factory()->count(100)->create(['tenant_id' => $store['tenant']->id, 'price_cents' => 100000000]);
        $data = $this->checkout($store, ['items' => $products->map(fn (Product $product): array => ['product_id' => $product->id, 'quantity' => 100000])->all(), 'discount_percent' => 0, 'tax_percent' => 100]);
        $this->actingAs($store['user'])->postJson('/api/sales', $data)->assertUnprocessable()->assertJsonValidationErrors('payments');
        $this->assertDatabaseCount('sales', 0);
    }
}
