<?php

namespace App\Console\Commands;

use App\Services\ConsentRequestService;
use Illuminate\Console\Command;

/**
 * Expires pending consent requests past their expires_at. Run hourly from
 * the scheduler.
 */
class ExpireStaleConsentRequests extends Command
{
    protected $signature = 'consent-requests:expire-stale';

    protected $description = 'Expire pending consent requests past their expires_at.';

    public function handle(ConsentRequestService $service): int
    {
        $count = $service->expireStale();

        $this->info(sprintf('Expired %d consent request(s).', $count));

        return self::SUCCESS;
    }
}
