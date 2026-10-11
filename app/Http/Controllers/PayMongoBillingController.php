<?php

namespace App\Http\Controllers;

use App\Services\BillingService;
use App\Services\PayMongoBillingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class PayMongoBillingController extends Controller
{
    public function checkout(Request $request, int $invoice, PayMongoBillingService $payments, BillingService $billing): JsonResponse
    {
        $result = $payments->startCheckout($request->user(), $invoice);

        return response()->json([...$result, 'billing' => $billing->state($request->user()->tenant)])
            ->header('Cache-Control', 'no-store, private');
    }

    public function recheck(Request $request, int $invoice, PayMongoBillingService $payments, BillingService $billing): JsonResponse
    {
        $result = $payments->recheck($request->user(), $invoice);

        return response()->json([...$result, 'billing' => $billing->state($request->user()->tenant)])
            ->header('Cache-Control', 'no-store, private');
    }

    public function recover(Request $request, int $invoice, PayMongoBillingService $payments, BillingService $billing): JsonResponse
    {
        if (array_diff(array_keys($request->all()), ['session_id', '_token']) !== []) {
            throw ValidationException::withMessages(['session_id' => 'Provide only the checkout session ID.']);
        }
        $data = $request->validate(['session_id' => ['required', 'string', 'regex:/^cs_[A-Za-z0-9_]{1,90}$/D']]);
        $result = $payments->recover($request->user(), $invoice, $data['session_id']);

        return response()->json([...$result, 'billing' => $billing->state($request->user()->tenant)])
            ->header('Cache-Control', 'no-store, private');
    }
}
