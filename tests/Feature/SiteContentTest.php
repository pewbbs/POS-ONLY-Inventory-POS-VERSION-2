<?php

namespace Tests\Feature;

use App\Models\PlatformAdmin;
use App\Models\PlatformAuditLog;
use App\Models\SiteContent;
use App\Models\Tenant;
use App\Models\User;
use App\Services\SiteContentService;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
use RuntimeException;
use Tests\TestCase;

class SiteContentTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function content(array $overrides = []): array
    {
        return array_replace(app(SiteContentService::class)->defaults(), $overrides);
    }

    private function developer(): PlatformAdmin
    {
        return PlatformAdmin::factory()->create();
    }

    public function test_public_page_uses_defaults_without_creating_content_or_billing_records(): void
    {
        Http::preventStrayRequests();
        Tenant::factory()->create();
        $this->get('/')->assertOk()->assertSee('LESS ADMIN.')->assertSee('MORE SELLING.');
        $this->assertDatabaseCount('site_contents', 0);
        $this->assertDatabaseCount('subscriptions', 0);
        $this->assertDatabaseCount('billing_invoices', 0);
        Http::assertNothingSent();
    }

    public function test_privacy_policy_is_public_and_explains_google_account_data(): void
    {
        Http::preventStrayRequests();

        $response = $this->get('/privacy');

        $response->assertOk()->assertViewIs('privacy')
            ->assertSeeText('Google sign-in')
            ->assertSeeText('Pablito Torres, trading as POSONLY')
            ->assertSeeText('PayMongo hosted checkout')
            ->assertSeeText('plan description, invoice amount in Philippine pesos, a payment reference, and checkout return URLs')
            ->assertSeeText('does not collect or store your card number or security code')
            ->assertSee('mailto:pewbbs@gmail.com', false)
            ->assertSee('href="https://www.paymongo.com/en/privacy"', false);
        Http::assertNothingSent();
    }

    public function test_live_public_pages_show_real_invoice_checkout_and_only_configured_methods(): void
    {
        Http::preventStrayRequests();
        $this->app->detectEnvironment(fn (): string => 'production');
        config(['billing.payment_provider' => 'paymongo', 'paymongo.enabled' => true, 'paymongo.mode' => 'live',
            'paymongo.secret_key' => 'sk_live_public_copy_fixture', 'paymongo.webhook_secret' => 'public-copy-signing-fixture',
            'paymongo.payment_methods' => ['qrph'], 'app.url' => 'https://posonly.example']);

        $this->get('/')->assertOk()->assertSeeText('PLANS · HOSTED PAYMENTS')
            ->assertSeeText('Live mode collects real payments')
            ->assertDontSeeText('These are test records');
        $this->get('/guide')->assertOk()->assertSeeText('Pay with PayMongo (live)')
            ->assertSeeText('Configured methods: QR Ph')
            ->assertSeeText('Open PayMongo checkout')
            ->assertDontSeeText('4343434343434345')
            ->assertDontSeeText('Configured methods: Card')
            ->assertDontSee('sk_live_public_copy_fixture')
            ->assertDontSee('public-copy-signing-fixture');

        $this->assertDatabaseCount('billing_invoices', 0);
        Http::assertNothingSent();
    }

    public function test_enabled_test_guide_keeps_test_payment_details_separate_from_live_checkout(): void
    {
        Http::preventStrayRequests();
        config(['billing.payment_provider' => 'paymongo', 'paymongo.enabled' => true, 'paymongo.mode' => 'test',
            'paymongo.secret_key' => 'sk_test_public_copy_fixture', 'paymongo.payment_methods' => ['card', 'gcash', 'qrph'],
            'app.url' => 'http://localhost']);

        $this->get('/')->assertOk()->assertSeeText('PLANS · TEST BILLING');
        $this->get('/guide')->assertOk()->assertSeeText('Pay with PayMongo (test)')
            ->assertSeeText('Open PayMongo test checkout')
            ->assertSeeText('Configured methods: Card, GCash')
            ->assertSeeText('4343434343434345')
            ->assertDontSeeText('Configured methods: QR Ph')
            ->assertDontSee('sk_test_public_copy_fixture');

        Http::assertNothingSent();
    }

    public function test_production_does_not_offer_test_card_instructions_when_test_mode_is_disabled(): void
    {
        Http::preventStrayRequests();
        $this->app->detectEnvironment(fn (): string => 'production');
        config(['billing.payment_provider' => 'paymongo', 'paymongo.enabled' => true, 'paymongo.mode' => 'test',
            'paymongo.secret_key' => 'sk_test_public_copy_fixture', 'app.url' => 'https://posonly.example']);

        $this->get('/guide')->assertOk()->assertSeeText('If configuration is incomplete, checkout remains disabled.')
            ->assertDontSeeText('4343434343434345');

        Http::assertNothingSent();
    }

    #[TestWith(['/'])]
    #[TestWith(['/login'])]
    #[TestWith(['/register'])]
    public function test_public_entry_pages_link_to_the_privacy_policy(string $path): void
    {
        Http::preventStrayRequests();

        $response = $this->get($path);

        $response->assertOk()->assertSee('href="'.route('privacy').'"', false);
        Http::assertNothingSent();
    }

    public function test_draft_is_private_until_published_and_both_mutations_are_audited(): void
    {
        $admin = $this->developer();
        $this->actingAs($admin, 'developer')->putJson('/api/developer/content', [
            'version' => 0, 'reason' => 'Update the homepage copy', 'content' => $this->content(['hero_title_line1' => 'YOUR STORE, SORTED.']),
        ])->assertOk()->assertJsonPath('version', 1)->assertJsonPath('has_unpublished_changes', true);
        $this->get('/')->assertOk()->assertDontSee('YOUR STORE, SORTED.');
        $this->get('/developer/content/preview')->assertOk()->assertSee('YOUR STORE, SORTED.')->assertSee('Website draft preview');
        $this->postJson('/api/developer/content/publish', ['version' => 1, 'reason' => 'Approve the local homepage copy'])
            ->assertOk()->assertJsonPath('version', 2)->assertJsonPath('has_unpublished_changes', false);
        $this->get('/')->assertOk()->assertSee('YOUR STORE, SORTED.');
        $this->assertDatabaseHas('platform_audit_logs', ['platform_admin_id' => $admin->id, 'action' => 'content.draft_saved', 'reason' => 'Update the homepage copy']);
        $this->assertDatabaseHas('platform_audit_logs', ['platform_admin_id' => $admin->id, 'action' => 'content.published', 'reason' => 'Approve the local homepage copy']);
    }

    public function test_stale_save_and_publish_do_not_overwrite_the_current_draft(): void
    {
        $this->actingAs($this->developer(), 'developer')->putJson('/api/developer/content', [
            'version' => 0, 'reason' => 'First local draft', 'content' => $this->content(['hero_title_line1' => 'FIRST DRAFT']),
        ])->assertOk();
        $this->putJson('/api/developer/content', ['version' => 0, 'reason' => 'Stale second draft', 'content' => $this->content(['hero_title_line1' => 'OLD EDIT'])])
            ->assertConflict()->assertJsonPath('message', 'Website content changed in another session. Reload the editor before saving.');
        $this->postJson('/api/developer/content/publish', ['version' => 0, 'reason' => 'Stale publish'])->assertConflict();
        $this->assertSame('FIRST DRAFT', SiteContent::first()->draft['hero_title_line1']);
        $this->assertDatabaseCount('platform_audit_logs', 1);
    }

    public function test_content_is_escaped_in_public_page_and_authenticated_preview(): void
    {
        $hostile = '<script>alert("cms")</script>';
        $admin = $this->developer();
        SiteContent::factory()->create(['draft' => $this->content(['hero_title_line1' => $hostile]),
            'published' => $this->content(['site_title' => $hostile, 'hero_title_line1' => $hostile, 'footer_tagline' => $hostile])]);
        $this->get('/')->assertOk()->assertSee($hostile)->assertDontSee($hostile, false);
        $this->actingAs($admin, 'developer')->get('/developer/content/preview')->assertOk()->assertSee($hostile)->assertDontSee($hostile, false);
    }

    #[DataProvider('tenantRoles')]
    public function test_company_roles_cannot_read_edit_or_preview_global_content(string $role): void
    {
        $user = User::factory()->create(['tenant_id' => Tenant::factory(), 'role' => $role]);
        $this->actingAs($user, 'web')->getJson('/api/developer/content')->assertUnauthorized();
        $this->putJson('/api/developer/content', ['version' => 0, 'reason' => 'Unauthorized edit', 'content' => $this->content()])->assertUnauthorized();
        $this->postJson('/api/developer/content/publish', ['version' => 0, 'reason' => 'Unauthorized publish'])->assertUnauthorized();
        $this->get('/developer/content/preview')->assertRedirect('/developer/login');
        $this->assertDatabaseCount('site_contents', 0);
    }

    public static function tenantRoles(): array
    {
        return [['admin'], ['manager'], ['cashier']];
    }

    public function test_guest_cannot_read_drafts_and_authorized_reads_create_no_rows(): void
    {
        $this->getJson('/api/developer/content')->assertUnauthorized();
        $this->get('/developer/content/preview')->assertRedirect('/developer/login');
        $this->actingAs($this->developer(), 'developer')->getJson('/api/developer/content')->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')->assertJsonPath('version', 0);
        $this->assertDatabaseCount('site_contents', 0);
    }

    public function test_invalid_fields_or_reason_do_not_create_a_draft_or_audit(): void
    {
        $this->actingAs($this->developer(), 'developer')->putJson('/api/developer/content', [
            'version' => 0, 'reason' => '', 'content' => $this->content(['support_email' => 'javascript:alert(1)', 'primary_cta' => str_repeat('X', 61)]),
        ])->assertUnprocessable()->assertJsonValidationErrors(['reason', 'content.support_email', 'content.primary_cta']);
        $this->assertDatabaseCount('site_contents', 0);
        $this->assertDatabaseCount('platform_audit_logs', 0);
    }

    public function test_unknown_cms_keys_cannot_change_billing_configuration_or_privileges(): void
    {
        $original = config('billing.plans');
        $this->actingAs($this->developer(), 'developer')->putJson('/api/developer/content', [
            'version' => 0, 'reason' => 'Unsupported plan override', 'content' => $this->content(['plans' => ['scale' => ['monthly_cents' => 1]], 'is_active' => true]),
        ])->assertUnprocessable()->assertJsonValidationErrors('content');
        $this->assertSame($original, config('billing.plans'));
        $this->assertDatabaseCount('site_contents', 0);
    }

    public function test_landing_uses_configured_monthly_and_annual_prices_and_limits(): void
    {
        config(['billing.plans.starter.monthly_cents' => 73500, 'billing.plans.starter.annual_cents' => 823000,
            'billing.plans.starter.locations' => 7, 'billing.plans.starter.team_members' => 13]);
        $this->get('/')->assertOk()->assertSee('₱735')->assertSee('data-annual="8230"', false)
            ->assertSee('7 store locations')->assertSee('13 team members');
    }

    #[TestWith(['testing', false])]
    #[TestWith(['production', false])]
    #[TestWith(['production', true])]
    public function test_unavailable_workspace_demo_ctas_link_to_registration_and_keep_the_pos_sample(string $environment, bool $demoEnabled): void
    {
        config(['stockroom.demo_enabled' => $demoEnabled]);
        $this->app->detectEnvironment(fn (): string => $environment);

        $response = $this->get('/')->assertOk()
            ->assertDontSee('action="/demo"', false)->assertDontSee('Try live demo')->assertDontSee('Open the full live demo')
            ->assertSee('Create workspace')->assertSee('Create your own workspace')
            ->assertSee('interactive POS preview')->assertSee('Complete sample sale')->assertSee('Preview only. No payment is charged.')
            ->assertSee('data-workspace-demo-enabled="false"', false)->assertSee('id="demo-pay"', false);
        $document = new DOMDocument;
        $document->loadHTML($response->getContent(), LIBXML_NOERROR | LIBXML_NOWARNING);
        $links = (new DOMXPath($document))->query('//header//a[contains(@class, "button")] | //a[contains(@class, "feature-link")] | //div[contains(@class, "demo-under")]//a');
        $this->assertCount(5, $links);
        foreach ($links as $link) {
            $this->assertSame('/register', $link->getAttribute('href'));
            $this->assertStringContainsString('workspace', $link->textContent);
        }
        $this->assertDatabaseCount('tenants', 0);
        $this->assertDatabaseCount('sales', 0);
    }

    public function test_enabled_local_workspace_demo_retains_its_section_forms_and_sample(): void
    {
        config(['stockroom.demo_enabled' => true]);
        $this->app->detectEnvironment(fn (): string => 'local');

        $response = $this->get('/')->assertOk()->assertSee('Try live demo')->assertSee('Open the full live demo')
            ->assertSee('Explore inventory')->assertSee('Explore point of sale')->assertSee('Explore your insights')
            ->assertSee('data-workspace-demo-enabled="true"', false)->assertSee('Complete sample sale');
        $document = new DOMDocument;
        $document->loadHTML($response->getContent(), LIBXML_NOERROR | LIBXML_NOWARNING);
        $forms = (new DOMXPath($document))->query('//form[@action="/demo"]');
        $this->assertCount(5, $forms);
        foreach ($forms as $form) {
            $this->assertSame('POST', $form->getAttribute('method'));
        }
        $this->assertDatabaseCount('tenants', 0);
        $this->assertDatabaseCount('sales', 0);
    }

    public function test_paused_developer_cannot_save_with_a_stale_actor_object(): void
    {
        $admin = $this->developer();
        PlatformAdmin::whereKey($admin->id)->update(['is_active' => false]);
        $this->actingAs($admin, 'developer')->putJson('/api/developer/content', [
            'version' => 0, 'reason' => 'Paused developer attempt', 'content' => $this->content(),
        ])->assertForbidden();
        $this->assertDatabaseCount('site_contents', 0);
        $this->assertDatabaseCount('platform_audit_logs', 0);
    }

    public function test_audit_failure_rolls_back_the_draft_and_returns_a_sanitized_error(): void
    {
        $admin = $this->developer();
        $original = SiteContent::factory()->create(['published' => $this->content(), 'version' => 7]);
        PlatformAuditLog::creating(static function (): void {
            throw new RuntimeException('Private audit storage failure');
        });
        try {
            $this->actingAs($admin, 'developer')->putJson('/api/developer/content', [
                'version' => 7, 'reason' => 'Draft requiring an audit', 'content' => $this->content(['hero_title_line1' => 'SHOULD ROLL BACK']),
            ])->assertStatus(503)->assertDontSee('Private audit storage failure');
        } finally {
            PlatformAuditLog::flushEventListeners();
        }
        $this->assertSame(7, $original->fresh()->version);
        $this->assertNull($original->fresh()->draft);
        $this->assertDatabaseCount('platform_audit_logs', 0);
    }
}
