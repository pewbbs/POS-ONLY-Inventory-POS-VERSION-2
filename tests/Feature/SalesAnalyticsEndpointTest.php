<?php

namespace Tests\Feature;

use App\Models\CommerceChannel;
use App\Models\Location;
use App\Models\Product;
use App\Models\Sale;
use App\Models\Stock;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class SalesAnalyticsEndpointTest extends TestCase
{
    use LazilyRefreshDatabase;

    /** @return array{tenant: Tenant, user: User, location: Location, product: Product} */
    private function store(string $role = 'admin'): array
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'role' => $role]);
        $location = Location::factory()->create(['tenant_id' => $tenant->id]);
        $product = Product::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Endpoint item', 'sku' => 'ENDPOINT-SKU']);

        return compact('tenant', 'user', 'location', 'product');
    }

    /** @param array{tenant: Tenant, user: User, location: Location, product: Product} $store */
    private function receiptAttributes(array $store, int $quantity = 1, string $at = '2026-10-10 09:15:00', array $extra = []): array
    {
        return array_replace([
            'tenant_id' => $store['tenant']->id, 'user_id' => $store['user']->id, 'location_id' => $store['location']->id,
            'created_at' => $at, 'source' => 'pos', 'subtotal_cents' => $quantity * 1000,
            'discount_cents' => 0, 'tax_cents' => 0, 'total_cents' => $quantity * 1000,
            'paid_cents' => $quantity * 1000, 'change_cents' => 0, 'returned_cents' => 0,
            'payments' => [['method' => 'cash', 'amount_cents' => $quantity * 1000]],
            'items' => [[
                'product_id' => $store['product']->id, 'name' => $store['product']->name, 'sku' => $store['product']->sku,
                'quantity' => $quantity, 'unit_price_cents' => 1000, 'line_total_cents' => $quantity * 1000,
                'discount_cents' => 0, 'tax_cents' => 0, 'total_cents' => $quantity * 1000, 'returned_quantity' => 0,
            ]],
        ], $extra);
    }

    public function test_analytics_requires_authentication_and_rejects_paused_staff(): void
    {
        $this->getJson('/api/reports/analytics')->assertUnauthorized();
        $store = $this->store();
        $store['user']->update(['is_active' => false]);

        $this->actingAs($store['user'])->getJson('/api/reports/analytics')->assertForbidden();

        $this->assertDatabaseCount('activity_logs', 0);
    }

    #[TestWith(['admin'])]
    #[TestWith(['manager'])]
    public function test_business_analytics_includes_team_sales_but_excludes_other_tenants(string $role): void
    {
        $store = $this->store($role);
        $teammate = User::factory()->create(['tenant_id' => $store['tenant']->id, 'role' => 'cashier']);
        Sale::factory()->create($this->receiptAttributes($store));
        Sale::factory()->create($this->receiptAttributes($store, 2, extra: ['user_id' => $teammate->id]));
        $foreign = $this->store();
        Sale::factory()->create($this->receiptAttributes($foreign, 50));

        $this->actingAs($store['user'])->getJson('/api/reports/analytics')->assertOk()
            ->assertJsonPath('filters.scope', 'business')->assertJsonPath('filters.from', null)->assertJsonPath('filters.to', null)
            ->assertJsonPath('filters.location_id', null)->assertJsonPath('filters.source', 'all')->assertJsonPath('filters.rank_by', 'units')
            ->assertJsonPath('summary.transactions', 2)->assertJsonPath('summary.net_units', 3)->assertJsonPath('summary.net_total', 30)
            ->assertJsonPath('best_sellers.0.product_id', $store['product']->id)->assertJsonPath('product_count', 1);

        $this->assertDatabaseCount('activity_logs', 0);
    }

    public function test_cashier_analytics_cannot_expand_own_sales_with_actor_or_tenant_parameters(): void
    {
        $store = $this->store();
        $cashier = User::factory()->create(['tenant_id' => $store['tenant']->id, 'role' => 'cashier']);
        Sale::factory()->create($this->receiptAttributes($store, 2, extra: ['user_id' => $cashier->id]));
        Sale::factory()->create($this->receiptAttributes($store, 9));
        $foreign = $this->store();
        Sale::factory()->create($this->receiptAttributes($foreign, 50));
        $query = http_build_query(['actor_id' => $store['user']->id, 'user_id' => $store['user']->id, 'tenant_id' => $foreign['tenant']->id]);

        $response = $this->actingAs($cashier)->getJson('/api/reports/analytics?'.$query)->assertOk()
            ->assertJsonPath('filters.scope', 'own_sales')->assertJsonPath('summary.transactions', 1)
            ->assertJsonPath('summary.net_units', 2)->assertJsonPath('summary.net_total', 20)
            ->assertJsonPath('best_sellers.0.net_units', 2)->assertJsonPath('product_count', 1);

        $this->assertArrayNotHasKey('cost', $response->json('best_sellers.0'));
    }

    public function test_location_filter_hides_foreign_locations_and_limits_own_locations(): void
    {
        $store = $this->store();
        $second = Location::factory()->create(['tenant_id' => $store['tenant']->id]);
        Sale::factory()->create($this->receiptAttributes($store));
        Sale::factory()->create($this->receiptAttributes($store, 4, extra: ['location_id' => $second->id]));
        $foreign = Location::factory()->create();

        $this->actingAs($store['user'])->getJson('/api/reports/analytics?location_id='.$foreign->id)->assertNotFound();
        $this->getJson('/api/reports/analytics?location_id='.$second->id)->assertOk()
            ->assertJsonPath('filters.location_id', $second->id)->assertJsonPath('summary.transactions', 1)
            ->assertJsonPath('summary.net_units', 4)->assertJsonPath('summary.net_total', 40);
    }

    public function test_source_filter_keeps_pos_and_imported_channel_records_separate(): void
    {
        $store = $this->store();
        Sale::factory()->create($this->receiptAttributes($store));
        Sale::factory()->create($this->receiptAttributes($store, 3, extra: ['source' => 'channel']));

        $this->actingAs($store['user'])->getJson('/api/reports/analytics?source=pos&rank_by=net_total')->assertOk()
            ->assertJsonPath('filters.source', 'pos')->assertJsonPath('filters.rank_by', 'net_total')
            ->assertJsonPath('summary.transactions', 1)->assertJsonPath('summary.net_units', 1)
            ->assertJsonPath('summary.simulated_transactions', 0);
        $this->getJson('/api/reports/analytics?source=channel')->assertOk()
            ->assertJsonPath('filters.source', 'channel')->assertJsonPath('summary.transactions', 1)
            ->assertJsonPath('summary.net_units', 3)->assertJsonPath('summary.simulated_transactions', 1);
    }

    public function test_analytics_stays_available_when_subscription_writes_are_paused(): void
    {
        $store = $this->store();
        Subscription::factory()->create(['tenant_id' => $store['tenant']->id, 'status' => 'canceled', 'current_period_end' => now()->subDay()]);
        Sale::factory()->create($this->receiptAttributes($store));

        $this->actingAs($store['user'])->getJson('/api/reports/analytics')->assertOk()
            ->assertJsonPath('summary.transactions', 1)->assertJsonPath('summary.net_total', 10);
    }

    public function test_hourly_totals_and_best_sellers_include_older_receipts_beyond_bootstrap_limit(): void
    {
        $store = $this->store();
        Sale::factory()->count(500)->create($this->receiptAttributes($store));
        $historic = Product::factory()->create(['tenant_id' => $store['tenant']->id, 'name' => 'Historic winner', 'sku' => 'HISTORIC-WINNER']);
        $old = Sale::factory()->create($this->receiptAttributes(array_replace($store, ['product' => $historic]), 501, '2026-10-01 09:15:00'));

        $this->actingAs($store['user'])->getJson('/api/bootstrap')->assertOk()->assertJsonCount(500, 'sales')->assertJsonPath('history_total', 501);
        $response = $this->getJson('/api/reports/analytics?from=2026-10-01&to=2026-10-11')->assertOk()
            ->assertJsonPath('summary.transactions', 501)->assertJsonPath('summary.net_units', 1001)->assertJsonPath('summary.net_total', 10010)
            ->assertJsonPath('best_sellers.0.product_id', $historic->id)->assertJsonPath('best_sellers.0.net_units', 501)
            ->assertJsonPath('product_count', 2)->assertJsonCount(24, 'hourly')->assertJsonPath('hourly.9.transactions', 501)
            ->assertJsonPath('hourly.9.net_units', 1001)->assertJsonPath('peak_hours', [9]);

        $this->assertSame('HISTORIC-WINNER', $response->json('best_sellers.0.sku'));
        $this->assertDatabaseHas('sales', ['id' => $old->id, 'total_cents' => 501000]);
    }

    public function test_manila_date_bounds_convert_utc_storage_and_include_both_local_midnight_edges(): void
    {
        $previousTimezone = date_default_timezone_get();
        config(['app.timezone' => 'UTC']);
        date_default_timezone_set('UTC');
        try {
            $store = $this->store();
            foreach (['2026-10-09 15:59:59', '2026-10-09 16:00:00', '2026-10-10 05:00:00', '2026-10-10 15:59:59', '2026-10-10 16:00:00'] as $at) {
                Sale::factory()->create($this->receiptAttributes($store, at: $at));
            }

            $this->actingAs($store['user'])->getJson('/api/reports/analytics?from=2026-10-10&to=2026-10-10')->assertOk()
                ->assertJsonPath('filters.timezone', 'Asia/Manila')->assertJsonPath('summary.transactions', 3)
                ->assertJsonPath('summary.net_total', 30)->assertJsonPath('hourly.0.transactions', 1)
                ->assertJsonPath('hourly.13.transactions', 1)->assertJsonPath('hourly.23.transactions', 1)
                ->assertJsonPath('peak_hours', [0, 13, 23]);
        } finally {
            date_default_timezone_set($previousTimezone);
        }
    }

    public function test_pos_and_channel_checkout_reports_keep_original_amounts_after_later_returns(): void
    {
        Http::preventStrayRequests();
        config(['commerce.simulations_enabled' => true]);
        $this->travelTo(now()->setDate(2026, 10, 10)->setTime(9, 15));
        $store = $this->store();
        $store['tenant']->update(['settings' => array_replace(Tenant::defaultSettings(), ['tax_percent' => 12])]);
        $store['product']->update(['price_cents' => 45000, 'reorder_level' => 1]);
        $second = Product::factory()->create(['tenant_id' => $store['tenant']->id, 'price_cents' => 10000, 'reorder_level' => 1]);
        $firstStock = Stock::factory()->create(['tenant_id' => $store['tenant']->id, 'location_id' => $store['location']->id, 'product_id' => $store['product']->id, 'quantity' => 10]);
        $secondStock = Stock::factory()->create(['tenant_id' => $store['tenant']->id, 'location_id' => $store['location']->id, 'product_id' => $second->id, 'quantity' => 10]);
        $channel = CommerceChannel::factory()->create(['tenant_id' => $store['tenant']->id, 'location_id' => $store['location']->id, 'user_id' => $store['user']->id]);
        $posSale = $this->actingAs($store['user'])->postJson('/api/sales', [
            'location_id' => $store['location']->id,
            'items' => [['product_id' => $store['product']->id, 'quantity' => 3], ['product_id' => $second->id, 'quantity' => 2]],
            'discount_percent' => 10, 'tax_percent' => 12, 'payments' => [['method' => 'cash', 'amount' => 2000]],
            'idempotency_key' => 'analytics-e2e-pos-sale',
        ])->assertCreated()->assertJsonPath('total', 1562.4)->json('id');
        $this->travelTo(now()->setTime(17, 20));
        $order = $this->postJson('/api/commerce/channels/'.$channel->id.'/orders', [
            'external_reference' => 'ANALYTICS-E2E-WEB', 'idempotency_key' => 'analytics-e2e-channel-order',
            'items' => [['sku' => $store['product']->sku, 'quantity' => 2]],
        ])->assertCreated()->json('id');
        $channelSale = $this->postJson('/api/commerce/orders/'.$order.'/import')->assertOk()->json('sale_id');
        $store['product']->update(['price_cents' => 99000]);
        $store['tenant']->update(['settings' => array_replace($store['tenant']->settings, ['tax_percent' => 25])]);
        $this->travelTo(now()->setDate(2026, 10, 11)->setTime(12, 0));
        foreach ([$posSale => 'analytics-e2e-pos-return', $channelSale => 'analytics-e2e-channel-return'] as $saleId => $key) {
            $this->postJson('/api/sales/'.$saleId.'/return', [
                'items' => [['product_id' => $store['product']->id, 'quantity' => 1]],
                'reason' => 'One original item returned', 'idempotency_key' => $key,
            ])->assertOk();
        }
        $filters = http_build_query(['from' => '2026-10-10', 'to' => '2026-10-10', 'location_id' => $store['location']->id]);

        $this->getJson('/api/reports/analytics?'.$filters)->assertOk()
            ->assertJsonPath('summary.transactions', 2)->assertJsonPath('summary.gross_total', 2570.4)
            ->assertJsonPath('summary.returned_total', 957.6)->assertJsonPath('summary.net_total', 1612.8)
            ->assertJsonPath('summary.units_sold', 7)->assertJsonPath('summary.units_returned', 2)->assertJsonPath('summary.net_units', 5)
            ->assertJsonPath('summary.returned_transactions', 2)->assertJsonPath('summary.simulated_transactions', 1)
            ->assertJsonPath('summary.average_basket', 806.4)->assertJsonPath('peak_hours', [9, 17])
            ->assertJsonPath('hourly.9.net_total', 1108.8)->assertJsonPath('hourly.17.net_total', 504)
            ->assertJsonPath('best_sellers.0.product_id', $store['product']->id)->assertJsonPath('best_sellers.0.net_total', 1411.2)
            ->assertJsonPath('best_sellers.0.net_units', 3)->assertJsonPath('best_sellers.1.net_total', 201.6);
        $this->getJson('/api/reports/analytics?'.$filters.'&source=pos')->assertOk()
            ->assertJsonPath('summary.transactions', 1)->assertJsonPath('summary.net_total', 1108.8)->assertJsonPath('peak_hours', [9]);
        $this->getJson('/api/reports/analytics?'.$filters.'&source=channel')->assertOk()
            ->assertJsonPath('summary.transactions', 1)->assertJsonPath('summary.net_total', 504)->assertJsonPath('peak_hours', [17]);
        $this->getJson('/api/reports/analytics?from=2026-10-11&to=2026-10-11')->assertOk()->assertJsonPath('summary.transactions', 0);
        $csv = $this->get('/api/reports/export?'.$filters)->assertOk()->streamedContent();
        $rows = array_map(fn (string $line): array => str_getcsv($line, ',', '"', ''), explode("\n", trim($csv)));

        $this->assertCount(3, $rows);
        $this->assertSame(['504', '1108.8'], array_column(array_slice($rows, 1), 9));
        $this->assertSame(['channel', 'pos'], array_column(array_slice($rows, 1), 11));
        $this->assertDatabaseHas('stocks', ['id' => $firstStock->id, 'quantity' => 7, 'reserved_quantity' => 0]);
        $this->assertDatabaseHas('stocks', ['id' => $secondStock->id, 'quantity' => 8]);
        $this->assertDatabaseCount('sales', 2);
        $this->assertDatabaseCount('sale_returns', 2);
        Http::assertNothingSent();
    }

    public static function invalidFilters(): array
    {
        return [
            'missing end date' => [['from' => '2026-10-01'], 'to'],
            'missing start date' => [['to' => '2026-10-11'], 'from'],
            'invalid calendar date' => [['from' => '2026-02-30', 'to' => '2026-03-01'], 'from'],
            'reversed range' => [['from' => '2026-10-12', 'to' => '2026-10-11'], 'from'],
            'date array' => [['from' => ['2026-10-01'], 'to' => '2026-10-11'], 'from'],
            'unsupported source' => [['source' => 'wallet'], 'source'],
            'source array' => [['source' => ['pos']], 'source'],
            'unsupported ranking' => [['rank_by' => 'cost'], 'rank_by'],
            'ranking injection' => [['rank_by' => 'net_total; DROP TABLE sales'], 'rank_by'],
            'location injection' => [['location_id' => '1 OR 1=1'], 'location_id'],
        ];
    }

    #[DataProvider('invalidFilters')]
    public function test_invalid_analytics_filters_return_422_without_mutating_receipts(array $filters, string $field): void
    {
        $store = $this->store();
        $sale = Sale::factory()->create($this->receiptAttributes($store));

        $this->actingAs($store['user'])->getJson('/api/reports/analytics?'.http_build_query($filters))
            ->assertUnprocessable()->assertJsonValidationErrors($field);

        $this->assertDatabaseHas('sales', ['id' => $sale->id, 'total_cents' => 1000, 'returned_cents' => 0]);
        $this->assertDatabaseCount('sales', 1);
        $this->assertDatabaseCount('activity_logs', 0);
    }
}
