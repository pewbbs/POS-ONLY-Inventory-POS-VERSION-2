<?php

namespace App\Http\Controllers;

use App\Http\Resources\SaleResource;
use App\Services\OfflineSaleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class OfflineController extends Controller
{
    public function snapshot(Request $request, OfflineSaleService $offline): JsonResponse
    {
        return response()->json($offline->snapshot($request->user(), $request))
            ->header('Cache-Control', 'no-store, private');
    }

    public function store(Request $request, OfflineSaleService $offline): JsonResponse
    {
        $data = $request->validate([
            'location_id' => ['required', 'integer'],
            'customer_id' => ['prohibited'],
            'price_tier' => ['required', Rule::in(['retail', 'wholesale'])],
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*' => ['required', 'array:product_id,quantity'],
            'items.*.product_id' => ['required', 'integer', 'distinct'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:100000'],
            'discount_percent' => ['required', 'numeric', 'min:0', 'max:100', 'decimal:0,2'],
            'tax_percent' => ['required', 'numeric', 'min:0', 'max:100', 'decimal:0,2'],
            'payments' => ['required', 'array', 'size:1'],
            'payments.*' => ['required', 'array:method,amount'],
            'payments.*.method' => ['required', Rule::in(['cash'])],
            'payments.*.amount' => ['required', 'numeric', 'min:0', 'max:1000000000', 'decimal:0,2'],
            'idempotency_key' => ['required', 'string', 'min:8', 'max:100'],
            'permit' => ['required', 'string', 'max:2000'],
            'recorded_at' => ['required', 'string', 'max:40', 'date', 'regex:/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})$/'],
            'expected' => ['required', 'array:items,subtotal_cents,discount_cents,tax_cents,total_cents,cash_session_id'],
            'expected.items' => ['required', 'array', 'min:1', 'max:100'],
            'expected.items.*' => ['required', 'array:product_id,unit_price_cents'],
            'expected.items.*.product_id' => ['required', 'integer', 'distinct'],
            'expected.items.*.unit_price_cents' => ['required', 'integer', 'min:0', 'max:9007199254740991'],
            'expected.subtotal_cents' => ['required', 'integer', 'min:0', 'max:9007199254740991'],
            'expected.discount_cents' => ['required', 'integer', 'min:0', 'max:9007199254740991'],
            'expected.tax_cents' => ['required', 'integer', 'min:0', 'max:9007199254740991'],
            'expected.total_cents' => ['required', 'integer', 'min:0', 'max:9007199254740991'],
            'expected.cash_session_id' => ['present', 'nullable', 'integer', 'min:1'],
        ]);
        $sale = $offline->sync($request->user(), $data);

        return response()->json((new SaleResource($sale))->resolve($request), 201)
            ->header('Cache-Control', 'no-store, private');
    }
}
