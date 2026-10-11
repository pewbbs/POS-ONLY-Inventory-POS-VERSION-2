<?php

namespace Tests\Feature;

use App\Models\Location;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleReturn;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class FinancialReportTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function store(): array
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'role' => 'admin']);
        $location = Location::factory()->create(['tenant_id' => $tenant->id]);

        return compact('tenant', 'user', 'location');
    }

    private function line(Product $product, int $quantity, int $price, array $overrides = []): array
    {
        return array_replace([
            'product_id' => $product->id, 'name' => $product->name, 'sku' => $product->sku,
            'quantity' => $quantity, 'unit_price_cents' => $price, 'line_total_cents' => $price * $quantity,
        ], $overrides);
    }

    private function sale(array $store, array $items, array $overrides = []): Sale
    {
        $subtotal = array_sum(array_column($items, 'line_total_cents'));

        return Sale::factory()->create(array_replace([
            'tenant_id' => $store['tenant']->id, 'location_id' => $store['location']->id, 'user_id' => $store['user']->id,
            'items' => $items, 'subtotal_cents' => $subtotal, 'discount_cents' => 0, 'tax_cents' => 0,
            'total_cents' => $subtotal, 'paid_cents' => $subtotal, 'payments' => [['method' => 'cash', 'amount_cents' => $subtotal]],
            'returned_cents' => 0, 'created_at' => '2026-10-01 09:00:00',
        ], $overrides));
    }

    private function credit(Sale $sale, Product $product, int $quantity, array $overrides = []): SaleReturn
    {
        $item = collect($sale->items)->firstWhere('product_id', $product->id);
        $subtotal = $item['unit_price_cents'] * $quantity;
        $data = array_replace([
            'sale_id' => $sale->id, 'tenant_id' => $sale->tenant_id, 'user_id' => $sale->user_id,
            'subtotal_cents' => $subtotal, 'discount_cents' => 0, 'tax_cents' => 0, 'total_cents' => $subtotal,
            'created_at' => '2026-10-11 10:00:00',
        ], $overrides);
        $data['items'] = [[
            'product_id' => $product->id, 'name' => $item['name'], 'sku' => $item['sku'], 'quantity' => $quantity,
            'subtotal_cents' => $data['subtotal_cents'], 'discount_cents' => $data['discount_cents'],
            'tax_cents' => $data['tax_cents'], 'total_cents' => $data['total_cents'],
        ]];

        return SaleReturn::factory()->create($data);
    }

    public function test_empty_financial_history_has_zero_components_no_grouped_rows_and_complete_zero_cost_coverage(): void
    {
        $store = $this->store();

        $this->actingAs($store['user'])->getJson('/api/reports/financial')->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')->assertJsonPath('filters.basis', 'activity')
            ->assertJsonPath('filters.scope', 'business')->assertJsonPath('summary.sales_count', 0)
            ->assertJsonPath('sales.total', 0)->assertJsonPath('refunds.total', 0)->assertJsonPath('net.total', 0)
            ->assertJsonPath('costs.estimated_gross_margin', 0)->assertJsonPath('costs.missing_sales_units', 0)
            ->assertJsonPath('daily', [])->assertJsonPath('locations', [])->assertJsonPath('sources', [])
            ->assertJsonPath('tax_rates', [])->assertJsonPath('tenders', []);
    }

    public function test_activity_uses_credit_date_and_cohort_uses_original_sale_date_with_exact_tax_discount_and_snapshot_costs(): void
    {
        $store = $this->store();
        $oldProduct = Product::factory()->create(['tenant_id' => $store['tenant']->id, 'cost_cents' => 999999]);
        $newProduct = Product::factory()->create(['tenant_id' => $store['tenant']->id]);
        $old = $this->sale($store, [$this->line($oldProduct, 3, 10000, ['unit_cost_cents' => 3000, 'returned_quantity' => 1])], [
            'status' => 'partially_returned', 'discount_cents' => 3000, 'tax_cents' => 3240, 'tax_percent' => 12,
            'total_cents' => 30240, 'returned_cents' => 10080, 'payments' => [['method' => 'cash', 'amount_cents' => 30240]],
        ]);
        $this->credit($old, $oldProduct, 1, ['discount_cents' => 1000, 'tax_cents' => 1080, 'total_cents' => 10080]);
        $this->sale($store, [$this->line($newProduct, 2, 20000, ['unit_cost_cents' => 7000])], [
            'source' => 'channel', 'created_at' => '2026-10-11 11:00:00', 'tax_percent' => 12, 'tax_cents' => 4800, 'total_cents' => 44800,
            'payments' => [['method' => 'card', 'amount_cents' => 44800, 'simulated' => true]],
        ]);

        $this->actingAs($store['user'])->getJson('/api/reports/financial?from=2026-10-11&to=2026-10-11')->assertOk()
            ->assertJsonPath('summary.sales_count', 1)->assertJsonPath('summary.refund_count', 1)->assertJsonPath('summary.simulated_sales_count', 1)
            ->assertJsonPath('sales.subtotal', 400)->assertJsonPath('sales.tax', 48)->assertJsonPath('sales.total', 448)
            ->assertJsonPath('refunds.subtotal', 100)->assertJsonPath('refunds.discount', 10)->assertJsonPath('refunds.tax', 10.8)
            ->assertJsonPath('refunds.total', 100.8)->assertJsonPath('net.subtotal', 300)->assertJsonPath('net.discount', -10)
            ->assertJsonPath('net.revenue_ex_tax', 310)->assertJsonPath('net.tax', 37.2)->assertJsonPath('net.total', 347.2)
            ->assertJsonPath('costs.sales_cost', 140)->assertJsonPath('costs.returned_cost', 30)->assertJsonPath('costs.net_cost', 110)
            ->assertJsonPath('costs.estimated_gross_margin', 200)->assertJsonPath('daily.0.date', '2026-10-11')
            ->assertJsonPath('daily.0.net_total', 347.2)->assertJsonPath('tax_rates.0.tax_percent', 12)
            ->assertJsonPath('tax_rates.0.net_revenue_ex_tax', 310)->assertJsonPath('tax_rates.0.net_tax', 37.2)
            ->assertJsonPath('sources.0.source', 'channel')->assertJsonPath('sources.0.simulated', true)
            ->assertJsonPath('sources.1.source', 'pos')->assertJsonPath('sources.1.net_total', -100.8);
        $this->getJson('/api/reports/financial?from=2026-10-01&to=2026-10-01&basis=sale_cohort')->assertOk()
            ->assertJsonPath('summary.sales_count', 1)->assertJsonPath('summary.refund_count', 1)
            ->assertJsonPath('net.revenue_ex_tax', 180)->assertJsonPath('net.tax', 21.6)->assertJsonPath('net.total', 201.6)
            ->assertJsonPath('costs.sales_cost', 90)->assertJsonPath('costs.returned_cost', 30)
            ->assertJsonPath('costs.estimated_gross_margin', 120)->assertJsonPath('daily.0.date', '2026-10-01')
            ->assertJsonPath('daily.0.refund_count', 1)->assertJsonPath('daily.0.net_total', 201.6);
    }

    public function test_refund_only_activity_can_be_negative_and_uses_original_cost_instead_of_current_catalog_cost(): void
    {
        $store = $this->store();
        $product = Product::factory()->create(['tenant_id' => $store['tenant']->id, 'cost_cents' => 90000]);
        $sale = $this->sale($store, [$this->line($product, 1, 10000, ['unit_cost_cents' => 1200])], ['status' => 'returned', 'returned_cents' => 10000, 'returned_at' => '2026-10-11 10:00:00']);
        $this->credit($sale, $product, 1);

        $this->actingAs($store['user'])->getJson('/api/reports/financial?from=2026-10-11&to=2026-10-11')->assertOk()
            ->assertJsonPath('summary.sales_count', 0)->assertJsonPath('summary.refund_count', 1)
            ->assertJsonPath('net.total', -100)->assertJsonPath('net.revenue_ex_tax', -100)
            ->assertJsonPath('costs.sales_cost', 0)->assertJsonPath('costs.returned_cost', 12)
            ->assertJsonPath('costs.net_cost', -12)->assertJsonPath('costs.estimated_gross_margin', -88)
            ->assertJsonPath('costs.snapshot_sales_units', 0)->assertJsonPath('costs.snapshot_return_units', 1)
            ->assertJsonPath('tenders', [])->assertJsonPath('coverage.unallocated_refund_total', 100);
    }

    public function test_missing_and_null_costs_remain_unknown_even_for_zero_price_while_zero_cost_is_known(): void
    {
        $store = $this->store();
        $zero = Product::factory()->create(['tenant_id' => $store['tenant']->id]);
        $null = Product::factory()->create(['tenant_id' => $store['tenant']->id, 'cost_cents' => 0]);
        $missing = Product::factory()->create(['tenant_id' => $store['tenant']->id, 'cost_cents' => 0]);
        $sale = $this->sale($store, [
            $this->line($zero, 1, 0, ['unit_cost_cents' => 0]),
            $this->line($null, 2, 10000, ['unit_cost_cents' => null, 'returned_quantity' => 1]),
            $this->line($missing, 3, 0, ['returned_quantity' => 1]),
        ], ['status' => 'partially_returned', 'returned_cents' => 10000]);
        $this->credit($sale, $null, 1);
        $this->credit($sale, $missing, 1);

        $this->actingAs($store['user'])->getJson('/api/reports/financial?basis=sale_cohort')->assertOk()
            ->assertJsonPath('summary.refund_count', 2)->assertJsonPath('summary.legacy_refund_count', 0)
            ->assertJsonPath('costs.sales_cost', 0)->assertJsonPath('costs.returned_cost', 0)
            ->assertJsonPath('costs.snapshot_sales_units', 1)->assertJsonPath('costs.missing_sales_units', 5)
            ->assertJsonPath('costs.snapshot_return_units', 0)->assertJsonPath('costs.missing_return_units', 2)
            ->assertJsonPath('costs.estimated_gross_margin', null);
    }

    public function test_dated_legacy_remainder_is_only_missing_credit_not_the_full_receipt_and_preserves_penny_allocations(): void
    {
        $store = $this->store();
        $product = Product::factory()->create(['tenant_id' => $store['tenant']->id]);
        $sale = $this->sale($store, [$this->line($product, 3, 1, ['unit_cost_cents' => 7])], [
            'status' => 'returned', 'discount_cents' => 1, 'tax_cents' => 1, 'tax_percent' => 50,
            'total_cents' => 3, 'returned_cents' => 0, 'returned_at' => '2026-10-11 15:00:00',
        ]);
        $this->credit($sale, $product, 1, ['created_at' => '2026-10-10 14:00:00']);

        $this->actingAs($store['user'])->getJson('/api/reports/financial?from=2026-10-11&to=2026-10-11')->assertOk()
            ->assertJsonPath('summary.refund_count', 1)->assertJsonPath('summary.legacy_refund_count', 1)
            ->assertJsonPath('refunds.subtotal', 0.02)->assertJsonPath('refunds.discount', 0.01)
            ->assertJsonPath('refunds.tax', 0.01)->assertJsonPath('refunds.total', 0.02)
            ->assertJsonPath('coverage.legacy_dated_refunds', 1)->assertJsonPath('coverage.legacy_dated_refund_total', 0.02)
            ->assertJsonPath('costs.returned_cost', 0.14)->assertJsonPath('costs.snapshot_return_units', 2);
        $this->getJson('/api/reports/financial?from=2026-10-10&to=2026-10-10')->assertOk()
            ->assertJsonPath('summary.refund_count', 1)->assertJsonPath('summary.legacy_refund_count', 0)
            ->assertJsonPath('refunds.total', 0.01)->assertJsonPath('costs.returned_cost', 0.07);
        $this->getJson('/api/reports/financial?basis=sale_cohort')->assertOk()
            ->assertJsonPath('summary.refund_count', 2)->assertJsonPath('refunds.total', 0.03)
            ->assertJsonPath('net.total', 0)->assertJsonPath('costs.net_cost', 0)->assertJsonPath('costs.estimated_gross_margin', 0);
        $this->assertSame(0, $sale->refresh()->returned_cents);
        $this->assertDatabaseCount('sale_returns', 1);
    }

    public function test_undated_partial_legacy_remainder_is_disclosed_but_only_included_in_original_sale_cohort(): void
    {
        $store = $this->store();
        $product = Product::factory()->create(['tenant_id' => $store['tenant']->id]);
        $sale = $this->sale($store, [$this->line($product, 3, 10000, ['unit_cost_cents' => 3000, 'returned_quantity' => 2])], ['status' => 'partially_returned', 'returned_cents' => 20000]);
        $this->credit($sale, $product, 1, ['created_at' => '2026-10-10 14:00:00']);

        $this->actingAs($store['user'])->getJson('/api/reports/financial?from=2026-10-11&to=2026-10-11')->assertOk()
            ->assertJsonPath('summary.refund_count', 0)->assertJsonPath('refunds.total', 0)
            ->assertJsonPath('coverage.undated_legacy_refunds', 1)->assertJsonPath('coverage.undated_legacy_refund_total', 100)
            ->assertJsonPath('coverage.unallocated_refund_total', 0)->assertJsonPath('costs.returned_cost', 0);
        $this->getJson('/api/reports/financial?from=2026-10-01&to=2026-10-01&basis=sale_cohort')->assertOk()
            ->assertJsonPath('summary.refund_count', 2)->assertJsonPath('summary.legacy_refund_count', 1)
            ->assertJsonPath('refunds.total', 200)->assertJsonPath('net.total', 100)
            ->assertJsonPath('costs.sales_cost', 90)->assertJsonPath('costs.returned_cost', 60)
            ->assertJsonPath('costs.estimated_gross_margin', 70)->assertJsonPath('daily.0.date', '2026-10-01')
            ->assertJsonPath('daily.0.refund_count', 2)->assertJsonPath('coverage.unallocated_refund_total', 200);
    }

    public function test_zero_value_missing_legacy_credit_counts_returned_units_and_cost_reversal_in_cohort(): void
    {
        $store = $this->store();
        $product = Product::factory()->create(['tenant_id' => $store['tenant']->id]);
        $this->sale($store, [$this->line($product, 2, 0, ['unit_cost_cents' => 7000])], ['status' => 'returned']);

        $this->actingAs($store['user'])->getJson('/api/reports/financial?basis=sale_cohort')->assertOk()
            ->assertJsonPath('summary.refund_count', 1)->assertJsonPath('summary.legacy_refund_count', 1)
            ->assertJsonPath('refunds.total', 0)->assertJsonPath('coverage.undated_legacy_refunds', 1)
            ->assertJsonPath('costs.sales_cost', 140)->assertJsonPath('costs.returned_cost', 140)
            ->assertJsonPath('costs.snapshot_return_units', 2)->assertJsonPath('costs.estimated_gross_margin', 0);
        $this->getJson('/api/reports/financial')->assertOk()
            ->assertJsonPath('summary.refund_count', 0)->assertJsonPath('coverage.undated_legacy_refunds', 1)
            ->assertJsonPath('coverage.undated_legacy_refund_total', 0)->assertJsonPath('costs.returned_cost', 0);
    }

    public function test_tender_rows_deduct_only_cash_change_count_repeated_method_once_and_leave_refunds_unallocated(): void
    {
        $store = $this->store();
        $product = Product::factory()->create(['tenant_id' => $store['tenant']->id]);
        $sale = $this->sale($store, [$this->line($product, 1, 10000, ['unit_cost_cents' => 5000])], [
            'status' => 'returned', 'returned_cents' => 10000, 'returned_at' => '2026-10-11 10:00:00', 'paid_cents' => 16000, 'change_cents' => 6000,
            'payments' => [['method' => 'cash', 'amount_cents' => 10000], ['method' => 'cash', 'amount_cents' => 2000], ['method' => 'card', 'amount_cents' => 3000], ['method' => 'wallet', 'amount_cents' => 1000]],
        ]);
        $this->credit($sale, $product, 1);
        $this->sale($store, [$this->line($product, 1, 2000, ['unit_cost_cents' => 500])], ['payments' => [['method' => 'cash', 'amount_cents' => 3000]], 'paid_cents' => 3000, 'change_cents' => 1000]);
        $this->sale($store, [$this->line($product, 1, 5000, ['unit_cost_cents' => 1200])], ['source' => 'channel', 'payments' => [['method' => 'card', 'amount_cents' => 5000, 'simulated' => true]]]);
        $this->sale($store, [$this->line($product, 1, 2000)], ['payments' => []]);

        $response = $this->actingAs($store['user'])->getJson('/api/reports/financial')->assertOk()
            ->assertJsonPath('coverage.unallocated_refund_total', 100)
            ->assertJsonPath('coverage.unreconciled_tender_sales', 1)->assertJsonPath('coverage.unreconciled_tender_total', 20)
            ->assertJsonCount(4, 'tenders');
        $tenders = collect($response->json('tenders'))->keyBy(fn (array $row): string => $row['method'].'|'.$row['source']);
        $this->assertSame(['method' => 'cash', 'source' => 'pos', 'simulated' => false, 'transactions' => 2, 'tendered' => 150, 'change' => 70, 'accepted' => 80], $tenders['cash|pos']);
        $this->assertSame(30, $tenders['card|pos']['accepted']);
        $this->assertSame(0, $tenders['card|pos']['change']);
        $this->assertSame(50, $tenders['card|channel']['accepted']);
        $this->assertTrue($tenders['card|channel']['simulated']);
        $this->assertSame(10, $tenders['wallet|pos']['accepted']);
    }
}
