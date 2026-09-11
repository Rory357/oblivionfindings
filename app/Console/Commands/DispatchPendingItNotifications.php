<?php

namespace App\Console\Commands;

use App\Domain\It\Services\ItEmailDeliveryService;
use Illuminate\Console\Command;

class DispatchPendingItNotifications extends Command
{
    protected $signature = 'it:dispatch-notifications {--limit=50 : Maximum pending deliveries (1–100)}';

    protected $description = 'Dispatch committed IT notification intents without repeating accepted or uncertain mail';

    public function handle(ItEmailDeliveryService $deliveries): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100]]);
        if ($limit === false) {
            $this->error('Limit must be an integer from 1 to 100.');

            return self::INVALID;
        }
        $count = $deliveries->dispatchPending($limit);
        $this->info("Processed {$count} pending notification intent(s). Check the delivery ledger for channel outcomes.");

        return self::SUCCESS;
    }
}
