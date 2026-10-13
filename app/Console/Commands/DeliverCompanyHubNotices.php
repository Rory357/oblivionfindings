<?php

namespace App\Console\Commands;

use App\Domain\CompanyHub\Services\HubNotices;
use Illuminate\Console\Command;

class DeliverCompanyHubNotices extends Command
{
    protected $signature = 'company-hub:deliver-notices';

    protected $description = 'Deliver permission-aware company news and page review notices to the in-app bell';

    public function handle(HubNotices $notices): int
    {
        $this->info($notices->deliver().' hub notices delivered.');

        return self::SUCCESS;
    }
}
