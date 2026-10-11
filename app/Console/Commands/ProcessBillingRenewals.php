<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\BillingService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('billing:process-renewals')]
#[Description('Update subscription access and create renewal invoices without charging money')]
class ProcessBillingRenewals extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(BillingService $billing): int
    {
        Tenant::query()->each(fn (Tenant $tenant) => $billing->process($tenant));
        $this->info('Subscription periods checked. Due invoices prepared; no money was collected.');

        return self::SUCCESS;
    }
}
