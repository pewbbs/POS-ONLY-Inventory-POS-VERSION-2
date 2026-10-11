<?php

namespace App\Jobs;

use App\Models\PayMongoWebhookEvent;
use App\Services\PayMongoBillingService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;
use Throwable;

class ProcessPayMongoWebhook implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 30;

    public array $backoff = [10, 30];

    public function __construct(public int $eventId) {}

    /**
     * Execute the job.
     */
    public function handle(PayMongoBillingService $payments): void
    {
        $receipt = PayMongoWebhookEvent::find($this->eventId);
        if (! $receipt || $receipt->status === 'processed') {
            return;
        }
        try {
            $payments->processSession($receipt->session_id, $receipt->reference);
            $receipt->update(['status' => 'processed', 'processed_at' => now(), 'failure_summary' => null]);
        } catch (Throwable) {
            throw new RuntimeException('PayMongo test payment verification is temporarily unavailable.');
        }
    }

    public function failed(?Throwable $exception): void
    {
        PayMongoWebhookEvent::whereKey($this->eventId)->where('status', '!=', 'processed')->update([
            'status' => 'failed', 'failure_summary' => 'PayMongo test payment verification could not finish. Recheck the invoice payment.',
        ]);
    }
}
