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
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

class MobileOfflineLifecycleTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['mail.default' => 'array', 'paymongo.enabled' => false, 'paymongo.secret_key' => '',
            'paymongo.webhook_secret' => '', 'billing.simulations_enabled' => false]);
    }

    /** @return array{tenant: Tenant, user: User, location: Location, branch: Location, product: Product, stock: Stock} */
    private function store(string $role = 'manager'): array
    {
        $tenant = Tenant::factory()->create(['settings' => array_replace(Tenant::defaultSettings(), ['tax_percent' => 12])]);
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'role' => $role]);
        $location = Location::factory()->create(['tenant_id' => $tenant->id]);
        $branch = Location::factory()->create(['tenant_id' => $tenant->id]);
        $product = Product::factory()->create(['tenant_id' => $tenant->id, 'price_cents' => 10000,
            'wholesale_price_cents' => 8000, 'reorder_level' => 0, 'barcode' => 'OFFLINE-MOBILE-'.$tenant->id]);
        $stock = Stock::factory()->create(['tenant_id' => $tenant->id, 'location_id' => $location->id,
            'product_id' => $product->id, 'quantity' => 20, 'reserved_quantity' => 0]);
        Stock::factory()->create(['tenant_id' => $tenant->id, 'location_id' => $branch->id,
            'product_id' => $product->id, 'quantity' => 7, 'reserved_quantity' => 0]);
        $subscription = Subscription::factory()->create(['tenant_id' => $tenant->id]);
        $invoice = BillingInvoice::factory()->create(['tenant_id' => $tenant->id, 'subscription_id' => $subscription->id,
            'status' => 'paid', 'paid_at' => now()]);
        BillingPaymentAttempt::factory()->create(['tenant_id' => $tenant->id, 'billing_invoice_id' => $invoice->id,
            'mode' => 'live', 'status' => 'paid', 'active_scope' => null, 'confirmed_at' => now()]);
        PayMongoWebhookEvent::factory()->create(['mode' => 'live', 'status' => 'processed', 'processed_at' => now()]);

        return compact('tenant', 'user', 'location', 'branch', 'product', 'stock');
    }

    private function login(User $user, string $device = 'Offline QA phone'): string
    {
        Auth::forgetGuards();

        return $this->withHeader('Authorization', '')->postJson('/api/v1/auth/token', [
            'email' => $user->email, 'password' => 'password', 'device_name' => $device,
        ])->assertCreated()->assertHeaderMissing('Set-Cookie')->json('token');
    }

    private function bearer(string $token): static
    {
        Auth::forgetGuards();

        return $this->withHeader('Authorization', 'Bearer '.$token);
    }

    /** @param array{location: Location, product: Product} $store
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>
     */
    private function queuedSale(array $store, array $snapshot, string $key, int $quantity, int $subtotal, int $tax, int $tender, int $discount = 0, string $tier = 'retail'): array
    {
        $session = collect($snapshot['cash_sessions'])->firstWhere('location_id', $store['location']->id);

        return ['location_id' => $store['location']->id, 'price_tier' => $tier,
            'items' => [['product_id' => $store['product']->id, 'quantity' => $quantity]],
            'discount_percent' => $discount === 0 ? 0 : 10, 'tax_percent' => 12,
            'payments' => [['method' => 'cash', 'amount' => $tender]], 'idempotency_key' => $key,
            'permit' => $snapshot['permit'], 'recorded_at' => now()->toIso8601String(),
            'expected' => ['items' => [['product_id' => $store['product']->id, 'unit_price_cents' => $tier === 'retail' ? 10000 : 8000]],
                'subtotal_cents' => $subtotal, 'discount_cents' => $discount, 'tax_cents' => $tax,
                'total_cents' => $subtotal - $discount + $tax, 'cash_session_id' => $session['id'] ?? null]];
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

    public function test_queued_mobile_cash_sales_survive_device_expiry_and_relogin_then_reconcile_once_after_returns(): void
    {
        $this->freezeTime();
        $store = $this->store();
        $foreign = $this->store();
        Http::preventStrayRequests();
        Mail::fake();
        Queue::fake([CheckLowStock::class]);
        $protected = $this->records($this->protectedTables());
        $token = $this->login($store['user']);
        $this->bearer($token)->getJson('/api/v1/bootstrap')->assertOk()->assertJsonPath('tenant.id', $store['tenant']->id)
            ->assertJsonPath('user.role', 'manager')->assertJsonPath('access.can_write', true);
        $this->bearer($token)->getJson('/api/v1/products?barcode='.$store['product']->barcode)->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $store['product']->id);
        $sessionId = $this->bearer($token)->postJson('/api/v1/cash-sessions/open', ['location_id' => $store['location']->id,
            'opening_float' => 25, 'idempotency_key' => 'offline-lifecycle-open'])->assertCreated()->json('id');
        $snapshot = $this->bearer($token)->getJson('/api/v1/offline/snapshot')->assertOk()->json();
        $first = $this->queuedSale($store, $snapshot, 'offline-lifecycle-first', 2, 20000, 2160, 250, 2000);
        $second = $this->queuedSale($store, $snapshot, 'offline-lifecycle-second', 1, 8000, 960, 100, 0, 'wholesale');
        $this->travel(2)->days();
        PersonalAccessToken::findToken($token)->update(['expires_at' => now()->subSecond()]);
        $unchanged = $this->records(['sales', 'stocks', 'cash_sessions', 'stock_movements']);

        $this->bearer($token)->postJson('/api/v1/offline/sales', $first)->assertUnauthorized();
        $this->assertSame($unchanged, $this->records(array_keys($unchanged)));
        $replacement = $this->login($store['user'], 'Reconnected QA phone');
        $firstId = $this->bearer($replacement)->postJson('/api/v1/offline/sales', $first)->assertCreated()
            ->assertJsonPath('total', 201.6)->assertJsonPath('change', 48.4)->assertJsonPath('cash_session_id', $sessionId)
            ->assertJsonPath('offline.recorded_at', $first['recorded_at'])->assertJsonPath('offline.synced_at', now()->toIso8601String())->json('id');
        $secondId = $this->bearer($replacement)->postJson('/api/v1/offline/sales', $second)->assertCreated()->assertJsonPath('total', 89.6)->json('id');
        $this->bearer($replacement)->getJson('/api/v1/sales/'.$firstId)->assertOk()->assertJsonPath('offline.recorded_at', $first['recorded_at'])
            ->assertJsonPath('items.0.quantity', 2)->assertJsonPath('items.0.unit_price', 100);
        $this->bearer($replacement)->postJson('/api/v1/sales/'.$firstId.'/return', ['reason' => 'One queued item returned',
            'items' => [['product_id' => $store['product']->id, 'quantity' => 1]], 'idempotency_key' => 'offline-lifecycle-return'])
            ->assertOk()->assertJsonPath('status', 'partially_returned')->assertJsonPath('returned_amount', 100.8);
        $this->bearer($replacement)->postJson('/api/v1/cash-sessions/'.$sessionId.'/adjustments', ['direction' => 'out', 'amount' => 100.8,
            'reason' => 'Cash refund for returned queued item', 'idempotency_key' => 'offline-lifecycle-refund'])->assertOk();
        $this->bearer($replacement)->postJson('/api/v1/cash-sessions/'.$sessionId.'/close', ['counted_cash' => 215.4,
            'idempotency_key' => 'offline-lifecycle-close'])->assertOk()->assertJsonPath('variance', 0);
        $confirmed = $this->records(['sales', 'stocks', 'cash_sessions', 'cash_adjustments', 'stock_movements', 'sale_returns']);
        $this->bearer($replacement)->postJson('/api/v1/offline/sales', $first)->assertCreated()->assertJsonPath('id', $firstId)
            ->assertJsonPath('status', 'partially_returned')->assertJsonPath('cash_session_id', $sessionId);
        $this->bearer($replacement)->postJson('/api/v1/offline/sales', $second)->assertCreated()->assertJsonPath('id', $secondId);

        $this->assertSame($confirmed, $this->records(array_keys($confirmed)));
        $this->assertDatabaseHas('stocks', ['id' => $store['stock']->id, 'quantity' => 18]);
        $this->assertDatabaseHas('stocks', ['id' => $foreign['stock']->id, 'quantity' => 20]);
        $this->assertDatabaseHas('stocks', ['product_id' => $store['product']->id, 'location_id' => $store['branch']->id, 'quantity' => 7]);
        $this->assertDatabaseCount('sales', 2);
        $this->assertDatabaseHas('cash_sessions', ['id' => $sessionId, 'cash_sales_cents' => 29120, 'cash_out_cents' => 10080,
            'expected_cash_cents' => 21540, 'variance_cents' => 0]);
        $this->assertSame($protected, $this->records($this->protectedTables()));
        $this->bearer($replacement)->deleteJson('/api/v1/auth/token')->assertNoContent();
        $this->bearer($replacement)->getJson('/api/v1/sales/'.$firstId)->assertUnauthorized();
        Queue::assertPushed(CheckLowStock::class, fn (CheckLowStock $job): bool => $job->tenantId === $store['tenant']->id);
        Queue::assertPushed(CheckLowStock::class, 1);
        Http::assertNothingSent();
        Mail::assertNothingSent();
    }

    public function test_reconnection_stock_conflict_keeps_prior_confirmed_sale_and_can_recover_with_the_original_second_payload(): void
    {
        $this->freezeTime();
        $store = $this->store();
        $store['stock']->update(['quantity' => 5, 'reserved_quantity' => 1]);
        Http::preventStrayRequests();
        Mail::fake();
        Queue::fake([CheckLowStock::class]);
        $protected = $this->records($this->protectedTables());
        $token = $this->login($store['user']);
        $snapshot = $this->bearer($token)->getJson('/api/v1/offline/snapshot')->assertOk()->assertJsonPath('products.0.stocks.0.available_quantity', 4)->json();
        $first = $this->queuedSale($store, $snapshot, 'reconnect-confirmed-first', 3, 30000, 3600, 400);
        $second = $this->queuedSale($store, $snapshot, 'reconnect-conflict-second', 2, 20000, 2400, 250);
        $firstId = $this->bearer($token)->postJson('/api/v1/offline/sales', $first)->assertCreated()->assertJsonPath('total', 336)->json('id');
        $beforeConflict = $this->records(['sales', 'stocks', 'stock_movements', 'cash_sessions', 'activity_logs']);

        $this->bearer($token)->postJson('/api/v1/offline/sales', $second)->assertUnprocessable()->assertJsonValidationErrors('items');
        $this->assertSame($beforeConflict, $this->records(array_keys($beforeConflict)));
        $this->bearer($token)->postJson('/api/v1/offline/sales', $first)->assertCreated()->assertJsonPath('id', $firstId);
        $this->bearer($token)->postJson('/api/v1/sales/'.$firstId.'/return', ['reason' => 'Restore saleable stock',
            'items' => [['product_id' => $store['product']->id, 'quantity' => 1]], 'idempotency_key' => 'reconnect-stock-return'])->assertOk();
        $this->bearer($token)->postJson('/api/v1/offline/sales', $second)->assertCreated()->assertJsonPath('total', 224);

        $this->assertDatabaseCount('sales', 2);
        $this->assertDatabaseCount('sale_returns', 1);
        $this->assertDatabaseHas('stocks', ['id' => $store['stock']->id, 'quantity' => 1, 'reserved_quantity' => 1]);
        $this->assertSame($protected, $this->records($this->protectedTables()));
        Queue::assertPushed(CheckLowStock::class, fn (CheckLowStock $job): bool => $job->tenantId === $store['tenant']->id);
        Queue::assertPushed(CheckLowStock::class, 1);
        Http::assertNothingSent();
        Mail::assertNothingSent();
    }

    public function test_another_company_cannot_use_queued_permits_or_receipts_and_same_keys_remain_company_scoped(): void
    {
        $this->freezeTime();
        $store = $this->store('cashier');
        $foreign = $this->store('cashier');
        Http::preventStrayRequests();
        Mail::fake();
        Queue::fake([CheckLowStock::class]);
        $protected = $this->records($this->protectedTables());
        $token = $this->login($store['user']);
        $foreignToken = $this->login($foreign['user']);
        $snapshot = $this->bearer($token)->getJson('/api/v1/offline/snapshot')->assertOk()->json();
        $foreignSnapshot = $this->bearer($foreignToken)->getJson('/api/v1/offline/snapshot')->assertOk()->json();
        $first = $this->queuedSale($store, $snapshot, 'shared-device-queue-key', 1, 10000, 1200, 150);
        $foreignSale = $this->queuedSale($foreign, $foreignSnapshot, 'shared-device-queue-key', 1, 10000, 1200, 150);
        $stolenPermit = $foreignSale;
        $stolenPermit['permit'] = $first['permit'];

        $this->bearer($foreignToken)->postJson('/api/v1/offline/sales', $stolenPermit)->assertForbidden()
            ->assertJsonPath('message', 'This offline snapshot does not belong to your current workspace account.');
        $this->assertDatabaseCount('sales', 0);
        $firstId = $this->bearer($token)->postJson('/api/v1/offline/sales', $first)->assertCreated()->json('id');
        $foreignId = $this->bearer($foreignToken)->postJson('/api/v1/offline/sales', $foreignSale)->assertCreated()->json('id');
        $this->bearer($token)->getJson('/api/v1/sales/'.$foreignId)->assertNotFound();
        $this->bearer($foreignToken)->getJson('/api/v1/sales/'.$firstId)->assertNotFound();
        $this->bearer($token)->getJson('/api/v1/sales?range=all')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $firstId);

        $this->assertDatabaseHas('stocks', ['id' => $store['stock']->id, 'quantity' => 19]);
        $this->assertDatabaseHas('stocks', ['id' => $foreign['stock']->id, 'quantity' => 19]);
        $this->assertDatabaseCount('sales', 2);
        $this->assertSame($protected, $this->records($this->protectedTables()));
        Queue::assertPushed(CheckLowStock::class, 2);
        Http::assertNothingSent();
        Mail::assertNothingSent();
    }

    public function test_production_https_mobile_lifecycle_stays_stateless_and_never_calls_payment_providers(): void
    {
        $this->freezeTime();
        $store = $this->store();
        Http::preventStrayRequests();
        Mail::fake();
        Queue::fake([CheckLowStock::class]);
        $protected = $this->records($this->protectedTables());
        $sessionRows = DB::table('sessions')->get()->all();
        app()->instance('env', 'production');
        $token = $this->postJson('https://posonly.example.test/api/v1/auth/token', ['email' => $store['user']->email,
            'password' => 'password', 'device_name' => 'HTTPS QA phone'])->assertCreated()->assertHeaderMissing('Set-Cookie')->json('token');
        $this->bearer($token)->getJson('https://posonly.example.test/api/v1/bootstrap')->assertOk()
            ->assertHeader('Referrer-Policy', 'no-referrer')->assertHeader('Cache-Control', 'no-store, private')->assertHeaderMissing('Set-Cookie');
        $snapshot = $this->bearer($token)->getJson('https://posonly.example.test/api/v1/offline/snapshot')->assertOk()->json();
        $payload = $this->queuedSale($store, $snapshot, 'https-offline-cash-sale', 1, 10000, 1200, 150);

        $this->bearer($token)->postJson('https://posonly.example.test/api/v1/offline/sales', $payload)->assertCreated()
            ->assertJsonPath('total', 112)->assertHeaderMissing('Set-Cookie');
        $this->bearer($token)->postJson('http://posonly.example.test/api/v1/offline/sales', $payload)->assertBadRequest();

        $this->assertDatabaseCount('sales', 1);
        $this->assertDatabaseHas('stocks', ['id' => $store['stock']->id, 'quantity' => 19]);
        $this->assertSame($sessionRows, DB::table('sessions')->get()->all());
        $this->assertSame($protected, $this->records($this->protectedTables()));
        Queue::assertPushed(CheckLowStock::class, 1);
        Http::assertNothingSent();
        Mail::assertNothingSent();
    }
}
