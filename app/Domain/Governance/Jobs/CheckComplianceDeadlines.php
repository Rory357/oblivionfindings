<?php

namespace App\Domain\Governance\Jobs;

use App\Domain\Governance\Services\ComplianceEngineService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class CheckComplianceDeadlines implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(ComplianceEngineService $service): void
    {
        $count = $service->processDueReminders();
        
        \Log::info("Processed {$count} compliance reminders");
    }
}
