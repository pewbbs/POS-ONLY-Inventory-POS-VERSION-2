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
use App\Services\DeveloperPlatformService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\TestWith;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class DeveloperServiceTest extends TestCase
{
    use LazilyRefreshDatabase;

    private array $privateFiles = [];

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config(['app.url' => 'http://localhost', 'billing.payment_provider' => 'simulation']);
        $this->freezeTime();
    }

    protected function tearDown(): void
    {
        foreach ($this->privateFiles as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        parent::tearDown();
    }

    private function service(): DeveloperPlatformService
    {
        return app(DeveloperPlatformService::class);
    }

    private function filters(array $query = []): array
    {
        return $this->service()->filters(Request::create('/api/developer/bootstrap', 'GET', $query));
    }

    private function subscription(Tenant $tenant, array $values = []): Subscription
    {
        return Subscription::factory()->create($values + ['tenant_id' => $tenant->id, 'plan' => 'grow']);
    }

    private function outputFile(): string
    {
        $file = sys_get_temp_dir().'/stockroom-invite-test-'.bin2hex(random_bytes(8)).'.html';
        $this->privateFiles[] = $file;

        return $file;
    }

    public function test_bootstrap_and_detail_are_pure_with_missing_and_expired_subscriptions(): void
    {
        $actor = PlatformAdmin::factory()->create();
        $missing = Tenant::factory()->create();
        $expired = Tenant::factory()->create();
        $subscription = $this->subscription($expired, ['status' => 'active', 'trial_ends_at' => now()->subMonth(),
            'current_period_end' => now()->subDays(10), 'current_period_start' => now()->subMonth()]);
        $original = $subscription->fresh()->getRawOriginal();
        $before = DB::table('tenants')->get()->toArray();

        $state = $this->service()->bootstrap($actor, $this->filters());
        $detail = $this->service()->detail($actor, $expired->id, $this->filters());
        $empty = $this->service()->detail($actor, $missing->id, $this->filters());

        $this->assertSame(2, $state['overview']['companies']);
        $this->assertNull($empty['company']['subscription']);
        $this->assertSame('past_due', $detail['company']['subscription']['status']);
        $this->assertFalse($detail['company']['subscription']['can_write']);
        $this->assertSame($original, $subscription->fresh()->getRawOriginal());
        $this->assertEquals($before, DB::table('tenants')->get()->toArray());
        $this->assertDatabaseCount('subscriptions', 1);
        $this->assertDatabaseCount('billing_invoices', 0);
        $this->assertDatabaseCount('platform_audit_logs', 0);
        Http::assertNothingSent();
    }

    public function test_pagination_usage_and_literal_search_do_not_merge_company_records(): void
    {
        $actor = PlatformAdmin::factory()->create();
        $wanted = Tenant::factory()->create(['name' => '100%_! shop']);
        Tenant::factory()->count(3)->create();
        Location::factory()->count(2)->create(['tenant_id' => $wanted->id]);
        User::factory()->count(2)->create(['tenant_id' => $wanted->id, 'is_active' => true]);
        User::factory()->create(['tenant_id' => $wanted->id, 'is_active' => false]);

        $search = $this->service()->bootstrap($actor, $this->filters(['search' => '%_!', 'per_page' => 1]));
        $page = $this->service()->bootstrap($actor, $this->filters(['page' => 2, 'per_page' => 2]));

        $this->assertSame(1, $search['companies']['meta']['total']);
        $this->assertSame($wanted->id, $search['companies']['data'][0]['id']);
        $this->assertSame(2, $search['companies']['data'][0]['usage']['locations']);
        $this->assertSame(2, $search['companies']['data'][0]['usage']['team_members']);
        $this->assertSame(4, $page['companies']['meta']['total']);
        $this->assertSame(2, $page['companies']['meta']['current_page']);
        $this->assertCount(2, $page['companies']['data']);
    }

    public function test_overview_separates_recorded_simulation_and_test_payments_from_open_invoices(): void
    {
        $actor = PlatformAdmin::factory()->create();
        $tenant = Tenant::factory()->create(['is_demo' => true, 'operations_paused' => true]);
        $subscription = $this->subscription($tenant);
        foreach ([['simulation', 'simulation', 'paid', 49900], ['paymongo', 'test', 'paid', 199900], ['paymongo', 'test', 'open', 99900]] as [$provider, $mode, $status, $amount]) {
            BillingInvoice::factory()->create(['tenant_id' => $tenant->id, 'subscription_id' => $subscription->id,
                'payment_provider' => $provider, 'payment_mode' => $mode, 'status' => $status, 'amount_cents' => $amount]);
        }

        $overview = $this->service()->bootstrap($actor, $this->filters())['overview'];

        $this->assertEquals(499, $overview['paid_simulation_total']);
        $this->assertEquals(1999, $overview['paid_paymongo_test_total']);
        $this->assertSame(1, $overview['open_invoices']);
        $this->assertSame(1, $overview['paused_companies']);
        $this->assertSame(1, $overview['demo_companies']);
        $this->assertArrayNotHasKey('mrr', $overview);
    }

    public function test_invoice_and_activity_search_match_invoice_company_and_reason_with_literal_wildcards(): void
    {
        $actor = PlatformAdmin::factory()->create();
        $tenant = Tenant::factory()->create(['name' => 'Special%_! company']);
        $subscription = $this->subscription($tenant);
        $invoice = BillingInvoice::factory()->create(['tenant_id' => $tenant->id, 'subscription_id' => $subscription->id, 'number' => 'INV-UNIQUE-LABEL']);
        BillingInvoice::factory()->create();
        $entry = PlatformAuditLog::factory()->create(['tenant_id' => $tenant->id, 'reason' => 'Special%_! maintenance']);
        PlatformAuditLog::factory()->create();

        $companyMatch = $this->service()->bootstrap($actor, $this->filters(['search' => '%_!']));
        $invoiceMatch = $this->service()->bootstrap($actor, $this->filters(['search' => 'UNIQUE-LABEL']));
        $reasonMatch = $this->service()->bootstrap($actor, $this->filters(['search' => '%_! maintenance']));

        $this->assertSame(1, $companyMatch['invoices']['meta']['total']);
        $this->assertSame($invoice->id, $companyMatch['invoices']['data'][0]['id']);
        $this->assertSame($entry->id, $companyMatch['activity']['data'][0]['id']);
        $this->assertSame(1, $invoiceMatch['invoices']['meta']['total']);
        $this->assertSame(1, $reasonMatch['activity']['meta']['total']);
        $this->assertSame($entry->id, $reasonMatch['activity']['data'][0]['id']);
    }

    public function test_pause_and_resume_audit_only_the_target_company_and_override_demo_exemption(): void
    {
        $actor = PlatformAdmin::factory()->create();
        $tenant = Tenant::factory()->create(['is_demo' => true]);
        $foreign = Tenant::factory()->create();
        $this->subscription($tenant);

        $paused = $this->service()->mutate($actor, $tenant->id, 'pause', ['reason' => 'Maintenance requested', 'status' => 'paid']);
        $this->assertTrue($paused['company']['operations_paused']);
        $this->assertFalse($paused['company']['subscription']['can_write']);
        $this->assertFalse($foreign->fresh()->operations_paused);
        $this->assertSame('company.paused', PlatformAuditLog::first()->action);
        $this->assertFalse(PlatformAuditLog::first()->metadata['before']['operations_paused']);
        $this->assertTrue(PlatformAuditLog::first()->metadata['after']['operations_paused']);

        $resumed = $this->service()->mutate($actor, $tenant->id, 'resume', ['reason' => 'Maintenance finished']);
        $this->assertTrue($resumed['company']['subscription']['can_write']);
        $this->assertDatabaseCount('billing_invoices', 0);
        $this->assertDatabaseCount('platform_audit_logs', 2);
    }

    public function test_paid_period_plan_change_is_deferred_without_payment_or_trial_extension(): void
    {
        $actor = PlatformAdmin::factory()->create();
        $tenant = Tenant::factory()->create();
        $subscription = $this->subscription($tenant, ['status' => 'active', 'current_period_start' => now(), 'current_period_end' => now()->addMonthNoOverflow()]);
        $end = $subscription->current_period_end->toIso8601String();
        $trial = $subscription->trial_ends_at->toIso8601String();

        $response = $this->service()->mutate($actor, $tenant->id, 'plan', ['reason' => 'Annual plan requested', 'plan' => 'scale', 'billing_cycle' => 'annual']);

        $this->assertSame('grow', $response['company']['subscription']['plan']);
        $this->assertSame('scale', $response['company']['subscription']['pending_plan']);
        $this->assertSame('annual', $response['company']['subscription']['pending_billing_cycle']);
        $this->assertSame($end, $subscription->fresh()->current_period_end->toIso8601String());
        $this->assertSame($trial, $subscription->fresh()->trial_ends_at->toIso8601String());
        $this->assertDatabaseCount('billing_invoices', 0);
        Http::assertNothingSent();
    }

    public function test_trial_plan_change_preserves_company_settings_and_original_trial_date(): void
    {
        $actor = PlatformAdmin::factory()->create();
        $tenant = Tenant::factory()->create(['settings' => Tenant::defaultSettings() + ['custom' => 'retain']]);
        $subscription = $this->subscription($tenant);
        $trial = $subscription->trial_ends_at->toIso8601String();

        $this->service()->mutate($actor, $tenant->id, 'plan', ['reason' => 'Changed company needs', 'plan' => 'starter', 'billing_cycle' => 'monthly']);

        $this->assertSame('starter', $tenant->fresh()->settings['plan']);
        $this->assertSame('retain', $tenant->fresh()->settings['custom']);
        $this->assertSame($trial, $subscription->fresh()->trial_ends_at->toIso8601String());
        $this->assertDatabaseCount('billing_invoices', 0);
    }

    public function test_platform_plan_change_uses_the_existing_capacity_rules(): void
    {
        $actor = PlatformAdmin::factory()->create();
        $tenant = Tenant::factory()->create();
        $subscription = $this->subscription($tenant);
        Location::factory()->count(2)->create(['tenant_id' => $tenant->id]);

        try {
            $this->service()->mutate($actor, $tenant->id, 'plan', ['reason' => 'Smaller plan requested', 'plan' => 'starter', 'billing_cycle' => 'monthly']);
            $this->fail('An undersized plan was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('plan', $exception->errors());
        }
        $this->assertSame('grow', $subscription->fresh()->plan);
        $this->assertDatabaseCount('platform_audit_logs', 0);
    }

    #[TestWith(['creating', 'plan'])]
    #[TestWith(['pending', 'cancel-renewal'])]
    #[TestWith(['unknown', 'invoice'])]
    #[TestWith(['needs_review', 'plan'])]
    public function test_outstanding_checkouts_block_platform_billing_changes(string $status, string $action): void
    {
        $actor = PlatformAdmin::factory()->create();
        $tenant = Tenant::factory()->create();
        $subscription = $this->subscription($tenant);
        $invoice = BillingInvoice::factory()->create(['tenant_id' => $tenant->id, 'subscription_id' => $subscription->id]);
        BillingPaymentAttempt::factory()->create(['tenant_id' => $tenant->id, 'billing_invoice_id' => $invoice->id, 'status' => $status]);

        try {
            $this->service()->mutate($actor, $tenant->id, $action, ['reason' => 'Requested account adjustment', 'plan' => 'scale', 'billing_cycle' => 'annual']);
            $this->fail('Outstanding provider evidence was bypassed.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('payment', $exception->errors());
        }
        $this->assertSame('open', $invoice->fresh()->status);
        $this->assertSame('grow', $subscription->fresh()->plan);
        $this->assertFalse($subscription->fresh()->cancel_at_period_end);
        $this->assertDatabaseCount('platform_audit_logs', 0);
        Http::assertNothingSent();
    }

    public function test_invoice_creation_uses_server_prices_and_reuses_an_existing_open_invoice(): void
    {
        $actor = PlatformAdmin::factory()->create();
        $tenant = Tenant::factory()->create();
        $this->subscription($tenant, ['plan' => 'scale', 'billing_cycle' => 'annual']);

        $this->service()->mutate($actor, $tenant->id, 'invoice', ['reason' => 'Company requested invoice', 'amount_cents' => 1, 'status' => 'paid']);
        $this->service()->mutate($actor, $tenant->id, 'invoice', ['reason' => 'Confirmed invoice request']);

        $invoice = BillingInvoice::sole();
        $this->assertSame(config('billing.plans.scale.annual_cents'), $invoice->amount_cents);
        $this->assertSame('open', $invoice->status);
        $this->assertNull($invoice->paid_at);
        $this->assertDatabaseCount('billing_payment_attempts', 0);
        Http::assertNothingSent();
    }

    public function test_cancel_and_resume_renewal_keep_the_paid_period_and_history(): void
    {
        $actor = PlatformAdmin::factory()->create();
        $tenant = Tenant::factory()->create();
        $subscription = $this->subscription($tenant, ['status' => 'active', 'current_period_start' => now(), 'current_period_end' => now()->addMonthNoOverflow()]);
        $invoice = BillingInvoice::factory()->create(['tenant_id' => $tenant->id, 'subscription_id' => $subscription->id, 'status' => 'paid']);
        $original = $invoice->fresh()->getRawOriginal();
        $period = $subscription->current_period_end->toIso8601String();

        $this->service()->mutate($actor, $tenant->id, 'cancel-renewal', ['reason' => 'Company requested cancellation']);
        $this->assertTrue($subscription->fresh()->cancel_at_period_end);
        $this->service()->mutate($actor, $tenant->id, 'resume-renewal', ['reason' => 'Company requested continuation']);
        $this->assertFalse($subscription->fresh()->cancel_at_period_end);
        $this->assertSame($period, $subscription->fresh()->current_period_end->toIso8601String());
        $this->assertSame($original, $invoice->fresh()->getRawOriginal());
    }

    public function test_an_audit_failure_rolls_back_the_company_change(): void
    {
        $actor = PlatformAdmin::factory()->create();
        $tenant = Tenant::factory()->create();
        PlatformAuditLog::creating(function (PlatformAuditLog $entry): void {
            if ($entry->reason === 'cause-audit-failure') {
                throw new \RuntimeException('Private diagnostic details');
            }
        });
        try {
            $this->service()->mutate($actor, $tenant->id, 'pause', ['reason' => 'cause-audit-failure']);
            $this->fail('A change without its audit was committed.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Private diagnostic details', $exception->getMessage());
        }
        $this->assertFalse($tenant->fresh()->operations_paused);
        $this->assertDatabaseCount('platform_audit_logs', 0);
    }

    public function test_stale_developer_objects_do_not_authorize_reads_or_writes(): void
    {
        $actor = PlatformAdmin::factory()->create();
        $tenant = Tenant::factory()->create();
        PlatformAdmin::whereKey($actor->id)->update(['is_active' => false]);
        foreach (['read', 'write'] as $operation) {
            try {
                $operation === 'read' ? $this->service()->bootstrap($actor, $this->filters())
                    : $this->service()->mutate($actor, $tenant->id, 'pause', ['reason' => 'Requested maintenance']);
                $this->fail('A disabled actor was authorized.');
            } catch (HttpException $exception) {
                $this->assertSame(403, $exception->getStatusCode());
            }
        }
        $this->assertFalse($tenant->fresh()->operations_paused);
        $this->assertDatabaseCount('platform_audit_logs', 0);
    }

    public function test_invite_writes_only_a_hashed_expiring_token_and_private_setup_file(): void
    {
        $file = $this->outputFile();

        $status = Artisan::call('platform:invite', ['email' => '  DEV@EXAMPLE.COM ', '--name' => 'Developer Owner', '--output' => $file]);

        $this->assertSame(0, $status);
        $account = PlatformAdmin::sole();
        $html = file_get_contents($file);
        preg_match('~/developer/setup/([a-f0-9]{64})~', $html, $match);
        $this->assertArrayHasKey(1, $match);
        $this->assertSame(hash('sha256', $match[1]), $account->setup_token_hash);
        $this->assertSame('dev@example.com', $account->email);
        $this->assertFalse($account->is_active);
        $this->assertNull($account->password);
        $this->assertSame(now()->addHours(24)->toIso8601String(), $account->setup_expires_at->toIso8601String());
        $this->assertSame(0600, fileperms($file) & 0777);
        $this->assertStringNotContainsString($match[1], Artisan::output());
        $this->assertStringNotContainsString($account->setup_token_hash, Artisan::output());
        $this->assertStringContainsString('no-referrer', $html);
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('tenants', 0);
    }

    public function test_reinvitation_rotates_only_pending_tokens_and_never_overwrites_active_accounts(): void
    {
        $file = $this->outputFile();
        $args = ['email' => 'dev@example.com', '--output' => $file];
        $this->assertSame(0, Artisan::call('platform:invite', $args));
        $account = PlatformAdmin::sole();
        $old = $account->setup_token_hash;
        $this->assertSame(0, Artisan::call('platform:invite', $args));
        $this->assertNotSame($old, $account->fresh()->setup_token_hash);
        $account->update(['is_active' => true, 'password' => 'PrivateDeveloperPassword123!', 'setup_token_hash' => null, 'setup_expires_at' => null]);
        $before = $account->fresh()->getRawOriginal();
        $html = file_get_contents($file);

        $this->assertSame(1, Artisan::call('platform:invite', $args));

        $this->assertSame($before, $account->fresh()->getRawOriginal());
        $this->assertSame($html, file_get_contents($file));
        $this->assertDatabaseCount('platform_admins', 1);
        $this->assertDatabaseCount('platform_audit_logs', 2);
    }

    public function test_invite_rejects_public_or_symlink_output_without_provisioning_an_account(): void
    {
        $this->assertSame(1, Artisan::call('platform:invite', ['email' => 'dev@example.com', '--output' => public_path('private-developer.html')]));
        $file = $this->outputFile();
        $target = $this->outputFile();
        file_put_contents($target, 'existing private content');
        symlink($target, $file);
        try {
            $this->assertSame(1, Artisan::call('platform:invite', ['email' => 'dev@example.com', '--output' => $file]));
            $this->assertSame('existing private content', file_get_contents($target));
            $this->assertDatabaseCount('platform_admins', 0);
        } finally {
            unlink($file);
        }
    }
}
