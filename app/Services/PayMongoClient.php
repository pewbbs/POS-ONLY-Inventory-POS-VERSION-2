<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Throwable;

class PayMongoClient
{
    public function configuration(): array
    {
        $provider = config('billing.payment_provider', 'simulation') === 'paymongo' ? 'paymongo' : 'simulation';
        $local = app()->environment(['local', 'testing']);
        if ($provider === 'simulation') {
            $enabled = $local && (bool) config('billing.simulations_enabled', false);

            return ['provider' => 'simulation', 'mode' => 'simulation', 'configured' => $enabled, 'checkout_enabled' => $enabled,
                'reason' => $enabled ? null : 'Local billing simulation is disabled.', 'webhook_configured' => false,
                'notice' => 'Simulated payments do not charge a card, wallet or bank account. Renewal invoices are paid individually.'];
        }
        $reason = match (true) {
            ! $local => 'PayMongo test checkout is available only in local development.',
            ! config('paymongo.enabled', false) => 'PayMongo test checkout is disabled.',
            config('paymongo.mode', 'test') !== 'test' => 'Only PayMongo test mode is supported.',
            ! $this->hasTestKey() => 'Configure a PayMongo test secret key to enable checkout.',
            $this->paymentMethods() === [] => 'Configure an allowed test payment method.',
            $this->returnBaseUrl() === null => 'Configure a valid application URL for checkout returns.',
            default => null,
        };

        return ['provider' => 'paymongo', 'mode' => 'test', 'configured' => $reason === null, 'checkout_enabled' => $reason === null,
            'reason' => $reason, 'webhook_configured' => is_string(config('paymongo.webhook_secret')) && trim(config('paymongo.webhook_secret')) !== '',
            'notice' => 'PayMongo test mode uses hosted checkout. No live payment is collected. Renewal invoices are paid individually; payments are confirmed on the server.'];
    }

    public function assertEnabled(): void
    {
        abort_unless(app()->environment(['local', 'testing']) && config('billing.payment_provider', 'simulation') === 'paymongo'
            && config('paymongo.enabled', false) && config('paymongo.mode', 'test') === 'test', 404);
        abort_if(str_starts_with((string) config('paymongo.secret_key', ''), 'sk_live_'), 404);
        $configuration = $this->configuration();
        if (! $configuration['checkout_enabled']) {
            throw ValidationException::withMessages(['payment' => $configuration['reason']]);
        }
    }

    public function createSession(array $payload, string $reference): array
    {
        $this->assertEnabled();

        return $this->request('POST', 'https://api.paymongo.com/v2/checkout_sessions', $payload, $reference);
    }

    public function retrieveSession(string $sessionId): array
    {
        $this->assertEnabled();
        if (! self::validSessionId($sessionId)) {
            return ['outcome' => 'failed', 'data' => null];
        }

        return $this->request('GET', 'https://api.paymongo.com/v1/checkout_sessions/'.$sessionId);
    }

    public function paymentMethods(): array
    {
        return array_values(array_intersect(['card', 'gcash'], (array) config('paymongo.payment_methods', ['card', 'gcash'])));
    }

    public function returnBaseUrl(): ?string
    {
        $url = rtrim((string) config('app.url', ''), '/');
        $parts = parse_url($url);
        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host']) || array_intersect(['user', 'pass', 'query', 'fragment'], array_keys($parts)) !== []) {
            return null;
        }
        if ($parts['scheme'] !== 'https' && ! ($parts['scheme'] === 'http' && in_array($parts['host'], ['localhost', '127.0.0.1', '[::1]'], true))) {
            return null;
        }

        return filter_var($url, FILTER_VALIDATE_URL) ? $url : null;
    }

    public static function validSessionId(string $sessionId): bool
    {
        return preg_match('/^cs_[A-Za-z0-9_]{1,90}$/D', $sessionId) === 1;
    }

    private function hasTestKey(): bool
    {
        return preg_match('/^sk_test_[A-Za-z0-9_-]{1,200}$/D', (string) config('paymongo.secret_key', '')) === 1;
    }

    /** No provider error bodies or exception messages cross this boundary. */
    private function request(string $method, string $url, ?array $payload = null, ?string $reference = null): array
    {
        try {
            $request = Http::withBasicAuth(config('paymongo.secret_key'), '')->acceptJson()->asJson()
                ->withOptions(['allow_redirects' => false])->connectTimeout(3)->timeout(10);
            if ($reference !== null) {
                $request->withHeaders(['Idempotency-Key' => $reference]);
            }
            $response = $method === 'POST' ? $request->post($url, $payload) : $request->get($url);
            if (! $response->successful()) {
                return ['outcome' => $response->clientError() && ! in_array($response->status(), [408, 409, 425, 429], true) ? 'failed' : 'unknown', 'data' => null];
            }
            $data = strlen($response->body()) <= 1048576 ? $response->json('data') : null;

            return ['outcome' => is_array($data) ? 'received' : 'unknown', 'data' => is_array($data) ? $data : null];
        } catch (Throwable) {
            return ['outcome' => 'unknown', 'data' => null];
        }
    }
}
