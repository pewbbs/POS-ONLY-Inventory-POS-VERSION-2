<?php

namespace Tests\Feature;

use App\Models\ChannelOrder;
use App\Models\Location;
use App\Models\Product;
use App\Models\Sale;
use App\Models\Stock;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class SaleCostSnapshotTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function store(string $role = 'manager', int $cost = 21000): array
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'role' => $role]);
        $location = Location::factory()->create(['tenant_id' => $tenant->id]);
        $product = Product::factory()->create(['tenant_id' => $tenant->id, 'price_cents' => 45000, 'cost_cents' => $cost]);
        Stock::factory()->create(['tenant_id' => $tenant->id, 'product_id' => $product->id, 'location_id' => $location->id, 'quantity' => 10]);

        return compact('tenant', 'user', 'location', 'product');
    }

    private function checkout(array $store): array
    {
        return [
            'location_id' => $store['location']->id,
            'items' => [['product_id' => $store['product']->id, 'quantity' => 2, 'unit_cost_cents' => 1]],
            'payments' => [['method' => 'cash', 'amount' => 1100]],
            'discount_percent' => 10, 'tax_percent' => 12, 'idempotency_key' => 'capture-pos-item-cost',
        ];
    }

    #[TestWith([21000])]
    #[TestWith([0])]
    public function test_checkout_snapshots_server_cost_and_keeps_it_on_replay_without_exposing_cost_to_cashiers(int $cost): void
    {
        $store = $this->store('cashier', $cost);
        $data = $this->checkout($store);

        $saleId = $this->actingAs($store['user'])->postJson('/api/sales', $data)->assertCreated()
            ->assertJsonMissingPath('items.0.unit_cost_cents')->assertJsonMissingPath('items.0.unit_cost')->json('id');
        $this->assertSame($cost, Sale::findOrFail($saleId)->items[0]['unit_cost_cents']);
        $store['product']->update(['cost_cents' => 99999, 'price_cents' => 99999]);
        $this->postJson('/api/sales', $data)->assertCreated()->assertJsonPath('id', $saleId)->assertJsonPath('total', 907.2);
        $this->getJson('/api/bootstrap')->assertOk()->assertJsonMissingPath('sales.0.items.0.unit_cost_cents');

        $this->assertSame($cost, Sale::findOrFail($saleId)->items[0]['unit_cost_cents']);
        $this->assertDatabaseCount('sales', 1);
        $this->assertDatabaseHas('stocks', ['product_id' => $store['product']->id, 'quantity' => 8]);
    }

    public function test_item_returns_preserve_original_cost_after_catalog_changes(): void
    {
        $store = $this->store();
        $saleId = $this->actingAs($store['user'])->postJson('/api/sales', $this->checkout($store))->assertCreated()->json('id');
        $store['product']->update(['cost_cents' => 99999]);

        $this->postJson('/api/sales/'.$saleId.'/return', [
            'items' => [['product_id' => $store['product']->id, 'quantity' => 1]],
            'reason' => 'Return original item', 'idempotency_key' => 'return-cost-snapshot-item',
        ])->assertOk()->assertJsonPath('returned_amount', 453.6)->assertJsonPath('net_total', 453.6);

        $this->assertSame(21000, Sale::findOrFail($saleId)->items[0]['unit_cost_cents']);
        $this->assertDatabaseCount('sale_returns', 1);
        $this->assertDatabaseHas('stocks', ['product_id' => $store['product']->id, 'quantity' => 9]);
    }

    public function test_channel_import_preserves_server_cost_from_the_quote_and_retries_without_duplicates(): void
    {
        $store = $this->store();
        $channelId = $this->actingAs($store['user'])->postJson('/api/commerce/channels', [
            'name' => 'Local snapshot store', 'kind' => 'web_store', 'location_id' => $store['location']->id,
            'idempotency_key' => 'cost-snapshot-channel',
        ])->assertCreated()->json('id');
        $data = ['external_reference' => 'COST-QUOTE-1', 'idempotency_key' => 'cost-snapshot-order',
            'items' => [['sku' => $store['product']->sku, 'quantity' => 2, 'unit_cost_cents' => 1]]];
        $orderId = $this->postJson('/api/commerce/channels/'.$channelId.'/orders', $data)->assertCreated()->json('id');
        $this->assertSame(21000, ChannelOrder::findOrFail($orderId)->items[0]['unit_cost_cents']);
        $store['product']->update(['cost_cents' => 99999, 'price_cents' => 99999]);

        $this->postJson('/api/commerce/channels/'.$channelId.'/orders', $data)->assertCreated()->assertJsonPath('id', $orderId);
        $saleId = $this->postJson('/api/commerce/orders/'.$orderId.'/import')->assertOk()->assertJsonPath('total', 1008)->json('sale_id');
        $this->postJson('/api/commerce/orders/'.$orderId.'/import')->assertOk()->assertJsonPath('sale_id', $saleId);

        $this->assertSame(21000, Sale::findOrFail($saleId)->items[0]['unit_cost_cents']);
        $this->assertDatabaseCount('sales', 1);
        $this->assertDatabaseCount('channel_orders', 1);
        $this->assertDatabaseHas('stocks', ['product_id' => $store['product']->id, 'quantity' => 8, 'reserved_quantity' => 0]);
    }

    public function test_importing_a_legacy_quote_does_not_backfill_cost_from_todays_catalog(): void
    {
        $store = $this->store();
        $channelId = $this->actingAs($store['user'])->postJson('/api/commerce/channels', [
            'name' => 'Legacy quote store', 'kind' => 'web_store', 'location_id' => $store['location']->id,
            'idempotency_key' => 'legacy-cost-channel',
        ])->assertCreated()->json('id');
        $orderId = $this->postJson('/api/commerce/channels/'.$channelId.'/orders', [
            'external_reference' => 'LEGACY-COST-1', 'idempotency_key' => 'legacy-cost-order',
            'items' => [['sku' => $store['product']->sku, 'quantity' => 1]],
        ])->assertCreated()->json('id');
        $order = ChannelOrder::findOrFail($orderId);
        $items = $order->items;
        unset($items[0]['unit_cost_cents']);
        $order->update(['items' => $items]);

        $saleId = $this->postJson('/api/commerce/orders/'.$orderId.'/import')->assertOk()->json('sale_id');

        $this->assertArrayNotHasKey('unit_cost_cents', Sale::findOrFail($saleId)->items[0]);
        $this->assertDatabaseCount('sales', 1);
        $this->assertDatabaseHas('stocks', ['product_id' => $store['product']->id, 'quantity' => 9]);
    }

    public function test_financial_margin_follows_the_original_cost_and_the_selected_sale_or_refund_dates(): void
    {
        $store = $this->store();
        $this->travelTo(now()->setDate(2026, 10, 1)->setTime(9, 30));
        $saleId = $this->actingAs($store['user'])->postJson('/api/sales', $this->checkout($store))->assertCreated()->json('id');
        $store['product']->update(['cost_cents' => 99999, 'price_cents' => 99999]);
        $this->travelTo(now()->setDate(2026, 10, 11)->setTime(11, 0));
        $this->postJson('/api/sales/'.$saleId.'/return', [
            'items' => [['product_id' => $store['product']->id, 'quantity' => 1]],
            'reason' => 'Later return of the original item', 'idempotency_key' => 'financial-later-return',
        ])->assertOk();

        $this->getJson('/api/reports/financial?from=2026-10-01&to=2026-10-01&basis=sale_cohort')->assertOk()
            ->assertJsonPath('sales.revenue_ex_tax', 810)->assertJsonPath('refunds.revenue_ex_tax', 405)
            ->assertJsonPath('net.revenue_ex_tax', 405)->assertJsonPath('net.tax', 48.6)->assertJsonPath('net.total', 453.6)
            ->assertJsonPath('costs.sales_cost', 420)->assertJsonPath('costs.returned_cost', 210)
            ->assertJsonPath('costs.net_cost', 210)->assertJsonPath('costs.estimated_gross_margin', 195)
            ->assertJsonPath('costs.missing_sales_units', 0)->assertJsonPath('costs.missing_return_units', 0);
        $this->getJson('/api/reports/financial?from=2026-10-11&to=2026-10-11&basis=activity')->assertOk()
            ->assertJsonPath('summary.sales_count', 0)->assertJsonPath('summary.refund_count', 1)
            ->assertJsonPath('net.revenue_ex_tax', -405)->assertJsonPath('net.tax', -48.6)->assertJsonPath('net.total', -453.6)
            ->assertJsonPath('costs.net_cost', -210)->assertJsonPath('costs.estimated_gross_margin', -195)
            ->assertJsonCount(0, 'tenders');

        $this->assertSame(21000, Sale::findOrFail($saleId)->items[0]['unit_cost_cents']);
        $this->assertDatabaseCount('sales', 1);
        $this->assertDatabaseCount('sale_returns', 1);
    }
}
