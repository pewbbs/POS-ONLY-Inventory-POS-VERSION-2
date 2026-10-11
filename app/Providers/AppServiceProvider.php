<?php

namespace App\Providers;

use App\Models\User;
use App\Services\MonitoringService;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Queue\Events\Looping;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\ServiceProvider;

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
        Queue::looping(function (Looping $event): void {
            app(MonitoringService::class)->heartbeat('worker', $event->connectionName, $event->queue);
        });
        ResetPassword::createUrlUsing(fn (User $user, string $token): string => rtrim(config('app.url'), '/').route('password.reset', [
            'token' => $token,
            'email' => $user->getEmailForPasswordReset(),
        ], false));
    }
}
