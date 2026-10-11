<?php

namespace Tests\Feature;

use App\Jobs\CheckLowStock;
use App\Models\CashSession;
use App\Models\ChannelOrder;
use App\Models\CommerceChannel;
use App\Models\Location;
use App\Models\Product;
use App\Models\Sale;
use App\Models\Stock;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Services\CommerceService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\TestWith;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class CommerceTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake([CheckLowStock::class]);
    }

    private function store(string $role = 'manager', int $quantity = 10): array
    {
        $tenant = Tenant::factory()->create();
        $actor = User::factory()->create(['tenant_id' => $tenant->id, 'role' => $role]);
        $location = Location::factory()->create(['tenant_id' => $tenant->id]);
        $product = Product::factory()->create(['tenant_id' => $tenant->id, 'sku' => 'TOTE-LOCAL', 'name' => 'Canvas tote', 'price_cents' => 10000]);
        $stock = Stock::factory()->create(['tenant_id' => $tenant->id, 'location_id' => $location->id, 'product_id' => $product->id, 'quantity' => $quantity]);

        return compact('tenant', 'actor', 'location', 'product', 'stock');
    }

    private function channel(array $store, string $key = 'create-web-channel'): int
    {
        return $this->actingAs($store['actor'])->postJson('/api/commerce/channels', [
            'name' => 'Local web store', 'kind' => 'web_store', 'location_id' => $store['location']->id, 'idempotency_key' => $key,
        ])->assertCreated()->json('id');
    }

    private function orderData(array $store, int $quantity = 2, string $reference = 'WEB-LOCAL-1', string $key = 'local-order-1'): array
    {
        return ['external_reference' => $reference, 'idempotency_key' => $key, 'items' => [['sku' => $store['product']->sku, 'quantity' => $quantity]]];
    }

    private function order(array $store, int $channelId, int $quantity = 2): int
    {
        return $this->postJson('/api/commerce/channels/'.$channelId.'/orders', $this->orderData($store, $quantity))->assertCreated()->json('id');
    }

    public function test_channel_actions_require_authentication_and_manager_access(): void
    {
        $this->getJson('/api/commerce')->assertUnauthorized();
        $store = $this->store('cashier');
        $channel = CommerceChannel::factory()->create(['tenant_id' => $store['tenant']->id, 'location_id' => $store['location']->id]);
        $this->actingAs($store['actor'])->getJson('/api/commerce')->assertForbidden();
        $this->postJson('/api/commerce/channels', [])->assertForbidden();
        $this->postJson('/api/commerce/channels/'.$channel->id.'/orders', $this->orderData($store))->assertForbidden();
        $this->postJson('/api/commerce/channels/'.$channel->id.'/pause')->assertForbidden();
        $this->assertDatabaseCount('channel_orders', 0);
    }

    public function test_channel_creation_maps_the_catalog_and_retries_without_duplicates(): void
    {
        $store = $this->store();
        $data = ['name' => 'Local web store', 'kind' => 'web_store', 'location_id' => $store['location']->id, 'idempotency_key' => 'create-channel-safe'];
        $first = $this->actingAs($store['actor'])->postJson('/api/commerce/channels', $data)->assertCreated()
            ->assertJsonPath('enabled', true)->assertJsonPath('is_simulated', true)->assertJsonPath('simulation_enabled', true)
            ->assertJsonPath('sync_state', 'synced')->assertJsonPath('pending_sync', false)->assertJsonPath('listings.0.published_quantity', 10)
            ->assertJsonPath('listings.0.on_hand', 10)->assertJsonPath('listings.0.reserved_quantity', 0)->assertJsonPath('listings.0.available_quantity', 10);
        $this->postJson('/api/commerce/channels', $data)->assertCreated()->assertJsonPath('id', $first->json('id'));
        $this->postJson('/api/commerce/channels', array_replace($data, ['name' => 'Other store']))->assertConflict();
        $this->assertDatabaseCount('commerce_channels', 1);
        $this->assertDatabaseCount('channel_listings', 1);
        $this->assertDatabaseCount('channel_syncs', 0);
    }

    public function test_foreign_channel_location_orders_and_catalog_are_isolated(): void
    {
        $store = $this->store();
        $foreign = $this->store();
        $foreignChannel = $this->channel($foreign);
        $foreignOrder = $this->order($foreign, $foreignChannel);
        $this->actingAs($store['actor'])->postJson('/api/commerce/channels', ['name' => 'Wrong tenant', 'kind' => 'marketplace', 'location_id' => $foreign['location']->id, 'idempotency_key' => 'foreign-location'])->assertNotFound();
        $this->patchJson('/api/commerce/channels/'.$foreignChannel, ['name' => 'Intrusion'])->assertNotFound();
        $this->postJson('/api/commerce/channels/'.$foreignChannel.'/orders', $this->orderData($store))->assertNotFound();
        $this->postJson('/api/commerce/orders/'.$foreignOrder.'/import')->assertNotFound();
        $this->postJson('/api/commerce/orders/'.$foreignOrder.'/cancel')->assertNotFound();
        $this->getJson('/api/commerce')->assertOk()->assertJsonCount(0, 'channels')->assertJsonCount(0, 'orders');
        $this->assertDatabaseHas('stocks', ['id' => $foreign['stock']->id, 'quantity' => 10, 'reserved_quantity' => 2]);
    }

    public function test_order_quotes_server_prices_and_tax_then_reserves_all_channels(): void
    {
        $store = $this->store();
        $channel = $this->channel($store);
        $other = $this->channel($store, 'second-store-key');
        $data = $this->orderData($store, 3);
        $data['tax_percent'] = 0;
        $data['total'] = 1;
        $data['discount_percent'] = 100;
        $data['items'][0]['unit_price'] = 1;
        $this->postJson('/api/commerce/channels/'.$channel.'/orders', $data)->assertCreated()
            ->assertJsonPath('status', 'pending')->assertJsonPath('payment_status', 'simulated_paid')
            ->assertJsonPath('items.0.unit_price', 100)->assertJsonPath('subtotal', 300)->assertJsonPath('discount', 0)
            ->assertJsonPath('tax_percent', 12)->assertJsonPath('tax', 36)->assertJsonPath('total', 336);
        $this->assertDatabaseHas('stocks', ['id' => $store['stock']->id, 'quantity' => 10, 'reserved_quantity' => 3]);
        $this->assertDatabaseHas('channel_listings', ['commerce_channel_id' => $channel, 'published_quantity' => 7]);
        $this->assertDatabaseHas('channel_listings', ['commerce_channel_id' => $other, 'published_quantity' => 7]);
        $this->assertDatabaseCount('sales', 0);
        $this->assertDatabaseCount('stock_movements', 0);
        Bus::assertDispatched(CheckLowStock::class, fn (CheckLowStock $job): bool => $job->tenantId === $store['tenant']->id);
    }

    public function test_order_replays_keep_original_quote_and_bind_actor_reference_and_payload(): void
    {
        $store = $this->store();
        $channel = $this->channel($store);
        $data = $this->orderData($store);
        $first = $this->postJson('/api/commerce/channels/'.$channel.'/orders', $data)->assertCreated();
        $store['product']->update(['price_cents' => 99900]);
        $this->postJson('/api/commerce/channels/'.$channel.'/orders', $data)->assertCreated()->assertJsonPath('id', $first->json('id'))->assertJsonPath('total', 224);
        $this->postJson('/api/commerce/channels/'.$channel.'/orders', $this->orderData($store, 3))->assertConflict();
        $this->postJson('/api/commerce/channels/'.$channel.'/orders', array_replace($data, ['external_reference' => 'NEW-REFERENCE']))->assertConflict();
        $this->postJson('/api/commerce/channels/'.$channel.'/orders', array_replace($data, ['idempotency_key' => 'different-key']))->assertConflict();
        $otherActor = User::factory()->create(['tenant_id' => $store['tenant']->id, 'role' => 'admin']);
        $this->actingAs($otherActor)->postJson('/api/commerce/channels/'.$channel.'/orders', $data)->assertConflict();
        $this->assertDatabaseCount('channel_orders', 1);
        $this->assertDatabaseHas('stocks', ['id' => $store['stock']->id, 'reserved_quantity' => 2]);
    }

    public function test_multiple_channels_cannot_oversell_even_with_stale_published_quantities(): void
    {
        $store = $this->store();
        $first = $this->channel($store);
        $second = $this->channel($store, 'second-channel-key');
        $this->patchJson('/api/commerce/channels/'.$second, ['simulate_failure' => true])->assertOk();
        $this->postJson('/api/commerce/channels/'.$first.'/orders', $this->orderData($store, 8))->assertCreated();
        $this->getJson('/api/commerce')->assertOk()->assertJsonPath('channels.1.sync_state', 'failed')->assertJsonPath('channels.1.listings.0.published_quantity', 10)->assertJsonPath('channels.1.listings.0.available_quantity', 2);
        $this->postJson('/api/commerce/channels/'.$second.'/orders', $this->orderData($store, 3, 'MARKET-2', 'market-order-2'))->assertUnprocessable();
        $this->postJson('/api/commerce/channels/'.$second.'/orders', $this->orderData($store, 2, 'MARKET-2', 'market-order-2'))->assertCreated();
        $this->assertDatabaseHas('stocks', ['id' => $store['stock']->id, 'quantity' => 10, 'reserved_quantity' => 10]);
        $this->assertDatabaseCount('channel_orders', 2);
        $this->assertDatabaseCount('channel_syncs', 0);
    }

    public function test_later_unavailable_item_rolls_back_earlier_reservations_and_order(): void
    {
        $store = $this->store();
        $channel = $this->channel($store);
        $product = Product::factory()->create(['tenant_id' => $store['tenant']->id, 'sku' => 'ZZ-NO-STOCK']);
        $data = $this->orderData($store);
        $data['items'][] = ['sku' => $product->sku, 'quantity' => 1];
        $this->postJson('/api/commerce/channels/'.$channel.'/orders', $data)->assertUnprocessable()->assertJsonValidationErrors('items');
        $this->assertDatabaseHas('stocks', ['id' => $store['stock']->id, 'quantity' => 10, 'reserved_quantity' => 0]);
        $this->assertDatabaseCount('channel_orders', 0);
        Bus::assertNotDispatched(CheckLowStock::class);
    }

    public function test_invalid_or_foreign_skus_and_duplicate_lines_do_not_reserve_stock(): void
    {
        $store = $this->store();
        $channel = $this->channel($store);
        $foreign = Product::factory()->create(['sku' => 'FOREIGN-SKU']);
        $data = $this->orderData($store);
        $data['items'][0]['sku'] = $foreign->sku;
        $this->postJson('/api/commerce/channels/'.$channel.'/orders', $data)->assertUnprocessable();
        $data = $this->orderData($store);
        $data['items'][] = $data['items'][0];
        $this->postJson('/api/commerce/channels/'.$channel.'/orders', $data)->assertUnprocessable();
        $data['items'] = [['sku' => $store['product']->sku, 'quantity' => 0]];
        $this->postJson('/api/commerce/channels/'.$channel.'/orders', $data)->assertUnprocessable();
        $this->assertDatabaseCount('channel_orders', 0);
        $this->assertDatabaseHas('stocks', ['id' => $store['stock']->id, 'reserved_quantity' => 0]);
    }

    public function test_import_keeps_original_quote_prices_and_tax_without_attaching_a_cash_drawer(): void
    {
        $store = $this->store();
        $channel = $this->channel($store);
        $order = $this->order($store, $channel);
        $this->postJson('/api/commerce/channels/'.$channel.'/orders', $this->orderData($store, 3, 'OTHER-PENDING', 'other-pending-key'))->assertCreated();
        $drawer = CashSession::factory()->create(['tenant_id' => $store['tenant']->id, 'location_id' => $store['location']->id, 'user_id' => $store['actor']->id, 'status' => 'open', 'cash_sales_cents' => 0]);
        $store['product']->update(['name' => 'Changed name', 'sku' => 'CHANGED-SKU', 'price_cents' => 99900]);
        $store['tenant']->update(['settings' => array_replace(Tenant::defaultSettings(), ['tax_percent' => 20])]);
        $first = $this->postJson('/api/commerce/orders/'.$order.'/import')->assertOk()->assertJsonPath('status', 'imported')->assertJsonPath('items.0.unit_price', 100)->assertJsonPath('items.0.sku', 'TOTE-LOCAL')->assertJsonPath('total', 224);
        $this->postJson('/api/commerce/orders/'.$order.'/import')->assertOk()->assertJsonPath('sale_id', $first->json('sale_id'));
        $sale = Sale::findOrFail($first->json('sale_id'));
        $this->assertSame('channel', $sale->source);
        $this->assertSame($channel, $sale->channel_id);
        $this->assertSame(22400, $sale->total_cents);
        $this->assertNull($sale->cash_session_id);
        $this->assertSame(['method' => 'card', 'amount_cents' => 22400, 'simulated' => true, 'source' => 'commerce_simulation', 'external_reference' => 'WEB-LOCAL-1'], $sale->payments[0]);
        $this->assertSame(0, $drawer->refresh()->cash_sales_cents);
        $this->assertDatabaseHas('stocks', ['id' => $store['stock']->id, 'quantity' => 8, 'reserved_quantity' => 3]);
        $this->assertDatabaseHas('channel_listings', ['commerce_channel_id' => $channel, 'published_quantity' => 5]);
        $this->assertDatabaseCount('sales', 1);
        $this->assertDatabaseCount('stock_movements', 1);
    }

    public function test_pending_cancel_releases_once_and_preserves_immutable_quote(): void
    {
        $this->freezeTime();
        $store = $this->store();
        $channel = $this->channel($store);
        $order = $this->order($store, $channel);
        $first = $this->postJson('/api/commerce/orders/'.$order.'/cancel')->assertOk()->assertJsonPath('status', 'canceled')->assertJsonPath('total', 224);
        $this->travel(1)->hour();
        $this->postJson('/api/commerce/orders/'.$order.'/cancel')->assertOk()->assertJsonPath('canceled_at', $first->json('canceled_at'));
        $this->postJson('/api/commerce/orders/'.$order.'/import')->assertConflict();
        $this->assertDatabaseHas('stocks', ['id' => $store['stock']->id, 'quantity' => 10, 'reserved_quantity' => 0]);
        $this->assertDatabaseHas('channel_listings', ['commerce_channel_id' => $channel, 'published_quantity' => 10]);
        $this->assertDatabaseCount('sales', 0);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_imported_order_cannot_cancel_and_receipt_return_restocks_original_amount(): void
    {
        $store = $this->store();
        $channel = $this->channel($store);
        $order = $this->order($store, $channel);
        $saleId = $this->postJson('/api/commerce/orders/'.$order.'/import')->assertOk()->json('sale_id');
        $this->postJson('/api/commerce/orders/'.$order.'/cancel')->assertConflict();
        $this->postJson('/api/sales/'.$saleId.'/return')->assertOk()->assertJsonPath('status', 'returned')->assertJsonPath('returned_amount', 224);
        $this->assertDatabaseHas('stocks', ['id' => $store['stock']->id, 'quantity' => 10, 'reserved_quantity' => 0]);
        $this->assertDatabaseHas('channel_orders', ['id' => $order, 'status' => 'imported']);
    }

    public function test_pause_always_publishes_zero_and_allows_pending_order_processing(): void
    {
        $store = $this->store();
        $channel = $this->channel($store);
        $order = $this->order($store, $channel);
        $this->patchJson('/api/commerce/channels/'.$channel, ['simulate_failure' => true])->assertOk();
        $this->postJson('/api/commerce/channels/'.$channel.'/pause')->assertOk()->assertJsonPath('enabled', false)->assertJsonPath('sync_state', 'paused')->assertJsonPath('pending_sync', false)->assertJsonPath('listings.0.published_quantity', 0);
        $this->postJson('/api/commerce/channels/'.$channel.'/orders', $this->orderData($store))->assertCreated()->assertJsonPath('id', $order);
        $this->postJson('/api/commerce/channels/'.$channel.'/orders', $this->orderData($store, 1, 'NEW-WEB-2', 'new-web-order'))->assertUnprocessable();
        $this->postJson('/api/commerce/orders/'.$order.'/import')->assertOk();
        $this->assertDatabaseHas('channel_listings', ['commerce_channel_id' => $channel, 'published_quantity' => 0]);
        $this->postJson('/api/commerce/channels/'.$channel.'/sync', ['idempotency_key' => 'paused-sync-key'])->assertOk()->assertJsonPath('sync.status', 'succeeded')->assertJsonPath('channel.sync_state', 'paused');
        $this->postJson('/api/commerce/channels/'.$channel.'/resume')->assertOk()->assertJsonPath('sync_state', 'failed')->assertJsonPath('pending_sync', true);
    }

    public function test_failed_manual_sync_is_persistent_and_fresh_retry_recovers_snapshot(): void
    {
        $store = $this->store();
        $channel = $this->channel($store);
        $this->patchJson('/api/commerce/channels/'.$channel, ['simulate_failure' => true])->assertOk();
        $store['stock']->update(['quantity' => 4]);
        $first = $this->postJson('/api/commerce/channels/'.$channel.'/sync', ['idempotency_key' => 'failed-sync-key'])->assertOk()->assertJsonPath('sync.status', 'failed')->assertJsonPath('channel.listings.0.published_quantity', 10);
        $this->patchJson('/api/commerce/channels/'.$channel, ['simulate_failure' => false])->assertOk()->assertJsonPath('sync_state', 'stale');
        $this->postJson('/api/commerce/channels/'.$channel.'/sync', ['idempotency_key' => 'failed-sync-key'])->assertOk()->assertJsonPath('sync.id', $first->json('sync.id'))->assertJsonPath('sync.status', 'failed')->assertJsonPath('channel.listings.0.published_quantity', 10);
        $this->assertDatabaseCount('channel_syncs', 1);
        $this->postJson('/api/commerce/channels/'.$channel.'/sync', ['idempotency_key' => 'fresh-sync-key'])->assertOk()->assertJsonPath('sync.status', 'succeeded')->assertJsonPath('channel.listings.0.published_quantity', 4)->assertJsonPath('channel.pending_sync', false);
        $this->assertDatabaseCount('channel_syncs', 2);
        $this->getJson('/api/commerce')->assertOk()->assertJsonPath('syncs.1.status', 'failed');
    }

    #[TestWith(['cashier', true])]
    #[TestWith(['manager', false])]
    public function test_mutations_recheck_current_role_and_active_status_under_tenant_lock(string $role, bool $isActive): void
    {
        $store = $this->store();
        $channel = $this->channel($store);
        $actor = $store['actor'];
        User::whereKey($actor->id)->update(['role' => $role, 'is_active' => $isActive]);
        try {
            app(CommerceService::class)->createOrder($actor, $channel, $this->orderData($store));
            $this->fail('A stale privileged actor must not create a channel order.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $this->assertDatabaseCount('channel_orders', 0);
        $this->assertDatabaseHas('stocks', ['id' => $store['stock']->id, 'reserved_quantity' => 0]);
    }

    public function test_expired_subscriptions_allow_read_pause_cancel_but_block_new_changes(): void
    {
        $store = $this->store();
        $channel = $this->channel($store);
        $order = $this->order($store, $channel);
        Subscription::where('tenant_id', $store['tenant']->id)->update(['trial_ends_at' => now()->subDays(8)]);
        $this->getJson('/api/commerce')->assertOk();
        $this->postJson('/api/commerce/channels/'.$channel.'/orders', $this->orderData($store, 1, 'EXPIRED-NEW', 'expired-new-key'))->assertStatus(423);
        $this->postJson('/api/commerce/orders/'.$order.'/import')->assertStatus(423);
        $this->postJson('/api/commerce/channels/'.$channel.'/sync', ['idempotency_key' => 'expired-sync-key'])->assertStatus(423);
        $this->postJson('/api/commerce/channels/'.$channel.'/pause')->assertOk();
        $this->postJson('/api/commerce/orders/'.$order.'/cancel')->assertOk();
        $this->assertDatabaseHas('stocks', ['id' => $store['stock']->id, 'quantity' => 10, 'reserved_quantity' => 0]);
    }

    #[TestWith(['production'])]
    #[TestWith(['staging'])]
    public function test_simulation_mutations_are_hidden_outside_local_environments(string $environment): void
    {
        $store = $this->store();
        $channel = $this->channel($store);
        $order = $this->order($store, $channel);
        app()->detectEnvironment(fn (): string => $environment);
        $this->withSession(['_token' => 'commerce-csrf-token']);
        $token = ['_token' => 'commerce-csrf-token'];
        $this->postJson('/api/commerce/channels', $token)->assertNotFound();
        $this->patchJson('/api/commerce/channels/'.$channel, $token + ['simulate_failure' => false])->assertNotFound();
        $this->postJson('/api/commerce/channels/'.$channel.'/orders', $token + $this->orderData($store, 1, 'OUTSIDE-2', 'outside-order-key'))->assertNotFound();
        $this->postJson('/api/commerce/channels/'.$channel.'/sync', $token + ['idempotency_key' => 'outside-sync-key'])->assertNotFound();
        $this->postJson('/api/commerce/orders/'.$order.'/import', $token)->assertNotFound();
        $this->getJson('/api/commerce')->assertOk()->assertJsonPath('simulation_enabled', false);
        $this->postJson('/api/commerce/channels/'.$channel.'/pause', $token)->assertOk()->assertJsonPath('listings.0.published_quantity', 0);
        $this->postJson('/api/commerce/orders/'.$order.'/cancel', $token)->assertOk();
        $this->assertDatabaseHas('stocks', ['id' => $store['stock']->id, 'quantity' => 10, 'reserved_quantity' => 0]);
    }

    public function test_disabled_simulation_keeps_enabled_snapshots_stale_and_releases_reservations_safely(): void
    {
        $store = $this->store();
        $channel = $this->channel($store);
        $order = $this->order($store, $channel);
        config(['commerce.simulations_enabled' => false]);
        $store['stock']->update(['quantity' => 8]);
        app(CommerceService::class)->syncInventoryForTenant($store['tenant']->id);
        $this->getJson('/api/commerce')->assertOk()->assertJsonPath('channels.0.sync_state', 'stale')->assertJsonPath('channels.0.pending_sync', true)->assertJsonPath('channels.0.listings.0.published_quantity', 8)->assertJsonPath('channels.0.listings.0.available_quantity', 6);
        $this->postJson('/api/commerce/channels/'.$channel.'/sync', ['idempotency_key' => 'flag-disabled-key'])->assertNotFound();
        $this->postJson('/api/commerce/orders/'.$order.'/cancel')->assertOk();
        $this->assertDatabaseHas('stocks', ['id' => $store['stock']->id, 'quantity' => 8, 'reserved_quantity' => 0]);
        $this->assertDatabaseCount('channel_syncs', 0);
    }

    public function test_channel_grid_fetches_stocks_once_and_hides_internal_idempotency_hashes(): void
    {
        $store = $this->store();
        $this->channel($store);
        $this->channel($store, 'second-grid-store');
        $this->channel($store, 'third-grid-store');
        DB::enableQueryLog();
        $state = app(CommerceService::class)->state($store['actor']);
        $stockQueries = array_filter(DB::getQueryLog(), fn (array $query): bool => str_contains($query['query'], 'from "stocks"'));
        DB::disableQueryLog();
        $this->assertCount(1, $stockQueries);
        $this->assertCount(3, $state['channels']);
        $this->assertArrayNotHasKey('creation_hash', $state['channels'][0]);
        $this->assertArrayNotHasKey('creation_key', $state['channels'][0]);
    }

    public function test_old_pending_reservations_remain_visible_beyond_recent_handled_history(): void
    {
        $store = $this->store();
        $channel = $this->channel($store);
        $pending = $this->order($store, $channel);
        ChannelOrder::factory()->count(201)->create(['commerce_channel_id' => $channel, 'status' => 'canceled']);
        $state = $this->getJson('/api/commerce')->assertOk()->assertJsonPath('order_total', 202)
            ->assertJsonPath('pending_order_count', 1)->assertJsonCount(201, 'orders');
        $this->assertContains($pending, array_column($state->json('orders'), 'id'));
        $this->postJson('/api/commerce/orders/'.$pending.'/cancel')->assertOk();
        $this->assertDatabaseHas('stocks', ['id' => $store['stock']->id, 'quantity' => 10, 'reserved_quantity' => 0]);
    }

    public function test_direct_service_mutation_rechecks_expired_subscription_under_lock(): void
    {
        $store = $this->store();
        $channel = $this->channel($store);
        Subscription::where('tenant_id', $store['tenant']->id)->update(['trial_ends_at' => now()->subDays(8)]);
        try {
            app(CommerceService::class)->createOrder($store['actor'], $channel, $this->orderData($store));
            $this->fail('An expired subscription must not reserve inventory.');
        } catch (HttpResponseException $exception) {
            $this->assertSame(423, $exception->getResponse()->getStatusCode());
        }
        $this->assertDatabaseCount('channel_orders', 0);
        $this->assertDatabaseHas('stocks', ['id' => $store['stock']->id, 'reserved_quantity' => 0]);
    }

    public function test_manual_sync_retries_bind_actor_and_foreign_channel_ids_are_hidden(): void
    {
        $store = $this->store();
        $channel = $this->channel($store);
        $this->postJson('/api/commerce/channels/'.$channel.'/sync', ['idempotency_key' => 'actor-bound-sync'])->assertOk();
        $other = User::factory()->create(['tenant_id' => $store['tenant']->id, 'role' => 'admin']);
        $this->actingAs($other)->postJson('/api/commerce/channels/'.$channel.'/sync', ['idempotency_key' => 'actor-bound-sync'])->assertConflict();
        $foreign = CommerceChannel::factory()->create();
        $this->postJson('/api/commerce/channels/'.$foreign->id.'/sync', ['idempotency_key' => 'foreign-sync-key'])->assertNotFound();
        $this->postJson('/api/commerce/channels/'.$foreign->id.'/pause')->assertNotFound();
        $this->postJson('/api/commerce/channels/'.$foreign->id.'/resume')->assertNotFound();
        $this->assertDatabaseCount('channel_syncs', 1);
    }

    public function test_failed_import_rolls_back_reservation_release_sale_and_prior_line_deduction(): void
    {
        $store = $this->store();
        $channel = $this->channel($store);
        $product = Product::factory()->create(['tenant_id' => $store['tenant']->id, 'sku' => 'ZZ-SECOND-LINE']);
        $stock = Stock::factory()->create(['tenant_id' => $store['tenant']->id, 'location_id' => $store['location']->id, 'product_id' => $product->id, 'quantity' => 10]);
        $data = $this->orderData($store);
        $data['items'][] = ['sku' => $product->sku, 'quantity' => 2];
        $order = $this->postJson('/api/commerce/channels/'.$channel.'/orders', $data)->assertCreated()->json('id');
        $stock->update(['quantity' => 0]);
        $this->postJson('/api/commerce/orders/'.$order.'/import')->assertUnprocessable();
        $this->assertDatabaseHas('stocks', ['id' => $store['stock']->id, 'quantity' => 10, 'reserved_quantity' => 2]);
        $this->assertDatabaseHas('stocks', ['id' => $stock->id, 'quantity' => 0, 'reserved_quantity' => 2]);
        $this->assertDatabaseHas('channel_orders', ['id' => $order, 'status' => 'pending', 'sale_id' => null]);
        $this->assertDatabaseCount('sales', 0);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_case_insensitive_sku_aliases_cannot_reserve_the_same_product_twice(): void
    {
        $store = $this->store();
        $channel = $this->channel($store);
        DB::statement('CREATE TEMP TABLE commerce_test_products (id INTEGER, tenant_id INTEGER, name TEXT, sku TEXT COLLATE NOCASE, price_cents INTEGER)');
        DB::table('commerce_test_products')->insert($store['product']->only(['id', 'tenant_id', 'name', 'sku', 'price_cents']));
        Product::addGlobalScope('case-insensitive-test-catalog', fn (Builder $query): Builder => $query->from('commerce_test_products'));
        try {
            $alias = strtolower($store['product']->sku);
            $this->assertSame($store['product']->id, Product::where('tenant_id', $store['tenant']->id)->where('sku', $alias)->firstOrFail()->id);
            $data = $this->orderData($store);
            $data['items'][] = ['sku' => $alias, 'quantity' => 2];
            $this->postJson('/api/commerce/channels/'.$channel.'/orders', $data)->assertUnprocessable()->assertJsonValidationErrors('items');
            $this->assertDatabaseHas('stocks', ['id' => $store['stock']->id, 'quantity' => 10, 'reserved_quantity' => 0]);
            $this->assertDatabaseCount('channel_orders', 0);
            $this->assertDatabaseCount('sales', 0);
        } finally {
            Product::clearBootedModels();
            DB::statement('DROP TABLE commerce_test_products');
        }
    }

    public function test_case_sensitive_distinct_catalog_products_are_allowed_on_the_same_order(): void
    {
        $store = $this->store();
        $channel = $this->channel($store);
        $product = Product::factory()->create(['tenant_id' => $store['tenant']->id, 'sku' => strtolower($store['product']->sku), 'price_cents' => 5000]);
        $stock = Stock::factory()->create(['tenant_id' => $store['tenant']->id, 'location_id' => $store['location']->id, 'product_id' => $product->id, 'quantity' => 10]);
        $data = $this->orderData($store);
        $data['items'][] = ['sku' => $product->sku, 'quantity' => 1];
        $response = $this->postJson('/api/commerce/channels/'.$channel.'/orders', $data)->assertCreated()->assertJsonCount(2, 'items')->assertJsonPath('subtotal', 250);
        $this->assertEqualsCanonicalizing([$store['product']->id, $product->id], array_column($response->json('items'), 'product_id'));
        $this->assertDatabaseHas('stocks', ['id' => $store['stock']->id, 'quantity' => 10, 'reserved_quantity' => 2]);
        $this->assertDatabaseHas('stocks', ['id' => $stock->id, 'quantity' => 10, 'reserved_quantity' => 1]);
    }
}
