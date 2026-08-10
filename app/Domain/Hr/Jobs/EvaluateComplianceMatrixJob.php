<?php

namespace App\Domain\Hr\Jobs;

use App\Domain\Hr\Services\ComplianceMatrixService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class EvaluateComplianceMatrixJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(ComplianceMatrixService $service): void
    {
        $count = $service->evaluateAllStaff();

        Log::info('Application compliance matrix evaluated.', [
            'staff_processed' => $count,
        ]);
    }
}
