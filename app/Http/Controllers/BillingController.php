<?php

namespace App\Http\Controllers;

use App\Models\BillingInvoice;
use App\Services\BillingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class BillingController extends Controller
{
    public function show(Request $request, BillingService $billing): JsonResponse
    {
        return response()->json($billing->state($request->user()->tenant))->header('Cache-Control', 'no-store, private');
    }

    public function changePlan(Request $request, BillingService $billing): JsonResponse
    {
        $data = $request->validate(['plan' => ['required', Rule::in(array_keys(config('billing.plans')))], 'billing_cycle' => ['required', Rule::in(['monthly', 'annual'])]]);
        $billing->changePlan($request->user(), $data['plan'], $data['billing_cycle']);

        return $this->show($request, $billing);
    }

    public function checkout(Request $request, BillingService $billing): JsonResponse
    {
        $billing->checkout($request->user());

        return $this->show($request, $billing);
    }

    public function simulatePayment(Request $request, int $invoice, BillingService $billing): JsonResponse
    {
        $data = $request->validate(['idempotency_key' => ['required', 'string', 'min:8', 'max:100']]);
        $billing->simulatePayment($request->user(), $invoice, $data['idempotency_key']);

        return $this->show($request, $billing);
    }

    public function cancel(Request $request, BillingService $billing): JsonResponse
    {
        $billing->cancel($request->user());

        return $this->show($request, $billing);
    }

    public function resume(Request $request, BillingService $billing): JsonResponse
    {
        $billing->resume($request->user());

        return $this->show($request, $billing);
    }

    public function export(Request $request): StreamedResponse
    {
        $tenantId = $request->user()->tenant_id;

        return response()->streamDownload(function () use ($tenantId): void {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, ['Invoice', 'Plan', 'Cycle', 'Amount PHP', 'Status', 'Due', 'Paid', 'Period Start', 'Period End', 'Payment Method', 'Provider', 'Mode', 'Checkout Reference', 'Checkout Session', 'Payment Reference'], ',', '"', '');
            BillingInvoice::where('tenant_id', $tenantId)->with(['latestPaymentAttempt', 'confirmedPaymentAttempt'])->chunkByIdDesc(200, function ($invoices) use ($handle): void {
                foreach ($invoices as $invoice) {
                    $attempt = $invoice->status === 'paid'
                        ? ($invoice->payment_provider === 'paymongo' ? $invoice->confirmedPaymentAttempt : null)
                        : $invoice->latestPaymentAttempt;
                    $provider = $invoice->payment_provider ?? ($invoice->payment_method === 'simulation' ? 'simulation' : ($attempt ? 'paymongo' : null));
                    $mode = $invoice->payment_mode ?? ($invoice->payment_method === 'simulation' ? 'simulation' : $attempt?->mode);
                    fputcsv($handle, [$invoice->number, $invoice->plan, $invoice->billing_cycle, $invoice->amount_cents / 100,
                        $invoice->status, $invoice->due_at->toIso8601String(), $invoice->paid_at?->toIso8601String(),
                        $invoice->period_start?->toIso8601String(), $invoice->period_end?->toIso8601String(), $invoice->payment_method,
                        $provider, $mode, $attempt?->reference, $attempt?->session_id, $attempt?->payment_id], ',', '"', '');
                }
            });
            fclose($handle);
        }, 'posonly-billing-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv', 'Cache-Control' => 'no-store, private']);
    }
}
