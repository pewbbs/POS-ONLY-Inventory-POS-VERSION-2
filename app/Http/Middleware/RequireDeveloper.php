<?php

namespace App\Http\Middleware;

use App\Models\PlatformAdmin;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class RequireDeveloper
{
    public function handle(Request $request, Closure $next): Response
    {
        $actor = $request->user('developer');
        $account = $actor instanceof PlatformAdmin ? PlatformAdmin::find($actor->id) : null;
        if (! $account?->is_active || ! $account->password) {
            Auth::guard('developer')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
            abort(403, 'Developer access is unavailable.');
        }
        Auth::guard('developer')->setUser($account);

        return $next($request);
    }
}
