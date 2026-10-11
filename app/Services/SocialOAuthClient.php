<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use OpenSSLAsymmetricKey;
use RuntimeException;
use Throwable;

class SocialOAuthClient
{
    private const ENDPOINTS = [
        'google' => ['authorize' => 'https://accounts.google.com/o/oauth2/v2/auth', 'token' => 'https://oauth2.googleapis.com/token', 'keys' => 'https://www.googleapis.com/oauth2/v3/certs'],
        'apple' => ['authorize' => 'https://appleid.apple.com/auth/authorize', 'token' => 'https://appleid.apple.com/auth/token', 'keys' => 'https://appleid.apple.com/auth/keys'],
    ];

    /** @return array<string, array{label: string, available: bool, message: string}> */
    public function providers(): array
    {
        $providers = [];
        foreach (['google' => 'Google', 'apple' => 'Apple'] as $provider => $label) {
            $available = $this->isConfigured($provider);
            $providers[$provider] = [
                'label' => $label, 'available' => $available,
                'message' => $available ? 'Continue with your '.$label.' account.' : ($provider === 'apple'
                    ? 'Apple sign-in is unavailable. Use email to continue.' : 'Google sign-in is unavailable. Use email to continue.'),
            ];
        }

        return $providers;
    }

    public function authorizationUrl(string $provider, string $state, string $nonce, string $codeVerifier): string
    {
        $this->assertConfigured($provider);
        $this->assertRandomValue($state);
        $this->assertRandomValue($nonce);
        $parameters = [
            'client_id' => $this->setting($provider, 'client_id'), 'redirect_uri' => $this->setting($provider, 'redirect_uri'),
            'response_type' => 'code', 'scope' => $provider === 'google' ? 'openid email profile' : 'name email', 'state' => $state, 'nonce' => $nonce,
        ];
        if ($provider === 'google') {
            $this->assertCodeVerifier($codeVerifier);
            $parameters['code_challenge'] = $this->base64UrlEncode(hash('sha256', $codeVerifier, true));
            $parameters['code_challenge_method'] = 'S256';
            $parameters['prompt'] = 'select_account';
        } else {
            $parameters['response_mode'] = 'form_post';
        }

        return self::ENDPOINTS[$provider]['authorize'].'?'.http_build_query($parameters, '', '&', PHP_QUERY_RFC3986);
    }

    /** @return array{provider: string, subject: string, email: ?string, email_verified: bool, name: ?string} */
    public function identity(string $provider, string $code, string $nonce, string $codeVerifier): array
    {
        $this->assertConfigured($provider);
        try {
            $this->assertRandomValue($nonce);
            if ($code === '' || strlen($code) > 4096 || preg_match('/[\x00-\x20\x7f]/', $code)) {
                throw new RuntimeException;
            }
            $payload = [
                'client_id' => $this->setting($provider, 'client_id'),
                'client_secret' => $provider === 'google' ? $this->setting($provider, 'client_secret') : $this->appleClientSecret(),
                'code' => $code, 'redirect_uri' => $this->setting($provider, 'redirect_uri'), 'grant_type' => 'authorization_code',
            ];
            if ($provider === 'google') {
                $this->assertCodeVerifier($codeVerifier);
                $payload['code_verifier'] = $codeVerifier;
            }
            $response = Http::acceptJson()->asForm()->withOptions(['allow_redirects' => false])
                ->connectTimeout(3)->timeout(10)->post(self::ENDPOINTS[$provider]['token'], $payload);
            $data = $response->successful() && strlen($response->body()) <= 65536 ? $response->json() : null;
            if (! is_array($data) || ! is_string($data['id_token'] ?? null)) {
                throw new RuntimeException;
            }
            $claims = $this->verifiedClaims($provider, $data['id_token'], $nonce);
            $email = $claims['email'] ?? null;
            $emailVerified = in_array($claims['email_verified'] ?? false, [true, 'true'], true);
            if ($email !== null && (! is_string($email) || strlen($email) > 254 || ! filter_var($email, FILTER_VALIDATE_EMAIL) || ! $emailVerified)) {
                throw new RuntimeException;
            }
            if ($provider === 'google' && $email === null) {
                throw new RuntimeException;
            }
            $name = $claims['name'] ?? null;

            return [
                'provider' => $provider, 'subject' => $claims['sub'], 'email' => $email === null ? null : mb_strtolower(trim($email)),
                'email_verified' => $email !== null && $emailVerified,
                'name' => is_string($name) && mb_strlen(trim($name)) <= 120 && trim($name) !== '' ? trim($name) : null,
            ];
        } catch (Throwable) {
            throw new RuntimeException('We could not verify this sign-in. Please try again.');
        }
    }

