<?php

namespace App\Http\Controllers;

use App\Services\PayMongoWebhookService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PayMongoWebhookController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(Request $request, PayMongoWebhookService $webhooks): JsonResponse
    {
        abort_if($request->is('webhooks/paymongo/test') && config('paymongo.mode') !== 'test', 404);
        $status = $webhooks->receive($request->getContent(), $request->header('Paymongo-Signature'));

        return response()->json(['status' => $status])->header('Cache-Control', 'no-store, private');
    }
}
