<?php

namespace App\Console\Commands;

use App\Domain\CompanyHub\Services\HubEventScheduling;
use App\Domain\CompanyHub\Services\HubNotices;
use Illuminate\Console\Command;

class DeliverCompanyHubNotices extends Command
{
    protected $signature = 'company-hub:deliver-notices';

    protected $description = 'Release due Hub events and deliver permission-aware company news and page review notices';

    public function handle(HubNotices $notices, HubEventScheduling $events): int
    {
        $result = $events->publishDue();
        $this->info(sprintf('Hub events: %d published, %d blocked, %d retrying.', $result['published'], $result['blocked'], $result['failed']));
        $this->info($notices->deliver().' hub notices delivered.');

        return $result['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
