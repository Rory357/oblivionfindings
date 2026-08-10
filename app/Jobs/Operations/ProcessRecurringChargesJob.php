<?php

namespace App\Jobs\Operations;

use App\Services\Operations\RecurringChargeService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessRecurringChargesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(RecurringChargeService $service): void
    {
        $count = $service->processDueCharges();
        Log::info("Processed {$count} application recurring charges.");
    }
}
