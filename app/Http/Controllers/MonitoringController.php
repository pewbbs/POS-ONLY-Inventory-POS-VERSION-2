<?php

namespace App\Http\Controllers;

use App\Services\MonitoringService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MonitoringController extends Controller
{
    public function show(Request $request, MonitoringService $monitoring): JsonResponse
    {
        return response()->json($monitoring->snapshot($request->user()))->header('Cache-Control', 'no-store, private');
    }

    public function probe(Request $request, MonitoringService $monitoring): JsonResponse
    {
        return response()->json($monitoring->probe($request->user()))->header('Cache-Control', 'no-store, private');
    }

    public function acknowledge(Request $request, int $incident, MonitoringService $monitoring): JsonResponse
    {
        return response()->json($monitoring->acknowledge($request->user(), $incident))->header('Cache-Control', 'no-store, private');
    }
}
