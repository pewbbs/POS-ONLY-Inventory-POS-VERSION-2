<?php

namespace App\Console\Commands;

use App\Services\MonitoringService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('monitoring:check')]
#[Description('Check local application services without changing business records')]
class CheckSystemHealth extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(MonitoringService $monitoring): int
    {
        try {
            $report = $monitoring->systemReport();
            $this->table(['Service', 'Status', 'Details'], array_map(
                fn (array $check): array => [$check['label'], $check['status'], $check['summary']],
                $report['checks'],
            ));
            $this->line('Overall status: '.$report['status']);
            $this->line('This check does not connect external integrations or process sales and payments.');

            return $report['status'] === 'healthy' ? self::SUCCESS : self::FAILURE;
        } catch (Throwable) {
            $this->error('System health could not be read. Check the database and local service configuration.');

            return self::FAILURE;
        }
    }
}
