<?php

namespace App\Jobs;

use App\Services\MonitoringService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class MonitoringQueueProbe implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 15;

    public function __construct(public int $tenantId, public int $eventId) {}

    /**
     * Execute the job.
     */
    public function handle(MonitoringService $monitoring): void
    {
        $monitoring->completeProbe($this->tenantId, $this->eventId);
    }

    public function failed(?Throwable $exception): void
    {
        app(MonitoringService::class)->failProbe($this->tenantId, $this->eventId);
    }
}
