<?php

namespace Tests\Feature;

use App\Models\BillingInvoice;
use App\Models\BillingPaymentAttempt;
use App\Models\Location;
use App\Models\PlatformAdmin;
use App\Models\PlatformAuditLog;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class DeveloperPortalTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_guests_cannot_enter_the_portal_or_mutate_company_access(): void
    {
        $this->getJson('/api/developer/bootstrap')->assertUnauthorized();
        $this->postJson('/api/developer/companies/1/pause', ['reason' => 'Access review'])->assertUnauthorized();
        $this->get('/developer')->assertRedirect('/developer/login');
        $this->assertGuest('developer');
        $this->assertDatabaseCount('platform_audit_logs', 0);
    }

    #[TestWith(['admin'])]
    #[TestWith(['manager'])]
    #[TestWith(['cashier'])]
    public function test_company_staff_roles_do_not_grant_developer_access(string $role): void
    {
        $company = $this->company($role);
        $this->actingAs($company['user'], 'web')->getJson('/api/developer/bootstrap')->assertUnauthorized();
        $this->postJson('/api/developer/companies/'.$company['tenant']->id.'/pause', ['reason' => 'Access review'])->assertUnauthorized();
        $this->assertFalse($company['tenant']->fresh()->operations_paused);
        $this->assertGuest('developer');
        $this->assertDatabaseCount('platform_audit_logs', 0);
    }

    public function test_developer_session_does_not_authenticate_company_endpoints(): void
    {
        $developer = PlatformAdmin::factory()->create();
        $this->actingAs($developer, 'developer')->getJson('/api/bootstrap')->assertUnauthorized();
        $this->postJson('/api/billing/checkout')->assertUnauthorized();
        $this->postJson('/api/sales')->assertUnauthorized();
        $this->assertGuest('web');
        $this->assertAuthenticatedAs($developer, 'developer');
        $this->assertDatabaseCount('billing_invoices', 0);
        $this->assertDatabaseCount('sales', 0);
    }

    public function test_developer_login_canonicalizes_email_and_authenticates_only_the_developer_guard(): void
    {
        $developer = PlatformAdmin::factory()->create(['email' => 'developer@example.test']);
        $password = $developer->password;
        $this->post('/developer/login', ['email' => ' DEVELOPER@EXAMPLE.TEST ', 'password' => 'DeveloperPassword123!'])
            ->assertRedirect('/developer/overview');
        $this->assertAuthenticatedAs($developer, 'developer');
        $this->assertGuest('web');
        $this->assertSame($password, $developer->fresh()->password);
    }

    public function test_company_credentials_cannot_sign_in_as_a_developer(): void
    {
        $company = $this->company();
        $company['user']->update(['password' => 'CompanyPassword123!']);
        $this->postJson('/developer/login', ['email' => $company['user']->email, 'password' => 'CompanyPassword123!'])
            ->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->assertGuest('developer');
        $this->assertDatabaseCount('platform_admins', 0);
    }

    public function test_developer_credentials_cannot_sign_in_as_company_staff(): void
    {
        $developer = PlatformAdmin::factory()->create();
        $this->postJson('/login', ['email' => $developer->email, 'password' => 'DeveloperPassword123!'])
            ->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->assertGuest('web');
        $this->assertGuest('developer');
    }

    public function test_both_cookie_sessions_coexist_and_developer_logout_preserves_company_login(): void
    {
        config(['session.driver' => 'database']);
        $company = $this->company();
        $company['user']->update(['password' => 'CompanyPassword123!']);
        $developer = PlatformAdmin::factory()->create();
        $this->carryCookies($this->post('/login', ['email' => $company['user']->email, 'password' => 'CompanyPassword123!'])->assertRedirect('/app'));
        $this->carryCookies($this->post('/developer/login', ['email' => $developer->email, 'password' => 'DeveloperPassword123!'])->assertRedirect('/developer/overview'));
        $this->carryCookies($this->getJson('/api/bootstrap')->assertOk()->assertJsonPath('user.id', $company['user']->id));
        $this->carryCookies($this->getJson('/api/developer/bootstrap')->assertOk()->assertJsonPath('developer.id', $developer->id));
        $this->carryCookies($this->post('/developer/logout')->assertRedirect('/developer/login'));
        $this->getJson('/api/bootstrap')->assertOk()->assertJsonPath('user.id', $company['user']->id);
        $this->getJson('/api/developer/bootstrap')->assertUnauthorized();
    }

    public function test_company_logout_preserves_an_independent_developer_cookie_session(): void
    {
        config(['session.driver' => 'database']);
        $company = $this->company();
        $company['user']->update(['password' => 'CompanyPassword123!']);
        $developer = PlatformAdmin::factory()->create();
        $this->carryCookies($this->post('/login', ['email' => $company['user']->email, 'password' => 'CompanyPassword123!'])->assertRedirect('/app'));
        $this->carryCookies($this->post('/developer/login', ['email' => $developer->email, 'password' => 'DeveloperPassword123!'])->assertRedirect('/developer/overview'));
        $this->carryCookies($this->post('/logout')->assertRedirect('/'));
        $this->getJson('/api/developer/bootstrap')->assertOk()->assertJsonPath('developer.id', $developer->id);
        $this->getJson('/api/bootstrap')->assertUnauthorized();
    }

    public function test_a_deactivated_developer_cannot_use_a_previously_authenticated_session(): void
    {
        $developer = PlatformAdmin::factory()->create();
        $this->actingAs($developer, 'developer');
        PlatformAdmin::whereKey($developer->id)->update(['is_active' => false]);
        $this->getJson('/api/developer/bootstrap')->assertForbidden();
        $this->assertGuest('developer');
        $this->assertDatabaseCount('platform_audit_logs', 0);
    }

    public function test_setup_consumes_the_hashed_token_and_requires_a_separate_login(): void
    {
        $this->freezeTime();
        $setup = $this->pendingDeveloper();
        $this->get('/developer/setup/'.$setup['token'].'?email='.urlencode($setup['developer']->email))->assertOk()
            ->assertHeader('Referrer-Policy', 'no-referrer');
        $payload = ['email' => $setup['developer']->email, 'token' => $setup['token'],
            'password' => 'NewDeveloperPassword123!', 'password_confirmation' => 'NewDeveloperPassword123!'];
        $this->post('/developer/setup', $payload)->assertRedirect('/developer/login');
        $account = $setup['developer']->fresh();
        $this->assertTrue($account->is_active);
        $this->assertNull($account->setup_token_hash);
        $this->assertNull($account->setup_expires_at);
        $this->assertNotSame($payload['password'], $account->password);
        $this->assertTrue(Hash::check($payload['password'], $account->password));
        $this->assertGuest('web');
        $this->assertGuest('developer');
        $this->postJson('/developer/setup', array_replace($payload, ['password' => 'AnotherPassword123!', 'password_confirmation' => 'AnotherPassword123!']))
            ->assertUnprocessable();
        $this->assertSame($account->password, $account->fresh()->password);
        $this->assertDatabaseHas('platform_audit_logs', ['platform_admin_id' => $account->id, 'action' => 'developer.account_activated']);
        $this->assertDatabaseCount('platform_audit_logs', 1);
    }

    #[TestWith(['expired'])]
    #[TestWith(['wrong_token'])]
    #[TestWith(['wrong_email'])]
    public function test_expired_or_mismatched_setup_links_do_not_change_an_account(string $case): void
    {
        $this->freezeTime();
        $setup = $this->pendingDeveloper();
        if ($case === 'expired') {
            $setup['developer']->update(['setup_expires_at' => now()->subSecond()]);
        }
        $before = $setup['developer']->fresh()->getAttributes();
        $token = $case === 'wrong_token' ? str_repeat('b', 64) : $setup['token'];
        $email = $case === 'wrong_email' ? 'other@example.test' : $setup['developer']->email;
        $this->get('/developer/setup/'.$token.'?email='.urlencode($email))->assertNotFound();
        $this->postJson('/developer/setup', ['email' => $email, 'token' => $token,
            'password' => 'NewDeveloperPassword123!', 'password_confirmation' => 'NewDeveloperPassword123!'])->assertUnprocessable();
        $this->assertSame($before, $setup['developer']->fresh()->getAttributes());
        $this->assertGuest('developer');
        $this->assertDatabaseCount('platform_audit_logs', 0);
    }

    public function test_setup_rejects_a_short_password_without_consuming_the_invitation(): void
    {
        $setup = $this->pendingDeveloper();
        $this->postJson('/developer/setup', ['email' => $setup['developer']->email, 'token' => $setup['token'],
            'password' => 'Short123!', 'password_confirmation' => 'Short123!'])
            ->assertUnprocessable()->assertJsonValidationErrors('password');
        $account = $setup['developer']->fresh();
        $this->assertFalse($account->is_active);
        $this->assertNull($account->password);
        $this->assertSame(hash('sha256', $setup['token']), $account->setup_token_hash);
    }

    #[TestWith(['/developer/login', ['email' => 'missing@example.test', 'password' => 'WrongPassword123!']])]
    #[TestWith(['/developer/setup', ['email' => 'missing@example.test', 'token' => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', 'password' => 'NewDeveloperPassword123!', 'password_confirmation' => 'NewDeveloperPassword123!']])]
    public function test_login_and_setup_limit_repeated_attempts(string $endpoint, array $payload): void
    {
        $this->freezeTime();
        for ($attempt = 0; $attempt < 6; $attempt++) {
            $this->postJson($endpoint, $payload)->assertUnprocessable();
        }
        $this->postJson($endpoint, $payload)->assertTooManyRequests()->assertHeader('Retry-After');
        $this->assertGuest('developer');
        $this->assertDatabaseCount('platform_admins', 0);
    }

    public function test_public_registration_cannot_promote_a_company_account_to_platform_administrator(): void
    {
        $this->post('/register', ['name' => 'Owner', 'business_name' => 'Local company', 'email' => 'owner@example.test',
            'password' => 'CompanyPassword123!', 'password_confirmation' => 'CompanyPassword123!',
            'role' => 'developer', 'is_developer' => true, 'is_platform_admin' => true, 'guard' => 'developer'])
            ->assertRedirect('/app');
        $this->assertDatabaseHas('users', ['email' => 'owner@example.test', 'role' => 'admin']);
        $this->assertDatabaseCount('platform_admins', 0);
        $this->assertGuest('developer');
        $this->postJson('/developer/register')->assertStatus(405);
    }

    public function test_team_management_cannot_create_a_developer_role(): void
    {
        $company = $this->company();
        $this->actingAs($company['user'], 'web')->postJson('/api/team', ['name' => 'Injected developer',
            'email' => 'not-developer@example.test', 'password' => 'CompanyPassword123!', 'role' => 'developer'])
            ->assertUnprocessable()->assertJsonValidationErrors('role');
        $this->assertDatabaseMissing('users', ['email' => 'not-developer@example.test']);
        $this->assertDatabaseCount('platform_admins', 0);
    }

    public function test_portal_gets_do_not_reconcile_expired_subscriptions_or_create_missing_ones(): void
    {
        $this->freezeTime();
        $developer = PlatformAdmin::factory()->create();
        $company = $this->company('admin', ['status' => 'active', 'current_period_start' => now()->subDays(40), 'current_period_end' => now()->subDays(10)]);
        $unsubscribed = Tenant::factory()->create();
        $before = $this->businessRecords();
        $this->actingAs($developer, 'developer');
        foreach (['overview', 'companies', 'billing', 'activity'] as $section) {
            $this->getJson('/api/developer/bootstrap?section='.$section)->assertOk();
        }
        $this->getJson('/api/developer/companies/'.$company['tenant']->id)->assertOk()->assertJsonPath('company.subscription.status', 'past_due');
        $this->getJson('/api/developer/companies/'.$unsubscribed->id)->assertOk()->assertJsonPath('company.subscription', null);
        $this->assertSame($before, $this->businessRecords());
        $this->assertSame('active', $company['subscription']->fresh()->status);
        $this->assertDatabaseCount('subscriptions', 1);
        $this->assertDatabaseCount('billing_invoices', 0);
        $this->assertDatabaseCount('platform_audit_logs', 0);
    }

    public function test_portal_responses_keep_passwords_setup_tokens_keys_and_checkout_payloads_private(): void
    {
        $developer = PlatformAdmin::factory()->create();
        $company = $this->company();
        $company['tenant']->update(['settings' => Tenant::defaultSettings() + ['private_key' => 'private-company-secret', 'receipt_logo_path' => 'receipts/internal-private-file.png']]);
        $invoice = BillingInvoice::factory()->create(['subscription_id' => $company['subscription']->id]);
        $attempt = BillingPaymentAttempt::factory()->create(['billing_invoice_id' => $invoice->id,
            'checkout_url' => 'https://checkout.paymongo.com/private-link', 'checkout_payload' => ['private' => 'private-checkout-payload']]);
        config(['billing.payment_provider' => 'paymongo', 'paymongo.enabled' => true, 'paymongo.mode' => 'test',
            'paymongo.secret_key' => 'sk_test_private_fixture', 'paymongo.webhook_secret' => 'whsec_private_fixture']);
        $this->actingAs($developer, 'developer');
        $bootstrap = $this->getJson('/api/developer/bootstrap')->assertOk()->assertJsonPath('developer.id', $developer->id);
        $detail = $this->getJson('/api/developer/companies/'.$company['tenant']->id)->assertOk()->assertJsonPath('attempts.0.id', $attempt->id);
        foreach ([$bootstrap, $detail] as $response) {
            foreach ([$developer->password, $company['user']->password, 'private-company-secret', 'internal-private-file',
                'private-checkout-payload', 'checkout.paymongo.com', 'sk_test_private_fixture', 'whsec_private_fixture'] as $secret) {
                $this->assertStringNotContainsString($secret, $response->getContent());
            }
            $this->assertStringNotContainsString('setup_token_hash', $response->getContent());
            $this->assertStringNotContainsString('remember_token', $response->getContent());
            $this->assertStringNotContainsString('checkout_payload', $response->getContent());
            $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        }
    }

    #[TestWith(['pause', []])]
    #[TestWith(['resume', []])]
    #[TestWith(['plan', ['plan' => 'scale', 'billing_cycle' => 'annual']])]
    #[TestWith(['cancel-renewal', []])]
    #[TestWith(['resume-renewal', []])]
    #[TestWith(['invoice', []])]
    public function test_every_company_management_action_requires_a_reason(string $action, array $payload): void
    {
        $company = $this->company();
        $before = $this->businessRecords();
        $this->actingAs(PlatformAdmin::factory()->create(), 'developer')
            ->postJson('/api/developer/companies/'.$company['tenant']->id.'/'.$action, $payload)
            ->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->assertSame($before, $this->businessRecords());
        $this->assertDatabaseCount('platform_audit_logs', 0);
    }

    public function test_pause_and_resume_are_audited_and_gate_company_writes_without_changing_paid_dates(): void
    {
        $this->freezeTime();
        $developer = PlatformAdmin::factory()->create();
        $company = $this->company();
        $before = $company['subscription']->fresh()->getAttributes();
        $this->actingAs($developer, 'developer')->postJson('/api/developer/companies/'.$company['tenant']->id.'/pause', ['reason' => 'Investigate support request'])
            ->assertOk()->assertJsonPath('company.operations_paused', true)->assertJsonPath('company.subscription.can_write', false);
        $this->actingAs($company['user'], 'web')->postJson('/api/customers', ['name' => 'Paused customer'])
            ->assertStatus(423)->assertJsonPath('code', 'platform_paused');
        $this->getJson('/api/bootstrap')->assertOk()->assertJsonPath('subscription.can_write', false);
        $this->actingAs($developer, 'developer')->postJson('/api/developer/companies/'.$company['tenant']->id.'/resume', ['reason' => 'Support review completed'])
            ->assertOk()->assertJsonPath('company.operations_paused', false)->assertJsonPath('company.subscription.can_write', true);
        $this->actingAs($company['user'], 'web')->postJson('/api/customers', ['name' => 'Resumed customer'])->assertCreated();
        $this->assertSame($before, $company['subscription']->fresh()->getAttributes());
        $this->assertDatabaseHas('platform_audit_logs', ['platform_admin_id' => $developer->id, 'tenant_id' => $company['tenant']->id,
            'action' => 'company.paused', 'reason' => 'Investigate support request']);
        $this->assertDatabaseHas('platform_audit_logs', ['platform_admin_id' => $developer->id, 'tenant_id' => $company['tenant']->id,
            'action' => 'company.resumed', 'reason' => 'Support review completed']);
        $this->assertDatabaseCount('platform_audit_logs', 2);
        $audit = PlatformAuditLog::where('action', 'company.paused')->firstOrFail();
        $this->assertFalse($audit->metadata['before']['operations_paused']);
        $this->assertTrue($audit->metadata['after']['operations_paused']);
    }

    public function test_plan_endpoint_enforces_active_staff_capacity(): void
    {
        $company = $this->company();
        User::factory()->count(2)->create(['tenant_id' => $company['tenant']->id, 'role' => 'cashier']);
        Location::factory()->create(['tenant_id' => $company['tenant']->id]);
        $before = $company['subscription']->fresh()->getAttributes();
        $this->actingAs(PlatformAdmin::factory()->create(), 'developer')
            ->postJson('/api/developer/companies/'.$company['tenant']->id.'/plan', ['reason' => 'Requested downgrade', 'plan' => 'starter', 'billing_cycle' => 'monthly'])
            ->assertUnprocessable()->assertJsonValidationErrors('plan');
        $this->assertSame($before, $company['subscription']->fresh()->getAttributes());
        $this->assertDatabaseCount('platform_audit_logs', 0);
    }

    #[TestWith(['plan', ['plan' => 'scale', 'billing_cycle' => 'annual']])]
    #[TestWith(['cancel-renewal', []])]
    #[TestWith(['invoice', []])]
    public function test_unresolved_paymongo_checkout_blocks_conflicting_portal_billing_actions(string $action, array $payload): void
    {
        Http::preventStrayRequests();
        $company = $this->company();
        $invoice = BillingInvoice::factory()->create(['subscription_id' => $company['subscription']->id]);
        BillingPaymentAttempt::factory()->create(['billing_invoice_id' => $invoice->id, 'status' => 'unknown']);
        $before = $this->businessRecords();
        $this->actingAs(PlatformAdmin::factory()->create(), 'developer')
            ->postJson('/api/developer/companies/'.$company['tenant']->id.'/'.$action, $payload + ['reason' => 'Requested account update'])
            ->assertUnprocessable()->assertJsonValidationErrors('payment');
        $this->assertSame($before, $this->businessRecords());
        $this->assertDatabaseCount('platform_audit_logs', 0);
        Http::assertNothingSent();
    }

    public function test_creating_a_local_invoice_does_not_collect_payment_or_activate_a_paid_period(): void
    {
        Http::preventStrayRequests();
        $company = $this->company();
        $this->actingAs(PlatformAdmin::factory()->create(), 'developer')
            ->postJson('/api/developer/companies/'.$company['tenant']->id.'/invoice', ['reason' => 'Prepare local subscription invoice'])
            ->assertOk()->assertJsonPath('company.subscription.status', 'trialing');
        $this->assertDatabaseHas('billing_invoices', ['tenant_id' => $company['tenant']->id, 'amount_cents' => 99900,
            'currency' => 'PHP', 'status' => 'open', 'paid_at' => null]);
        $this->assertDatabaseCount('billing_payment_attempts', 0);
        $this->assertNull($company['subscription']->fresh()->current_period_end);
        $this->assertDatabaseCount('platform_audit_logs', 1);
        Http::assertNothingSent();
    }

    public function test_portal_cannot_manually_mark_an_invoice_paid_or_use_company_simulation_payment(): void
    {
        $company = $this->company();
        $invoice = BillingInvoice::factory()->create(['subscription_id' => $company['subscription']->id]);
        $before = $this->businessRecords();
        $this->actingAs(PlatformAdmin::factory()->create(), 'developer')
            ->postJson('/api/developer/companies/'.$company['tenant']->id.'/mark-paid', ['reason' => 'Force payment', 'invoice_id' => $invoice->id, 'status' => 'paid'])
            ->assertNotFound();
        $this->patchJson('/api/developer/invoices/'.$invoice->id, ['status' => 'paid', 'paid_at' => now()->toIso8601String()])->assertNotFound();
        $this->postJson('/api/billing/invoices/'.$invoice->id.'/simulate-payment')->assertUnauthorized();
        $this->assertSame($before, $this->businessRecords());
        $this->assertDatabaseCount('platform_audit_logs', 0);
    }

    public function test_mutations_cannot_override_the_route_company_actor_or_payment_records(): void
    {
        $developer = PlatformAdmin::factory()->create();
        $otherDeveloper = PlatformAdmin::factory()->create();
        $company = $this->company();
        $other = $this->company();
        $before = $company['subscription']->fresh()->getAttributes();
        $this->actingAs($developer, 'developer')->postJson('/api/developer/companies/'.$company['tenant']->id.'/pause', [
            'reason' => 'Review this company only', 'tenant_id' => $other['tenant']->id, 'platform_admin_id' => $otherDeveloper->id,
            'operations_paused' => false, 'status' => 'paid', 'paid_at' => now()->toIso8601String(), 'amount_cents' => 1,
        ])->assertOk()->assertJsonPath('company.id', $company['tenant']->id)->assertJsonPath('company.operations_paused', true);
        $this->assertFalse($other['tenant']->fresh()->operations_paused);
        $this->assertSame($before, $company['subscription']->fresh()->getAttributes());
        $this->assertDatabaseHas('platform_audit_logs', ['platform_admin_id' => $developer->id, 'tenant_id' => $company['tenant']->id,
            'reason' => 'Review this company only']);
        $this->assertDatabaseCount('platform_audit_logs', 1);
        $this->assertDatabaseCount('billing_invoices', 0);
    }

    public function test_cancel_and_resume_renewal_change_local_records_without_collecting_payment(): void
    {
        Http::preventStrayRequests();
        $this->freezeTime();
        $developer = PlatformAdmin::factory()->create();
        $company = $this->company('admin', ['status' => 'active', 'current_period_start' => now()->subDay(), 'current_period_end' => now()->addDays(29)]);
        $paidEnd = $company['subscription']->current_period_end->toIso8601String();
        $this->actingAs($developer, 'developer')->postJson('/api/developer/companies/'.$company['tenant']->id.'/cancel-renewal', ['reason' => 'Company requested cancellation'])
            ->assertOk()->assertJsonPath('company.subscription.cancel_at_period_end', true)->assertJsonPath('company.subscription.can_write', true);
        $this->postJson('/api/developer/companies/'.$company['tenant']->id.'/resume-renewal', ['reason' => 'Company requested renewal'])
            ->assertOk()->assertJsonPath('company.subscription.cancel_at_period_end', false);
        $this->assertSame($paidEnd, $company['subscription']->fresh()->current_period_end->toIso8601String());
        $this->assertDatabaseHas('platform_audit_logs', ['action' => 'subscription.renewal_canceled', 'platform_admin_id' => $developer->id, 'tenant_id' => $company['tenant']->id]);
        $this->assertDatabaseHas('platform_audit_logs', ['action' => 'subscription.renewal_resumed', 'platform_admin_id' => $developer->id, 'tenant_id' => $company['tenant']->id]);
        $this->assertDatabaseCount('billing_invoices', 0);
        $this->assertDatabaseCount('billing_payment_attempts', 0);
        Http::assertNothingSent();
    }

    public function test_portal_filters_reject_an_unbounded_page_size_without_business_changes(): void
    {
        $company = $this->company();
        $before = $this->businessRecords();
        $this->actingAs(PlatformAdmin::factory()->create(), 'developer')->getJson('/api/developer/bootstrap?per_page=51')
            ->assertUnprocessable()->assertJsonValidationErrors('per_page');
        $this->assertSame($before, $this->businessRecords());
        $this->assertDatabaseCount('platform_audit_logs', 0);
    }

    public function test_company_detail_does_not_accept_an_injected_invoice_tenant_filter(): void
    {
        $company = $this->company();
        $other = $this->company();
        $invoice = BillingInvoice::factory()->create(['subscription_id' => $company['subscription']->id]);
        BillingInvoice::factory()->create(['subscription_id' => $other['subscription']->id]);
        $this->actingAs(PlatformAdmin::factory()->create(), 'developer')
            ->getJson('/api/developer/companies/'.$company['tenant']->id.'?tenant_id='.$other['tenant']->id)
            ->assertOk()->assertJsonCount(1, 'invoices.data')->assertJsonPath('invoices.data.0.id', $invoice->id)
            ->assertJsonPath('invoices.data.0.tenant_id', $company['tenant']->id);
        $this->getJson('/api/developer/companies/999999')->assertNotFound();
        $this->assertDatabaseCount('platform_audit_logs', 0);
    }

    public function test_company_search_treats_sql_syntax_as_data_and_leaves_records_intact(): void
    {
        $company = $this->company();
        $before = $this->businessRecords();
        $this->actingAs(PlatformAdmin::factory()->create(), 'developer')
            ->getJson('/api/developer/bootstrap?search='.urlencode("' OR 1=1 --"))
            ->assertOk()->assertJsonCount(0, 'companies.data')->assertJsonPath('companies.meta.total', 0);
        $this->assertSame($before, $this->businessRecords());
    }

    public function test_login_setup_and_administration_posts_require_csrf_outside_testing(): void
    {
        $setup = $this->pendingDeveloper();
        $company = $this->company();
        $developer = PlatformAdmin::factory()->create();
        $before = $this->businessRecords();
        $this->app->detectEnvironment(fn (): string => 'local');
        $this->postJson('/developer/login', ['email' => $developer->email, 'password' => 'DeveloperPassword123!'])->assertStatus(419);
        $this->postJson('/developer/setup', ['email' => $setup['developer']->email, 'token' => $setup['token'],
            'password' => 'NewDeveloperPassword123!', 'password_confirmation' => 'NewDeveloperPassword123!'])->assertStatus(419);
        $this->actingAs($developer, 'developer')->postJson('/api/developer/companies/'.$company['tenant']->id.'/pause', ['reason' => 'Unauthorized cross-site request'])->assertStatus(419);
        $this->assertFalse($setup['developer']->fresh()->is_active);
        $this->assertSame($before, $this->businessRecords());
        $this->assertDatabaseCount('platform_audit_logs', 0);
    }

    public function test_administration_request_rate_limit_prevents_further_mutations(): void
    {
        $this->freezeTime();
        $company = $this->company();
        $this->actingAs(PlatformAdmin::factory()->create(), 'developer');
        for ($attempt = 0; $attempt < 30; $attempt++) {
            $this->postJson('/api/developer/companies/'.$company['tenant']->id.'/pause')->assertUnprocessable();
        }
        $this->postJson('/api/developer/companies/'.$company['tenant']->id.'/pause', ['reason' => 'Blocked after repeated requests'])
            ->assertTooManyRequests()->assertHeader('Retry-After');
        $this->assertFalse($company['tenant']->fresh()->operations_paused);
        $this->assertDatabaseCount('platform_audit_logs', 0);
    }

    private function company(string $role = 'admin', array $subscriptionAttributes = []): array
    {
        $tenant = Tenant::factory()->create(['settings' => Tenant::defaultSettings()]);
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'role' => $role]);
        $subscription = Subscription::factory()->create(['tenant_id' => $tenant->id] + $subscriptionAttributes);

        return compact('tenant', 'user', 'subscription');
    }

    private function pendingDeveloper(): array
    {
        $token = str_repeat('a', 64);
        $developer = PlatformAdmin::factory()->pending()->create(['setup_token_hash' => hash('sha256', $token), 'setup_expires_at' => now()->addDay()]);

        return compact('developer', 'token');
    }

    private function carryCookies(TestResponse $response): void
    {
        $cookies = [];
        foreach ($response->baseResponse->headers->getCookies() as $cookie) {
            $cookies[$cookie->getName()] = $cookie->getValue();
        }
        $this->withUnencryptedCookies($cookies);
        Auth::forgetGuards();
    }

    private function businessRecords(): array
    {
        $records = [];
        foreach (['tenants', 'users', 'subscriptions', 'billing_invoices', 'billing_payment_attempts', 'products', 'stocks', 'sales', 'customers'] as $table) {
            $records[$table] = DB::table($table)->orderBy('id')->get()->map(fn (object $record): array => (array) $record)->all();
        }

        return $records;
    }
}
