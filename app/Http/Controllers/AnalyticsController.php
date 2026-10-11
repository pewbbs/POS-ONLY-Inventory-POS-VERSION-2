<?php

namespace App\Http\Controllers;

use App\Models\AnalyticsEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AnalyticsController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'event' => ['required', Rule::in(['landing_view', 'demo_open', 'pricing_view', 'trial_click'])],
            'variant' => ['required', Rule::in(['a', 'b'])],
        ]);
        AnalyticsEvent::create($data);

        return response()->json(['recorded' => true], 201);
    }
}
