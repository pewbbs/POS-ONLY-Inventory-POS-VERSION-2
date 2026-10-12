<?php

namespace Tests\Feature;

use App\Jobs\CheckLowStock;
use App\Models\BillingInvoice;
use App\Models\Customer;
use App\Models\Location;
use App\Models\PlatformAdmin;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\Sale;
use App\Models\Stock;
use App\Models\Subscription;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Models\User;
use App\Services\BillingService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\PersonalAccessToken;
use PHPUnit\Framework\Attributes\TestWith;
use RuntimeException;
use Tests\TestCase;

class MobileApiTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['mail.default' => 'array', 'paymongo.enabled' => false, 'paymongo.secret_key' => '', 'paymongo.webhook_secret' => '', 'billing.simulations_enabled' => false]);
    }

    /** @return array{tenant: Tenant, user: User, location: Location, branch: Location, product: Product, stock: Stock, subscription: Subscription} */
    private function store(string $role = 'admin'): array
    {
        $tenant = Tenant::factory()->create(['settings' => array_replace(Tenant::defaultSettings(), ['tax_percent' => 12])]);
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'role' => $role]);
        $location = Location::factory()->create(['tenant_id' => $tenant->id]);
        $branch = Location::factory()->create(['tenant_id' => $tenant->id]);
        $product = Product::factory()->create(['tenant_id' => $tenant->id, 'price_cents' => 10000, 'wholesale_price_cents' => 8000, 'cost_cents' => 5000, 'reorder_level' => 0, 'barcode' => 'MOBILE-'.$tenant->id]);
        $stock = Stock::factory()->create(['tenant_id' => $tenant->id, 'product_id' => $product->id, 'location_id' => $location->id, 'quantity' => 20, 'reserved_quantity' => 0]);
        Stock::factory()->create(['tenant_id' => $tenant->id, 'product_id' => $product->id, 'location_id' => $branch->id, 'quantity' => 0, 'reserved_quantity' => 0]);
        $subscription = Subscription::factory()->create(['tenant_id' => $tenant->id]);

        return compact('tenant', 'user', 'location', 'branch', 'product', 'stock', 'subscription');
    }

    /** @param array<int, string>|null $abilities */
    private function device(User $user, ?array $abilities = null): string
    {
        return $user->createToken('QA phone', $abilities ?? config('mobile-api.abilities.'.$user->role), now()->addDays(30))->plainTextToken;
    }

    private function bearer(string $token): static
    {
        Auth::forgetGuards();

        return $this->withHeader('Authorization', 'Bearer '.$token);
    }

    /** @param array{location: Location, product: Product} $store
     * @return array<string, mixed>
     */
    private function saleData(array $store, string $key = 'mobile-sale-retry'): array
    {
        return ['location_id' => $store['location']->id, 'price_tier' => 'wholesale', 'items' => [['product_id' => $store['product']->id, 'quantity' => 2]],
            'tax_percent' => 12, 'payments' => [['method' => 'cash', 'amount' => 200]], 'idempotency_key' => $key];
    }

    public function test_login_issues_hashed_expiring_role_scopes_without_browser_sessions(): void
    {
        $this->freezeTime();
        $store = $this->store('cashier');
        Http::preventStrayRequests();
        $sessions = DB::table('sessions')->count();

        $response = $this->postJson('/api/v1/auth/token', ['email' => ' '.strtoupper($store['user']->email).' ', 'password' => 'password', 'device_name' => 'Store phone'])
            ->assertCreated()->assertJsonPath('token_type', 'Bearer')->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeaderMissing('Set-Cookie');
        $token = $response->json('token');
        $record = PersonalAccessToken::findToken($token);
        $this->assertSame(hash('sha256', explode('|', $token, 2)[1]), $record->token);
        $this->assertSame(now()->addDays(30)->toIso8601String(), $response->json('expires_at'));
        $this->assertContains('sales:write', $response->json('abilities'));
        $this->assertNotContains('inventory:write', $response->json('abilities'));
        $this->assertNotContains('*', $response->json('abilities'));
        $this->assertSame($sessions, DB::table('sessions')->count());
        $this->bearer($token)->getJson('/api/v1/me')->assertOk()->assertJsonPath('user.id', $store['user']->id)->assertJsonPath('user.role', 'cashier')
            ->assertJsonMissingPath('user.password')->assertJsonMissingPath('user.remember_token');
        Http::assertNothingSent();
    }

    public function test_login_refuses_client_selected_scopes_and_company(): void
    {
        $store = $this->store();

        $this->postJson('/api/v1/auth/token', ['email' => $store['user']->email, 'password' => 'password', 'device_name' => 'Phone', 'abilities' => ['*'], 'scopes' => ['billing:write'], 'tenant_id' => 500])
            ->assertUnprocessable()->assertJsonValidationErrors(['abilities', 'scopes', 'tenant_id']);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_login_with_malformed_email_returns_validation_errors_instead_of_an_api_failure(): void
    {
        $this->postJson('/api/v1/auth/token', ['email' => ['unexpected'], 'password' => 'password', 'device_name' => 'Phone'])
            ->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    #[TestWith(['unknown'])]
    #[TestWith(['wrong_password'])]
    #[TestWith(['paused'])]
    #[TestWith(['no_company'])]
    #[TestWith(['developer'])]
    public function test_ineligible_credentials_return_422_without_issuing_a_token(string $case): void
    {
        $user = User::factory()->create(['email' => 'member@example.test', 'is_active' => $case !== 'paused', 'tenant_id' => $case === 'no_company' ? null : Tenant::factory()]);
        if ($case === 'developer') {
            PlatformAdmin::factory()->create(['email' => 'developer@example.test']);
        }
        $email = match ($case) {
            'unknown' => 'missing@example.test', 'developer' => 'developer@example.test', default => $user->email,
        };

        $this->postJson('/api/v1/auth/token', ['email' => $email, 'password' => $case === 'wrong_password' ? 'incorrect' : 'password', 'device_name' => 'Phone'])
            ->assertUnprocessable()->assertJsonValidationErrors(['email' => 'The email or password is incorrect.']);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_login_throttles_repeated_requests_with_429(): void
    {
        for ($attempt = 0; $attempt < 6; $attempt++) {
            $this->postJson('/api/v1/auth/token', ['email' => 'missing@example.test', 'password' => 'incorrect', 'device_name' => 'Phone'])->assertUnprocessable();
        }

        $this->postJson('/api/v1/auth/token', ['email' => 'missing@example.test', 'password' => 'incorrect', 'device_name' => 'Phone'])->assertTooManyRequests();
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_device_limit_returns_409_and_expired_devices_release_capacity(): void
    {
        $this->freezeTime();
        $store = $this->store();
        config(['mobile-api.max_devices' => 2]);
        $first = $store['user']->createToken('First', ['account:read'], now()->addDay());
        $this->device($store['user']);
        $data = ['email' => $store['user']->email, 'password' => 'password', 'device_name' => 'Replacement'];

        $this->postJson('/api/v1/auth/token', $data)->assertConflict()->assertJsonPath('message', 'Your account has reached its device limit. Revoke an unused device before signing in.');
        $first->accessToken->update(['expires_at' => now()->subSecond()]);
        $this->postJson('/api/v1/auth/token', $data)->assertCreated();
        $this->assertModelMissing($first->accessToken);
        $this->assertDatabaseCount('personal_access_tokens', 2);
    }

    public function test_browser_sessions_invalid_bearers_and_url_tokens_cannot_authenticate_mobile_api(): void
    {
        $store = $this->store();
        $this->actingAs($store['user'], 'web')->getJson('/api/v1/me')->assertUnauthorized()->assertHeader('Cache-Control', 'no-store, private');
        $this->bearer('invalid-token')->getJson('/api/v1/products')->assertUnauthorized();
        $token = $this->device($store['user']);

        $this->bearer($token)->getJson('/api/v1/me?access_token=untrusted')->assertBadRequest()->assertJsonPath('message', 'Send API tokens only in the Authorization header.');
        $this->assertDatabaseCount('sales', 0);
    }

    public function test_device_expiry_and_global_maximum_expiry_return_401(): void
    {
        $this->freezeTime();
        $store = $this->store();
        $expired = $store['user']->createToken('Expired', ['account:read'], now()->subSecond());
        $old = $store['user']->createToken('Over maximum', ['account:read'], now()->addDays(100));
        $old->accessToken->forceFill(['created_at' => now()->subDays(31)])->save();

        $this->bearer($expired->plainTextToken)->getJson('/api/v1/me')->assertUnauthorized();
        $this->bearer($old->plainTextToken)->getJson('/api/v1/me')->assertUnauthorized();
    }

    public function test_device_listing_and_revocation_are_owned_by_the_user_and_never_expose_secrets(): void
    {
        $store = $this->store();
        $foreign = User::factory()->create();
        $current = $this->device($store['user']);
        $other = $store['user']->createToken('Other phone', ['account:read'], now()->addDay());
        $foreignToken = $foreign->createToken('Foreign phone', ['account:read'], now()->addDay());

        $list = $this->bearer($current)->getJson('/api/v1/devices')->assertOk()->assertJsonCount(2, 'data')->json('data');
        $this->assertSame(['id', 'name', 'current', 'created_at', 'last_used_at', 'expires_at'], array_keys($list[0]));
        $this->assertSame('Other phone', $list[0]['name']);
        $this->bearer($current)->deleteJson('/api/v1/devices/'.$foreignToken->accessToken->id)->assertNotFound();
        $this->bearer($current)->deleteJson('/api/v1/devices/'.$other->accessToken->id)->assertNoContent();
        $this->assertModelMissing($other->accessToken);
        $this->assertModelExists($foreignToken->accessToken);
        $this->bearer($current)->deleteJson('/api/v1/auth/token')->assertNoContent();
        $this->bearer($current)->getJson('/api/v1/me')->assertUnauthorized();
    }

    public function test_scopes_and_live_roles_both_restrict_actions_and_cashier_costs(): void
    {
        $store = $this->store();
        $readOnly = $this->device($store['user'], ['catalog:read']);
        $admin = $this->device($store['user']);

        $this->bearer($readOnly)->getJson('/api/v1/products')->assertOk()->assertJsonPath('data.0.cost', 50);
        $this->bearer($readOnly)->postJson('/api/v1/stock/adjust', [])->assertForbidden();
        $store['user']->update(['role' => 'cashier']);
        $this->bearer($admin)->getJson('/api/v1/products')->assertOk()->assertJsonPath('data.0.cost', 0);
        $this->bearer($admin)->getJson('/api/v1/reports/financial')->assertForbidden();
        $this->bearer($admin)->postJson('/api/v1/suppliers', ['name' => 'Forbidden'])->assertForbidden();
        $this->assertDatabaseCount('suppliers', 0);
        $this->assertDatabaseHas('stocks', ['id' => $store['stock']->id, 'quantity' => 20]);
    }

    public function test_paused_staff_tokens_are_rejected_and_revoked_on_every_request(): void
    {
        $store = $this->store();
        $token = $this->device($store['user']);
        $this->device($store['user']);
        $store['user']->update(['is_active' => false]);

        $this->bearer($token)->getJson('/api/v1/me')->assertForbidden()->assertJsonPath('message', 'Your workspace access is paused. Ask an administrator to reactivate your account.');
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->bearer($token)->getJson('/api/v1/me')->assertUnauthorized();
    }

    #[TestWith(['role', 'cashier'])]
    #[TestWith(['is_active', false])]
    public function test_team_role_or_access_change_revokes_only_that_staff_members_devices(string $field, mixed $value): void
    {
        $store = $this->store();
        $member = User::factory()->create(['tenant_id' => $store['tenant']->id, 'role' => 'manager']);
        $memberToken = $this->device($member);
        $adminToken = $this->device($store['user']);
        $foreign = User::factory()->create();
        $foreignToken = $this->device($foreign);

        $this->actingAs($store['user'], 'web')->patchJson('/api/team/'.$member->id, [$field => $value])->assertOk();
        $this->assertDatabaseCount('personal_access_tokens', 2);
        $this->bearer($memberToken)->getJson('/api/v1/me')->assertUnauthorized();
        $this->bearer($adminToken)->getJson('/api/v1/me')->assertOk();
        $this->bearer($foreignToken)->getJson('/api/v1/me')->assertOk()->assertJsonPath('user.id', $foreign->id);
    }

    public function test_password_reset_revokes_only_the_reset_accounts_devices(): void
    {
        $store = $this->store();
        $token = $this->device($store['user']);
        $this->device($store['user']);
        $foreign = User::factory()->create();
        $foreignToken = $this->device($foreign);
        $reset = Password::createToken($store['user']);

        $this->post('/reset-password', ['email' => $store['user']->email, 'token' => $reset, 'password' => 'private-recovery-password', 'password_confirmation' => 'private-recovery-password'])->assertRedirect(route('login'));
        $this->assertTrue(Hash::check('private-recovery-password', $store['user']->fresh()->password));
        $this->assertDatabaseCount('personal_access_tokens', 1);
        $this->bearer($token)->getJson('/api/v1/me')->assertUnauthorized();
        $this->bearer($foreignToken)->getJson('/api/v1/me')->assertOk()->assertJsonPath('user.id', $foreign->id);
    }

    public function test_bootstrap_and_read_endpoints_preserve_expired_billing_records_and_do_not_expose_provider_configuration(): void
    {
        $this->freezeTime();
        $store = $this->store();
        $store['subscription']->update(['trial_ends_at' => now()->subDays(10)]);
        BillingInvoice::factory()->create(['tenant_id' => $store['tenant']->id, 'subscription_id' => $store['subscription']->id, 'status' => 'paid']);
        Customer::factory()->create(['tenant_id' => $store['tenant']->id]);
        Supplier::factory()->create(['tenant_id' => $store['tenant']->id]);
        $tables = ['tenants', 'subscriptions', 'billing_invoices', 'billing_payment_attempts', 'paymongo_webhook_events'];
        $before = $this->records($tables);
        $token = $this->device($store['user']);
        Http::preventStrayRequests();

        $bootstrap = $this->bearer($token)->getJson('/api/v1/bootstrap')->assertOk()->assertJsonPath('access.can_write', false)->assertJsonPath('tenant.id', $store['tenant']->id)->assertJsonCount(2, 'locations')->json();
        $this->assertSame(['user', 'tenant', 'abilities', 'api_version', 'server_time', 'locations', 'settings', 'access', 'offline'], array_keys($bootstrap));
        $this->assertArrayNotHasKey('plan', $bootstrap['settings']);
        foreach (['/products', '/customers', '/suppliers', '/purchase-orders', '/sales', '/reports/analytics', '/reports/financial', '/offline/snapshot'] as $path) {
            $this->bearer($token)->getJson('/api/v1'.$path)->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        }
        $this->assertSame($before, $this->records($tables));
        Http::assertNothingSent();
    }

    public function test_bootstrap_does_not_create_a_missing_subscription(): void
    {
        $user = User::factory()->create();
        $token = $this->device($user);

        $this->bearer($token)->getJson('/api/v1/bootstrap')->assertOk()->assertJsonPath('access.can_write', false);
        $this->assertDatabaseCount('subscriptions', 0);
        $this->assertDatabaseCount('billing_invoices', 0);
    }

    public function test_catalog_contacts_and_purchases_are_paginated_searchable_and_company_scoped(): void
    {
        $store = $this->store();
        $foreign = $this->store();
        $customer = Customer::factory()->create(['tenant_id' => $store['tenant']->id, 'name' => 'Searchable customer']);
        Customer::factory()->create(['tenant_id' => $foreign['tenant']->id, 'name' => 'Searchable customer']);
        $supplier = Supplier::factory()->create(['tenant_id' => $store['tenant']->id, 'name' => 'Searchable supplier']);
        Supplier::factory()->create(['tenant_id' => $foreign['tenant']->id, 'name' => 'Searchable supplier']);
        $order = PurchaseOrder::factory()->create(['tenant_id' => $store['tenant']->id, 'user_id' => $store['user']->id, 'supplier_id' => $supplier->id, 'location_id' => $store['location']->id]);
        $foreignOrder = PurchaseOrder::factory()->create(['tenant_id' => $foreign['tenant']->id]);
        $token = $this->device($store['user']);

        $this->bearer($token)->getJson('/api/v1/products?barcode='.$store['product']->barcode.'&per_page=1')->assertOk()->assertJsonPath('data.0.id', $store['product']->id)->assertJsonPath('meta.total', 1);
        $this->bearer($token)->getJson('/api/v1/products/'.$foreign['product']->id)->assertNotFound();
        $this->bearer($token)->getJson('/api/v1/products?per_page=101')->assertUnprocessable()->assertJsonValidationErrors('per_page');
        $this->bearer($token)->getJson('/api/v1/customers?q=Searchable&per_page=1')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $customer->id);
        $this->bearer($token)->getJson('/api/v1/suppliers?q=Searchable')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $supplier->id);
        $this->bearer($token)->getJson('/api/v1/purchase-orders?per_page=1')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $order->id);
        $this->bearer($token)->getJson('/api/v1/purchase-orders/'.$foreignOrder->id)->assertNotFound();
        $this->bearer($token)->getJson('/api/v1/purchase-orders?location_id='.$foreign['location']->id)->assertNotFound();
    }

    public function test_mobile_mutations_require_request_keys_without_changing_stock(): void
    {
        $store = $this->store();
        $token = $this->device($store['user']);

        foreach (['/stock/adjust', '/stock/transfer', '/purchase-orders', '/sales', '/cash-sessions/open', '/offline/sales'] as $path) {
            $this->bearer($token)->postJson('/api/v1'.$path, [])->assertUnprocessable()->assertJsonValidationErrors('idempotency_key');
        }
        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertDatabaseCount('sales', 0);
        $this->assertDatabaseHas('stocks', ['id' => $store['stock']->id, 'quantity' => 20]);
    }

    public function test_cashier_history_is_own_only_and_foreign_checkout_links_return_404(): void
    {
        $store = $this->store('cashier');
        $foreign = $this->store();
        $otherCashier = User::factory()->create(['tenant_id' => $store['tenant']->id, 'role' => 'cashier']);
        $sale = Sale::factory()->create(['tenant_id' => $store['tenant']->id, 'user_id' => $otherCashier->id]);
        $token = $this->device($store['user']);
        Http::preventStrayRequests();
        Queue::fake([CheckLowStock::class]);

        $this->bearer($token)->getJson('/api/v1/sales')->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('filters.scope', 'own_sales');
        $this->bearer($token)->getJson('/api/v1/sales/'.$sale->id)->assertNotFound();
        $payload = $this->saleData($store);
        $payload['location_id'] = $foreign['location']->id;
        $this->bearer($token)->postJson('/api/v1/sales', $payload)->assertNotFound();
        $this->assertDatabaseCount('sales', 1);
        $this->assertDatabaseHas('stocks', ['id' => $store['stock']->id, 'quantity' => 20]);
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_mobile_checkout_retry_return_stock_purchase_and_drawer_use_existing_server_rules(): void
    {
        $this->freezeTime();
        $store = $this->store('manager');
        $token = $this->device($store['user']);
        Http::preventStrayRequests();
        Queue::fake([CheckLowStock::class]);
        $companyAndBilling = $this->records(['tenants', 'subscriptions', 'billing_invoices', 'billing_payment_attempts', 'paymongo_webhook_events']);
        $customerId = $this->bearer($token)->postJson('/api/v1/customers', ['name' => 'Mobile customer', 'tenant_id' => 999])->assertCreated()->json('id');
        $supplierId = $this->bearer($token)->postJson('/api/v1/suppliers', ['name' => 'Mobile supplier'])->assertCreated()->json('id');
        $sessionId = $this->bearer($token)->postJson('/api/v1/cash-sessions/open', ['location_id' => $store['location']->id, 'opening_float' => 50, 'idempotency_key' => 'mobile-open-drawer'])->assertCreated()->json('id');
        $payload = $this->saleData($store) + ['customer_id' => $customerId];

        $saleId = $this->bearer($token)->postJson('/api/v1/sales', $payload)->assertCreated()->assertJsonPath('total', 179.2)->assertJsonPath('change', 20.8)->assertJsonPath('cash_session_id', $sessionId)->json('id');
        $this->bearer($token)->postJson('/api/v1/sales', $payload)->assertCreated()->assertJsonPath('id', $saleId);
        $changed = $payload;
        $changed['items'][0]['quantity'] = 1;
        $this->bearer($token)->postJson('/api/v1/sales', $changed)->assertConflict();
        $this->assertDatabaseHas('stocks', ['id' => $store['stock']->id, 'quantity' => 18]);
        $return = ['items' => [['product_id' => $store['product']->id, 'quantity' => 1]], 'reason' => 'Mobile return', 'idempotency_key' => 'mobile-return-one'];
        $this->bearer($token)->postJson('/api/v1/sales/'.$saleId.'/return', $return)->assertOk()->assertJsonPath('returned_amount', 89.6);
        $this->bearer($token)->postJson('/api/v1/sales/'.$saleId.'/return', $return)->assertOk()->assertJsonCount(1, 'returns');
        $adjustment = ['product_id' => $store['product']->id, 'location_id' => $store['location']->id, 'quantity' => 3, 'reason' => 'Mobile count', 'idempotency_key' => 'mobile-adjust-stock'];
        $this->bearer($token)->postJson('/api/v1/stock/adjust', $adjustment)->assertOk();
        $this->bearer($token)->postJson('/api/v1/stock/adjust', $adjustment)->assertOk();
        $transfer = ['product_id' => $store['product']->id, 'from_location_id' => $store['location']->id, 'to_location_id' => $store['branch']->id, 'quantity' => 2, 'idempotency_key' => 'mobile-transfer-stock'];
        $this->bearer($token)->postJson('/api/v1/stock/transfer', $transfer)->assertOk();
        $purchase = ['supplier_id' => $supplierId, 'location_id' => $store['location']->id, 'items' => [['product_id' => $store['product']->id, 'quantity' => 3, 'cost' => 60]], 'idempotency_key' => 'mobile-create-purchase'];
        $orderId = $this->bearer($token)->postJson('/api/v1/purchase-orders', $purchase)->assertCreated()->assertJsonPath('total', 180)->json('id');
        $delivery = ['items' => [['product_id' => $store['product']->id, 'quantity' => 3]], 'idempotency_key' => 'mobile-receive-purchase'];
        $this->bearer($token)->postJson('/api/v1/purchase-orders/'.$orderId.'/receive', $delivery)->assertOk()->assertJsonPath('status', 'received');
        $this->bearer($token)->postJson('/api/v1/purchase-orders/'.$orderId.'/receive', $delivery)->assertOk()->assertJsonCount(1, 'receipts');
        $this->bearer($token)->postJson('/api/v1/cash-sessions/'.$sessionId.'/adjustments', ['direction' => 'in', 'amount' => 10, 'reason' => 'Drawer addition', 'idempotency_key' => 'mobile-cash-adjust'])->assertOk();
        $this->bearer($token)->postJson('/api/v1/cash-sessions/'.$sessionId.'/close', ['counted_cash' => 239.2, 'reason' => 'Mobile close', 'idempotency_key' => 'mobile-close-drawer'])->assertOk()->assertJsonPath('variance', 0);
        $this->assertDatabaseHas('stocks', ['id' => $store['stock']->id, 'quantity' => 23]);
        $this->assertDatabaseHas('stocks', ['product_id' => $store['product']->id, 'location_id' => $store['branch']->id, 'quantity' => 2]);
        $this->assertDatabaseCount('sales', 1);
        $this->assertDatabaseCount('sale_returns', 1);
        $this->assertDatabaseCount('purchase_receipts', 1);
        $this->assertDatabaseHas('customers', ['id' => $customerId, 'tenant_id' => $store['tenant']->id]);
        $this->assertDatabaseHas('activity_logs', ['action' => 'checkout.confirmed', 'resource_id' => $saleId, 'actor_id' => $store['user']->id]);
        $this->assertDatabaseHas('activity_logs', ['action' => 'delivery.confirmed', 'resource_id' => $orderId]);
        $this->assertSame($companyAndBilling, $this->records(['tenants', 'subscriptions', 'billing_invoices', 'billing_payment_attempts', 'paymongo_webhook_events']));
        Queue::assertPushed(CheckLowStock::class, fn (CheckLowStock $job): bool => $job->tenantId === $store['tenant']->id);
        Http::assertNothingSent();
    }

    public function test_mobile_billing_developer_and_web_session_endpoints_are_not_token_accessible(): void
    {
        $store = $this->store();
        $token = $this->device($store['user']);

        $this->bearer($token)->getJson('/api/v1/billing')->assertNotFound();
        $this->bearer($token)->postJson('/api/v1/billing/checkout', [])->assertNotFound();
        $this->bearer($token)->getJson('/api/v1/developer/companies')->assertNotFound();
        $this->bearer($token)->getJson('/api/billing')->assertUnauthorized();
        $this->bearer($token)->postJson('/api/sales', $this->saleData($store))->assertUnauthorized();
        $this->assertDatabaseCount('sales', 0);
        $this->assertDatabaseCount('billing_payment_attempts', 0);
    }

    public function test_mobile_catalog_writes_and_purchase_approval_and_cancellation_remain_role_scoped(): void
    {
        $store = $this->store('manager');
        $token = $this->device($store['user']);
        $foreign = $this->store();
        $supplier = Supplier::factory()->create(['tenant_id' => $store['tenant']->id]);
        $foreignOrder = PurchaseOrder::factory()->create(['tenant_id' => $foreign['tenant']->id]);
        Http::preventStrayRequests();
        Queue::fake([CheckLowStock::class]);
        $data = ['name' => 'Mobile product', 'sku' => 'MOBILE-CREATED', 'barcode' => '1234567890', 'category' => 'Retail',
            'price' => 200, 'wholesale_price' => 150, 'cost' => 100, 'reorder_level' => 0, 'location_id' => $store['location']->id, 'quantity' => 4];

        $productId = $this->bearer($token)->postJson('/api/v1/products', $data)->assertCreated()->assertJsonPath('price', 200)->assertJsonPath('wholesale_price', 150)->json('id');
        $this->assertDatabaseHas('stocks', ['product_id' => $productId, 'location_id' => $store['location']->id, 'quantity' => 4]);
        $this->bearer($token)->putJson('/api/v1/products/'.$productId, array_replace($data, ['price' => 250]))->assertOk()->assertJsonPath('price', 250);
        $purchase = ['supplier_id' => $supplier->id, 'location_id' => $store['location']->id, 'status' => 'draft',
            'items' => [['product_id' => $productId, 'quantity' => 5]], 'idempotency_key' => 'mobile-draft-purchase'];
        $orderId = $this->bearer($token)->postJson('/api/v1/purchase-orders', $purchase)->assertCreated()->assertJsonPath('status', 'draft')->json('id');
        $this->bearer($token)->postJson('/api/v1/purchase-orders/'.$orderId.'/approve', [])->assertOk()->assertJsonPath('status', 'ordered');
        $this->bearer($token)->postJson('/api/v1/purchase-orders/'.$orderId.'/cancel', [])->assertOk()->assertJsonPath('status', 'cancelled');
        $this->bearer($token)->postJson('/api/v1/purchase-orders/'.$foreignOrder->id.'/cancel', [])->assertNotFound();
        $this->assertDatabaseHas('purchase_orders', ['id' => $orderId, 'status' => 'cancelled']);
        $this->assertDatabaseHas('products', ['id' => $productId, 'tenant_id' => $store['tenant']->id, 'price_cents' => 25000]);
        $this->assertDatabaseHas('activity_logs', ['action' => 'product.created', 'resource_id' => $productId]);
        Queue::assertPushed(CheckLowStock::class);
        Http::assertNothingSent();
    }

    public function test_mobile_offline_cash_sync_requires_write_scope_and_replays_without_billing_mutations(): void
    {
        $this->freezeTime();
        $store = $this->store();
        $token = $this->device($store['user']);
        $readOnly = $this->device($store['user'], ['catalog:read']);
        Http::preventStrayRequests();
        Queue::fake([CheckLowStock::class]);
        $snapshot = $this->bearer($token)->getJson('/api/v1/offline/snapshot')->assertOk()->json();
        $payload = $this->saleData($store, 'mobile-offline-cash') + ['discount_percent' => 0, 'permit' => $snapshot['permit'], 'recorded_at' => now()->toIso8601String(),
            'expected' => ['items' => [['product_id' => $store['product']->id, 'unit_price_cents' => 8000]], 'subtotal_cents' => 16000, 'discount_cents' => 0, 'tax_cents' => 1920, 'total_cents' => 17920, 'cash_session_id' => null]];
        $before = $this->records(['tenants', 'subscriptions', 'billing_invoices', 'billing_payment_attempts', 'paymongo_webhook_events']);

        $this->bearer($readOnly)->postJson('/api/v1/offline/sales', $payload)->assertForbidden();
        $saleId = $this->bearer($token)->postJson('/api/v1/offline/sales', $payload)->assertCreated()->assertJsonPath('total', 179.2)->json('id');
        $store['subscription']->update(['trial_ends_at' => now()->subDays(10)]);
        $expired = $this->records(['subscriptions', 'billing_invoices', 'billing_payment_attempts', 'paymongo_webhook_events']);
        $this->bearer($token)->postJson('/api/v1/offline/sales', $payload)->assertCreated()->assertJsonPath('id', $saleId);
        $this->assertSame($expired, $this->records(['subscriptions', 'billing_invoices', 'billing_payment_attempts', 'paymongo_webhook_events']));
        $this->assertSame($before['tenants'], $this->records(['tenants'])['tenants']);
        $this->assertDatabaseCount('sales', 1);
        $this->assertDatabaseHas('stocks', ['id' => $store['stock']->id, 'quantity' => 18]);
        $this->assertDatabaseHas('activity_logs', ['action' => 'checkout.confirmed', 'resource_id' => $saleId]);
        Queue::assertPushed(CheckLowStock::class);
        Http::assertNothingSent();
    }

    public function test_production_transport_requires_https_before_any_credentials_are_processed(): void
    {
        app()->instance('env', 'production');

        $this->postJson('http://localhost/api/v1/auth/token', ['email' => 'member@example.test', 'password' => 'password', 'device_name' => 'Phone'])->assertBadRequest()->assertJsonPath('message', 'Use HTTPS to access the mobile API.');
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_unexpected_api_failures_have_safe_json_and_no_store_headers(): void
    {
        $store = $this->store();
        $token = $this->device($store['user']);
        $this->mock(BillingService::class)->shouldReceive('describeSubscription')->once()->andThrow(new RuntimeException('private database credential'));

        $this->bearer($token)->getJson('/api/v1/bootstrap')->assertServiceUnavailable()->assertExactJson(['message' => 'The API is temporarily unavailable. Try again using the same request key.'])
            ->assertHeader('Cache-Control', 'no-store, private')->assertHeader('Referrer-Policy', 'no-referrer');
    }

    /** @param array<int, string> $tables
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function records(array $tables): array
    {
        $result = [];
        foreach ($tables as $table) {
            $result[$table] = DB::table($table)->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all();
        }

        return $result;
    }
}