    private function isConfigured(string $provider): bool
    {
        if (! isset(self::ENDPOINTS[$provider]) || ! extension_loaded('openssl') || ! $this->validSetting($provider, 'client_id', 255) || ! $this->validRedirectUri($provider)) {
            return false;
        }
        if ($provider === 'google') {
            return $this->validSetting($provider, 'client_secret', 4096);
        }

        return preg_match('/^[A-Z0-9]{10}$/D', $this->setting('apple', 'team_id')) === 1
            && preg_match('/^[A-Z0-9]{10}$/D', $this->setting('apple', 'key_id')) === 1 && $this->applePrivateKey() !== null;
    }

    private function assertConfigured(string $provider): void
    {
        if (! $this->isConfigured($provider)) {
            throw new RuntimeException('This sign-in provider is not configured.');
        }
    }

    private function setting(string $provider, string $key): string
    {
        $value = config('social-auth.'.$provider.'.'.$key, '');

        return is_string($value) ? trim($value) : '';
    }

    private function validSetting(string $provider, string $key, int $maxLength): bool
    {
        $value = $this->setting($provider, $key);

        return $value !== '' && strlen($value) <= $maxLength && ! preg_match('/[\x00-\x20\x7f]/', $value);
    }

    private function validRedirectUri(string $provider): bool
    {
        $url = $this->setting($provider, 'redirect_uri');
        $parts = parse_url($url);
        if (strlen($url) > 2048 || ! filter_var($url, FILTER_VALIDATE_URL) || ! is_array($parts)
            || ! isset($parts['scheme'], $parts['host']) || array_intersect(['user', 'pass', 'query', 'fragment'], array_keys($parts)) !== []
            || ($parts['path'] ?? '') !== '/auth/'.$provider.'/callback') {
            return false;
        }
        $host = strtolower($parts['host']);
        $app = parse_url((string) config('app.url', ''));
        $sameOrigin = is_array($app) && ($app['scheme'] ?? null) === $parts['scheme']
            && strtolower($app['host'] ?? '') === $host && ($app['port'] ?? null) === ($parts['port'] ?? null);
        if ($provider === 'apple') {
            return $sameOrigin && $parts['scheme'] === 'https' && str_contains($host, '.') && $host !== 'localhost'
                && ! str_ends_with($host, '.localhost') && ! filter_var(trim($host, '[]'), FILTER_VALIDATE_IP);
        }
        if ($parts['scheme'] !== 'https' && ! ($parts['scheme'] === 'http' && in_array($host, ['localhost', '127.0.0.1', '[::1]'], true))) {
            return false;
        }

        return $sameOrigin;
    }

    private function verifiedClaims(string $provider, string $token, string $nonce): array
    {
        $parts = explode('.', $token);
        if (strlen($token) > 16384 || count($parts) !== 3) {
            throw new RuntimeException;
        }
        $header = json_decode($this->base64UrlDecode($parts[0]), true, 16, JSON_THROW_ON_ERROR);
        $claims = json_decode($this->base64UrlDecode($parts[1]), true, 16, JSON_THROW_ON_ERROR);
        if (! is_array($header) || ! is_array($claims) || ($header['alg'] ?? '') !== 'RS256'
            || ! is_string($header['kid'] ?? null) || preg_match('/^[A-Za-z0-9_-]{1,128}$/D', $header['kid']) !== 1
            || array_key_exists('crit', $header) || (array_key_exists('typ', $header) && $header['typ'] !== 'JWT')) {
            throw new RuntimeException;
        }
        $signature = $this->base64UrlDecode($parts[2]);
        if (strlen($signature) < 256 || strlen($signature) > 1024
            || openssl_verify($parts[0].'.'.$parts[1], $signature, $this->signingKey($provider, $header['kid']), OPENSSL_ALGO_SHA256) !== 1) {
            throw new RuntimeException;
        }
        $timestamp = now()->timestamp;
        $issuers = $provider === 'google' ? ['https://accounts.google.com', 'accounts.google.com'] : ['https://appleid.apple.com'];
        $audience = $claims['aud'] ?? null;
        $clientId = $this->setting($provider, 'client_id');
        $validAudience = $audience === $clientId;
        if ($provider === 'google' && is_array($audience) && count($audience) >= 1 && count($audience) <= 10) {
            $validAudience = array_is_list($audience) && count(array_filter($audience, 'is_string')) === count($audience)
                && in_array($clientId, $audience, true) && (count($audience) === 1 || ($claims['azp'] ?? null) === $clientId);
        }
        if (! in_array($claims['iss'] ?? null, $issuers, true) || ! $validAudience
            || (array_key_exists('azp', $claims) && $claims['azp'] !== $clientId)
            || ! is_int($claims['exp'] ?? null) || $claims['exp'] <= $timestamp
            || ! is_int($claims['iat'] ?? null) || $claims['iat'] < 0 || $claims['iat'] > $timestamp + 60
            || $claims['exp'] <= $claims['iat'] || $claims['exp'] - $claims['iat'] > 86400
            || (array_key_exists('nbf', $claims) && (! is_int($claims['nbf']) || $claims['nbf'] > $timestamp + 60))
            || ! is_string($claims['sub'] ?? null) || preg_match('/^[\x21-\x7e]{1,255}$/D', $claims['sub']) !== 1
            || ! is_string($claims['nonce'] ?? null) || ! hash_equals($nonce, $claims['nonce'])) {
            throw new RuntimeException;
        }

        return $claims;
    }

