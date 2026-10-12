<?php

namespace Tests\Feature;

use App\Models\BillingInvoice;
use App\Models\CashSession;
use App\Models\Customer;
use App\Models\Location;
use App\Models\Product;
use App\Models\ProductFamily;
use App\Models\Sale;
use App\Models\Stock;
use App\Models\Subscription;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class OfflineSnapshotTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_guest_cannot_download_private_offline_catalog_as_401(): void
    {
        $this->getJson('/api/offline/snapshot')->assertUnauthorized();
    }

    /** @return array<string, array{string}> */
    public static function roles(): array
    {
        return ['administrator' => ['admin'], 'manager' => ['manager'], 'cashier' => ['cashier']];
    }

    #[DataProvider('roles')]
    public function test_snapshot_exposes_only_scoped_catalog_and_own_open_drawers_without_private_contacts_or_billing(string $role): void
    {
        $this->freezeTime();
        $tenant = Tenant::factory()->create(['name' => 'Offline company', 'settings' => array_replace(Tenant::defaultSettings(),
            ['tax_percent' => 12, 'receipt_footer' => 'Thank you', 'receipt_address' => 'PRIVATE OFFICE ADDRESS', 'receipt_contact' => 'PRIVATE PHONE'])]);
        $actor = User::factory()->create(['tenant_id' => $tenant->id, 'role' => $role, 'email' => 'private-owner@example.test']);
        $teammate = User::factory()->create(['tenant_id' => $tenant->id, 'role' => 'cashier', 'email' => 'private-team@example.test']);
        $location = Location::factory()->create(['tenant_id' => $tenant->id, 'address' => 'PRIVATE STORE ADDRESS']);
        $branch = Location::factory()->create(['tenant_id' => $tenant->id]);
        $product = Product::factory()->create(['tenant_id' => $tenant->id, 'cost_cents' => 1234567,
            'price_cents' => 25000, 'wholesale_price_cents' => null, 'barcode' => 'QA-OFFLINE-BARCODE']);
        Stock::factory()->create(['tenant_id' => $tenant->id, 'product_id' => $product->id, 'location_id' => $location->id, 'quantity' => 12, 'reserved_quantity' => 4]);
        $own = CashSession::factory()->create(['tenant_id' => $tenant->id, 'user_id' => $actor->id, 'location_id' => $location->id]);
        CashSession::factory()->create(['tenant_id' => $tenant->id, 'user_id' => $teammate->id, 'location_id' => $location->id]);
        CashSession::factory()->create(['tenant_id' => $tenant->id, 'user_id' => $actor->id, 'location_id' => $branch->id,
            'status' => 'closed', 'open_scope' => null, 'closed_at' => now()]);
        Customer::factory()->create(['tenant_id' => $tenant->id, 'name' => 'PRIVATE CUSTOMER NAME', 'email' => 'private-customer@example.test']);
        Supplier::factory()->create(['tenant_id' => $tenant->id, 'name' => 'PRIVATE SUPPLIER NAME']);
        Sale::factory()->create(['tenant_id' => $tenant->id, 'user_id' => $actor->id, 'location_id' => $location->id, 'number' => 'PRIVATE-RECEIPT']);
        $subscription = Subscription::factory()->create(['tenant_id' => $tenant->id]);
        BillingInvoice::factory()->create(['tenant_id' => $tenant->id, 'subscription_id' => $subscription->id, 'number' => 'PRIVATE-INVOICE']);
        $foreign = User::factory()->create();
        Product::factory()->create(['tenant_id' => $foreign->tenant_id, 'name' => 'FOREIGN PRODUCT SENTINEL']);
        CashSession::factory()->create(['tenant_id' => $foreign->tenant_id, 'user_id' => $foreign->id]);
        $before = $this->records(['tenants', 'users', 'subscriptions', 'billing_invoices', 'sales', 'cash_sessions']);
        Http::preventStrayRequests();
        Mail::fake();

        $response = $this->actingAs($actor)->getJson('/api/offline/snapshot?tenant_id='.$foreign->tenant_id)->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')->assertJsonPath('version', 1)
            ->assertJsonPath('user', ['id' => $actor->id, 'role' => $role])
            ->assertJsonPath('tenant', ['id' => $tenant->id, 'name' => 'Offline company'])
            ->assertJsonPath('settings', ['tax_percent' => 12, 'receipt_footer' => 'Thank you'])
            ->assertJsonPath('can_write', true)->assertJsonCount(2, 'locations')->assertJsonCount(1, 'products')
            ->assertJsonPath('products.0.id', $product->id)->assertJsonPath('products.0.barcode', 'QA-OFFLINE-BARCODE')
            ->assertJsonPath('products.0.wholesale_price', null)->assertJsonPath('products.0.stocks.0.available_quantity', 8)
            ->assertJsonPath('cash_sessions', [['id' => $own->id, 'location_id' => $location->id]])
            ->assertDontSee('FOREIGN PRODUCT SENTINEL')->assertDontSee('PRIVATE CUSTOMER NAME')
            ->assertDontSee('PRIVATE SUPPLIER NAME')->assertDontSee('PRIVATE OFFICE ADDRESS')->assertDontSee('PRIVATE STORE ADDRESS')
            ->assertDontSee('private-owner@example.test')->assertDontSee('private-team@example.test')
            ->assertDontSee('PRIVATE-INVOICE')->assertDontSee('PRIVATE-RECEIPT');

        $this->assertSame(['version', 'captured_at', 'expires_at', 'permit', 'user', 'tenant', 'locations', 'products', 'settings', 'cash_sessions', 'can_write'], array_keys($response->json()));
        $this->assertSame(['id', 'name', 'type'], array_keys($response->json('locations.0')));
        $this->assertArrayNotHasKey('cost', $response->json('products.0'));
        $this->assertArrayNotHasKey('option_names', $response->json('products.0'));
        $this->assertArrayNotHasKey('default_values', $response->json('products.0'));
        $this->assertNotEmpty($response->json('permit'));
        $this->assertSame(now()->toIso8601String(), $response->json('captured_at'));
        $this->assertSame(now()->addHours(24)->toIso8601String(), $response->json('expires_at'));
        $this->assertSame($before, $this->records(array_keys($before)));
        Http::assertNothingSent();
        Mail::assertNothingSent();
    }

    public function test_snapshot_filters_foreign_stock_and_family_links_even_when_catalog_references_are_malformed(): void
    {
        $actor = User::factory()->create();
        $location = Location::factory()->create(['tenant_id' => $actor->tenant_id]);
        $foreign = Tenant::factory()->create();
        $foreignLocation = Location::factory()->create(['tenant_id' => $foreign->id]);
        $foreignFamily = ProductFamily::factory()->create(['tenant_id' => $foreign->id, 'name' => 'FOREIGN FAMILY SENTINEL']);
        $localFamily = ProductFamily::factory()->create(['tenant_id' => $actor->tenant_id, 'name' => 'Local variant family']);
        $malformed = Product::factory()->create(['tenant_id' => $actor->tenant_id, 'product_family_id' => $foreignFamily->id]);
        $valid = Product::factory()->create(['tenant_id' => $actor->tenant_id, 'product_family_id' => $localFamily->id]);
        Stock::factory()->create(['tenant_id' => $actor->tenant_id, 'product_id' => $malformed->id,
            'location_id' => $location->id, 'quantity' => 12, 'reserved_quantity' => 4]);
        Stock::factory()->create(['tenant_id' => $foreign->id, 'product_id' => $malformed->id,
            'location_id' => $foreignLocation->id, 'quantity' => 99999, 'reserved_quantity' => 0]);
        Stock::factory()->create(['tenant_id' => $actor->tenant_id, 'product_id' => $valid->id,
            'location_id' => $location->id, 'quantity' => 6, 'reserved_quantity' => 0]);
        Subscription::factory()->create(['tenant_id' => $actor->tenant_id]);
        $before = $this->records(['tenants', 'users', 'products', 'product_families', 'stocks', 'subscriptions', 'billing_invoices']);
        Http::preventStrayRequests();
        Mail::fake();

        $this->actingAs($actor)->getJson('/api/offline/snapshot')->assertOk()
            ->assertJsonCount(2, 'products')->assertJsonPath('products.0.id', $malformed->id)
            ->assertJsonPath('products.0.family_name', null)
            ->assertJsonPath('products.0.stocks', [['location_id' => $location->id, 'quantity' => 12, 'on_hand' => 12,
                'reserved_quantity' => 4, 'available_quantity' => 8]])
            ->assertJsonPath('products.1.id', $valid->id)->assertJsonPath('products.1.family_name', 'Local variant family')
            ->assertJsonPath('products.1.stocks', [['location_id' => $location->id, 'quantity' => 6, 'on_hand' => 6,
                'reserved_quantity' => 0, 'available_quantity' => 6]])
            ->assertDontSee('FOREIGN FAMILY SENTINEL');

        $this->assertSame($before, $this->records(array_keys($before)));
        Http::assertNothingSent();
        Mail::assertNothingSent();
    }

    public function test_expired_subscription_returns_read_only_snapshot_without_generating_renewal_invoices(): void
    {
        $this->freezeTime();
        $user = User::factory()->create();
        Subscription::factory()->create(['tenant_id' => $user->tenant_id, 'status' => 'past_due', 'trial_ends_at' => now()->subDays(30)]);
        $before = $this->records(['tenants', 'users', 'subscriptions', 'billing_invoices']);

        $this->actingAs($user)->getJson('/api/offline/snapshot')->assertOk()->assertJsonPath('can_write', false);

        $this->assertSame($before, $this->records(array_keys($before)));
    }

    public function test_paused_company_can_read_catalog_but_receives_no_permission_to_record_offline_sales(): void
    {
        $user = User::factory()->create();
        $user->tenant->update(['operations_paused' => true]);
        Subscription::factory()->create(['tenant_id' => $user->tenant_id]);

        $this->actingAs($user)->getJson('/api/offline/snapshot')->assertOk()->assertJsonPath('can_write', false);
    }

    public function test_missing_subscription_returns_read_only_snapshot_without_creating_billing_records(): void
    {
        $user = User::factory()->create();
        $before = $this->records(['tenants', 'users', 'subscriptions', 'billing_invoices']);

        $this->actingAs($user)->getJson('/api/offline/snapshot')->assertOk()->assertJsonPath('can_write', false);

        $this->assertSame($before, $this->records(array_keys($before)));
    }

    public function test_snapshot_uses_current_demoted_role_and_rejects_paused_staff_as_403(): void
    {
        $user = User::factory()->create();
        Subscription::factory()->create(['tenant_id' => $user->tenant_id]);
        $this->actingAs($user);
        User::whereKey($user->id)->update(['role' => 'cashier']);

        $this->getJson('/api/offline/snapshot')->assertOk()->assertJsonPath('user.role', 'cashier');

        User::whereKey($user->id)->update(['is_active' => false]);
        $this->getJson('/api/offline/snapshot')->assertForbidden();
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
}
