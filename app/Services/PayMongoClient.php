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
                'reason' => $enabled ? null : 'Local billing simulation is disabled.', 'webhook_configured' => false, 'payment_methods' => [],
                'notice' => 'Simulated payments do not charge a card, wallet or bank account. Renewal invoices are paid individually.'];
        }
        $mode = $this->mode();
        $reason = match (true) {
            $mode === null => 'Configure PayMongo mode as test or live.',
            ! $this->modeAllowed() => $mode === 'live' ? 'PayMongo live checkout is available only in production.' : 'PayMongo test checkout is available only in local development.',
            ! config('paymongo.enabled', false) => 'PayMongo checkout is disabled.',
            ! $this->hasModeKey() => 'Configure a PayMongo secret key matching the selected mode.',
            $mode === 'live' && ! $this->webhookConfigured() => 'Configure the live PayMongo webhook signing secret before accepting payments.',
            $this->paymentMethods() === [] => 'Configure an allowed payment method.',
            $this->returnBaseUrl() === null => 'Configure a valid application URL for checkout returns.',
            default => null,
        };

        return ['provider' => 'paymongo', 'mode' => $mode ?? 'unavailable', 'configured' => $reason === null, 'checkout_enabled' => $reason === null,
            'reason' => $reason, 'webhook_configured' => $this->webhookConfigured(), 'payment_methods' => $this->paymentMethods(),
            'notice' => $mode === 'live'
                ? 'PayMongo live mode collects real payments through hosted checkout. Renewal invoices are paid individually; payments are confirmed on the server.'
                : 'PayMongo test mode uses hosted checkout. No live payment is collected. Renewal invoices are paid individually; payments are confirmed on the server.'];
    }

    public function assertEnabled(): void
    {
        abort_unless($this->modeAllowed() && config('billing.payment_provider', 'simulation') === 'paymongo'
            && config('paymongo.enabled', false), 404);
        $key = (string) config('paymongo.secret_key', '');
        abort_if(preg_match('/^sk_(test|live)_/', $key, $matches) === 1 && $matches[1] !== $this->mode(), 404);
        $configuration = $this->configuration();
        if (! $configuration['checkout_enabled']) {
            throw ValidationException::withMessages(['payment' => $configuration['reason']]);
        }
    }

    public function mode(): ?string
    {
        $mode = config('paymongo.mode', 'test');

        return in_array($mode, ['test', 'live'], true) ? $mode : null;
    }

    public function modeAllowed(): bool
    {
        return ($this->mode() === 'test' && app()->environment(['local', 'testing']))
            || ($this->mode() === 'live' && app()->isProduction());
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
        $allowed = $this->mode() === 'live' ? ['card', 'gcash', 'qrph'] : ['card', 'gcash'];

        return array_values(array_intersect($allowed, (array) config('paymongo.payment_methods', ['card', 'gcash'])));
    }

    public function returnBaseUrl(): ?string
    {
        $url = rtrim((string) config('app.url', ''), '/');
        $parts = parse_url($url);
        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host']) || array_intersect(['user', 'pass', 'query', 'fragment'], array_keys($parts)) !== []) {
            return null;
        }
        if ($parts['scheme'] !== 'https' && ! ($this->mode() !== 'live' && $parts['scheme'] === 'http' && in_array($parts['host'], ['localhost', '127.0.0.1', '[::1]'], true))) {
            return null;
        }

        return filter_var($url, FILTER_VALIDATE_URL) ? $url : null;
    }

    public static function validSessionId(string $sessionId): bool
    {
        return preg_match('/^cs_[A-Za-z0-9_]{1,90}$/D', $sessionId) === 1;
    }

    private function hasModeKey(): bool
    {
        $mode = $this->mode();

        return $mode !== null && preg_match('/^sk_'.$mode.'_[A-Za-z0-9_-]{1,200}$/D', (string) config('paymongo.secret_key', '')) === 1;
    }

    private function webhookConfigured(): bool
    {
        return is_string(config('paymongo.webhook_secret')) && trim(config('paymongo.webhook_secret')) !== '';
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