    private function signingKey(string $provider, string $keyId): OpenSSLAsymmetricKey
    {
        $keys = Cache::get('social-auth.jwks.'.$provider);
        $cached = is_array($keys);
        if (! $cached) {
            $keys = $this->fetchSigningKeys($provider);
        }
        $matches = array_values(array_filter($keys, fn ($key) => is_array($key) && ($key['kid'] ?? null) === $keyId));
        if ($matches === [] && $cached) {
            $keys = $this->fetchSigningKeys($provider);
            $matches = array_values(array_filter($keys, fn ($key) => is_array($key) && ($key['kid'] ?? null) === $keyId));
        }
        if (count($matches) !== 1) {
            throw new RuntimeException;
        }
        $key = $matches[0];
        if (($key['kty'] ?? null) !== 'RSA' || (array_key_exists('alg', $key) && $key['alg'] !== 'RS256')
            || (array_key_exists('use', $key) && $key['use'] !== 'sig') || ! is_string($key['n'] ?? null) || ! is_string($key['e'] ?? null)) {
            throw new RuntimeException;
        }
        $modulus = $this->base64UrlDecode($key['n']);
        $exponent = $this->base64UrlDecode($key['e']);
        if (strlen($modulus) < 256 || strlen($modulus) > 1024 || strlen($exponent) < 1 || strlen($exponent) > 8
            || ord($modulus[0]) === 0 || ord($exponent[0]) === 0 || (strlen($exponent) === 1 && ord($exponent[0]) < 3)
            || (ord($exponent[strlen($exponent) - 1]) & 1) !== 1) {
            throw new RuntimeException;
        }
        $der = $this->derElement(0x30, $this->derInteger($modulus).$this->derInteger($exponent));
        $pem = "-----BEGIN RSA PUBLIC KEY-----\n".chunk_split(base64_encode($der), 64, "\n")."-----END RSA PUBLIC KEY-----\n";
        $publicKey = openssl_pkey_get_public($pem);
        $details = $publicKey !== false ? openssl_pkey_get_details($publicKey) : false;
        if (! is_array($details) || $details['type'] !== OPENSSL_KEYTYPE_RSA || $details['bits'] < 2048 || $details['bits'] > 8192) {
            throw new RuntimeException;
        }

        return $publicKey;
    }

    private function fetchSigningKeys(string $provider): array
    {
        $response = Http::acceptJson()->withOptions(['allow_redirects' => false])->connectTimeout(3)->timeout(10)
            ->get(self::ENDPOINTS[$provider]['keys']);
        $keys = $response->successful() && strlen($response->body()) <= 65536 ? $response->json('keys') : null;
        if (! is_array($keys) || ! array_is_list($keys) || count($keys) < 1 || count($keys) > 20) {
            throw new RuntimeException;
        }
        $ttl = 300;
        if (preg_match('/(?:^|,)\s*max-age=(\d+)/i', (string) $response->header('Cache-Control'), $match)) {
            $ttl = min($ttl, (int) $match[1]);
        }
        if ($ttl > 0 && ! preg_match('/(?:^|,)\s*no-(?:store|cache)\b/i', (string) $response->header('Cache-Control'))) {
            Cache::put('social-auth.jwks.'.$provider, $keys, $ttl);
        }

        return $keys;
    }

