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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
use RuntimeException;
use Tests\TestCase;

class SiteContentTest extends TestCase
{
    use RefreshDatabase;

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

    #[TestWith(['/', 'POSONLY | Cloud Inventory & POS System in the Philippines'])]
    #[TestWith(['/inventory-management', 'Cloud Inventory Management Software Philippines | POSONLY'])]
    #[TestWith(['/point-of-sale', 'Cloud POS Software for Philippine Businesses | POSONLY'])]
    #[TestWith(['/about', 'About POSONLY | Cloud Inventory and POS in the Philippines'])]
    #[TestWith(['/guide', 'POSONLY User Guide | Inventory & POS Handbook'])]
    #[TestWith(['/privacy', 'Privacy Policy | POSONLY'])]
    public function test_public_metadata_matches_its_page_and_publishes_the_trusted_social_image(string $path, string $title): void
    {
        Http::preventStrayRequests();
        config(['app.url' => 'https://posonly.example']);

        $response = $this->get($path)->assertOk();
        $document = new DOMDocument;
        $document->loadHTML($response->getContent(), LIBXML_NOERROR | LIBXML_NOWARNING);
        $xpath = new DOMXPath($document);

        $this->assertCount(1, $xpath->query('//head/title'));
        $this->assertSame($title, $xpath->evaluate('string(//head/title)'));
        $this->assertSame($title, $xpath->evaluate('string(//meta[@property="og:title"]/@content)'));
        $this->assertSame($title, $xpath->evaluate('string(//meta[@name="twitter:title"]/@content)'));
        $description = $xpath->evaluate('string(//meta[@name="description"]/@content)');
        $this->assertNotEmpty($description);
        $this->assertSame($description, $xpath->evaluate('string(//meta[@property="og:description"]/@content)'));
        $this->assertSame($description, $xpath->evaluate('string(//meta[@name="twitter:description"]/@content)'));
        $this->assertSame('https://posonly.example'.$path, $xpath->evaluate('string(//link[@rel="canonical"]/@href)'));
        $this->assertSame('summary_large_image', $xpath->evaluate('string(//meta[@name="twitter:card"]/@content)'));
        $this->assertSame('https://posonly.example/images/posonly-social.png', $xpath->evaluate('string(//meta[@property="og:image"]/@content)'));
        $this->assertSame('https://posonly.example/images/posonly-social.png', $xpath->evaluate('string(//meta[@name="twitter:image"]/@content)'));
        $this->assertSame('1200', $xpath->evaluate('string(//meta[@property="og:image:width"]/@content)'));
        $this->assertSame('630', $xpath->evaluate('string(//meta[@property="og:image:height"]/@content)'));
        $this->assertSame('/images/favicon.png', $xpath->evaluate('string(//link[@rel="icon"]/@href)'));
        Http::assertNothingSent();
    }

    public function test_social_preview_asset_matches_its_published_dimensions_and_format(): void
    {
        $image = public_path('images/posonly-social.png');

        $this->assertFileExists($image);
        $dimensions = getimagesize($image);

        $this->assertSame(1200, $dimensions[0]);
        $this->assertSame(630, $dimensions[1]);
        $this->assertSame('image/png', $dimensions['mime']);
    }

