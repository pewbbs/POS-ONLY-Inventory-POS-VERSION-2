<?php

namespace App\Services;

use App\Models\BillingInvoice;
use App\Models\BillingPaymentAttempt;
use App\Models\Tenant;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class PayMongoBillingService
{
    public function __construct(private PayMongoClient $client, private BillingService $billing) {}

    public function paymentConfiguration(): array
    {
        return $this->client->configuration();
    }

    public function startCheckout(User $actor, int $invoiceId): array
    {
        $this->client->assertEnabled();
        [$attempt, $send] = DB::transaction(function () use ($actor, $invoiceId): array {
            $tenant = $this->lockAdministrator($actor);
            $invoice = BillingInvoice::where('tenant_id', $tenant->id)->lockForUpdate()->findOrFail($invoiceId);
            $latest = $invoice->paymentAttempts()->latest('id')->lockForUpdate()->first();
            if ($latest && in_array($latest->status, ['paid', 'needs_review'], true)) {
                return [$latest, false];
            }
            if ($latest && $this->containsQrPh($latest)) {
                throw ValidationException::withMessages(['payment' => 'This older checkout includes QR Ph. Recheck its known session or reconcile it with the provider before creating another checkout.']);
            }
            if ($latest && in_array($latest->status, ['creating', 'unknown'], true) && $latest->session_id === null) {
                return [$latest, false];
            }
            if (BillingPaymentAttempt::where('tenant_id', $tenant->id)->where('billing_invoice_id', '!=', $invoice->id)
                ->whereIn('status', ['creating', 'pending', 'unknown', 'needs_review'])->exists()) {
                throw ValidationException::withMessages(['payment' => 'Resolve the outstanding PayMongo test checkout before starting another invoice.']);
            }
            $this->billing->assertInvoicePayable($tenant, $invoice);
            if ($latest && $latest->session_id !== null && ! in_array($latest->status, ['failed', 'expired'], true)) {
                return [$latest, false];
            }
            if ($latest && $latest->active_scope !== null) {
                return [$latest, false];
            }
            if ($invoice->currency !== 'PHP' || ! is_numeric($invoice->amount_cents) || (int) $invoice->amount_cents <= 0) {
                throw ValidationException::withMessages(['payment' => 'This invoice does not have a payable PHP amount.']);
            }
            $reference = (string) Str::uuid();

            return [BillingPaymentAttempt::create([
                'tenant_id' => $tenant->id, 'billing_invoice_id' => $invoice->id, 'initiated_by' => $actor->id,
                'reference' => $reference, 'active_scope' => 'invoice:'.$invoice->id, 'status' => 'creating',
                'amount_cents' => (int) $invoice->amount_cents, 'currency' => 'PHP', 'mode' => 'test',
                'checkout_payload' => $this->payload($invoice, $reference), 'last_requested_at' => $this->now(),
                'created_at' => $this->now(), 'updated_at' => $this->now(),
            ]), true];
        }, 5);
        if ($send) {
            $result = $this->client->createSession($attempt->checkout_payload, $attempt->reference);
            if ($this->isSparseCreation($result)) {
                [$attempt, $retrieve] = $this->rememberCreatedSession($attempt, $result['data']['id']);
                if ($retrieve) {
                    $attempt = $this->applyResponse($attempt, $this->client->retrieveSession($attempt->session_id), false);
                }
            } else {
                $attempt = $this->applyResponse($attempt, $result, true);
            }
        }
        $this->authorize($actor);

        return $this->envelope($attempt);
    }

    public function recheck(User $actor, int $invoiceId): array
    {
        $this->client->assertEnabled();
        $attempt = DB::transaction(function () use ($actor, $invoiceId): ?BillingPaymentAttempt {
            $tenant = $this->lockAdministrator($actor);
            $invoice = BillingInvoice::where('tenant_id', $tenant->id)->findOrFail($invoiceId);

            $latest = $invoice->paymentAttempts()->latest('id')->lockForUpdate()->first();
            if ($latest && $latest->status === 'creating' && $latest->session_id === null && $this->time($latest->getRawOriginal('last_requested_at'))->lessThanOrEqualTo($this->now()->subSeconds(60))) {
                $latest->update(['status' => 'unknown', 'failure_summary' => 'Checkout creation was not confirmed. Recover the existing provider session before starting another checkout.']);
            }

            return $latest;
        }, 5);
        if ($attempt === null) {
            return ['attempt' => null, 'checkout_url' => null, 'message' => 'No PayMongo test checkout has been started for this invoice.'];
        }
        if ($attempt->session_id !== null && ! in_array($attempt->status, ['paid', 'needs_review'], true)) {
            $attempt = $this->applyResponse($attempt, $this->client->retrieveSession($attempt->session_id), false);
        }
        $this->authorize($actor);

        return $this->envelope($attempt);
    }

    public function recover(User $actor, int $invoiceId, string $sessionId): array
    {
        $this->client->assertEnabled();
        if (! PayMongoClient::validSessionId($sessionId)) {
            throw ValidationException::withMessages(['session_id' => 'Enter a valid PayMongo checkout session ID.']);
        }
        $candidate = DB::transaction(function () use ($actor, $invoiceId, $sessionId): BillingPaymentAttempt {
            return $this->recoveryAttempt($actor, $invoiceId, $sessionId);
        }, 5);
        if (in_array($candidate->status, ['paid', 'needs_review'], true)) {
            return $this->envelope($candidate);
        }

        $result = $this->client->retrieveSession($sessionId);
        try {
            $attempt = DB::transaction(function () use ($actor, $invoiceId, $sessionId, $candidate, $result): BillingPaymentAttempt {
                $current = $this->recoveryAttempt($actor, $invoiceId, $sessionId);
                if ($current->id !== $candidate->id) {
                    throw ValidationException::withMessages(['payment' => 'This billing record changed during verification. Recheck its current checkout.']);
                }
                if (in_array($current->status, ['paid', 'needs_review'], true)) {
                    return $current;
                }
                if ($result['outcome'] !== 'received') {
                    return $this->applyResponse($current, $result, false);
                }
                $session = $result['data'];
                $attributes = $session['attributes'] ?? null;
                $payments = is_array($attributes) ? ($attributes['payments'] ?? null) : null;
                if (($session['id'] ?? null) !== $sessionId || ! $this->validQuote($current, $session, $attributes) || ! is_array($payments)) {
                    throw $this->invalidRecovery();
                }
                $paid = array_values(array_filter($payments, fn (mixed $payment): bool => is_array($payment) && ($payment['attributes']['status'] ?? null) === 'paid'));
                foreach ($paid as $payment) {
                    if ($this->verifiedPayment($current, $payment) === null) {
                        throw $this->invalidRecovery();
                    }
                }
                if ($paid === [] && ($attributes['status'] ?? null) !== 'expired'
                    && (($attributes['status'] ?? null) !== 'active' || ! $this->validCheckoutUrl($attributes['checkout_url'] ?? null))) {
                    throw $this->invalidRecovery();
                }

                return $this->applyResponse($current, $result, false);
            }, 5);
        } catch (UniqueConstraintViolationException) {
            throw $this->invalidRecovery();
        }
        $this->authorize($actor);

        return $this->envelope($attempt);
    }

    private function recoveryAttempt(User $actor, int $invoiceId, string $sessionId): BillingPaymentAttempt
    {
        $tenant = $this->lockAdministrator($actor);
        $invoice = BillingInvoice::where('tenant_id', $tenant->id)->lockForUpdate()->findOrFail($invoiceId);
        $attempt = $invoice->paymentAttempts()->latest('id')->lockForUpdate()->first();
        if ($attempt === null || ($attempt->session_id === null && ! in_array($attempt->status, ['creating', 'unknown'], true))) {
            throw ValidationException::withMessages(['payment' => 'This invoice has no unresolved checkout to recover.']);
        }
        if (($attempt->session_id !== null && $attempt->session_id !== $sessionId)
            || BillingPaymentAttempt::where('session_id', $sessionId)->whereKeyNot($attempt->id)->exists()) {
            throw $this->invalidRecovery();
        }

        return $attempt;
    }

    private function invalidRecovery(): ValidationException
    {
        return ValidationException::withMessages(['session_id' => 'This provider session could not be verified for this invoice. The existing billing record was preserved.']);
    }

    /** Called with sanitized identifiers from an authenticated test webhook. */
    public function processSession(string $sessionId, ?string $reference = null): void
    {
        $this->client->assertEnabled();
        if (! PayMongoClient::validSessionId($sessionId)) {
            return;
        }
        $attempt = BillingPaymentAttempt::where('session_id', $sessionId)->first();
        if ($attempt === null && $reference !== null && Str::isUuid($reference)) {
            $attempt = BillingPaymentAttempt::where('reference', $reference)->whereNull('session_id')->first();
        }
        if ($attempt === null || ($reference !== null && $reference !== $attempt->reference) || in_array($attempt->status, ['paid', 'needs_review'], true)) {
            return;
        }
        $result = $this->client->retrieveSession($sessionId);
        if ($result['outcome'] !== 'received') {
            throw new RuntimeException('PayMongo test checkout status could not be confirmed.');
        }
        if (($result['data']['id'] ?? null) !== $sessionId) {
            throw new RuntimeException('PayMongo test checkout verification did not match the requested record.');
        }
        $updated = $this->applyResponse($attempt, $result, false);
        if (! in_array($updated->status, ['paid', 'needs_review'], true)) {
            throw new RuntimeException('PayMongo test payment could not be verified for this checkout record.');
        }
    }

    private function isSparseCreation(array $result): bool
    {
        $session = $result['data'] ?? null;
        $attributes = is_array($session) ? ($session['attributes'] ?? null) : null;

        return ($result['outcome'] ?? null) === 'received' && is_array($session) && is_array($attributes)
            && ($session['type'] ?? null) === 'checkout_session' && is_string($session['id'] ?? null)
            && PayMongoClient::validSessionId($session['id']) && ($attributes['livemode'] ?? null) === false
            && array_diff(array_keys($attributes), ['checkout_url', 'livemode', 'created_at', 'updated_at']) === []
            && $this->validCheckoutUrl($attributes['checkout_url'] ?? null);
    }

    /** @return array{BillingPaymentAttempt, bool} */
    private function rememberCreatedSession(BillingPaymentAttempt $candidate, string $sessionId): array
    {
        return DB::transaction(function () use ($candidate, $sessionId): array {
            Tenant::whereKey($candidate->tenant_id)->lockForUpdate()->firstOrFail();
            $attempt = BillingPaymentAttempt::where('tenant_id', $candidate->tenant_id)->lockForUpdate()->findOrFail($candidate->id);
            if (in_array($attempt->status, ['paid', 'needs_review', 'failed', 'expired'], true)) {
                return [$attempt, false];
            }
            if ($attempt->session_id !== null && $attempt->session_id !== $sessionId) {
                $attempt->update(['status' => 'unknown', 'checkout_url' => null,
                    'failure_summary' => 'Checkout creation did not match the session already stored for this billing record. Recheck the stored session.']);

                return [$attempt, false];
            }
            if (BillingPaymentAttempt::where('session_id', $sessionId)->whereKeyNot($attempt->id)->exists()) {
                $attempt->update(['status' => 'needs_review', 'checkout_url' => null,
                    'failure_summary' => 'This checkout session is already associated with another billing record.']);

                return [$attempt, false];
            }
            if ($attempt->status === 'pending' && $attempt->session_id === $sessionId) {
                return [$attempt, false];
            }
            $attempt->update(['session_id' => $sessionId, 'status' => 'unknown', 'checkout_url' => null,
                'failure_summary' => 'Checkout creation was received. Recheck this record to verify its stored invoice quote.']);

            return [$attempt, true];
        }, 5);
    }

    private function applyResponse(BillingPaymentAttempt $candidate, array $result, bool $creating): BillingPaymentAttempt
    {
        return DB::transaction(function () use ($candidate, $result, $creating): BillingPaymentAttempt {
            Tenant::whereKey($candidate->tenant_id)->lockForUpdate()->firstOrFail();
            $attempt = BillingPaymentAttempt::where('tenant_id', $candidate->tenant_id)->lockForUpdate()->findOrFail($candidate->id);
            if (in_array($attempt->status, ['paid', 'needs_review'], true)) {
                return $attempt;
            }
            $terminalUnpaid = in_array($attempt->status, ['failed', 'expired'], true);
            if ($result['outcome'] !== 'received') {
                if ($terminalUnpaid) {
                    return $attempt;
                }
                $failed = $creating && $result['outcome'] === 'failed' && $attempt->session_id === null;
                $attempt->update(['status' => $failed ? 'failed' : 'unknown', 'active_scope' => $failed ? null : $attempt->active_scope,
                    'failure_summary' => $failed ? 'PayMongo test checkout could not be created. Check the test configuration.' : 'PayMongo test checkout could not be confirmed. Recheck its known session or recover the existing provider session.']);

                return $attempt;
            }
            $session = $result['data'];
            $attributes = $session['attributes'] ?? null;
            if (! $this->validQuote($attempt, $session, $attributes)) {
                if ($terminalUnpaid) {
                    return $attempt;
                }
                $attempt->update(['status' => 'unknown', 'failure_summary' => 'PayMongo test checkout did not match the stored invoice quote. No invoice payment was applied.']);

                return $attempt;
            }
            $payments = $attributes['payments'] ?? [];
            if (! is_array($payments)) {
                if ($terminalUnpaid) {
                    return $attempt;
                }
                $attempt->update(['status' => 'unknown', 'failure_summary' => 'PayMongo test payment details could not be verified.']);

                return $attempt;
            }
            $paid = array_values(array_filter($payments, fn (mixed $payment): bool => is_array($payment) && ($payment['attributes']['status'] ?? null) === 'paid'));
            if ($terminalUnpaid && count($paid) === 0) {
                return $attempt;
            }
            $sessionId = $session['id'];
            $alreadyBound = BillingPaymentAttempt::where('session_id', $sessionId)->whereKeyNot($attempt->id)->exists();
            if ($alreadyBound) {
                $attempt->update(['status' => 'needs_review', 'failure_summary' => 'This checkout session is already associated with another billing record.']);

                return $attempt;
            }
            $attempt->update(['session_id' => $sessionId]);
            if (count($paid) > 0) {
                $payment = $this->verifiedPayment($attempt, $paid[0]);
                if ($payment === null) {
                    if ($terminalUnpaid) {
                        return $attempt;
                    }
                    $attempt->update(['status' => 'unknown', 'failure_summary' => 'PayMongo test payment details did not match this invoice. No payment was applied.']);

                    return $attempt;
                }
                if ($payment['method'] === 'qrph') {
                    return $this->billing->settleVerifiedAttempt($attempt, $payment['id'], $payment['paid_at'], $payment['method'], 'QR Ph payment evidence requires provider review. Subscription access was not changed by this confirmation.');
                }
                if (count($paid) !== 1 || ! empty($paid[0]['attributes']['refunds'])) {
                    return $this->billing->settleVerifiedAttempt($attempt, $payment['id'], $payment['paid_at'], $payment['method'], 'This checkout contains multiple paid entries or refund records. Review it with the provider before applying access.');
                }

                return $this->billing->settleVerifiedAttempt($attempt, $payment['id'], $payment['paid_at'], $payment['method']);
            }
            if (($attributes['status'] ?? null) === 'expired') {
                $attempt->update(['status' => 'expired', 'active_scope' => null, 'checkout_url' => null, 'failure_summary' => 'This PayMongo test checkout has expired.']);

                return $attempt;
            }
            $url = $attributes['checkout_url'] ?? null;
            if (($attributes['status'] ?? null) !== 'active' || ! $this->validCheckoutUrl($url)) {
                $attempt->update(['status' => 'unknown', 'failure_summary' => 'PayMongo test checkout did not return a verified hosted checkout link.']);

                return $attempt;
            }
            $attempt->update(['status' => 'pending', 'checkout_url' => $url, 'failure_summary' => null]);

            return $attempt;
        }, 5);
    }

    private function validQuote(BillingPaymentAttempt $attempt, array $session, mixed $attributes): bool
    {
        if (! is_array($attributes) || ($session['type'] ?? null) !== 'checkout_session' || ! is_string($session['id'] ?? null)
            || ! PayMongoClient::validSessionId($session['id']) || ($attempt->session_id !== null && $attempt->session_id !== $session['id'])
            || ($attributes['livemode'] ?? null) !== false || ($attributes['reference_number'] ?? null) !== $attempt->reference || $attempt->mode !== 'test') {
            return false;
        }
        $items = $attributes['line_items'] ?? null;
        if (! is_array($items) || count($items) !== 1 || ! is_array($items[0] ?? null)) {
            return false;
        }

        return ($items[0]['amount'] ?? null) === $attempt->amount_cents && ($items[0]['currency'] ?? null) === 'PHP' && ($items[0]['quantity'] ?? null) === 1;
    }

    private function verifiedPayment(BillingPaymentAttempt $attempt, array $payment): ?array
    {
        $attributes = $payment['attributes'] ?? null;
        $id = $payment['id'] ?? null;
        if (($payment['type'] ?? null) !== 'payment' || ! is_array($attributes) || ! is_string($id) || preg_match('/^pay_[A-Za-z0-9_]{1,80}$/D', $id) !== 1
            || ($attributes['livemode'] ?? null) !== false || ($attributes['amount'] ?? null) !== $attempt->amount_cents
            || ($attributes['currency'] ?? null) !== 'PHP' || ! is_int($attributes['paid_at'] ?? null)) {
            return null;
        }
        $paidAt = CarbonImmutable::createFromTimestamp($attributes['paid_at'], config('app.timezone', 'UTC'));
        if ($paidAt->lessThan($this->time($attempt->getRawOriginal('created_at'))->subMinutes(5)) || $paidAt->greaterThan($this->now()->addMinutes(5))) {
            return null;
        }
        $method = $attributes['source']['type'] ?? null;
        if (! is_string($method) || ! in_array($method, ['card', 'gcash', 'qrph'], true)
            || ! in_array($method, $attempt->checkout_payload['data']['attributes']['payment_method_types'] ?? [], true)) {
            return null;
        }

        return ['id' => $id, 'paid_at' => $paidAt, 'method' => $method];
    }

    private function payload(BillingInvoice $invoice, string $reference): array
    {
        $url = $this->client->returnBaseUrl().'/app/billing';

        return ['data' => ['attributes' => [
            'line_items' => [['name' => 'POSONLY '.config('billing.plans.'.$invoice->plan.'.name').' subscription', 'amount' => (int) $invoice->amount_cents, 'currency' => 'PHP', 'quantity' => 1]],
            'payment_method_types' => $this->client->paymentMethods(), 'reference_number' => $reference,
            'success_url' => $url.'?paymongo_return=success&invoice='.$invoice->id, 'cancel_url' => $url.'?paymongo_return=cancel&invoice='.$invoice->id,
            'send_email_receipt' => false, 'pass_on_fees' => false,
        ]]];
    }

    private function envelope(BillingPaymentAttempt $attempt): array
    {
        $invoice = BillingInvoice::where('tenant_id', $attempt->tenant_id)->findOrFail($attempt->billing_invoice_id);
        $message = match ($attempt->status) {
            'paid' => 'PayMongo test payment confirmed. This invoice is paid.',
            'needs_review' => 'This checkout needs review. Subscription access was not changed by this confirmation.',
            'pending' => 'Your PayMongo test checkout is ready. Payment will be confirmed on the server.',
            'expired' => 'This test checkout expired. Start a new checkout if the invoice is still open.',
            'failed' => 'Test checkout could not be created. Check the test configuration before trying again.',
            'creating' => 'This test checkout is being created. Recheck this record before starting another.',
            default => $attempt->session_id === null ? 'Checkout creation is unresolved. Recover the existing provider session before starting another checkout.' : 'Test checkout could not be confirmed. Recheck this stored session.',
        };

        if ($this->containsQrPh($attempt) && ! in_array($attempt->status, ['paid', 'needs_review'], true)) {
            $message = 'This older checkout includes QR Ph. Do not reopen or scan it. Recheck its known session or reconcile it with the provider.';
        }

        return ['attempt' => $attempt->safeRecord(), 'checkout_url' => $attempt->status === 'pending' && $invoice->status === 'open' && ! $this->containsQrPh($attempt) ? $attempt->checkout_url : null, 'message' => $message];
    }

    private function containsQrPh(BillingPaymentAttempt $attempt): bool
    {
        return in_array('qrph', (array) ($attempt->checkout_payload['data']['attributes']['payment_method_types'] ?? []), true);
    }

    private function validCheckoutUrl(mixed $url): bool
    {
        if (! is_string($url) || strlen($url) > 2048 || ! filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }
        $parts = parse_url($url);

        return is_array($parts) && ($parts['scheme'] ?? null) === 'https' && ($parts['host'] ?? null) === 'checkout.paymongo.com'
            && ! isset($parts['user']) && ! isset($parts['pass']) && (! isset($parts['port']) || $parts['port'] === 443);
    }

    private function lockAdministrator(User $actor): Tenant
    {
        $tenant = Tenant::whereKey($actor->tenant_id)->lockForUpdate()->firstOrFail();
        $this->authorize($actor, true);

        return $tenant;
    }

    private function authorize(User $actor, bool $lock = false): void
    {
        $query = User::where('tenant_id', $actor->tenant_id)->whereKey($actor->id);
        $user = ($lock ? $query->lockForUpdate() : $query)->first();
        abort_unless($user && $user->is_active && $user->role === 'admin', 403);
    }

    private function now(): CarbonImmutable
    {
        return CarbonImmutable::now(config('app.timezone', 'UTC'));
    }

    private function time(?string $timestamp): CarbonImmutable
    {
        return $timestamp === null ? $this->now()->subDays(1) : CarbonImmutable::parse($timestamp, config('app.timezone', 'UTC'));
    }
}
