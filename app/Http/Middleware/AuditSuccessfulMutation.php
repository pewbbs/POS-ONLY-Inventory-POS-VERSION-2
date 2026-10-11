<?php

namespace App\Http\Middleware;

use App\Models\ActivityLog;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class AuditSuccessfulMutation
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $actions = [
            'POST api/sales' => ['checkout.confirmed', 'sale'],
            'POST api/sales/{sale}/return' => ['return.confirmed', 'sale'],
            'POST api/products' => ['product.created', 'product'],
            'PUT api/products/{product}' => ['product.updated', 'product'],
            'POST api/product-families' => ['product_family.created', 'product_family'],
            'POST api/product-families/{family}/variants' => ['product_family.variants_added', 'product_family'],
            'POST api/products/{product}/adjust' => ['inventory.adjusted', 'product'],
            'POST api/stock/adjust' => ['inventory.adjusted', 'product'],
            'POST api/stock/transfer' => ['inventory.transferred', 'product'],
            'POST api/transfers' => ['inventory.transferred', 'product'],
            'POST api/purchase-orders' => ['purchase.created', 'purchase_order'],
            'POST api/purchase-orders/{order}/receive' => ['delivery.confirmed', 'purchase_order'],
            'POST api/customers' => ['customer.created', 'customer'],
            'POST api/suppliers' => ['supplier.created', 'supplier'],
            'POST api/locations' => ['location.created', 'location'],
            'POST api/team' => ['member.created', 'user'],
            'PATCH api/team/{member}' => ['member.updated', 'user'],
            'PUT api/settings' => ['settings.updated', 'tenant'],
            'POST api/purchase-orders/{order}/approve' => ['purchase.approved', 'purchase_order'],
            'POST api/purchase-orders/{order}/cancel' => ['purchase.canceled', 'purchase_order'],
            'POST api/reorder-rules' => ['replenishment.configured', 'reorder_rule'],
            'PATCH api/reorder-rules/{rule}' => ['replenishment.configured', 'reorder_rule'],
            'POST api/reorder-rules/generate' => ['replenishment.generated', 'purchase_order'],
            'POST api/billing/change-plan' => ['subscription.plan_changed', 'subscription'],
            'POST api/billing/checkout' => ['subscription.invoice_created', 'subscription'],
            'POST api/billing/cancel' => ['subscription.canceled', 'subscription'],
            'POST api/billing/resume' => ['subscription.resumed', 'subscription'],
            'POST api/billing/invoices/{invoice}/simulate-payment' => ['subscription.payment_simulated', 'billing_invoice'],
            'POST api/billing/invoices/{invoice}/paymongo-checkout' => ['subscription.test_checkout_requested', 'billing_invoice'],
            'POST api/billing/invoices/{invoice}/paymongo-recheck' => ['subscription.test_payment_checked', 'billing_invoice'],
            'POST api/billing/invoices/{invoice}/paymongo-recover' => ['subscription.test_checkout_recovered', 'billing_invoice'],
            'POST api/cash-sessions/open' => ['register.opened', 'cash_session'],
            'POST api/cash-sessions/{session}/adjustments' => ['register.cash_recorded', 'cash_session'],
            'POST api/cash-sessions/{session}/close' => ['register.closed', 'cash_session'],
            'POST api/commerce/channels' => ['channel.created', 'commerce_channel'],
            'PATCH api/commerce/channels/{channel}' => ['channel.updated', 'commerce_channel'],
            'POST api/commerce/channels/{channel}/pause' => ['channel.paused', 'commerce_channel'],
            'POST api/commerce/channels/{channel}/resume' => ['channel.resumed', 'commerce_channel'],
            'POST api/commerce/channels/{channel}/sync' => ['channel.sync_recorded', 'commerce_channel'],
            'POST api/commerce/channels/{channel}/orders' => ['channel.order_reserved', 'channel_order'],
            'POST api/commerce/orders/{order}/import' => ['channel.order_imported', 'channel_order'],
            'POST api/commerce/orders/{order}/cancel' => ['channel.order_canceled', 'channel_order'],
        ];
        $action = $actions[$request->method().' '.$request->route()?->uri()] ?? null;
        if (! $action || ! $request->user() || $response->getStatusCode() < 200 || $response->getStatusCode() >= 300 || $request->attributes->get('stock_mutation_replayed') === true) {
            return $response;
        }
        try {
            $resourceId = $action[1] === 'tenant' ? $request->user()->tenant_id : null;
            if ($response instanceof JsonResponse) {
                $data = $response->getData(true);
                $paymentMode = $data['attempt']['mode'] ?? $data['billing']['payment_config']['mode'] ?? null;
                if ($paymentMode === 'live' && str_starts_with($action[0], 'subscription.test_')) {
                    $action[0] = str_replace('subscription.test_', 'subscription.', $action[0]);
                }
                $resourceId = isset($data['id']) && is_numeric($data['id']) ? (int) $data['id'] : $resourceId;
                if ($action[1] === 'subscription') {
                    $resourceId = $data['subscription']['id'] ?? null;
                }
                if ($action[1] === 'billing_invoice') {
                    $resourceId = (int) $request->route('invoice');
                }
                if ($action[1] === 'commerce_channel') {
                    $resourceId = $request->route('channel') ?? ($data['channel']['id'] ?? $resourceId);
                }
                if ($action[1] === 'channel_order') {
                    $resourceId = $request->route('order') ?? ($data['order']['id'] ?? $resourceId);
                }
                if ($action[1] === 'product_family') {
                    $resourceId = $request->route('family') ?? $resourceId;
                }
            }
            ActivityLog::create([
                'tenant_id' => $request->user()->tenant_id,
                'actor_id' => $request->user()->id,
                'action' => $action[0],
                'resource_type' => $action[1],
                'resource_id' => $resourceId,
                'created_at' => now(),
            ]);
        } catch (Throwable $exception) {
            report($exception);
        }

        return $response;
    }
}
