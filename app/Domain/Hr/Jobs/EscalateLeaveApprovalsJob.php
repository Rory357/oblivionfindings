<?php

namespace App\Domain\Hr\Jobs;

use App\Domain\Hr\Services\LeaveService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class EscalateLeaveApprovalsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(LeaveService $leaveService): void
    {
        $count = $leaveService->escalatePendingApprovals();
        Log::info('Application leave approval escalation processed.', ['escalated' => $count]);
    }
}
