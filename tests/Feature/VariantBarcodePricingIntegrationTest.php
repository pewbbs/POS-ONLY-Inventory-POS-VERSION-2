<?php

namespace Tests\Feature;

use App\Jobs\CheckLowStock;
use App\Models\BillingInvoice;
use App\Models\BillingPaymentAttempt;
use App\Models\Location;
use App\Models\PayMongoWebhookEvent;
use App\Models\Product;
use App\Models\Stock;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class VariantBarcodePricingIntegrationTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['paymongo.enabled' => false, 'paymongo.secret_key' => '', 'paymongo.webhook_secret' => '',
            'mail.default' => 'array', 'billing.simulations_enabled' => false]);
    }

    /** @return array{tenant: Tenant, manager: User, cashier: User, location: Location, branch: Location} */
    private function store(): array
    {
        $tenant = Tenant::factory()->create(['settings' => Tenant::defaultSettings()]);
        $manager = User::factory()->create(['tenant_id' => $tenant->id, 'role' => 'manager']);
        $cashier = User::factory()->create(['tenant_id' => $tenant->id, 'role' => 'cashier']);
        $location = Location::factory()->create(['tenant_id' => $tenant->id]);
        $branch = Location::factory()->create(['tenant_id' => $tenant->id]);
        $subscription = Subscription::factory()->create(['tenant_id' => $tenant->id]);
        $invoice = BillingInvoice::factory()->create(['tenant_id' => $tenant->id, 'subscription_id' => $subscription->id,
            'status' => 'paid', 'paid_at' => now()]);
        BillingPaymentAttempt::factory()->create(['tenant_id' => $tenant->id, 'billing_invoice_id' => $invoice->id,
            'active_scope' => null, 'status' => 'paid', 'confirmed_at' => now()]);
        PayMongoWebhookEvent::factory()->create(['status' => 'processed', 'processed_at' => now()]);

        return compact('tenant', 'manager', 'cashier', 'location', 'branch');
    }

    /** @return array<string, array<int, array<string, mixed>>> */
    private function protectedRecords(): array
    {
        $records = [];
        foreach (['tenants', 'users', 'subscriptions', 'billing_invoices', 'billing_payment_attempts', 'paymongo_webhook_events'] as $table) {
            $records[$table] = DB::table($table)->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all();
        }

        return $records;
    }

    /** @param array{manager: User, location: Location} $store
     * @return array<string, array<string, mixed>>
     */
    private function createVariants(array $store): array
    {
        $products = $this->actingAs($store['manager'])->postJson('/api/product-families', [
            'name' => 'Cotton shirt', 'option_names' => ['size', 'color'], 'category' => 'Clothing',
            'price' => '200.99', 'cost' => '80.25', 'wholesale_price' => '101.01', 'reorder_level' => 0,
            'location_id' => $store['location']->id, 'idempotency_key' => 'decimal-variant-family',
            'variants' => [
                ['options' => ['size' => 'S', 'color' => 'Blue'], 'sku' => 'SHIRT-S-BLUE', 'barcode' => '0012345678901', 'quantity' => 10],
                ['options' => ['size' => 'M', 'color' => 'Blue'], 'sku' => 'SHIRT-M-BLUE', 'barcode' => '0', 'quantity' => 7,
                    'price' => '250.75', 'wholesale_price' => '159.92'],
                ['options' => ['size' => 'L', 'color' => 'Blue'], 'sku' => 'SHIRT-L-BLUE', 'barcode' => '0012345678903', 'quantity' => 4,
                    'wholesale_price' => 0],
            ],
        ])->assertCreated()->json('products');

        return collect($products)->keyBy('sku')->all();
    }

    private function assertStock(array $product, Location $location, int $quantity, int $reserved = 0): void
    {
        $this->assertDatabaseHas('stocks', ['product_id' => $product['id'], 'location_id' => $location->id,
            'quantity' => $quantity, 'reserved_quantity' => $reserved]);
    }

    public function test_scanner_catalog_wholesale_rounding_and_successive_refunds_keep_original_receipt_totals_after_edits(): void
    {
        $this->freezeTime();
        $store = $this->store();
        $foreign = $this->store();
        Http::preventStrayRequests();
        Mail::fake();
        Queue::fake([CheckLowStock::class]);
        $variants = $this->createVariants($store);
        $small = $variants['SHIRT-S-BLUE'];
        $medium = $variants['SHIRT-M-BLUE'];
        $free = $variants['SHIRT-L-BLUE'];
        $protected = $this->protectedRecords();
        $catalog = $this->actingAs($store['cashier'])->getJson('/api/bootstrap')->assertOk()
            ->assertJsonCount(3, 'products')->assertJsonCount(0, 'product_families')->json('products');
        $catalog = collect($catalog)->keyBy('sku');
        $this->assertSame('0012345678901', $catalog['SHIRT-S-BLUE']['barcode']);
        $this->assertSame('0', $catalog['SHIRT-M-BLUE']['barcode']);
        $this->assertSame(101.01, $catalog['SHIRT-S-BLUE']['wholesale_price']);
        $this->assertSame(0, $catalog['SHIRT-L-BLUE']['wholesale_price']);
        $this->assertSame(0, $catalog['SHIRT-S-BLUE']['cost']);
        $checkout = ['location_id' => $store['location']->id, 'price_tier' => 'wholesale',
            'items' => [['product_id' => $small['id'], 'quantity' => 3, 'price' => 0],
                ['product_id' => $medium['id'], 'quantity' => 2, 'price' => 0], ['product_id' => $free['id'], 'quantity' => 1]],
            'discount_percent' => '7.25', 'tax_percent' => 12, 'payments' => [['method' => 'cash', 'amount' => 700]],
            'idempotency_key' => 'decimal-wholesale-checkout'];

        $saleId = $this->postJson('/api/sales', $checkout)->assertCreated()->assertJsonPath('subtotal', 622.87)
            ->assertJsonPath('discount', 45.16)->assertJsonPath('tax', 69.33)->assertJsonPath('total', 647.04)
            ->assertJsonPath('change', 52.96)->assertJsonPath('items.0.unit_price', 101.01)
            ->assertJsonPath('items.1.unit_price', 159.92)->assertJsonPath('items.2.unit_price', 0)->json('id');
        $this->assertStock($small, $store['location'], 7);
        $this->assertStock($medium, $store['location'], 5);
        $this->assertStock($free, $store['location'], 3);
        foreach ([$small, $medium, $free] as $product) {
            $this->assertStock($product, $store['branch'], 0);
        }
        $edit = ['name' => 'Cotton shirt', 'sku' => 'SHIRT-S-GREEN', 'barcode' => '0098765432101', 'category' => 'Clothing',
            'price' => 999, 'cost' => 999, 'wholesale_price' => 888, 'reorder_level' => 0,
            'variant_options' => ['size' => 'S', 'color' => 'Green']];
        $this->actingAs($store['manager'])->putJson('/api/products/'.$small['id'], $edit)->assertOk()
            ->assertJsonPath('name', 'Cotton shirt · S / Green');
        $this->actingAs($store['cashier'])->postJson('/api/sales', $checkout)->assertCreated()
            ->assertJsonPath('id', $saleId)->assertJsonPath('items.0.name', 'Cotton shirt · S / Blue')
            ->assertJsonPath('items.0.sku', 'SHIRT-S-BLUE')->assertJsonPath('items.0.unit_price', 101.01)->assertJsonPath('total', 647.04);
        $this->postJson('/api/sales/'.$saleId.'/return', [])->assertForbidden();
        $this->actingAs($foreign['manager'])->postJson('/api/sales/'.$saleId.'/return', [])->assertNotFound();
        $partial = ['items' => [['product_id' => $small['id'], 'quantity' => 1]],
            'reason' => 'One original blue shirt returned', 'idempotency_key' => 'decimal-partial-refund'];
        $this->actingAs($store['manager'])->postJson('/api/sales/'.$saleId.'/return', $partial)->assertOk()
            ->assertJsonPath('returned_amount', 104.93)->assertJsonPath('net_total', 542.11)
            ->assertJsonPath('returns.0.items.0.sku', 'SHIRT-S-BLUE');
        $this->postJson('/api/sales/'.$saleId.'/return', $partial)->assertOk()->assertJsonCount(1, 'returns');
        $remaining = ['items' => [['product_id' => $small['id'], 'quantity' => 2],
            ['product_id' => $medium['id'], 'quantity' => 2], ['product_id' => $free['id'], 'quantity' => 1]],
            'reason' => 'Remaining original items returned', 'idempotency_key' => 'decimal-final-refund'];
        $this->postJson('/api/sales/'.$saleId.'/return', $remaining)->assertOk()->assertJsonPath('status', 'returned')
            ->assertJsonPath('returned_amount', 647.04)->assertJsonPath('net_total', 0)->assertJsonCount(2, 'returns');
        $this->postJson('/api/sales/'.$saleId.'/return', $remaining)->assertOk()->assertJsonCount(2, 'returns');
        $this->actingAs($store['cashier'])->getJson('/api/sales/'.$saleId)->assertOk()
            ->assertJsonPath('price_tier', 'wholesale')->assertJsonPath('total', 647.04)
            ->assertJsonPath('items.0.unit_price', 101.01)->assertJsonPath('items.2.total', 0);

        $this->assertStock($small, $store['location'], 10);
        $this->assertStock($medium, $store['location'], 7);
        $this->assertStock($free, $store['location'], 4);
        $this->assertDatabaseCount('sales', 1);
        $this->assertDatabaseCount('sale_returns', 2);
        $this->assertDatabaseCount('stock_movements', 10);
        $this->assertSame($protected, $this->protectedRecords());
        Queue::assertPushed(CheckLowStock::class, fn (CheckLowStock $job): bool => $job->tenantId === $store['tenant']->id);
        Http::assertNothingSent();
        Mail::assertNothingSent();
    }

    public function test_barcode_namespace_and_available_variant_stock_stay_isolated_with_reservations_and_identical_foreign_codes(): void
    {
        $this->freezeTime();
        $store = $this->store();
        $foreign = $this->store();
        Http::preventStrayRequests();
        Mail::fake();
        Queue::fake([CheckLowStock::class]);
        $variants = $this->createVariants($store);
        $small = $variants['SHIRT-S-BLUE'];
        $medium = $variants['SHIRT-M-BLUE'];
        $foreignProduct = Product::factory()->create(['tenant_id' => $foreign['tenant']->id,
            'sku' => 'SHIRT-S-BLUE', 'barcode' => '0012345678901', 'wholesale_price_cents' => 1]);
        $foreignStock = Stock::factory()->create(['tenant_id' => $foreign['tenant']->id,
            'product_id' => $foreignProduct->id, 'location_id' => $foreign['location']->id, 'quantity' => 99]);
        Stock::where('product_id', $small['id'])->where('location_id', $store['location']->id)->update(['reserved_quantity' => 7]);
        $protected = $this->protectedRecords();
        $catalog = $this->actingAs($store['cashier'])->getJson('/api/bootstrap')->assertOk()->assertJsonCount(3, 'products')->json('products');
        $scannerProduct = collect($catalog)->firstWhere('barcode', '0012345678901');
        $this->assertSame($small['id'], $scannerProduct['id']);
        $this->assertSame(3, collect($scannerProduct['stocks'])->firstWhere('location_id', $store['location']->id)['available_quantity']);
        $checkout = ['location_id' => $store['location']->id, 'price_tier' => 'wholesale',
            'items' => [['product_id' => $small['id'], 'quantity' => 4], ['product_id' => $medium['id'], 'quantity' => 1]],
            'payments' => [['method' => 'cash', 'amount' => 1000]], 'idempotency_key' => 'reserved-variant-checkout'];

        $this->postJson('/api/sales', $checkout)->assertUnprocessable()->assertJsonValidationErrors('items');
        $this->assertDatabaseCount('sales', 0);
        $this->assertStock($small, $store['location'], 10, 7);
        $this->assertStock($medium, $store['location'], 7);
        $checkout['items'][0]['quantity'] = 3;
        $saleId = $this->postJson('/api/sales', $checkout)->assertCreated()->assertJsonPath('total', 518.5)->json('id');
        $this->postJson('/api/sales', $checkout)->assertCreated()->assertJsonPath('id', $saleId);
        $this->assertStock($small, $store['location'], 7, 7);
        $this->assertStock($medium, $store['location'], 6);
        $this->actingAs($store['manager'])->postJson('/api/sales/'.$saleId.'/return', [
            'items' => [['product_id' => $small['id'], 'quantity' => 1]], 'reason' => 'Release one returned variant',
            'idempotency_key' => 'reserved-variant-return',
        ])->assertOk()->assertJsonPath('returned_amount', 113.13);
        $catalog = $this->actingAs($store['cashier'])->getJson('/api/bootstrap')->assertOk()->json('products');
        $scannerProduct = collect($catalog)->firstWhere('barcode', '0012345678901');
        $this->assertSame(1, collect($scannerProduct['stocks'])->firstWhere('location_id', $store['location']->id)['available_quantity']);

        $this->assertStock($small, $store['location'], 8, 7);
        $this->assertStock($medium, $store['location'], 6);
        $this->assertStock($small, $store['branch'], 0);
        $this->assertDatabaseHas('stocks', ['id' => $foreignStock->id, 'quantity' => 99, 'reserved_quantity' => 0]);
        $this->assertDatabaseHas('products', ['id' => $foreignProduct->id, 'sku' => 'SHIRT-S-BLUE', 'barcode' => '0012345678901', 'wholesale_price_cents' => 1]);
        $this->assertDatabaseCount('sales', 1);
        $this->assertDatabaseCount('sale_returns', 1);
        $this->assertDatabaseCount('stock_movements', 6);
        $this->assertSame($protected, $this->protectedRecords());
        Queue::assertPushed(CheckLowStock::class, fn (CheckLowStock $job): bool => $job->tenantId === $store['tenant']->id);
        Http::assertNothingSent();
        Mail::assertNothingSent();
    }
}
