<?php

namespace App\Services;

use App\Jobs\ProcessPayMongoWebhook;
use App\Models\PayMongoWebhookEvent;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use JsonException;
use RuntimeException;
use Throwable;

class PayMongoWebhookService
{
    public function __construct(private PayMongoClient $client) {}

    public function receive(string $body, ?string $signature): string
    {
        $this->client->assertEnabled();
        $mode = $this->client->mode();
        $isLive = $mode === 'live';

        abort_if(strlen($body) > 262144, 413, 'Webhook payload is too large.');
        $secret = (string) config('paymongo.webhook_secret', '');
        abort_if($secret === '', 503, 'PayMongo webhook verification is not configured.');
        abort_unless($this->validSignature($body, $signature, $secret, $mode), 401, 'Webhook signature could not be verified.');

        try {
            $payload = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            abort(422, 'Webhook data is invalid.');
        }

        abort_unless(is_array($payload), 422, 'Webhook data is invalid.');
        [$event, $eventKey] = $this->event($payload);
        abort_unless(($event['livemode'] ?? null) === $isLive, 422, 'Webhook event mode does not match this endpoint.');
        if (($event['type'] ?? null) !== 'checkout_session.payment.paid') {
            return 'ignored';
        }
        $session = $event['data'] ?? null;
        abort_unless(is_array($session) && ($session['type'] ?? null) === 'checkout_session', 422, 'Checkout session data is invalid.');
        $sessionId = $session['id'] ?? null;
        abort_unless(is_string($sessionId) && PayMongoClient::validSessionId($sessionId), 422, 'Checkout session data is invalid.');
        $attributes = $session['attributes'] ?? null;
        abort_unless(is_array($attributes) && (! array_key_exists('livemode', $attributes) || $attributes['livemode'] === $isLive), 422, 'Checkout session data is invalid.');
        $reference = $attributes['reference_number'] ?? null;
        abort_unless($reference === null || (is_string($reference) && preg_match('/\A[A-Za-z0-9._:-]{1,100}\z/D', $reference) === 1), 422, 'Checkout reference is invalid.');

        $digest = hash('sha256', $body);
        $key = $eventKey ?? 'sha256:'.$digest;

        return DB::transaction(function () use ($key, $digest, $sessionId, $reference, $mode): string {
            $receipt = PayMongoWebhookEvent::firstOrCreate(['event_key' => $key], [
                'payload_digest' => $digest, 'session_id' => $sessionId, 'reference' => $reference, 'status' => 'queued', 'mode' => $mode,
            ]);
            $created = $receipt->wasRecentlyCreated;
            $receipt = PayMongoWebhookEvent::whereKey($receipt->id)->lockForUpdate()->firstOrFail();
            abort_unless($receipt->mode === $mode && hash_equals($receipt->payload_digest, $digest), 409, 'Webhook event conflicts with an earlier delivery.');
            $staleQueued = $receipt->status === 'queued' && $receipt->updated_at->lessThanOrEqualTo(now()->subMinutes(1));
            if ($created || $receipt->status === 'failed' || $staleQueued) {
                $receipt->update(['status' => 'queued', 'failure_summary' => null, 'updated_at' => now()]);
                DB::afterCommit(function () use ($receipt): void {
                    try {
                        Bus::dispatch((new ProcessPayMongoWebhook($receipt->id))->afterCommit());
                    } catch (Throwable) {
                        try {
                            PayMongoWebhookEvent::whereKey($receipt->id)->where('status', 'queued')->update([
                                'status' => 'failed', 'failure_summary' => 'Payment verification could not be queued. A redelivery can retry.',
                            ]);
                        } catch (Throwable) {
                            // A later signed delivery can retry a stale queued receipt after storage recovers.
                        }
                        throw new RuntimeException('PayMongo verification could not be queued. Retry this delivery.');
                    }
                });

                return 'queued';
            }

            return 'received';
        }, 3);
    }

    private function validSignature(string $body, ?string $header, string $secret, string $mode): bool
    {
        if (! is_string($header) || strlen($header) > 300) {
            return false;
        }
        $fields = [];
        foreach (explode(',', $header) as $part) {
            $pair = explode('=', trim($part), 2);
            if (count($pair) !== 2 || ! in_array($pair[0], ['t', 'te', 'li'], true) || array_key_exists($pair[0], $fields)) {
                return false;
            }
            $fields[$pair[0]] = $pair[1];
        }
        $modeField = $mode === 'live' ? 'li' : 'te';
        if (! isset($fields['t'], $fields[$modeField])
            || preg_match('/\A[0-9]{10}\z/D', $fields['t']) !== 1
            || preg_match('/\A[a-f0-9]{64}\z/Di', $fields[$modeField]) !== 1) {
            return false;
        }
        $tolerance = max(1, min(300, (int) config('paymongo.webhook_tolerance_seconds', 300)));
        if (abs(now()->timestamp - (int) $fields['t']) > $tolerance) {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $fields['t'].'.'.$body, $secret), strtolower($fields[$modeField]));
    }

    /** @return array{0: array, 1: ?string} */
    private function event(array $payload): array
    {
        $data = $payload['data'] ?? null;
        abort_unless(is_array($data), 422, 'Webhook event data is invalid.');
        if (($payload['event_type'] ?? null) === 'send.webhook') {
            return [$data, null];
        }
        $id = $data['id'] ?? null;
        abort_unless(($data['type'] ?? null) === 'event'
            && is_string($id) && preg_match('/\Aevt_[A-Za-z0-9_-]{1,146}\z/D', $id) === 1
            && is_array($data['attributes'] ?? null), 422, 'Webhook event data is invalid.');

        return [$data['attributes'], $id];
    }
}
