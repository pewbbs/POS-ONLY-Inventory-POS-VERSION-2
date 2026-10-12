<?php

namespace Tests\Feature;

use App\Jobs\CheckLowStock;
use App\Models\BillingInvoice;
use App\Models\Location;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleReturn;
use App\Models\Stock;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class FinancialReportEndpointTest extends TestCase
{
    use LazilyRefreshDatabase;

    /** @return array{tenant: Tenant, user: User, location: Location, product: Product} */
    private function store(string $role = 'admin'): array
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'role' => $role]);
        $location = Location::factory()->create(['tenant_id' => $tenant->id]);
        $product = Product::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Financial item', 'sku' => 'FINANCIAL-SKU']);

        return compact('tenant', 'user', 'location', 'product');
    }

    /** @param array{tenant: Tenant, user: User, location: Location, product: Product} $store */
    private function saleAttributes(array $store, int $quantity = 1, string $at = '2026-10-10 09:15:00', array $extra = []): array
    {
        return array_replace([
            'tenant_id' => $store['tenant']->id, 'user_id' => $store['user']->id, 'location_id' => $store['location']->id,
            'created_at' => $at, 'source' => 'pos', 'subtotal_cents' => $quantity * 1000,
            'discount_cents' => 0, 'tax_cents' => 0, 'total_cents' => $quantity * 1000,
            'paid_cents' => $quantity * 1000, 'change_cents' => 0, 'returned_cents' => 0,
            'payments' => [['method' => 'cash', 'amount_cents' => $quantity * 1000]],
            'items' => [[
                'product_id' => $store['product']->id, 'name' => $store['product']->name, 'sku' => $store['product']->sku,
                'quantity' => $quantity, 'unit_price_cents' => 1000, 'unit_cost_cents' => 400,
                'line_total_cents' => $quantity * 1000, 'discount_cents' => 0, 'tax_cents' => 0,
                'total_cents' => $quantity * 1000, 'returned_quantity' => 0,
            ]],
        ], $extra);
    }

    private function recordedReturn(Sale $sale, string $at): SaleReturn
    {
        $item = $sale->items[0];

        return SaleReturn::factory()->create([
            'tenant_id' => $sale->tenant_id, 'sale_id' => $sale->id, 'user_id' => $sale->user_id, 'created_at' => $at,
            'subtotal_cents' => 1000, 'discount_cents' => 0, 'tax_cents' => 0, 'total_cents' => 1000,
            'items' => [[
                'product_id' => $item['product_id'], 'name' => $item['name'], 'sku' => $item['sku'], 'quantity' => 1,
                'subtotal_cents' => 1000, 'discount_cents' => 0, 'tax_cents' => 0, 'total_cents' => 1000, 'unit_cost_cents' => 400,
            ]],
        ]);
    }

    public function test_guests_cannot_read_or_export_financial_reports(): void
    {
        $this->getJson('/api/reports/financial')->assertUnauthorized();
        $this->getJson('/api/reports/financial/export')->assertUnauthorized();
    }

    public function test_cashiers_cannot_read_or_export_finance_but_retain_their_own_sales_analytics(): void
    {
        $store = $this->store('cashier');
        Sale::factory()->create($this->saleAttributes($store));

        $this->actingAs($store['user'])->getJson('/api/reports/financial')->assertForbidden();
        $this->getJson('/api/reports/financial/export')->assertForbidden();
        $this->getJson('/api/reports/analytics')->assertOk()
            ->assertJsonPath('filters.scope', 'own_sales')->assertJsonPath('summary.transactions', 1);
    }

    public function test_paused_administrators_cannot_read_or_export_financial_reports(): void
    {
        $store = $this->store();
        $store['user']->update(['is_active' => false]);

        $this->actingAs($store['user'])->getJson('/api/reports/financial')->assertForbidden();
        $this->actingAs($store['user'])->getJson('/api/reports/financial/export')->assertForbidden();
    }

    #[TestWith(['admin'])]
    #[TestWith(['manager'])]
    public function test_financial_reports_include_the_team_but_cannot_expand_to_an_injected_tenant(string $role): void
    {
        $store = $this->store($role);
        $teammate = User::factory()->create(['tenant_id' => $store['tenant']->id, 'role' => 'cashier']);
        Sale::factory()->create($this->saleAttributes($store));
        Sale::factory()->create($this->saleAttributes($store, 2, extra: ['user_id' => $teammate->id]));
        $foreign = $this->store();
        Sale::factory()->create($this->saleAttributes($foreign, 50));
        $query = http_build_query(['tenant_id' => $foreign['tenant']->id, 'user_id' => $teammate->id, 'scope' => 'all_tenants']);

        $response = $this->actingAs($store['user'])->getJson('/api/reports/financial?'.$query)->assertOk()
            ->assertJsonPath('filters.scope', 'business')->assertJsonPath('filters.basis', 'activity')
            ->assertJsonPath('filters.from', null)->assertJsonPath('filters.to', null)->assertJsonPath('filters.location_id', null)
            ->assertJsonPath('summary.sales_count', 2)->assertJsonPath('sales.total', 30)->assertJsonPath('net.total', 30);

        $this->assertArrayNotHasKey('billing', $response->json());
        $this->assertArrayNotHasKey('invoices', $response->json());
        $this->assertDatabaseCount('activity_logs', 0);
    }

    public function test_managers_may_read_finance_but_only_administrators_may_export_it(): void
    {
        $store = $this->store('manager');

        $this->actingAs($store['user'])->getJson('/api/reports/financial')->assertOk();
        $this->getJson('/api/reports/financial/export')->assertForbidden();
    }

    public function test_location_and_source_filters_scope_sales_and_hide_foreign_locations(): void
    {
        $store = $this->store();
        $second = Location::factory()->create(['tenant_id' => $store['tenant']->id]);
        Sale::factory()->create($this->saleAttributes($store));
        Sale::factory()->create($this->saleAttributes($store, 3, extra: ['source' => 'channel']));
        Sale::factory()->create($this->saleAttributes($store, 4, extra: ['location_id' => $second->id]));
        $foreign = Location::factory()->create();

        $this->actingAs($store['user'])->getJson('/api/reports/financial?location_id='.$foreign->id)->assertNotFound();
        $this->getJson('/api/reports/financial/export?location_id='.$foreign->id)->assertNotFound();
        $this->getJson('/api/reports/financial?location_id='.$store['location']->id.'&source=pos')->assertOk()
            ->assertJsonPath('summary.sales_count', 1)->assertJsonPath('sales.total', 10)->assertJsonPath('summary.simulated_sales_count', 0);
        $this->getJson('/api/reports/financial?source=channel')->assertOk()
            ->assertJsonPath('summary.sales_count', 1)->assertJsonPath('sales.total', 30)->assertJsonPath('summary.simulated_sales_count', 1);
        $this->getJson('/api/reports/financial?location_id='.$second->id)->assertOk()
            ->assertJsonPath('filters.location_id', $second->id)->assertJsonPath('summary.sales_count', 1)->assertJsonPath('sales.total', 40);
    }

    public function test_activity_and_cohort_filters_select_the_return_date_or_original_sale_date(): void
    {
        $store = $this->store();
        $sale = Sale::factory()->create($this->saleAttributes($store, 1, '2026-10-01 09:15:00', [
            'status' => 'returned', 'returned_cents' => 1000, 'returned_at' => '2026-10-10 11:00:00',
        ]));
        $this->recordedReturn($sale, '2026-10-10 11:00:00');

        $this->actingAs($store['user'])->getJson('/api/reports/financial?from=2026-10-10&to=2026-10-10')->assertOk()
            ->assertJsonPath('filters.basis', 'activity')->assertJsonPath('summary.sales_count', 0)
            ->assertJsonPath('summary.refund_count', 1)->assertJsonPath('refunds.total', 10)->assertJsonPath('net.total', -10);
        $this->getJson('/api/reports/financial?from=2026-10-10&to=2026-10-10&basis=sale_cohort')->assertOk()
            ->assertJsonPath('filters.basis', 'sale_cohort')->assertJsonPath('summary.sales_count', 0)
            ->assertJsonPath('summary.refund_count', 0)->assertJsonPath('net.total', 0);
        $this->getJson('/api/reports/financial?from=2026-10-01&to=2026-10-01&basis=sale_cohort')->assertOk()
            ->assertJsonPath('summary.sales_count', 1)->assertJsonPath('summary.refund_count', 1)->assertJsonPath('net.total', 0);
    }

    public function test_financial_reads_and_exports_do_not_reconcile_billing_or_mutate_existing_records(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 11)->setTime(12, 0));
        $store = $this->store();
        $subscription = Subscription::factory()->create([
            'tenant_id' => $store['tenant']->id, 'status' => 'active', 'trial_ends_at' => now()->subDays(40),
            'current_period_start' => now()->subMonth(), 'current_period_end' => now()->subDays(10),
        ]);
        $invoice = BillingInvoice::factory()->create(['tenant_id' => $store['tenant']->id, 'subscription_id' => $subscription->id]);
        $sale = Sale::factory()->create($this->saleAttributes($store));
        $beforeSubscription = $subscription->refresh()->getAttributes();
        $beforeInvoice = $invoice->refresh()->getAttributes();
        $beforeSale = $sale->refresh()->getAttributes();

        $this->actingAs($store['user'])->getJson('/api/reports/financial')->assertOk()->assertJsonPath('sales.total', 10);
        $this->getJson('/api/reports/financial/export')->assertOk()->streamedContent();

        $this->assertSame($beforeSubscription, $subscription->refresh()->getAttributes());
        $this->assertSame($beforeInvoice, $invoice->refresh()->getAttributes());
        $this->assertSame($beforeSale, $sale->refresh()->getAttributes());
        $this->assertDatabaseCount('subscriptions', 1);
        $this->assertDatabaseCount('billing_invoices', 1);
        $this->assertDatabaseCount('sales', 1);
        foreach (['sale_returns', 'stocks', 'stock_movements', 'purchase_orders', 'purchase_receipts', 'cash_sessions', 'cash_adjustments', 'activity_logs', 'jobs'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
    }

    public function test_financial_totals_and_daily_rows_include_receipts_older_than_the_latest_500(): void
    {
        $store = $this->store();
        Sale::factory()->count(500)->create($this->saleAttributes($store));
        Sale::factory()->create($this->saleAttributes($store, 501, '2026-10-01 09:15:00'));

        $this->actingAs($store['user'])->getJson('/api/reports/financial?from=2026-10-01&to=2026-10-11')->assertOk()
            ->assertJsonPath('summary.sales_count', 501)->assertJsonPath('sales.total', 10010)->assertJsonPath('net.total', 10010)
            ->assertJsonCount(2, 'daily')->assertJsonPath('daily.0.date', '2026-10-01')->assertJsonPath('daily.0.sales_total', 5010)
            ->assertJsonPath('daily.1.date', '2026-10-10')->assertJsonPath('daily.1.sales_count', 500)->assertJsonPath('daily.1.sales_total', 5000);

        $csv = $this->getJson('/api/reports/financial/export?from=2026-10-01&to=2026-10-11')->assertOk()->streamedContent();
        $sections = $this->csvSections($csv);
        $this->assertSame('501', array_column($sections['Summary'], 1, 0)['sales_count']);
        $this->assertSame('10010.00', $sections['Sales, credit notes and net'][1][4]);
        $this->assertCount(3, $sections['Daily']);
        $this->assertSame('5010.00', $sections['Daily'][1][3]);
    }

    public function test_checkout_partial_then_full_returns_reconcile_exact_cents_and_csv_without_counting_retries(): void
    {
        Http::preventStrayRequests();
        Queue::fake([CheckLowStock::class]);
        $store = $this->store();
        $store['product']->update(['price_cents' => 101, 'cost_cents' => 40]);
        $second = Product::factory()->create(['tenant_id' => $store['tenant']->id, 'price_cents' => 203, 'cost_cents' => 70]);
        foreach ([$store['product'], $second] as $product) {
            Stock::factory()->create(['tenant_id' => $store['tenant']->id, 'location_id' => $store['location']->id, 'product_id' => $product->id, 'quantity' => 10]);
        }
        $this->travelTo(now()->setDate(2026, 10, 1)->setTime(9, 30));
        $checkout = [
            'location_id' => $store['location']->id,
            'items' => [['product_id' => $store['product']->id, 'quantity' => 3], ['product_id' => $second->id, 'quantity' => 2]],
            'discount_percent' => 17, 'tax_percent' => 12,
            'payments' => [['method' => 'cash', 'amount' => 6], ['method' => 'card', 'amount' => 1]],
            'idempotency_key' => 'finance-ledger-checkout',
        ];

        $saleId = $this->actingAs($store['user'])->postJson('/api/sales', $checkout)->assertCreated()
            ->assertJsonPath('subtotal', 7.09)->assertJsonPath('discount', 1.21)->assertJsonPath('tax', 0.71)
            ->assertJsonPath('total', 6.59)->assertJsonPath('change', 0.41)->json('id');
        $this->postJson('/api/sales', $checkout)->assertCreated()->assertJsonPath('id', $saleId);
        $this->travelTo(now()->setDate(2026, 10, 11)->setTime(10, 0));
        $partial = ['items' => [['product_id' => $store['product']->id, 'quantity' => 1]], 'reason' => 'One item returned', 'idempotency_key' => 'finance-ledger-partial'];
        $this->postJson('/api/sales/'.$saleId.'/return', $partial)->assertOk()->assertJsonPath('returned_amount', 0.94)->assertJsonPath('net_total', 5.65);
        $this->postJson('/api/sales/'.$saleId.'/return', $partial)->assertOk()->assertJsonPath('returned_amount', 0.94);
        $cohort = '/api/reports/financial?from=2026-10-01&to=2026-10-01&basis=sale_cohort';
        $this->getJson($cohort)->assertOk()
            ->assertJsonPath('summary.sales_count', 1)->assertJsonPath('summary.refund_count', 1)
            ->assertJsonPath('refunds.subtotal', 1.01)->assertJsonPath('refunds.discount', 0.17)->assertJsonPath('refunds.tax', 0.1)
            ->assertJsonPath('net.total', 5.65)->assertJsonPath('net.revenue_ex_tax', 5.04)->assertJsonPath('net.tax', 0.61)
            ->assertJsonPath('costs.sales_cost', 2.6)->assertJsonPath('costs.returned_cost', 0.4)
            ->assertJsonPath('costs.net_cost', 2.2)->assertJsonPath('costs.estimated_gross_margin', 2.84)
            ->assertJsonPath('tenders.0.method', 'card')->assertJsonPath('tenders.0.accepted', 1)
            ->assertJsonPath('tenders.1.method', 'cash')->assertJsonPath('tenders.1.change', 0.41)->assertJsonPath('tenders.1.accepted', 5.59);
        $store['product']->update(['cost_cents' => 99999, 'price_cents' => 99999]);
        $second->update(['cost_cents' => 99999, 'price_cents' => 99999]);
        $this->travelTo(now()->setDate(2026, 10, 12)->setTime(10, 0));
        $full = ['reason' => 'Return all remaining items', 'idempotency_key' => 'finance-ledger-full'];
        $this->postJson('/api/sales/'.$saleId.'/return', $full)->assertOk()->assertJsonPath('status', 'returned')->assertJsonPath('returned_amount', 6.59)->assertJsonPath('net_total', 0);
        $this->postJson('/api/sales/'.$saleId.'/return', $full)->assertOk()->assertJsonPath('returned_amount', 6.59);

        $this->getJson('/api/reports/financial?from=2026-10-12&to=2026-10-12')->assertOk()
            ->assertJsonPath('summary.sales_count', 0)->assertJsonPath('summary.refund_count', 1)
            ->assertJsonPath('refunds.subtotal', 6.08)->assertJsonPath('refunds.discount', 1.04)->assertJsonPath('refunds.tax', 0.61)
            ->assertJsonPath('net.total', -5.65)->assertJsonPath('net.revenue_ex_tax', -5.04)
            ->assertJsonPath('costs.returned_cost', 2.2)->assertJsonPath('costs.estimated_gross_margin', -2.84);
        $this->getJson($cohort)->assertOk()->assertJsonPath('summary.refund_count', 2)->assertJsonPath('summary.legacy_refund_count', 0)
            ->assertJsonPath('net.subtotal', 0)->assertJsonPath('net.discount', 0)->assertJsonPath('net.tax', 0)->assertJsonPath('net.total', 0)
            ->assertJsonPath('costs.net_cost', 0)->assertJsonPath('costs.estimated_gross_margin', 0)
            ->assertJsonPath('coverage.unreconciled_tender_sales', 0);
        $sections = $this->csvSections($this->getJson('/api/reports/financial/export?from=2026-10-01&to=2026-10-01&basis=sale_cohort')->assertOk()->streamedContent());
        $this->assertSame(['sales', '7.09', '1.21', '0.71', '6.59', '5.88'], $sections['Sales, credit notes and net'][1]);
        $this->assertSame(['refunds', '7.09', '1.21', '0.71', '6.59', '5.88'], $sections['Sales, credit notes and net'][2]);
        $this->assertSame(['net', '0.00', '0.00', '0.00', '0.00', '0.00'], $sections['Sales, credit notes and net'][3]);
        $this->assertDatabaseCount('sales', 1);
        $this->assertDatabaseCount('sale_returns', 2);
        foreach ([$store['product'], $second] as $product) {
            $this->assertDatabaseHas('stocks', ['product_id' => $product->id, 'location_id' => $store['location']->id, 'quantity' => 10]);
        }
        Queue::assertPushed(CheckLowStock::class, fn (CheckLowStock $job): bool => $job->tenantId === $store['tenant']->id);
        Http::assertNothingSent();
    }

    public function test_manila_boundaries_include_local_midnight_sales_and_independent_returns_with_utc_storage(): void
    {
        config(['app.timezone' => 'UTC']);
        $store = $this->store();
        foreach (['2026-10-09 15:59:59', '2026-10-09 16:00:00', '2026-10-10 05:00:00', '2026-10-10 15:59:59', '2026-10-10 16:00:00'] as $at) {
            Sale::factory()->create($this->saleAttributes($store, at: $at));
        }
        $oldSale = Sale::factory()->create($this->saleAttributes($store, 1, '2026-10-01 00:00:00', [
            'status' => 'returned', 'returned_cents' => 1000, 'returned_at' => '2026-10-09 16:00:00',
        ]));
        $this->recordedReturn($oldSale, '2026-10-09 16:00:00');

        $this->actingAs($store['user'])->getJson('/api/reports/financial?from=2026-10-10&to=2026-10-10')->assertOk()
            ->assertJsonPath('filters.timezone', 'Asia/Manila')->assertJsonPath('summary.sales_count', 3)
            ->assertJsonPath('summary.refund_count', 1)->assertJsonPath('sales.total', 30)->assertJsonPath('refunds.total', 10)
            ->assertJsonPath('net.total', 20)->assertJsonCount(1, 'daily')->assertJsonPath('daily.0.date', '2026-10-10');
    }

    /** @return array<string, array<int, array<int, string|null>>> */
    private function csvSections(string $csv): array
    {
        $handle = fopen('php://temp', 'r+');
        fwrite($handle, $csv);
        rewind($handle);
        $sections = [];
        $section = null;
        while (($row = fgetcsv($handle, null, ',', '"', '')) !== false) {
            if ($row[0] === null) {
                continue;
            }
            if (count($row) === 1) {
                $section = $row[0];
                $sections[$section] = [];
            } elseif ($section !== null) {
                $sections[$section][] = $row;
            }
        }
        fclose($handle);

        return $sections;
    }

    #[TestWith(['activity', '10.00', '20.00', 1])]
    #[TestWith(['sale_cohort', '0.00', '30.00', 0])]
    public function test_csv_matches_applied_date_location_source_and_basis_filters_and_escapes_location_labels(string $basis, string $refundAmount, string $netAmount, int $refundCount): void
    {
        $store = $this->store();
        $label = '=HYPERLINK("https://example.invalid","Unsafe spreadsheet label")';
        $store['location']->update(['name' => $label]);
        $old = Sale::factory()->create($this->saleAttributes($store, 1, '2026-10-01 09:00:00', [
            'source' => 'channel', 'status' => 'returned', 'returned_cents' => 1000, 'returned_at' => '2026-10-10 11:00:00',
        ]));
        $this->recordedReturn($old, '2026-10-10 11:00:00');
        Sale::factory()->create($this->saleAttributes($store, 3, extra: ['source' => 'channel']));
        Sale::factory()->create($this->saleAttributes($store, 7));
        $posReturn = Sale::factory()->create($this->saleAttributes($store, 1, '2026-10-01 09:00:00', [
            'status' => 'returned', 'returned_cents' => 1000, 'returned_at' => '2026-10-10 11:00:00',
        ]));
        $this->recordedReturn($posReturn, '2026-10-10 11:00:00');
        $second = Location::factory()->create(['tenant_id' => $store['tenant']->id, 'name' => 'Excluded own location']);
        Sale::factory()->create($this->saleAttributes($store, 9, extra: ['source' => 'channel', 'location_id' => $second->id]));
        $otherLocationReturn = Sale::factory()->create($this->saleAttributes($store, 1, '2026-10-01 09:00:00', [
            'source' => 'channel', 'location_id' => $second->id, 'status' => 'returned', 'returned_cents' => 1000, 'returned_at' => '2026-10-10 11:00:00',
        ]));
        $this->recordedReturn($otherLocationReturn, '2026-10-10 11:00:00');
        $foreign = $this->store();
        Sale::factory()->create($this->saleAttributes($foreign, 50, extra: ['source' => 'channel']));
        $foreignReturn = Sale::factory()->create($this->saleAttributes($foreign, 1, '2026-10-01 09:00:00', [
            'source' => 'channel', 'status' => 'returned', 'returned_cents' => 1000, 'returned_at' => '2026-10-10 11:00:00',
        ]));
        $this->recordedReturn($foreignReturn, '2026-10-10 11:00:00');
        $query = http_build_query([
            'from' => '2026-10-10', 'to' => '2026-10-10', 'location_id' => $store['location']->id,
            'source' => 'channel', 'basis' => $basis, 'tenant_id' => $foreign['tenant']->id,
        ]);

        $this->actingAs($store['user'])->getJson('/api/reports/financial?'.$query)->assertOk()
            ->assertJsonPath('summary.sales_count', 1)->assertJsonPath('summary.refund_count', $refundCount)
            ->assertJsonPath('sales.total', 30)->assertJsonPath('refunds.total', (int) $refundAmount)->assertJsonPath('net.total', (int) $netAmount);
        $response = $this->getJson('/api/reports/financial/export?'.$query)->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $sections = $this->csvSections($response->streamedContent());

        $filters = array_column($sections['Filters'], 1, 0);
        $this->assertSame($basis, $filters['basis']);
        $this->assertSame('channel', $filters['source']);
        $this->assertSame('2026-10-10', $filters['from']);
        $this->assertSame((string) $store['location']->id, $filters['location_id']);
        $this->assertSame(['sales', '30.00', '0.00', '0.00', '30.00', '30.00'], $sections['Sales, credit notes and net'][1]);
        $this->assertSame(['refunds', $refundAmount, '0.00', '0.00', $refundAmount, $refundAmount], $sections['Sales, credit notes and net'][2]);
        $this->assertSame(['net', $netAmount, '0.00', '0.00', $netAmount, $netAmount], $sections['Sales, credit notes and net'][3]);
        $this->assertCount(2, $sections['Locations']);
        $this->assertSame("'".$label, $sections['Locations'][1][1]);
        $this->assertCount(2, $sections['Sources']);
        $this->assertSame('channel', $sections['Sources'][1][0]);
        $this->assertSame('yes', $sections['Sources'][1][1]);
        $this->assertDatabaseCount('activity_logs', 0);
    }

    public static function invalidFilters(): array
    {
        return [
            'missing end' => [['from' => '2026-10-01'], 'to'],
            'missing start' => [['to' => '2026-10-11'], 'from'],
            'invalid calendar date' => [['from' => '2026-02-30', 'to' => '2026-03-01'], 'from'],
            'reversed range' => [['from' => '2026-10-12', 'to' => '2026-10-11'], 'from'],
            'date array' => [['from' => ['2026-10-01'], 'to' => '2026-10-11'], 'from'],
            'unsupported source' => [['source' => 'wallet'], 'source'],
            'source array' => [['source' => ['pos']], 'source'],
            'unsupported basis' => [['basis' => 'profit'], 'basis'],
            'basis injection' => [['basis' => 'activity; DROP TABLE sales'], 'basis'],
            'location injection' => [['location_id' => '1 OR 1=1'], 'location_id'],
        ];
    }

    #[DataProvider('invalidFilters')]
    public function test_invalid_financial_filters_return_422_for_json_and_export_without_mutations(array $filters, string $field): void
    {
        $store = $this->store();
        $sale = Sale::factory()->create($this->saleAttributes($store));
        $query = http_build_query($filters);

        $this->actingAs($store['user'])->getJson('/api/reports/financial?'.$query)->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->getJson('/api/reports/financial/export?'.$query)->assertUnprocessable()->assertJsonValidationErrors($field);

        $this->assertDatabaseHas('sales', ['id' => $sale->id, 'total_cents' => 1000, 'returned_cents' => 0]);
        $this->assertDatabaseCount('sales', 1);
        $this->assertDatabaseCount('activity_logs', 0);
    }
}
