<?php

namespace Tests\Feature;

use App\Jobs\CheckLowStock;
use App\Models\CommerceChannel;
use App\Models\Location;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\ReorderRule;
use App\Models\Stock;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Models\User;
use App\Services\CommerceService;
use App\Services\InventoryService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class CrossChannelStockGuardTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function store(int $quantity = 10, int $reserved = 8): array
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'role' => 'manager']);
        $location = Location::factory()->create(['tenant_id' => $tenant->id]);
        $product = Product::factory()->create(['tenant_id' => $tenant->id, 'price_cents' => 10000]);
        $stock = Stock::create(['tenant_id' => $tenant->id, 'product_id' => $product->id, 'location_id' => $location->id, 'quantity' => $quantity, 'reserved_quantity' => $reserved]);

        return compact('tenant', 'user', 'location', 'product', 'stock');
    }

    private function checkout(array $store, int $quantity = 3): array
    {
        return ['location_id' => $store['location']->id, 'items' => [['product_id' => $store['product']->id, 'quantity' => $quantity]], 'payments' => [['method' => 'cash', 'amount' => 1000]], 'idempotency_key' => 'reserved-stock-checkout'];
    }

    private function productData(Product $product): array
    {
        return ['name' => $product->name, 'sku' => $product->sku, 'category' => $product->category, 'price' => $product->price_cents / 100, 'cost' => $product->cost_cents / 100, 'reorder_level' => $product->reorder_level];
    }

    private function channel(array $store, string $name = 'Sample web store', ?int $locationId = null): CommerceChannel
    {
        return app(CommerceService::class)->createChannel($store['user'], ['name' => $name, 'kind' => 'web_store', 'location_id' => $locationId ?? $store['location']->id, 'idempotency_key' => 'channel-'.str_replace(' ', '-', $name)]);
    }

    private function assertPublished(CommerceChannel $channel, Product $product, int $quantity): void
    {
        $this->assertDatabaseHas('channel_listings', ['commerce_channel_id' => $channel->id, 'product_id' => $product->id, 'published_quantity' => $quantity]);
    }

    public function test_pos_cannot_sell_reserved_units_and_fully_rolls_back(): void
    {
        $store = $this->store();
        $this->actingAs($store['user'])->postJson('/api/sales', $this->checkout($store))->assertUnprocessable()->assertJsonValidationErrors('items');
        $this->assertDatabaseHas('stocks', ['id' => $store['stock']->id, 'quantity' => 10, 'reserved_quantity' => 8]);
        $this->assertDatabaseCount('sales', 0);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_pos_can_sell_only_available_units_once(): void
    {
        $store = $this->store();
        $data = $this->checkout($store, 2);
        $this->actingAs($store['user'])->postJson('/api/sales', $data)->assertCreated();
        $this->postJson('/api/sales', $data)->assertCreated();
        $this->assertDatabaseHas('stocks', ['id' => $store['stock']->id, 'quantity' => 8, 'reserved_quantity' => 8]);
        $this->assertDatabaseCount('sales', 1);
        $this->assertDatabaseCount('stock_movements', 1);
        $this->getJson('/api/bootstrap')->assertOk()->assertJsonPath('products.0.stocks.0.quantity', 8)->assertJsonPath('products.0.stocks.0.on_hand', 8)->assertJsonPath('products.0.stocks.0.reserved_quantity', 8)->assertJsonPath('products.0.stocks.0.available_quantity', 0);
    }

    public function test_count_cannot_reduce_physical_stock_below_reservations(): void
    {
        $store = $this->store();
        $url = '/api/products/'.$store['product']->id.'/adjust';
        $this->actingAs($store['user'])->postJson($url, ['location_id' => $store['location']->id, 'quantity' => -3, 'reason' => 'Physical count'])->assertUnprocessable()->assertJsonValidationErrors('items');
        $this->assertDatabaseHas('stocks', ['id' => $store['stock']->id, 'quantity' => 10, 'reserved_quantity' => 8]);
        $this->assertDatabaseCount('stock_movements', 0);
        $this->postJson($url, ['location_id' => $store['location']->id, 'quantity' => -2, 'reason' => 'Physical count'])->assertOk()->assertJsonPath('stocks.0.quantity', 8)->assertJsonPath('stocks.0.available_quantity', 0);
        $this->assertDatabaseCount('stock_movements', 1);
    }

    public function test_transfer_cannot_consume_reserved_units_or_create_partial_destination_stock(): void
    {
        $store = $this->store();
        $destination = Location::factory()->create(['tenant_id' => $store['tenant']->id]);
        $data = ['product_id' => $store['product']->id, 'from_location_id' => $store['location']->id, 'to_location_id' => $destination->id, 'quantity' => 3];
        $this->actingAs($store['user'])->postJson('/api/transfers', $data)->assertUnprocessable()->assertJsonValidationErrors('items');
        $this->assertDatabaseHas('stocks', ['id' => $store['stock']->id, 'quantity' => 10, 'reserved_quantity' => 8]);
        $this->assertDatabaseMissing('stocks', ['product_id' => $store['product']->id, 'location_id' => $destination->id]);
        $this->assertDatabaseCount('stock_movements', 0);
        $this->postJson('/api/transfers', array_replace($data, ['quantity' => 2]))->assertOk();
        $this->assertDatabaseHas('stocks', ['id' => $store['stock']->id, 'quantity' => 8, 'reserved_quantity' => 8]);
        $this->assertDatabaseHas('stocks', ['product_id' => $store['product']->id, 'location_id' => $destination->id, 'quantity' => 2, 'reserved_quantity' => 0]);
        $this->assertDatabaseCount('stock_movements', 2);
    }

    public function test_returns_and_purchase_receipts_increase_available_stock_without_releasing_existing_reservations(): void
    {
        $store = $this->store();
        $sale = app(InventoryService::class)->sale($store['user'], $this->checkout($store, 2));
        $this->actingAs($store['user'])->postJson('/api/sales/'.$sale->id.'/return')->assertOk();
        $this->assertDatabaseHas('stocks', ['id' => $store['stock']->id, 'quantity' => 10, 'reserved_quantity' => 8]);
        $supplier = Supplier::factory()->create(['tenant_id' => $store['tenant']->id]);
        $order = app(InventoryService::class)->purchaseOrder($store['user'], ['supplier_id' => $supplier->id, 'location_id' => $store['location']->id, 'items' => [['product_id' => $store['product']->id, 'quantity' => 5]]]);
        $this->postJson('/api/purchase-orders/'.$order->id.'/receive')->assertOk();
        $this->getJson('/api/bootstrap')->assertOk()->assertJsonPath('products.0.stocks.0.on_hand', 15)->assertJsonPath('products.0.stocks.0.reserved_quantity', 8)->assertJsonPath('products.0.stocks.0.available_quantity', 7);
    }

    public function test_catalog_payload_cannot_overwrite_reservations_or_physical_stock(): void
    {
        $store = $this->store();
        $data = $this->productData($store['product']) + ['quantity' => 100, 'reserved_quantity' => 0, 'stocks' => [['reserved_quantity' => 0]]];
        $this->actingAs($store['user'])->putJson('/api/products/'.$store['product']->id, $data)->assertOk()->assertJsonPath('stocks.0.quantity', 10)->assertJsonPath('stocks.0.reserved_quantity', 8)->assertJsonPath('stocks.0.available_quantity', 2);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_stock_and_catalog_changes_recheck_stale_role_before_writing(): void
    {
        $store = $this->store();
        $destination = Location::factory()->create(['tenant_id' => $store['tenant']->id]);
        User::whereKey($store['user']->id)->update(['role' => 'cashier']);
        $this->actingAs($store['user'])->postJson('/api/products/'.$store['product']->id.'/adjust', ['location_id' => $store['location']->id, 'quantity' => 1, 'reason' => 'Count'])->assertForbidden();
        $this->postJson('/api/transfers', ['product_id' => $store['product']->id, 'from_location_id' => $store['location']->id, 'to_location_id' => $destination->id, 'quantity' => 1])->assertForbidden();
        $this->putJson('/api/products/'.$store['product']->id, $this->productData($store['product']))->assertForbidden();
        $this->postJson('/api/products', array_replace($this->productData($store['product']), ['sku' => 'NEW-RESERVED-SKU']))->assertForbidden();
        $this->assertDatabaseCount('products', 1);
        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertDatabaseHas('stocks', ['id' => $store['stock']->id, 'quantity' => 10, 'reserved_quantity' => 8]);
    }

    public function test_count_and_transfer_reject_other_tenant_records_without_mutation(): void
    {
        $store = $this->store();
        $other = $this->store();
        $this->actingAs($store['user'])->postJson('/api/products/'.$other['product']->id.'/adjust', ['location_id' => $other['location']->id, 'quantity' => 1, 'reason' => 'Count'])->assertNotFound();
        $this->postJson('/api/transfers', ['product_id' => $store['product']->id, 'from_location_id' => $store['location']->id, 'to_location_id' => $other['location']->id, 'quantity' => 1])->assertNotFound();
        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertDatabaseHas('stocks', ['id' => $store['stock']->id, 'quantity' => 10, 'reserved_quantity' => 8]);
    }

    public function test_actual_pending_order_protects_stock_until_cancellation_releases_it(): void
    {
        $store = $this->store(10, 0);
        $channel = $this->channel($store);
        $commerce = app(CommerceService::class);
        $order = $commerce->createOrder($store['user'], $channel->id, ['external_reference' => 'WEB-100', 'idempotency_key' => 'web-order-100', 'items' => [['sku' => $store['product']->sku, 'quantity' => 8]]]);
        $this->assertPublished($channel, $store['product'], 2);
        $this->actingAs($store['user'])->postJson('/api/sales', $this->checkout($store, 3))->assertUnprocessable();
        $this->postJson('/api/commerce/orders/'.$order->id.'/cancel')->assertOk()->assertJsonPath('status', 'canceled');
        $this->assertPublished($channel, $store['product'], 10);
        $this->postJson('/api/sales', $this->checkout($store, 3))->assertCreated();
        $this->assertPublished($channel, $store['product'], 7);
        $this->assertDatabaseHas('stocks', ['id' => $store['stock']->id, 'quantity' => 7, 'reserved_quantity' => 0]);
    }

    public function test_pos_returns_and_receipts_refresh_all_healthy_channel_copies(): void
    {
        $store = $this->store(10, 0);
        $first = $this->channel($store);
        $second = $this->channel($store, 'Sample marketplace');
        $commerce = app(CommerceService::class);
        $commerce->createOrder($store['user'], $first->id, ['external_reference' => 'WEB-COPIES', 'idempotency_key' => 'copies-reservation', 'items' => [['sku' => $store['product']->sku, 'quantity' => 8]]]);
        $sale = app(InventoryService::class)->sale($store['user'], $this->checkout($store, 2));
        $this->assertPublished($first, $store['product'], 0);
        $this->assertPublished($second, $store['product'], 0);
        $this->actingAs($store['user'])->postJson('/api/sales/'.$sale->id.'/return')->assertOk();
        $this->assertPublished($first, $store['product'], 2);
        $this->assertPublished($second, $store['product'], 2);
        $supplier = Supplier::factory()->create(['tenant_id' => $store['tenant']->id]);
        $order = app(InventoryService::class)->purchaseOrder($store['user'], ['supplier_id' => $supplier->id, 'location_id' => $store['location']->id, 'items' => [['product_id' => $store['product']->id, 'quantity' => 5]]]);
        $this->postJson('/api/purchase-orders/'.$order->id.'/receive')->assertOk();
        $this->assertPublished($first, $store['product'], 7);
        $this->assertPublished($second, $store['product'], 7);
        $this->assertDatabaseCount('channel_syncs', 0);
    }

    public function test_transfer_refreshes_channel_copies_at_both_locations(): void
    {
        $store = $this->store(10, 0);
        $destination = Location::factory()->create(['tenant_id' => $store['tenant']->id]);
        Stock::create(['tenant_id' => $store['tenant']->id, 'product_id' => $store['product']->id, 'location_id' => $destination->id, 'quantity' => 4]);
        $sourceChannel = $this->channel($store);
        $destinationChannel = $this->channel($store, 'Destination store', $destination->id);
        app(CommerceService::class)->createOrder($store['user'], $sourceChannel->id, ['external_reference' => 'WEB-TRANSFER', 'idempotency_key' => 'transfer-reserved', 'items' => [['sku' => $store['product']->sku, 'quantity' => 8]]]);
        $this->actingAs($store['user'])->postJson('/api/transfers', ['product_id' => $store['product']->id, 'from_location_id' => $store['location']->id, 'to_location_id' => $destination->id, 'quantity' => 2])->assertOk();
        $this->assertPublished($sourceChannel, $store['product'], 0);
        $this->assertPublished($destinationChannel, $store['product'], 6);
        $this->postJson('/api/products/'.$store['product']->id.'/adjust', ['location_id' => $destination->id, 'quantity' => 1, 'reason' => 'Recount'])->assertOk();
        $this->assertPublished($sourceChannel, $store['product'], 0);
        $this->assertPublished($destinationChannel, $store['product'], 7);
    }

    public function test_catalog_edits_and_zero_opening_products_push_metadata_to_channels(): void
    {
        $store = $this->store(10, 0);
        $channel = $this->channel($store);
        $data = array_replace($this->productData($store['product']), ['name' => 'Updated name', 'sku' => 'UPDATED-SKU', 'price' => 145.50]);
        $this->actingAs($store['user'])->putJson('/api/products/'.$store['product']->id, $data)->assertOk();
        $this->assertDatabaseHas('channel_listings', ['commerce_channel_id' => $channel->id, 'product_id' => $store['product']->id, 'name' => 'Updated name', 'sku' => 'UPDATED-SKU', 'price_cents' => 14550, 'published_quantity' => 10]);
        $created = $this->postJson('/api/products', array_replace($data, ['name' => 'New zero-stock item', 'sku' => 'ZERO-STOCK-SKU', 'quantity' => 0, 'location_id' => $store['location']->id, 'reserved_quantity' => 99]))->assertCreated()->assertJsonPath('stocks.0.reserved_quantity', 0)->assertJsonPath('stocks.0.available_quantity', 0);
        $this->assertDatabaseHas('channel_listings', ['commerce_channel_id' => $channel->id, 'product_id' => $created->json('id'), 'sku' => 'ZERO-STOCK-SKU', 'published_quantity' => 0]);
        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertDatabaseCount('channel_syncs', 0);
    }

    public function test_failed_and_paused_channels_do_not_publish_incorrect_healthy_snapshots(): void
    {
        $store = $this->store(10, 0);
        $healthy = $this->channel($store);
        $failed = $this->channel($store, 'Failing store');
        $paused = $this->channel($store, 'Paused store');
        $commerce = app(CommerceService::class);
        $commerce->updateChannel($store['user'], $failed->id, ['simulate_failure' => true]);
        $commerce->setEnabled($store['user'], $paused->id, false);
        $this->actingAs($store['user'])->postJson('/api/products/'.$store['product']->id.'/adjust', ['location_id' => $store['location']->id, 'quantity' => 1, 'reason' => 'Found item'])->assertOk();
        $this->assertPublished($healthy, $store['product'], 11);
        $this->assertPublished($failed, $store['product'], 10);
        $this->assertPublished($paused, $store['product'], 0);
        $this->assertTrue($failed->refresh()->pending_sync);
        $this->assertSame('failed', $failed->sync_state);
        $this->assertSame('paused', $paused->refresh()->sync_state);
        $this->assertDatabaseCount('channel_syncs', 0);
        $this->assertSame('failed', $commerce->sync($store['user'], $failed->id, 'failed-sync-001')['sync']['status']);
        $commerce->updateChannel($store['user'], $failed->id, ['simulate_failure' => false]);
        $this->assertSame('succeeded', $commerce->sync($store['user'], $failed->id, 'recovery-sync-001')['sync']['status']);
        $this->assertPublished($failed, $store['product'], 11);
        $this->assertDatabaseCount('channel_syncs', 2);
    }

    public function test_stock_and_catalog_sync_cannot_touch_another_tenants_same_sku_channel(): void
    {
        $store = $this->store(10, 0);
        $other = $this->store(14, 0);
        $other['product']->update(['sku' => $store['product']->sku]);
        $channel = $this->channel($store);
        $otherChannel = $this->channel($other);
        $this->actingAs($store['user'])->postJson('/api/products/'.$store['product']->id.'/adjust', ['location_id' => $store['location']->id, 'quantity' => -3, 'reason' => 'Count'])->assertOk();
        $this->putJson('/api/products/'.$store['product']->id, array_replace($this->productData($store['product']), ['name' => 'Changed in first tenant']))->assertOk();
        $this->assertPublished($channel, $store['product'], 7);
        $this->assertPublished($otherChannel, $other['product'], 14);
        $this->assertDatabaseHas('channel_listings', ['commerce_channel_id' => $otherChannel->id, 'product_id' => $other['product']->id, 'name' => $other['product']->name, 'sku' => $store['product']->sku]);
        $this->assertDatabaseMissing('channel_listings', ['commerce_channel_id' => $channel->id, 'product_id' => $other['product']->id]);
        $this->assertDatabaseMissing('channel_listings', ['commerce_channel_id' => $otherChannel->id, 'product_id' => $store['product']->id]);
    }

    public function test_pending_reservation_triggers_replenishment_before_import_and_cancel_preserves_pending_coverage(): void
    {
        $store = $this->store(12, 0);
        $store['product']->update(['reorder_level' => 5]);
        $supplier = Supplier::factory()->create(['tenant_id' => $store['tenant']->id]);
        ReorderRule::factory()->create(['tenant_id' => $store['tenant']->id, 'product_id' => $store['product']->id, 'location_id' => $store['location']->id, 'supplier_id' => $supplier->id, 'user_id' => $store['user']->id, 'target_quantity' => 20]);
        $channel = $this->channel($store);
        $commerce = app(CommerceService::class);
        $job = new CheckLowStock($store['tenant']->id);
        $job->handle();
        $this->assertDatabaseCount('purchase_orders', 0);
        $order = $commerce->createOrder($store['user'], $channel->id, ['external_reference' => 'REORDER-WEB-1', 'idempotency_key' => 'reorder-reservation-1', 'items' => [['sku' => $store['product']->sku, 'quantity' => 8]]]);
        $job->handle();
        $job->handle();
        $this->assertSame('pending', $order->status);
        $this->assertSame(16, PurchaseOrder::sole()->items[0]['quantity']);
        $this->assertDatabaseHas('low_stock_alerts', ['product_id' => $store['product']->id, 'quantity' => 4, 'active' => true]);
        $this->assertDatabaseCount('sales', 0);
        $this->assertDatabaseCount('stock_movements', 0);
        $commerce->cancelOrder($store['user'], $order->id);
        $job->handle();
        $this->assertDatabaseHas('low_stock_alerts', ['product_id' => $store['product']->id, 'quantity' => 12, 'active' => false]);
        $commerce->createOrder($store['user'], $channel->id, ['external_reference' => 'REORDER-WEB-2', 'idempotency_key' => 'reorder-reservation-2', 'items' => [['sku' => $store['product']->sku, 'quantity' => 8]]]);
        $job->handle();
        $this->assertDatabaseCount('purchase_orders', 1);
        $this->assertSame(16, PurchaseOrder::sole()->items[0]['quantity']);
    }

    public function test_failed_multi_item_pos_sale_rolls_back_published_copies_as_well_as_physical_stock(): void
    {
        $store = $this->store(10, 0);
        $other = Product::factory()->create(['tenant_id' => $store['tenant']->id, 'price_cents' => 10000]);
        Stock::create(['tenant_id' => $store['tenant']->id, 'product_id' => $other->id, 'location_id' => $store['location']->id, 'quantity' => 0]);
        $channel = $this->channel($store);
        app(CommerceService::class)->createOrder($store['user'], $channel->id, ['external_reference' => 'ROLLBACK-RESERVE', 'idempotency_key' => 'rollback-reserve-1', 'items' => [['sku' => $store['product']->sku, 'quantity' => 8]]]);
        $data = $this->checkout($store, 1);
        $data['items'][] = ['product_id' => $other->id, 'quantity' => 1];
        $this->actingAs($store['user'])->postJson('/api/sales', $data)->assertUnprocessable();
        $this->assertPublished($channel, $store['product'], 2);
        $this->assertPublished($channel, $other, 0);
        $this->assertDatabaseHas('stocks', ['id' => $store['stock']->id, 'quantity' => 10, 'reserved_quantity' => 8]);
        $this->assertDatabaseCount('sales', 0);
        $this->assertDatabaseCount('stock_movements', 0);
    }
}
