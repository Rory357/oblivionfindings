<?php

namespace App\Jobs\Operations;

use App\Models\Timesheet;
use App\Services\Operations\BillingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class GenerateBillingFromTimesheetJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public Timesheet $timesheet
    ) {}

    public function handle(BillingService $service): void
    {
        $entry = $service->generateFromTimesheet($this->timesheet);

        if ($entry) {
            Log::info("Generated billing entry {$entry->id} from timesheet {$this->timesheet->id}.");
        }
    }
}
