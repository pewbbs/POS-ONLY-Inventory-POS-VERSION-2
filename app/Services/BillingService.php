<?php

namespace App\Services;

use App\Models\BillingInvoice;
use App\Models\BillingPaymentAttempt;
use App\Models\Location;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class BillingService
{
    public function __construct(private PayMongoClient $paymongo) {}

    public function simulationEnabled(): bool
    {
        return app()->environment(['local', 'testing']) && config('billing.simulations_enabled') && config('billing.payment_provider', 'simulation') === 'simulation';
    }

    public function process(Tenant $tenant): Subscription
    {
        return DB::transaction(function () use ($tenant): Subscription {
            $tenant = Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            $subscription = $this->ensure($tenant);
            $this->reconcile($subscription);

            return $subscription;
        }, 5);
    }

    private function ensure(Tenant $tenant): Subscription
    {
        $settings = $tenant->settings;
        $plan = array_key_exists($settings['plan'] ?? '', config('billing.plans')) ? $settings['plan'] : 'grow';

        return Subscription::firstOrCreate(['tenant_id' => $tenant->id], [
            'plan' => $plan, 'billing_cycle' => ($settings['billing_cycle'] ?? '') === 'annual' ? 'annual' : 'monthly',
            'status' => 'trialing', 'trial_ends_at' => $tenant->created_at->copy()->addDays(config('billing.trial_days')),
            'cancel_at_period_end' => false,
        ]);
    }

    private function reconcile(Subscription $subscription): void
    {
        $end = $subscription->current_period_end ?? $subscription->trial_ends_at;
        if ($end->isPast() || $end->equalTo(now())) {
            $status = $subscription->cancel_at_period_end ? 'canceled' : 'past_due';
            if ($subscription->status !== $status) {
                $subscription->update(['status' => $status]);
            }
            if ($status === 'past_due') {
                if (! $this->hasOutstandingCheckout($subscription)) {
                    $this->invoice($subscription);
                }
            }
        }
    }

    public function summary(Tenant $tenant): array
    {
        $subscription = $this->process($tenant);

        return $this->describeSubscription($tenant->fresh(), $subscription);
    }

    /** Pure description: platform reads never create subscriptions or reconcile invoices. */
    public function describeSubscription(Tenant $tenant, Subscription $subscription): array
    {
        $end = ($subscription->current_period_end ?? $subscription->trial_ends_at)->copy();
        $accessEnd = $subscription->cancel_at_period_end ? $end : $end->copy()->addDays(config('billing.grace_days'));

        return [
            'id' => $subscription->id, 'plan' => $subscription->plan, 'billing_cycle' => $subscription->billing_cycle,
            'status' => now()->greaterThanOrEqualTo($end) ? ($subscription->cancel_at_period_end ? 'canceled' : 'past_due') : $subscription->status, 'trial_ends_at' => $subscription->trial_ends_at->toIso8601String(),
            'current_period_start' => $subscription->current_period_start?->toIso8601String(),
            'current_period_end' => $subscription->current_period_end?->toIso8601String(),
            'cancel_at_period_end' => $subscription->cancel_at_period_end,
            'pending_plan' => $subscription->pending_plan, 'pending_billing_cycle' => $subscription->pending_billing_cycle,
            'can_write' => ! $tenant->operations_paused && ($tenant->is_demo || now()->lessThan($accessEnd)),
            'operations_paused' => $tenant->operations_paused,
            'access_ends_at' => $accessEnd->toIso8601String(), 'demo_exempt' => $tenant->is_demo,
        ];
    }

    public function state(Tenant $tenant): array
    {
        $summary = $this->summary($tenant);

        return [
            'plans' => collect(config('billing.plans'))->map(fn (array $plan, string $id): array => [
                'id' => $id, 'name' => $plan['name'], 'monthly_price' => $plan['monthly_cents'] / 100,
                'annual_price' => $plan['annual_cents'] / 100,
                'limits' => ['locations' => $plan['locations'], 'team_members' => $plan['team_members']],
            ])->values()->all(),
            'subscription' => $summary, 'usage' => $this->usage($tenant, $summary['plan']),
            'invoices' => BillingInvoice::where('tenant_id', $tenant->id)->with(['latestPaymentAttempt', 'confirmedPaymentAttempt'])->latest('id')->limit(100)->get()->map(fn (BillingInvoice $invoice): array => $this->serializeInvoice($invoice))->all(),
            'invoice_total' => BillingInvoice::where('tenant_id', $tenant->id)->count(),
            'simulation_enabled' => $this->simulationEnabled(),
            'payment_config' => $this->paymongo->configuration(),
            'payment_attempts' => BillingPaymentAttempt::where('tenant_id', $tenant->id)->latest('id')->limit(100)->get()->map(fn (BillingPaymentAttempt $attempt): array => $attempt->safeRecord())->all(),
            'payment_attempt_total' => BillingPaymentAttempt::where('tenant_id', $tenant->id)->count(),
            'notice' => $this->paymongo->configuration()['notice'].' Plan changes on a paid subscription start at the next renewal. Renewals create invoices; they do not collect payments automatically.',
        ];
    }

    public function serializeInvoice(BillingInvoice $invoice): array
    {
        return ['id' => $invoice->id, 'number' => $invoice->number, 'plan' => $invoice->plan,
            'billing_cycle' => $invoice->billing_cycle, 'amount' => $invoice->amount_cents / 100,
            'currency' => $invoice->currency, 'status' => $invoice->status,
            'due_at' => $invoice->due_at->toIso8601String(), 'paid_at' => $invoice->paid_at?->toIso8601String(),
            'period_start' => $invoice->period_start?->toIso8601String(), 'period_end' => $invoice->period_end?->toIso8601String(),
            'payment_method' => $invoice->payment_method,
            'payment_provider' => $invoice->payment_provider ?? ($invoice->payment_method === 'simulation' ? 'simulation' : null),
            'payment_mode' => $invoice->payment_mode ?? ($invoice->payment_method === 'simulation' ? 'simulation' : null),
            'payment_source' => $invoice->payment_provider === 'paymongo'
                ? (in_array($invoice->payment_mode, ['test', 'live'], true) ? 'paymongo_'.$invoice->payment_mode : 'paymongo_unknown')
                : ($invoice->payment_method === 'simulation' ? 'simulation' : null),
            'latest_attempt' => $invoice->relationLoaded('latestPaymentAttempt') ? $invoice->latestPaymentAttempt?->safeRecord() : null,
            'confirmed_attempt' => $invoice->payment_provider === 'paymongo' && $invoice->relationLoaded('confirmedPaymentAttempt') ? $invoice->confirmedPaymentAttempt?->safeRecord() : null];
    }

    public function usage(Tenant $tenant, string $plan): array
    {
        return ['locations' => Location::where('tenant_id', $tenant->id)->count(),
            'team_members' => User::where('tenant_id', $tenant->id)->where('is_active', true)->count(),
            'limits' => $this->limits($plan)];
    }

    private function limits(string $plan): array
    {
        return ['locations' => config('billing.plans.'.$plan.'.locations'), 'team_members' => config('billing.plans.'.$plan.'.team_members')];
    }

    private function checkPlanFits(Tenant $tenant, string $plan): void
    {
        $usage = $this->usage($tenant, $plan);
        foreach (['locations', 'team_members'] as $resource) {
            if ($usage['limits'][$resource] !== null && $usage[$resource] > $usage['limits'][$resource]) {
                throw ValidationException::withMessages(['plan' => 'This plan does not fit your current locations or active team. Choose a larger plan, or reduce active team access first.']);
            }
        }
    }

    /** Called under the tenant lock before sending a hosted checkout request. */
    public function assertInvoicePayable(Tenant $tenant, BillingInvoice $invoice): void
    {
        $subscription = $this->ensure($tenant);
        abort_unless($invoice->tenant_id === $tenant->id && $invoice->subscription_id === $subscription->id && $invoice->status === 'open', 409, 'This invoice is no longer payable. Refresh Billing.');
        if ($subscription->cancel_at_period_end || $subscription->status === 'canceled' || $subscription->current_period_end?->isFuture()) {
            throw ValidationException::withMessages(['subscription' => 'This subscription cannot start another invoice payment. Refresh Billing.']);
        }
        $this->checkPlanFits($tenant, $invoice->plan);
    }

    public function assertCapacity(Tenant $tenant, string $resource): void
    {
        $subscription = $this->ensure($tenant);
        $usage = $this->usage($tenant, $subscription->plan);
        $limit = $usage['limits'][$resource];
        if ($limit !== null && $usage[$resource] >= $limit) {
            throw ValidationException::withMessages([$resource => 'Your plan limit has been reached. Ask an administrator to review Billing or pause an unused team member.']);
        }
    }

    /** @param callable(Subscription, Tenant): void $action */
    private function mutate(User $actor, callable $action): void
    {
        DB::transaction(function () use ($actor, $action): void {
            $tenant = Tenant::whereKey($actor->tenant_id)->lockForUpdate()->firstOrFail();
            $user = User::where('tenant_id', $tenant->id)->lockForUpdate()->findOrFail($actor->id);
            abort_unless($user->role === 'admin' && $user->is_active, 403);
            $subscription = $this->ensure($tenant);
            $this->reconcile($subscription);
            $action($subscription, $tenant);
        }, 5);
    }

    public function changePlan(User $actor, string $plan, string $cycle): void
    {
        $this->mutate($actor, fn (Subscription $subscription, Tenant $tenant) => $this->applyAction($subscription, $tenant, 'plan', ['plan' => $plan, 'billing_cycle' => $cycle]));
    }

    public function checkout(User $actor): void
    {
        $this->mutate($actor, fn (Subscription $subscription, Tenant $tenant) => $this->applyAction($subscription, $tenant, 'invoice'));
    }

    /** The caller must hold the tenant lock and freshly authorize its platform actor. */
    public function applyLockedPlatformAction(Tenant $tenant, string $action, array $data = []): void
    {
        $subscription = $this->ensure($tenant);
        $this->reconcile($subscription);
        $this->applyAction($subscription, $tenant, $action, $data);
    }

    private function applyAction(Subscription $subscription, Tenant $tenant, string $action, array $data = []): void
    {
        if ($action === 'plan') {
            $plan = $data['plan'];
            $cycle = $data['billing_cycle'];
            if (! array_key_exists($plan, config('billing.plans')) || ! in_array($cycle, ['monthly', 'annual'], true)) {
                throw ValidationException::withMessages(['plan' => 'Choose a configured plan and billing cycle.']);
            }
            $this->checkPlanFits($tenant, $plan);
            if ($subscription->plan === $plan && $subscription->billing_cycle === $cycle
                && $subscription->pending_plan === null && $subscription->pending_billing_cycle === null) {
                return;
            }
            $this->assertNoOutstandingCheckout($subscription);
            if ($subscription->current_period_end?->isFuture()) {
                $subscription->update(['pending_plan' => $plan === $subscription->plan && $cycle === $subscription->billing_cycle ? null : $plan,
                    'pending_billing_cycle' => $plan === $subscription->plan && $cycle === $subscription->billing_cycle ? null : $cycle]);
            } else {
                $subscription->update(['plan' => $plan, 'billing_cycle' => $cycle, 'pending_plan' => null, 'pending_billing_cycle' => null]);
                $this->voidOpenInvoices($subscription);
                if ($subscription->status === 'past_due') {
                    $this->invoice($subscription);
                }
                $this->syncSettings($subscription, $tenant);
            }

            return;
        }
        if ($action === 'invoice') {
            if ($subscription->cancel_at_period_end || $subscription->status === 'canceled') {
                throw ValidationException::withMessages(['subscription' => 'Resume your subscription before creating an invoice.']);
            }
            if ($subscription->current_period_end?->isFuture()) {
                throw ValidationException::withMessages(['subscription' => 'This period is already paid. A renewal invoice will be created when it ends.']);
            }
            $this->checkPlanFits($tenant, $subscription->pending_plan ?? $subscription->plan);
            $this->assertNoOutstandingCheckout($subscription);
            $this->invoice($subscription);

            return;
        }
        if ($action === 'cancel-renewal') {
            $this->assertNoOutstandingCheckout($subscription);
            $subscription->update(['cancel_at_period_end' => true, 'pending_plan' => null, 'pending_billing_cycle' => null]);
            $this->voidOpenInvoices($subscription);
            $this->reconcile($subscription);

            return;
        }
        if ($action === 'resume-renewal') {
            $subscription->update(['cancel_at_period_end' => false]);
            $this->reconcile($subscription);

            return;
        }
        abort(404);
    }

    private function invoice(Subscription $subscription): BillingInvoice
    {
        $existing = BillingInvoice::where('subscription_id', $subscription->id)->where('status', 'open')->first();
        if ($existing) {
            return $existing;
        }
        $plan = $subscription->pending_plan ?? $subscription->plan;
        $cycle = $subscription->pending_billing_cycle ?? $subscription->billing_cycle;

        return BillingInvoice::create(['tenant_id' => $subscription->tenant_id, 'subscription_id' => $subscription->id,
            'number' => 'INV-'.now()->format('ymd').'-'.Str::upper(Str::random(12)), 'plan' => $plan, 'billing_cycle' => $cycle,
            'amount_cents' => config('billing.plans.'.$plan.'.'.$cycle.'_cents'), 'currency' => 'PHP', 'status' => 'open', 'due_at' => now()]);
    }

    private function hasOutstandingCheckout(Subscription $subscription): bool
    {
        return BillingPaymentAttempt::where('tenant_id', $subscription->tenant_id)
            ->whereIn('status', ['creating', 'pending', 'unknown', 'needs_review'])->exists();
    }

    private function assertNoOutstandingCheckout(Subscription $subscription): void
    {
        if ($this->hasOutstandingCheckout($subscription)) {
            throw ValidationException::withMessages(['payment' => 'Resolve the outstanding PayMongo checkout before changing or canceling this subscription or starting another invoice.']);
        }
    }

    public function simulatePayment(User $actor, int $invoiceId, string $key): void
    {
        abort_unless($this->simulationEnabled(), 404);
        $this->mutate($actor, function (Subscription $subscription, Tenant $tenant) use ($actor, $invoiceId, $key): void {
            $invoice = BillingInvoice::where('tenant_id', $tenant->id)->lockForUpdate()->findOrFail($invoiceId);
            if ($invoice->status === 'paid') {
                abort_unless($invoice->payment_key === $key && $invoice->paid_by === $actor->id, 409, 'This invoice already has a payment confirmation.');

                return;
            }
            abort_unless($invoice->status === 'open', 409, 'This invoice is no longer payable. Refresh Billing.');
            abort_if(BillingInvoice::where('tenant_id', $tenant->id)->where('payment_key', $key)->exists(), 409, 'This payment key was already used for another invoice.');
            $this->checkPlanFits($tenant, $invoice->plan);
            $this->activateInvoice($invoice, $subscription, $tenant, now(), 'simulation', $key, $actor->id, 'simulation', 'simulation');
        });
    }

    /** Provider evidence is verified before entry; settlement locks keep all replays one-time. */
    public function settleVerifiedAttempt(BillingPaymentAttempt $candidate, string $paymentId, CarbonInterface $paidAt, string $method, ?string $reviewReason = null): BillingPaymentAttempt
    {
        return DB::transaction(function () use ($candidate, $paymentId, $paidAt, $method, $reviewReason): BillingPaymentAttempt {
            $tenant = Tenant::whereKey($candidate->tenant_id)->lockForUpdate()->firstOrFail();
            $attempt = BillingPaymentAttempt::where('tenant_id', $tenant->id)->lockForUpdate()->findOrFail($candidate->id);
            if (in_array($attempt->status, ['paid', 'needs_review'], true)) {
                return $attempt;
            }
            $invoice = BillingInvoice::where('tenant_id', $tenant->id)->lockForUpdate()->findOrFail($attempt->billing_invoice_id);
            $subscription = $this->ensure($tenant);
            $duplicate = BillingPaymentAttempt::where('payment_id', $paymentId)->whereKeyNot($attempt->id)->exists();
            $reason = match (true) {
                $duplicate => 'This payment was already associated with another checkout record.',
                $reviewReason !== null => $reviewReason,
                $invoice->subscription_id !== $subscription->id => 'The paid checkout does not match the current subscription record.',
                $invoice->status !== 'open' => 'A payment was confirmed after this invoice was paid or closed. Review it with the provider.',
                $subscription->cancel_at_period_end || $subscription->status === 'canceled' => 'A payment was confirmed after subscription cancellation. Review it with the provider.',
                $subscription->current_period_end?->isFuture() === true => 'A payment was confirmed while this subscription period was already paid. Review it with the provider.',
                ! $this->paymongo->modeAllowed() || $attempt->mode !== $this->paymongo->mode()
                    || ! $this->paymongo->configuration()['checkout_enabled']
                    || $attempt->amount_cents !== (int) $invoice->amount_cents || $attempt->currency !== $invoice->currency => 'The paid checkout does not match the stored invoice amount or mode.',
                ! array_key_exists($invoice->plan, config('billing.plans')) => 'The paid invoice plan is no longer configured.',
                default => null,
            };
            if ($reason === null) {
                try {
                    $this->checkPlanFits($tenant, $invoice->plan);
                } catch (ValidationException) {
                    $reason = 'A payment was confirmed, but this plan no longer fits the active team or locations. Review Billing.';
                }
            }
            $attempt->update(['payment_id' => $duplicate ? null : $paymentId, 'confirmed_at' => $paidAt,
                'status' => $reason === null ? 'paid' : 'needs_review', 'failure_summary' => $reason,
                'active_scope' => $reason === null ? null : $attempt->active_scope]);
            if ($reason === null) {
                $this->activateInvoice($invoice, $subscription, $tenant, $paidAt, $method, 'paymongo:'.$paymentId, $attempt->initiated_by, 'paymongo', $attempt->mode);
            }

            return $attempt;
        }, 5);
    }

    private function activateInvoice(BillingInvoice $invoice, Subscription $subscription, Tenant $tenant, CarbonInterface $start, string $method, string $key, ?int $paidBy, string $provider, string $mode): void
    {
        $end = $invoice->billing_cycle === 'annual' ? $start->copy()->addYearNoOverflow() : $start->copy()->addMonthNoOverflow();
        $invoice->update(['status' => 'paid', 'paid_at' => $start, 'period_start' => $start, 'period_end' => $end,
            'payment_method' => $method, 'payment_key' => $key, 'paid_by' => $paidBy, 'payment_provider' => $provider, 'payment_mode' => $mode]);
        $subscription->update(['plan' => $invoice->plan, 'billing_cycle' => $invoice->billing_cycle,
            'status' => 'active', 'current_period_start' => $start, 'current_period_end' => $end,
            'cancel_at_period_end' => false, 'pending_plan' => null, 'pending_billing_cycle' => null]);
        $this->syncSettings($subscription, $tenant);
    }

    public function cancel(User $actor): void
    {
        $this->mutate($actor, fn (Subscription $subscription, Tenant $tenant) => $this->applyAction($subscription, $tenant, 'cancel-renewal'));
    }

    public function resume(User $actor): void
    {
        $this->mutate($actor, fn (Subscription $subscription, Tenant $tenant) => $this->applyAction($subscription, $tenant, 'resume-renewal'));
    }

    private function voidOpenInvoices(Subscription $subscription): void
    {
        BillingInvoice::where('subscription_id', $subscription->id)->where('status', 'open')->update(['status' => 'void']);
    }

    private function syncSettings(Subscription $subscription, Tenant $tenant): void
    {
        $tenant->update(['settings' => array_replace($tenant->settings, ['plan' => $subscription->plan, 'billing_cycle' => $subscription->billing_cycle])]);
    }
}
