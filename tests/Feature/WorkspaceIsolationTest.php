<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Location;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\Sale;
use App\Models\Stock;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class WorkspaceIsolationTest extends TestCase
{
    use LazilyRefreshDatabase;

    /** @return iterable<string, array{string, string}> */
    public static function restrictedPages(): iterable
    {
        foreach (['team', 'settings', 'activity', 'billing', 'monitoring'] as $section) {
            foreach (['manager', 'cashier'] as $role) {
                yield $role.' '.$section => [$role, $section];
            }
        }
        foreach (['purchases', 'orders', 'channels'] as $section) {
            yield 'cashier '.$section => ['cashier', $section];
        }
    }

    #[DataProvider('restrictedPages')]
    public function test_restricted_workspace_pages_return_403_before_rendering_privileged_controls(string $role, string $section): void
    {
        $user = User::factory()->create(['role' => $role]);
        $before = $user->refresh()->getRawOriginal();
        Http::preventStrayRequests();

        $this->actingAs($user)->get('/app/'.$section)->assertForbidden()->assertDontSee('id="workspace-view"', false);

        $this->assertSame($before, $user->refresh()->getRawOriginal());
        $this->assertDatabaseCount('subscriptions', 0);
        $this->assertDatabaseCount('billing_invoices', 0);
        Http::assertNothingSent();
    }

    /** @return iterable<string, array{string, string}> */
    public static function allowedPages(): iterable
    {
        foreach (['admin', 'manager', 'cashier'] as $role) {
            foreach (['', 'overview', 'dashboard', 'pos', 'inventory', 'customers', 'reports', 'sales', 'guide'] as $section) {
                yield $role.' general '.$section => [$role, $section];
            }
        }
        foreach (['team', 'settings', 'activity', 'billing', 'monitoring'] as $section) {
            yield 'admin '.$section => ['admin', $section];
        }
        foreach (['admin', 'manager'] as $role) {
            foreach (['purchases', 'orders', 'channels'] as $section) {
                yield $role.' management '.$section => [$role, $section];
            }
        }
    }

    #[DataProvider('allowedPages')]
    public function test_permitted_workspace_pages_and_legacy_aliases_render_for_the_current_role(string $role, string $section): void
    {
        $user = User::factory()->create(['role' => $role]);
        Http::preventStrayRequests();

        $this->actingAs($user)->get('/app'.($section === '' ? '' : '/'.$section))->assertOk()->assertViewIs('workspace');

        Http::assertNothingSent();
    }

    /** @return array<string, array{string, int, int, int, int, ?int}> */
    public static function bootstrapRoles(): array
    {
        return ['administrator' => ['admin', 3, 1, 1, 2, 370], 'manager' => ['manager', 0, 1, 1, 2, 370], 'cashier' => ['cashier', 0, 0, 0, 1, null]];
    }

    #[DataProvider('bootstrapRoles')]
    public function test_bootstrap_ignores_forged_scope_and_preserves_role_sensitive_company_data(string $role, int $teamCount, int $supplierCount, int $purchaseCount, int $salesCount, ?int $inventoryValue): void
    {
        $local = $this->company('COMPANY-A-SENTINEL');
        $foreign = $this->company('COMPANY-B-SENTINEL');
        $actor = $local['users'][$role];
        Http::preventStrayRequests();
        $query = http_build_query(['tenant_id' => $foreign['tenant']->id, 'user_id' => $foreign['users']['admin']->id, 'actor_id' => $foreign['users']['admin']->id, 'role' => 'admin']);

        $response = $this->actingAs($actor)->getJson('/api/bootstrap?'.$query)->assertOk()
            ->assertJsonPath('tenant.id', $local['tenant']->id)->assertJsonPath('user.id', $actor->id)->assertJsonPath('user.role', $role)
            ->assertJsonCount(1, 'products')->assertJsonPath('products.0.id', $local['product']->id)
            ->assertJsonPath('products.0.cost', $inventoryValue === null ? 0 : 37)
            ->assertJsonPath('products.0.stocks.0.location_id', $local['location']->id)
            ->assertJsonCount(1, 'locations')->assertJsonCount(1, 'customers')->assertJsonPath('customers.0.id', $local['customer']->id)
            ->assertJsonCount($teamCount, 'team')->assertJsonCount($supplierCount, 'suppliers')->assertJsonCount($purchaseCount, 'purchase_orders')
            ->assertJsonCount($salesCount, 'sales')->assertJsonPath('history_total', $salesCount)
            ->assertJsonPath('stats.inventory_value', $inventoryValue)->assertJsonPath('settings.receipt_address', 'COMPANY-A-SENTINEL address')
            ->assertDontSee('COMPANY-B-SENTINEL');

        $this->assertContains($local['cashierSale']->id, array_column($response->json('sales'), 'id'));
        $this->assertDatabaseHas('stocks', ['id' => $foreign['stock']->id, 'quantity' => 10]);
        $this->assertDatabaseHas('sales', ['id' => $foreign['cashierSale']->id, 'total_cents' => 10000]);
        Http::assertNothingSent();
    }

    public function test_foreign_product_edit_returns_404_without_changing_catalog_or_stock(): void
    {
        $actor = User::factory()->create(['role' => 'admin']);
        $foreignProduct = Product::factory()->create(['name' => 'Foreign company product']);
        $foreignLocation = Location::factory()->create(['tenant_id' => $foreignProduct->tenant_id]);
        $foreignStock = Stock::factory()->create(['tenant_id' => $foreignProduct->tenant_id, 'product_id' => $foreignProduct->id, 'location_id' => $foreignLocation->id, 'quantity' => 10]);
        $before = $foreignProduct->refresh()->getRawOriginal();
        Http::preventStrayRequests();

        $this->actingAs($actor)->putJson('/api/products/'.$foreignProduct->id, [
            'name' => 'Forged replacement', 'sku' => 'FORGED-SKU', 'category' => 'Retail', 'price' => 1,
            'cost' => 1, 'reorder_level' => 0, 'tenant_id' => $actor->tenant_id,
        ])->assertNotFound();

        $this->assertSame($before, $foreignProduct->refresh()->getRawOriginal());
        $this->assertDatabaseHas('stocks', ['id' => $foreignStock->id, 'quantity' => 10]);
        $this->assertDatabaseCount('stock_movements', 0);
        Http::assertNothingSent();
    }

    /** @return array{tenant: Tenant, users: array<string, User>, location: Location, product: Product, stock: Stock, customer: Customer, cashierSale: Sale} */
    private function company(string $name): array
    {
        $tenant = Tenant::factory()->create(['name' => $name, 'settings' => array_replace(Tenant::defaultSettings(), ['receipt_address' => $name.' address'])]);
        $users = [];
        foreach (['admin', 'manager', 'cashier'] as $role) {
            $users[$role] = User::factory()->create(['tenant_id' => $tenant->id, 'role' => $role, 'name' => $name.' '.$role]);
        }
        $location = Location::factory()->create(['tenant_id' => $tenant->id, 'name' => $name.' store']);
        $product = Product::factory()->create(['tenant_id' => $tenant->id, 'name' => $name.' product', 'sku' => $name.'-SKU', 'cost_cents' => 3700]);
        $stock = Stock::factory()->create(['tenant_id' => $tenant->id, 'location_id' => $location->id, 'product_id' => $product->id, 'quantity' => 10]);
        $customer = Customer::factory()->create(['tenant_id' => $tenant->id, 'name' => $name.' customer']);
        $supplier = Supplier::factory()->create(['tenant_id' => $tenant->id, 'name' => $name.' supplier']);
        PurchaseOrder::factory()->create(['tenant_id' => $tenant->id, 'supplier_id' => $supplier->id, 'location_id' => $location->id,
            'user_id' => $users['admin']->id, 'items' => [['product_id' => $product->id, 'name' => $product->name, 'sku' => $product->sku,
                'quantity' => 5, 'received_quantity' => 0, 'unit_cost' => 37, 'line_total' => 185]]]);
        $cashierSale = Sale::factory()->create(['tenant_id' => $tenant->id, 'location_id' => $location->id,
            'user_id' => $users['cashier']->id, 'customer_id' => $customer->id, 'number' => $name.'-CASHIER']);
        Sale::factory()->create(['tenant_id' => $tenant->id, 'location_id' => $location->id, 'user_id' => $users['admin']->id,
            'customer_id' => $customer->id, 'number' => $name.'-ADMIN', 'subtotal_cents' => 25000, 'total_cents' => 25000,
            'paid_cents' => 25000, 'payments' => [['method' => 'cash', 'amount_cents' => 25000]]]);

        return compact('tenant', 'users', 'location', 'product', 'stock', 'customer', 'cashierSale');
    }
}