    public function test_homepage_structured_data_links_the_site_page_and_software_without_invented_ratings_or_addresses(): void
    {
        Http::preventStrayRequests();
        config(['app.url' => 'https://posonly.example']);

        $response = $this->get('/')->assertOk()->assertSeeText('Inventory management and point of sale for Philippine businesses.');
        $document = new DOMDocument;
        $document->loadHTML($response->getContent(), LIBXML_NOERROR | LIBXML_NOWARNING);
        $scripts = (new DOMXPath($document))->query('//script[@type="application/ld+json"]');

        $this->assertCount(1, $scripts);
        $data = json_decode($scripts->item(0)->textContent, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('https://schema.org', $data['@context']);
        $this->assertSame(['Organization', 'WebSite', 'WebPage', 'SoftwareApplication'], array_column($data['@graph'], '@type'));
        $this->assertSame('POSONLY', $data['@graph'][0]['name']);
        $this->assertSame('https://posonly.example/', $data['@graph'][1]['url']);
        $this->assertSame('https://posonly.example/images/favicon.png', $data['@graph'][0]['logo']['url']);
        $this->assertSame(192, $data['@graph'][0]['logo']['width']);
        $this->assertSame(192, $data['@graph'][0]['logo']['height']);
        $this->assertSame('https://posonly.example/#organization', $data['@graph'][0]['@id']);
        $this->assertSame('https://posonly.example/#website', $data['@graph'][1]['@id']);
        $this->assertSame(['@id' => 'https://posonly.example/#website'], $data['@graph'][2]['isPartOf']);
        $this->assertSame(['@id' => 'https://posonly.example/#software'], $data['@graph'][2]['mainEntity']);
        $this->assertSame('https://posonly.example/#software', $data['@graph'][3]['@id']);
        $this->assertSame('BusinessApplication', $data['@graph'][3]['applicationCategory']);
        $this->assertSame(['PH'], $data['@graph'][3]['countriesSupported']);
        foreach ($data['@graph'] as $entity) {
            $this->assertArrayNotHasKey('aggregateRating', $entity);
            $this->assertArrayNotHasKey('review', $entity);
            $this->assertArrayNotHasKey('address', $entity);
            $this->assertArrayNotHasKey('sameAs', $entity);
            $this->assertNotSame('LocalBusiness', $entity['@type']);
        }
        Http::assertNothingSent();
    }

    #[TestWith(['/inventory-management', 'WebPage', true])]
    #[TestWith(['/point-of-sale', 'WebPage', true])]
    #[TestWith(['/about', 'AboutPage', false])]
    #[TestWith(['/guide', 'WebPage', false])]
    #[TestWith(['/privacy', 'WebPage', false])]
    public function test_public_pages_share_site_identities_and_keep_their_own_canonical_page(string $path, string $pageType, bool $hasSoftware): void
    {
        Http::preventStrayRequests();
        config(['app.url' => 'https://posonly.example']);

        $response = $this->get('https://untrusted.example'.$path.'?token=ignored')->assertOk();
        $document = new DOMDocument;
        $document->loadHTML($response->getContent(), LIBXML_NOERROR | LIBXML_NOWARNING);
        $scripts = (new DOMXPath($document))->query('//script[@type="application/ld+json"]');
        $this->assertCount(1, $scripts);
        $graph = json_decode($scripts->item(0)->textContent, true, flags: JSON_THROW_ON_ERROR)['@graph'];

        $this->assertSame('https://posonly.example/#organization', $graph[0]['@id']);
        $this->assertSame('https://posonly.example/', $graph[0]['url']);
        $this->assertSame('https://posonly.example/#website', $graph[1]['@id']);
        $this->assertSame('https://posonly.example/', $graph[1]['url']);
        $this->assertSame($pageType, $graph[2]['@type']);
        $this->assertSame('https://posonly.example'.$path.'#webpage', $graph[2]['@id']);
        $this->assertSame('https://posonly.example'.$path, $graph[2]['url']);
        $this->assertSame(['@id' => 'https://posonly.example/#website'], $graph[2]['isPartOf']);
        $this->assertSame(['@id' => 'https://posonly.example/#organization'], $graph[2]['publisher']);
        $this->assertSame($hasSoftware, in_array('SoftwareApplication', array_column($graph, '@type'), true));
        $this->assertStringNotContainsString('untrusted.example', json_encode($graph, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('ignored', json_encode($graph, JSON_THROW_ON_ERROR));
        if ($pageType === 'AboutPage') {
            $this->assertSame(['@id' => 'https://posonly.example/#organization'], $graph[2]['about']);
        }
        Http::assertNothingSent();
    }

    public function test_software_offers_match_configured_invoice_prices_and_billing_periods(): void
    {
        Http::preventStrayRequests();
        config(['app.url' => 'https://posonly.example', 'billing.plans.starter.monthly_cents' => 73525,
            'billing.plans.starter.annual_cents' => 823025]);

        $response = $this->get('/')->assertOk();
        $document = new DOMDocument;
        $document->loadHTML($response->getContent(), LIBXML_NOERROR | LIBXML_NOWARNING);
        $scripts = (new DOMXPath($document))->query('//script[@type="application/ld+json"]');
        $graph = json_decode($scripts->item(0)->textContent, true, flags: JSON_THROW_ON_ERROR)['@graph'];
        $offers = $graph[3]['offers'];

        $this->assertCount(6, $offers);
        $this->assertSame(['Starter monthly subscription', 'Starter annual subscription', 'Grow monthly subscription',
            'Grow annual subscription', 'Scale monthly subscription', 'Scale annual subscription'], array_column($offers, 'name'));
        $this->assertSame(['735.25', '8230.25', '999.00', '9590.40', '1999.00', '19190.40'], array_column($offers, 'price'));
        $this->assertSame(['P1M', 'P1Y', 'P1M', 'P1Y', 'P1M', 'P1Y'], array_column(array_column($offers, 'priceSpecification'), 'billingDuration'));
        foreach ($offers as $offer) {
            $this->assertSame('PHP', $offer['priceCurrency']);
            $this->assertSame('https://posonly.example/#pricing', $offer['url']);
            $this->assertSame($offer['price'], $offer['priceSpecification']['price']);
            $this->assertSame('PHP', $offer['priceSpecification']['priceCurrency']);
            $this->assertStringContainsString('no automatic recurring charge', $offer['description']);
        }
        $this->assertDatabaseCount('subscriptions', 0);
        $this->assertDatabaseCount('billing_invoices', 0);
        Http::assertNothingSent();
    }

    #[TestWith(['/inventory-management', 'Inventory management'])]
    #[TestWith(['/point-of-sale', 'Point of sale'])]
    #[TestWith(['/about', 'About POSONLY'])]
    public function test_marketing_breadcrumbs_match_visible_navigation_and_trusted_canonical_urls(string $path, string $label): void
    {
        Http::preventStrayRequests();
        config(['app.url' => 'https://posonly.example']);

        $response = $this->get('https://untrusted.example'.$path.'?token=ignored')->assertOk();
        $document = new DOMDocument;
        $document->loadHTML($response->getContent(), LIBXML_NOERROR | LIBXML_NOWARNING);
        $xpath = new DOMXPath($document);
        $scripts = $xpath->query('//script[@type="application/ld+json"]');
        $graph = json_decode($scripts->item(0)->textContent, true, flags: JSON_THROW_ON_ERROR)['@graph'];
        $breadcrumbs = array_values(array_filter($graph, fn (array $entity): bool => $entity['@type'] === 'BreadcrumbList'));

        $this->assertSame('Home', $xpath->evaluate('string(//nav[@aria-label="Breadcrumb"]/a)'));
        $this->assertSame($label, $xpath->evaluate('string(//nav[@aria-label="Breadcrumb"]/*[@aria-current="page"])'));
        $this->assertCount(1, $breadcrumbs);
        $this->assertSame('https://posonly.example'.$path.'#breadcrumb', $breadcrumbs[0]['@id']);
        $this->assertSame(['@id' => 'https://posonly.example'.$path.'#breadcrumb'], $graph[2]['breadcrumb']);
        $this->assertSame([
            ['@type' => 'ListItem', 'position' => 1, 'name' => 'Home', 'item' => 'https://posonly.example/'],
            ['@type' => 'ListItem', 'position' => 2, 'name' => $label, 'item' => 'https://posonly.example'.$path],
        ], $breadcrumbs[0]['itemListElement']);
        Http::assertNothingSent();
    }

    public function test_custom_published_metadata_is_preserved_and_cannot_end_its_json_script(): void
    {
        Http::preventStrayRequests();
        config(['app.url' => 'https://posonly.example']);
        $title = 'A company-approved title & description';
        $description = 'Owner text </script><script id="metadata-injection">alert("x")</script> & details';
        SiteContent::factory()->create(['published' => $this->content(['site_title' => $title, 'meta_description' => $description])]);

        $response = $this->get('/')->assertOk();
        $document = new DOMDocument;
        $document->loadHTML($response->getContent(), LIBXML_NOERROR | LIBXML_NOWARNING);
        $xpath = new DOMXPath($document);
        $data = json_decode($xpath->query('//script[@type="application/ld+json"]')->item(0)->textContent, true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame($title, $xpath->evaluate('string(//head/title)'));
        $this->assertSame($description, $xpath->evaluate('string(//meta[@name="description"]/@content)'));
        $this->assertSame($description, $data['@graph'][1]['description']);
        $this->assertSame($description, $data['@graph'][2]['description']);
        $this->assertCount(0, $xpath->query('//script[@id="metadata-injection"]'));
        Http::assertNothingSent();
    }

    public function test_private_cms_preview_cannot_publish_canonical_or_structured_site_data(): void
    {
        Http::preventStrayRequests();
        config(['app.url' => 'https://posonly.example']);

        $response = $this->actingAs($this->developer(), 'developer')->get('/developer/content/preview')->assertOk();
        $document = new DOMDocument;
        $document->loadHTML($response->getContent(), LIBXML_NOERROR | LIBXML_NOWARNING);
        $xpath = new DOMXPath($document);

        $this->assertSame('noindex, nofollow', $xpath->evaluate('string(//meta[@name="robots"]/@content)'));
        $this->assertCount(0, $xpath->query('//link[@rel="canonical"] | //meta[@property="og:url"] | //meta[@property="og:image"] | //meta[@name="twitter:image"] | //script[@type="application/ld+json"]'));
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
