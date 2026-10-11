<?php

namespace Tests\Feature;

use App\Models\PlatformAdmin;
use App\Models\SiteContent;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Services\MonitoringService;
use App\Services\SiteContentService;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\TestWith;
use RuntimeException;
use Tests\TestCase;

class SeoEndpointsTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.url' => 'https://posonly.example', 'app.debug' => false, 'seo.indexing_enabled' => true]);
        Http::preventStrayRequests();
    }

    private function production(): void
    {
        $this->app->detectEnvironment(fn (): string => 'production');
    }

    private function assertPrivate(TestResponse $response): void
    {
        $response->assertHeader('X-Robots-Tag', 'noindex, nofollow')
            ->assertHeader('Cache-Control', 'no-store, private');
    }

    /** @return list<string> */
    private function sitemapUrls(TestResponse $response): array
    {
        $document = new DOMDocument;
        $this->assertTrue($document->loadXML($response->getContent()));
        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('sitemap', 'http://www.sitemaps.org/schemas/sitemap/0.9');

        return array_map(fn ($node): string => $node->textContent, iterator_to_array($xpath->query('/sitemap:urlset/sitemap:url/sitemap:loc')));
    }

    public function test_production_sitemap_contains_only_three_trusted_public_urls_without_queries_or_invented_dates(): void
    {
        $tenant = Tenant::factory()->create(['name' => 'Private business outside the sitemap']);
        User::factory()->create(['tenant_id' => $tenant->id, 'email' => 'private-owner@example.test']);
        $before = $tenant->refresh()->getAttributes();
        $this->production();
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $response = $this->get('https://untrusted.example/sitemap.xml?tenant_id='.$tenant->id.'&token=private-token')
            ->assertOk()->assertHeader('Content-Type', 'application/xml; charset=UTF-8')
            ->assertHeader('X-Content-Type-Options', 'nosniff');

        $this->assertPrivate($response);
        $this->assertSame(['https://posonly.example/', 'https://posonly.example/guide', 'https://posonly.example/privacy'], $this->sitemapUrls($response));
        $this->assertSame([], $queries);
        foreach (['lastmod', '/app', '/api', '/login', '/register', '/developer', '/webhooks', 'private-token', 'untrusted.example', $tenant->name, 'private-owner@example.test'] as $privateValue) {
            $response->assertDontSee($privateValue, false);
        }
        $this->assertSame($before, $tenant->refresh()->getAttributes());
        $this->assertDatabaseCount('site_contents', 0);
        $this->assertDatabaseCount('subscriptions', 0);
        $this->assertDatabaseCount('billing_invoices', 0);
        $this->assertDatabaseCount('billing_payment_attempts', 0);
        Http::assertNothingSent();
    }

    public function test_production_robots_allows_crawling_and_lists_the_trusted_dynamic_sitemap(): void
    {
        $this->production();

        $response = $this->get('https://www.untrusted.example/robots.txt?token=private-token')->assertOk()
            ->assertHeader('Content-Type', 'text/plain; charset=UTF-8')->assertHeader('X-Content-Type-Options', 'nosniff');

        $this->assertPrivate($response);
        $this->assertSame("User-agent: *\nAllow: /\nSitemap: https://posonly.example/sitemap.xml\n", $response->getContent());
        $this->assertFileDoesNotExist(public_path('robots.txt'));
        Http::assertNothingSent();
    }

    #[TestWith(['/'])]
    #[TestWith(['/guide'])]
    #[TestWith(['/privacy'])]
    public function test_only_production_public_pages_are_indexable_with_canonicals_independent_of_request_host_and_query(string $path): void
    {
        $this->production();

        $response = $this->get('https://www.untrusted.example'.$path.'?utm_source=campaign&token=ignored')->assertOk()
            ->assertHeader('X-Robots-Tag', 'index, follow')->assertHeader('Cache-Control', 'no-store, private');

        $response->assertViewHas('seo', fn (array $seo): bool => $seo['canonical_url'] === 'https://posonly.example'.$path
            && $seo['site_url'] === 'https://posonly.example' && $seo['indexable'] === true
            && $seo['robots'] === 'index, follow' && $seo['image_url'] === null);
        Http::assertNothingSent();
    }

    public function test_head_requests_follow_the_same_public_indexing_policy(): void
    {
        $this->production();

        $this->head('/privacy')->assertOk()->assertHeader('X-Robots-Tag', 'index, follow')
            ->assertHeader('Cache-Control', 'no-store, private');
    }

    #[TestWith(['local'])]
    #[TestWith(['staging'])]
    #[TestWith(['testing'])]
    public function test_nonproduction_environments_do_not_publish_indexable_pages_or_sitemap_entries(string $environment): void
    {
        $this->app->detectEnvironment(fn (): string => $environment);
        foreach (['/', '/guide', '/privacy'] as $path) {
            $response = $this->get($path)->assertOk()->assertViewHas('seo', fn (array $seo): bool => $seo['indexable'] === false);
            $this->assertPrivate($response);
        }
        $sitemap = $this->get('/sitemap.xml')->assertOk();
        $this->assertPrivate($sitemap);
        $this->assertSame([], $this->sitemapUrls($sitemap));
        $robots = $this->get('/robots.txt')->assertOk();
        $this->assertPrivate($robots);
        $this->assertSame("User-agent: *\nDisallow: /\n", $robots->getContent());
        Http::assertNothingSent();
    }

    public function test_indexing_can_be_disabled_explicitly_in_production(): void
    {
        $this->production();
        config(['seo.indexing_enabled' => false]);

        $this->assertPrivate($this->get('/')->assertOk());
        $this->assertSame([], $this->sitemapUrls($this->get('/sitemap.xml')->assertOk()));
        $this->assertSame("User-agent: *\nDisallow: /\n", $this->get('/robots.txt')->assertOk()->getContent());
    }

    #[TestWith([''])]
    #[TestWith(['not-a-url'])]
    #[TestWith(['http://posonly.example'])]
    #[TestWith(['https://user@posonly.example'])]
    #[TestWith(['https://user:password@posonly.example'])]
    #[TestWith(['https://posonly.example/subdirectory'])]
    #[TestWith(['https://posonly.example?token=secret'])]
    #[TestWith(['https://posonly.example#fragment'])]
    public function test_invalid_production_site_urls_fail_closed_without_exposing_untrusted_canonicals(string $siteUrl): void
    {
        $this->production();
        config(['app.url' => $siteUrl]);

        $home = $this->get('/')->assertOk()->assertViewHas('seo', fn (array $seo): bool => $seo['canonical_url'] === null
            && $seo['site_url'] === null && $seo['indexable'] === false);
        $this->assertPrivate($home);
        $this->assertPrivate($this->get('/sitemap.xml')->assertStatus(503));
        $robots = $this->get('/robots.txt')->assertOk();
        $this->assertPrivate($robots);
        $this->assertSame("User-agent: *\nDisallow: /\n", $robots->getContent());
        Http::assertNothingSent();
    }

    #[TestWith(['/login', 200])]
    #[TestWith(['/register', 200])]
    #[TestWith(['/forgot-password', 200])]
    #[TestWith(['/reset-password/private-token?email=staff@example.test', 200])]
    #[TestWith(['/developer/login', 200])]
    #[TestWith(['/developer/setup/private-token?email=owner@example.test', 404])]
    #[TestWith(['/app/billing', 302])]
    #[TestWith(['/account/connections', 302])]
    #[TestWith(['/developer/content/preview', 302])]
    #[TestWith(['/missing-page', 404])]
    #[TestWith(['/webhooks/paymongo', 405])]
    #[TestWith(['/health', 200])]
    #[TestWith(['/up', 200])]
    public function test_private_pages_redirects_errors_and_health_responses_are_never_indexable(string $path, int $status): void
    {
        $this->production();

        $this->assertPrivate($this->get($path)->assertStatus($status));
        Http::assertNothingSent();
    }

    public function test_unauthorized_api_errors_have_noindex_and_private_cache_headers(): void
    {
        $this->production();

        $this->assertPrivate($this->getJson('/api/bootstrap')->assertUnauthorized());
        $this->assertPrivate($this->getJson('/api/developer/content')->assertUnauthorized());
        $this->assertPrivate($this->getJson('/api/missing')->assertNotFound());
    }

    public function test_authenticated_workspace_and_tenant_api_keep_their_existing_access_and_privacy_boundaries(): void
    {
        $tenant = Tenant::factory()->create();
        Subscription::factory()->create(['tenant_id' => $tenant->id]);
        $staff = User::factory()->create(['tenant_id' => $tenant->id, 'role' => 'cashier']);
        $foreign = Tenant::factory()->create(['name' => 'Foreign tenant identity']);
        User::factory()->create(['tenant_id' => $foreign->id, 'email' => 'foreign@example.test']);
        $this->production();

        $this->assertPrivate($this->actingAs($staff, 'web')->get('/app/pos')->assertOk());
        $response = $this->getJson('/api/bootstrap')->assertOk()->assertJsonPath('tenant.id', $tenant->id)
            ->assertJsonPath('user.id', $staff->id)->assertDontSee($foreign->name)->assertDontSee('foreign@example.test');
        $this->assertPrivate($response);
        $this->assertPrivate($this->getJson('/api/billing')->assertForbidden());
        Http::assertNothingSent();
    }

    public function test_authorized_developer_draft_preview_remains_private_and_has_no_public_canonical(): void
    {
        $developer = PlatformAdmin::factory()->create();
        $content = app(SiteContentService::class)->defaults();
        SiteContent::factory()->create(['draft' => array_replace($content, ['hero_title_line1' => 'Private draft headline'])]);
        $this->production();

        $response = $this->actingAs($developer, 'developer')->get('/developer/content/preview')->assertOk()
            ->assertSee('Private draft headline')->assertViewHas('seo', fn (array $seo): bool => $seo['canonical_url'] === null && $seo['indexable'] === false);
        $this->assertPrivate($response);
        $response->assertHeader('Referrer-Policy', 'no-referrer');
        $this->get('/')->assertOk()->assertDontSee('Private draft headline');
        $this->assertDatabaseCount('site_contents', 1);
        $this->assertDatabaseCount('platform_audit_logs', 0);
        Http::assertNothingSent();
    }

    public function test_public_page_runtime_errors_receive_noindex_headers_from_the_exception_response_hook(): void
    {
        $this->production();
        $this->mock(SiteContentService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('state')->once()->andThrow(new RuntimeException('Private internal failure fixture'));
        });

        $response = $this->get('/')->assertStatus(500)->assertDontSee('Private internal failure fixture');

        $this->assertPrivate($response);
    }

    public function test_sanitized_authenticated_api_failures_keep_noindex_headers(): void
    {
        $staff = User::factory()->create();
        $this->production();
        $this->mock(MonitoringService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('snapshot')->once()->andThrow(new RuntimeException('Private internal failure fixture'));
            $mock->shouldReceive('recordException')->once();
        });

        $response = $this->actingAs($staff, 'web')->getJson('/api/monitoring')->assertStatus(503)
            ->assertDontSee('Private internal failure fixture');

        $this->assertPrivate($response);
    }
}
