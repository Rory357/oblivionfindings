<?php

namespace App\Domain\Hr\Jobs;

use App\Domain\Hr\Models\HrLeaveBalance;
use App\Domain\Hr\Services\LeaveService;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProcessLeaveBalanceAccrualJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public ?int $tenantId = null
    ) {}

    public function handle(LeaveService $leaveService): void
    {
        $tenantIds = $this->tenantId
            ? collect([$this->tenantId])
            : User::select('tenant_id')
                ->whereNotNull('tenant_id')
                ->distinct()
                ->pluck('tenant_id');

        foreach ($tenantIds as $tenantId) {
            $processed = $leaveService->processAccruals($tenantId);

            Log::info("Leave balance accrual processed for tenant {$tenantId}: {$processed} employees accrued.");
        }
    }
}
