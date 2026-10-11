<?php

use App\Jobs\CheckLowStock;
use App\Models\Tenant;
use App\Services\MonitoringService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inventory:check-low-stock', function (): void {
    Tenant::query()->each(fn (Tenant $tenant) => CheckLowStock::dispatch($tenant->id));
    $this->info('Low-stock checks dispatched for every workspace.');
})->purpose('Refresh persistent low-stock alerts for each workspace');
Schedule::command('inventory:check-low-stock')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('queue:prune-failed --hours=168')->daily()->withoutOverlapping();
Schedule::command('billing:process-renewals')->daily()->withoutOverlapping();
Schedule::call(fn () => app(MonitoringService::class)->heartbeat('scheduler'))
    ->name('monitoring:scheduler-heartbeat')->everyMinute();
Schedule::command('monitoring:prune')->daily()->withoutOverlapping();
