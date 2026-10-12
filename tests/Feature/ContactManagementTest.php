<?php

namespace Tests\Feature;

use App\Models\BillingInvoice;
use App\Models\Customer;
use App\Models\Location;
use App\Models\Product;
use App\Models\Stock;
use App\Models\Subscription;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ContactManagementTest extends TestCase
{
    use LazilyRefreshDatabase;

    /** @return array<string, array{string, string, string}> */
    public static function permittedContacts(): array
    {
        return [
            'admin customer' => ['customers', Customer::class, 'admin'],
            'manager customer' => ['customers', Customer::class, 'manager'],
            'cashier customer' => ['customers', Customer::class, 'cashier'],
            'admin supplier' => ['suppliers', Supplier::class, 'admin'],
            'manager supplier' => ['suppliers', Supplier::class, 'manager'],
        ];
    }

    #[DataProvider('permittedContacts')]
    public function test_contact_creation_returns_201_and_ignores_forged_ownership_and_record_attributes(string $table, string $model, string $role): void
    {
        $actor = $this->actor($role);
        $foreign = $model::factory()->create(['name' => 'Foreign company contact']);
        $before = $foreign->refresh()->getRawOriginal();
        $payload = ['name' => 'Ñaña & Sons <script>example</script>', 'email' => 'contact@example.test', 'phone' => '0'];
        Http::preventStrayRequests();

        $response = $this->actingAs($actor)->postJson('/api/'.$table, $payload + [
            'id' => $foreign->id, 'tenant_id' => $foreign->tenant_id, 'role' => 'admin', 'is_active' => false,
            'created_at' => '1990-01-01 00:00:00', 'password' => 'ignored-secret',
        ])->assertCreated();

        $response->assertExactJson(['id' => $response->json('id')] + $payload);
        $this->assertDatabaseHas($table, ['id' => $response->json('id'), 'tenant_id' => $actor->tenant_id] + $payload);
        $this->assertSame($before, $foreign->refresh()->getRawOriginal());
        $this->assertDatabaseCount($table, 2);
        $this->assertDatabaseCount('billing_invoices', 0);
        Http::assertNothingSent();
    }

    /** @return array<string, array{string}> */
    public static function contactEndpoints(): array
    {
        return ['customer' => ['customers'], 'supplier' => ['suppliers']];
    }

    #[DataProvider('contactEndpoints')]
    public function test_optional_contact_fields_can_be_blank_and_maximum_name_and_phone_lengths_are_saved(string $table): void
    {
        $actor = $this->actor();
        $maximum = ['name' => str_repeat('n', 100), 'email' => 'contact@example.test', 'phone' => str_repeat('1', 30)];
        Http::preventStrayRequests();

        $this->actingAs($actor)->postJson('/api/'.$table, $maximum)->assertCreated();
        $blank = $this->postJson('/api/'.$table, ['name' => '  Name only  ', 'email' => '', 'phone' => ' '])->assertCreated();

        $blank->assertExactJson(['id' => $blank->json('id'), 'name' => 'Name only', 'email' => null, 'phone' => null]);
        $this->assertDatabaseHas($table, ['tenant_id' => $actor->tenant_id] + $maximum);
        $this->assertDatabaseHas($table, ['id' => $blank->json('id'), 'tenant_id' => $actor->tenant_id, 'name' => 'Name only', 'email' => null, 'phone' => null]);
        Http::assertNothingSent();
    }

    /** @return array<string, array{array<string, mixed>, string, string}> */
    public static function invalidContactDetails(): array
    {
        return [
            'blank name' => [['name' => '   '], 'name', 'The name field is required.'],
            'non-string name' => [['name' => ['invalid']], 'name', 'The name field must be a string.'],
            'long name' => [['name' => str_repeat('n', 101)], 'name', 'The name field must not be greater than 100 characters.'],
            'invalid email' => [['email' => 'not-an-email'], 'email', 'The email field must be a valid email address.'],
            'long email' => [['email' => str_repeat('a', 256).'@example.test'], 'email', 'The email field must not be greater than 255 characters.'],
            'numeric phone' => [['phone' => 123456789], 'phone', 'The phone field must be a string.'],
            'long phone' => [['phone' => str_repeat('1', 31)], 'phone', 'The phone field must not be greater than 30 characters.'],
        ];
    }

    #[DataProvider('invalidContactDetails')]
    public function test_invalid_contact_details_return_422_without_saving_a_customer(array $invalid, string $field, string $message): void
    {
        $actor = $this->actor();
        Http::preventStrayRequests();

        $response = $this->actingAs($actor)->postJson('/api/customers', array_replace(['name' => 'Valid customer'], $invalid))
            ->assertUnprocessable()->assertJsonValidationErrors($field);

        $this->assertContains($message, $response->json('errors.'.$field));
        $this->assertDatabaseCount('customers', 0);
        Http::assertNothingSent();
    }

    public function test_supplier_validation_returns_422_without_saving_invalid_contact_details(): void
    {
        $actor = $this->actor('manager');
        Http::preventStrayRequests();

        $this->actingAs($actor)->postJson('/api/suppliers', ['email' => 'not-an-email', 'phone' => ['invalid']])
            ->assertUnprocessable()->assertJsonValidationErrors(['name', 'email', 'phone']);

        $this->assertDatabaseCount('suppliers', 0);
        Http::assertNothingSent();
    }

    #[DataProvider('contactEndpoints')]
    public function test_anonymous_contact_creation_returns_401_without_saving_records(string $table): void
    {
        Http::preventStrayRequests();

        $this->postJson('/api/'.$table, ['name' => 'Anonymous contact'])->assertUnauthorized();

        $this->assertDatabaseCount($table, 0);
        $this->assertDatabaseCount('subscriptions', 0);
        Http::assertNothingSent();
    }

    #[DataProvider('contactEndpoints')]
    public function test_paused_team_member_contact_creation_returns_403_without_saving_records(string $table): void
    {
        $actor = $this->actor();
        $actor->update(['is_active' => false]);
        Http::preventStrayRequests();

        $this->actingAs($actor)->postJson('/api/'.$table, ['name' => 'Paused team contact'])->assertForbidden();

        $this->assertDatabaseCount($table, 0);
        $this->assertDatabaseCount('billing_invoices', 0);
        Http::assertNothingSent();
    }

    public function test_cashier_supplier_creation_returns_403_even_with_forged_role(): void
    {
        $actor = $this->actor('cashier');
        Http::preventStrayRequests();

        $this->actingAs($actor)->postJson('/api/suppliers', ['name' => 'Restricted supplier', 'role' => 'admin'])
            ->assertForbidden();

        $this->assertDatabaseCount('suppliers', 0);
        $this->assertDatabaseHas('users', ['id' => $actor->id, 'role' => 'cashier']);
        Http::assertNothingSent();
    }

    /** @return iterable<string, array{string, bool, string}> */
    public static function blockedContacts(): iterable
    {
        foreach (['customers', 'suppliers'] as $table) {
            yield $table.' expired' => [$table, false, 'subscription_required'];
            yield $table.' paused' => [$table, true, 'platform_paused'];
        }
    }

    #[DataProvider('blockedContacts')]
    public function test_contact_creation_returns_423_when_company_writes_are_blocked(string $table, bool $paused, string $code): void
    {
        $this->freezeTime();
        $actor = $this->actor();
        $actor->tenant->update(['operations_paused' => $paused]);
        if (! $paused) {
            $actor->tenant->subscription->update(['status' => 'past_due', 'trial_ends_at' => now()->subDays(30)]);
        }
        $before = $actor->tenant->subscription->refresh()->getRawOriginal();
        $invoice = BillingInvoice::factory()->create(['tenant_id' => $actor->tenant_id, 'subscription_id' => $actor->tenant->subscription->id]);
        $invoiceBefore = $invoice->refresh()->getRawOriginal();
        Http::preventStrayRequests();

        $this->actingAs($actor)->postJson('/api/'.$table, ['name' => 'Blocked contact'])
            ->assertStatus(423)->assertJsonPath('code', $code);

        $this->assertDatabaseCount($table, 0);
        $this->assertSame($before, $actor->tenant->subscription->refresh()->getRawOriginal());
        $this->assertDatabaseCount('billing_invoices', 1);
        $this->assertSame($invoiceBefore, $invoice->refresh()->getRawOriginal());
        Http::assertNothingSent();
    }

    /** @return array<string, array{int|string}> */
    public static function zeroCustomerIds(): array
    {
        return ['integer' => [0], 'numeric string' => ['0']];
    }

    #[DataProvider('zeroCustomerIds')]
    public function test_nonexistent_zero_customer_returns_404_without_recording_sale_or_changing_stock(int|string $customerId): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        Subscription::factory()->create(['tenant_id' => $tenant->id]);
        $location = Location::factory()->create(['tenant_id' => $tenant->id]);
        $product = Product::factory()->create(['tenant_id' => $tenant->id, 'price_cents' => 10000]);
        $stock = Stock::factory()->create(['tenant_id' => $tenant->id, 'location_id' => $location->id, 'product_id' => $product->id, 'quantity' => 10]);
        Http::preventStrayRequests();

        $this->actingAs($user)->postJson('/api/sales', [
            'location_id' => $location->id, 'customer_id' => $customerId,
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'payments' => [['method' => 'cash', 'amount' => 200]], 'idempotency_key' => 'zero-customer-sale',
        ])->assertNotFound();

        $this->assertDatabaseCount('sales', 0);
        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertDatabaseHas('stocks', ['id' => $stock->id, 'quantity' => 10]);
        Http::assertNothingSent();
    }

    public function test_walk_in_sale_retries_accept_null_customer_but_reject_changed_zero_customer_with_409(): void
    {
        $actor = $this->actor();
        $location = Location::factory()->create(['tenant_id' => $actor->tenant_id]);
        $product = Product::factory()->create(['tenant_id' => $actor->tenant_id, 'price_cents' => 10000]);
        $stock = Stock::factory()->create(['tenant_id' => $actor->tenant_id, 'location_id' => $location->id, 'product_id' => $product->id, 'quantity' => 10]);
        $payload = [
            'location_id' => $location->id, 'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'payments' => [['method' => 'cash', 'amount' => 200]], 'idempotency_key' => 'walk-in-customer-retry',
        ];
        Http::preventStrayRequests();

        $sale = $this->actingAs($actor)->postJson('/api/sales', $payload)->assertCreated()->assertJsonPath('customer_id', null);
        $this->postJson('/api/sales', $payload + ['customer_id' => null])->assertCreated()->assertJsonPath('id', $sale->json('id'));
        $this->postJson('/api/sales', $payload + ['customer_id' => 0])->assertConflict();

        $this->assertDatabaseCount('sales', 1);
        $this->assertDatabaseCount('stock_movements', 1);
        $this->assertDatabaseHas('sales', ['id' => $sale->json('id'), 'customer_id' => null]);
        $this->assertDatabaseHas('stocks', ['id' => $stock->id, 'quantity' => 9]);
        Http::assertNothingSent();
    }

    private function actor(string $role = 'admin'): User
    {
        $actor = User::factory()->create(['role' => $role]);
        Subscription::factory()->create(['tenant_id' => $actor->tenant_id]);

        return $actor;
    }
}
