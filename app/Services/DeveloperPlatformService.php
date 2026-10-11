<?php

namespace App\Services;

use App\Models\BillingInvoice;
use App\Models\BillingPaymentAttempt;
use App\Models\Location;
use App\Models\PlatformAdmin;
use App\Models\PlatformAuditLog;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class DeveloperPlatformService
{
    public function __construct(private BillingService $billing, private PayMongoClient $paymongo) {}

    public function authorize(PlatformAdmin $actor): PlatformAdmin
    {
        $account = PlatformAdmin::findOrFail($actor->id);
        abort_unless($account->is_active && $account->password !== null, 403, 'Developer access is unavailable.');

        return $account;
    }

    public function filters(Request $request): array
    {
        return $request->validate(['section' => ['sometimes', Rule::in(['overview', 'companies', 'billing', 'content', 'activity'])],
            'page' => ['sometimes', 'integer', 'min:1'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
            'search' => ['nullable', 'string', 'max:100'], 'status' => ['sometimes', Rule::in(['all', 'open', 'paid', 'void'])],
            'tenant_id' => ['nullable', 'integer', 'min:1']]) + ['section' => 'overview', 'page' => 1, 'per_page' => 25, 'search' => null, 'status' => 'all', 'tenant_id' => null];
    }

    public function bootstrap(PlatformAdmin $actor, array $filters): array
    {
        $account = $this->authorize($actor);
        $companies = $this->companyQuery()->when($filters['search'], function (Builder $query, string $search): void {
            $query->whereRaw("name LIKE ? ESCAPE '!'", ['%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search).'%']);
        })->latest('id')->paginate($filters['per_page'], ['*'], 'page', $filters['page']);
        $activity = PlatformAuditLog::with(['actor:id,name', 'tenant:id,name'])
            ->when($filters['search'], function (Builder $query, string $search): void {
                $pattern = $this->searchPattern($search);
                $query->where(function (Builder $match) use ($pattern): void {
                    $match->whereRaw("action LIKE ? ESCAPE '!'", [$pattern])
                        ->orWhereRaw("reason LIKE ? ESCAPE '!'", [$pattern])
                        ->orWhereIn('tenant_id', Tenant::select('id')->whereRaw("name LIKE ? ESCAPE '!'", [$pattern]));
                });
            })
            ->when($filters['tenant_id'], fn (Builder $query, int $id): Builder => $query->where('tenant_id', $id))
            ->latest('id')->paginate($filters['per_page'], ['*'], 'page', $filters['page']);

        return ['developer' => ['id' => $account->id, 'name' => $account->name, 'email' => $account->email],
            'section' => $filters['section'], 'overview' => $this->overview(),
            'companies' => $this->page($companies, fn (Tenant $tenant): array => $this->serializeCompany($tenant)),
            'invoices' => $this->invoices($filters),
            'activity' => $this->page($activity, fn (PlatformAuditLog $entry): array => [
                'id' => $entry->id, 'actor' => $entry->actor ? ['id' => $entry->actor->id, 'name' => $entry->actor->name] : null,
                'tenant_id' => $entry->tenant_id, 'company_name' => $entry->tenant?->name,
                'action' => $entry->action, 'reason' => $entry->reason, 'metadata' => $entry->metadata, 'created_at' => $entry->created_at->toIso8601String(),
            ]), 'plans' => $this->plans(), 'payment_config' => $this->paymongo->configuration()];
    }

    private function overview(): array
    {
        return ['companies' => Tenant::count(), 'paused_companies' => Tenant::where('operations_paused', true)->count(),
            'demo_companies' => Tenant::where('is_demo', true)->count(), 'subscriptions' => Subscription::count(),
            'open_invoices' => BillingInvoice::where('status', 'open')->count(),
            'paid_simulation_total' => BillingInvoice::where('status', 'paid')->where(function (Builder $query): void {
                $query->where('payment_provider', 'simulation')->orWhere('payment_method', 'simulation');
            })->sum('amount_cents') / 100,
            'paid_paymongo_test_total' => BillingInvoice::where('status', 'paid')->where('payment_provider', 'paymongo')->where('payment_mode', 'test')->sum('amount_cents') / 100,
            'notice' => 'Recorded subscription invoices only. Simulation and PayMongo TEST payments are shown separately and are not live revenue or MRR. Renewals do not charge automatically.'];
    }

    public function detail(PlatformAdmin $actor, int $tenantId, array $filters): array
    {
        $this->authorize($actor);
        $tenant = $this->companyQuery()->findOrFail($tenantId);
        $filters['tenant_id'] = $tenant->id;

        return ['company' => $this->serializeCompany($tenant), 'invoices' => $this->invoices($filters),
            'attempts' => BillingPaymentAttempt::where('tenant_id', $tenant->id)->latest('id')->limit(50)->get()->map(fn (BillingPaymentAttempt $attempt): array => $attempt->safeRecord())->all(),
            'attempt_total' => BillingPaymentAttempt::where('tenant_id', $tenant->id)->count()];
    }

    public function mutate(PlatformAdmin $actor, int $tenantId, string $action, array $input): array
    {
        $rules = ['reason' => ['required', 'string', 'min:3', 'max:300']];
        if ($action === 'plan') {
            $rules += ['plan' => ['required', Rule::in(array_keys(config('billing.plans')))], 'billing_cycle' => ['required', Rule::in(['monthly', 'annual'])]];
        }
        $data = Validator::make($input, $rules)->validate();
        abort_unless(in_array($action, ['pause', 'resume', 'plan', 'cancel-renewal', 'resume-renewal', 'invoice'], true), 404);
        DB::transaction(function () use ($actor, $tenantId, $action, $data): void {
            $tenant = Tenant::whereKey($tenantId)->lockForUpdate()->firstOrFail();
            $account = PlatformAdmin::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            abort_unless($account->is_active && $account->password !== null, 403, 'Developer access is unavailable.');
            $before = $this->auditState($tenant);
            if (in_array($action, ['pause', 'resume'], true)) {
                $tenant->update(['operations_paused' => $action === 'pause']);
            } else {
                $this->billing->applyLockedPlatformAction($tenant, $action, $data);
            }
            $after = $this->auditState($tenant->fresh());
            $names = ['pause' => 'company.paused', 'resume' => 'company.resumed', 'plan' => 'subscription.plan_changed',
                'cancel-renewal' => 'subscription.renewal_canceled', 'resume-renewal' => 'subscription.renewal_resumed', 'invoice' => 'subscription.invoice_created'];
            PlatformAuditLog::create(['platform_admin_id' => $account->id, 'tenant_id' => $tenant->id,
                'action' => $names[$action], 'reason' => $data['reason'], 'metadata' => ['before' => $before, 'after' => $after], 'created_at' => now()]);
        }, 5);

        return ['message' => 'Company administration updated.', 'company' => $this->serializeCompany($this->companyQuery()->findOrFail($tenantId))];
    }

    private function auditState(Tenant $tenant): array
    {
        $subscription = Subscription::where('tenant_id', $tenant->id)->first();

        return ['operations_paused' => $tenant->operations_paused, 'plan' => $subscription?->plan,
            'billing_cycle' => $subscription?->billing_cycle, 'status' => $subscription?->status,
            'cancel_at_period_end' => $subscription?->cancel_at_period_end,
            'pending_plan' => $subscription?->pending_plan, 'pending_billing_cycle' => $subscription?->pending_billing_cycle];
    }

    private function companyQuery(): Builder
    {
        return Tenant::query()->with('subscription')
            ->addSelect(['locations_count' => Location::selectRaw('count(*)')->whereColumn('locations.tenant_id', 'tenants.id'),
                'active_team_count' => User::selectRaw('count(*)')->whereColumn('users.tenant_id', 'tenants.id')->where('is_active', true)]);
    }

    private function serializeCompany(Tenant $tenant): array
    {
        $plan = $tenant->subscription?->plan ?? ($tenant->settings['plan'] ?? 'grow');

        return ['id' => $tenant->id, 'name' => $tenant->name, 'is_demo' => $tenant->is_demo, 'operations_paused' => $tenant->operations_paused,
            'created_at' => $tenant->created_at->toIso8601String(),
            'usage' => ['locations' => (int) $tenant->locations_count, 'team_members' => (int) $tenant->active_team_count,
                'limits' => ['locations' => config('billing.plans.'.$plan.'.locations'), 'team_members' => config('billing.plans.'.$plan.'.team_members')]],
            'subscription' => $tenant->subscription ? $this->billing->describeSubscription($tenant, $tenant->subscription) : null];
    }

    private function invoices(array $filters): array
    {
        $invoices = BillingInvoice::with(['latestPaymentAttempt', 'confirmedPaymentAttempt'])
            ->when($filters['search'], function (Builder $query, string $search): void {
                $pattern = $this->searchPattern($search);
                $query->where(function (Builder $match) use ($pattern): void {
                    $match->whereRaw("number LIKE ? ESCAPE '!'", [$pattern])
                        ->orWhereIn('tenant_id', Tenant::select('id')->whereRaw("name LIKE ? ESCAPE '!'", [$pattern]));
                });
            })
            ->when($filters['tenant_id'], fn (Builder $query, int $id): Builder => $query->where('tenant_id', $id))
            ->when($filters['status'] !== 'all', fn (Builder $query): Builder => $query->where('status', $filters['status']))
            ->latest('id')->paginate($filters['per_page'], ['*'], 'page', $filters['page']);
        $companies = Tenant::whereIn('id', $invoices->getCollection()->pluck('tenant_id'))->pluck('name', 'id');

        return $this->page($invoices, fn (BillingInvoice $invoice): array => $this->billing->serializeInvoice($invoice) + [
            'tenant_id' => $invoice->tenant_id, 'company_name' => $companies[$invoice->tenant_id] ?? null]);
    }

    /** @param callable(mixed):array $serialize */
    private function page(LengthAwarePaginator $page, callable $serialize): array
    {
        return ['data' => $page->getCollection()->map($serialize)->all(), 'meta' => ['current_page' => $page->currentPage(),
            'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()]];
    }

    private function plans(): array
    {
        return collect(config('billing.plans'))->map(fn (array $plan, string $id): array => [
            'id' => $id, 'name' => $plan['name'], 'monthly_price' => $plan['monthly_cents'] / 100,
            'annual_price' => $plan['annual_cents'] / 100, 'limits' => ['locations' => $plan['locations'], 'team_members' => $plan['team_members']],
        ])->values()->all();
    }

    private function searchPattern(string $search): string
    {
        return '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search).'%';
    }
}
