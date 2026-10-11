<?php

namespace Tests\Feature;

use App\Models\Location;
use App\Models\Product;
use App\Models\Sale;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class SalesReportExportTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_csv_and_analytics_share_manila_dates_location_source_and_returned_receipts(): void
    {
        config(['app.timezone' => 'UTC']);
        $tenant = Tenant::factory()->create();
        $actor = User::factory()->create(['tenant_id' => $tenant->id, 'role' => 'admin']);
        $location = Location::factory()->create(['tenant_id' => $tenant->id]);
        $otherLocation = Location::factory()->create(['tenant_id' => $tenant->id]);
        $product = Product::factory()->create(['tenant_id' => $tenant->id]);
        $base = ['tenant_id' => $tenant->id, 'user_id' => $actor->id, 'location_id' => $location->id, 'source' => 'channel',
            'subtotal_cents' => 30000, 'total_cents' => 30000, 'paid_cents' => 30000,
            'items' => [['product_id' => $product->id, 'name' => 'Original tee', 'sku' => 'TEE-S-BLACK', 'quantity' => 3, 'unit_price_cents' => 10000, 'line_total_cents' => 30000, 'returned_quantity' => 1]],
            'status' => 'partially_returned', 'returned_cents' => 10000];
        $matching = Sale::factory()->create($base + ['created_at' => '2026-10-09 16:00:00']);
        $fullyReturned = Sale::factory()->create(array_replace($base, ['created_at' => '2026-10-10 15:59:59', 'status' => 'returned', 'returned_cents' => 0]));
        $excluded = [
            Sale::factory()->create($base + ['created_at' => '2026-10-09 15:59:59']),
            Sale::factory()->create($base + ['created_at' => '2026-10-10 16:00:00']),
            Sale::factory()->create(array_replace($base, ['created_at' => '2026-10-10 04:00:00', 'source' => 'pos'])),
            Sale::factory()->create(array_replace($base, ['created_at' => '2026-10-10 04:00:00', 'location_id' => $otherLocation->id])),
            Sale::factory()->create(['created_at' => '2026-10-10 04:00:00', 'source' => 'channel']),
        ];
        $filters = http_build_query(['from' => '2026-10-10', 'to' => '2026-10-10', 'source' => 'channel', 'location_id' => $location->id]);

        $response = $this->actingAs($actor)->get('/api/reports/export?'.$filters)->assertOk();

        $csv = $response->streamedContent();
        $rows = array_map(fn (string $line): array => str_getcsv($line, ',', '"', ''), explode("\n", trim($csv)));
        $this->assertCount(3, $rows);
        $this->assertEqualsCanonicalizing([$matching->number, $fullyReturned->number], array_column(array_slice($rows, 1), 0));
        $this->assertSame('0', $rows[1][9]);
        $this->assertSame('200', $rows[2][9]);
        $this->assertSame('2026-10-10T23:59:59+08:00', $rows[1][1]);
        $this->assertSame('2026-10-10T00:00:00+08:00', $rows[2][1]);
        foreach ($excluded as $sale) {
            $this->assertStringNotContainsString($sale->number, $csv);
        }
        $this->getJson('/api/reports/analytics?'.$filters)->assertOk()
            ->assertJsonPath('summary.transactions', 2)->assertJsonPath('summary.net_total', 200)
            ->assertJsonPath('summary.net_units', 2)->assertJsonPath('summary.returned_transactions', 2)
            ->assertJsonPath('hourly.0.transactions', 1)->assertJsonPath('hourly.23.transactions', 1);
        $this->assertDatabaseCount('activity_logs', 0);
    }

    public function test_csv_rejects_an_incomplete_date_range_with_422_instead_of_exporting_all_records(): void
    {
        $actor = User::factory()->create(['tenant_id' => Tenant::factory(), 'role' => 'admin']);

        $this->actingAs($actor)->getJson('/api/reports/export?from=2026-10-10')->assertUnprocessable()->assertJsonValidationErrors('to');
    }
}
