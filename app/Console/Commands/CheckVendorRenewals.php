<?php

namespace App\Console\Commands;

use App\Services\Sites\VendorAgreements;
use Illuminate\Console\Command;

class CheckVendorRenewals extends Command
{
    protected $signature = 'vendors:check-renewals';

    protected $description = 'Make due vendor owner follow-ups actionable without duplicate reminders';

    public function handle(VendorAgreements $agreements): int
    {
        $this->info('Owner follow-ups made due: '.$agreements->due());

        return self::SUCCESS;
    }
}
