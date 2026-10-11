<?php

namespace Tests\Feature;

use App\Models\Location;
use App\Models\Product;
use App\Models\SaleReturn;
use App\Models\Stock;
use App\Models\StockMovement;
use App\Models\Tenant;
use App\Models\User;
use App\Services\InventoryService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class PartialReturnsTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function store(string $role = 'manager', int $price = 45000, int $quantity = 3, float $discount = 10, float $tax = 12): array
    {
        $tenant = Tenant::factory()->create(['settings' => array_replace(Tenant::defaultSettings(), ['tax_percent' => $tax])]);
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'role' => $role]);
        $location = Location::factory()->create(['tenant_id' => $tenant->id]);
        $product = Product::factory()->create(['tenant_id' => $tenant->id, 'price_cents' => $price]);
        $stock = Stock::create(['tenant_id' => $tenant->id, 'location_id' => $location->id, 'product_id' => $product->id, 'quantity' => 10]);
        $sale = app(InventoryService::class)->sale($user, ['location_id' => $location->id, 'items' => [['product_id' => $product->id, 'quantity' => $quantity]], 'discount_percent' => $discount, 'tax_percent' => $tax, 'payments' => [['method' => 'cash', 'amount' => 10000]], 'idempotency_key' => 'partial-sale-key']);

        return compact('tenant', 'user', 'location', 'product', 'stock', 'sale');
    }

    private function returnData(array $store, int $quantity = 1, string $key = 'return-confirmation-001'): array
    {
        return ['items' => [['product_id' => $store['product']->id, 'quantity' => $quantity]], 'reason' => 'Size did not fit', 'idempotency_key' => $key];
    }

    public function test_partial_return_restocks_selected_quantity_and_records_exact_original_refund(): void
    {
        $store = $this->store();
        $store['product']->update(['price_cents' => 99999]);
        $store['tenant']->update(['settings' => array_replace($store['tenant']->settings, ['tax_percent' => 25])]);

        $this->actingAs($store['user'])->postJson('/api/sales/'.$store['sale']->id.'/return', $this->returnData($store))->assertOk()
            ->assertJsonPath('status', 'partially_returned')->assertJsonPath('returned_amount', 453.6)->assertJsonPath('net_total', 907.2)
            ->assertJsonPath('items.0.returned_quantity', 1)->assertJsonPath('items.0.returnable_quantity', 2)
            ->assertJsonPath('returns.0.reason', 'Size did not fit')->assertJsonPath('returns.0.subtotal', 450)
            ->assertJsonPath('returns.0.discount', 45)->assertJsonPath('returns.0.tax', 48.6)->assertJsonPath('returns.0.total', 453.6);
        $this->assertDatabaseHas('stocks', ['id' => $store['stock']->id, 'quantity' => 8]);
        $this->assertDatabaseHas('sale_returns', ['sale_id' => $store['sale']->id, 'total_cents' => 45360, 'user_id' => $store['user']->id]);
        $this->assertDatabaseHas('stock_movements', ['type' => 'return', 'quantity' => 1, 'reason' => 'Size did not fit']);
        $this->assertNull($store['sale']->refresh()->returned_at);
    }

    public function test_same_return_key_replay_restocks_only_once_even_after_other_returns(): void
    {
        $store = $this->store();
        $data = $this->returnData($store);
        $this->actingAs($store['user'])->postJson('/api/sales/'.$store['sale']->id.'/return', $data)->assertOk();
        $this->postJson('/api/sales/'.$store['sale']->id.'/return', $this->returnData($store, 1, 'return-confirmation-002'))->assertOk();
        $this->postJson('/api/sales/'.$store['sale']->id.'/return', $data)->assertOk()->assertJsonCount(2, 'returns')->assertJsonPath('returned_amount', 907.2);
        $this->assertDatabaseCount('sale_returns', 2);
        $this->assertSame(2, StockMovement::where('type', 'return')->count());
        $this->assertDatabaseHas('stocks', ['id' => $store['stock']->id, 'quantity' => 9]);
    }

    public function test_changed_return_payload_or_actor_for_same_key_is_409(): void
    {
        $store = $this->store();
        $data = $this->returnData($store);
        $this->actingAs($store['user'])->postJson('/api/sales/'.$store['sale']->id.'/return', $data)->assertOk();
        $this->postJson('/api/sales/'.$store['sale']->id.'/return', $this->returnData($store, 2))->assertConflict();
        $other = User::factory()->create(['tenant_id' => $store['tenant']->id, 'role' => 'manager']);
        $this->actingAs($other)->postJson('/api/sales/'.$store['sale']->id.'/return', $data)->assertConflict();
        $this->assertDatabaseCount('sale_returns', 1);
        $this->assertDatabaseHas('stocks', ['id' => $store['stock']->id, 'quantity' => 8]);
    }

    public function test_legacy_empty_full_return_returns_only_remaining_items_once(): void
    {
        $store = $this->store();
        $this->actingAs($store['user'])->postJson('/api/sales/'.$store['sale']->id.'/return', $this->returnData($store))->assertOk();
        $this->postJson('/api/sales/'.$store['sale']->id.'/return', [])->assertOk()->assertJsonPath('status', 'returned')
            ->assertJsonPath('returned_amount', 1360.8)->assertJsonPath('net_total', 0)
            ->assertJsonPath('returns.1.items.0.quantity', 2)->assertJsonPath('returns.1.total', 907.2);
        $this->postJson('/api/sales/'.$store['sale']->id.'/return', [])->assertOk()->assertJsonCount(2, 'returns');
        $this->assertDatabaseHas('stocks', ['id' => $store['stock']->id, 'quantity' => 10]);
        $this->assertDatabaseCount('sale_returns', 2);
        $this->assertNotNull($store['sale']->refresh()->returned_at);
    }

    public function test_full_remaining_return_key_replay_does_not_change_selected_items(): void
    {
        $store = $this->store();
        $data = ['reason' => 'Order cancelled', 'idempotency_key' => 'full-return-confirmation'];
        $this->actingAs($store['user'])->postJson('/api/sales/'.$store['sale']->id.'/return', $data)->assertOk();
        $this->postJson('/api/sales/'.$store['sale']->id.'/return', $data)->assertOk()->assertJsonCount(1, 'returns')->assertJsonPath('returns.0.total', 1360.8);
        $this->assertDatabaseHas('stocks', ['id' => $store['stock']->id, 'quantity' => 10]);
    }

    public function test_one_unit_returns_allocate_discount_cumulatively_without_rounding_drift(): void
    {
        $store = $this->store(price: 1, discount: 20, tax: 0);
        $this->actingAs($store['user']);
        foreach (['rounding-return-001', 'rounding-return-002', 'rounding-return-003'] as $key) {
            $this->postJson('/api/sales/'.$store['sale']->id.'/return', $this->returnData($store, 1, $key))->assertOk();
        }
        $refunds = SaleReturn::where('sale_id', $store['sale']->id)->orderBy('id')->get();
        $this->assertSame([1, 0, 1], $refunds->pluck('total_cents')->all());
        $this->assertSame([0, 1, 0], $refunds->pluck('discount_cents')->all());
        $this->assertSame(2, $store['sale']->refresh()->returned_cents);
        $this->assertSame('returned', $store['sale']->status);
        $this->assertDatabaseHas('stocks', ['id' => $store['stock']->id, 'quantity' => 10]);
    }

    public function test_one_unit_returns_allocate_tax_cumulatively_without_rounding_drift(): void
    {
        $store = $this->store(price: 1, discount: 0, tax: 33.33);
        $this->actingAs($store['user']);
        foreach (['tax-return-001', 'tax-return-002', 'tax-return-003'] as $key) {
            $this->postJson('/api/sales/'.$store['sale']->id.'/return', $this->returnData($store, 1, $key))->assertOk();
        }
        $refunds = SaleReturn::where('sale_id', $store['sale']->id)->orderBy('id')->get();
        $this->assertSame([1, 2, 1], $refunds->pluck('total_cents')->all());
        $this->assertSame([0, 1, 0], $refunds->pluck('tax_cents')->all());
        $this->assertSame(4, $store['sale']->refresh()->returned_cents);
    }

    public function test_multi_line_refunds_preserve_original_global_discount_tax_and_total(): void
    {
        $store = $this->store(price: 1, discount: 20, tax: 25);
        $other = Product::factory()->create(['tenant_id' => $store['tenant']->id, 'price_cents' => 1]);
        Stock::create(['tenant_id' => $store['tenant']->id, 'location_id' => $store['location']->id, 'product_id' => $other->id, 'quantity' => 2]);
        $sale = app(InventoryService::class)->sale($store['user'], ['location_id' => $store['location']->id, 'items' => [['product_id' => $store['product']->id, 'quantity' => 3], ['product_id' => $other->id, 'quantity' => 2]], 'discount_percent' => 20, 'tax_percent' => 25, 'payments' => [['method' => 'cash', 'amount' => 1]], 'idempotency_key' => 'multiple-lines-sale']);
        $this->actingAs($store['user'])->postJson('/api/sales/'.$sale->id.'/return', ['items' => [['product_id' => $other->id, 'quantity' => 1]], 'reason' => 'Other line first', 'idempotency_key' => 'other-line-return'])->assertOk();
        $this->postJson('/api/sales/'.$sale->id.'/return', [])->assertOk()->assertJsonPath('returned_amount', 0.05)->assertJsonPath('net_total', 0);
        $refunds = SaleReturn::where('sale_id', $sale->id)->get();
        $this->assertSame(5, $refunds->sum('subtotal_cents'));
        $this->assertSame(1, $refunds->sum('discount_cents'));
        $this->assertSame(1, $refunds->sum('tax_cents'));
        $this->assertSame(5, $refunds->sum('total_cents'));
    }

    public function test_free_sale_is_partially_returned_by_quantity_even_when_refund_is_zero(): void
    {
        $store = $this->store(discount: 100);
        $this->actingAs($store['user'])->postJson('/api/sales/'.$store['sale']->id.'/return', $this->returnData($store))->assertOk()->assertJsonPath('status', 'partially_returned')->assertJsonPath('returned_amount', 0)->assertJsonPath('items.0.returnable_quantity', 2);
        $this->assertDatabaseHas('stocks', ['id' => $store['stock']->id, 'quantity' => 8]);
    }

    public function test_large_valid_allocations_do_not_overflow_and_refund_exact_original_total(): void
    {
        $tenant = Tenant::factory()->create(['settings' => array_replace(Tenant::defaultSettings(), ['tax_percent' => 100])]);
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'role' => 'manager']);
        $location = Location::factory()->create(['tenant_id' => $tenant->id]);
        $products = Product::factory()->count(2)->create(['tenant_id' => $tenant->id, 'price_cents' => 100000000]);
        foreach ($products as $product) {
            Stock::create(['tenant_id' => $tenant->id, 'location_id' => $location->id, 'product_id' => $product->id, 'quantity' => 100000]);
        }
        $sale = $this->actingAs($user)->postJson('/api/sales', ['location_id' => $location->id, 'items' => $products->map(fn (Product $product): array => ['product_id' => $product->id, 'quantity' => 100000])->all(), 'discount_percent' => 99.99, 'tax_percent' => 100, 'payments' => [['method' => 'cash', 'amount' => 40000000]], 'idempotency_key' => 'large-allocated-sale'])->assertCreated()->assertJsonPath('total', 40000000);
        $saleId = $sale->json('id');

        $this->postJson('/api/sales/'.$saleId.'/return', ['items' => [['product_id' => $products->first()->id, 'quantity' => 1]], 'reason' => 'One unit', 'idempotency_key' => 'large-one-unit-return'])->assertOk()->assertJsonPath('returned_amount', 200);
        $this->postJson('/api/sales/'.$saleId.'/return', [])->assertOk()->assertJsonPath('returned_amount', 40000000)->assertJsonPath('net_total', 0);
        $this->assertSame(4000000000, SaleReturn::where('sale_id', $saleId)->sum('total_cents'));
        $this->assertSame(19998000000000, SaleReturn::where('sale_id', $saleId)->sum('discount_cents'));
        $this->assertSame(2000000000, SaleReturn::where('sale_id', $saleId)->sum('tax_cents'));
        foreach ($products as $product) {
            $this->assertDatabaseHas('stocks', ['product_id' => $product->id, 'quantity' => 100000]);
        }
    }

    public function test_excessive_and_missing_receipt_items_are_422_without_restock(): void
    {
        $store = $this->store();
        $this->actingAs($store['user'])->postJson('/api/sales/'.$store['sale']->id.'/return', $this->returnData($store, 4))->assertUnprocessable()->assertJsonValidationErrors('items');
        $data = $this->returnData($store);
        $data['items'][0]['product_id'] = 999999;
        $this->postJson('/api/sales/'.$store['sale']->id.'/return', $data)->assertUnprocessable()->assertJsonValidationErrors('items');
        $this->assertDatabaseCount('sale_returns', 0);
        $this->assertDatabaseHas('stocks', ['id' => $store['stock']->id, 'quantity' => 7]);
    }

    public function test_partial_return_requires_reason_idempotency_and_positive_distinct_quantities(): void
    {
        $store = $this->store();
        $this->actingAs($store['user'])->postJson('/api/sales/'.$store['sale']->id.'/return', ['items' => [['product_id' => $store['product']->id, 'quantity' => 0], ['product_id' => $store['product']->id, 'quantity' => 1]]])->assertUnprocessable()->assertJsonValidationErrors(['reason', 'idempotency_key', 'items.0.quantity', 'items.0.product_id']);
        $this->assertDatabaseCount('sale_returns', 0);
    }

    public function test_cashier_cannot_return_and_cross_tenant_receipt_is_404(): void
    {
        $store = $this->store('cashier');
        $other = $this->store();
        $this->actingAs($store['user'])->postJson('/api/sales/'.$store['sale']->id.'/return', $this->returnData($store))->assertForbidden();
        $this->actingAs($other['user'])->postJson('/api/sales/'.$store['sale']->id.'/return', $this->returnData($store))->assertNotFound();
        $this->assertDatabaseCount('sale_returns', 0);
        $this->assertDatabaseHas('stocks', ['id' => $store['stock']->id, 'quantity' => 7]);
    }

    public function test_old_receipt_without_line_allocations_can_be_partially_returned(): void
    {
        $store = $this->store();
        $items = $store['sale']->items;
        foreach ($items as &$item) {
            unset($item['discount_cents'], $item['tax_cents'], $item['total_cents'], $item['returned_quantity']);
            $item['quantity'] = (string) $item['quantity'];
        }
        unset($item);
        $store['sale']->update(['items' => $items]);
        $this->actingAs($store['user'])->postJson('/api/sales/'.$store['sale']->id.'/return', $this->returnData($store, 3))->assertOk()->assertJsonPath('status', 'returned')->assertJsonPath('returned_amount', 1360.8);
        $this->assertDatabaseHas('stocks', ['id' => $store['stock']->id, 'quantity' => 10]);
    }

    public function test_late_stock_failure_rolls_back_all_refund_history_and_prior_restock(): void
    {
        $store = $this->store();
        $other = Product::factory()->create(['tenant_id' => $store['tenant']->id]);
        Stock::create(['tenant_id' => $store['tenant']->id, 'location_id' => $store['location']->id, 'product_id' => $other->id, 'quantity' => 2]);
        $items = $store['sale']->items;
        $items[] = ['product_id' => $other->id, 'name' => $other->name, 'sku' => $other->sku, 'quantity' => 1, 'unit_price_cents' => 45000, 'line_total_cents' => 45000];
        $store['sale']->update(['items' => $items, 'subtotal_cents' => 180000, 'discount_cents' => 18000, 'tax_cents' => 19440, 'total_cents' => 181440]);
        $other->update(['tenant_id' => Tenant::factory()->create()->id]);
        $this->actingAs($store['user'])->postJson('/api/sales/'.$store['sale']->id.'/return', [])->assertNotFound();
        $this->assertDatabaseCount('sale_returns', 0);
        $this->assertDatabaseHas('stocks', ['id' => $store['stock']->id, 'quantity' => 7]);
        $this->assertDatabaseHas('sales', ['id' => $store['sale']->id, 'returned_cents' => 0, 'status' => 'completed']);
    }
}
