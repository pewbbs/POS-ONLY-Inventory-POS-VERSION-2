<?php

namespace App\Http\Controllers;

use App\Http\Resources\ProductResource;
use App\Models\Customer;
use App\Models\Location;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\Subscription;
use App\Models\Supplier;
use App\Services\BillingService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

class MobileApiController extends Controller
{
    public function me(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'user' => $user->only(['id', 'name', 'email', 'role', 'is_active']),
            'tenant' => $user->tenant->only(['id', 'name']),
            'abilities' => array_values(array_filter(config('mobile-api.abilities.'.$user->role), fn (string $ability): bool => $user->tokenCan($ability))),
        ]);
    }

    public function bootstrap(Request $request, BillingService $billing): JsonResponse
    {
        $user = $request->user();
        $subscription = Subscription::where('tenant_id', $user->tenant_id)->first();
        $access = $subscription === null
            ? ['can_write' => false, 'operations_paused' => (bool) $user->tenant->operations_paused]
            : $billing->describeSubscription($user->tenant, $subscription);
        $settings = Arr::only($user->tenant->publicSettings(), ['currency', 'tax_percent', 'receipt_footer', 'receipt_address', 'receipt_phone', 'receipt_logo_url']);
        if ($settings['receipt_logo_url'] ?? null) {
            $settings['receipt_logo_url'] = route('mobile.receipt-logo', [], false).'?v='.substr(hash('sha256', $user->tenant->receiptLogoPath()), 0, 16);
        }

        return response()->json($this->me($request)->getData(true) + [
            'api_version' => 1, 'server_time' => now()->toIso8601String(),
            'locations' => Location::where('tenant_id', $user->tenant_id)->orderBy('id')->get(['id', 'name', 'address', 'type']),
            'settings' => $settings,
            'access' => Arr::only($access, ['can_write', 'operations_paused']),
            'offline' => ['cash_sales_only' => true, 'requires_online_confirmation' => true, 'snapshot_url' => route('mobile.offline.snapshot', [], false), 'sales_url' => route('mobile.offline.sales', [], false)],
        ]);
    }

    public function products(Request $request): JsonResponse
    {
        $data = $this->pagination($request) + $request->validate([
            'barcode' => ['nullable', 'string', 'max:100'], 'sku' => ['nullable', 'string', 'max:100'],
        ]);
        $query = $this->productsQuery($request);
        foreach (['barcode', 'sku'] as $field) {
            if (isset($data[$field])) {
                $query->where($field, $data[$field]);
            }
        }
        if (isset($data['q'])) {
            $query->where(function (Builder $query) use ($data): void {
                $query->where('name', 'like', '%'.$data['q'].'%')->orWhere('sku', 'like', '%'.$data['q'].'%')->orWhere('barcode', $data['q']);
            });
        }

        return ProductResource::collection($query->orderBy('id')->paginate($data['per_page'] ?? 50)->withQueryString())->response();
    }

    public function product(Request $request, int $product): JsonResponse
    {
        return response()->json((new ProductResource($this->productsQuery($request)->findOrFail($product)))->resolve($request));
    }

    private function productsQuery(Request $request): Builder
    {
        $tenantId = $request->user()->tenant_id;

        return Product::where('tenant_id', $tenantId)->with([
            'stocks' => fn (Relation $query): Relation => $query->where('tenant_id', $tenantId),
            'family' => fn (Relation $query): Relation => $query->where('tenant_id', $tenantId),
        ]);
    }

    public function customers(Request $request): JsonResponse
    {
        $data = $this->pagination($request);
        $query = Customer::where('tenant_id', $request->user()->tenant_id);
        $this->searchContacts($query, $data);

        return response()->json($query->orderBy('name')->orderBy('id')->paginate($data['per_page'] ?? 50, ['id', 'name', 'email', 'phone'])->withQueryString());
    }

    public function suppliers(Request $request): JsonResponse
    {
        $data = $this->pagination($request);
        $query = Supplier::where('tenant_id', $request->user()->tenant_id);
        $this->searchContacts($query, $data);

        return response()->json($query->orderBy('name')->orderBy('id')->paginate($data['per_page'] ?? 50, ['id', 'name', 'email', 'phone'])->withQueryString());
    }

    /** @param array<string, mixed> $data */
    private function searchContacts(Builder $query, array $data): void
    {
        if (isset($data['q'])) {
            $query->where(function (Builder $query) use ($data): void {
                $query->where('name', 'like', '%'.$data['q'].'%')->orWhere('email', 'like', '%'.$data['q'].'%')->orWhere('phone', 'like', '%'.$data['q'].'%');
            });
        }
    }

    public function purchases(Request $request): JsonResponse
    {
        $data = $this->pagination($request) + $request->validate([
            'status' => ['nullable', 'in:draft,ordered,partially_received,received,cancelled'],
            'location_id' => ['nullable', 'integer'],
        ]);
        if (isset($data['location_id'])) {
            Location::where('tenant_id', $request->user()->tenant_id)->findOrFail($data['location_id']);
        }
        $query = PurchaseOrder::where('tenant_id', $request->user()->tenant_id)->with('receipts.receiver:id,name');
        foreach (['status', 'location_id'] as $field) {
            if (isset($data[$field])) {
                $query->where($field, $data[$field]);
            }
        }
        if (isset($data['q'])) {
            $query->where('number', 'like', '%'.$data['q'].'%');
        }
        $page = $query->orderByDesc('created_at')->orderByDesc('id')->paginate($data['per_page'] ?? 25)->withQueryString();
        $page->through(fn (PurchaseOrder $order): array => PurchaseOrderController::serialize($order));

        return response()->json($page);
    }

    public function purchase(Request $request, int $order): JsonResponse
    {
        $record = PurchaseOrder::where('tenant_id', $request->user()->tenant_id)->with('receipts.receiver:id,name')->findOrFail($order);

        return response()->json(PurchaseOrderController::serialize($record));
    }

    /** @return array<string, mixed> */
    private function pagination(Request $request): array
    {
        return $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1', 'max:100000'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
    }
}
