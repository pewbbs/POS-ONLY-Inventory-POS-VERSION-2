<?php

namespace Tests\Feature;

use App\Models\PlatformAdmin;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class PublicAccountNavigationTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.url' => 'https://posonly.example', 'stockroom.demo_enabled' => false]);
    }

    private function company(): User
    {
        $tenant = Tenant::factory()->create(['name' => 'Private navigation business']);
        Subscription::factory()->create(['tenant_id' => $tenant->id]);

        return User::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Private staff identity',
            'email' => 'private-staff@example.test', 'password' => 'CompanyPassword123!']);
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

    /** @return list<string> */
    private function linkPaths(TestResponse $response, string $selector = '//a'): array
    {
        $document = new DOMDocument;
        @$document->loadHTML('<?xml encoding="UTF-8">'.$response->getContent());
        $xpath = new DOMXPath($document);

        return array_map(fn ($node): string => parse_url($node->getAttribute('href'), PHP_URL_PATH) ?? $node->getAttribute('href'),
            iterator_to_array($xpath->query($selector)));
    }

    private function assertPrivate(TestResponse $response): void
    {
        $response->assertHeader('X-Robots-Tag', 'noindex, nofollow')->assertHeader('Cache-Control', 'no-store, private');
    }

    #[TestWith(['/'])]
    #[TestWith(['/guide'])]
    #[TestWith(['/privacy'])]
    public function test_anonymous_public_pages_offer_a_protected_developer_portal_link_without_authenticating_either_guard(string $path): void
    {
        Http::preventStrayRequests();

        $response = $this->get($path)->assertOk();

        $this->assertContains('/developer/overview', $this->linkPaths($response));
        $this->assertGuest('web');
        $this->assertGuest('developer');
        $this->assertPrivate($this->get('/developer/overview')->assertRedirect('/developer/login'));
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('platform_admins', 0);
        Http::assertNothingSent();
    }

    public function test_anonymous_homepage_keeps_company_login_and_create_workspace_calls_to_action(): void
    {
        Http::preventStrayRequests();

        $response = $this->get('/')->assertOk();

        $this->assertContains('/login', $this->linkPaths($response, '//header//a'));
        $this->assertContains('/login', $this->linkPaths($response, '//header//nav//a'));
        $this->assertContains('/register', $this->linkPaths($response, '//header//a'));
        $this->assertContains('/register', $this->linkPaths($response, '//div[contains(concat(" ", normalize-space(@class), " "), " hero-buttons ")]/a'));
        $this->assertNotContains('/app/overview', $this->linkPaths($response, '//header//a'));
        $document = new DOMDocument;
        @$document->loadHTML('<?xml encoding="UTF-8">'.$response->getContent());
        $xpath = new DOMXPath($document);
        $menuButton = $xpath->query('//header//button[@aria-controls]')->item(0);
        $this->assertNotNull($menuButton);
        $this->assertCount(1, $xpath->query('//header/nav[@id="'.$menuButton->getAttribute('aria-controls').'"]'));
        $this->assertSame('false', $menuButton->getAttribute('aria-expanded'));
        $this->assertSame('Open navigation', $menuButton->getAttribute('aria-label'));
        Http::assertNothingSent();
    }

    #[TestWith(['/login'])]
    #[TestWith(['/register'])]
    #[TestWith(['/forgot-password'])]
    #[TestWith(['/reset-password/public-test-token?email=person@example.test'])]
    public function test_anonymous_company_account_pages_stay_accessible_and_noindex(string $path): void
    {
        Http::preventStrayRequests();

        $this->assertPrivate($this->get($path)->assertOk());

        $this->assertGuest('web');
        $this->assertGuest('developer');
        Http::assertNothingSent();
    }

    #[TestWith(['/'])]
    #[TestWith(['/guide'])]
    #[TestWith(['/privacy'])]
    public function test_company_public_navigation_returns_to_its_workspace_without_private_metadata(string $path): void
    {
        Http::preventStrayRequests();
        $user = $this->company();
        $this->app->detectEnvironment(fn (): string => 'production');

        $response = $this->actingAs($user, 'web')->get($path)->assertOk()
            ->assertHeader('X-Robots-Tag', 'index, follow')->assertHeader('Cache-Control', 'no-store, private');

        $headerLinks = $this->linkPaths($response, '//header//a');
        $this->assertContains('/app/overview', $headerLinks);
        $this->assertNotContains('/login', $headerLinks);
        $this->assertNotContains('/register', $headerLinks);
        $this->assertContains('/developer/overview', $this->linkPaths($response));
        $this->assertMatchesRegularExpression('/<head>(.*?)<\/head>/s', $response->getContent());
        preg_match('/<head>(.*?)<\/head>/s', $response->getContent(), $head);
        foreach ([$user->name, $user->email, $user->tenant->name, $user->password, $user->remember_token] as $privateValue) {
            $this->assertStringNotContainsString($privateValue, $head[1]);
        }
        $response->assertViewHas('seo', fn (array $seo): bool => $seo['canonical_url'] === 'https://posonly.example'.$path);
        $this->assertPrivate($this->get('/app/overview')->assertOk());
        $this->assertPrivate($this->get('/developer/overview')->assertRedirect('/developer/login'));
        $this->assertAuthenticatedAs($user, 'web');
        $this->assertGuest('developer');
        Http::assertNothingSent();
    }

    public function test_signed_in_homepage_hero_opens_the_existing_workspace_instead_of_registration(): void
    {
        Http::preventStrayRequests();
        config(['stockroom.demo_enabled' => true]);

        $response = $this->actingAs($this->company(), 'web')->get('/')->assertOk();

        $heroLinks = $this->linkPaths($response, '//div[contains(concat(" ", normalize-space(@class), " "), " hero-buttons ")]/a');
        $this->assertContains('/app/overview', $heroLinks);
        $this->assertNotContains('/register', $this->linkPaths($response));
        $document = new DOMDocument;
        @$document->loadHTML('<?xml encoding="UTF-8">'.$response->getContent());
        $xpath = new DOMXPath($document);
        $postFormPaths = array_map(fn ($form): string => parse_url($form->getAttribute('action'), PHP_URL_PATH) ?? '',
            iterator_to_array($xpath->query('//form[translate(@method, "POST", "post") = "post"]')));
        $this->assertNotContains('/register', $postFormPaths);
        $this->assertNotContains('/demo', $postFormPaths);
        $response->assertSeeText('Back to workspace');
        Http::assertNothingSent();
    }

    #[TestWith(['GET', '/login'])]
    #[TestWith(['POST', '/login'])]
    #[TestWith(['GET', '/register'])]
    #[TestWith(['POST', '/register'])]
    #[TestWith(['GET', '/forgot-password'])]
    #[TestWith(['POST', '/forgot-password'])]
    #[TestWith(['GET', '/reset-password/public-test-token?email=person@example.test'])]
    #[TestWith(['POST', '/reset-password'])]
    public function test_company_guest_routes_redirect_authenticated_staff_to_overview_without_account_changes(string $method, string $path): void
    {
        Http::preventStrayRequests();
        $user = $this->company();
        $before = $user->fresh()->getAttributes();

        $response = $this->actingAs($user, 'web')->call($method, $path)
            ->assertRedirect(route('workspace', ['section' => 'overview']));

        $this->assertPrivate($response);
        $this->assertAuthenticatedAs($user, 'web');
        $this->assertGuest('developer');
        $this->assertSame($before, $user->fresh()->getAttributes());
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('tenants', 1);
        $this->assertDatabaseCount('billing_invoices', 0);
        $this->assertDatabaseCount('password_reset_tokens', 0);
        Http::assertNothingSent();
    }

    #[TestWith(['/', ['/login', '/register']])]
    #[TestWith(['/guide', ['/app/overview']])]
    #[TestWith(['/privacy', ['/']])]
    public function test_developer_cookie_alone_keeps_public_company_navigation_anonymous_and_portal_access_separate(string $path, array $expectedHeaderLinks): void
    {
        Http::preventStrayRequests();
        config(['session.driver' => 'database']);
        $developer = PlatformAdmin::factory()->create();
        $this->carryCookies($this->post('/developer/login', ['email' => $developer->email, 'password' => 'DeveloperPassword123!'])
            ->assertRedirect('/developer/overview'));

        $response = $this->get($path)->assertOk();

        $this->assertContains('/developer/overview', $this->linkPaths($response));
        foreach ($expectedHeaderLinks as $expectedHeaderLink) {
            $this->assertContains($expectedHeaderLink, $this->linkPaths($response, '//header//a'));
        }
        $this->carryCookies($response);
        $this->assertPrivate($this->get('/login')->assertOk());
        $this->carryCookies($this->get('/developer/overview')->assertOk()->assertViewHas('section', 'overview'));
        $this->assertPrivate($this->getJson('/api/bootstrap')->assertUnauthorized());
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('tenants', 0);
        Http::assertNothingSent();
    }

    public function test_both_real_cookie_sessions_keep_company_redirects_and_portal_navigation_independent(): void
    {
        Http::preventStrayRequests();
        config(['session.driver' => 'database']);
        $user = $this->company();
        $developer = PlatformAdmin::factory()->create();
        $this->carryCookies($this->post('/login', ['email' => $user->email, 'password' => 'CompanyPassword123!'])->assertRedirect('/app'));
        $this->carryCookies($this->post('/developer/login', ['email' => $developer->email, 'password' => 'DeveloperPassword123!'])
            ->assertRedirect('/developer/overview'));

        $response = $this->get('/')->assertOk();

        $headerLinks = $this->linkPaths($response, '//header//a');
        $this->assertContains('/app/overview', $headerLinks);
        $this->assertNotContains('/login', $headerLinks);
        $this->assertNotContains('/register', $headerLinks);
        $this->assertContains('/developer/overview', $this->linkPaths($response));
        $this->carryCookies($response);
        $this->assertPrivate($this->get('/register')->assertRedirect('/app/overview'));
        $this->carryCookies($this->get('/app/overview')->assertOk()->assertViewHas('user', fn (User $current): bool => $current->id === $user->id));
        $this->carryCookies($this->get('/developer/overview')->assertOk()->assertViewHas('section', 'overview'));
        $this->assertPrivate($this->get('/developer/login')->assertOk());
        $this->carryCookies($this->getJson('/api/developer/bootstrap')->assertOk()->assertJsonPath('developer.id', $developer->id));
        $this->getJson('/api/bootstrap')->assertOk()->assertJsonPath('user.id', $user->id)->assertJsonPath('tenant.id', $user->tenant_id);
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('platform_admins', 1);
        $this->assertDatabaseCount('billing_invoices', 0);
        Http::assertNothingSent();
    }
}