    private function applePrivateKey(): ?OpenSSLAsymmetricKey
    {
        $path = $this->setting('apple', 'private_key_path');
        $realPath = $path !== '' && strlen($path) <= 4096 && ! preg_match('/[\x00-\x1f\x7f]/', $path) ? realpath($path) : false;
        if ($realPath === false || ! is_file($realPath) || ! is_readable($realPath) || filesize($realPath) > 16384) {
            return null;
        }
        $publicRoots = [public_path(), storage_path('app/public'), config('filesystems.disks.public.root')];
        foreach ($publicRoots as $publicRoot) {
            $publicPath = is_string($publicRoot) ? realpath($publicRoot) : false;
            if ($publicPath !== false && ($realPath === $publicPath || str_starts_with($realPath, $publicPath.DIRECTORY_SEPARATOR))) {
                return null;
            }
        }
        $contents = @file_get_contents($realPath, false, null, 0, 16385);
        $key = is_string($contents) && strlen($contents) <= 16384 ? @openssl_pkey_get_private($contents) : false;
        $details = $key !== false ? openssl_pkey_get_details($key) : false;

        return is_array($details) && ($details['type'] ?? null) === OPENSSL_KEYTYPE_EC && ($details['ec']['curve_name'] ?? null) === 'prime256v1' ? $key : null;
    }

    private function appleClientSecret(): string
    {
        $key = $this->applePrivateKey();
        if ($key === null) {
            throw new RuntimeException;
        }
        $timestamp = now()->timestamp;
        $header = $this->base64UrlEncode(json_encode(['alg' => 'ES256', 'kid' => $this->setting('apple', 'key_id')], JSON_THROW_ON_ERROR));
        $claims = $this->base64UrlEncode(json_encode([
            'iss' => $this->setting('apple', 'team_id'), 'iat' => $timestamp, 'exp' => $timestamp + 300,
            'aud' => 'https://appleid.apple.com', 'sub' => $this->setting('apple', 'client_id'),
        ], JSON_THROW_ON_ERROR));
        $signature = '';
        if (! openssl_sign($header.'.'.$claims, $signature, $key, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException;
        }

        return $header.'.'.$claims.'.'.$this->base64UrlEncode($this->ecdsaSignature($signature));
    }

    /** OpenSSL emits a bounded DER sequence of two positive P-256 integers; JWT needs their 64-byte concatenation. */
    private function ecdsaSignature(string $signature): string
    {
        $length = strlen($signature);
        if ($length < 8 || $length > 72 || ord($signature[0]) !== 0x30 || ord($signature[1]) !== $length - 2) {
            throw new RuntimeException;
        }
        $offset = 2;
        $result = '';
        for ($index = 0; $index < 2; $index++) {
            if ($offset + 2 > $length || ord($signature[$offset++]) !== 0x02) {
                throw new RuntimeException;
            }
            $integerLength = ord($signature[$offset++]);
            if ($integerLength < 1 || $integerLength > 33 || $offset + $integerLength > $length) {
                throw new RuntimeException;
            }
            $integer = substr($signature, $offset, $integerLength);
            $offset += $integerLength;
            if ((ord($integer[0]) & 0x80) !== 0 || ($integerLength === 33 && $integer[0] !== "\0")) {
                throw new RuntimeException;
            }
            $integer = ltrim($integer, "\0");
            $result .= str_pad($integer, 32, "\0", STR_PAD_LEFT);
        }
        if ($offset !== $length || strlen($result) !== 64) {
            throw new RuntimeException;
        }

        return $result;
    }

    private function derInteger(string $value): string
    {
        return $this->derElement(0x02, (ord($value[0]) & 0x80) !== 0 ? "\0".$value : $value);
    }

    private function derElement(int $tag, string $value): string
    {
        $length = strlen($value);
        $encodedLength = $length < 128 ? chr($length) : ($length < 256 ? "\x81".chr($length) : "\x82".pack('n', $length));

        return chr($tag).$encodedLength.$value;
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $value): string
    {
        if ($value === '' || strlen($value) > 16384 || preg_match('/^[A-Za-z0-9_-]+$/D', $value) !== 1 || strlen($value) % 4 === 1) {
            throw new RuntimeException;
        }
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);
        if ($decoded === false || $this->base64UrlEncode($decoded) !== $value) {
            throw new RuntimeException;
        }

        return $decoded;
    }

    private function assertRandomValue(string $value): void
    {
        if (preg_match('/^[A-Za-z0-9_-]{32,128}$/D', $value) !== 1) {
            throw new RuntimeException('Invalid sign-in request.');
        }
    }

    private function assertCodeVerifier(string $codeVerifier): void
    {
        if (preg_match('/^[A-Za-z0-9._~-]{43,128}$/D', $codeVerifier) !== 1) {
            throw new RuntimeException('Invalid sign-in request.');
        }
    }
}
