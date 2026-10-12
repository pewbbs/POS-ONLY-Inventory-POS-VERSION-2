<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

class RequireMobileToken
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $authenticated = $request->user();
        $token = $authenticated?->currentAccessToken();
        abort_unless($authenticated instanceof User && $token instanceof PersonalAccessToken && $request->bearerToken(), 401, 'A valid device token is required.');
        $user = $authenticated->fresh('tenant');
        abort_unless($user && $user->tenant, 403, 'Your account has no active workspace.');
        if (! $user->is_active || ! array_key_exists($user->role, config('mobile-api.abilities'))) {
            $user->tokens()->delete();
            abort(403, 'Your workspace access is paused. Ask an administrator to reactivate your account.');
        }
        abort_if($user->tenant->is_demo && ! app()->environment(['local', 'testing']), 403, 'Demo workspaces are unavailable in this environment.');
        $user->withAccessToken($token);
        Auth::guard('sanctum')->setUser($user);
        $request->setUserResolver(fn (?string $guard = null): User => $user);

        return $next($request);
    }
}
