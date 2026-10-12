<?php

namespace Tests\Feature;

use App\Http\Controllers\PasswordRecoveryController;
use App\Models\BillingInvoice;
use App\Models\BillingPaymentAttempt;
use App\Models\PlatformAdmin;
use App\Models\SocialIdentity;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Services\SocialAuthenticationService;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
use Symfony\Component\Mailer\Exception\TransportException;
use Tests\TestCase;

class AccountRecoveryTest extends TestCase
{
    use LazilyRefreshDatabase;

    /** @return array{email: string, token: string, password: string, password_confirmation: string} */
    private function resetData(User $user, string $token): array
    {
        return ['email' => $user->email, 'token' => $token, 'password' => 'a-new-private-password', 'password_confirmation' => 'a-new-private-password'];
    }

    public function test_recovery_forms_are_accessible_from_login_and_escape_link_input(): void
    {
        $this->get('/login')->assertOk()->assertSee('Forgot password?')->assertSee(route('password.request'), false);
        $this->get('/forgot-password')->assertOk()->assertSee('Send reset link')->assertSee('name="_token"', false)->assertDontSee('name="password"', false);
        $email = 'owner"><script>alert(1)</script>@example.test';
        $this->get('/reset-password/sample-token?'.http_build_query(['email' => $email]))->assertOk()
            ->assertHeader('Referrer-Policy', 'no-referrer')->assertHeader('Cache-Control', 'no-store, private')
            ->assertSee('name="token" value="sample-token"', false)->assertSee($email)->assertDontSee('<script>alert(1)</script>', false)
            ->assertSee('Save new password');
    }

    public function test_known_and_unknown_email_receive_the_same_generic_response(): void
    {
        $user = User::factory()->create(['email' => 'owner@example.test']);
        Notification::fake();
        $known = $this->post('/forgot-password', ['email' => ' OWNER@EXAMPLE.TEST '])->assertRedirect(route('password.request'))->assertSessionHas('status', PasswordRecoveryController::LINK_REQUESTED);
        Notification::assertSentTo($user, ResetPassword::class);
        $token = Notification::sent($user, ResetPassword::class)->first()->token;
        $this->assertNotSame($token, DB::table('password_reset_tokens')->where('email', $user->email)->value('token'));
        $known->assertDontSee($token, false);
        $this->post('/forgot-password', ['email' => 'unknown@example.test'])->assertRedirect(route('password.request'))->assertSessionHas('status', PasswordRecoveryController::LINK_REQUESTED);
        $this->assertDatabaseCount('password_reset_tokens', 1);
        Notification::assertCount(1);
    }

    /** @return array<string, array{bool, bool}> */
    public static function ineligibleAccounts(): array
    {
        return ['paused account' => [false, false], 'demo account' => [true, true]];
    }

