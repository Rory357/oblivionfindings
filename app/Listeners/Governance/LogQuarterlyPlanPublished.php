<?php

namespace App\Listeners\Governance;

use App\Domain\Roadmap\Events\QuarterlyPlanPublished;
use Illuminate\Support\Facades\Log;

class LogQuarterlyPlanPublished
{
    public function handle(QuarterlyPlanPublished $event): void
    {
        Log::channel('daily')->info('Quarterly plan published', [
            'plan_id' => $event->plan->id ?? null,
        ]);
    }
}
