<?php

namespace Tests\Feature;

use App\Models\ChannelOrder;
use App\Models\Location;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\ReorderRule;
use App\Models\Stock;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class ProductVariantIntegrationTest extends TestCase
{
    use LazilyRefreshDatabase;

    /** @return array{tenant: Tenant, user: User, location: Location, first: Product, second: Product} */
    private function variants(int $firstQuantity = 5, int $secondQuantity = 8): array
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'role' => 'manager']);
        $location = Location::factory()->create(['tenant_id' => $tenant->id]);
        $response = $this->actingAs($user)->postJson('/api/product-families', [
            'name' => 'Everyday tee', 'option_names' => ['size', 'color'], 'category' => 'Apparel',
            'price' => 100, 'cost' => 40, 'wholesale_price' => 80, 'reorder_level' => 3,
            'location_id' => $location->id, 'idempotency_key' => 'integration-tee-family',
            'variants' => [
                ['options' => ['size' => 'S', 'color' => 'Blue'], 'sku' => 'TEE-S-BLUE', 'barcode' => '001001', 'quantity' => $firstQuantity],
                ['options' => ['size' => 'M', 'color' => 'Blue'], 'sku' => 'TEE-M-BLUE', 'barcode' => '001002', 'price' => 150, 'wholesale_price' => 120, 'quantity' => $secondQuantity],
            ],
        ])->assertCreated();
        $first = Product::where('tenant_id', $tenant->id)->where('product_family_id', $response->json('id'))->where('sku', 'TEE-S-BLUE')->firstOrFail();
        $second = Product::where('tenant_id', $tenant->id)->where('product_family_id', $response->json('id'))->where('sku', 'TEE-M-BLUE')->firstOrFail();

        return compact('tenant', 'user', 'location', 'first', 'second');
    }

    /** @param array{location: Location} $store */
    private function checkout(array $store, array $items, string $key = 'variant-checkout', array $extra = []): array
    {
        return array_replace([
            'location_id' => $store['location']->id, 'items' => $items,
            'payments' => [['method' => 'cash', 'amount' => 1000]], 'idempotency_key' => $key,
        ], $extra);
    }

    private function assertStock(Product $product, Location $location, int $quantity, int $reserved = 0): void
    {
        $this->assertDatabaseHas('stocks', [
            'tenant_id' => $product->tenant_id, 'product_id' => $product->id,
            'location_id' => $location->id, 'quantity' => $quantity, 'reserved_quantity' => $reserved,
        ]);
    }

    /** @param array{user: User, location: Location} $store */
    private function channel(array $store): int
    {
        return $this->actingAs($store['user'])->postJson('/api/commerce/channels', [
            'name' => 'Variant practice shop', 'kind' => 'web_store', 'location_id' => $store['location']->id,
            'idempotency_key' => 'variant-practice-channel',
        ])->assertCreated()->json('id');
    }

    public function test_cashier_sale_preserves_variant_labels_and_original_refund_amounts_after_catalog_edits(): void
    {
        $store = $this->variants();
        $cashier = User::factory()->create(['tenant_id' => $store['tenant']->id, 'role' => 'cashier']);
        $payload = $this->checkout($store, [
            ['product_id' => $store['first']->id, 'quantity' => 2],
            ['product_id' => $store['second']->id, 'quantity' => 1],
        ], extra: ['discount_percent' => 10]);

        $sale = $this->actingAs($cashier)->postJson('/api/sales', $payload)->assertCreated()
            ->assertJsonPath('items.0.name', 'Everyday tee · S / Blue')->assertJsonPath('items.0.sku', 'TEE-S-BLUE')
            ->assertJsonPath('items.0.unit_price', 100)->assertJsonPath('items.1.unit_price', 150)->assertJsonPath('total', 352.8);
        $this->assertStock($store['first'], $store['location'], 3);
        $this->assertStock($store['second'], $store['location'], 7);
        $this->actingAs($store['user'])->putJson('/api/products/'.$store['first']->id, [
            'name' => 'Everyday tee', 'sku' => 'TEE-L-GREEN', 'barcode' => '001001', 'category' => 'Apparel',
            'price' => 225, 'cost' => 45, 'wholesale_price' => 180, 'reorder_level' => 3,
            'variant_options' => ['size' => 'L', 'color' => 'Green'],
        ])->assertOk()->assertJsonPath('name', 'Everyday tee · L / Green');

        $this->postJson('/api/sales/'.$sale->json('id').'/return', [
            'items' => [['product_id' => $store['first']->id, 'quantity' => 1]],
            'reason' => 'One small blue shirt came back', 'idempotency_key' => 'variant-one-item-return',
        ])->assertOk()->assertJsonPath('items.0.name', 'Everyday tee · S / Blue')
            ->assertJsonPath('items.0.sku', 'TEE-S-BLUE')->assertJsonPath('items.0.returned_quantity', 1)
            ->assertJsonPath('items.1.returned_quantity', 0)->assertJsonPath('returned_amount', 100.8)->assertJsonPath('net_total', 252);
        $this->assertStock($store['first'], $store['location'], 4);
        $this->assertStock($store['second'], $store['location'], 7);
        $this->assertDatabaseHas('stock_movements', ['product_id' => $store['first']->id, 'type' => 'return', 'quantity' => 1]);
        $this->assertDatabaseMissing('stock_movements', ['product_id' => $store['second']->id, 'type' => 'return']);
        $this->actingAs($cashier)->getJson('/api/sales/'.$sale->json('id'))->assertOk()
            ->assertJsonPath('items.0.name', 'Everyday tee · S / Blue')->assertJsonPath('items.0.unit_price', 100);
    }

    public function test_out_of_stock_variant_cannot_borrow_units_from_a_sibling_and_rolls_back_the_cart(): void
    {
        $store = $this->variants(0, 8);
        $movements = StockMovement::count();
        $payload = $this->checkout($store, [
            ['product_id' => $store['second']->id, 'quantity' => 2],
            ['product_id' => $store['first']->id, 'quantity' => 1],
        ]);

        $this->postJson('/api/sales', $payload)->assertUnprocessable()->assertJsonValidationErrors('items');

        $this->assertStock($store['first'], $store['location'], 0);
        $this->assertStock($store['second'], $store['location'], 8);
        $this->assertDatabaseCount('sales', 0);
        $this->assertDatabaseCount('stock_movements', $movements);
    }

    public function test_wholesale_checkout_uses_each_variants_own_price_and_deducts_each_sku_once(): void
    {
        $store = $this->variants();
        $payload = $this->checkout($store, [
            ['product_id' => $store['first']->id, 'quantity' => 1],
            ['product_id' => $store['second']->id, 'quantity' => 1],
        ], extra: ['price_tier' => 'wholesale']);

        $this->postJson('/api/sales', $payload)->assertCreated()->assertJsonPath('price_tier', 'wholesale')
            ->assertJsonPath('items.0.unit_price', 80)->assertJsonPath('items.1.unit_price', 120)->assertJsonPath('total', 224);
        $this->postJson('/api/sales', $payload)->assertCreated();

        $this->assertStock($store['first'], $store['location'], 4);
        $this->assertStock($store['second'], $store['location'], 7);
        $this->assertDatabaseCount('sales', 1);
    }

    public function test_transfer_moves_only_the_selected_variant_between_locations(): void
    {
        $store = $this->variants();
        $destination = Location::factory()->create(['tenant_id' => $store['tenant']->id]);
        Stock::factory()->create(['tenant_id' => $store['tenant']->id, 'product_id' => $store['second']->id, 'location_id' => $destination->id, 'quantity' => 12]);

        $this->postJson('/api/transfers', [
            'product_id' => $store['first']->id, 'from_location_id' => $store['location']->id,
            'to_location_id' => $destination->id, 'quantity' => 2,
        ])->assertOk();

        $this->assertStock($store['first'], $store['location'], 3);
        $this->assertStock($store['first'], $destination, 2);
        $this->assertStock($store['second'], $store['location'], 8);
        $this->assertStock($store['second'], $destination, 12);
        $this->assertDatabaseMissing('stock_movements', ['product_id' => $store['second']->id, 'type' => 'transfer_out']);
        $this->assertDatabaseMissing('stock_movements', ['product_id' => $store['second']->id, 'type' => 'transfer_in']);
    }

    public function test_partial_purchase_delivery_receives_only_its_chosen_variant_once(): void
    {
        $store = $this->variants();
        $supplier = Supplier::factory()->create(['tenant_id' => $store['tenant']->id]);
        $order = $this->postJson('/api/purchase-orders', [
            'supplier_id' => $supplier->id, 'location_id' => $store['location']->id,
            'items' => [
                ['product_id' => $store['first']->id, 'quantity' => 3],
                ['product_id' => $store['second']->id, 'quantity' => 4],
            ],
        ])->assertCreated();
        $payload = ['items' => [['product_id' => $store['second']->id, 'quantity' => 2]], 'note' => 'Medium blue delivery only', 'idempotency_key' => 'variant-partial-delivery'];

        $this->postJson('/api/purchase-orders/'.$order->json('id').'/receive', $payload)->assertOk()
            ->assertJsonPath('status', 'partially_received')->assertJsonPath('items.0.received_quantity', 0)
            ->assertJsonPath('items.1.received_quantity', 2)->assertJsonPath('receipts.0.items.0.sku', 'TEE-M-BLUE');
        $this->postJson('/api/purchase-orders/'.$order->json('id').'/receive', $payload)->assertOk()->assertJsonCount(1, 'receipts');

        $this->assertStock($store['first'], $store['location'], 5);
        $this->assertStock($store['second'], $store['location'], 10);
        $this->assertDatabaseMissing('stock_movements', ['product_id' => $store['first']->id, 'type' => 'receipt']);
        $this->assertDatabaseHas('stock_movements', ['product_id' => $store['second']->id, 'type' => 'receipt', 'quantity' => 2]);
        $this->assertDatabaseCount('purchase_receipts', 1);
    }

    public function test_replenishment_rules_and_pending_coverage_count_each_variant_independently(): void
    {
        $store = $this->variants(2, 8);
        $supplier = Supplier::factory()->create(['tenant_id' => $store['tenant']->id]);
        foreach ([$store['first'], $store['second']] as $product) {
            ReorderRule::factory()->create([
                'tenant_id' => $store['tenant']->id, 'product_id' => $product->id,
                'location_id' => $store['location']->id, 'supplier_id' => $supplier->id,
                'user_id' => $store['user']->id, 'target_quantity' => 10,
            ]);
        }
        $this->postJson('/api/purchase-orders', [
            'supplier_id' => $supplier->id, 'location_id' => $store['location']->id,
            'items' => [['product_id' => $store['second']->id, 'quantity' => 20]],
        ])->assertCreated();

        $this->postJson('/api/reorder-rules/generate')->assertOk()->assertJsonPath('created_count', 1)
            ->assertJsonPath('orders.0.items.0.product_id', $store['first']->id)->assertJsonPath('orders.0.items.0.quantity', 8);
        $this->postJson('/api/reorder-rules/generate')->assertOk()->assertJsonPath('created_count', 0);

        $this->assertDatabaseCount('purchase_orders', 2);
        $draft = PurchaseOrder::where('status', 'draft')->sole();
        $this->assertSame('TEE-S-BLUE', $draft->items[0]['sku']);
        $this->assertStock($store['first'], $store['location'], 2);
        $this->assertStock($store['second'], $store['location'], 8);
    }

    public function test_channel_reservations_cancel_and_import_each_variant_without_touching_siblings(): void
    {
        $store = $this->variants();
        $channelId = $this->channel($store);
        $pending = $this->postJson('/api/commerce/channels/'.$channelId.'/orders', [
            'external_reference' => 'VARIANT-CANCEL', 'idempotency_key' => 'variant-cancel-order',
            'items' => [['sku' => 'TEE-S-BLUE', 'quantity' => 4]],
        ])->assertCreated();

        $this->assertStock($store['first'], $store['location'], 5, 4);
        $this->assertStock($store['second'], $store['location'], 8);
        $this->assertDatabaseHas('channel_listings', ['commerce_channel_id' => $channelId, 'product_id' => $store['first']->id, 'published_quantity' => 1]);
        $this->assertDatabaseHas('channel_listings', ['commerce_channel_id' => $channelId, 'product_id' => $store['second']->id, 'published_quantity' => 8]);
        $this->postJson('/api/commerce/orders/'.$pending->json('id').'/cancel')->assertOk()->assertJsonPath('status', 'canceled');
        $this->assertStock($store['first'], $store['location'], 5);
        $this->assertStock($store['second'], $store['location'], 8);
        $imported = $this->postJson('/api/commerce/channels/'.$channelId.'/orders', [
            'external_reference' => 'VARIANT-IMPORT', 'idempotency_key' => 'variant-import-order',
            'items' => [['sku' => 'TEE-M-BLUE', 'quantity' => 3]],
        ])->assertCreated()->assertJsonPath('items.0.name', 'Everyday tee · M / Blue')->assertJsonPath('total', 504);
        $this->putJson('/api/products/'.$store['second']->id, [
            'name' => 'Everyday tee', 'sku' => 'TEE-M-RED', 'category' => 'Apparel',
            'price' => 250, 'cost' => 40, 'reorder_level' => 3,
            'variant_options' => ['size' => 'M', 'color' => 'Red'],
        ])->assertOk()->assertJsonPath('name', 'Everyday tee · M / Red');
        $this->postJson('/api/commerce/orders/'.$imported->json('id').'/import')->assertOk()->assertJsonPath('status', 'imported');
        $this->postJson('/api/commerce/orders/'.$imported->json('id').'/import')->assertOk();

        $this->assertStock($store['first'], $store['location'], 5);
        $this->assertStock($store['second'], $store['location'], 5);
        $this->assertDatabaseHas('channel_listings', ['commerce_channel_id' => $channelId, 'product_id' => $store['first']->id, 'published_quantity' => 5]);
        $this->assertDatabaseHas('channel_listings', ['commerce_channel_id' => $channelId, 'product_id' => $store['second']->id, 'published_quantity' => 5]);
        $this->assertDatabaseCount('sales', 1);
        $this->assertDatabaseMissing('stock_movements', ['product_id' => $store['first']->id, 'type' => 'sale']);
        $order = ChannelOrder::findOrFail($imported->json('id'));
        $this->getJson('/api/sales/'.$order->sale_id)->assertOk()->assertJsonPath('items.0.sku', 'TEE-M-BLUE')
            ->assertJsonPath('items.0.name', 'Everyday tee · M / Blue')->assertJsonPath('items.0.unit_price', 150);
    }

    public function test_pos_rejects_a_foreign_tenants_variant_without_modifying_either_family(): void
    {
        $store = $this->variants();
        $foreign = $this->variants();
        $movements = StockMovement::count();

        $this->actingAs($store['user'])->postJson('/api/sales', $this->checkout($store, [
            ['product_id' => $foreign['first']->id, 'quantity' => 1],
        ]))->assertNotFound();

        $this->assertDatabaseCount('sales', 0);
        $this->assertDatabaseCount('stock_movements', $movements);
        $this->assertStock($store['first'], $store['location'], 5);
        $this->assertStock($store['second'], $store['location'], 8);
        $this->assertStock($foreign['first'], $foreign['location'], 5);
        $this->assertStock($foreign['second'], $foreign['location'], 8);
    }
}
