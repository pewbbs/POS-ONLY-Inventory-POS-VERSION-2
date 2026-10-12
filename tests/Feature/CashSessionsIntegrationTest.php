<?php

namespace Tests\Feature;

use App\Jobs\CheckLowStock;
use App\Models\BillingInvoice;
use App\Models\BillingPaymentAttempt;
use App\Models\CashSession;
use App\Models\Location;
use App\Models\PayMongoWebhookEvent;
use App\Models\Product;
use App\Models\Stock;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class CashSessionsIntegrationTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['mail.default' => 'array', 'paymongo.enabled' => false, 'paymongo.secret_key' => '',
            'paymongo.webhook_secret' => '', 'billing.simulations_enabled' => false]);
    }

    /** @return array{tenant: Tenant, user: User, location: Location, product: Product, stock: Stock} */
    private function store(): array
    {
        $tenant = Tenant::factory()->create(['settings' => array_replace(Tenant::defaultSettings(), ['tax_percent' => 0])]);
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'role' => 'manager']);
        $location = Location::factory()->create(['tenant_id' => $tenant->id]);
        $product = Product::factory()->create(['tenant_id' => $tenant->id, 'price_cents' => 25000, 'reorder_level' => 0]);
        $stock = Stock::factory()->create(['tenant_id' => $tenant->id, 'location_id' => $location->id,
            'product_id' => $product->id, 'quantity' => 20, 'reserved_quantity' => 0]);
        $subscription = Subscription::factory()->create(['tenant_id' => $tenant->id]);
        $invoice = BillingInvoice::factory()->create(['tenant_id' => $tenant->id, 'subscription_id' => $subscription->id,
            'status' => 'paid', 'paid_at' => now()]);
        BillingPaymentAttempt::factory()->create(['tenant_id' => $tenant->id, 'billing_invoice_id' => $invoice->id,
            'active_scope' => null, 'status' => 'paid', 'confirmed_at' => now()]);
        PayMongoWebhookEvent::factory()->create(['status' => 'processed', 'processed_at' => now()]);

        return compact('tenant', 'user', 'location', 'product', 'stock');
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

    private function openShift(User $user, Location $location, string $key, float $amount): int
    {
        return $this->actingAs($user)->postJson('/api/cash-sessions/open', [
            'location_id' => $location->id, 'opening_float' => $amount, 'idempotency_key' => $key,
        ])->assertCreated()->json('id');
    }

    /**
     * @param  array{tenant: Tenant, user: User, location: Location, product: Product, stock: Stock}  $store
     * @param  array<int, array{method: string, amount: int|float}>  $payments
     * @return array<string, mixed>
     */
    private function checkout(array $store, string $key, array $payments, int $quantity = 1): array
    {
        return ['location_id' => $store['location']->id, 'items' => [['product_id' => $store['product']->id, 'quantity' => $quantity]],
            'tax_percent' => 0, 'discount_percent' => 0, 'payments' => $payments, 'idempotency_key' => $key];
    }

    public function test_mixed_tenders_manual_refund_and_reconciliation_survive_retries_without_changing_company_or_billing_records(): void
    {
        $this->freezeTime();
        $store = $this->store();
        $foreign = $this->store();
        $protected = $this->protectedRecords();
        Http::preventStrayRequests();
        Mail::fake();
        Queue::fake([CheckLowStock::class]);
        $shiftId = $this->openShift($store['user'], $store['location'], 'reconciliation-shift-open', 100.25);
        $checkout = $this->checkout($store, 'reconciliation-mixed-sale', [
            ['method' => 'card', 'amount' => 125], ['method' => 'wallet', 'amount' => 75], ['method' => 'cash', 'amount' => 400],
        ], 2);

        $saleId = $this->postJson('/api/sales', $checkout)->assertCreated()->assertJsonPath('total', 500)
            ->assertJsonPath('change', 100)->assertJsonPath('cash_session_id', $shiftId)->json('id');
        $this->postJson('/api/sales', $checkout)->assertCreated()->assertJsonPath('id', $saleId);
        $this->postJson('/api/sales', $this->checkout($store, 'reconciliation-wallet-sale', [['method' => 'wallet', 'amount' => 250]]))
            ->assertCreated()->assertJsonPath('cash_session_id', $shiftId)->assertJsonPath('change', 0);
        $this->postJson('/api/sales', $this->checkout($store, 'reconciliation-card-sale', [['method' => 'card', 'amount' => 250]]))
            ->assertCreated()->assertJsonPath('cash_session_id', $shiftId);
        $cashIn = ['direction' => 'in', 'amount' => 25.75, 'reason' => 'Extra change supplied', 'idempotency_key' => 'reconciliation-cash-in'];
        $cashOut = ['direction' => 'out', 'amount' => 20, 'reason' => 'Petty cash paid', 'idempotency_key' => 'reconciliation-cash-out'];
        $this->postJson('/api/cash-sessions/'.$shiftId.'/adjustments', $cashIn)->assertOk()->assertJsonPath('expected_cash', 426);
        $this->postJson('/api/cash-sessions/'.$shiftId.'/adjustments', $cashOut)->assertOk()->assertJsonPath('expected_cash', 406);
        $return = ['items' => [['product_id' => $store['product']->id, 'quantity' => 1]],
            'reason' => 'One damaged item returned', 'idempotency_key' => 'reconciliation-partial-return'];
        $this->postJson('/api/sales/'.$saleId.'/return', $return)->assertOk()->assertJsonPath('returned_amount', 250)
            ->assertJsonPath('status', 'partially_returned');
        $this->postJson('/api/sales/'.$saleId.'/return', $return)->assertOk()->assertJsonCount(1, 'returns');
        $this->getJson('/api/cash-sessions?location_id='.$store['location']->id)->assertOk()
            ->assertJsonPath('current.expected_cash', 406)->assertJsonPath('current.cash_sales', 300)
            ->assertJsonPath('current.cash_out', 20);
        $refund = ['direction' => 'out', 'amount' => 250, 'reason' => 'Cash refund paid for returned item', 'idempotency_key' => 'reconciliation-manual-refund'];
        $this->postJson('/api/cash-sessions/'.$shiftId.'/adjustments', $refund)->assertOk()->assertJsonPath('expected_cash', 156);
        $this->postJson('/api/cash-sessions/'.$shiftId.'/adjustments', $refund)->assertOk()->assertJsonCount(3, 'adjustments');
        $close = ['counted_cash' => 155.50, 'reason' => 'Fifty centavos short', 'idempotency_key' => 'reconciliation-close'];
        $this->postJson('/api/cash-sessions/'.$shiftId.'/close', $close)->assertOk()->assertJsonPath('status', 'closed')
            ->assertJsonPath('expected_cash', 156)->assertJsonPath('variance', -0.5);
        $closed = CashSession::findOrFail($shiftId)->getRawOriginal();
        $this->travel(1)->hours();
        $nextShift = $this->openShift($store['user'], $store['location'], 'reconciliation-next-shift', 100);
        $this->postJson('/api/sales', $checkout)->assertCreated()->assertJsonPath('id', $saleId)->assertJsonPath('cash_session_id', $shiftId);
        $this->postJson('/api/cash-sessions/'.$shiftId.'/close', $close)->assertOk()->assertJsonPath('variance', -0.5);
        $this->postJson('/api/cash-sessions/'.$shiftId.'/adjustments', $refund)->assertOk()->assertJsonPath('status', 'closed');
        $this->getJson('/api/cash-sessions?location_id='.$store['location']->id)->assertOk()
            ->assertJsonPath('current.id', $nextShift)->assertJsonPath('current.expected_cash', 100)
            ->assertJsonPath('history.1.id', $shiftId)->assertJsonPath('history.1.counted_cash', 155.5);

        $this->assertSame($closed, CashSession::findOrFail($shiftId)->getRawOriginal());
        $this->assertSame($protected, $this->protectedRecords());
        $this->assertDatabaseHas('stocks', ['id' => $store['stock']->id, 'quantity' => 17]);
        $this->assertDatabaseHas('stocks', ['id' => $foreign['stock']->id, 'quantity' => 20]);
        $this->assertDatabaseCount('sales', 3);
        $this->assertDatabaseCount('sale_returns', 1);
        $this->assertDatabaseCount('cash_adjustments', 3);
        $this->assertDatabaseCount('stock_movements', 4);
        Queue::assertPushed(CheckLowStock::class, fn (CheckLowStock $job): bool => $job->tenantId === $store['tenant']->id);
        Http::assertNothingSent();
        Mail::assertNothingSent();
    }

    public function test_interleaved_cashiers_and_locations_reconcile_only_their_own_drawer(): void
    {
        $this->freezeTime();
        $store = $this->store();
        $foreign = $this->store();
        $otherUser = User::factory()->create(['tenant_id' => $store['tenant']->id, 'role' => 'cashier']);
        $otherLocation = Location::factory()->create(['tenant_id' => $store['tenant']->id]);
        $otherStock = Stock::factory()->create(['tenant_id' => $store['tenant']->id, 'product_id' => $store['product']->id,
            'location_id' => $otherLocation->id, 'quantity' => 12, 'reserved_quantity' => 0]);
        Http::preventStrayRequests();
        Mail::fake();
        Queue::fake([CheckLowStock::class]);
        $primary = $this->openShift($store['user'], $store['location'], 'interleaved-primary-shift', 100);
        $otherDrawer = $this->openShift($otherUser, $store['location'], 'interleaved-other-cashier', 20);
        $branch = $this->openShift($store['user'], $otherLocation, 'interleaved-branch-shift', 50);
        $foreignDrawer = $this->openShift($foreign['user'], $foreign['location'], 'interleaved-foreign-shift', 1000);
        $protected = $this->protectedRecords();
        $foreignBefore = CashSession::findOrFail($foreignDrawer)->getRawOriginal();

        $this->actingAs($store['user'])->postJson('/api/sales', $this->checkout($store, 'interleaved-primary-sale', [
            ['method' => 'cash', 'amount' => 200], ['method' => 'card', 'amount' => 100],
        ]))->assertCreated()->assertJsonPath('change', 50)->assertJsonPath('cash_session_id', $primary);
        $branchStore = array_replace($store, ['location' => $otherLocation]);
        $this->postJson('/api/sales', $this->checkout($branchStore, 'interleaved-branch-sale', [['method' => 'cash', 'amount' => 300]]))
            ->assertCreated()->assertJsonPath('cash_session_id', $branch)->assertJsonPath('change', 50);
        $this->actingAs($otherUser)->postJson('/api/sales', $this->checkout($store, 'interleaved-cashier-sale', [['method' => 'cash', 'amount' => 250]]))
            ->assertCreated()->assertJsonPath('cash_session_id', $otherDrawer);
        $this->getJson('/api/cash-sessions?location_id='.$store['location']->id)->assertOk()->assertJsonPath('current.id', $otherDrawer)
            ->assertJsonPath('current.expected_cash', 270)->assertJsonCount(1, 'history');
        $this->postJson('/api/cash-sessions/'.$primary.'/close', ['counted_cash' => 250, 'idempotency_key' => 'interleaved-forbidden-close'])->assertNotFound();
        $this->postJson('/api/cash-sessions/'.$otherDrawer.'/close', ['counted_cash' => 275, 'idempotency_key' => 'interleaved-cashier-close'])->assertOk()
            ->assertJsonPath('expected_cash', 270)->assertJsonPath('variance', 5);
        $this->actingAs($store['user'])->postJson('/api/cash-sessions/'.$primary.'/close', ['counted_cash' => 250, 'idempotency_key' => 'interleaved-primary-close'])
            ->assertOk()->assertJsonPath('variance', 0);
        $this->postJson('/api/cash-sessions/'.$branch.'/close', ['counted_cash' => 300, 'idempotency_key' => 'interleaved-branch-close'])
            ->assertOk()->assertJsonPath('expected_cash', 300)->assertJsonPath('variance', 0);

        $this->assertSame($foreignBefore, CashSession::findOrFail($foreignDrawer)->getRawOriginal());
        $this->assertSame($protected, $this->protectedRecords());
        $this->assertDatabaseHas('stocks', ['id' => $store['stock']->id, 'quantity' => 18]);
        $this->assertDatabaseHas('stocks', ['id' => $otherStock->id, 'quantity' => 11]);
        $this->assertDatabaseHas('stocks', ['id' => $foreign['stock']->id, 'quantity' => 20]);
        $this->assertDatabaseCount('sales', 3);
        $this->assertDatabaseCount('stock_movements', 3);
        $this->assertDatabaseCount('cash_adjustments', 0);
        Queue::assertPushed(CheckLowStock::class, fn (CheckLowStock $job): bool => $job->tenantId === $store['tenant']->id);
        Http::assertNothingSent();
        Mail::assertNothingSent();
    }

    public function test_late_cash_attachment_failure_rolls_back_stock_sale_and_drawer_before_same_key_checkout_recovery(): void
    {
        $this->freezeTime();
        $store = $this->store();
        Http::preventStrayRequests();
        Mail::fake();
        Exceptions::fake();
        $shift = $this->openShift($store['user'], $store['location'], 'late-failure-opening', 100);
        $protected = $this->protectedRecords();
        $checkout = $this->checkout($store, 'late-failure-checkout', [['method' => 'cash', 'amount' => 600]], 2);
        config(['queue.default' => 'database']);
        DB::unprepared("CREATE TEMP TRIGGER reject_cash_attachment BEFORE UPDATE OF cash_session_id ON sales
            WHEN NEW.cash_session_id IS NOT NULL BEGIN SELECT RAISE(ABORT, 'Isolated cash attachment failure'); END");

        try {
            $this->postJson('/api/sales', $checkout)->assertInternalServerError();
            $this->assertDatabaseCount('sales', 0);
            $this->assertDatabaseCount('stock_movements', 0);
            $this->assertDatabaseCount('jobs', 0);
            $this->assertDatabaseHas('stocks', ['id' => $store['stock']->id, 'quantity' => 20]);
            $this->assertDatabaseHas('cash_sessions', ['id' => $shift, 'cash_sales_cents' => 0]);
        } finally {
            DB::unprepared('DROP TRIGGER reject_cash_attachment');
        }

        $saleId = $this->postJson('/api/sales', $checkout)->assertCreated()->assertJsonPath('cash_session_id', $shift)
            ->assertJsonPath('change', 100)->json('id');
        $this->postJson('/api/sales', $checkout)->assertCreated()->assertJsonPath('id', $saleId);
        $this->assertDatabaseCount('sales', 1);
        $this->assertDatabaseCount('stock_movements', 1);
        $this->assertDatabaseCount('jobs', 1);
        $this->assertDatabaseHas('stocks', ['id' => $store['stock']->id, 'quantity' => 18]);
        $this->assertDatabaseHas('cash_sessions', ['id' => $shift, 'cash_sales_cents' => 50000]);
        $this->assertSame($protected, $this->protectedRecords());
        Exceptions::assertReported(QueryException::class);
        Http::assertNothingSent();
        Mail::assertNothingSent();
    }
}
