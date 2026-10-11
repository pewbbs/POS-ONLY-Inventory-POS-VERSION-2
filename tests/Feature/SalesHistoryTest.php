<?php

namespace Tests\Feature;

use App\Models\ChannelOrder;
use App\Models\CommerceChannel;
use App\Models\Customer;
use App\Models\Location;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleReturn;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class SalesHistoryTest extends TestCase
{
    use LazilyRefreshDatabase;

    /** @return array{tenant: Tenant, user: User, location: Location} */
    private function store(string $role = 'admin'): array
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'role' => $role]);
        $location = Location::factory()->create(['tenant_id' => $tenant->id]);

        return compact('tenant', 'user', 'location');
    }

    /** @param array{tenant: Tenant, user: User, location: Location} $store */
    private function receiptAttributes(array $store, array $extra = []): array
    {
        return array_replace([
            'tenant_id' => $store['tenant']->id, 'user_id' => $store['user']->id,
            'location_id' => $store['location']->id, 'source' => 'pos',
            'created_at' => '2026-10-10 09:00:00',
        ], $extra);
    }

    public function test_history_requires_authentication_and_rejects_inactive_staff_as_403(): void
    {
        $this->getJson('/api/sales')->assertUnauthorized();
        $store = $this->store();
        $store['user']->update(['is_active' => false]);

        $this->actingAs($store['user'])->getJson('/api/sales')->assertForbidden();

        $this->assertDatabaseCount('subscriptions', 0);
        $this->assertDatabaseCount('billing_invoices', 0);
        $this->assertDatabaseCount('activity_logs', 0);
    }

    #[TestWith(['admin'])]
    #[TestWith(['manager'])]
    public function test_business_roles_see_team_receipts_and_never_another_company(string $role): void
    {
        $store = $this->store($role);
        $cashier = User::factory()->create(['tenant_id' => $store['tenant']->id, 'role' => 'cashier']);
        $first = Sale::factory()->create($this->receiptAttributes($store));
        $second = Sale::factory()->create($this->receiptAttributes($store, ['user_id' => $cashier->id]));
        $foreign = Sale::factory()->create();

        $response = $this->actingAs($store['user'])->getJson('/api/sales')->assertOk()
            ->assertJsonPath('filters.scope', 'business')->assertJsonPath('meta.total', 2)
            ->assertJsonCount(2, 'data')->assertJsonPath('data.0.id', $second->id)->assertJsonPath('data.1.id', $first->id);

        $this->assertNotContains($foreign->id, array_column($response->json('data'), 'id'));
        $this->getJson('/api/sales?receipt='.urlencode($foreign->number))->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('meta.total', 0);
        $this->assertDatabaseCount('subscriptions', 0);
        $this->assertDatabaseCount('billing_invoices', 0);
    }

    public function test_cashier_cannot_expand_receipt_access_with_company_or_actor_parameters(): void
    {
        $store = $this->store('cashier');
        $own = Sale::factory()->create($this->receiptAttributes($store));
        $teammate = User::factory()->create(['tenant_id' => $store['tenant']->id, 'role' => 'cashier']);
        $other = Sale::factory()->create($this->receiptAttributes($store, ['user_id' => $teammate->id]));
        $foreign = Sale::factory()->create();
        $query = http_build_query(['tenant_id' => $foreign->tenant_id, 'user_id' => $teammate->id, 'actor_id' => $teammate->id]);

        $this->actingAs($store['user'])->getJson('/api/sales?'.$query)->assertOk()
            ->assertJsonPath('filters.scope', 'own_sales')->assertJsonPath('meta.total', 1)
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $own->id);

        $this->getJson('/api/sales?receipt='.urlencode($other->number))->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/sales?receipt='.urlencode($foreign->number))->assertOk()->assertJsonCount(0, 'data');
        $this->assertDatabaseCount('activity_logs', 0);
    }

    public function test_old_receipt_is_found_after_more_than_five_hundred_newer_sales(): void
    {
        $store = $this->store();
        $old = Sale::factory()->create($this->receiptAttributes($store, ['number' => 'SR-HISTORIC-0001', 'created_at' => '2025-01-01 00:00:00']));
        Sale::factory()->count(500)->create($this->receiptAttributes($store));

        $this->actingAs($store['user'])->getJson('/api/sales?'.http_build_query(['receipt' => '  sr-historic-0001  ']))
            ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('meta.per_page', 25)
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $old->id)
            ->assertJsonPath('filters.receipt', 'sr-historic-0001');

        $this->assertDatabaseCount('sales', 501);
        $this->assertDatabaseCount('subscriptions', 0);
        $this->assertDatabaseCount('billing_invoices', 0);
    }

    public function test_blank_receipt_filter_shows_history_without_creating_billing_records(): void
    {
        $store = $this->store();
        Sale::factory()->count(2)->create($this->receiptAttributes($store));

        $this->actingAs($store['user'])->getJson('/api/sales?'.http_build_query(['receipt' => '  ']))
            ->assertOk()->assertJsonPath('filters.receipt', null)->assertJsonPath('meta.total', 2)->assertJsonCount(2, 'data');

        $this->assertDatabaseCount('subscriptions', 0);
        $this->assertDatabaseCount('billing_invoices', 0);
        $this->assertDatabaseCount('activity_logs', 0);
    }

    #[TestWith(['SR-%_QUOTE'])]
    #[TestWith(["SR-' OR 1=1 --"])]
    public function test_receipt_search_matches_only_the_full_literal_number(string $number): void
    {
        $store = $this->store();
        $exact = Sale::factory()->create($this->receiptAttributes($store, ['number' => $number]));
        Sale::factory()->create($this->receiptAttributes($store, ['number' => $number.'-EXTRA']));
        Sale::factory()->create($this->receiptAttributes($store, ['number' => 'SR-UNRELATED']));

        $this->actingAs($store['user'])->getJson('/api/sales?'.http_build_query(['receipt' => $number]))
            ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $exact->id);

        $this->assertDatabaseCount('sales', 3);
        $this->assertDatabaseCount('activity_logs', 0);
    }

    public function test_manila_dates_location_and_source_filters_include_both_day_edges_and_returned_receipts(): void
    {
        config(['app.timezone' => 'UTC']);
        $store = $this->store();
        $otherLocation = Location::factory()->create(['tenant_id' => $store['tenant']->id]);
        $base = $this->receiptAttributes($store, ['source' => 'channel']);
        $start = Sale::factory()->create(array_replace($base, ['created_at' => '2026-10-09 16:00:00']));
        $end = Sale::factory()->create(array_replace($base, ['created_at' => '2026-10-10 15:59:59', 'status' => 'returned']));
        Sale::factory()->create(array_replace($base, ['created_at' => '2026-10-09 15:59:59']));
        Sale::factory()->create(array_replace($base, ['created_at' => '2026-10-10 16:00:00']));
        Sale::factory()->create(array_replace($base, ['created_at' => '2026-10-10 03:00:00', 'source' => 'pos']));
        Sale::factory()->create(array_replace($base, ['created_at' => '2026-10-10 03:00:00', 'location_id' => $otherLocation->id]));
        $query = http_build_query(['from' => '2026-10-10', 'to' => '2026-10-10', 'location_id' => $store['location']->id, 'source' => 'channel']);

        $this->actingAs($store['user'])->getJson('/api/sales?'.$query)->assertOk()->assertJsonPath('meta.total', 2)
            ->assertJsonPath('filters.timezone', 'Asia/Manila')->assertJsonPath('filters.from', '2026-10-10')
            ->assertJsonPath('filters.to', '2026-10-10')->assertJsonPath('filters.location_id', $store['location']->id)
            ->assertJsonPath('filters.source', 'channel')->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $end->id)->assertJsonPath('data.0.status', 'returned')
            ->assertJsonPath('data.1.id', $start->id);

        $this->assertDatabaseCount('sales', 6);
        $this->assertDatabaseCount('activity_logs', 0);
    }

    public function test_foreign_location_filter_is_404_without_disclosing_its_receipts(): void
    {
        $store = $this->store();
        $foreign = Location::factory()->create();

        $this->actingAs($store['user'])->getJson('/api/sales?location_id='.$foreign->id)->assertNotFound();

        $this->assertDatabaseCount('billing_invoices', 0);
    }

    public function test_fixed_pagination_uses_creation_time_and_id_to_prevent_duplicates_on_timestamp_ties(): void
    {
        $store = $this->store();
        $sameTime = Sale::factory()->count(51)->create($this->receiptAttributes($store));
        $older = Sale::factory()->create($this->receiptAttributes($store, ['created_at' => '2025-01-01 00:00:00']));
        $expected = [...$sameTime->pluck('id')->reverse()->values()->all(), $older->id];

        $this->actingAs($store['user']);
        $first = $this->getJson('/api/sales?per_page=1000&source=pos')->assertOk()->assertJsonCount(25, 'data')
            ->assertJsonPath('meta.current_page', 1)->assertJsonPath('meta.last_page', 3)
            ->assertJsonPath('meta.per_page', 25)->assertJsonPath('meta.total', 52);
        $second = $this->getJson('/api/sales?page=2')->assertOk()->assertJsonCount(25, 'data')->assertJsonPath('meta.current_page', 2);
        $third = $this->getJson('/api/sales?page=3')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('meta.current_page', 3);

        $actual = [...array_column($first->json('data'), 'id'), ...array_column($second->json('data'), 'id'), ...array_column($third->json('data'), 'id')];
        $this->assertSame($expected, $actual);
        $this->assertCount(52, array_unique($actual));
        $query = [];
        parse_str(parse_url($first->json('links.next'), PHP_URL_QUERY), $query);
        $this->assertSame('pos', $query['source']);
        $this->assertSame('2', $query['page']);
        $this->getJson('/api/sales?page=4')->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('meta.total', 52);
    }

    public function test_history_preserves_complete_receipt_items_payments_return_details_and_channel_context(): void
    {
        $store = $this->store();
        $product = Product::factory()->create(['tenant_id' => $store['tenant']->id, 'name' => 'Black shirt · Small', 'sku' => 'SHIRT-S-BLACK']);
        $customer = Customer::factory()->create(['tenant_id' => $store['tenant']->id]);
        $channel = CommerceChannel::factory()->create(['tenant_id' => $store['tenant']->id, 'location_id' => $store['location']->id, 'user_id' => $store['user']->id, 'name' => 'Local storefront']);
        $item = ['product_id' => $product->id, 'name' => $product->name, 'sku' => $product->sku, 'quantity' => 2,
            'unit_price_cents' => 5000, 'line_total_cents' => 10000, 'discount_cents' => 1000,
            'tax_cents' => 1080, 'total_cents' => 10080, 'returned_quantity' => 1];
        $sale = Sale::factory()->create($this->receiptAttributes($store, [
            'source' => 'channel', 'channel_id' => $channel->id, 'customer_id' => $customer->id,
            'status' => 'partially_returned', 'price_tier' => 'wholesale', 'items' => [$item],
            'subtotal_cents' => 10000, 'discount_percent' => 10, 'discount_cents' => 1000, 'tax_percent' => 12, 'tax_cents' => 1080,
            'total_cents' => 10080, 'paid_cents' => 15000, 'change_cents' => 4920, 'returned_cents' => 5040,
            'payments' => [['method' => 'cash', 'amount_cents' => 15000]],
        ]));
        ChannelOrder::factory()->create(['commerce_channel_id' => $channel->id, 'tenant_id' => $store['tenant']->id,
            'location_id' => $store['location']->id, 'user_id' => $store['user']->id, 'sale_id' => $sale->id, 'external_reference' => 'SIM-ORDER-1001']);
        $returnItem = ['product_id' => $product->id, 'name' => $product->name, 'sku' => $product->sku, 'quantity' => 1,
            'subtotal_cents' => 5000, 'discount_cents' => 500, 'tax_cents' => 540, 'total_cents' => 5040];
        $return = SaleReturn::factory()->create(['sale_id' => $sale->id, 'tenant_id' => $store['tenant']->id, 'user_id' => $store['user']->id,
            'reason' => 'Wrong size', 'items' => [$returnItem], 'subtotal_cents' => 5000, 'discount_cents' => 500, 'tax_cents' => 540, 'total_cents' => 5040]);

        $response = $this->actingAs($store['user'])->getJson('/api/sales')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.cashier_name', $store['user']->name)->assertJsonPath('data.0.customer_id', $customer->id)
            ->assertJsonPath('data.0.price_tier', 'wholesale')->assertJsonPath('data.0.channel_name', 'Local storefront')
            ->assertJsonPath('data.0.external_reference', 'SIM-ORDER-1001')->assertJsonPath('data.0.simulated', true)
            ->assertJsonPath('data.0.subtotal', 100)->assertJsonPath('data.0.discount', 10)->assertJsonPath('data.0.tax', 10.8)
            ->assertJsonPath('data.0.total', 100.8)->assertJsonPath('data.0.paid', 150)->assertJsonPath('data.0.change', 49.2)
            ->assertJsonPath('data.0.returned_amount', 50.4)->assertJsonPath('data.0.net_total', 50.4)
            ->assertJsonPath('data.0.items.0.sku', 'SHIRT-S-BLACK')->assertJsonPath('data.0.items.0.quantity', 2)
            ->assertJsonPath('data.0.items.0.returned_quantity', 1)->assertJsonPath('data.0.items.0.returnable_quantity', 1)
            ->assertJsonPath('data.0.items.0.returnable_amount', 50.4)->assertJsonPath('data.0.payments.0.method', 'cash')
            ->assertJsonPath('data.0.payments.0.amount', 150)->assertJsonPath('data.0.returns.0.id', $return->id)
            ->assertJsonPath('data.0.returns.0.actor_name', $store['user']->name)->assertJsonPath('data.0.returns.0.reason', 'Wrong size')
            ->assertJsonPath('data.0.returns.0.items.0.total', 50.4);

        $single = $this->getJson('/api/sales/'.$sale->id)->assertOk();
        $this->assertSame($single->json(), $response->json('data.0'));
        $this->assertDatabaseCount('activity_logs', 0);
    }

    public static function invalidFilters(): array
    {
        return [
            'receipt array' => [['receipt' => ['SR-1']], 'receipt'],
            'receipt too long' => [['receipt' => str_repeat('a', 101)], 'receipt'],
            'zero page' => [['page' => 0], 'page'],
            'negative page' => [['page' => -1], 'page'],
            'fractional page' => [['page' => 1.5], 'page'],
            'oversized page' => [['page' => 100001], 'page'],
            'page array' => [['page' => [1]], 'page'],
            'page injection' => [['page' => '1 OR 1=1'], 'page'],
            'missing end date' => [['from' => '2026-10-10'], 'to'],
            'missing start date' => [['to' => '2026-10-10'], 'from'],
            'invalid date' => [['from' => '2026-02-30', 'to' => '2026-03-01'], 'from'],
            'reversed dates' => [['from' => '2026-10-11', 'to' => '2026-10-10'], 'from'],
            'unsupported source' => [['source' => 'wallet'], 'source'],
            'location injection' => [['location_id' => '1 OR 1=1'], 'location_id'],
        ];
    }

    #[DataProvider('invalidFilters')]
    public function test_invalid_history_filters_are_422_and_preserve_receipt_and_billing_records(array $filters, string $field): void
    {
        $store = $this->store();
        $sale = Sale::factory()->create($this->receiptAttributes($store));

        $this->actingAs($store['user'])->getJson('/api/sales?'.http_build_query($filters))
            ->assertUnprocessable()->assertJsonValidationErrors($field);

        $this->assertDatabaseHas('sales', ['id' => $sale->id, 'total_cents' => 10000]);
        $this->assertDatabaseCount('sales', 1);
        $this->assertDatabaseCount('subscriptions', 0);
        $this->assertDatabaseCount('billing_invoices', 0);
        $this->assertDatabaseCount('billing_payment_attempts', 0);
        $this->assertDatabaseCount('activity_logs', 0);
    }

    public function test_expired_subscription_and_platform_pause_keep_receipt_history_available_without_billing_or_provider_changes(): void
    {
        Http::preventStrayRequests();
        $this->freezeTime();
        $store = $this->store();
        $store['tenant']->update(['operations_paused' => true]);
        $subscription = Subscription::factory()->create(['tenant_id' => $store['tenant']->id, 'status' => 'canceled',
            'cancel_at_period_end' => true, 'trial_ends_at' => now()->subDays(40), 'current_period_end' => now()->subDays(10)]);
        $before = $subscription->fresh()->getAttributes();
        $sale = Sale::factory()->create($this->receiptAttributes($store));

        $this->actingAs($store['user'])->getJson('/api/sales')->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $sale->id);

        $this->assertSame($before, $subscription->fresh()->getAttributes());
        $this->assertDatabaseCount('subscriptions', 1);
        $this->assertDatabaseCount('billing_invoices', 0);
        $this->assertDatabaseCount('billing_payment_attempts', 0);
        $this->assertDatabaseCount('paymongo_webhook_events', 0);
        $this->assertDatabaseCount('activity_logs', 0);
        Http::assertNothingSent();
    }
}
