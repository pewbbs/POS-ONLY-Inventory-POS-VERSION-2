<?php

namespace App\Http\Middleware;

use App\Services\BillingService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureSubscriptionAccess
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $summary = app(BillingService::class)->summary($request->user()->tenant);
        if (! $summary['can_write']) {
            if ($summary['operations_paused']) {
                return response()->json(['message' => 'Company operations are paused by the platform administrator. Records, reports and reconciliation remain available.', 'code' => 'platform_paused'], 423);
            }

            return response()->json(['message' => 'Subscription access has ended. An administrator can resume the subscription in Billing. Your records, exports and returns remain available.', 'code' => 'subscription_required'], 423);
        }

        return $next($request);
    }
}
