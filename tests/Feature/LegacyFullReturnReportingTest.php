<?php

namespace Tests\Feature;

use App\Models\Location;
use App\Models\Product;
use App\Models\Sale;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class LegacyFullReturnReportingTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function receipt(string $status, int $returnedCents = 0, int $returnedQuantity = 0): array
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'role' => 'manager']);
        $location = Location::factory()->create(['tenant_id' => $tenant->id]);
        $product = Product::factory()->create(['tenant_id' => $tenant->id, 'price_cents' => 45000]);
        $sale = Sale::factory()->create([
            'tenant_id' => $tenant->id, 'user_id' => $user->id, 'location_id' => $location->id,
            'status' => $status, 'returned_cents' => $returnedCents,
            'subtotal_cents' => 90000, 'discount_cents' => 0, 'tax_cents' => 10800, 'tax_percent' => 12,
            'total_cents' => 100800, 'paid_cents' => 100800,
            'items' => [[
                'product_id' => $product->id, 'name' => $product->name, 'sku' => $product->sku,
                'quantity' => 2, 'unit_price_cents' => 45000, 'line_total_cents' => 90000,
                'returned_quantity' => $returnedQuantity,
            ]],
        ]);

        return compact('user', 'sale');
    }

    public function test_legacy_full_return_is_zero_net_in_receipt_and_bootstrap_without_rewriting_history(): void
    {
        $store = $this->receipt('returned');

        $this->actingAs($store['user'])->getJson('/api/sales/'.$store['sale']->id)->assertOk()
            ->assertJsonPath('status', 'returned')->assertJsonPath('total', 1008)
            ->assertJsonPath('returned_amount', 1008)->assertJsonPath('net_total', 0)
            ->assertJsonPath('items.0.returned_quantity', 2)->assertJsonPath('items.0.returned_amount', 1008);
        $this->getJson('/api/bootstrap')->assertOk()
            ->assertJsonPath('sales.0.id', $store['sale']->id)->assertJsonPath('sales.0.returned_amount', 1008)
            ->assertJsonPath('sales.0.net_total', 0)->assertJsonPath('stats.revenue_total', 0);

        $this->assertSame(0, $store['sale']->refresh()->returned_cents);
        $this->assertSame(0, $store['sale']->items[0]['returned_quantity']);
        $this->assertDatabaseCount('sale_returns', 0);
    }

    #[TestWith(['completed', 0, 0, 0, 1008])]
    #[TestWith(['partially_returned', 50400, 1, 504, 504])]
    public function test_completed_and_partial_receipts_preserve_recorded_return_and_net_values(string $status, int $returnedCents, int $returnedQuantity, int $returnedAmount, int $netTotal): void
    {
        $store = $this->receipt($status, $returnedCents, $returnedQuantity);

        $this->actingAs($store['user'])->getJson('/api/sales/'.$store['sale']->id)->assertOk()
            ->assertJsonPath('status', $status)->assertJsonPath('returned_amount', $returnedAmount)
            ->assertJsonPath('net_total', $netTotal)->assertJsonPath('items.0.returned_quantity', $returnedQuantity)
            ->assertJsonPath('items.0.returned_amount', $returnedAmount);
        $this->getJson('/api/bootstrap')->assertOk()
            ->assertJsonPath('sales.0.returned_amount', $returnedAmount)->assertJsonPath('sales.0.net_total', $netTotal)
            ->assertJsonPath('stats.revenue_total', $netTotal);

        $this->assertSame($returnedCents, $store['sale']->refresh()->returned_cents);
        $this->assertDatabaseCount('sale_returns', 0);
    }
}
