<?php

namespace App\Http\Controllers;

use App\Models\PurchaseOrder;
use App\Models\PurchaseReceipt;
use App\Models\Tenant;
use App\Models\User;
use App\Services\InventoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class PurchaseOrderController extends Controller
{
    public static function serialize(PurchaseOrder $order): array
    {
        $items = $order->receiptItems();
        $order->loadMissing('receipts.receiver:id,name');

        return [
            'id' => $order->id, 'number' => $order->number, 'supplier_id' => $order->supplier_id,
            'location_id' => $order->location_id, 'status' => $order->status, 'items' => $items,
            'total' => $order->total_cents / 100, 'created_at' => $order->created_at->toIso8601String(),
            'received_at' => $order->received_at?->toIso8601String(), 'source' => $order->source ?? 'manual',
            'reorder_rule_id' => $order->reorder_rule_id, 'approved_at' => $order->approved_at?->toIso8601String(),
            'cancelled_at' => $order->cancelled_at?->toIso8601String(),
            'ordered_quantity' => array_sum(array_column($items, 'quantity')),
            'received_quantity' => array_sum(array_column($items, 'received_quantity')),
            'remaining_quantity' => array_sum(array_column($items, 'remaining_quantity')),
            'receipts' => $order->receipts->map(fn (PurchaseReceipt $receipt): array => [
                'id' => $receipt->id, 'number' => $receipt->number, 'note' => $receipt->note,
                'items' => $receipt->items, 'received_by' => $receipt->user_id,
                'received_by_name' => $receipt->receiver?->name ?? 'Former team member',
                'created_at' => $receipt->created_at->toIso8601String(),
            ])->all(),
        ];
    }

    public function store(Request $request, InventoryService $inventory): JsonResponse
    {
        $data = $request->validate([
            'supplier_id' => ['required', 'integer'], 'location_id' => ['required', 'integer'],
            'idempotency_key' => ['sometimes', 'required', 'string', 'min:8', 'max:100'],
            'status' => ['sometimes', 'required', Rule::in(['draft', 'ordered'])],
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.product_id' => ['required', 'integer', 'distinct'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:100000'],
            'items.*.cost' => ['nullable', 'numeric', 'min:0', 'max:1000000', 'decimal:0,2'],
        ]);

        return response()->json(self::serialize($inventory->purchaseOrder($request->user(), $data)), 201);
    }

    public function receive(Request $request, int $order, InventoryService $inventory): JsonResponse
    {
        $data = $request->validate([
            'idempotency_key' => ['required_with:items', 'string', 'max:100'],
            'items' => ['sometimes', 'required', 'array', 'min:1', 'max:100'],
            'items.*' => ['required', 'array:product_id,quantity'],
            'items.*.product_id' => ['required', 'integer', 'distinct'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:1000000'],
            'note' => ['nullable', 'string', 'max:250'],
        ]);

        return response()->json(self::serialize($inventory->receive($request->user(), $order, $data)));
    }

    public function approve(Request $request, int $order): JsonResponse
    {
        $record = DB::transaction(function () use ($request, $order): PurchaseOrder {
            Tenant::whereKey($request->user()->tenant_id)->lockForUpdate()->firstOrFail();
            $this->authorizeActor($request);
            $record = PurchaseOrder::where('tenant_id', $request->user()->tenant_id)->lockForUpdate()->findOrFail($order);
            if ($record->status === 'ordered') {
                return $record;
            }
            abort_unless($record->status === 'draft', 409, 'Only a draft purchase order can be approved.');
            $record->update(['status' => 'ordered', 'approved_at' => now()]);

            return $record;
        }, 5);

        return response()->json(self::serialize($record));
    }

    public function cancel(Request $request, int $order): JsonResponse
    {
        $record = DB::transaction(function () use ($request, $order): PurchaseOrder {
            Tenant::whereKey($request->user()->tenant_id)->lockForUpdate()->firstOrFail();
            $this->authorizeActor($request);
            $record = PurchaseOrder::where('tenant_id', $request->user()->tenant_id)->lockForUpdate()->findOrFail($order);
            if ($record->status === 'cancelled') {
                return $record;
            }
            abort_unless(in_array($record->status, ['draft', 'ordered', 'partially_received'], true), 409, 'A fully received order cannot be cancelled.');
            $record->update(['status' => 'cancelled', 'cancelled_at' => now()]);

            return $record;
        }, 5);

        return response()->json(self::serialize($record));
    }

    private function authorizeActor(Request $request): void
    {
        $actor = User::where('tenant_id', $request->user()->tenant_id)->lockForUpdate()->findOrFail($request->user()->id);
        abort_unless($actor->is_active && in_array($actor->role, ['admin', 'manager'], true), 403, 'Manager or administrator access is required for purchasing.');
    }
}
