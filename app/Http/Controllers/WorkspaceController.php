<?php

namespace App\Http\Controllers;

use App\Http\Resources\ProductResource;
use App\Http\Resources\SaleResource;
use App\Models\Customer;
use App\Models\Location;
use App\Models\LowStockAlert;
use App\Models\Product;
use App\Models\ProductFamily;
use App\Models\PurchaseOrder;
use App\Models\Sale;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\User;
use App\Services\BillingService;
use App\Services\SalesAnalyticsService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class WorkspaceController extends Controller
{
    public function show(Request $request, ?string $section = null): View
    {
        $allowed = ['overview', 'dashboard', 'pos', 'inventory', 'orders', 'customers', 'reports', 'team', 'settings', 'guide', 'sales', 'purchases', 'activity', 'billing', 'channels', 'monitoring'];
        abort_if($section && ! in_array($section, $allowed, true), 404);
        abort_if(in_array($section, ['team', 'settings', 'activity', 'billing', 'monitoring'], true) && $request->user()->role !== 'admin', 403);
        abort_if(in_array($section, ['purchases', 'orders', 'channels'], true) && ! in_array($request->user()->role, ['admin', 'manager'], true), 403);

        return view('workspace', ['section' => $section ?? 'dashboard', 'user' => $request->user()]);
    }

    public function bootstrap(Request $request): JsonResponse
    {
        $user = $request->user();
        $tenantId = $user->tenant_id;
        $isCashier = $user->role === 'cashier';
        $products = Product::where('tenant_id', $tenantId)->with(['stocks', 'family'])->orderBy('id')->get();
        $sales = Sale::where('tenant_id', $tenantId)->when($isCashier, fn ($query) => $query->where('user_id', $user->id))->with(['cashier', 'returns.actor:id,name', 'channel:id,name', 'channelOrder:id,sale_id,external_reference'])->orderByDesc('created_at')->orderByDesc('id')->limit(500)->get();
        $historyQuery = Sale::where('tenant_id', $tenantId)->when($isCashier, fn ($query) => $query->where('user_id', $user->id));
        $completedQuery = (clone $historyQuery)->whereIn('status', ['completed', 'partially_returned']);
        $todayQuery = (clone $completedQuery)->whereDate('created_at', now()->toDateString());
        $locationAggregates = (clone $historyQuery)->select('location_id')->selectRaw(
            "SUM(CASE WHEN status IN ('completed', 'partially_returned') THEN total_cents - returned_cents ELSE 0 END) AS revenue_cents,
             SUM(CASE WHEN status IN ('completed', 'partially_returned') THEN 1 ELSE 0 END) AS transactions_count,
             SUM(CASE WHEN status IN ('completed', 'partially_returned') AND DATE(created_at) = ? THEN total_cents - returned_cents ELSE 0 END) AS today_revenue_cents,
             SUM(CASE WHEN status IN ('completed', 'partially_returned') AND DATE(created_at) = ? THEN 1 ELSE 0 END) AS today_sales_count,
             SUM(CASE WHEN status IN ('returned', 'partially_returned') THEN 1 ELSE 0 END) AS returned_count",
            [now()->toDateString(), now()->toDateString()],
        )->groupBy('location_id')->get()->keyBy('location_id');
        $statsByLocation = [];
        foreach (Location::where('tenant_id', $tenantId)->get(['id']) as $location) {
            $aggregate = $locationAggregates->get($location->id);
            $statsByLocation[$location->id] = [
                'revenue_total' => ($aggregate?->revenue_cents ?? 0) / 100,
                'transactions' => (int) ($aggregate?->transactions_count ?? 0),
                'revenue_today' => ($aggregate?->today_revenue_cents ?? 0) / 100,
                'sales_today' => (int) ($aggregate?->today_sales_count ?? 0),
                'returned_count' => (int) ($aggregate?->returned_count ?? 0),
            ];
        }
        $inventoryValue = $products->sum(fn (Product $product): int => $product->cost_cents * $product->stocks->sum('quantity'));
        $low = $products->filter(fn (Product $product): bool => $product->stocks->contains(fn ($stock): bool => $stock->quantity - $stock->reserved_quantity <= $product->reorder_level));
        $stats = [
            'revenue_today' => (clone $todayQuery)->sum(DB::raw('total_cents - returned_cents')) / 100, 'sales_today' => (clone $todayQuery)->count(),
            'revenue_total' => (clone $completedQuery)->sum(DB::raw('total_cents - returned_cents')) / 100, 'transactions' => (clone $completedQuery)->count(),
            'products' => $products->count(), 'stock_units' => $products->sum(fn (Product $product): int => $product->stocks->sum('quantity')),
            'reserved_units' => $products->sum(fn (Product $product): int => $product->stocks->sum('reserved_quantity')),
            'available_units' => $products->sum(fn (Product $product): int => $product->stocks->sum(fn ($stock): int => $stock->quantity - $stock->reserved_quantity)),
            'low_stock_count' => $low->count(), 'inventory_value' => $isCashier ? null : $inventoryValue / 100,
        ];
        $purchaseOrders = collect();
        $purchaseOrderHistory = null;
        $purchaseOrderStatsByLocation = [];
        if (! $isCashier) {
            $purchaseQuery = PurchaseOrder::where('tenant_id', $tenantId);
            $processedIds = (clone $purchaseQuery)->whereIn('status', ['received', 'cancelled'])->latest('id')->limit(200)->pluck('id');
            $purchaseOrders = (clone $purchaseQuery)->where(function ($query) use ($processedIds): void {
                $query->whereIn('status', ['draft', 'ordered', 'partially_received'])->orWhereIn('id', $processedIds);
            })->with('receipts.receiver:id,name')->latest('id')->get();
            $purchaseAggregates = (clone $purchaseQuery)->select('location_id')->selectRaw(
                "SUM(CASE WHEN status = 'draft' THEN 1 ELSE 0 END) AS drafts,
                 SUM(CASE WHEN status IN ('ordered', 'partially_received') THEN 1 ELSE 0 END) AS pending_deliveries,
                 SUM(CASE WHEN status = 'received' THEN 1 ELSE 0 END) AS received,
                 SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) AS cancelled",
            )->groupBy('location_id')->get();
            foreach ($purchaseAggregates as $aggregate) {
                $purchaseOrderStatsByLocation[$aggregate->location_id] = [
                    'drafts' => (int) $aggregate->drafts, 'pending_deliveries' => (int) $aggregate->pending_deliveries,
                    'received' => (int) $aggregate->received, 'cancelled' => (int) $aggregate->cancelled,
                ];
            }
            $outstandingCount = $purchaseAggregates->sum(fn ($aggregate): int => (int) $aggregate->drafts + (int) $aggregate->pending_deliveries);
            $processedCount = $purchaseAggregates->sum(fn ($aggregate): int => (int) $aggregate->received + (int) $aggregate->cancelled);
            $purchaseOrderHistory = [
                'processed_limit' => 200, 'total' => (clone $purchaseQuery)->count(), 'outstanding_count' => $outstandingCount,
                'processed_count' => $processedCount, 'loaded_processed_count' => $processedIds->count(), 'truncated' => $processedCount > $processedIds->count(),
            ];
        }

        return response()->json([
            'user' => $user->only(['id', 'name', 'email', 'role', 'is_active']),
            'tenant' => $user->tenant->only(['id', 'name', 'is_demo']),
            'locations' => Location::where('tenant_id', $tenantId)->orderBy('id')->get(['id', 'name', 'address', 'type']),
            'products' => ProductResource::collection($products)->resolve($request),
            'product_families' => $isCashier ? [] : ProductFamily::where('tenant_id', $tenantId)->withCount('products')->orderBy('id')->get()->map(fn (ProductFamily $family): array => [
                'id' => $family->id, 'name' => $family->name, 'option_names' => $family->option_names,
                'default_values' => $family->defaults, 'variant_count' => $family->products_count,
                'created_at' => $family->created_at->toIso8601String(),
            ]),
            'customers' => Customer::where('tenant_id', $tenantId)->orderBy('name')->get(['id', 'name', 'email', 'phone']),
            'suppliers' => $isCashier ? [] : Supplier::where('tenant_id', $tenantId)->orderBy('id')->get(['id', 'name', 'email', 'phone']),
            'sales' => SaleResource::collection($sales)->resolve($request),
            'movements' => $isCashier ? [] : StockMovement::where('tenant_id', $tenantId)->latest('id')->limit(200)->get(['id', 'product_id', 'location_id', 'quantity', 'type', 'reason', 'reference', 'created_at']),
            'purchase_orders' => $purchaseOrders->map(fn (PurchaseOrder $order): array => PurchaseOrderController::serialize($order)),
            'purchase_order_history' => $purchaseOrderHistory, 'purchase_order_stats_by_location' => (object) $purchaseOrderStatsByLocation,
            'team' => $user->role !== 'admin' ? [] : User::where('tenant_id', $tenantId)->orderBy('id')->get(['id', 'name', 'email', 'role', 'is_active']),
            'settings' => $user->tenant->publicSettings(), 'stats' => $stats, 'stats_by_location' => (object) $statsByLocation,
            'history_limit' => 500, 'history_total' => (clone $historyQuery)->count(),
            'subscription' => app(BillingService::class)->summary($user->tenant),
            'low_stock_alerts' => $isCashier ? [] : LowStockAlert::where('tenant_id', $tenantId)->where('active', true)->get(['id', 'product_id', 'location_id', 'quantity', 'reorder_level']),
        ]);
    }

    public function export(Request $request, SalesAnalyticsService $analytics): StreamedResponse
    {
        $data = $analytics->validatedFilters($request);
        $query = $analytics->salesQuery($request->user(), $data);

        return response()->streamDownload(function () use ($query): void {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, ['Receipt', 'Date', 'Status', 'Subtotal PHP', 'Discount PHP', 'Tax PHP', 'Total PHP', 'Location ID', 'Returned PHP', 'Net Total PHP', 'Price Tier', 'Source', 'Channel', 'External Order', 'Simulated Integration'], ',', '"', '');
            $query->with(['channel:id,name', 'channelOrder:id,sale_id,external_reference'])->chunkByIdDesc(200, function ($sales) use ($handle): void {
                foreach ($sales as $sale) {
                    $returnedCents = $sale->status === 'returned' ? $sale->total_cents : $sale->returned_cents;
                    $soldAt = CarbonImmutable::parse($sale->getRawOriginal('created_at'), config('app.timezone', 'UTC'))->setTimezone('Asia/Manila');
                    fputcsv($handle, [$sale->number, $soldAt->toIso8601String(), $sale->status, $sale->subtotal_cents / 100, $sale->discount_cents / 100, $sale->tax_cents / 100, $sale->total_cents / 100, $sale->location_id, $returnedCents / 100, ($sale->total_cents - $returnedCents) / 100, $sale->price_tier, $sale->source ?? 'pos', $this->csvText($sale->channel?->name), $this->csvText($sale->channelOrder?->external_reference), $sale->source === 'channel' ? 'yes' : 'no'], ',', '"', '');
                }
            });
            fclose($handle);
        }, 'posonly-sales-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv']);
    }

    public function health(): JsonResponse
    {
        try {
            DB::connection()->select('SELECT 1');
        } catch (\Throwable $exception) {
            report($exception);

            return response()->json(['status' => 'unavailable', 'database' => 'unavailable', 'application' => 'POSONLY'], 503)
                ->header('Cache-Control', 'no-store');
        }

        return response()->json(['status' => 'ok', 'database' => 'connected', 'application' => 'POSONLY'])
            ->header('Cache-Control', 'no-store');
    }

    private function csvText(?string $value): string
    {
        $value ??= '';

        return preg_match('/^[=+\-@\t\r\n]/u', $value) ? "'".$value : $value;
    }
}
