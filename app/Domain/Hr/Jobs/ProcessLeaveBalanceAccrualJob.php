<?php

namespace App\Domain\Hr\Jobs;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrLeaveBalance;
use App\Domain\Hr\Models\HrLeaveBalanceLedger;
use App\Domain\Hr\Services\HrCurrentStaffService;
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

    public function handle(HrCurrentStaffService $currentStaff): void
    {
        try {
            $this->accrueApplication($currentStaff);
        } catch (\Throwable $e) {
            Log::error('ProcessLeaveBalanceAccrualJob failed: '.$e->getMessage(), ['exception' => $e]);
            throw $e;
        }
    }

    private function accrueApplication(HrCurrentStaffService $currentStaff): void
    {
        DB::transaction(function () use ($currentStaff) {
            $accrued = 0;
            $year = now()->year;
            $accrualPeriod = now()->format('Y-m');
            $fullTimeHours = (float) config('hr.leave.full_time_hours_per_week', 40);
            $accrualTypes = (array) config('hr.leave.accrual_types', ['annual', 'sick']);
            $defaultEntitlements = (array) config('hr.leave.default_entitlements', [
                'annual' => 152,
                'sick' => 80,
            ]);

            $profiles = HrEmployeeProfile::query()
                ->whereIn('user_id', $currentStaff->currentUsersQuery()->select('users.id'))
                ->get(['user_id', 'employment_type', 'hours_per_week']);

            foreach ($profiles as $profile) {
                $employmentType = (string) ($profile->employment_type ?? 'full_time');
                if (in_array($employmentType, ['casual', 'contractor'], true)) {
                    continue;
                }

                $partTimeFactor = $employmentType === 'part_time'
                    ? min(1, max(0, ((float) ($profile->hours_per_week ?: $fullTimeHours)) / $fullTimeHours))
                    : 1.0;

                foreach ($accrualTypes as $leaveType) {
                    $annualEntitlement = (float) ($defaultEntitlements[$leaveType] ?? 0);
                    if ($annualEntitlement <= 0) {
                        continue;
                    }

                    $monthlyAccrual = round(($annualEntitlement / 12) * $partTimeFactor, 2);
                    if ($monthlyAccrual <= 0) {
                        continue;
                    }

                    $alreadyAccrued = HrLeaveBalanceLedger::query()
                        ->where('user_id', $profile->user_id)
                        ->where('leave_type', $leaveType)
                        ->where('year', $year)
                        ->where('entry_type', 'accrual')
                        ->where('notes', 'like', "%period={$accrualPeriod}%")
                        ->exists();

                    if ($alreadyAccrued) {
                        continue;
                    }

                    $balance = HrLeaveBalance::query()->firstOrCreate(
                        [
                            'user_id' => $profile->user_id,
                            'leave_type' => $leaveType,
                            'year' => $year,
                        ],
                        [
                            'source' => 'system',
                            'balance_hours' => 0,
                            'accrued_hours' => 0,
                            'used_hours' => 0,
                            'pending_hours' => 0,
                        ]
                    );

                    $beforeBalance = (float) $balance->balance_hours;
                    $beforeUsed = (float) $balance->used_hours;
                    $beforePending = (float) $balance->pending_hours;

                    $balance->balance_hours = round($beforeBalance + $monthlyAccrual, 2);
                    $balance->accrued_hours = round((float) $balance->accrued_hours + $monthlyAccrual, 2);
                    $balance->last_synced_at = now();
                    $balance->save();

                    HrLeaveBalanceLedger::create([
                        'user_id' => $profile->user_id,
                        'leave_type' => $leaveType,
                        'year' => $year,
                        'entry_type' => 'accrual',
                        'hours_delta' => $monthlyAccrual,
                        'balance_hours_before' => $beforeBalance,
                        'balance_hours_after' => (float) $balance->balance_hours,
                        'used_hours_before' => $beforeUsed,
                        'used_hours_after' => (float) $balance->used_hours,
                        'pending_hours_before' => $beforePending,
                        'pending_hours_after' => (float) $balance->pending_hours,
                        'notes' => "Automated leave accrual period={$accrualPeriod}",
                    ]);

                    $accrued++;
                }
            }

            Log::info('Application leave balance accrual processed.', ['employees_accrued' => $accrued]);
        });
    }
}
