<?php

use App\Http\Middleware\AuditSuccessfulMutation;
use App\Http\Middleware\DeveloperSession;
use App\Http\Middleware\EnsureSubscriptionAccess;
use App\Http\Middleware\EnsureTenantAccess;
use App\Http\Middleware\RequireDeveloper;
use App\Http\Middleware\RequireRole;
use App\Http\Middleware\SeoHeaders;
use App\Services\MonitoringService;
use App\Services\SeoService;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend(DeveloperSession::class);
        $middleware->prepend(SeoHeaders::class);
        $middleware->alias(['role' => RequireRole::class, 'tenant' => EnsureTenantAccess::class, 'audit' => AuditSuccessfulMutation::class, 'subscribed' => EnsureSubscriptionAccess::class, 'developer' => RequireDeveloper::class]);
        $middleware->redirectGuestsTo(fn (Request $request): string => $request->is('developer', 'developer/*', 'api/developer', 'api/developer/*') ? '/developer/login' : '/login');
        $middleware->preventRequestForgery(except: ['webhooks/paymongo', 'webhooks/paymongo/test']);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontReportDuplicates();
        $exceptions->respond(function (Response $response): Response {
            $response = app(SeoService::class)->applyHeaders(request(), $response);
            if (request()->is('developer', 'developer/*', 'api/developer', 'api/developer/*', 'auth/*', 'account/connections', 'account/connections/*')) {
                $response->headers->set('Cache-Control', 'no-store, private');
                $response->headers->set('Referrer-Policy', 'no-referrer');
            }

            return $response;
        });
        $exceptions->report(function (Throwable $exception): void {
            try {
                if (request()->is('developer', 'developer/*', 'api/developer', 'api/developer/*')) {
                    return;
                }
                $tenantId = request()->user()?->tenant_id;
                if ($tenantId !== null) {
                    app(MonitoringService::class)->recordException($exception, $tenantId);
                }
            } catch (Throwable) {
                // A monitoring failure must leave Laravel's normal exception reporting intact.
            }
        });
        $exceptions->render(function (Throwable $exception, Request $request) {
            if ($request->is('api/developer', 'api/developer/*')
                && ! $exception instanceof HttpExceptionInterface
                && ! $exception instanceof AuthenticationException
                && ! $exception instanceof ValidationException) {
                return response()->json(['message' => 'Developer administration is temporarily unavailable. Refresh and try again.'], 503)
                    ->header('Cache-Control', 'no-store, private');
            }
            if ($request->is('api/billing', 'api/billing/*', 'webhooks/paymongo', 'webhooks/paymongo/*') && $exception instanceof HttpExceptionInterface) {
                $status = $exception->getStatusCode();
                $message = $status >= 500 ? 'Billing is temporarily unavailable. Refresh billing or recheck the invoice payment.'
                    : ($status === 404 ? 'Billing record not found.' : ($exception->getMessage() ?: Response::$statusTexts[$status] ?? 'Billing request could not be completed.'));

                return response()->json(['message' => $message], $status, $exception->getHeaders())
                    ->header('Cache-Control', 'no-store, private');
            }
            if (($request->is('api/billing', 'api/billing/*', 'webhooks/paymongo', 'webhooks/paymongo/*'))
                && ! $exception instanceof HttpExceptionInterface
                && ! $exception instanceof AuthenticationException
                && ! $exception instanceof ValidationException) {
                return response()->json(['message' => 'Billing is temporarily unavailable. Refresh billing or recheck the invoice payment.'], 503)
                    ->header('Cache-Control', 'no-store, private');
            }
            if ($request->is('api/monitoring', 'api/monitoring/*')
                && ! $exception instanceof HttpExceptionInterface
                && ! $exception instanceof AuthenticationException
                && ! $exception instanceof ValidationException) {
                return response()->json(['message' => 'System health is temporarily unavailable. Refresh health or ask the system operator to check the local services.'], 503)
                    ->header('Cache-Control', 'no-store, private');
            }

            return null;
        });
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*', 'webhooks/paymongo', 'webhooks/paymongo/*') || $request->expectsJson(),
        );
    })->create();
