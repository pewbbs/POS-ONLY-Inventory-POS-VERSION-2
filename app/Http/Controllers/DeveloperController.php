<?php

namespace App\Http\Controllers;

use App\Services\DeveloperPlatformService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class DeveloperController extends Controller
{
    public function show(Request $request, string $section = 'overview'): Response
    {
        abort_unless(in_array($section, ['overview', 'companies', 'billing', 'content', 'activity'], true), 404);

        return response()->view('developer', ['section' => $section]);
    }

    public function bootstrap(Request $request, DeveloperPlatformService $platform): JsonResponse
    {
        return response()->json($platform->bootstrap($request->user('developer'), $platform->filters($request)));
    }

    public function company(Request $request, int $tenant, DeveloperPlatformService $platform): JsonResponse
    {
        return response()->json($platform->detail($request->user('developer'), $tenant, $platform->filters($request)));
    }

    public function mutate(Request $request, int $tenant, string $action, DeveloperPlatformService $platform): JsonResponse
    {
        return response()->json($platform->mutate($request->user('developer'), $tenant, $action, $request->all()));
    }
}
