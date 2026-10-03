<?php

namespace App\Console\Commands;

use App\Services\Medication\Support\SupportReviewDelivery;
use Illuminate\Console\Command;

class DeliverMedicationSupportReviews extends Command
{
    protected $signature = 'emar:support-review-delivery';

    protected $description = 'Retry durable medication support reassessment triggers';

    public function handle(SupportReviewDelivery $delivery): int
    {
        $this->info($delivery->sweep().' support triggers delivered.');

        return self::SUCCESS;
    }
}
