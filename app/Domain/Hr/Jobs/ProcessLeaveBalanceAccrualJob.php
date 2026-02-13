<?php

namespace App\Domain\Hr\Jobs;

use App\Domain\Hr\Models\HrLeaveBalance;
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

    public function handle(): void
    {
        $tenantIds = $this->tenantId
            ? collect([$this->tenantId])
            : User::select('tenant_id')
                ->whereNotNull('tenant_id')
                ->distinct()
                ->pluck('tenant_id');

        foreach ($tenantIds as $tenantId) {
            $this->accrueForTenant($tenantId);
        }
    }

    private function accrueForTenant(int $tenantId): void
    {
        $accrued = 0;

        // TODO: For each active employee with a leave balance record:
        //
        // 1. Determine employment type (full-time, part-time, casual, contractor).
        //    Different types accrue at different rates defined in config('hr.leave_accrual_rates').
        //
        // 2. Calculate monthly accrual:
        //    - Full-time:  annual_entitlement / 12
        //    - Part-time:  (annual_entitlement / 12) * (contracted_hours / full_time_hours)
        //    - Casual:     Accrual loaded onto hourly rate (no balance accrual)
        //    - Contractor: No leave accrual
        //
        // 3. For each leave type (annual, sick, personal, long_service):
        //    HrLeaveBalance::where('user_id', $userId)
        //        ->where('tenant_id', $tenantId)
        //        ->where('leave_type', $leaveType)
        //        ->where('year', now()->year)
        //        ->increment('accrued', $monthlyAccrual);
        //
        // 4. Handle carry-over caps if accrual crosses the year boundary.
        //
        // $accrued++;

        Log::info("Leave balance accrual processed for tenant {$tenantId}: {$accrued} employees accrued.");
    }
}
