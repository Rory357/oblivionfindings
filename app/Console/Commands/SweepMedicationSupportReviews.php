<?php

namespace App\Console\Commands;

use App\Services\Medication\Support\SupportReviewSources;
use Illuminate\Console\Command;

final class SweepMedicationSupportReviews extends Command
{
    protected $signature = 'emar:support-reviews';

    protected $description = 'Create idempotent reassessment follow-ups when medication support review dates have passed';

    public function handle(SupportReviewSources $sources): int
    {
        $this->info($sources->sweep().' support review records checked.');

        return self::SUCCESS;
    }
}
