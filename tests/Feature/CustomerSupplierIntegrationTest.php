<?php

namespace Tests\Feature;

use App\Jobs\CheckLowStock;
use App\Models\BillingInvoice;
use App\Models\BillingPaymentAttempt;
use App\Models\Customer;
use App\Models\Location;
use App\Models\PayMongoWebhookEvent;
use App\Models\Product;
use App\Models\Stock;
use App\Models\Subscription;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class CustomerSupplierIntegrationTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['mail.default' => 'array', 'paymongo.enabled' => false, 'paymongo.secret_key' => '',
            'paymongo.webhook_secret' => '', 'billing.simulations_enabled' => false]);
    }

    /** @return array{tenant: Tenant, user: User, location: Location, branch: Location, product: Product, stock: Stock, branchStock: Stock} */
    private function store(): array
    {
        $tenant = Tenant::factory()->create(['settings' => array_replace(Tenant::defaultSettings(), ['tax_percent' => 12])]);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $location = Location::factory()->create(['tenant_id' => $tenant->id]);
        $branch = Location::factory()->create(['tenant_id' => $tenant->id]);
        $product = Product::factory()->create(['tenant_id' => $tenant->id, 'price_cents' => 25000, 'cost_cents' => 12345, 'reorder_level' => 0]);
        $stock = Stock::factory()->create(['tenant_id' => $tenant->id, 'location_id' => $location->id, 'product_id' => $product->id, 'quantity' => 20, 'reserved_quantity' => 0]);
        $branchStock = Stock::factory()->create(['tenant_id' => $tenant->id, 'location_id' => $branch->id, 'product_id' => $product->id, 'quantity' => 7, 'reserved_quantity' => 0]);
        $subscription = Subscription::factory()->create(['tenant_id' => $tenant->id]);
        $invoice = BillingInvoice::factory()->create(['tenant_id' => $tenant->id, 'subscription_id' => $subscription->id, 'status' => 'paid', 'paid_at' => now()]);
        BillingPaymentAttempt::factory()->create(['tenant_id' => $tenant->id, 'billing_invoice_id' => $invoice->id, 'active_scope' => null, 'status' => 'paid', 'confirmed_at' => now()]);
        PayMongoWebhookEvent::factory()->create(['status' => 'processed', 'processed_at' => now()]);

        return compact('tenant', 'user', 'location', 'branch', 'product', 'stock', 'branchStock');
    }

    /** @param array<int, string> $tables
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function records(array $tables): array
    {
        $records = [];
        foreach ($tables as $table) {
            $records[$table] = DB::table($table)->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all();
        }

        return $records;
    }

    /** @return array<int, string> */
    private function protectedTables(): array
    {
        return ['tenants', 'users', 'subscriptions', 'billing_invoices', 'billing_payment_attempts', 'paymongo_webhook_events'];
    }

    /** @param array{location: Location, product: Product} $store
     * @return array<string, mixed>
     */
    private function checkout(array $store, int $customerId, string $key): array
    {
        return ['location_id' => $store['location']->id, 'customer_id' => $customerId,
            'items' => [['product_id' => $store['product']->id, 'quantity' => 3]],
            'discount_percent' => 10, 'tax_percent' => 12, 'payments' => [['method' => 'cash', 'amount' => 800]], 'idempotency_key' => $key];
    }

    public function test_new_contacts_remain_linked_through_checkout_return_and_supplier_deliveries_without_changing_company_or_billing_records(): void
    {
        $this->freezeTime();
        $store = $this->store();
        $foreign = $this->store();
        $protected = $this->records($this->protectedTables());
        Http::preventStrayRequests();
        Mail::fake();
        Queue::fake([CheckLowStock::class]);
        $customerId = $this->actingAs($store['user'])->postJson('/api/customers', ['name' => 'Ana Reyes', 'email' => 'ana@example.test', 'phone' => '+63 900 111 2222'])
            ->assertCreated()->json('id');
        $supplierId = $this->postJson('/api/suppliers', ['name' => 'Café Supply Co.', 'email' => 'supplier@example.test', 'phone' => '+63 900 333 4444'])
            ->assertCreated()->json('id');
        $contactRecords = $this->records(['customers', 'suppliers']);
        $checkout = $this->checkout($store, $customerId, 'contact-linked-checkout');

        $saleId = $this->postJson('/api/sales', $checkout)->assertCreated()->assertJsonPath('customer_id', $customerId)
            ->assertJsonPath('subtotal', 750)->assertJsonPath('discount', 75)->assertJsonPath('tax', 81)
            ->assertJsonPath('total', 756)->assertJsonPath('change', 44)->json('id');
        $this->assertDatabaseHas('stocks', ['id' => $store['stock']->id, 'quantity' => 17]);
        $return = ['items' => [['product_id' => $store['product']->id, 'quantity' => 1]], 'reason' => 'One item returned', 'idempotency_key' => 'contact-partial-return'];
        $this->postJson('/api/sales/'.$saleId.'/return', $return)->assertOk()->assertJsonPath('customer_id', $customerId)
            ->assertJsonPath('returned_amount', 252)->assertJsonPath('net_total', 504)->assertJsonPath('status', 'partially_returned');
        $this->postJson('/api/sales/'.$saleId.'/return', $return)->assertOk()->assertJsonCount(1, 'returns');
        $this->postJson('/api/sales', $checkout)->assertCreated()->assertJsonPath('id', $saleId)->assertJsonPath('status', 'partially_returned');
        $this->getJson('/api/sales/'.$saleId)->assertOk()->assertJsonPath('customer_id', $customerId)
            ->assertJsonPath('items.0.returned_quantity', 1)->assertJsonPath('net_total', 504);
        $this->assertDatabaseHas('stocks', ['id' => $store['stock']->id, 'quantity' => 18]);
        $purchase = ['supplier_id' => $supplierId, 'location_id' => $store['location']->id,
            'items' => [['product_id' => $store['product']->id, 'quantity' => 5]], 'idempotency_key' => 'contact-supplier-order'];
        $orderId = $this->postJson('/api/purchase-orders', $purchase)->assertCreated()->assertJsonPath('supplier_id', $supplierId)
            ->assertJsonPath('total', 617.25)->json('id');
        $delivery = ['items' => [['product_id' => $store['product']->id, 'quantity' => 2]], 'note' => 'First supplier delivery', 'idempotency_key' => 'contact-supplier-delivery-one'];
        $this->postJson('/api/purchase-orders/'.$orderId.'/receive', $delivery)->assertOk()->assertJsonPath('status', 'partially_received')
            ->assertJsonPath('received_quantity', 2)->assertJsonPath('remaining_quantity', 3);
        $this->postJson('/api/purchase-orders/'.$orderId.'/receive', $delivery)->assertOk()->assertJsonCount(1, 'receipts');
        $this->assertDatabaseHas('stocks', ['id' => $store['stock']->id, 'quantity' => 20]);
        $this->postJson('/api/purchase-orders/'.$orderId.'/receive', ['items' => [['product_id' => $store['product']->id, 'quantity' => 3]],
            'note' => 'Final supplier delivery', 'idempotency_key' => 'contact-supplier-delivery-two'])
            ->assertOk()->assertJsonPath('status', 'received')->assertJsonPath('supplier_id', $supplierId)->assertJsonCount(2, 'receipts');
        $this->postJson('/api/purchase-orders', $purchase)->assertCreated()->assertJsonPath('id', $orderId)->assertJsonPath('status', 'received');
        $this->getJson('/api/bootstrap')->assertOk()->assertJsonPath('customers.0.id', $customerId)
            ->assertJsonPath('suppliers.0.id', $supplierId)->assertJsonPath('sales.0.customer_id', $customerId)
            ->assertJsonPath('sales.0.net_total', 504)->assertJsonPath('purchase_orders.0.supplier_id', $supplierId);

        $this->assertDatabaseHas('stocks', ['id' => $store['stock']->id, 'quantity' => 23, 'reserved_quantity' => 0]);
        $this->assertDatabaseHas('stocks', ['id' => $store['branchStock']->id, 'quantity' => 7]);
        $this->assertDatabaseHas('stocks', ['id' => $foreign['stock']->id, 'quantity' => 20]);
        $this->assertDatabaseHas('stocks', ['id' => $foreign['branchStock']->id, 'quantity' => 7]);
        $this->assertDatabaseHas('sales', ['id' => $saleId, 'customer_id' => $customerId, 'tenant_id' => $store['tenant']->id, 'returned_cents' => 25200]);
        $this->assertDatabaseHas('purchase_orders', ['id' => $orderId, 'supplier_id' => $supplierId, 'tenant_id' => $store['tenant']->id, 'total_cents' => 61725]);
        $this->assertDatabaseCount('sales', 1);
        $this->assertDatabaseCount('sale_returns', 1);
        $this->assertDatabaseCount('purchase_orders', 1);
        $this->assertDatabaseCount('purchase_receipts', 2);
        $this->assertDatabaseCount('stock_movements', 4);
        $this->assertSame($protected, $this->records($this->protectedTables()));
        $this->assertSame($contactRecords, $this->records(['customers', 'suppliers']));
        Queue::assertPushed(CheckLowStock::class, fn (CheckLowStock $job): bool => $job->tenantId === $store['tenant']->id);
        Queue::assertPushed(CheckLowStock::class, 1);
        Http::assertNothingSent();
        Mail::assertNothingSent();
    }

    public function test_foreign_contact_links_return_404_without_partial_sale_or_purchase_and_cashiers_see_only_permitted_history(): void
    {
        $this->freezeTime();
        $store = $this->store();
        $foreign = $this->store();
        $cashier = User::factory()->create(['tenant_id' => $store['tenant']->id, 'role' => 'cashier']);
        $customer = Customer::factory()->create(['tenant_id' => $store['tenant']->id]);
        $foreignCustomer = Customer::factory()->create(['tenant_id' => $foreign['tenant']->id]);
        $supplier = Supplier::factory()->create(['tenant_id' => $store['tenant']->id]);
        $foreignSupplier = Supplier::factory()->create(['tenant_id' => $foreign['tenant']->id]);
        $tables = [...$this->protectedTables(), 'customers', 'suppliers', 'sales', 'sale_returns', 'purchase_orders', 'purchase_receipts', 'stocks', 'stock_movements'];
        $before = $this->records($tables);
        Http::preventStrayRequests();
        Mail::fake();
        Queue::fake([CheckLowStock::class]);

        $this->actingAs($store['user'])->postJson('/api/sales', $this->checkout($store, $foreignCustomer->id, 'foreign-customer-sale'))
            ->assertNotFound();
        $this->postJson('/api/purchase-orders', ['supplier_id' => $foreignSupplier->id, 'location_id' => $store['location']->id,
            'items' => [['product_id' => $store['product']->id, 'quantity' => 2]], 'idempotency_key' => 'foreign-supplier-order'])
            ->assertNotFound();

        $this->assertSame($before, $this->records($tables));
        Queue::assertNothingPushed();
        $adminSale = $this->postJson('/api/sales', $this->checkout($store, $customer->id, 'admin-customer-sale'))->assertCreated()->json('id');
        $cashierSale = $this->actingAs($cashier)->postJson('/api/sales', $this->checkout($store, $customer->id, 'cashier-customer-sale'))->assertCreated()->json('id');
        $this->getJson('/api/bootstrap')->assertOk()->assertJsonCount(1, 'customers')->assertJsonPath('customers.0.id', $customer->id)
            ->assertJsonCount(0, 'suppliers')->assertJsonCount(0, 'purchase_orders')->assertJsonCount(1, 'sales')
            ->assertJsonPath('sales.0.id', $cashierSale)->assertJsonPath('sales.0.customer_id', $customer->id);
        $this->getJson('/api/sales/'.$adminSale)->assertNotFound();
        $this->getJson('/api/sales/'.$cashierSale)->assertOk()->assertJsonPath('customer_id', $customer->id);
        $this->postJson('/api/purchase-orders', ['supplier_id' => $supplier->id, 'location_id' => $store['location']->id,
            'items' => [['product_id' => $store['product']->id, 'quantity' => 2]], 'idempotency_key' => 'cashier-supplier-order'])->assertForbidden();
        $this->actingAs($store['user'])->getJson('/api/bootstrap')->assertOk()->assertJsonCount(1, 'customers')
            ->assertJsonPath('suppliers.0.id', $supplier->id)->assertJsonCount(2, 'sales')->assertJsonCount(0, 'purchase_orders');

        $this->assertSame($before['customers'], $this->records(['customers'])['customers']);
        $this->assertSame($before['suppliers'], $this->records(['suppliers'])['suppliers']);
        $this->assertSame(array_intersect_key($before, array_flip($this->protectedTables())), $this->records($this->protectedTables()));
        $this->assertDatabaseHas('stocks', ['id' => $store['stock']->id, 'quantity' => 14]);
        $this->assertDatabaseHas('stocks', ['id' => $foreign['stock']->id, 'quantity' => 20]);
        $this->assertDatabaseCount('sales', 2);
        $this->assertDatabaseCount('stock_movements', 2);
        $this->assertDatabaseCount('purchase_orders', 0);
        Queue::assertPushed(CheckLowStock::class, fn (CheckLowStock $job): bool => $job->tenantId === $store['tenant']->id);
        Queue::assertPushed(CheckLowStock::class, 1);
        Http::assertNothingSent();
        Mail::assertNothingSent();
    }
}
