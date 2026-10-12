<?php

namespace App\Providers;

use App\Models\User;
use App\Services\MonitoringService;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\Looping;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('mobile-login', fn (Request $request): array => [
            Limit::perMinute(6)->by('mobile-login-ip:'.$request->ip()),
            Limit::perHour(20)->by('mobile-login-email:'.hash('sha256', Str::lower(trim(is_string($request->input('email')) ? $request->input('email') : '')))),
        ]);
        RateLimiter::for('mobile-api', fn (Request $request): Limit => Limit::perMinute(120)->by('mobile-api:'.($request->user()?->id ?? $request->ip())));
        Queue::looping(function (Looping $event): void {
            app(MonitoringService::class)->heartbeat('worker', $event->connectionName, $event->queue);
        });
        ResetPassword::createUrlUsing(fn (User $user, string $token): string => rtrim(config('app.url'), '/').route('password.reset', [
            'token' => $token,
            'email' => $user->getEmailForPasswordReset(),
        ], false));
    }
}
