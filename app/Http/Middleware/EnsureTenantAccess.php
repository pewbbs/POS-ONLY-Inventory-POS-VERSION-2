<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureTenantAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user && ! $user->is_active) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
            abort(403, 'Your workspace access is paused. Ask an administrator to reactivate your account.');
        }
        $tenant = $user?->tenant;
        abort_unless($tenant, 403, 'Your account has no active workspace.');
        abort_if($tenant->is_demo && ! app()->environment(['local', 'testing']), 403, 'Demo workspaces are unavailable in this environment.');

        return $next($request);
    }
}
