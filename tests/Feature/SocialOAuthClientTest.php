<?php

namespace Tests\Feature;

use App\Services\SocialOAuthClient;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use OpenSSLAsymmetricKey;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class SocialOAuthClientTest extends TestCase
{
    private array $temporaryFiles = [];

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'app.url' => 'http://127.0.0.1:8010',
            'social-auth.google' => ['client_id' => 'google-client.apps.googleusercontent.com', 'client_secret' => 'private-google-secret', 'redirect_uri' => 'http://127.0.0.1:8010/auth/google/callback'],
            'social-auth.apple' => ['client_id' => 'com.stockroom.web', 'team_id' => 'TEAM123456', 'key_id' => 'KEY1234567', 'private_key_path' => '', 'redirect_uri' => ''],
        ]);
    }

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        parent::tearDown();
    }

    public function test_google_authorization_uses_fixed_callback_pkce_state_nonce_and_minimal_identity_scopes(): void
    {
        Http::preventStrayRequests();
        $url = app(SocialOAuthClient::class)->authorizationUrl('google', str_repeat('s', 64), str_repeat('n', 64), 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk');
        parse_str(parse_url($url, PHP_URL_QUERY), $query);

        $this->assertSame('https://accounts.google.com/o/oauth2/v2/auth', explode('?', $url)[0]);
        $this->assertSame([
            'client_id' => 'google-client.apps.googleusercontent.com', 'redirect_uri' => 'http://127.0.0.1:8010/auth/google/callback',
            'response_type' => 'code', 'scope' => 'openid email profile', 'state' => str_repeat('s', 64), 'nonce' => str_repeat('n', 64),
            'code_challenge' => 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM', 'code_challenge_method' => 'S256', 'prompt' => 'select_account',
        ], $query);
        Http::assertNothingSent();
    }

    public function test_provider_status_exposes_only_safe_availability_information_without_network_requests(): void
    {
        Http::preventStrayRequests();
        $providers = app(SocialOAuthClient::class)->providers();

        $this->assertSame(['label', 'available', 'message'], array_keys($providers['google']));
        $this->assertSame(true, $providers['google']['available']);
        $this->assertSame(false, $providers['apple']['available']);
        $this->assertStringNotContainsString('private-google-secret', json_encode($providers));
        $this->assertStringNotContainsString('google-client', json_encode($providers));
        Http::assertNothingSent();
    }

    #[DataProvider('invalidGoogleConfiguration')]
    public function test_google_rejects_missing_keys_and_unsafe_or_mismatched_callback_configuration(array $changes): void
    {
        Http::preventStrayRequests();
        foreach ($changes as $key => $value) {
            config(['social-auth.google.'.$key => $value]);
        }

        $this->assertSame(false, app(SocialOAuthClient::class)->providers()['google']['available']);
        $this->assertRejected(fn () => app(SocialOAuthClient::class)->authorizationUrl('google', str_repeat('s', 64), str_repeat('n', 64), str_repeat('v', 64)), 'This sign-in provider is not configured.');
        Http::assertNothingSent();
    }

    public static function invalidGoogleConfiguration(): array
    {
        return [
            'missing client' => [['client_id' => '']], 'missing secret' => [['client_secret' => '']],
            'nonstring secret' => [['client_secret' => ['secret']]], 'remote http' => [['redirect_uri' => 'http://stockroom.example/auth/google/callback']],
            'wrong origin' => [['redirect_uri' => 'https://stockroom.example/auth/google/callback']],
            'wrong port' => [['redirect_uri' => 'http://127.0.0.1:9999/auth/google/callback']],
            'wrong path' => [['redirect_uri' => 'http://127.0.0.1:8010/attacker']],
            'embedded credentials' => [['redirect_uri' => 'http://user:secret@127.0.0.1:8010/auth/google/callback']],
            'query' => [['redirect_uri' => 'http://127.0.0.1:8010/auth/google/callback?next=evil']],
            'fragment' => [['redirect_uri' => 'http://127.0.0.1:8010/auth/google/callback#fragment']],
        ];
    }

    public function test_google_code_exchange_returns_only_a_verified_signed_identity_and_discards_provider_tokens(): void
    {
        $this->travelTo(Carbon::parse('2026-10-11T12:00:00Z'));
        [$key, $jwk] = $this->rsaKey();
        $this->fakeProvider('google', $this->token($key, $this->claims()), [$jwk]);

        $identity = app(SocialOAuthClient::class)->identity('google', 'one-use-code', str_repeat('n', 64), str_repeat('v', 64));

        $this->assertSame(['provider' => 'google', 'subject' => 'subject-123', 'email' => 'owner@example.com', 'email_verified' => true, 'name' => 'Sample Owner'], $identity);
        Http::assertSent(fn ($request) => $request->url() === 'https://oauth2.googleapis.com/token'
            && $request->method() === 'POST' && $request['grant_type'] === 'authorization_code' && $request['code'] === 'one-use-code'
            && $request['client_secret'] === 'private-google-secret' && $request['code_verifier'] === str_repeat('v', 64)
            && $request['redirect_uri'] === 'http://127.0.0.1:8010/auth/google/callback'
            && $request->hasHeader('Content-Type', 'application/x-www-form-urlencoded'));
        Http::assertSentCount(2);
        $this->assertStringNotContainsString('private-access-token', json_encode($identity));
        $this->assertStringNotContainsString('private-refresh-token', json_encode($identity));
    }

    #[DataProvider('invalidClaims')]
    public function test_invalid_signed_identity_claims_are_rejected(string $claim, mixed $value): void
    {
        $this->freezeSecond();
        [$key, $jwk] = $this->rsaKey();
        $claims = $this->claims();
        $claims[$claim] = match ($value) {
            'expired' => now()->timestamp, 'future' => now()->timestamp + 3600, 'too-long' => now()->timestamp + 172800,
            default => $value,
        };
        $this->fakeProvider('google', $this->token($key, $claims), [$jwk]);

        $this->assertRejected(fn () => app(SocialOAuthClient::class)->identity('google', 'code', str_repeat('n', 64), str_repeat('v', 64)));
        Http::assertSentCount(2);
    }

    public static function invalidClaims(): array
    {
        return [
            'issuer' => ['iss', 'https://attacker.example'], 'audience' => ['aud', 'another-client'],
            'multiple audience without authorized party' => ['aud', ['google-client.apps.googleusercontent.com', 'another-client']],
            'authorized party' => ['azp', 'another-client'], 'null authorized party' => ['azp', null], 'expired' => ['exp', 'expired'], 'expiration string' => ['exp', '12345678900'],
            'future issued' => ['iat', 'future'], 'issued string' => ['iat', '123'], 'not before' => ['nbf', 'future'], 'null not before' => ['nbf', null],
            'lifetime' => ['exp', 'too-long'], 'nonce' => ['nonce', 'attacker-nonce'], 'missing nonce' => ['nonce', null],
            'subject type' => ['sub', 123], 'subject size' => ['sub', str_repeat('s', 256)], 'subject whitespace' => ['sub', 'subject 123'],
            'unverified email' => ['email_verified', false], 'unverified string' => ['email_verified', 'false'],
            'integer verification' => ['email_verified', 1], 'invalid email' => ['email', 'not-an-email'], 'missing google email' => ['email', null],
        ];
    }

    public function test_google_accepts_a_matching_authorized_party_for_multiple_audiences(): void
    {
        $this->freezeSecond();
        [$key, $jwk] = $this->rsaKey();
        $this->fakeProvider('google', $this->token($key, $this->claims(['aud' => ['google-client.apps.googleusercontent.com', 'another-client'], 'azp' => 'google-client.apps.googleusercontent.com', 'iss' => 'accounts.google.com'])), [$jwk]);

        $this->assertSame('subject-123', app(SocialOAuthClient::class)->identity('google', 'code', str_repeat('n', 64), str_repeat('v', 64))['subject']);
        Http::assertSentCount(2);
    }

    public function test_a_token_signed_by_another_key_is_rejected(): void
    {
        $this->freezeSecond();
        [$key] = $this->rsaKey();
        [, $otherJwk] = $this->rsaKey();
        $this->fakeProvider('google', $this->token($key, $this->claims()), [$otherJwk]);

        $this->assertRejected(fn () => app(SocialOAuthClient::class)->identity('google', 'code', str_repeat('n', 64), str_repeat('v', 64)));
        Http::assertSentCount(2);
    }

    #[DataProvider('invalidTokenHeaders')]
    public function test_algorithm_confusion_and_unsupported_token_headers_are_rejected_without_fetching_keys(array $header): void
    {
        $this->freezeSecond();
        [$key, $jwk] = $this->rsaKey();
        $this->fakeProvider('google', $this->token($key, $this->claims(), $header), [$jwk]);

        $this->assertRejected(fn () => app(SocialOAuthClient::class)->identity('google', 'code', str_repeat('n', 64), str_repeat('v', 64)));
        Http::assertSentCount(1);
    }

    public static function invalidTokenHeaders(): array
    {
        return [
            'none' => [['alg' => 'none']], 'hmac' => [['alg' => 'HS256']], 'empty kid' => [['kid' => '']],
            'critical extension' => [['crit' => ['extension']]], 'null critical extension' => [['crit' => null]],
            'non jwt type' => [['typ' => 'unexpected']], 'null token type' => [['typ' => null]], 'nonstring kid' => [['kid' => ['key']]],
        ];
    }

    #[DataProvider('invalidSigningKeys')]
    public function test_invalid_provider_jwks_are_rejected(array $changes, bool $duplicate = false): void
    {
        $this->freezeSecond();
        [$key, $jwk] = $this->rsaKey();
        $jwk = array_replace($jwk, $changes);
        $this->fakeProvider('google', $this->token($key, $this->claims()), $duplicate ? [$jwk, $jwk] : [$jwk]);

        $this->assertRejected(fn () => app(SocialOAuthClient::class)->identity('google', 'code', str_repeat('n', 64), str_repeat('v', 64)));
        Http::assertSentCount(2);
    }

    public static function invalidSigningKeys(): array
    {
        return [
            'unknown kid' => [['kid' => 'other-key']], 'key type' => [['kty' => 'EC']], 'key algorithm' => [['alg' => 'HS256']],
            'encryption key' => [['use' => 'enc']], 'invalid modulus' => [['n' => 'bad=base64']], 'tiny modulus' => [['n' => 'AQ']],
            'even exponent' => [['e' => 'Ag']], 'unit exponent' => [['e' => 'AQ']], 'duplicate kid' => [[], true],
            'null algorithm' => [['alg' => null]], 'null use' => [['use' => null]],
        ];
    }

    public function test_unknown_cached_key_is_refreshed_once_to_support_provider_rotation(): void
    {
        $this->freezeSecond();
        [$key, $jwk] = $this->rsaKey('rotated-key');
        Cache::put('social-auth.jwks.google', [array_replace($jwk, ['kid' => 'retired-key'])], 300);
        $this->fakeProvider('google', $this->token($key, $this->claims(), ['kid' => 'rotated-key']), [$jwk]);

        $this->assertSame('subject-123', app(SocialOAuthClient::class)->identity('google', 'code', str_repeat('n', 64), str_repeat('v', 64))['subject']);
        Http::assertSentCount(2);
        $this->assertSame('rotated-key', Cache::get('social-auth.jwks.google')[0]['kid']);
    }

    public function test_provider_no_store_prevents_jwks_caching(): void
    {
        $this->freezeSecond();
        [$key, $jwk] = $this->rsaKey();
        $this->fakeProvider('google', $this->token($key, $this->claims()), [$jwk], ['Cache-Control' => 'no-store']);
        $client = app(SocialOAuthClient::class);

        $client->identity('google', 'first-code', str_repeat('n', 64), str_repeat('v', 64));
        $client->identity('google', 'second-code', str_repeat('n', 64), str_repeat('v', 64));

        Http::assertSentCount(4);
        $this->assertNull(Cache::get('social-auth.jwks.google'));
    }

    public function test_jwks_are_reused_only_until_the_provider_max_age_expires(): void
    {
        $this->freezeSecond();
        [$key, $jwk] = $this->rsaKey();
        $this->fakeProvider('google', $this->token($key, $this->claims()), [$jwk], ['Cache-Control' => 'public, max-age=30']);
        $client = app(SocialOAuthClient::class);

        $client->identity('google', 'first-code', str_repeat('n', 64), str_repeat('v', 64));
        $client->identity('google', 'second-code', str_repeat('n', 64), str_repeat('v', 64));
        Http::assertSentCount(3);
        $this->travel(31)->seconds();
        $client->identity('google', 'third-code', str_repeat('n', 64), str_repeat('v', 64));

        Http::assertSentCount(5);
    }

    #[DataProvider('malformedTokens')]
    public function test_malformed_identity_tokens_fail_without_fetching_signing_keys(string $token): void
    {
        $this->fakeProvider('google', $token, []);

        $this->assertRejected(fn () => app(SocialOAuthClient::class)->identity('google', 'code', str_repeat('n', 64), str_repeat('v', 64)));
        Http::assertSentCount(1);
    }

    public static function malformedTokens(): array
    {
        return [
            [''], ['a.b'], ['a.b.c.d'], ['A.B.C'], ['e30=.e30.AQ'], ['e30.e30.AQ'], ['e30.e30.%bad'],
            [str_repeat('a', 16385)], ['null.null.null'],
        ];
    }

    #[DataProvider('invalidTokenResponses')]
    public function test_invalid_token_exchange_responses_cannot_authenticate(mixed $body, int $status = 200): void
    {
        Http::preventStrayRequests();
        Http::fake(['https://oauth2.googleapis.com/token' => Http::response($body, $status)]);

        $this->assertRejected(fn () => app(SocialOAuthClient::class)->identity('google', 'code', str_repeat('n', 64), str_repeat('v', 64)));
        Http::assertSentCount(1);
    }

    public static function invalidTokenResponses(): array
    {
        return [
            [[]], [['id_token' => ['token']]], [['access_token' => 'private-token']], ['not-json'],
            [str_repeat('x', 65537)], [['id_token' => 'token'], 302], [['error' => 'private-error'], 401],
        ];
    }

    #[DataProvider('invalidJwksResponses')]
    public function test_malformed_jwks_and_provider_key_endpoint_errors_cannot_authenticate(mixed $body, int $status = 200): void
    {
        $this->freezeSecond();
        [$key] = $this->rsaKey();
        Http::preventStrayRequests();
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['id_token' => $this->token($key, $this->claims())]),
            'https://www.googleapis.com/oauth2/v3/certs' => Http::response($body, $status),
        ]);

        $this->assertRejected(fn () => app(SocialOAuthClient::class)->identity('google', 'code', str_repeat('n', 64), str_repeat('v', 64)));
        Http::assertSentCount(2);
    }

    public static function invalidJwksResponses(): array
    {
        return [
            [[]], [['keys' => []]], [['keys' => 'key']], [['keys' => ['key' => ['kid' => 'provider-key']]]],
            [['keys' => array_fill(0, 21, ['kid' => 'provider-key'])]], ['not-json'], [str_repeat('x', 65537)],
            [['error' => 'private-provider-error'], 500], [['keys' => []], 302],
        ];
    }

    #[DataProvider('invalidSignInInputs')]
    public function test_invalid_code_nonce_and_pkce_verifier_are_rejected_before_network_requests(string $code, string $nonce, string $verifier): void
    {
        Http::preventStrayRequests();

        $this->assertRejected(fn () => app(SocialOAuthClient::class)->identity('google', $code, $nonce, $verifier));
        Http::assertNothingSent();
    }

    public static function invalidSignInInputs(): array
    {
        return [
            ['', str_repeat('n', 64), str_repeat('v', 64)], ['code with spaces', str_repeat('n', 64), str_repeat('v', 64)],
            [str_repeat('c', 4097), str_repeat('n', 64), str_repeat('v', 64)], ['code', 'short', str_repeat('v', 64)],
            ['code', str_repeat('n', 129), str_repeat('v', 64)], ['code', str_repeat('n', 64), 'short'],
            ['code', str_repeat('n', 64), str_repeat('v', 129)], ['code', str_repeat('n', 64), str_repeat('%', 64)],
        ];
    }

    public function test_unknown_providers_fail_without_network_requests(): void
    {
        Http::preventStrayRequests();

        $this->assertRejected(fn () => app(SocialOAuthClient::class)->authorizationUrl('attacker', str_repeat('s', 64), str_repeat('n', 64), str_repeat('v', 64)), 'This sign-in provider is not configured.');
        $this->assertRejected(fn () => app(SocialOAuthClient::class)->identity('attacker', 'code', str_repeat('n', 64), str_repeat('v', 64)), 'This sign-in provider is not configured.');
        Http::assertNothingSent();
    }

    public function test_provider_errors_are_sanitized_and_authorization_code_exchange_is_never_retried(): void
    {
        Http::preventStrayRequests();
        Http::fake(['https://oauth2.googleapis.com/token' => Http::response(['error' => 'private-secret-in-error'], 500)]);

        $this->assertRejected(fn () => app(SocialOAuthClient::class)->identity('google', 'private-code', str_repeat('n', 64), str_repeat('v', 64)));
        Http::assertSentCount(1);
    }

    public function test_connection_failures_are_sanitized_without_a_token_exchange_retry(): void
    {
        Http::preventStrayRequests();
        Http::fake(['https://oauth2.googleapis.com/token' => Http::failedConnection('private-network-secret')]);

        $this->assertRejected(fn () => app(SocialOAuthClient::class)->identity('google', 'code', str_repeat('n', 64), str_repeat('v', 64)));
        Http::assertSentCount(1);
    }

    public function test_apple_uses_form_post_and_a_short_lived_correctly_signed_es256_client_secret(): void
    {
        $this->travelTo(Carbon::parse('2026-10-11T12:00:00Z'));
        $appleKey = $this->configureApple();
        [$key, $jwk] = $this->rsaKey();
        $this->fakeProvider('apple', $this->token($key, $this->claims(['iss' => 'https://appleid.apple.com', 'aud' => 'com.stockroom.web', 'email' => 'sample@privaterelay.appleid.com', 'email_verified' => 'true', 'name' => null])), [$jwk]);
        $client = app(SocialOAuthClient::class);
        $url = $client->authorizationUrl('apple', str_repeat('s', 64), str_repeat('n', 64), str_repeat('v', 64));
        parse_str(parse_url($url, PHP_URL_QUERY), $query);

        $this->assertSame(['client_id' => 'com.stockroom.web', 'redirect_uri' => 'https://stockroom.example/auth/apple/callback', 'response_type' => 'code', 'scope' => 'name email', 'state' => str_repeat('s', 64), 'nonce' => str_repeat('n', 64), 'response_mode' => 'form_post'], $query);
        $this->assertSame(['provider' => 'apple', 'subject' => 'subject-123', 'email' => 'sample@privaterelay.appleid.com', 'email_verified' => true, 'name' => null], $client->identity('apple', 'apple-code', str_repeat('n', 64), str_repeat('v', 64)));
        Http::assertSent(function ($request) use ($appleKey): bool {
            if ($request->url() !== 'https://appleid.apple.com/auth/token') {
                return false;
            }
            [$header, $claims, $signature] = explode('.', $request['client_secret']);
            $this->assertSame(['alg' => 'ES256', 'kid' => 'KEY1234567'], json_decode($this->decode($header), true));
            $this->assertSame(['iss' => 'TEAM123456', 'iat' => now()->timestamp, 'exp' => now()->timestamp + 300, 'aud' => 'https://appleid.apple.com', 'sub' => 'com.stockroom.web'], json_decode($this->decode($claims), true));
            $raw = $this->decode($signature);
            $this->assertSame(64, strlen($raw));
            $der = $this->ecdsaDer($raw);
            $publicKey = openssl_pkey_get_details($appleKey)['key'];
            $this->assertSame(1, openssl_verify($header.'.'.$claims, $der, $publicKey, OPENSSL_ALGO_SHA256));

            return $request['grant_type'] === 'authorization_code' && $request['code'] === 'apple-code'
                && $request['redirect_uri'] === 'https://stockroom.example/auth/apple/callback' && ! isset($request['code_verifier']);
        });
        Http::assertSentCount(2);
    }

    public function test_apple_signed_subject_can_be_returned_without_email_for_an_existing_identity(): void
    {
        $this->freezeSecond();
        $this->configureApple();
        [$key, $jwk] = $this->rsaKey();
        $claims = $this->claims(['iss' => 'https://appleid.apple.com', 'aud' => 'com.stockroom.web']);
        unset($claims['email'], $claims['email_verified'], $claims['name']);
        $this->fakeProvider('apple', $this->token($key, $claims), [$jwk]);

        $this->assertSame(['provider' => 'apple', 'subject' => 'subject-123', 'email' => null, 'email_verified' => false, 'name' => null], app(SocialOAuthClient::class)->identity('apple', 'code', str_repeat('n', 64), str_repeat('v', 64)));
        Http::assertSentCount(2);
    }

    #[DataProvider('invalidAppleCallbacks')]
    public function test_apple_rejects_local_insecure_and_malformed_callback_configuration(string $callback, string $appUrl): void
    {
        Http::preventStrayRequests();
        $this->configureApple();
        config(['app.url' => $appUrl, 'social-auth.apple.redirect_uri' => $callback]);

        $this->assertSame(false, app(SocialOAuthClient::class)->providers()['apple']['available']);
        Http::assertNothingSent();
    }

    public static function invalidAppleCallbacks(): array
    {
        return [
            ['http://stockroom.example/auth/apple/callback', 'http://stockroom.example'], ['https://localhost/auth/apple/callback', 'https://localhost'],
            ['https://127.0.0.1/auth/apple/callback', 'https://127.0.0.1'], ['https://[::1]/auth/apple/callback', 'https://[::1]'],
            ['https://app.localhost/auth/apple/callback', 'https://app.localhost'], ['https://stockroom.example/wrong', 'https://stockroom.example'],
            ['https://stockroom.example/auth/apple/callback#fragment', 'https://stockroom.example'], ['https://secret@stockroom.example/auth/apple/callback', 'https://stockroom.example'],
        ];
    }

    public function test_apple_does_not_accept_a_private_signing_key_with_the_wrong_curve(): void
    {
        Http::preventStrayRequests();
        $this->configureApple('secp384r1');

        $this->assertSame(false, app(SocialOAuthClient::class)->providers()['apple']['available']);
        Http::assertNothingSent();
    }

    public function test_apple_stays_unavailable_when_app_origin_is_localhost_or_a_different_domain(): void
    {
        Http::preventStrayRequests();
        $this->configureApple();
        $client = app(SocialOAuthClient::class);
        $this->assertSame(true, $client->providers()['apple']['available']);
        config(['app.url' => 'http://127.0.0.1:8010']);
        $this->assertSame(false, $client->providers()['apple']['available']);
        config(['app.url' => 'https://another.example']);
        $this->assertSame(false, $client->providers()['apple']['available']);
        config(['app.url' => 'https://stockroom.example:8443']);
        $this->assertSame(false, $client->providers()['apple']['available']);
        Http::assertNothingSent();
    }

    #[DataProvider('invalidAppleKeyLocations')]
    public function test_apple_missing_invalid_or_publicly_accessible_private_key_files_stay_unavailable(string $scenario): void
    {
        Http::preventStrayRequests();
        $this->configureApple();
        $path = config('social-auth.apple.private_key_path');
        match ($scenario) {
            'missing' => config(['social-auth.apple.private_key_path' => $path.'-missing']),
            'null-byte' => config(['social-auth.apple.private_key_path' => "bad\0path"]),
            'public-root' => $this->app->usePublicPath(dirname($path)),
            'public-disk' => config(['filesystems.disks.public.root' => dirname($path)]),
            'empty' => file_put_contents($path, ''),
            'invalid-pem' => file_put_contents($path, 'private-unparseable-content'),
            'oversized' => file_put_contents($path, str_repeat('x', 16385)),
        };

        $providers = app(SocialOAuthClient::class)->providers();
        $this->assertSame(false, $providers['apple']['available']);
        $this->assertStringNotContainsString($path, json_encode($providers));
        Http::assertNothingSent();
    }

    public static function invalidAppleKeyLocations(): array
    {
        return [['missing'], ['null-byte'], ['public-root'], ['public-disk'], ['empty'], ['invalid-pem'], ['oversized']];
    }

    private function assertRejected(callable $operation, string $message = 'We could not verify this sign-in. Please try again.'): void
    {
        try {
            $operation();
            $this->fail('An invalid identity was accepted.');
        } catch (RuntimeException $exception) {
            $this->assertSame($message, $exception->getMessage());
            $this->assertNull($exception->getPrevious());
        }
    }

    private function rsaKey(string $keyId = 'provider-key'): array
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
        $details = openssl_pkey_get_details($key);

        return [$key, ['kid' => $keyId, 'kty' => 'RSA', 'alg' => 'RS256', 'use' => 'sig', 'n' => $this->encode($details['rsa']['n']), 'e' => $this->encode($details['rsa']['e'])]];
    }

    private function claims(array $overrides = []): array
    {
        return array_replace([
            'iss' => 'https://accounts.google.com', 'aud' => 'google-client.apps.googleusercontent.com', 'sub' => 'subject-123',
            'iat' => now()->timestamp, 'exp' => now()->timestamp + 3600, 'nonce' => str_repeat('n', 64),
            'email' => 'Owner@Example.com', 'email_verified' => true, 'name' => 'Sample Owner',
        ], $overrides);
    }

    private function token(OpenSSLAsymmetricKey $key, array $claims, array $header = []): string
    {
        $content = $this->encode(json_encode(array_replace(['alg' => 'RS256', 'kid' => 'provider-key', 'typ' => 'JWT'], $header))).'.'.$this->encode(json_encode($claims));
        openssl_sign($content, $signature, $key, OPENSSL_ALGO_SHA256);

        return $content.'.'.$this->encode($signature);
    }

    private function fakeProvider(string $provider, string $token, array $keys, array $headers = []): void
    {
        Http::preventStrayRequests();
        Http::fake([
            ($provider === 'google' ? 'https://oauth2.googleapis.com/token' : 'https://appleid.apple.com/auth/token') => Http::response(['id_token' => $token, 'access_token' => 'private-access-token', 'refresh_token' => 'private-refresh-token']),
            ($provider === 'google' ? 'https://www.googleapis.com/oauth2/v3/certs' : 'https://appleid.apple.com/auth/keys') => Http::response(['keys' => $keys], 200, $headers),
        ]);
    }

    private function configureApple(string $curve = 'prime256v1'): OpenSSLAsymmetricKey
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => $curve]);
        openssl_pkey_export($key, $privateKey);
        $path = tempnam(sys_get_temp_dir(), 'stockroom-test-apple-');
        $this->temporaryFiles[] = $path;
        chmod($path, 0600);
        file_put_contents($path, $privateKey);
        config(['app.url' => 'https://stockroom.example', 'social-auth.apple.private_key_path' => $path, 'social-auth.apple.redirect_uri' => 'https://stockroom.example/auth/apple/callback']);

        return $key;
    }

    private function encode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function decode(string $value): string
    {
        return base64_decode(strtr($value, '-_', '+/'));
    }

    private function ecdsaDer(string $raw): string
    {
        $integers = '';
        foreach ([substr($raw, 0, 32), substr($raw, 32, 32)] as $integer) {
            $integer = ltrim($integer, "\0");
            if ((ord($integer[0]) & 0x80) !== 0) {
                $integer = "\0".$integer;
            }
            $integers .= "\x02".chr(strlen($integer)).$integer;
        }

        return "\x30".chr(strlen($integers)).$integers;
    }
}
