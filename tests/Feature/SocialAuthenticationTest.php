<?php

namespace Tests\Feature;

use App\Models\PlatformAdmin;
use App\Models\SocialIdentity;
use App\Models\User;
use App\Services\SocialOAuthClient;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class SocialAuthenticationTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_unconfigured_provider_returns_to_login_without_contacting_it(): void
    {
        $client = $this->client(false);
        $client->shouldNotReceive('authorizationUrl');
        $client->shouldNotReceive('identity');

        $this->get('/auth/google/redirect')->assertRedirect('/login')->assertSessionHasErrors('social_auth');

        $this->assertGuest('web');
        $this->assertDatabaseCount('social_identities', 0);
        Http::assertNothingSent();
    }

    public function test_unknown_providers_cannot_start_or_finish_authentication(): void
    {
        Http::preventStrayRequests();

        $this->get('/auth/unknown/redirect')->assertNotFound();
        $this->get('/auth/unknown/callback?state=invalid&code=invalid')->assertNotFound();

        $this->assertDatabaseCount('users', 0);
        Http::assertNothingSent();
    }

    #[TestWith(['intent=connect'])]
    #[TestWith(['intent=developer'])]
    #[TestWith(['plan=unsupported'])]
    public function test_start_rejects_untrusted_intent_or_plan(string $query): void
    {
        $client = $this->client();
        $client->shouldNotReceive('authorizationUrl');
        $client->shouldNotReceive('identity');

        $this->getJson('/auth/google/redirect?'.$query)->assertUnprocessable();

        $this->assertDatabaseCount('users', 0);
        Http::assertNothingSent();
    }

    public function test_provider_start_uses_a_fresh_random_session_bound_state_nonce_and_verifier(): void
    {
        $client = $this->client();
        $flow = $this->start($client, 'google', 'register', 'grow');

        $this->assertSame('google', $flow['provider']);
        $this->assertSame('register', $flow['intent']);
        $this->assertSame('grow', $flow['plan']);
        $this->assertGreaterThanOrEqual(32, strlen($flow['state']));
        $this->assertGreaterThanOrEqual(32, strlen($flow['nonce']));
        $this->assertGreaterThanOrEqual(43, strlen($flow['code_verifier']));
        $this->assertNotSame($flow['state'], $flow['nonce']);
        $this->assertGreaterThan(now()->timestamp, $flow['expires_at']);
        $this->assertGuest('web');
        $this->assertGuest('developer');
        Http::assertNothingSent();
    }

    #[TestWith(['state=wrong&code=sample-code'])]
    #[TestWith(['code=sample-code'])]
    #[TestWith(['state[]=invalid&code=sample-code'])]
    public function test_invalid_state_does_not_exchange_a_code_or_create_records(string $query): void
    {
        $client = $this->client();
        $this->start($client);
        $client->shouldNotReceive('identity');

        $this->get('/auth/google/callback?'.$query)->assertRedirect('/login')->assertSessionHasErrors('social_auth');

        $this->assertGuest('web');
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('social_identities', 0);
        Http::assertNothingSent();
    }

    public function test_callback_state_cannot_be_used_from_a_different_session(): void
    {
        $client = $this->client();
        $flow = $this->start($client);
        $client->shouldNotReceive('identity');
        $this->app['session']->flush();

        $this->finishGoogle($flow)->assertRedirect('/login')->assertSessionHasErrors('social_auth');

        $this->assertGuest('web');
        $this->assertDatabaseCount('users', 0);
        Http::assertNothingSent();
    }

    public function test_expired_state_does_not_exchange_a_code(): void
    {
        $this->freezeTime();
        $client = $this->client();
        $flow = $this->start($client);
        $client->shouldNotReceive('identity');
        $this->travelTo(now()->setTimestamp($flow['expires_at'] + 1));

        $this->finishGoogle($flow)->assertRedirect('/login')->assertSessionHasErrors('social_auth');

        $this->assertDatabaseCount('users', 0);
        Http::assertNothingSent();
    }

    public function test_callback_is_single_use_even_after_provider_verification_fails(): void
    {
        $client = $this->client();
        $flow = $this->start($client);
        $client->shouldReceive('identity')->once()->with('google', 'sample-code', $flow['nonce'], $flow['code_verifier'])
            ->andThrow(new \RuntimeException('Sensitive provider code must never be rendered.'));

        $this->finishGoogle($flow)->assertRedirect('/login')->assertSessionHasErrors('social_auth')->assertDontSee('Sensitive provider code', false);
        $this->finishGoogle($flow)->assertRedirect('/login')->assertSessionHasErrors('social_auth');

        $this->assertDatabaseCount('users', 0);
        Http::assertNothingSent();
    }

    public function test_provider_cancellation_consumes_the_flow_without_exchanging_a_code(): void
    {
        $client = $this->client();
        $flow = $this->start($client);
        $client->shouldNotReceive('identity');

        $this->get('/auth/google/callback?'.http_build_query(['state' => $flow['state'], 'error' => 'access_denied']))
            ->assertRedirect('/login')->assertSessionHasErrors('social_auth');
        $this->finishGoogle($flow)->assertRedirect('/login')->assertSessionHasErrors('social_auth');

        $this->assertDatabaseCount('users', 0);
        Http::assertNothingSent();
    }

    public function test_new_verified_google_identity_requires_company_details_before_creating_a_workspace(): void
    {
        $client = $this->client();
        $flow = $this->start($client, 'google', 'register', 'grow');
        $this->exchange($client, $flow, $this->profile());

        $this->finishGoogle($flow)->assertRedirect('/auth/register/complete');

        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('tenants', 0);
        $this->assertGuest('web');
        $this->get('/auth/register/complete')->assertOk()->assertSee('owner@example.test')->assertSee('name="_token"', false);
        Http::assertNothingSent();
    }

    public function test_company_setup_atomically_creates_owner_location_trial_and_identity_with_fixed_privileges(): void
    {
        $this->freezeTime();
        $this->pendingRegistration();

        $this->post('/auth/register/complete', ['business_name' => 'Corner Store', 'name' => 'Owner', 'plan' => 'grow',
            'email' => 'attacker@example.test', 'role' => 'developer', 'tenant_id' => 999, 'is_active' => false, 'password' => 'injected-password'])
            ->assertRedirect('/app');

        $user = User::firstOrFail();
        $this->assertSame('owner@example.test', $user->email);
        $this->assertSame('admin', $user->role);
        $this->assertTrue($user->is_active);
        $this->assertNotNull($user->email_verified_at);
        $this->assertSame('Corner Store', $user->tenant->name);
        $this->assertDatabaseHas('locations', ['tenant_id' => $user->tenant_id, 'name' => 'Main store', 'type' => 'store']);
        $this->assertDatabaseHas('subscriptions', ['tenant_id' => $user->tenant_id, 'plan' => 'grow', 'status' => 'trialing']);
        $this->assertDatabaseHas('social_identities', ['user_id' => $user->id, 'provider' => 'google', 'subject_hash' => hash('sha256', 'google-subject'), 'email' => 'owner@example.test']);
        $this->assertDatabaseCount('tenants', 1);
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('locations', 1);
        $this->assertDatabaseCount('subscriptions', 1);
        $this->assertDatabaseCount('billing_invoices', 0);
        $this->assertDatabaseCount('platform_admins', 0);
        $this->assertAuthenticatedAs($user, 'web');
        $this->assertGuest('developer');
        $this->assertNull($this->app['session']->get('social_auth.registration'));
        Http::assertNothingSent();
    }

    #[TestWith([null, false])]
    #[TestWith(['owner@example.test', false])]
    #[TestWith(['not-an-email', true])]
    public function test_new_identity_without_a_verified_valid_email_cannot_register(?string $email, bool $verified): void
    {
        $client = $this->client();
        $flow = $this->start($client);
        $this->exchange($client, $flow, $this->profile(['email' => $email, 'email_verified' => $verified]));

        $this->finishGoogle($flow)->assertRedirect('/login')->assertSessionHasErrors('social_auth');

        $this->assertGuest('web');
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('social_identities', 0);
        Http::assertNothingSent();
    }

    public function test_matching_email_does_not_automatically_link_or_sign_in_an_existing_account(): void
    {
        $user = User::factory()->create(['email' => 'OWNER@EXAMPLE.TEST']);
        $client = $this->client();
        $flow = $this->start($client);
        $this->exchange($client, $flow, $this->profile());

        $this->finishGoogle($flow)->assertRedirect('/login')->assertSessionHasErrors('social_auth');

        $this->assertGuest('web');
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('social_identities', 0);
        $this->assertSame('OWNER@EXAMPLE.TEST', $user->fresh()->email);
        Http::assertNothingSent();
    }

    public function test_linked_subject_signs_in_its_owner_even_when_provider_email_changes(): void
    {
        $user = User::factory()->create(['email' => 'original@example.test', 'role' => 'cashier']);
        SocialIdentity::factory()->create(['user_id' => $user->id, 'provider' => 'google', 'subject_hash' => hash('sha256', 'google-subject')]);
        $client = $this->client();
        $flow = $this->start($client);
        $this->exchange($client, $flow, $this->profile(['email' => 'changed@example.test']));

        $this->finishGoogle($flow)->assertRedirect('/app');

        $this->assertAuthenticatedAs($user, 'web');
        $this->assertGuest('developer');
        $this->assertSame('original@example.test', $user->fresh()->email);
        $this->assertSame('cashier', $user->role);
        $this->assertDatabaseCount('tenants', 1);
        Http::assertNothingSent();
    }

    public function test_linked_subject_cannot_restore_a_deactivated_company_user(): void
    {
        $user = User::factory()->create();
        SocialIdentity::factory()->create(['user_id' => $user->id, 'provider' => 'google', 'subject_hash' => hash('sha256', 'google-subject')]);
        $client = $this->client();
        $flow = $this->start($client);
        $user->update(['is_active' => false]);
        $this->exchange($client, $flow, $this->profile());

        $this->finishGoogle($flow)->assertRedirect('/login')->assertSessionHasErrors('social_auth');

        $this->assertGuest('web');
        $this->assertDatabaseCount('users', 1);
        $this->assertFalse($user->fresh()->is_active);
        Http::assertNothingSent();
    }

    public function test_pending_registration_cannot_be_replayed_to_create_a_second_company(): void
    {
        $this->pendingRegistration();
        $data = ['business_name' => 'Corner Store', 'name' => 'Owner', 'plan' => 'grow'];
        $this->post('/auth/register/complete', $data)->assertRedirect('/app');
        $this->post('/logout')->assertRedirect('/');

        $this->post('/auth/register/complete', $data)->assertRedirect('/login')->assertSessionHasErrors('social_auth');

        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('tenants', 1);
        Http::assertNothingSent();
    }

    public function test_expired_pending_registration_cannot_create_a_company(): void
    {
        $this->freezeTime();
        $this->pendingRegistration();
        $this->travel(31)->minutes();

        $this->post('/auth/register/complete', ['business_name' => 'Corner Store', 'name' => 'Owner', 'plan' => 'grow'])
            ->assertRedirect('/login')->assertSessionHasErrors('social_auth');

        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('tenants', 0);
        Http::assertNothingSent();
    }

    public function test_company_setup_rejects_missing_fields_and_keeps_pending_registration_for_correction(): void
    {
        $this->pendingRegistration();

        $this->postJson('/auth/register/complete', [])->assertUnprocessable()->assertJsonValidationErrors(['business_name', 'name']);

        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('tenants', 0);
        $this->get('/auth/register/complete')->assertOk();
        Http::assertNothingSent();
    }

    public function test_existing_email_created_during_setup_cannot_be_overwritten_or_merged(): void
    {
        $this->pendingRegistration();
        $existing = User::factory()->create(['email' => 'owner@example.test', 'role' => 'cashier']);
        $before = $existing->fresh()->getAttributes();

        $this->post('/auth/register/complete', ['business_name' => 'Corner Store', 'name' => 'Owner', 'plan' => 'grow'])
            ->assertRedirect('/login')->assertSessionHasErrors('social_auth');

        $this->assertSame($before, $existing->fresh()->getAttributes());
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('tenants', 1);
        $this->assertDatabaseCount('social_identities', 0);
        $this->assertGuest('web');
        Http::assertNothingSent();
    }

    public function test_developer_login_does_not_authorize_company_account_connections(): void
    {
        $developer = PlatformAdmin::factory()->create();
        $client = $this->client();
        $client->shouldNotReceive('authorizationUrl');
        $client->shouldNotReceive('identity');

        $this->actingAs($developer, 'developer')->get('/account/connections')->assertRedirect('/login');
        $this->post('/account/connections/google')->assertRedirect('/login');

        $this->assertGuest('web');
        $this->assertAuthenticatedAs($developer, 'developer');
        $this->assertDatabaseCount('social_identities', 0);
        Http::assertNothingSent();
    }

    public function test_explicit_connection_attaches_only_to_the_current_company_user(): void
    {
        $user = User::factory()->create(['role' => 'cashier']);
        $other = User::factory()->create(['email' => 'owner@example.test']);
        $client = $this->client();
        $this->actingAs($user, 'web');
        $flow = $this->start($client, 'google', 'connect');
        $this->exchange($client, $flow, $this->profile());

        $this->finishGoogle($flow)->assertRedirect('/account/connections');

        $this->assertDatabaseHas('social_identities', ['user_id' => $user->id, 'provider' => 'google', 'subject_hash' => hash('sha256', 'google-subject')]);
        $this->assertDatabaseMissing('social_identities', ['user_id' => $other->id]);
        $this->assertSame('cashier', $user->fresh()->role);
        $this->assertAuthenticatedAs($user, 'web');
        $this->assertGuest('developer');
        Http::assertNothingSent();
    }

    public function test_identity_already_owned_by_another_user_cannot_be_connected(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $identity = SocialIdentity::factory()->create(['user_id' => $other->id, 'provider' => 'google', 'subject_hash' => hash('sha256', 'google-subject')]);
        $client = $this->client();
        $this->actingAs($user, 'web');
        $flow = $this->start($client, 'google', 'connect');
        $this->exchange($client, $flow, $this->profile());

        $this->finishGoogle($flow)->assertRedirect('/account/connections')->assertSessionHasErrors('social_auth');

        $this->assertSame($other->id, $identity->fresh()->user_id);
        $this->assertDatabaseCount('social_identities', 1);
        $this->assertAuthenticatedAs($user, 'web');
        Http::assertNothingSent();
    }

    public function test_connection_rechecks_user_activation_at_callback_instead_of_trusting_cached_actor(): void
    {
        $user = User::factory()->create();
        $client = $this->client();
        $this->actingAs($user, 'web');
        $flow = $this->start($client, 'google', 'connect');
        User::whereKey($user->id)->update(['is_active' => false]);
        $client->shouldNotReceive('identity');

        $this->finishGoogle($flow)->assertRedirect('/login')->assertSessionHasErrors('social_auth');

        $this->assertDatabaseCount('social_identities', 0);
        $this->assertGuest('web');
        Http::assertNothingSent();
    }

    public function test_password_reset_during_connection_invalidates_the_original_actor(): void
    {
        $user = User::factory()->create();
        $client = $this->client();
        $this->actingAs($user, 'web');
        $flow = $this->start($client, 'google', 'connect');
        User::whereKey($user->id)->update(['password' => 'a-new-private-password']);
        $client->shouldNotReceive('identity');

        $this->finishGoogle($flow)->assertRedirect('/login')->assertSessionHasErrors('social_auth');

        $this->assertDatabaseCount('social_identities', 0);
        $this->assertGuest('web');
        Http::assertNothingSent();
    }

    public function test_apple_post_stages_callback_without_exchanging_code_or_replacing_original_session_cookie(): void
    {
        $client = $this->client();
        $flow = $this->start($client, 'apple', 'register', 'grow');
        $this->exchange($client, $flow, $this->profile(['provider' => 'apple', 'subject' => 'apple-subject']));

        $staged = $this->post('/auth/apple/callback', ['state' => $flow['state'], 'code' => 'sample-code', 'user' => json_encode(['email' => 'injected@example.test'])])
            ->assertStatus(303)->assertHeader('Cache-Control', 'no-store, private')->assertHeader('Referrer-Policy', 'no-referrer');

        $cookieNames = array_map(fn ($cookie): string => $cookie->getName(), $staged->baseResponse->headers->getCookies());
        $this->assertNotContains(config('session.cookie'), $cookieNames);
        $this->assertDatabaseCount('users', 0);
        $this->get($staged->headers->get('Location'))->assertRedirect('/auth/register/complete');
        $this->get('/auth/register/complete')->assertOk()->assertSee('owner@example.test')->assertDontSee('injected@example.test');
        $this->get($staged->headers->get('Location'))->assertRedirect('/login')->assertSessionHasErrors('social_auth');
        Http::assertNothingSent();
    }

    public function test_apple_completion_requires_the_session_that_started_authentication(): void
    {
        $client = $this->client();
        $flow = $this->start($client, 'apple');
        $client->shouldNotReceive('identity');
        $staged = $this->post('/auth/apple/callback', ['state' => $flow['state'], 'code' => 'sample-code'])->assertStatus(303);
        $this->app['session']->flush();

        $this->get($staged->headers->get('Location'))->assertRedirect('/login')->assertSessionHasErrors('social_auth');

        $this->assertGuest('web');
        $this->assertDatabaseCount('users', 0);
        Http::assertNothingSent();
    }

    public function test_setup_and_explicit_connect_require_csrf_while_only_apple_callback_is_exempt(): void
    {
        $this->pendingRegistration();
        $user = User::factory()->create();
        $this->app->detectEnvironment(fn (): string => 'local');

        $this->postJson('/auth/register/complete', ['business_name' => 'Corner Store', 'name' => 'Owner', 'plan' => 'grow'])->assertStatus(419);
        $this->actingAs($user, 'web')->postJson('/account/connections/google')->assertStatus(419);
        $this->post('/auth/apple/callback', ['state' => 'invalid', 'code' => 'sample-code'])->assertStatus(303);
        $this->postJson('/auth/google/callback', ['state' => 'invalid', 'code' => 'sample-code'])->assertStatus(405);

        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('social_identities', 0);
        Http::assertNothingSent();
    }

    public function test_company_setup_rejects_a_tampered_pending_identity(): void
    {
        Http::preventStrayRequests();
        $this->withSession(['social_auth.registration' => 'not-an-encrypted-identity']);

        $this->post('/auth/register/complete', ['business_name' => 'Corner Store', 'name' => 'Owner', 'plan' => 'grow'])
            ->assertRedirect('/login')->assertSessionHasErrors('social_auth');

        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('tenants', 0);
        Http::assertNothingSent();
    }

    public function test_company_setup_rejects_an_unknown_plan_without_losing_its_pending_identity(): void
    {
        $this->pendingRegistration();

        $this->postJson('/auth/register/complete', ['business_name' => 'Corner Store', 'name' => 'Owner', 'plan' => 'unlimited'])
            ->assertUnprocessable()->assertJsonValidationErrors('plan');

        $this->assertDatabaseCount('users', 0);
        $this->get('/auth/register/complete')->assertOk();
        Http::assertNothingSent();
    }

    public function test_company_setup_rolls_back_every_record_when_saving_the_identity_fails(): void
    {
        $this->pendingRegistration();
        SocialIdentity::creating(function (): void {
            throw new \RuntimeException('Secret database details must never appear in the response.');
        });
        try {
            $this->post('/auth/register/complete', ['business_name' => 'Corner Store', 'name' => 'Owner', 'plan' => 'grow'])
                ->assertRedirect('/login')->assertSessionHasErrors('social_auth')->assertDontSee('Secret database details', false);

            $this->assertDatabaseCount('tenants', 0);
            $this->assertDatabaseCount('users', 0);
            $this->assertDatabaseCount('locations', 0);
            $this->assertDatabaseCount('subscriptions', 0);
            $this->assertDatabaseCount('social_identities', 0);
            $this->assertGuest('web');
            Http::assertNothingSent();
        } finally {
            SocialIdentity::flushEventListeners();
        }
    }

    public function test_an_existing_linked_apple_subject_can_sign_in_without_a_newly_returned_email(): void
    {
        $user = User::factory()->create(['role' => 'manager']);
        SocialIdentity::factory()->create(['user_id' => $user->id, 'provider' => 'apple', 'subject_hash' => hash('sha256', 'apple-subject')]);
        $client = $this->client();
        $flow = $this->start($client, 'apple');
        $this->exchange($client, $flow, $this->profile(['provider' => 'apple', 'subject' => 'apple-subject', 'email' => null, 'email_verified' => false]));
        $staged = $this->post('/auth/apple/callback', ['state' => $flow['state'], 'code' => 'sample-code'])->assertStatus(303);

        $this->get($staged->headers->get('Location'))->assertRedirect('/app');

        $this->assertAuthenticatedAs($user, 'web');
        $this->assertGuest('developer');
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('social_identities', 1);
        Http::assertNothingSent();
    }

    public function test_apple_staged_code_expires_before_the_browser_restores_its_session(): void
    {
        $this->freezeTime();
        $client = $this->client();
        $flow = $this->start($client, 'apple');
        $client->shouldNotReceive('identity');
        $staged = $this->post('/auth/apple/callback', ['state' => $flow['state'], 'code' => 'sample-code'])->assertStatus(303);
        $this->travel(6)->minutes();

        $this->get($staged->headers->get('Location'))->assertRedirect('/login')->assertSessionHasErrors('social_auth');

        $this->assertGuest('web');
        $this->assertDatabaseCount('users', 0);
        Http::assertNothingSent();
    }

    public function test_connections_render_only_the_current_company_users_links(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        SocialIdentity::factory()->create(['user_id' => $user->id, 'provider' => 'google', 'email' => 'own-provider@example.test']);
        SocialIdentity::factory()->create(['user_id' => $other->id, 'provider' => 'apple', 'email' => 'other-provider@example.test']);
        $this->client();

        $this->actingAs($user, 'web')->get('/account/connections')->assertOk()
            ->assertSee('own-provider@example.test')->assertDontSee('other-provider@example.test')->assertSee('name="_token"', false);

        $this->assertDatabaseCount('social_identities', 2);
        Http::assertNothingSent();
    }

    #[TestWith([['not-a-string']])]
    #[TestWith([''])]
    #[TestWith(['oversized'])]
    public function test_invalid_code_is_rejected_before_any_provider_exchange(mixed $code): void
    {
        $client = $this->client();
        $flow = $this->start($client);
        $client->shouldNotReceive('identity');
        $code = $code === 'oversized' ? str_repeat('c', 4097) : $code;

        $this->get('/auth/google/callback?'.http_build_query(['state' => $flow['state'], 'code' => $code]))
            ->assertRedirect('/login')->assertSessionHasErrors('social_auth')
            ->assertHeader('Cache-Control', 'no-store, private')->assertHeader('Referrer-Policy', 'no-referrer');

        $this->assertGuest('web');
        $this->assertDatabaseCount('users', 0);
        Http::assertNothingSent();
    }

    public function test_google_state_cannot_be_finished_using_the_apple_callback(): void
    {
        $client = $this->client();
        $flow = $this->start($client, 'google');
        $client->shouldNotReceive('identity');
        $staged = $this->post('/auth/apple/callback', ['state' => $flow['state'], 'code' => 'sample-code'])->assertStatus(303);

        $this->get($staged->headers->get('Location'))->assertRedirect('/login')->assertSessionHasErrors('social_auth');
        $this->finishGoogle($flow)->assertRedirect('/login')->assertSessionHasErrors('social_auth');

        $this->assertDatabaseCount('users', 0);
        Http::assertNothingSent();
    }

    public function test_an_existing_provider_connection_cannot_be_replaced_by_another_subject(): void
    {
        $user = User::factory()->create();
        $identity = SocialIdentity::factory()->create(['user_id' => $user->id, 'provider' => 'google', 'subject_hash' => hash('sha256', 'original-subject')]);
        $client = $this->client();
        $this->actingAs($user, 'web');
        $flow = $this->start($client, 'google', 'connect');
        $this->exchange($client, $flow, $this->profile());

        $this->finishGoogle($flow)->assertRedirect('/account/connections')->assertSessionHasErrors('social_auth');

        $this->assertSame(hash('sha256', 'original-subject'), $identity->fresh()->subject_hash);
        $this->assertDatabaseCount('social_identities', 1);
        $this->assertAuthenticatedAs($user, 'web');
        Http::assertNothingSent();
    }

    public function test_apple_callback_name_is_only_a_display_hint_and_is_escaped_in_setup(): void
    {
        $client = $this->client();
        $flow = $this->start($client, 'apple');
        $this->exchange($client, $flow, $this->profile(['provider' => 'apple', 'subject' => 'apple-subject', 'name' => null]));
        $staged = $this->post('/auth/apple/callback', ['state' => $flow['state'], 'code' => 'sample-code',
            'user' => json_encode(['email' => 'injected@example.test', 'name' => ['firstName' => '<script>alert(1)</script>', 'lastName' => 'Owner']])])->assertStatus(303);
        $this->get($staged->headers->get('Location'))->assertRedirect('/auth/register/complete');

        $this->get('/auth/register/complete')->assertOk()->assertSee('owner@example.test')->assertSee('&lt;script&gt;', false)
            ->assertDontSee('<script>alert(1)</script>', false)->assertDontSee('injected@example.test');

        $this->assertDatabaseCount('users', 0);
        Http::assertNothingSent();
    }

    public function test_social_company_login_and_logout_preserve_the_separate_developer_cookie_session(): void
    {
        config(['session.driver' => 'database']);
        $developer = PlatformAdmin::factory()->create();
        $user = User::factory()->create(['role' => 'cashier']);
        SocialIdentity::factory()->create(['user_id' => $user->id, 'provider' => 'google', 'subject_hash' => hash('sha256', 'google-subject')]);
        $client = $this->client();
        $this->carryCookies($this->post('/developer/login', ['email' => $developer->email, 'password' => 'DeveloperPassword123!'])
            ->assertRedirect('/developer/overview'));
        $client->shouldReceive('authorizationUrl')->once()->with('google', Mockery::type('string'), Mockery::type('string'), Mockery::type('string'))
            ->andReturn('https://provider.example.test/authorize');
        $this->carryCookies($this->get('/auth/google/redirect')->assertRedirect('https://provider.example.test/authorize'));
        $flow = $this->app['session']->get('social_auth.flow');
        $this->exchange($client, $flow, $this->profile());

        $this->carryCookies($this->finishGoogle($flow)->assertRedirect('/app'));

        $this->carryCookies($this->getJson('/api/bootstrap')->assertOk()->assertJsonPath('user.id', $user->id));
        $this->carryCookies($this->getJson('/api/developer/bootstrap')->assertOk()->assertJsonPath('developer.id', $developer->id));
        $this->carryCookies($this->post('/logout')->assertRedirect('/'));
        $this->getJson('/api/developer/bootstrap')->assertOk()->assertJsonPath('developer.id', $developer->id);
        $this->getJson('/api/bootstrap')->assertUnauthorized();
        $this->assertDatabaseCount('social_identities', 1);
        Http::assertNothingSent();
    }

    public function test_apple_cross_site_post_without_cookies_restores_the_original_database_session_on_get(): void
    {
        config(['session.driver' => 'database', 'session.same_site' => 'lax']);
        $client = $this->client();
        $client->shouldReceive('authorizationUrl')->once()->with('apple', Mockery::type('string'), Mockery::type('string'), Mockery::type('string'))
            ->andReturn('https://provider.example.test/authorize');
        $this->carryCookies($this->get('/auth/apple/redirect?intent=register&plan=grow')
            ->assertRedirect('https://provider.example.test/authorize'));
        $flow = $this->app['session']->get('social_auth.flow');
        $this->exchange($client, $flow, $this->profile(['provider' => 'apple', 'subject' => 'apple-subject']));

        $staged = $this->call('POST', '/auth/apple/callback', ['state' => $flow['state'], 'code' => 'sample-code'], [])
            ->assertStatus(303);

        $cookieNames = array_map(fn ($cookie): string => $cookie->getName(), $staged->baseResponse->headers->getCookies());
        $this->assertNotContains(config('session.cookie'), $cookieNames);
        $this->assertDatabaseCount('sessions', 1);
        $this->carryCookies($this->get($staged->headers->get('Location'))->assertRedirect('/auth/register/complete'));
        $this->get('/auth/register/complete')->assertOk()->assertSee('owner@example.test');
        $this->assertDatabaseCount('users', 0);
        Http::assertNothingSent();
    }

    #[TestWith(['/login', 'login', 'Log in to POSONLY'])]
    #[TestWith(['/register', 'register', 'Create my workspace'])]
    public function test_unconfigured_social_providers_leave_regular_email_forms_available(string $path, string $routeName, string $submitLabel): void
    {
        $client = $this->client(false);
        $client->shouldNotReceive('authorizationUrl');
        $client->shouldNotReceive('identity');

        $this->get($path)->assertOk()->assertSee('Continue with Google')->assertSee('Continue with Apple')->assertSee('Setup required')
            ->assertSee('type="button" disabled', false)->assertSee('action="'.route($routeName).'"', false)
            ->assertSee('name="email"', false)->assertSee('name="password"', false)->assertSee($submitLabel)
            ->assertDontSee('href="'.route('auth.social.redirect', ['provider' => 'google']), false);

        Http::assertNothingSent();
    }

    #[TestWith(['/login', 'login', 'starter', 'starter'])]
    #[TestWith(['/register', 'register', 'grow', 'grow'])]
    #[TestWith(['/register', 'register', 'scale', 'scale'])]
    #[TestWith(['/register', 'register', '<script>alert(1)</script>', 'starter'])]
    public function test_configured_provider_links_carry_the_form_intent_and_only_an_allowed_plan(string $path, string $intent, string $requestedPlan, string $selectedPlan): void
    {
        $client = $this->client();
        $client->shouldNotReceive('authorizationUrl');
        $client->shouldNotReceive('identity');

        $response = $this->get($path.'?'.http_build_query(['plan' => $requestedPlan]))->assertOk()->assertDontSee('Setup required');

        foreach (['google', 'apple'] as $provider) {
            $url = route('auth.social.redirect', ['provider' => $provider, 'intent' => $intent, 'plan' => $selectedPlan]);
            $response->assertSee('href="'.e($url).'"', false);
        }
        $response->assertDontSee('<script>alert(1)</script>', false);
        Http::assertNothingSent();
    }

    public function test_apple_callback_failure_shows_a_fixed_login_error_and_ignores_untrusted_error_text(): void
    {
        $this->client(false);

        $this->get('/login?social_error=invalid')->assertOk()->assertSee('This sign-in could not be verified. Start again from login.');
        $this->get('/login?'.http_build_query(['social_error' => '<script>alert(1)</script>']))->assertOk()
            ->assertDontSee('<script>alert(1)</script>', false)->assertDontSee('This sign-in could not be verified. Start again from login.');

        Http::assertNothingSent();
    }

    public function test_password_recovery_and_developer_login_do_not_offer_company_social_authentication(): void
    {
        Http::preventStrayRequests();
        $client = $this->mock(SocialOAuthClient::class);
        $client->shouldNotReceive('providers');
        $client->shouldNotReceive('authorizationUrl');
        $client->shouldNotReceive('identity');

        $this->get('/forgot-password')->assertOk()->assertSee('Send reset link')->assertDontSee('Continue with Google')->assertDontSee('Continue with Apple');
        $this->get('/reset-password/sample-token?email=owner%40example.test')->assertOk()->assertSee('Save new password')
            ->assertDontSee('Continue with Google')->assertDontSee('Continue with Apple');
        $this->get('/developer/login')->assertOk()->assertSee('Sign in to developer portal')->assertDontSee('Continue with Google')->assertDontSee('Continue with Apple');

        Http::assertNothingSent();
    }

    public function test_google_registration_then_logout_and_repeat_sign_in_returns_to_the_same_workspace(): void
    {
        $this->freezeTime();
        $client = $this->client();
        $registrationFlow = $this->start($client, 'google', 'register', 'grow');
        $this->exchange($client, $registrationFlow, $this->profile());
        $this->finishGoogle($registrationFlow)->assertRedirect('/auth/register/complete');
        $this->post('/auth/register/complete', ['business_name' => 'Registered Corner Store', 'name' => 'Owner', 'plan' => 'grow'])->assertRedirect('/app');
        $user = User::firstOrFail();
        $tenant = $user->tenant;
        $subscription = $tenant->subscription;
        $trialEndsAt = $subscription->trial_ends_at->toIso8601String();
        $this->post('/logout')->assertRedirect('/');
        $this->assertGuest('web');
        $loginFlow = $this->start($client, 'google', 'login', 'starter');
        $this->exchange($client, $loginFlow, $this->profile());

        $this->finishGoogle($loginFlow)->assertRedirect('/app');

        $this->assertAuthenticatedAs($user, 'web');
        $this->assertGuest('developer');
        $this->assertNotSame($registrationFlow['state'], $loginFlow['state']);
        $this->assertSame($tenant->id, User::firstOrFail()->tenant_id);
        $this->assertSame('Registered Corner Store', $tenant->fresh()->name);
        $this->assertSame('admin', $user->fresh()->role);
        $this->assertSame('grow', $subscription->fresh()->plan);
        $this->assertSame($trialEndsAt, $subscription->fresh()->trial_ends_at->toIso8601String());
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('tenants', 1);
        $this->assertDatabaseCount('locations', 1);
        $this->assertDatabaseCount('subscriptions', 1);
        $this->assertDatabaseCount('social_identities', 1);
        $this->assertDatabaseCount('billing_invoices', 0);
        Http::assertNothingSent();
    }

    public function test_password_login_then_csrf_protected_google_connection_and_repeat_sign_in_preserves_the_original_company(): void
    {
        config(['session.driver' => 'database']);
        $user = User::factory()->create(['email' => 'existing-owner@example.test', 'role' => 'manager', 'password' => 'LocalJourneyPassword123!']);
        $tenant = $user->tenant;
        $tenantBefore = $tenant->fresh()->getAttributes();
        $client = $this->client();
        $this->app->detectEnvironment(fn (): string => 'local');
        $this->carryCookies($this->get('/login')->assertOk());
        $this->carryCookies($this->post('/login', ['email' => $user->email, 'password' => 'LocalJourneyPassword123!', '_token' => $this->app['session']->token()])
            ->assertRedirect('/app'));
        $this->post('/account/connections/google')->assertStatus(419);
        $client->shouldReceive('authorizationUrl')->once()->with('google', Mockery::type('string'), Mockery::type('string'), Mockery::type('string'))
            ->andReturn('https://provider.example.test/authorize');
        $this->carryCookies($this->post('/account/connections/google', ['_token' => $this->app['session']->token()])
            ->assertRedirect('https://provider.example.test/authorize'));
        $connectionFlow = $this->app['session']->get('social_auth.flow');
        $this->exchange($client, $connectionFlow, $this->profile());
        $this->carryCookies($this->finishGoogle($connectionFlow)->assertRedirect('/account/connections'));
        $this->carryCookies($this->get('/account/connections')->assertOk()->assertSee('owner@example.test'));
        $this->carryCookies($this->post('/logout', ['_token' => $this->app['session']->token()])->assertRedirect('/'));
        $this->assertGuest('web');
        $loginFlow = $this->start($client, 'google');
        $this->exchange($client, $loginFlow, $this->profile());

        $this->carryCookies($this->finishGoogle($loginFlow)->assertRedirect('/app'));

        $this->assertAuthenticatedAs($user, 'web');
        $this->assertGuest('developer');
        $this->assertSame($tenant->id, $user->fresh()->tenant_id);
        $this->assertSame('manager', $user->fresh()->role);
        $this->assertSame('existing-owner@example.test', $user->fresh()->email);
        $this->assertSame($tenantBefore, $tenant->fresh()->getAttributes());
        $this->assertDatabaseHas('social_identities', ['user_id' => $user->id, 'provider' => 'google', 'subject_hash' => hash('sha256', 'google-subject')]);
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('tenants', 1);
        $this->assertDatabaseCount('social_identities', 1);
        Http::assertNothingSent();
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

    private function client(bool $available = true): MockInterface
    {
        Http::preventStrayRequests();
        $client = $this->mock(SocialOAuthClient::class);
        $client->shouldReceive('providers')->andReturn([
            'google' => ['label' => 'Google', 'available' => $available, 'message' => 'Google sign-in needs local configuration.'],
            'apple' => ['label' => 'Apple', 'available' => $available, 'message' => 'Apple sign-in needs local configuration.'],
        ]);

        return $client;
    }

    /** @return array<string, mixed> */
    private function start(MockInterface $client, string $provider = 'google', string $intent = 'login', string $plan = 'starter'): array
    {
        $client->shouldReceive('authorizationUrl')->once()->with($provider, Mockery::type('string'), Mockery::type('string'), Mockery::type('string'))
            ->andReturn('https://provider.example.test/authorize');
        $response = $intent === 'connect' ? $this->post('/account/connections/'.$provider)
            : $this->get('/auth/'.$provider.'/redirect?'.http_build_query(compact('intent', 'plan')));
        $response->assertRedirect('https://provider.example.test/authorize');

        return $this->app['session']->get('social_auth.flow');
    }

    private function finishGoogle(array $flow): TestResponse
    {
        return $this->get('/auth/google/callback?'.http_build_query(['state' => $flow['state'], 'code' => 'sample-code']));
    }

    private function exchange(MockInterface $client, array $flow, array $profile): void
    {
        $client->shouldReceive('identity')->once()->with($flow['provider'], 'sample-code', $flow['nonce'], $flow['code_verifier'])->andReturn($profile);
    }

    /** @return array{provider: string, subject: string, email: ?string, email_verified: bool, name: ?string} */
    private function profile(array $changes = []): array
    {
        return array_replace(['provider' => 'google', 'subject' => 'google-subject', 'email' => 'owner@example.test', 'email_verified' => true, 'name' => 'Owner'], $changes);
    }

    private function pendingRegistration(): void
    {
        $client = $this->client();
        $flow = $this->start($client, 'google', 'register', 'grow');
        $this->exchange($client, $flow, $this->profile());
        $this->finishGoogle($flow)->assertRedirect('/auth/register/complete');
    }
}
