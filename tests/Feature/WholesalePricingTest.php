<?php

namespace Tests\Feature;

use App\Models\Location;
use App\Models\Product;
use App\Models\Sale;
use App\Models\Stock;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class WholesalePricingTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function store(string $role = 'admin', ?int $wholesale = 30000): array
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'role' => $role]);
        $location = Location::factory()->create(['tenant_id' => $tenant->id]);
        $product = Product::factory()->create(['tenant_id' => $tenant->id, 'price_cents' => 45000, 'wholesale_price_cents' => $wholesale]);
        Stock::create(['tenant_id' => $tenant->id, 'location_id' => $location->id, 'product_id' => $product->id, 'quantity' => 10]);

        return compact('tenant', 'user', 'location', 'product');
    }

    private function checkout(array $store, array $changes = []): array
    {
        return array_replace(['location_id' => $store['location']->id, 'items' => [['product_id' => $store['product']->id, 'quantity' => '2', 'price' => 1]], 'discount_percent' => 10, 'tax_percent' => 12, 'payments' => [['method' => 'cash', 'amount' => 1000]], 'idempotency_key' => 'wholesale-checkout'], $changes);
    }

    private function productData(array $store, array $changes = []): array
    {
        return array_replace(['name' => 'Canvas tote', 'sku' => 'NEW-TOTE', 'category' => 'Bags', 'price' => 450, 'cost' => 210, 'reorder_level' => 10, 'location_id' => $store['location']->id, 'quantity' => 0], $changes);
    }

    public function test_wholesale_checkout_uses_server_price_and_original_discount_tax_snapshot(): void
    {
        $store = $this->store('cashier');
        $this->actingAs($store['user'])->postJson('/api/sales', $this->checkout($store, ['price_tier' => 'wholesale']))->assertCreated()
            ->assertJsonPath('price_tier', 'wholesale')->assertJsonPath('subtotal', 600)->assertJsonPath('discount', 60)
            ->assertJsonPath('tax', 64.8)->assertJsonPath('total', 604.8)->assertJsonPath('items.0.unit_price', 300)
            ->assertJsonPath('items.0.quantity', 2)->assertJsonPath('net_total', 604.8);
        $this->assertDatabaseHas('sales', ['price_tier' => 'wholesale', 'subtotal_cents' => 60000, 'total_cents' => 60480]);
        $this->assertDatabaseHas('stocks', ['product_id' => $store['product']->id, 'quantity' => 8]);
    }

    public function test_omitted_price_tier_uses_retail_even_when_wholesale_is_available(): void
    {
        $store = $this->store();
        $this->actingAs($store['user'])->postJson('/api/sales', $this->checkout($store))->assertCreated()->assertJsonPath('price_tier', 'retail')->assertJsonPath('subtotal', 900)->assertJsonPath('total', 907.2);
    }

    public function test_wholesale_missing_price_and_invalid_tier_are_422_without_stock_deduction(): void
    {
        $store = $this->store(wholesale: null);
        $this->actingAs($store['user'])->postJson('/api/sales', $this->checkout($store, ['price_tier' => 'wholesale']))->assertUnprocessable()->assertJsonValidationErrors('items');
        $this->postJson('/api/sales', $this->checkout($store, ['price_tier' => 'unlimited']))->assertUnprocessable()->assertJsonValidationErrors('price_tier');
        $this->assertDatabaseCount('sales', 0);
        $this->assertDatabaseHas('stocks', ['product_id' => $store['product']->id, 'quantity' => 10]);
    }

    public function test_zero_wholesale_price_is_valid_and_distinct_from_missing_price(): void
    {
        $store = $this->store(wholesale: 0);
        $this->actingAs($store['user'])->postJson('/api/sales', $this->checkout($store, ['price_tier' => 'wholesale']))->assertCreated()->assertJsonPath('items.0.unit_price', 0)->assertJsonPath('total', 0);
        $this->assertDatabaseHas('stocks', ['product_id' => $store['product']->id, 'quantity' => 8]);
    }

    public function test_checkout_tier_is_bound_to_idempotency_key_and_catalog_edit_does_not_change_replay(): void
    {
        $store = $this->store();
        $data = $this->checkout($store, ['price_tier' => 'wholesale']);
        $this->actingAs($store['user'])->postJson('/api/sales', $data)->assertCreated();
        $store['product']->update(['wholesale_price_cents' => 100]);
        $this->postJson('/api/sales', $data)->assertCreated()->assertJsonPath('total', 604.8)->assertJsonPath('items.0.unit_price', 300);
        $this->postJson('/api/sales', array_replace($data, ['price_tier' => 'retail']))->assertConflict();
        $this->assertDatabaseCount('sales', 1);
        $this->assertDatabaseHas('stocks', ['product_id' => $store['product']->id, 'quantity' => 8]);
    }

    public function test_product_price_tier_can_be_created_updated_preserved_and_removed(): void
    {
        $store = $this->store();
        $created = $this->actingAs($store['user'])->postJson('/api/products', $this->productData($store, ['wholesale_price' => 289.99]))->assertCreated()->assertJsonPath('wholesale_price', 289.99);
        $productId = $created->json('id');
        $this->assertDatabaseHas('products', ['id' => $productId, 'wholesale_price_cents' => 28999]);
        $this->putJson('/api/products/'.$productId, $this->productData($store))->assertOk()->assertJsonPath('wholesale_price', 289.99);
        $this->putJson('/api/products/'.$productId, $this->productData($store, ['wholesale_price' => 275]))->assertOk()->assertJsonPath('wholesale_price', 275);
        $this->putJson('/api/products/'.$productId, $this->productData($store, ['wholesale_price' => null]))->assertOk()->assertJsonPath('wholesale_price', null);
        $this->assertDatabaseHas('products', ['id' => $productId, 'wholesale_price_cents' => null]);
    }

    public function test_invalid_wholesale_price_and_cashier_product_edits_are_rejected(): void
    {
        $store = $this->store();
        $this->actingAs($store['user'])->postJson('/api/products', $this->productData($store, ['wholesale_price' => -1]))->assertUnprocessable()->assertJsonValidationErrors('wholesale_price');
        $cashier = User::factory()->create(['tenant_id' => $store['tenant']->id, 'role' => 'cashier']);
        $this->actingAs($cashier)->putJson('/api/products/'.$store['product']->id, $this->productData($store, ['wholesale_price' => 1]))->assertForbidden();
        $this->assertSame(30000, $store['product']->refresh()->wholesale_price_cents);
    }

    public function test_wholesale_refund_uses_saved_tier_price_after_catalog_changes(): void
    {
        $store = $this->store();
        $sale = $this->actingAs($store['user'])->postJson('/api/sales', $this->checkout($store, ['price_tier' => 'wholesale']))->assertCreated();
        $store['product']->update(['wholesale_price_cents' => 100]);
        $this->postJson('/api/sales/'.$sale->json('id').'/return', ['items' => [['product_id' => $store['product']->id, 'quantity' => 1]], 'reason' => 'Damaged', 'idempotency_key' => 'wholesale-partial-return'])->assertOk()
            ->assertJsonPath('price_tier', 'wholesale')->assertJsonPath('returned_amount', 302.4)->assertJsonPath('net_total', 302.4)->assertJsonPath('returns.0.items.0.subtotal', 300);
    }

    public function test_legacy_retail_checkout_hash_can_be_replayed_after_upgrade(): void
    {
        $store = $this->store();
        $data = $this->checkout($store);
        $sale = $this->actingAs($store['user'])->postJson('/api/sales', $data)->assertCreated();
        $legacy = ['cashier_id' => $store['user']->id, 'location_id' => $store['location']->id, 'customer_id' => null, 'items' => [['product_id' => $store['product']->id, 'quantity' => 2]], 'discount' => 1000, 'tax' => 1200, 'payments' => [['method' => 'cash', 'amount' => 100000]]];
        Sale::findOrFail($sale->json('id'))->update(['request_hash' => hash('sha256', json_encode($legacy, JSON_THROW_ON_ERROR))]);
        $this->postJson('/api/sales', $data)->assertCreated()->assertJsonPath('id', $sale->json('id'));
        $this->postJson('/api/sales', array_replace($data, ['price_tier' => 'wholesale']))->assertConflict();
        $this->assertDatabaseCount('sales', 1);
        $this->assertDatabaseHas('stocks', ['product_id' => $store['product']->id, 'quantity' => 8]);
    }
}
