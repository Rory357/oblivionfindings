<?php

namespace App\Domain\Hr\Jobs;

use App\Domain\Hr\Services\LeaveService;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class EscalateLeaveApprovalsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public ?int $tenantId = null
    ) {}

    public function handle(LeaveService $leaveService): void
    {
        if ($this->tenantId !== null) {
            $count = $leaveService->escalatePendingApprovals($this->tenantId);
            Log::info("Leave approval escalation processed for tenant {$this->tenantId}.", ['escalated' => $count]);
            return;
        }

        $tenantIds = Schema::hasColumn('users', 'tenant_id')
            ? User::query()
                ->select('tenant_id')
                ->whereNotNull('tenant_id')
                ->distinct()
                ->pluck('tenant_id')
            : collect([null]);

        foreach ($tenantIds as $tenantId) {
            $count = $leaveService->escalatePendingApprovals($tenantId !== null ? (int) $tenantId : null);
            Log::info('Leave approval escalation processed.', [
                'tenant_id' => $tenantId,
                'escalated' => $count,
            ]);
        }
    }
}

