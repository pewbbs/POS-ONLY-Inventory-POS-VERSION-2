<?php

namespace Tests\Feature;

use App\Jobs\CheckLowStock;
use App\Models\BillingInvoice;
use App\Models\BillingPaymentAttempt;
use App\Models\CashSession;
use App\Models\Location;
use App\Models\PayMongoWebhookEvent;
use App\Models\Product;
use App\Models\Sale;
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
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class OfflineCashSyncTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['mail.default' => 'array', 'paymongo.enabled' => false, 'paymongo.secret_key' => '',
            'paymongo.webhook_secret' => '', 'billing.simulations_enabled' => false]);
    }

    /** @return array{tenant: Tenant, user: User, location: Location, product: Product, stock: Stock, session: CashSession} */
    private function store(): array
    {
        $tenant = Tenant::factory()->create(['settings' => array_replace(Tenant::defaultSettings(), ['tax_percent' => 12])]);
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'role' => 'cashier']);
        $location = Location::factory()->create(['tenant_id' => $tenant->id]);
        $product = Product::factory()->create(['tenant_id' => $tenant->id, 'price_cents' => 25000, 'wholesale_price_cents' => 20000, 'reorder_level' => 0]);
        $stock = Stock::factory()->create(['tenant_id' => $tenant->id, 'location_id' => $location->id, 'product_id' => $product->id,
            'quantity' => 20, 'reserved_quantity' => 0]);
        $session = CashSession::factory()->create(['tenant_id' => $tenant->id, 'user_id' => $user->id,
            'location_id' => $location->id, 'opening_float_cents' => 50000]);
        $subscription = Subscription::factory()->create(['tenant_id' => $tenant->id]);
        $invoice = BillingInvoice::factory()->create(['tenant_id' => $tenant->id, 'subscription_id' => $subscription->id, 'status' => 'paid', 'paid_at' => now()]);
        BillingPaymentAttempt::factory()->create(['tenant_id' => $tenant->id, 'billing_invoice_id' => $invoice->id,
            'active_scope' => null, 'status' => 'paid', 'confirmed_at' => now()]);
        PayMongoWebhookEvent::factory()->create(['status' => 'processed', 'processed_at' => now()]);

        return compact('tenant', 'user', 'location', 'product', 'stock', 'session');
    }

    /** @param array{user: User, location: Location, product: Product, session: CashSession} $store
     * @return array<string, mixed>
     */
    private function checkout(array $store, string $key = 'offline-cash-sale-one'): array
    {
        $snapshot = $this->actingAs($store['user'])->getJson('/api/offline/snapshot')->assertOk();

        return ['permit' => $snapshot->json('permit'), 'recorded_at' => now()->toIso8601String(),
            'location_id' => $store['location']->id, 'price_tier' => 'retail',
            'items' => [['product_id' => $store['product']->id, 'quantity' => 3]],
            'discount_percent' => 10, 'tax_percent' => 12, 'payments' => [['method' => 'cash', 'amount' => 800]],
            'idempotency_key' => $key, 'expected' => [
                'items' => [['product_id' => $store['product']->id, 'unit_price_cents' => 25000]],
                'subtotal_cents' => 75000, 'discount_cents' => 7500, 'tax_cents' => 8100, 'total_cents' => 75600,
                'cash_session_id' => $store['session']->id,
            ]];
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

    /** @return array<int, string> */
    private function transactionalTables(): array
    {
        return [...$this->protectedTables(), 'stocks', 'sales', 'stock_movements', 'cash_sessions', 'cash_adjustments'];
    }

    public function test_cash_sync_creates_one_receipt_and_exact_replay_preserves_original_prices_shift_and_protected_records(): void
    {
        $this->freezeTime();
        $store = $this->store();
        $foreign = $this->store();
        $legacySale = Sale::factory()->create(['tenant_id' => $store['tenant']->id, 'user_id' => $store['user']->id, 'location_id' => $store['location']->id]);
        $legacyBefore = $legacySale->refresh()->getRawOriginal();
        Http::preventStrayRequests();
        Mail::fake();
        Queue::fake([CheckLowStock::class]);
        $data = $this->checkout($store);
        $protected = $this->records($this->protectedTables());
        $foreignSession = $foreign['session']->refresh()->getRawOriginal();

        $saleId = $this->postJson('/api/offline/sales', $data)->assertCreated()
            ->assertJsonPath('subtotal', 750)->assertJsonPath('discount', 75)->assertJsonPath('tax', 81)
            ->assertJsonPath('total', 756)->assertJsonPath('change', 44)->assertJsonPath('cash_session_id', $store['session']->id)
            ->assertJsonPath('offline.recorded_at', $data['recorded_at'])->json('id');
        $this->assertDatabaseHas('stocks', ['id' => $store['stock']->id, 'quantity' => 17]);
        $this->assertDatabaseHas('cash_sessions', ['id' => $store['session']->id, 'cash_sales_cents' => 75600]);
        $this->assertSame($protected, $this->records($this->protectedTables()));
        $store['product']->update(['price_cents' => 30000]);
        $store['tenant']->update(['settings' => array_replace($store['tenant']->settings, ['tax_percent' => 5])]);
        $store['session']->update(['status' => 'closed', 'open_scope' => null, 'closed_at' => now(), 'expected_cash_cents' => 125600, 'counted_cash_cents' => 125600, 'variance_cents' => 0]);
        $replacement = CashSession::factory()->create(['tenant_id' => $store['tenant']->id, 'user_id' => $store['user']->id, 'location_id' => $store['location']->id]);
        $this->travel(3)->days();
        $after = $this->records(['sales', 'stock_movements', 'cash_sessions']);
        $this->postJson('/api/offline/sales', $data)->assertCreated()->assertJsonPath('id', $saleId)
            ->assertJsonPath('total', 756)->assertJsonPath('cash_session_id', $store['session']->id);

        $this->assertSame($after, $this->records(['sales', 'stock_movements', 'cash_sessions']));
        $this->assertDatabaseHas('stocks', ['id' => $foreign['stock']->id, 'quantity' => 20]);
        $this->assertDatabaseHas('cash_sessions', ['id' => $replacement->id, 'cash_sales_cents' => 0]);
        $this->assertSame($foreignSession, $foreign['session']->refresh()->getRawOriginal());
        $this->assertSame($legacyBefore, $legacySale->refresh()->getRawOriginal());
        unset($protected['tenants']);
        $this->assertSame($protected, $this->records(array_keys($protected)));
        Queue::assertPushed(CheckLowStock::class, fn (CheckLowStock $job): bool => $job->tenantId === $store['tenant']->id);
        Queue::assertPushed(CheckLowStock::class, 1);
        Http::assertNothingSent();
        Mail::assertNothingSent();
    }

    public function test_a_sale_recorded_during_the_lease_can_sync_after_cache_expiry_without_rewriting_its_recorded_time(): void
    {
        $this->freezeTime();
        $store = $this->store();
        $data = $this->checkout($store);
        Http::preventStrayRequests();
        Mail::fake();
        $this->travel(3)->days();

        $this->postJson('/api/offline/sales', $data)->assertCreated()->assertJsonPath('offline.recorded_at', $data['recorded_at']);

        $sale = Sale::sole();
        $this->assertSame($data['recorded_at'], $sale->offline_recorded_at->toIso8601String());
        $this->assertSame(now()->toIso8601String(), $sale->offline_synced_at->toIso8601String());
        $this->assertDatabaseHas('stocks', ['id' => $store['stock']->id, 'quantity' => 17]);
        Http::assertNothingSent();
        Mail::assertNothingSent();
    }

    public function test_a_sale_without_a_cached_drawer_remains_unattached_as_201(): void
    {
        $this->freezeTime();
        $store = $this->store();
        $store['session']->update(['status' => 'closed', 'open_scope' => null, 'closed_at' => now()]);
        $data = $this->checkout($store);
        $data['expected']['cash_session_id'] = null;

        $this->postJson('/api/offline/sales', $data)->assertCreated()->assertJsonPath('cash_session_id', null);

        $this->assertDatabaseHas('stocks', ['id' => $store['stock']->id, 'quantity' => 17]);
        $this->assertDatabaseHas('cash_sessions', ['id' => $store['session']->id, 'cash_sales_cents' => 0]);
    }

    public function test_a_new_cash_shift_after_a_drawerless_offline_sale_returns_409_without_assigning_cash_to_it(): void
    {
        $this->freezeTime();
        $store = $this->store();
        $store['session']->update(['status' => 'closed', 'open_scope' => null, 'closed_at' => now()]);
        $data = $this->checkout($store);
        $data['expected']['cash_session_id'] = null;
        CashSession::factory()->create(['tenant_id' => $store['tenant']->id, 'user_id' => $store['user']->id, 'location_id' => $store['location']->id]);
        $before = $this->records($this->transactionalTables());

        $this->postJson('/api/offline/sales', $data)->assertConflict();

        $this->assertSame($before, $this->records($this->transactionalTables()));
    }

    public function test_zero_priced_item_and_zero_cash_tender_sync_once_without_becoming_a_missing_price(): void
    {
        $this->freezeTime();
        $store = $this->store();
        $store['product']->update(['wholesale_price_cents' => 0]);
        $data = $this->checkout($store);
        $data['price_tier'] = 'wholesale';
        $data['payments'][0]['amount'] = 0;
        $data['expected'] = ['items' => [['product_id' => $store['product']->id, 'unit_price_cents' => 0]],
            'subtotal_cents' => 0, 'discount_cents' => 0, 'tax_cents' => 0, 'total_cents' => 0,
            'cash_session_id' => $store['session']->id];

        $saleId = $this->postJson('/api/offline/sales', $data)->assertCreated()->assertJsonPath('total', 0)->assertJsonPath('change', 0)->json('id');
        $this->postJson('/api/offline/sales', $data)->assertCreated()->assertJsonPath('id', $saleId);

        $this->assertDatabaseCount('sales', 1);
        $this->assertDatabaseCount('stock_movements', 1);
        $this->assertDatabaseHas('stocks', ['id' => $store['stock']->id, 'quantity' => 17]);
        $this->assertDatabaseHas('cash_sessions', ['id' => $store['session']->id, 'cash_sales_cents' => 0]);
    }

    /** @return array<string, array{string, int}> */
    public static function changedPrices(): array
    {
        return ['higher price' => ['price_cents', 30000], 'lower price' => ['price_cents', 20000], 'wholesale price' => ['wholesale_price_cents', 18000]];
    }

    #[DataProvider('changedPrices')]
    public function test_changed_price_returns_409_without_stock_or_cash_changes(string $field, int $price): void
    {
        $this->freezeTime();
        $store = $this->store();
        $data = $this->checkout($store);
        if ($field === 'wholesale_price_cents') {
            $data['price_tier'] = 'wholesale';
            $data['expected'] = ['items' => [['product_id' => $store['product']->id, 'unit_price_cents' => 20000]],
                'subtotal_cents' => 60000, 'discount_cents' => 6000, 'tax_cents' => 6480, 'total_cents' => 60480,
                'cash_session_id' => $store['session']->id];
        }
        $store['product']->update([$field => $price]);
        $before = $this->records($this->transactionalTables());
        Queue::fake([CheckLowStock::class]);

        $this->postJson('/api/offline/sales', $data)->assertConflict();

        $this->assertSame($before, $this->records($this->transactionalTables()));
        Queue::assertNothingPushed();
    }

    public function test_tax_change_returns_409_without_partial_writes(): void
    {
        $this->freezeTime();
        $store = $this->store();
        $data = $this->checkout($store);
        $store['tenant']->update(['settings' => array_replace($store['tenant']->settings, ['tax_percent' => 5])]);
        $before = $this->records($this->transactionalTables());

        $this->postJson('/api/offline/sales', $data)->assertConflict();

        $this->assertSame($before, $this->records($this->transactionalTables()));
    }

    public function test_reserved_stock_rejects_offline_overselling_as_422_without_changing_the_drawer(): void
    {
        $this->freezeTime();
        $store = $this->store();
        $data = $this->checkout($store);
        $store['stock']->update(['reserved_quantity' => 18]);
        $before = $this->records($this->transactionalTables());
        Queue::fake([CheckLowStock::class]);

        $this->postJson('/api/offline/sales', $data)->assertUnprocessable()->assertJsonValidationErrors('items');

        $this->assertSame($before, $this->records($this->transactionalTables()));
        Queue::assertNothingPushed();
    }

    public function test_replaced_drawer_returns_409_without_attaching_old_cash_to_the_new_shift(): void
    {
        $this->freezeTime();
        $store = $this->store();
        $data = $this->checkout($store);
        $store['session']->update(['status' => 'closed', 'open_scope' => null, 'closed_at' => now()]);
        CashSession::factory()->create(['tenant_id' => $store['tenant']->id, 'user_id' => $store['user']->id, 'location_id' => $store['location']->id]);
        $before = $this->records($this->transactionalTables());

        $this->postJson('/api/offline/sales', $data)->assertConflict();

        $this->assertSame($before, $this->records($this->transactionalTables()));
    }

    /** @return array<string, array{string}> */
    public static function modifiedReplayFields(): array
    {
        return ['quoted total' => ['total'], 'recorded time' => ['time'], 'quoted drawer' => ['drawer'], 'cash tender' => ['cash']];
    }

    #[DataProvider('modifiedReplayFields')]
    public function test_modified_offline_replay_returns_409_without_a_second_sale_or_stock_deduction(string $field): void
    {
        $this->freezeTime();
        $store = $this->store();
        $data = $this->checkout($store);
        $this->postJson('/api/offline/sales', $data)->assertCreated();
        $changed = $data;
        match ($field) {
            'total' => $changed['expected']['total_cents'] = 75000,
            'time' => $changed['recorded_at'] = now()->addMinute()->toIso8601String(),
            'drawer' => $changed['expected']['cash_session_id'] = null,
            'cash' => $changed['payments'][0]['amount'] = 900,
        };
        $before = $this->records($this->transactionalTables());

        $this->postJson('/api/offline/sales', $changed)->assertConflict();

        $this->assertSame($before, $this->records($this->transactionalTables()));
    }

    public function test_existing_online_checkout_key_cannot_be_claimed_as_an_offline_sale_as_409(): void
    {
        $this->freezeTime();
        $store = $this->store();
        $data = $this->checkout($store);
        $online = $data;
        unset($online['permit'], $online['recorded_at'], $online['expected']);
        $saleId = $this->postJson('/api/sales', $online)->assertCreated()->json('id');
        $before = $this->records($this->transactionalTables());

        $this->postJson('/api/offline/sales', $data)->assertConflict();

        $this->assertSame($before, $this->records($this->transactionalTables()));
        $this->assertDatabaseHas('sales', ['id' => $saleId, 'offline_recorded_at' => null, 'offline_synced_at' => null]);
    }

    public function test_tampered_permit_returns_403_without_changing_any_company_records(): void
    {
        $this->freezeTime();
        $store = $this->store();
        $data = $this->checkout($store);
        $data['permit'] = 'invalid-signed-permit';
        $before = $this->records($this->transactionalTables());

        $this->postJson('/api/offline/sales', $data)->assertForbidden();

        $this->assertSame($before, $this->records($this->transactionalTables()));
    }

    public function test_another_cashier_cannot_submit_the_original_cashiers_permit_as_403(): void
    {
        $this->freezeTime();
        $store = $this->store();
        $data = $this->checkout($store);
        $other = User::factory()->create(['tenant_id' => $store['tenant']->id, 'role' => 'cashier']);
        $before = $this->records($this->transactionalTables());

        $this->actingAs($other)->postJson('/api/offline/sales', $data)->assertForbidden();

        $this->assertSame($before, $this->records($this->transactionalTables()));
    }

    /** @return array<string, array{string}> */
    public static function invalidRecordingTimes(): array
    {
        return ['before snapshot' => ['before'], 'after lease' => ['expired'], 'future time' => ['future']];
    }

    #[DataProvider('invalidRecordingTimes')]
    public function test_recording_outside_the_permitted_window_returns_422_without_stock_or_cash_changes(string $kind): void
    {
        $this->freezeTime();
        $store = $this->store();
        $data = $this->checkout($store);
        $data['recorded_at'] = match ($kind) {
            'before' => now()->subMinute()->toIso8601String(),
            'expired' => now()->addHours(25)->toIso8601String(),
            'future' => now()->addMinutes(6)->toIso8601String(),
        };
        if ($kind === 'expired') {
            $this->travel(26)->hours();
        }
        $before = $this->records($this->transactionalTables());

        $this->postJson('/api/offline/sales', $data)->assertUnprocessable()->assertJsonValidationErrors(['recorded_at' => 'This cash sale was recorded outside the offline snapshot period. Review it before syncing.']);

        $this->assertSame($before, $this->records($this->transactionalTables()));
    }

    /** @return array<string, array{string}> */
    public static function foreignLinks(): array
    {
        return ['location' => ['location'], 'product' => ['product']];
    }

    #[DataProvider('foreignLinks')]
    public function test_foreign_catalog_links_return_404_without_partial_writes(string $field): void
    {
        $this->freezeTime();
        $store = $this->store();
        $foreign = $this->store();
        $data = $this->checkout($store);
        if ($field === 'location') {
            $data['location_id'] = $foreign['location']->id;
        } else {
            $data['items'][0]['product_id'] = $foreign['product']->id;
            $data['expected']['items'][0]['product_id'] = $foreign['product']->id;
        }
        $before = $this->records($this->transactionalTables());

        $this->postJson('/api/offline/sales', $data)->assertNotFound();

        $this->assertSame($before, $this->records($this->transactionalTables()));
    }

    public function test_cash_only_sync_rejects_customer_and_external_tender_as_422(): void
    {
        $this->freezeTime();
        $store = $this->store();
        $data = $this->checkout($store);
        $data['customer_id'] = 1;
        $data['payments'] = [['method' => 'card', 'amount' => 800]];
        $before = $this->records($this->transactionalTables());
        Http::preventStrayRequests();
        Mail::fake();

        $this->postJson('/api/offline/sales', $data)->assertUnprocessable()->assertJsonValidationErrors(['customer_id', 'payments.0.method']);

        $this->assertSame($before, $this->records($this->transactionalTables()));
        Http::assertNothingSent();
        Mail::assertNothingSent();
    }

    public function test_paused_staff_cannot_sync_a_previously_issued_permit_as_403(): void
    {
        $this->freezeTime();
        $store = $this->store();
        $data = $this->checkout($store);
        $store['user']->update(['is_active' => false]);
        $before = $this->records(array_diff($this->transactionalTables(), ['users']));

        $this->postJson('/api/offline/sales', $data)->assertForbidden();

        $this->assertSame($before, $this->records(array_keys($before)));
        $this->assertDatabaseHas('users', ['id' => $store['user']->id, 'is_active' => false]);
    }

    public function test_paused_company_cannot_sync_a_previously_issued_permit_as_423(): void
    {
        $this->freezeTime();
        $store = $this->store();
        $data = $this->checkout($store);
        $store['tenant']->update(['operations_paused' => true]);
        $before = $this->records($this->transactionalTables());

        $this->postJson('/api/offline/sales', $data)->assertStatus(423);

        $this->assertSame($before, $this->records($this->transactionalTables()));
    }

    public function test_late_drawer_failure_rolls_back_every_write_before_same_key_recovery(): void
    {
        $this->freezeTime();
        $store = $this->store();
        $data = $this->checkout($store);
        $before = $this->records($this->transactionalTables());
        config(['queue.default' => 'database']);
        Exceptions::fake();
        DB::unprepared("CREATE TRIGGER reject_offline_cash_attach BEFORE UPDATE OF cash_session_id ON sales BEGIN SELECT RAISE(ABORT, 'Isolated offline attachment failure'); END");

        $this->postJson('/api/offline/sales', $data)->assertStatus(503)
            ->assertJsonPath('message', 'The API is temporarily unavailable. Try again using the same request key.')
            ->assertDontSee('Isolated offline attachment failure');

        $this->assertSame($before, $this->records($this->transactionalTables()));
        $this->assertDatabaseCount('jobs', 0);
        DB::unprepared('DROP TRIGGER reject_offline_cash_attach');
        $this->postJson('/api/offline/sales', $data)->assertCreated()->assertJsonPath('total', 756);
        $this->assertDatabaseCount('sales', 1);
        $this->assertDatabaseCount('stock_movements', 1);
        $this->assertDatabaseHas('stocks', ['id' => $store['stock']->id, 'quantity' => 17]);
        $this->assertDatabaseHas('cash_sessions', ['id' => $store['session']->id, 'cash_sales_cents' => 75600]);
        $this->assertDatabaseCount('jobs', 1);
        Exceptions::assertReported(QueryException::class);
    }
}
