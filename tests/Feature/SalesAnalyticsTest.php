<?php

namespace Tests\Feature;

use App\Models\Location;
use App\Models\Product;
use App\Models\ProductFamily;
use App\Models\Sale;
use App\Models\Stock;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class SalesAnalyticsTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function store(): array
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'role' => 'manager']);
        $location = Location::factory()->create(['tenant_id' => $tenant->id]);

        return compact('tenant', 'user', 'location');
    }

    private function item(Product $product, int $quantity, int $price, array $overrides = []): array
    {
        return array_replace([
            'product_id' => $product->id, 'name' => $product->name, 'sku' => $product->sku,
            'quantity' => $quantity, 'unit_price_cents' => $price, 'line_total_cents' => $quantity * $price,
        ], $overrides);
    }

    private function receipt(array $store, array $items, array $overrides = []): Sale
    {
        $subtotal = array_sum(array_column($items, 'line_total_cents'));

        return Sale::factory()->create(array_replace([
            'tenant_id' => $store['tenant']->id, 'location_id' => $store['location']->id, 'user_id' => $store['user']->id,
            'items' => $items, 'subtotal_cents' => $subtotal, 'discount_cents' => 0, 'tax_cents' => 0,
            'total_cents' => $subtotal, 'paid_cents' => $subtotal, 'change_cents' => 0, 'returned_cents' => 0,
            'created_at' => '2026-10-01 09:30:00',
        ], $overrides));
    }

    public function test_empty_history_returns_twenty_four_empty_hours_without_a_peak_or_ranking(): void
    {
        $store = $this->store();
        $this->travelTo(now()->setDate(2026, 10, 11)->setTime(12, 0));

        $response = $this->actingAs($store['user'])->getJson('/api/reports/analytics')->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')->assertJsonPath('filters.scope', 'business')
            ->assertJsonPath('filters.timezone', 'Asia/Manila')->assertJsonPath('filters.from', null)
            ->assertJsonPath('summary.transactions', 0)->assertJsonPath('summary.average_basket', 0)
            ->assertJsonCount(24, 'hourly')->assertJsonPath('peak_hours', [])->assertJsonPath('best_sellers', [])
            ->assertJsonPath('product_count', 0)->assertJsonPath('ranked_product_count', 0)
            ->assertJsonPath('generated_at', '2026-10-11T12:00:00+08:00');

        $this->assertSame(range(0, 23), array_column($response->json('hourly'), 'hour'));
        $this->assertSame([0], array_values(array_unique(array_column($response->json('hourly'), 'net_total'))));
    }

    public function test_partial_and_legacy_full_returns_preserve_discount_tax_allocations_per_sku(): void
    {
        $store = $this->store();
        $first = Product::factory()->create(['tenant_id' => $store['tenant']->id]);
        $second = Product::factory()->create(['tenant_id' => $store['tenant']->id]);
        $this->receipt($store, [
            $this->item($first, 3, 1, ['returned_quantity' => 2]),
            $this->item($second, 2, 1),
        ], ['status' => 'partially_returned', 'discount_cents' => 1, 'tax_cents' => 1, 'total_cents' => 5, 'returned_cents' => 2]);
        $this->receipt($store, [$this->item($second, 2, 1)], ['status' => 'returned', 'returned_cents' => 0, 'created_at' => '2026-10-01 10:00:00']);

        $response = $this->actingAs($store['user'])->getJson('/api/reports/analytics')->assertOk()
            ->assertJsonPath('summary.transactions', 2)->assertJsonPath('summary.gross_total', 0.07)
            ->assertJsonPath('summary.returned_total', 0.04)->assertJsonPath('summary.net_total', 0.03)
            ->assertJsonPath('summary.units_sold', 7)->assertJsonPath('summary.units_returned', 4)->assertJsonPath('summary.net_units', 3)
            ->assertJsonPath('summary.returned_transactions', 2)->assertJsonPath('summary.average_basket', 0.02)
            ->assertJsonPath('hourly.9.gross_total', 0.05)->assertJsonPath('hourly.9.returned_total', 0.02)
            ->assertJsonPath('hourly.9.net_total', 0.03)->assertJsonPath('hourly.10.net_total', 0)
            ->assertJsonPath('best_sellers.0.product_id', $second->id)->assertJsonPath('best_sellers.0.units_sold', 4)
            ->assertJsonPath('best_sellers.0.units_returned', 2)->assertJsonPath('best_sellers.0.net_total', 0.02)
            ->assertJsonPath('best_sellers.1.product_id', $first->id)->assertJsonPath('best_sellers.1.returned_total', 0.02)
            ->assertJsonPath('best_sellers.1.net_total', 0.01)->assertJsonPath('product_count', 2);

        $this->assertSame(0.03, array_sum(array_column($response->json('best_sellers'), 'net_total')));
    }

    public function test_successive_rounding_refunds_and_later_return_dates_reduce_the_original_sale_cohort(): void
    {
        $store = $this->store();
        $store['tenant']->update(['settings' => array_replace($store['tenant']->settings, ['tax_percent' => 0])]);
        $product = Product::factory()->create(['tenant_id' => $store['tenant']->id, 'price_cents' => 1]);
        Stock::create(['tenant_id' => $store['tenant']->id, 'product_id' => $product->id, 'location_id' => $store['location']->id, 'quantity' => 5]);
        $this->travelTo(now()->setDate(2026, 10, 1)->setTime(9, 30));
        $saleId = $this->actingAs($store['user'])->postJson('/api/sales', [
            'location_id' => $store['location']->id, 'items' => [['product_id' => $product->id, 'quantity' => 3]],
            'discount_percent' => 20, 'tax_percent' => 0, 'payments' => [['method' => 'cash', 'amount' => 1]], 'idempotency_key' => 'analytics-rounding-sale',
        ])->assertCreated()->json('id');
        $this->travelTo(now()->setDate(2026, 10, 11)->setTime(11, 0));
        foreach (['analytics-refund-one', 'analytics-refund-two'] as $key) {
            $this->postJson('/api/sales/'.$saleId.'/return', [
                'items' => [['product_id' => $product->id, 'quantity' => 1]], 'reason' => 'Original item returned later', 'idempotency_key' => $key,
            ])->assertOk();
        }

        $this->getJson('/api/reports/analytics?from=2026-10-01&to=2026-10-01')->assertOk()
            ->assertJsonPath('summary.gross_total', 0.02)->assertJsonPath('summary.returned_total', 0.01)
            ->assertJsonPath('summary.net_total', 0.01)->assertJsonPath('summary.units_returned', 2)
            ->assertJsonPath('best_sellers.0.units_sold', 3)->assertJsonPath('best_sellers.0.net_units', 1)
            ->assertJsonPath('best_sellers.0.returned_total', 0.01)->assertJsonPath('best_sellers.0.net_total', 0.01);
        $this->getJson('/api/reports/analytics?from=2026-10-11&to=2026-10-11')->assertOk()->assertJsonPath('summary.transactions', 0);
    }

    public function test_zero_value_full_return_counts_traffic_and_positive_zero_value_units_remain_ranked(): void
    {
        $store = $this->store();
        $returned = Product::factory()->create(['tenant_id' => $store['tenant']->id]);
        $retained = Product::factory()->create(['tenant_id' => $store['tenant']->id]);
        $this->receipt($store, [$this->item($returned, 2, 100)], ['status' => 'returned', 'total_cents' => 0, 'discount_cents' => 200]);
        $this->receipt($store, [$this->item($retained, 1, 0)]);

        $this->actingAs($store['user'])->getJson('/api/reports/analytics')->assertOk()
            ->assertJsonPath('summary.transactions', 2)->assertJsonPath('summary.returned_transactions', 1)
            ->assertJsonPath('summary.gross_total', 0)->assertJsonPath('summary.net_total', 0)
            ->assertJsonPath('summary.units_returned', 2)->assertJsonPath('summary.net_units', 1)
            ->assertJsonPath('peak_hours', [9])->assertJsonCount(1, 'best_sellers')->assertJsonPath('product_count', 2)
            ->assertJsonPath('ranked_product_count', 1)->assertJsonPath('best_sellers.0.product_id', $retained->id)
            ->assertJsonPath('best_sellers.0.net_units', 1)->assertJsonPath('best_sellers.0.net_total', 0);
    }

    public function test_all_tied_peak_hours_follow_receipt_traffic_including_fully_returned_sales(): void
    {
        $store = $this->store();
        $product = Product::factory()->create(['tenant_id' => $store['tenant']->id]);
        $this->receipt($store, [$this->item($product, 1, 100)], ['created_at' => '2026-10-01 09:00:00']);
        $this->receipt($store, [$this->item($product, 1, 100)], ['created_at' => '2026-10-02 09:59:59', 'status' => 'returned', 'returned_cents' => 100]);
        $this->receipt($store, [$this->item($product, 1, 100)], ['created_at' => '2026-10-01 17:00:00', 'source' => 'channel']);
        $this->receipt($store, [$this->item($product, 1, 100)], ['created_at' => '2026-10-02 17:20:00']);
        $this->receipt($store, [$this->item($product, 1, 1000000)], ['created_at' => '2026-10-01 23:30:00']);

        $this->actingAs($store['user'])->getJson('/api/reports/analytics')->assertOk()
            ->assertJsonPath('summary.transactions', 5)->assertJsonPath('summary.simulated_transactions', 1)
            ->assertJsonPath('peak_hours', [9, 17])->assertJsonPath('hourly.9.transactions', 2)
            ->assertJsonPath('hourly.9.returned_total', 1)->assertJsonPath('hourly.9.net_total', 1)
            ->assertJsonPath('hourly.17.transactions', 2)->assertJsonPath('hourly.23.transactions', 1);
    }

    public function test_top_ten_ranking_uses_selected_metric_then_secondary_metric_then_product_id(): void
    {
        $store = $this->store();
        $first = Product::factory()->create(['tenant_id' => $store['tenant']->id]);
        $second = Product::factory()->create(['tenant_id' => $store['tenant']->id]);
        $third = Product::factory()->create(['tenant_id' => $store['tenant']->id]);
        $tied = Product::factory()->count(11)->create(['tenant_id' => $store['tenant']->id]);
        $items = [$this->item($first, 10, 100), $this->item($second, 10, 200), $this->item($third, 20, 10)];
        foreach ($tied as $product) {
            $items[] = $this->item($product, 1, 1);
        }
        $this->receipt($store, $items);

        $units = $this->actingAs($store['user'])->getJson('/api/reports/analytics')->assertOk()
            ->assertJsonCount(10, 'best_sellers')->assertJsonPath('product_count', 14)
            ->assertJsonPath('best_sellers.0.product_id', $third->id)->assertJsonPath('best_sellers.1.product_id', $second->id)
            ->assertJsonPath('best_sellers.2.product_id', $first->id);
        $amounts = $this->getJson('/api/reports/analytics?rank_by=net_total')->assertOk()->assertJsonCount(10, 'best_sellers')
            ->assertJsonPath('best_sellers.0.product_id', $second->id)->assertJsonPath('best_sellers.1.product_id', $first->id)
            ->assertJsonPath('best_sellers.2.product_id', $third->id);

        $expectedTies = $tied->take(7)->modelKeys();
        $this->assertSame($expectedTies, array_column(array_slice($units->json('best_sellers'), 3), 'product_id'));
        $this->assertSame($expectedTies, array_column(array_slice($amounts->json('best_sellers'), 3), 'product_id'));
    }

    public function test_each_variant_keeps_its_original_amount_and_latest_matching_snapshot_label(): void
    {
        $store = $this->store();
        $family = ProductFamily::factory()->create(['tenant_id' => $store['tenant']->id, 'name' => 'Everyday tee']);
        $small = Product::factory()->create(['tenant_id' => $store['tenant']->id, 'product_family_id' => $family->id, 'variant_options' => ['size' => 'S', 'color' => 'Blue'], 'name' => 'Current catalog changed', 'sku' => 'CURRENT-SKU', 'price_cents' => 999999]);
        $medium = Product::factory()->create(['tenant_id' => $store['tenant']->id, 'product_family_id' => $family->id, 'variant_options' => ['size' => 'M', 'color' => 'Blue']]);
        $this->receipt($store, [$this->item($small, 1, 10000, ['name' => 'Everyday tee · S / Blue', 'sku' => 'TEE-S-BLUE'])], ['created_at' => '2026-10-02 12:00:00']);
        $this->receipt($store, [$this->item($small, 1, 8000, ['name' => 'Older label', 'sku' => 'OLD-SKU'])], ['created_at' => '2026-10-01 12:00:00']);
        $this->receipt($store, [$this->item($small, 1, 12000, ['name' => 'Latest quoted tee · S / Green', 'sku' => 'TEE-S-GREEN']), $this->item($medium, 2, 15000, ['name' => 'Everyday tee · M / Blue', 'sku' => 'TEE-M-BLUE'])], ['created_at' => '2026-10-02 12:00:00', 'source' => 'channel']);

        $this->actingAs($store['user'])->getJson('/api/reports/analytics')->assertOk()->assertJsonPath('product_count', 2)
            ->assertJsonPath('best_sellers.0.product_id', $small->id)->assertJsonPath('best_sellers.0.name', 'Latest quoted tee · S / Green')
            ->assertJsonPath('best_sellers.0.sku', 'TEE-S-GREEN')->assertJsonPath('best_sellers.0.gross_total', 300)
            ->assertJsonPath('best_sellers.0.net_units', 3)->assertJsonPath('best_sellers.1.product_id', $medium->id)
            ->assertJsonPath('best_sellers.1.name', 'Everyday tee · M / Blue')->assertJsonPath('best_sellers.1.net_total', 300);
        $this->getJson('/api/reports/analytics?source=pos')->assertOk()
            ->assertJsonPath('best_sellers.0.name', 'Everyday tee · S / Blue')->assertJsonPath('best_sellers.0.sku', 'TEE-S-BLUE')
            ->assertJsonPath('best_sellers.0.gross_total', 180)->assertJsonPath('summary.simulated_transactions', 0);
        $this->getJson('/api/reports/analytics?from=2026-10-01&to=2026-10-01')->assertOk()
            ->assertJsonPath('best_sellers.0.name', 'Older label')->assertJsonPath('best_sellers.0.gross_total', 80);
    }

    public function test_ranking_uses_remaining_units_and_net_amount_after_refunds_with_secondary_unit_ties(): void
    {
        $store = $this->store();
        $mostlyReturned = Product::factory()->create(['tenant_id' => $store['tenant']->id]);
        $fourUnits = Product::factory()->create(['tenant_id' => $store['tenant']->id]);
        $twoUnits = Product::factory()->create(['tenant_id' => $store['tenant']->id]);
        $fullyReturned = Product::factory()->create(['tenant_id' => $store['tenant']->id]);
        $this->receipt($store, [
            $this->item($mostlyReturned, 20, 500, ['returned_quantity' => 19]),
            $this->item($fourUnits, 4, 100),
            $this->item($twoUnits, 2, 200),
            $this->item($fullyReturned, 50, 1000, ['returned_quantity' => 50]),
        ], ['status' => 'partially_returned', 'returned_cents' => 59500]);

        $this->actingAs($store['user'])->getJson('/api/reports/analytics')->assertOk()
            ->assertJsonPath('summary.net_total', 13)->assertJsonPath('summary.net_units', 7)
            ->assertJsonPath('product_count', 4)->assertJsonPath('ranked_product_count', 3)
            ->assertJsonPath('best_sellers.0.product_id', $fourUnits->id)
            ->assertJsonPath('best_sellers.1.product_id', $twoUnits->id)
            ->assertJsonPath('best_sellers.2.product_id', $mostlyReturned->id);
        $this->getJson('/api/reports/analytics?rank_by=net_total')->assertOk()
            ->assertJsonPath('best_sellers.0.product_id', $mostlyReturned->id)->assertJsonPath('best_sellers.0.net_total', 5)
            ->assertJsonPath('best_sellers.1.product_id', $fourUnits->id)->assertJsonPath('best_sellers.1.net_total', 4)
            ->assertJsonPath('best_sellers.2.product_id', $twoUnits->id)->assertJsonPath('best_sellers.2.net_total', 4);
    }
}
