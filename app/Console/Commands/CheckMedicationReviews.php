<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Services\MedicationAlertService;
use Illuminate\Console\Command;

class CheckMedicationReviews extends Command
{
    protected $signature = 'emar:check-medication-reviews';

    protected $description = 'Generate medication chart review, medicine review, and INR due alerts';

    public function handle(MedicationAlertService $alerts): int
    {
        $count = 0;

        Client::query()
            ->whereHas('medications')
            ->chunkById(200, function ($clients) use ($alerts, &$count) {
                foreach ($clients as $client) {
                    $alerts->generateClientAlerts($client);
                    $count++;
                }
            });

        $this->info("Generated medication review alerts for {$count} clients.");

        return self::SUCCESS;
    }
}
