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
        try {
            $entries = $service->generateFromTimesheet($this->timesheet);

            if ($entries->isNotEmpty()) {
                $ids = $entries->pluck('id')->implode(', ');
                Log::info("Generated billing entries [{$ids}] from timesheet {$this->timesheet->id}.");
            }
        } catch (\Throwable $e) {
            \Log::error('GenerateBillingFromTimesheetJob failed: ' . $e->getMessage(), ['exception' => $e]);
            throw $e;
        }
    }
}
