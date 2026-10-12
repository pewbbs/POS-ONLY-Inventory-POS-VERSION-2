<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class MarketingPagesTest extends TestCase
{
    use LazilyRefreshDatabase;

    #[TestWith(['/inventory-management', 'Inventory management'])]
    #[TestWith(['/point-of-sale', 'Point of sale'])]
    #[TestWith(['/about', 'About POSONLY'])]
    public function test_public_product_pages_render_useful_content_links_and_prices_without_creating_business_records(string $path, string $heading): void
    {
        Http::preventStrayRequests();
        config(['app.url' => 'https://posonly.example']);

        $response = $this->get($path)->assertOk()->assertViewIs('marketing')
            ->assertSeeText($heading)->assertSeeText('₱499.00')->assertSeeText('₱4,790.40')
            ->assertSeeText('₱999.00')->assertSeeText('₱9,590.40')
            ->assertSeeText('₱1,999.00')->assertSeeText('₱19,190.40')
            ->assertSeeText('14-day trial')->assertSeeText('Common questions.')
            ->assertSee('href="/register"', false)->assertSee('href="/guide#', false)
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow');

        $document = new DOMDocument;
        @$document->loadHTML('<?xml encoding="UTF-8">'.$response->getContent());
        $xpath = new DOMXPath($document);
        $this->assertCount(1, $xpath->query('//h1'));
        $this->assertCount(3, $xpath->query('//table/tbody/tr'));
        $this->assertCount(0, $xpath->query('//form'));
        $this->assertContains('/inventory-management', array_map(fn ($node): string => $node->getAttribute('href'), iterator_to_array($xpath->query('//a'))));
        $this->assertContains('/point-of-sale', array_map(fn ($node): string => $node->getAttribute('href'), iterator_to_array($xpath->query('//a'))));
        $this->assertContains('/about', array_map(fn ($node): string => $node->getAttribute('href'), iterator_to_array($xpath->query('//a'))));
        $this->assertDatabaseCount('tenants', 0);
        $this->assertDatabaseCount('subscriptions', 0);
        $this->assertDatabaseCount('billing_invoices', 0);
        $this->assertDatabaseCount('site_contents', 0);
        Http::assertNothingSent();
    }

    #[TestWith(['/inventory-management', 'admin'])]
    #[TestWith(['/inventory-management', 'manager'])]
    #[TestWith(['/inventory-management', 'cashier'])]
    #[TestWith(['/point-of-sale', 'admin'])]
    #[TestWith(['/point-of-sale', 'cashier'])]
    #[TestWith(['/about', 'admin'])]
    #[TestWith(['/about', 'cashier'])]
    public function test_company_users_have_workspace_links_without_exposing_their_business_in_public_content(string $path, string $role): void
    {
        Http::preventStrayRequests();
        config(['app.url' => 'https://posonly.example']);
        $tenant = Tenant::factory()->create(['name' => 'Private business identity']);
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'role' => $role, 'name' => 'Private employee identity']);
        $this->app->detectEnvironment(fn (): string => 'production');

        $response = $this->actingAs($user, 'web')->get($path)->assertOk()
            ->assertSeeText('Back to workspace')->assertSee('href="/app/overview"', false)
            ->assertDontSee('href="/register"', false)->assertDontSee('href="/login"', false)
            ->assertDontSee($tenant->name)->assertDontSee($user->name)->assertDontSee($user->email)
            ->assertHeader('X-Robots-Tag', 'index, follow')->assertHeader('Cache-Control', 'no-store, private');

        $this->assertAuthenticatedAs($user, 'web');
        $this->assertSame($role, $user->fresh()->role);
        $this->assertDatabaseCount('tenants', 1);
        $this->assertDatabaseCount('subscriptions', 0);
        $this->assertDatabaseCount('billing_invoices', 0);
        Http::assertNothingSent();
    }

    public function test_visible_plan_comparison_uses_current_trial_and_billing_configuration(): void
    {
        Http::preventStrayRequests();
        config(['billing.trial_days' => 21, 'billing.plans.starter.monthly_cents' => 65000,
            'billing.plans.starter.annual_cents' => 624000, 'billing.plans.starter.locations' => 2]);

        $this->get('/point-of-sale')->assertOk()->assertSeeText('21-day trial')
            ->assertSeeText('₱650.00')->assertSeeText('₱6,240.00')->assertDontSeeText('₱499.00')
            ->assertSeeText('Renewal invoices are paid individually');

        Http::assertNothingSent();
    }

    public function test_about_page_identifies_the_published_operator_and_online_service_without_inventing_a_storefront(): void
    {
        Http::preventStrayRequests();

        $this->get('/about')->assertOk()->assertSeeText('Pablito Torres, trading as POSONLY')
            ->assertSeeText('online software service')->assertSee('mailto:pewbbs@gmail.com', false)
            ->assertSee('href="/privacy"', false)->assertDontSee('LocalBusiness')
            ->assertDontSee('aggregateRating')->assertDontSee('streetAddress');

        Http::assertNothingSent();
    }

    public function test_public_inventory_and_pos_pages_describe_integration_and_hardware_limits(): void
    {
        Http::preventStrayRequests();

        $this->get('/inventory-management')->assertOk()->assertSeeText('not available in the current production service')
            ->assertSeeText('queue walk-in cash sales')
            ->assertSeeText('Offline stock edits are not supported');
        $this->get('/point-of-sale')->assertOk()->assertSeeText('separate terminal or payment service')
            ->assertSeeText('automatic cash-drawer control is not included')
            ->assertSeeText('does not claim BIR certification');

        Http::assertNothingSent();
    }
}
