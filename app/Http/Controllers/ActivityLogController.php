<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ActivityLogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $tenantId = $request->user()->tenant_id;
        $toRules = ['nullable', 'date_format:Y-m-d'];
        if ($request->filled('from')) {
            $toRules[] = 'after_or_equal:from';
        }
        $data = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'actor_id' => ['nullable', 'integer', Rule::exists('users', 'id')->where('tenant_id', $tenantId)],
            'action' => ['nullable', 'string', 'max:80', Rule::in(['checkout.confirmed', 'return.confirmed', 'product.created', 'product.updated', 'product_family.created', 'product_family.variants_added', 'inventory.adjusted', 'inventory.transferred', 'purchase.created', 'delivery.confirmed', 'customer.created', 'supplier.created', 'location.created', 'member.created', 'member.updated', 'settings.updated', 'purchase.approved', 'purchase.canceled', 'replenishment.configured', 'replenishment.generated', 'subscription.plan_changed', 'subscription.invoice_created', 'subscription.canceled', 'subscription.resumed', 'subscription.payment_simulated', 'subscription.test_checkout_requested', 'subscription.test_payment_checked', 'subscription.test_checkout_recovered', 'subscription.checkout_requested', 'subscription.payment_checked', 'subscription.checkout_recovered', 'register.opened', 'register.cash_recorded', 'register.closed', 'channel.created', 'channel.updated', 'channel.paused', 'channel.resumed', 'channel.sync_recorded', 'channel.order_reserved', 'channel.order_imported', 'channel.order_canceled'])],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => $toRules,
        ]);
        $query = ActivityLog::where('tenant_id', $tenantId)->with('actor:id,name');
        if (! empty($data['actor_id'])) {
            $query->where('actor_id', $data['actor_id']);
        }
        if (! empty($data['action'])) {
            $query->where('action', $data['action']);
        }
        if (! empty($data['from'])) {
            $query->where('created_at', '>=', $data['from'].' 00:00:00');
        }
        if (! empty($data['to'])) {
            $query->where('created_at', '<=', $data['to'].' 23:59:59');
        }
        $page = $query->latest('id')->paginate($data['per_page'] ?? 25);

        return response()->json([
            'data' => $page->getCollection()->map(fn (ActivityLog $entry): array => [
                'id' => $entry->id,
                'actor_id' => $entry->actor_id,
                'actor_name' => $entry->actor?->name ?? 'Former team member',
                'action' => $entry->action,
                'resource_type' => $entry->resource_type,
                'resource_id' => $entry->resource_id,
                'created_at' => $entry->created_at->toIso8601String(),
            ]),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
            'actors' => User::where('tenant_id', $tenantId)->orderBy('name')->get(['id', 'name']),
            'coverage' => 'Successful workspace actions since activity logging was enabled. Repeated checkout, return and delivery confirmations may appear more than once.',
        ]);
    }
}