    #[DataProvider('ineligibleAccounts')]
    public function test_paused_and_demo_accounts_cannot_request_reset_links(bool $active, bool $demo): void
    {
        $tenant = Tenant::factory()->create(['is_demo' => $demo]);
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'is_active' => $active]);
        Notification::fake();
        $this->post('/forgot-password', ['email' => $user->email])->assertRedirect(route('password.request'))->assertSessionHas('status', PasswordRecoveryController::LINK_REQUESTED);
        Notification::assertNothingSent();
        $this->assertDatabaseCount('password_reset_tokens', 0);
    }

    public function test_repeated_link_requests_are_throttled_without_changing_public_response(): void
    {
        $this->freezeTime();
        $user = User::factory()->create();
        Notification::fake();
        $this->post('/forgot-password', ['email' => $user->email])->assertSessionHas('status', PasswordRecoveryController::LINK_REQUESTED);
        $tokenHash = DB::table('password_reset_tokens')->where('email', $user->email)->value('token');
        $this->post('/forgot-password', ['email' => $user->email])->assertSessionHas('status', PasswordRecoveryController::LINK_REQUESTED);
        Notification::assertSentToTimes($user, ResetPassword::class, 1);
        $this->assertSame($tokenHash, DB::table('password_reset_tokens')->where('email', $user->email)->value('token'));
    }

    public function test_reset_notification_uses_configured_url_even_with_an_untrusted_host(): void
    {
        config(['app.url' => 'https://stockroom.example.test']);
        $user = User::factory()->create(['email' => 'owner@example.test']);
        Notification::fake();
        $this->withServerVariables(['HTTP_HOST' => 'untrusted.example.test'])->post('/forgot-password', ['email' => $user->email])->assertRedirect();
        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user): bool {
            $url = $notification->toMail($user)->actionUrl;

            return str_starts_with($url, 'https://stockroom.example.test/reset-password/') && ! str_contains($url, 'untrusted.example.test') && str_contains($url, 'email=owner%40example.test');
        });
    }

    public function test_mail_transport_failure_is_logged_without_exposing_account_or_token(): void
    {
        $user = User::factory()->create();
        Notification::shouldReceive('send')->once()->andThrow(new TransportException('Sensitive recipient and reset URL must not be logged.'));
        Log::spy();
        $this->post('/forgot-password', ['email' => $user->email])->assertRedirect(route('password.request'))->assertSessionHas('status', PasswordRecoveryController::LINK_REQUESTED)->assertDontSee($user->email, false);
        Log::shouldHaveReceived('error')->once()->with('Password recovery email delivery failed.', ['exception_type' => TransportException::class]);
    }

    public function test_valid_reset_changes_password_rotates_remember_token_and_revokes_only_user_sessions(): void
    {
        $user = User::factory()->create(['email' => 'owner@example.test']);
        $other = User::factory()->create();
        $rememberToken = $user->remember_token;
        DB::table('sessions')->insert([
            ['id' => 'old-user-session', 'user_id' => $user->id, 'payload' => '', 'last_activity' => now()->timestamp],
            ['id' => 'unrelated-session', 'user_id' => $other->id, 'payload' => '', 'last_activity' => now()->timestamp],
        ]);
        $token = Password::createToken($user);
        Event::fake([PasswordReset::class]);
        $data = array_replace($this->resetData($user, $token), ['email' => ' OWNER@EXAMPLE.TEST ']);
        $this->post('/reset-password', $data)->assertRedirect(route('login'))->assertSessionHas('status', 'Your password has been reset. Sign in with your new password.');
        $this->assertTrue(Hash::check('a-new-private-password', $user->refresh()->password));
        $this->assertNotSame($rememberToken, $user->remember_token);
        $this->assertDatabaseMissing('sessions', ['id' => 'old-user-session']);
        $this->assertDatabaseHas('sessions', ['id' => 'unrelated-session']);
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $user->email]);
        $this->assertGuest();
        Event::assertDispatched(PasswordReset::class, fn (PasswordReset $event): bool => $event->user->is($user));
    }

    public function test_reset_token_cannot_be_replayed(): void
    {
        $user = User::factory()->create();
        $token = Password::createToken($user);
        $this->post('/reset-password', $this->resetData($user, $token))->assertRedirect(route('login'));
        $data = array_replace($this->resetData($user, $token), ['password' => 'different-password-attempt', 'password_confirmation' => 'different-password-attempt']);
        $this->post('/reset-password', $data)->assertSessionHasErrors(['email' => PasswordRecoveryController::INVALID_LINK]);
        $this->assertTrue(Hash::check('a-new-private-password', $user->refresh()->password));
    }

    public function test_expired_token_does_not_change_password(): void
    {
        $this->freezeTime();
        $user = User::factory()->create();
        $oldHash = $user->password;
        $token = Password::createToken($user);
        $this->travel(61)->minutes();
        $this->post('/reset-password', $this->resetData($user, $token))->assertSessionHasErrors(['email' => PasswordRecoveryController::INVALID_LINK]);
        $this->assertSame($oldHash, $user->refresh()->password);
    }

    public function test_reset_token_is_bound_to_its_account_and_cannot_reset_another_tenant(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $oldHash = $other->password;
        $this->post('/reset-password', $this->resetData($other, Password::createToken($user)))->assertSessionHasErrors(['email' => PasswordRecoveryController::INVALID_LINK]);
        $this->assertSame($oldHash, $other->refresh()->password);
        $this->assertDatabaseHas('password_reset_tokens', ['email' => $user->email]);
    }

    #[DataProvider('ineligibleAccounts')]
    public function test_existing_reset_token_cannot_restore_paused_or_demo_access(bool $active, bool $demo): void
    {
        $user = User::factory()->create();
        $token = Password::createToken($user);
        $oldHash = $user->password;
        $user->update(['is_active' => $active]);
        $user->tenant->update(['is_demo' => $demo]);
        Event::fake([PasswordReset::class]);
        $this->post('/reset-password', $this->resetData($user, $token))->assertSessionHasErrors(['email' => PasswordRecoveryController::INVALID_LINK]);
        $this->assertSame($oldHash, $user->refresh()->password);
        Event::assertNotDispatched(PasswordReset::class);
    }

    /** @return array<string, array{array<string, mixed>, array<int, string>}> */
    public static function invalidPasswords(): array
    {
        return [
            'short password' => [['password' => 'short', 'password_confirmation' => 'short'], ['password']],
            'different confirmation' => [['password_confirmation' => 'not-the-same-password'], ['password']],
            'missing token' => [['token' => null], ['token']],
        ];
    }

    #[DataProvider('invalidPasswords')]
    public function test_invalid_reset_input_does_not_change_account(array $changes, array $errors): void
    {
        $user = User::factory()->create();
        $oldHash = $user->password;
        $token = Password::createToken($user);
        $this->post('/reset-password', array_replace($this->resetData($user, $token), $changes))->assertSessionHasErrors($errors);
        $this->assertSame($oldHash, $user->refresh()->password);
        $this->assertDatabaseHas('password_reset_tokens', ['email' => $user->email]);
    }

    public function test_recovery_request_rejects_malformed_email_without_type_error(): void
    {
        Notification::fake();
        $this->postJson('/forgot-password', ['email' => ['invalid-input']])->assertUnprocessable()->assertJsonValidationErrors('email');
        Notification::assertNothingSent();
        $this->assertDatabaseCount('password_reset_tokens', 0);
    }

    public function test_recovery_endpoints_limit_requests_to_six_per_minute(): void
    {
        Notification::fake();
        foreach (range(1, 6) as $attempt) {
            $this->post('/forgot-password', ['email' => 'unknown@example.test'])->assertRedirect(route('password.request'));
        }
        $this->post('/forgot-password', ['email' => 'unknown@example.test'])->assertTooManyRequests();
        $this->post('/reset-password', [])->assertTooManyRequests();
        Notification::assertNothingSent();
    }

    public function test_registration_and_login_canonicalize_email_and_prevent_case_duplicates(): void
    {
        $data = ['business_name' => 'Corner Store', 'name' => 'Owner', 'email' => ' OWNER@EXAMPLE.TEST ', 'password' => 'a-private-password', 'password_confirmation' => 'a-private-password'];
        $this->post('/register', $data)->assertRedirect('/app');
        $this->assertDatabaseHas('users', ['email' => 'owner@example.test', 'role' => 'admin']);
        $this->post('/logout')->assertRedirect('/');
        $this->post('/register', $data)->assertSessionHasErrors('email');
        $this->assertDatabaseCount('users', 1);
        $this->post('/login', ['email' => ' OWNER@EXAMPLE.TEST ', 'password' => 'a-private-password'])->assertRedirect('/app');
        $this->assertAuthenticated();
    }

    public function test_paused_account_cannot_log_in_even_with_the_correct_password(): void
    {
        $user = User::factory()->create(['is_active' => false, 'password' => 'known-private-password']);
        $this->post('/login', ['email' => $user->email, 'password' => 'known-private-password'])->assertSessionHasErrors(['email' => 'The email or password is incorrect.']);
        $this->assertGuest();
    }

    public function test_session_with_old_password_hash_is_rejected_after_reset(): void
    {
        $user = User::factory()->create();
        $oldHash = $user->password;
        $this->post('/reset-password', $this->resetData($user, Password::createToken($user)))->assertRedirect(route('login'));
        $this->actingAs($user->refresh())->withSession(['password_hash_web' => $oldHash])->getJson('/api/bootstrap')->assertUnauthorized();
        $this->assertGuest();
    }

    public function test_reset_rejects_a_null_byte_password_without_consuming_the_link(): void
    {
        $user = User::factory()->create();
        $oldHash = $user->password;
        $token = Password::createToken($user);
        Event::fake([PasswordReset::class]);

        $this->postJson('/reset-password', array_replace($this->resetData($user, $token), [
            'password' => "private\0password", 'password_confirmation' => "private\0password",
        ]))->assertUnprocessable()->assertJsonValidationErrors('password');

        $this->assertSame($oldHash, $user->fresh()->password);
        $this->assertDatabaseHas('password_reset_tokens', ['email' => $user->email]);
        $this->assertGuest('web');
        Event::assertNotDispatched(PasswordReset::class);
    }

    public function test_password_login_rejects_a_null_byte_suffix_instead_of_authenticating_the_prefix(): void
    {
        $user = User::factory()->create(['password' => 'known-private-password']);
        $before = $user->fresh()->getAttributes();

        $this->postJson('/login', ['email' => $user->email, 'password' => "known-private-password\0suffix"])
            ->assertUnprocessable()->assertJsonValidationErrors('password');

        $this->assertGuest('web');
        $this->assertSame($before, $user->fresh()->getAttributes());
    }

    public function test_registration_rejects_a_null_byte_password_without_creating_a_company(): void
    {
        $this->postJson('/register', ['business_name' => 'Invalid password company', 'name' => 'Owner', 'email' => 'owner@example.test',
            'password' => "private\0password", 'password_confirmation' => "private\0password"])
            ->assertUnprocessable()->assertJsonValidationErrors('password');

        $this->assertGuest('web');
        $this->assertDatabaseCount('tenants', 0);
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('subscriptions', 0);
    }

    #[TestWith(['admin'])]
    #[TestWith(['manager'])]
    #[TestWith(['cashier'])]
    public function test_google_only_account_recovery_keeps_the_same_company_role_link_and_billing(string $role): void
    {
        Http::preventStrayRequests();
        Notification::fake();
        $this->freezeTime();
        $tenant = Tenant::factory()->create(['operations_paused' => true]);
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'role' => $role, 'password' => str_repeat('x', 64)]);
        $identity = SocialIdentity::factory()->create(['user_id' => $user->id, 'email' => $user->email,
            'subject_hash' => hash('sha256', 'recovery-google-subject')]);
        $subscription = Subscription::factory()->create(['tenant_id' => $tenant->id, 'status' => 'active',
            'current_period_start' => now()->subDay(), 'current_period_end' => now()->addDays(29)]);
        $invoice = BillingInvoice::factory()->create(['subscription_id' => $subscription->id, 'status' => 'paid', 'paid_at' => now(),
            'payment_provider' => 'simulation', 'payment_mode' => 'test']);
        $attempt = BillingPaymentAttempt::factory()->create(['billing_invoice_id' => $invoice->id, 'status' => 'paid', 'active_scope' => null]);
        $other = User::factory()->create();
        $before = [$tenant->fresh()->getAttributes(), $identity->fresh()->getAttributes(), $subscription->fresh()->getAttributes(), $invoice->fresh()->getAttributes(), $attempt->fresh()->getAttributes(), $other->fresh()->getAttributes()];

        $this->post('/forgot-password', ['email' => $user->email])->assertRedirect(route('password.request'))
            ->assertSessionHas('status', PasswordRecoveryController::LINK_REQUESTED);
        $token = Notification::sent($user, ResetPassword::class)->sole()->token;
        $this->post('/reset-password', $this->resetData($user, $token) + ['role' => 'admin', 'tenant_id' => $other->tenant_id, 'is_active' => false, 'guard' => 'developer'])
            ->assertRedirect(route('login'));
        $this->assertGuest('web');
        $this->postJson('/login', ['email' => $user->email, 'password' => str_repeat('x', 64)])
            ->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->post('/login', ['email' => $user->email, 'password' => 'a-new-private-password'])->assertRedirect('/app');

        $this->assertAuthenticatedAs($user, 'web');
        $this->assertGuest('developer');
        $this->assertSame($tenant->id, $user->fresh()->tenant_id);
        $this->assertSame($role, $user->fresh()->role);
        $linked = app(SocialAuthenticationService::class)->linkedUser(['provider' => 'google', 'subject' => 'recovery-google-subject']);
        $this->assertSame($user->id, $linked->id);
        $this->assertSame($role, $linked->role);
        $this->assertSame($before, [$tenant->fresh()->getAttributes(), $identity->fresh()->getAttributes(), $subscription->fresh()->getAttributes(), $invoice->fresh()->getAttributes(), $attempt->fresh()->getAttributes(), $other->fresh()->getAttributes()]);
        $this->assertDatabaseCount('users', 2);
        $this->assertDatabaseCount('tenants', 2);
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $user->email]);
        Notification::assertSentToTimes($user, ResetPassword::class, 1);
        Http::assertNothingSent();
    }

    public function test_company_reset_does_not_change_a_developer_with_the_same_email_and_id(): void
    {
        $user = User::factory()->create(['email' => 'shared@example.test']);
        $developer = PlatformAdmin::factory()->create(['id' => $user->id, 'email' => $user->email]);
        $developerBefore = $developer->fresh()->getAttributes();
        DB::table('developer_sessions')->insert(['id' => 'developer-session', 'user_id' => $developer->id, 'payload' => '', 'last_activity' => now()->timestamp]);
        DB::table('sessions')->insert(['id' => 'company-session', 'user_id' => $user->id, 'payload' => '', 'last_activity' => now()->timestamp]);

        $this->post('/reset-password', $this->resetData($user, Password::createToken($user)))->assertRedirect(route('login'));

        $this->assertTrue(Hash::check('a-new-private-password', $user->fresh()->password));
        $this->assertSame($developerBefore, $developer->fresh()->getAttributes());
        $this->assertDatabaseHas('developer_sessions', ['id' => 'developer-session', 'user_id' => $developer->id]);
        $this->assertDatabaseMissing('sessions', ['id' => 'company-session']);
        $this->assertGuest('developer');
    }

    public function test_public_recovery_does_not_issue_a_company_token_for_a_developer_only_email(): void
    {
        $developer = PlatformAdmin::factory()->create();
        $before = $developer->fresh()->getAttributes();
        Notification::fake();

        $this->post('/forgot-password', ['email' => $developer->email])->assertRedirect(route('password.request'))
            ->assertSessionHas('status', PasswordRecoveryController::LINK_REQUESTED);

        $this->assertDatabaseCount('password_reset_tokens', 0);
        $this->assertSame($before, $developer->fresh()->getAttributes());
        Notification::assertNothingSent();
    }

    public function test_requesting_a_fresh_link_invalidates_the_older_link_after_the_resend_delay(): void
    {
        $this->freezeTime();
        $user = User::factory()->create();
        $oldHash = $user->password;
        Notification::fake();
        $this->post('/forgot-password', ['email' => $user->email])->assertRedirect(route('password.request'));
        $oldToken = Notification::sent($user, ResetPassword::class)->sole()->token;
        $this->travel(61)->seconds();

        $this->post('/forgot-password', ['email' => $user->email])->assertRedirect(route('password.request'));
        $newToken = Notification::sent($user, ResetPassword::class)->last()->token;
        $this->post('/reset-password', $this->resetData($user, $oldToken))->assertSessionHasErrors(['email' => PasswordRecoveryController::INVALID_LINK]);

        $this->assertSame($oldHash, $user->fresh()->password);
        $this->post('/reset-password', $this->resetData($user, $newToken))->assertRedirect(route('login'));
        $this->assertTrue(Hash::check('a-new-private-password', $user->fresh()->password));
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $user->email]);
        Notification::assertSentToTimes($user, ResetPassword::class, 2);
    }

    public function test_recovery_posts_require_csrf_before_issuing_a_link_or_changing_a_password(): void
    {
        $user = User::factory()->create();
        $before = $user->fresh()->getAttributes();
        $token = Password::createToken($user);
        $tokenHash = DB::table('password_reset_tokens')->where('email', $user->email)->value('token');
        Notification::fake();
        $this->app->detectEnvironment(fn (): string => 'local');

        $this->postJson('/forgot-password', ['email' => $user->email])->assertStatus(419);
        $this->postJson('/reset-password', $this->resetData($user, $token))->assertStatus(419);

        $this->assertSame($before, $user->fresh()->getAttributes());
        $this->assertSame($tokenHash, DB::table('password_reset_tokens')->where('email', $user->email)->value('token'));
        Notification::assertNothingSent();
    }
}
