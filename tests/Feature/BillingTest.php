<?php

namespace Tests\Feature;

use App\Models\BillingInvoice;
use App\Models\Location;
use App\Models\Product;
use App\Models\Stock;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Services\InventoryService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class BillingTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function owner(string $plan = 'grow', string $role = 'admin'): User
    {
        $tenant = Tenant::factory()->create(['settings' => Tenant::defaultSettings($plan)]);

        return User::factory()->create(['tenant_id' => $tenant->id, 'role' => $role]);
    }

    private function activeSubscription(User $user): Subscription
    {
        return Subscription::factory()->create(['tenant_id' => $user->tenant_id, 'status' => 'active', 'current_period_start' => now(), 'current_period_end' => now()->addMonthNoOverflow()]);
    }

    public function test_billing_requires_authentication_and_administrator_access(): void
    {
        $this->getJson('/api/billing')->assertUnauthorized();
        $manager = $this->owner(role: 'manager');

        $this->actingAs($manager)->getJson('/api/billing')->assertForbidden();
        $this->postJson('/api/billing/checkout')->assertForbidden();
        $this->assertDatabaseCount('billing_invoices', 0);
    }

    public function test_trial_billing_is_tenant_scoped_and_keeps_server_plan_prices(): void
    {
        $this->freezeTime();
        $owner = $this->owner('starter');
        $foreign = BillingInvoice::factory()->create();

        $response = $this->actingAs($owner)->getJson('/api/billing')->assertOk()
            ->assertJsonPath('subscription.status', 'trialing')->assertJsonPath('subscription.plan', 'starter')
            ->assertJsonPath('subscription.can_write', true)->assertJsonPath('plans.0.annual_price', 4790.4)
            ->assertJsonPath('usage.team_members', 1)->assertJsonCount(0, 'invoices');

        $this->assertSame(now()->addDays(14)->toIso8601String(), $response->json('subscription.trial_ends_at'));
        $this->postJson('/api/billing/invoices/'.$foreign->id.'/simulate-payment', ['idempotency_key' => 'tenant-isolation'])->assertNotFound();
        $this->assertDatabaseHas('billing_invoices', ['id' => $foreign->id, 'status' => 'open']);
    }

    public function test_trial_plan_change_does_not_extend_trial_or_activate_subscription(): void
    {
        $this->freezeTime();
        $owner = $this->owner('starter');
        $original = $this->actingAs($owner)->getJson('/api/billing')->json('subscription.trial_ends_at');
        $this->travel(2)->days();

        $this->postJson('/api/billing/change-plan', ['plan' => 'scale', 'billing_cycle' => 'annual', 'status' => 'active'])->assertOk()
            ->assertJsonPath('subscription.plan', 'scale')->assertJsonPath('subscription.status', 'trialing')
            ->assertJsonPath('subscription.trial_ends_at', $original);

        $this->assertDatabaseCount('billing_invoices', 0);
    }

    public function test_invoice_creation_is_duplicate_safe_and_ignores_client_amount(): void
    {
        $owner = $this->owner('starter');
        $this->actingAs($owner)->postJson('/api/billing/change-plan', ['plan' => 'starter', 'billing_cycle' => 'annual'])->assertOk();

        $first = $this->postJson('/api/billing/checkout', ['amount' => 1])->assertOk()->assertJsonPath('invoices.0.amount', 4790.4);
        $this->postJson('/api/billing/checkout')->assertOk()->assertJsonPath('invoices.0.id', $first->json('invoices.0.id'));

        $this->assertDatabaseCount('billing_invoices', 1);
        $this->assertDatabaseHas('billing_invoices', ['amount_cents' => 479040, 'status' => 'open']);
    }

    public function test_simulated_payment_activates_a_month_from_payment_and_replays_once(): void
    {
        $this->travelTo('2026-01-31 10:00:00');
        $owner = $this->owner('starter');
        $invoice = $this->actingAs($owner)->postJson('/api/billing/checkout')->json('invoices.0.id');
        $key = ['idempotency_key' => 'simulation-confirmation'];

        $response = $this->postJson('/api/billing/invoices/'.$invoice.'/simulate-payment', $key)->assertOk()
            ->assertJsonPath('subscription.status', 'active')->assertJsonPath('invoices.0.payment_method', 'simulation');
        $end = $response->json('subscription.current_period_end');
        $this->travel(1)->days();
        $this->postJson('/api/billing/invoices/'.$invoice.'/simulate-payment', $key)->assertOk()->assertJsonPath('subscription.current_period_end', $end);

        $this->assertStringStartsWith('2026-02-28T10:00:00', $end);
        $this->assertDatabaseHas('billing_invoices', ['id' => $invoice, 'status' => 'paid', 'paid_by' => $owner->id]);
        $this->assertDatabaseCount('billing_invoices', 1);
    }

    public function test_repeating_current_past_due_plan_keeps_the_original_invoice_payable(): void
    {
        $owner = $this->owner();
        Subscription::factory()->create(['tenant_id' => $owner->tenant_id, 'status' => 'past_due', 'trial_ends_at' => now()->subDays(1)]);
        $invoice = $this->actingAs($owner)->getJson('/api/billing')->assertOk()->json('invoices.0.id');

        for ($attempt = 0; $attempt < 2; $attempt++) {
            $this->postJson('/api/billing/change-plan', ['plan' => 'grow', 'billing_cycle' => 'monthly'])
                ->assertOk()->assertJsonPath('invoices.0.id', $invoice)->assertJsonPath('invoices.0.status', 'open');
        }

        $this->assertDatabaseCount('billing_invoices', 1);
        $this->postJson('/api/billing/invoices/'.$invoice.'/simulate-payment', ['idempotency_key' => 'same-plan-payment'])->assertOk();
    }

    public function test_payment_retry_with_changed_key_or_actor_is_409(): void
    {
        $owner = $this->owner();
        $invoice = $this->actingAs($owner)->postJson('/api/billing/checkout')->json('invoices.0.id');
        $this->postJson('/api/billing/invoices/'.$invoice.'/simulate-payment', ['idempotency_key' => 'original-payment'])->assertOk();
        $anotherAdmin = User::factory()->create(['tenant_id' => $owner->tenant_id, 'role' => 'admin']);

        $this->postJson('/api/billing/invoices/'.$invoice.'/simulate-payment', ['idempotency_key' => 'changed-payment'])->assertConflict();
        $this->actingAs($anotherAdmin)->postJson('/api/billing/invoices/'.$invoice.'/simulate-payment', ['idempotency_key' => 'original-payment'])->assertConflict();

        $this->assertDatabaseCount('billing_invoices', 1);
    }

    #[TestWith(['production'])]
    #[TestWith(['staging'])]
    public function test_simulated_payments_are_404_outside_local_and_testing(string $environment): void
    {
        $owner = $this->owner();
        $invoice = $this->actingAs($owner)->postJson('/api/billing/checkout')->json('invoices.0.id');
        app()->detectEnvironment(fn (): string => $environment);

        $this->withSession(['_token' => 'billing-production-token'])->postJson('/api/billing/invoices/'.$invoice.'/simulate-payment', ['_token' => 'billing-production-token', 'idempotency_key' => 'disabled-payment'])->assertNotFound();

        $this->assertDatabaseHas('billing_invoices', ['id' => $invoice, 'status' => 'open']);
    }

    public function test_simulation_flag_can_disable_local_payment_and_validates_key(): void
    {
        $owner = $this->owner();
        $invoice = $this->actingAs($owner)->postJson('/api/billing/checkout')->json('invoices.0.id');

        $this->postJson('/api/billing/invoices/'.$invoice.'/simulate-payment')->assertUnprocessable()->assertJsonValidationErrors('idempotency_key');
        config(['billing.simulations_enabled' => false]);
        $this->postJson('/api/billing/invoices/'.$invoice.'/simulate-payment', ['idempotency_key' => 'disabled-payment'])->assertNotFound();

        $this->assertDatabaseHas('billing_invoices', ['id' => $invoice, 'status' => 'open']);
    }

    public function test_paid_plan_changes_are_scheduled_and_existing_period_is_not_shortened(): void
    {
        $this->freezeTime();
        $owner = $this->owner();
        $subscription = $this->activeSubscription($owner);

        $this->actingAs($owner)->postJson('/api/billing/change-plan', ['plan' => 'scale', 'billing_cycle' => 'annual'])->assertOk()
            ->assertJsonPath('subscription.plan', 'grow')->assertJsonPath('subscription.pending_plan', 'scale')
            ->assertJsonPath('subscription.current_period_end', $subscription->current_period_end->toIso8601String());
        $this->postJson('/api/billing/checkout')->assertUnprocessable()->assertJsonValidationErrors('subscription');

        $this->assertDatabaseCount('billing_invoices', 0);
    }

    public function test_renewal_creates_one_invoice_and_applies_scheduled_plan_after_payment(): void
    {
        $this->freezeTime();
        $owner = $this->owner();
        $subscription = $this->activeSubscription($owner);
        $subscription->update(['pending_plan' => 'scale', 'pending_billing_cycle' => 'annual']);
        $this->travel(1)->months();

        $this->artisan('billing:process-renewals')->assertSuccessful();
        $this->artisan('billing:process-renewals')->assertSuccessful();
        $invoice = BillingInvoice::firstOrFail();
        $this->actingAs($owner)->postJson('/api/billing/invoices/'.$invoice->id.'/simulate-payment', ['idempotency_key' => 'renewal-confirmed'])->assertOk()
            ->assertJsonPath('subscription.plan', 'scale')->assertJsonPath('subscription.billing_cycle', 'annual')
            ->assertJsonPath('subscription.pending_plan', null);

        $this->assertDatabaseCount('billing_invoices', 1);
        $this->assertDatabaseHas('billing_invoices', ['amount_cents' => 1919040, 'status' => 'paid']);
    }

    public function test_changing_unpaid_plan_voids_the_old_invoice_and_it_cannot_be_paid(): void
    {
        $owner = $this->owner();
        $invoice = $this->actingAs($owner)->postJson('/api/billing/checkout')->json('invoices.0.id');

        $this->postJson('/api/billing/change-plan', ['plan' => 'starter', 'billing_cycle' => 'monthly'])->assertOk();
        $this->postJson('/api/billing/invoices/'.$invoice.'/simulate-payment', ['idempotency_key' => 'voided-invoice'])->assertConflict();

        $this->assertDatabaseHas('billing_invoices', ['id' => $invoice, 'status' => 'void']);
        $this->assertDatabaseHas('subscriptions', ['tenant_id' => $owner->tenant_id, 'status' => 'trialing']);
    }

    public function test_cancellation_keeps_access_until_period_end_and_stops_renewals(): void
    {
        $this->freezeTime();
        $owner = $this->owner();
        $subscription = $this->activeSubscription($owner);

        $this->actingAs($owner)->postJson('/api/billing/cancel')->assertOk()->assertJsonPath('subscription.can_write', true)->assertJsonPath('subscription.cancel_at_period_end', true);
        $this->travelTo($subscription->current_period_end);
        $this->getJson('/api/billing')->assertOk()->assertJsonPath('subscription.status', 'canceled')->assertJsonPath('subscription.can_write', false);
        $this->postJson('/api/billing/checkout')->assertUnprocessable();

        $this->assertDatabaseCount('billing_invoices', 0);
        $this->assertModelExists($owner);
    }

    public function test_resuming_ended_subscription_reopens_invoice_without_extending_access_until_paid(): void
    {
        $this->freezeTime();
        $owner = $this->owner();
        Subscription::factory()->create(['tenant_id' => $owner->tenant_id, 'status' => 'canceled', 'cancel_at_period_end' => true, 'trial_ends_at' => now()->subDays(10)]);

        $this->actingAs($owner)->postJson('/api/billing/resume')->assertOk()->assertJsonPath('subscription.status', 'past_due')->assertJsonPath('subscription.can_write', false)->assertJsonCount(1, 'invoices');

        $this->assertDatabaseHas('billing_invoices', ['tenant_id' => $owner->tenant_id, 'status' => 'open']);
    }

    public function test_expired_access_blocks_checkout_but_preserves_reports_returns_and_billing(): void
    {
        $this->freezeTime();
        $owner = $this->owner();
        $location = Location::factory()->create(['tenant_id' => $owner->tenant_id]);
        $product = Product::factory()->create(['tenant_id' => $owner->tenant_id, 'price_cents' => 10000]);
        Stock::create(['tenant_id' => $owner->tenant_id, 'location_id' => $location->id, 'product_id' => $product->id, 'quantity' => 3]);
        $sale = app(InventoryService::class)->sale($owner, ['location_id' => $location->id, 'items' => [['product_id' => $product->id, 'quantity' => 1]], 'payments' => [['method' => 'cash', 'amount' => 112]], 'idempotency_key' => 'earlier-sale']);
        Subscription::factory()->create(['tenant_id' => $owner->tenant_id, 'trial_ends_at' => now()->subDays(8)]);

        $this->actingAs($owner)->postJson('/api/sales', [])->assertStatus(423)->assertJsonPath('code', 'subscription_required');
        $this->get('/api/reports/export')->assertOk();
        $this->postJson('/api/sales/'.$sale->id.'/return')->assertOk()->assertJsonPath('status', 'returned');
        $this->getJson('/api/billing')->assertOk()->assertJsonPath('subscription.can_write', false);

        $this->assertDatabaseCount('sales', 1);
        $this->assertDatabaseHas('stocks', ['product_id' => $product->id, 'quantity' => 3]);
    }

    public function test_expired_trial_has_a_fixed_seven_day_grace_without_resetting_on_requests(): void
    {
        $this->freezeTime();
        $owner = $this->owner();
        Subscription::factory()->create(['tenant_id' => $owner->tenant_id, 'trial_ends_at' => now()->subDays(6)]);

        $this->actingAs($owner)->getJson('/api/billing')->assertOk()->assertJsonPath('subscription.status', 'past_due')->assertJsonPath('subscription.can_write', true);
        $this->travel(1)->days();
        $this->getJson('/api/billing')->assertOk()->assertJsonPath('subscription.can_write', false);

        $this->assertDatabaseCount('billing_invoices', 1);
    }

    public function test_plan_downgrade_rejects_current_location_and_active_team_overages(): void
    {
        $owner = $this->owner();
        Location::factory()->count(2)->create(['tenant_id' => $owner->tenant_id]);
        $this->actingAs($owner)->getJson('/api/billing')->assertOk();

        $this->actingAs($owner)->postJson('/api/billing/change-plan', ['plan' => 'starter', 'billing_cycle' => 'monthly'])->assertUnprocessable()->assertJsonValidationErrors('plan');

        $this->assertDatabaseHas('subscriptions', ['tenant_id' => $owner->tenant_id, 'plan' => 'grow']);
        $this->assertDatabaseCount('billing_invoices', 0);
    }

    public function test_location_plan_limit_is_enforced_before_creating_stock_records(): void
    {
        $owner = $this->owner('starter');
        Location::factory()->create(['tenant_id' => $owner->tenant_id]);

        $this->actingAs($owner)->postJson('/api/locations', ['name' => 'Extra counter'])->assertUnprocessable()->assertJsonValidationErrors('locations');

        $this->assertDatabaseCount('locations', 1);
        $this->assertDatabaseCount('stocks', 0);
    }

    public function test_active_team_limit_includes_admin_and_paused_accounts_do_not_use_seats(): void
    {
        $owner = $this->owner('starter');
        User::factory()->create(['tenant_id' => $owner->tenant_id, 'role' => 'cashier']);
        $paused = User::factory()->create(['tenant_id' => $owner->tenant_id, 'is_active' => false]);

        $this->actingAs($owner)->patchJson('/api/team/'.$paused->id, ['is_active' => true])->assertUnprocessable()->assertJsonValidationErrors('team_members');
        $this->postJson('/api/team', ['name' => 'Another cashier', 'email' => 'extra@example.test', 'password' => 'test-only-long-password', 'role' => 'cashier'])->assertUnprocessable()->assertJsonValidationErrors('team_members');

        $this->assertDatabaseHas('users', ['id' => $paused->id, 'is_active' => false]);
        $this->assertDatabaseCount('users', 3);
    }

    public function test_settings_cannot_bypass_billing_plan_or_activate_subscription(): void
    {
        $owner = $this->owner('starter');

        $this->actingAs($owner)->putJson('/api/settings', ['plan' => 'scale', 'billing_cycle' => 'annual', 'subscription_status' => 'active'])->assertOk()->assertJsonPath('plan', 'starter')->assertJsonPath('billing_cycle', 'monthly')->assertJsonPath('subscription_status', 'trial');

        $this->assertSame('starter', $owner->tenant->fresh()->settings['plan']);
    }

    public function test_demo_expiration_is_exempt_but_its_invoice_records_are_tenant_scoped(): void
    {
        $this->freezeTime();
        $owner = $this->owner();
        $owner->tenant->update(['is_demo' => true]);
        Subscription::factory()->create(['tenant_id' => $owner->tenant_id, 'trial_ends_at' => now()->subDays(30)]);

        $this->actingAs($owner)->getJson('/api/billing')->assertOk()->assertJsonPath('subscription.can_write', true)->assertJsonPath('subscription.demo_exempt', true);

        $this->assertDatabaseCount('billing_invoices', 1);
    }

    public function test_invalid_plan_and_cycle_return_422_without_creating_an_invoice(): void
    {
        $owner = $this->owner();

        $this->actingAs($owner)->postJson('/api/billing/change-plan', ['plan' => 'unlimited', 'billing_cycle' => 'daily'])->assertUnprocessable()->assertJsonValidationErrors(['plan', 'billing_cycle']);

        $this->assertDatabaseCount('billing_invoices', 0);
    }

    public function test_annual_payment_from_leap_day_keeps_a_full_valid_year(): void
    {
        $this->travelTo('2028-02-29 09:00:00');
        $owner = $this->owner();
        $this->actingAs($owner)->postJson('/api/billing/change-plan', ['plan' => 'grow', 'billing_cycle' => 'annual']);
        $invoice = $this->postJson('/api/billing/checkout')->json('invoices.0.id');

        $response = $this->postJson('/api/billing/invoices/'.$invoice.'/simulate-payment', ['idempotency_key' => 'leap-year-payment'])->assertOk()->assertJsonPath('invoices.0.amount', 9590.4);

        $this->assertStringStartsWith('2029-02-28T09:00:00', $response->json('subscription.current_period_end'));
    }

    public function test_payment_key_cannot_pay_a_second_renewal_invoice(): void
    {
        $this->freezeTime();
        $owner = $this->owner();
        $invoice = $this->actingAs($owner)->postJson('/api/billing/checkout')->json('invoices.0.id');
        $this->postJson('/api/billing/invoices/'.$invoice.'/simulate-payment', ['idempotency_key' => 'first-period-payment'])->assertOk();
        $this->travel(2)->months();
        $renewal = $this->getJson('/api/billing')->json('invoices.0.id');

        $this->postJson('/api/billing/invoices/'.$renewal.'/simulate-payment', ['idempotency_key' => 'first-period-payment'])->assertConflict();

        $this->assertDatabaseHas('billing_invoices', ['id' => $renewal, 'status' => 'open']);
        $this->assertDatabaseHas('subscriptions', ['tenant_id' => $owner->tenant_id, 'status' => 'past_due']);
    }

    public function test_scheduled_downgrade_is_checked_again_if_usage_grows_before_payment(): void
    {
        $this->freezeTime();
        $owner = $this->owner();
        $this->activeSubscription($owner);
        $this->actingAs($owner)->postJson('/api/billing/change-plan', ['plan' => 'starter', 'billing_cycle' => 'monthly'])->assertOk();
        Location::factory()->count(2)->create(['tenant_id' => $owner->tenant_id]);
        $this->travel(1)->months();
        $invoice = $this->getJson('/api/billing')->json('invoices.0.id');

        $this->postJson('/api/billing/invoices/'.$invoice.'/simulate-payment', ['idempotency_key' => 'invalid-downgrade'])->assertUnprocessable()->assertJsonValidationErrors('plan');

        $this->assertDatabaseHas('billing_invoices', ['id' => $invoice, 'status' => 'open']);
        $this->assertDatabaseHas('subscriptions', ['tenant_id' => $owner->tenant_id, 'plan' => 'grow', 'status' => 'past_due']);
    }

    public function test_canceling_trial_voids_unpaid_invoice_and_resuming_keeps_original_trial_end(): void
    {
        $this->freezeTime();
        $owner = $this->owner();
        $initial = $this->actingAs($owner)->postJson('/api/billing/checkout')->json('subscription.trial_ends_at');

        $this->postJson('/api/billing/cancel')->assertOk()->assertJsonPath('invoices.0.status', 'void');
        $this->travel(1)->days();
        $this->postJson('/api/billing/resume')->assertOk()->assertJsonPath('subscription.trial_ends_at', $initial)->assertJsonPath('subscription.status', 'trialing')->assertJsonPath('subscription.cancel_at_period_end', false);

        $this->assertDatabaseCount('billing_invoices', 1);
    }

    public function test_invoice_export_contains_all_tenant_history_and_simulation_labels(): void
    {
        $this->freezeTime();
        $owner = $this->owner();
        $subscription = Subscription::factory()->create(['tenant_id' => $owner->tenant_id]);
        BillingInvoice::factory()->count(101)->create(['tenant_id' => $owner->tenant_id, 'subscription_id' => $subscription->id, 'status' => 'paid', 'payment_method' => 'simulation']);
        $foreign = BillingInvoice::factory()->create();

        $response = $this->actingAs($owner)->get('/api/billing/invoices/export')->assertOk();
        $content = $response->streamedContent();

        $this->assertCount(102, explode("\n", trim($content)));
        $this->assertStringContainsString('simulation', $content);
        $this->assertStringNotContainsString($foreign->number, $content);
        $this->assertDatabaseCount('billing_invoices', 102);
    }
}
