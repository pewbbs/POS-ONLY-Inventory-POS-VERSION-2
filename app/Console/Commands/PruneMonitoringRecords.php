<?php

namespace App\Console\Commands;

use App\Services\MonitoringService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('monitoring:prune')]
#[Description('Remove monitoring events older than the configured retention period')]
class PruneMonitoringRecords extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(MonitoringService $monitoring): int
    {
        try {
            $count = $monitoring->prune();
            $this->info('Removed '.$count.' expired monitoring records. Business records were kept.');

            return self::SUCCESS;
        } catch (Throwable) {
            $this->error('Monitoring retention could not run. Check the database and monitoring tables.');

            return self::FAILURE;
        }
    }
}
