<?php

namespace App\Http\Controllers;

use App\Http\Resources\CashSessionResource;
use App\Models\CashSession;
use App\Models\Location;
use App\Services\CashSessionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CashSessionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate(['location_id' => ['required', 'integer']]);
        Location::where('tenant_id', $request->user()->tenant_id)->findOrFail($data['location_id']);
        $query = CashSession::where('tenant_id', $request->user()->tenant_id)->where('user_id', $request->user()->id)->where('location_id', $data['location_id'])->with(['cashier', 'adjustments']);
        $current = (clone $query)->where('status', 'open')->first();
        $history = (clone $query)->latest('id')->limit(50)->get();

        return response()->json(['current' => $current ? (new CashSessionResource($current))->resolve($request) : null, 'history' => CashSessionResource::collection($history)->resolve($request)]);
    }

    public function open(Request $request, CashSessionService $sessions): JsonResponse
    {
        $data = $request->validate(['location_id' => ['required', 'integer'], 'opening_float' => ['required', 'numeric', 'min:0', 'max:1000000000', 'decimal:0,2'], 'idempotency_key' => ['required', 'string', 'min:8', 'max:100']]);

        return response()->json((new CashSessionResource($sessions->open($request->user(), $data)))->resolve($request), 201);
    }

    public function adjust(Request $request, int $session, CashSessionService $sessions): JsonResponse
    {
        $data = $request->validate(['direction' => ['required', Rule::in(['in', 'out'])], 'amount' => ['required', 'numeric', 'min:0.01', 'max:1000000000', 'decimal:0,2'], 'reason' => ['required', 'string', 'max:200'], 'idempotency_key' => ['required', 'string', 'min:8', 'max:100']]);

        return response()->json((new CashSessionResource($sessions->adjust($request->user(), $session, $data)))->resolve($request));
    }

    public function close(Request $request, int $session, CashSessionService $sessions): JsonResponse
    {
        $data = $request->validate(['counted_cash' => ['required', 'numeric', 'min:0', 'max:1000000000', 'decimal:0,2'], 'reason' => ['nullable', 'string', 'max:200'], 'idempotency_key' => ['required', 'string', 'min:8', 'max:100']]);

        return response()->json((new CashSessionResource($sessions->close($request->user(), $session, $data)))->resolve($request));
    }
}
