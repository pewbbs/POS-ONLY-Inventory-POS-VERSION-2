<?php

namespace App\Http\Controllers;

use App\Services\CommerceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CommerceController extends Controller
{
    private function requireSimulation(CommerceService $commerce): void
    {
        abort_unless($commerce->simulationEnabled(), 404);
    }

    public function index(Request $request, CommerceService $commerce): JsonResponse
    {
        return response()->json($commerce->state($request->user()));
    }

    public function store(Request $request, CommerceService $commerce): JsonResponse
    {
        $this->requireSimulation($commerce);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'], 'kind' => ['required', Rule::in(['web_store', 'marketplace', 'social_shop'])],
            'location_id' => ['required', 'integer'], 'idempotency_key' => ['required', 'string', 'min:8', 'max:100'],
        ]);

        return response()->json($commerce->serializeChannel($commerce->createChannel($request->user(), $data)), 201);
    }

    public function update(Request $request, int $channel, CommerceService $commerce): JsonResponse
    {
        $this->requireSimulation($commerce);
        $data = $request->validate(['name' => ['sometimes', 'required', 'string', 'max:100'], 'simulate_failure' => ['sometimes', 'required', 'boolean']]);

        return response()->json($commerce->serializeChannel($commerce->updateChannel($request->user(), $channel, $data)));
    }

    public function pause(Request $request, int $channel, CommerceService $commerce): JsonResponse
    {
        return response()->json($commerce->serializeChannel($commerce->setEnabled($request->user(), $channel, false)));
    }

    public function resume(Request $request, int $channel, CommerceService $commerce): JsonResponse
    {
        $this->requireSimulation($commerce);

        return response()->json($commerce->serializeChannel($commerce->setEnabled($request->user(), $channel, true)));
    }

    public function sync(Request $request, int $channel, CommerceService $commerce): JsonResponse
    {
        $this->requireSimulation($commerce);
        $data = $request->validate(['idempotency_key' => ['required', 'string', 'min:8', 'max:100']]);

        return response()->json($commerce->sync($request->user(), $channel, $data['idempotency_key']));
    }

    public function order(Request $request, int $channel, CommerceService $commerce): JsonResponse
    {
        $this->requireSimulation($commerce);
        $data = $request->validate([
            'external_reference' => ['required', 'string', 'max:100'], 'idempotency_key' => ['required', 'string', 'min:8', 'max:100'],
            'items' => ['required', 'array', 'min:1', 'max:200'], 'items.*.sku' => ['required', 'string', 'max:255', 'distinct'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:100000'],
        ]);

        return response()->json($commerce->serializeOrder($commerce->createOrder($request->user(), $channel, $data)), 201);
    }

    public function import(Request $request, int $order, CommerceService $commerce): JsonResponse
    {
        $this->requireSimulation($commerce);

        return response()->json($commerce->serializeOrder($commerce->importOrder($request->user(), $order)));
    }

    public function cancel(Request $request, int $order, CommerceService $commerce): JsonResponse
    {
        return response()->json($commerce->serializeOrder($commerce->cancelOrder($request->user(), $order)));
    }
}
