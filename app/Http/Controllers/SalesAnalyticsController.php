<?php

namespace App\Http\Controllers;

use App\Services\SalesAnalyticsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SalesAnalyticsController extends Controller
{
    public function show(Request $request, SalesAnalyticsService $analytics): JsonResponse
    {
        return response()->json($analytics->report($request->user(), $analytics->validatedFilters($request)))
            ->header('Cache-Control', 'private, no-store');
    }
}
