<?php

namespace App\Jobs;

use App\Models\LowStockAlert;
use App\Models\Product;
use App\Models\Tenant;
use App\Services\MonitoringService;
use App\Services\ReplenishmentService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class CheckLowStock implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $uniqueFor = 300;

    public int $tries = 3;

    public int $timeout = 30;

    public array $backoff = [5, 30, 60];

    public function __construct(public int $tenantId) {}

    public function uniqueId(): string
    {
        return (string) $this->tenantId;
    }

    public function failed(?Throwable $exception): void
    {
        app(MonitoringService::class)->recordJobFailure(self::class, $this->tenantId);
    }

    public function handle(?ReplenishmentService $replenishment = null): void
    {
        $tenant = Tenant::find($this->tenantId);
        if ($tenant) {
            ($replenishment ?? app(ReplenishmentService::class))->generateForTenant($this->tenantId);
        }
        if (! $tenant || ! ($tenant->settings['low_stock_alerts'] ?? true)) {
            LowStockAlert::where('tenant_id', $this->tenantId)->update(['active' => false]);

            return;
        }
        Product::where('tenant_id', $this->tenantId)->with('stocks')->chunkById(100, function ($products): void {
            foreach ($products as $product) {
                foreach ($product->stocks as $stock) {
                    $available = max(0, $stock->quantity - $stock->reserved_quantity);
                    LowStockAlert::updateOrCreate(['product_id' => $product->id, 'location_id' => $stock->location_id], ['tenant_id' => $this->tenantId, 'quantity' => $available, 'reorder_level' => $product->reorder_level, 'active' => $available <= $product->reorder_level]);
                }
            }
        });
    }
}
