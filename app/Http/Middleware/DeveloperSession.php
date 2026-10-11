<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Session\SessionManager;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class DeveloperSession
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->is('developer', 'developer/*', 'api/developer', 'api/developer/*')) {
            Auth::shouldUse('web');

            return $next($request);
        }

        $settings = config('session');
        $companyManager = app('session');
        $companyStore = app('session.store');
        config(['session.driver' => 'database', 'session.table' => 'developer_sessions',
            'session.connection' => null, 'session.cookie' => 'stockroom_developer_session',
            'session.path' => '/', 'session.http_only' => true, 'session.same_site' => 'strict']);
        if (! app()->bound('session.developer.manager')) {
            app()->instance('session.developer.manager', new SessionManager(app()));
        }
        $developerManager = app('session.developer.manager');
        app()->instance('session', $developerManager);
        app()->instance('session.store', $developerManager->driver('database'));
        app()->forgetInstance(StartSession::class);
        Auth::shouldUse('developer');
        try {
            $response = $next($request);
            $response->headers->set('Cache-Control', 'no-store, private');
            $response->headers->set('Referrer-Policy', 'no-referrer');

            return $response;
        } finally {
            config(['session' => $settings]);
            app()->instance('session', $companyManager);
            app()->instance('session.store', $companyStore);
            app()->forgetInstance(StartSession::class);
            Auth::shouldUse('web');
        }
    }
}
