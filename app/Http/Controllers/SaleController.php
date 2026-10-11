<?php

namespace App\Http\Controllers;

use App\Http\Resources\SaleResource;
use App\Models\Sale;
use App\Services\InventoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SaleController extends Controller
{
    public function show(Request $request, int $sale): JsonResponse
    {
        $user = $request->user();
        $record = Sale::where('tenant_id', $user->tenant_id)
            ->when($user->role === 'cashier', fn ($query) => $query->where('user_id', $user->id))
            ->with(['cashier', 'returns.actor:id,name', 'channel:id,name', 'channelOrder:id,sale_id,external_reference'])
            ->findOrFail($sale);

        return response()->json((new SaleResource($record))->resolve($request));
    }

    public function store(Request $request, InventoryService $inventory): JsonResponse
    {
        $data = $request->validate([
            'location_id' => ['required', 'integer'],
            'customer_id' => ['nullable', 'integer'],
            'price_tier' => ['nullable', Rule::in(['retail', 'wholesale'])],
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.product_id' => ['required', 'integer', 'distinct'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:100000'],
            'discount_percent' => ['nullable', 'numeric', 'min:0', 'max:100', 'decimal:0,2'],
            'tax_percent' => ['nullable', 'numeric', 'min:0', 'max:100', 'decimal:0,2'],
            'payments' => ['required', 'array', 'min:1', 'max:5'],
            'payments.*.method' => ['required', Rule::in(['cash', 'card', 'wallet'])],
            'payments.*.amount' => ['required', 'numeric', 'min:0', 'max:1000000000', 'decimal:0,2'],
            'idempotency_key' => ['required', 'string', 'min:8', 'max:100'],
        ]);
        $sale = $inventory->sale($request->user(), $data);

        return response()->json((new SaleResource($sale))->resolve($request), 201);
    }

    public function returnSale(Request $request, int $sale, InventoryService $inventory): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['nullable', 'required_with:items', 'string', 'max:200'],
            'items' => ['sometimes', 'array', 'min:1', 'max:100'],
            'items.*.product_id' => ['required', 'integer', 'distinct'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:100000'],
            'idempotency_key' => ['nullable', 'required_with:items', 'string', 'min:8', 'max:100'],
        ]);
        $record = $inventory->returnSale($request->user(), $sale, $data);

        return response()->json((new SaleResource($record))->resolve($request));
    }
}
