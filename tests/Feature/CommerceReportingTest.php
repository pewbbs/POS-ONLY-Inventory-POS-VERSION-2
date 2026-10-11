<?php

namespace Tests\Feature;

use App\Models\ChannelOrder;
use App\Models\CommerceChannel;
use App\Models\Location;
use App\Models\Product;
use App\Models\Sale;
use App\Models\Stock;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class CommerceReportingTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_channel_sales_keep_their_origin_in_reports_and_after_a_partial_return(): void
    {
        $tenant = Tenant::factory()->create(['settings' => array_replace(Tenant::defaultSettings(), ['tax_percent' => 12])]);
        $actor = User::factory()->create(['tenant_id' => $tenant->id, 'role' => 'admin']);
        $location = Location::factory()->create(['tenant_id' => $tenant->id]);
        $product = Product::factory()->create(['tenant_id' => $tenant->id, 'sku' => 'TOTE-REPORT', 'price_cents' => 45000, 'reorder_level' => 1]);
        Stock::create(['tenant_id' => $tenant->id, 'location_id' => $location->id, 'product_id' => $product->id, 'quantity' => 3]);
        $channel = CommerceChannel::factory()->create(['tenant_id' => $tenant->id, 'location_id' => $location->id, 'user_id' => $actor->id, 'name' => 'Local web store']);

        $this->actingAs($actor)->postJson('/api/commerce/channels/'.$channel->id.'/orders', [
            'external_reference' => 'WEB-REPORT-1', 'idempotency_key' => 'channel-report-order', 'items' => [['sku' => $product->sku, 'quantity' => 2]],
        ])->assertCreated();
        $order = ChannelOrder::where('commerce_channel_id', $channel->id)->firstOrFail();
        $this->getJson('/api/bootstrap')->assertOk()->assertJsonPath('stats.reserved_units', 2)->assertJsonPath('stats.available_units', 1)->assertJsonPath('stats.revenue_total', 0);

        $this->postJson('/api/commerce/orders/'.$order->id.'/import')->assertOk();
        $sale = Sale::findOrFail($order->refresh()->sale_id);
        $this->getJson('/api/bootstrap')->assertOk()->assertJsonPath('sales.0.source', 'channel')
            ->assertJsonPath('sales.0.simulated', true)->assertJsonPath('sales.0.payments.0.simulated', true)
            ->assertJsonPath('sales.0.channel_name', 'Local web store')->assertJsonPath('sales.0.external_reference', 'WEB-REPORT-1')
            ->assertJsonPath('stats.reserved_units', 0)->assertJsonPath('stats.revenue_total', 1008);

        $this->postJson('/api/sales/'.$sale->id.'/return', ['items' => [['product_id' => $product->id, 'quantity' => 1]], 'reason' => 'One sellable item came back', 'idempotency_key' => 'channel-report-return'])->assertOk()
            ->assertJsonPath('source', 'channel')->assertJsonPath('channel_name', 'Local web store')->assertJsonPath('external_reference', 'WEB-REPORT-1')->assertJsonPath('net_total', 504);
        $this->getJson('/api/bootstrap')->assertOk()->assertJsonPath('stats.revenue_total', 504)->assertJsonPath('sales.0.status', 'partially_returned');
        $this->assertDatabaseHas('sales', ['id' => $sale->id, 'source' => 'channel', 'channel_id' => $channel->id, 'returned_cents' => 50400]);
        $this->assertDatabaseHas('activity_logs', ['tenant_id' => $tenant->id, 'actor_id' => $actor->id, 'action' => 'channel.order_reserved', 'resource_id' => $order->id]);
        $this->getJson('/api/activity?action=channel.order_imported')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.resource_id', $order->id);
    }

    public function test_sales_csv_includes_channel_origin_and_escapes_formula_text_with_tenant_isolation(): void
    {
        $channel = CommerceChannel::factory()->create(['name' => '=SUM(1,1)']);
        $actor = User::factory()->create(['tenant_id' => $channel->tenant_id, 'role' => 'admin']);
        $sale = Sale::factory()->create(['tenant_id' => $channel->tenant_id, 'location_id' => $channel->location_id, 'user_id' => $actor->id, 'source' => 'channel', 'channel_id' => $channel->id]);
        ChannelOrder::factory()->create(['tenant_id' => $channel->tenant_id, 'commerce_channel_id' => $channel->id, 'location_id' => $channel->location_id, 'user_id' => $actor->id, 'sale_id' => $sale->id, 'status' => 'imported', 'external_reference' => '@external-order']);
        $foreign = Sale::factory()->create();

        $response = $this->actingAs($actor)->get('/api/reports/export')->assertOk();
        $csv = $response->streamedContent();
        $rows = array_map(fn (string $line): array => str_getcsv($line, ',', '"', ''), explode("\n", trim($csv)));
        $this->assertCount(2, $rows);
        $this->assertSame(['Source', 'Channel', 'External Order', 'Simulated Integration'], array_slice($rows[0], 11));
        $this->assertSame(['channel', "'=SUM(1,1)", "'@external-order", 'yes'], array_slice($rows[1], 11));
        $this->assertStringNotContainsString($foreign->number, $csv);
    }

    public function test_cashier_cannot_open_the_channel_management_page_or_read_channel_orders(): void
    {
        $actor = User::factory()->create(['tenant_id' => Tenant::factory(), 'role' => 'cashier']);

        $this->actingAs($actor)->get('/app/channels')->assertForbidden();
        $this->getJson('/api/commerce')->assertForbidden();
    }

    public function test_direct_receipt_lookup_requires_authentication_and_respects_tenant_and_cashier_scope(): void
    {
        $tenant = Tenant::factory()->create();
        $cashier = User::factory()->create(['tenant_id' => $tenant->id, 'role' => 'cashier']);
        $manager = User::factory()->create(['tenant_id' => $tenant->id, 'role' => 'manager']);
        $own = Sale::factory()->create(['tenant_id' => $tenant->id, 'user_id' => $cashier->id]);
        $teamSale = Sale::factory()->create(['tenant_id' => $tenant->id, 'user_id' => $manager->id]);
        $foreign = Sale::factory()->create();

        $this->getJson('/api/sales/'.$own->id)->assertUnauthorized();
        $this->actingAs($cashier)->getJson('/api/sales/'.$own->id)->assertOk()->assertJsonPath('number', $own->number);
        $this->getJson('/api/sales/'.$teamSale->id)->assertNotFound();
        $this->getJson('/api/sales/'.$foreign->id)->assertNotFound();
        $this->actingAs($manager)->getJson('/api/sales/'.$teamSale->id)->assertOk()->assertJsonPath('number', $teamSale->number);
        $this->getJson('/api/sales/'.$foreign->id)->assertNotFound();
        $this->assertDatabaseCount('activity_logs', 0);
    }
}
