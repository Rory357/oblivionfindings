<?php

namespace App\Domain\Hr\Jobs;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CalculateWellbeingIndicatorsJob implements ShouldQueue
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
            $this->calculateForTenant($tenantId);
        }
    }

    private function calculateForTenant(int $tenantId): void
    {
        $processed = 0;

        // TODO: For each active employee in this tenant, calculate:
        //
        // 1. Overtime hours — Sum hours worked beyond contracted hours in the
        //    current rolling period (e.g. last 4 weeks). Flag if > threshold.
        //
        // 2. Consecutive working days — Count streak of consecutive days
        //    worked without a rest day. Flag if > config('hr.max_consecutive_days', 6).
        //
        // 3. Sick leave trends — Count sick leave occurrences in rolling 12 months
        //    and calculate Bradford Factor score:
        //    Bradford = S^2 * D  (S = number of spells, D = total days absent)
        //
        // 4. Upsert into hr_wellbeing_indicators table:
        //    DB::table('hr_wellbeing_indicators')->updateOrInsert(
        //        ['user_id' => $userId, 'tenant_id' => $tenantId],
        //        [
        //            'overtime_hours_4w'       => $overtimeHours,
        //            'consecutive_days_worked' => $consecutiveDays,
        //            'sick_leave_spells_12m'   => $sickSpells,
        //            'sick_leave_days_12m'     => $sickDays,
        //            'bradford_factor'         => $bradfordFactor,
        //            'flags'                   => json_encode($flags),
        //            'calculated_at'           => now(),
        //        ]
        //    );
        //
        // $processed++;

        Log::info("Wellbeing indicators calculated for tenant {$tenantId}: {$processed} employees processed.");
    }
}
