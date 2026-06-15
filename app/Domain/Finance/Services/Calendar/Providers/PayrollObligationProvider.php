<?php

namespace App\Domain\Finance\Services\Calendar\Providers;

use App\Domain\Finance\Services\Calendar\FinanceCalendarItem;
use App\Domain\Hr\Models\HrPayrollRun;
use Illuminate\Support\Carbon;

/**
 * Surfaces payroll runs onto the finance calendar on their pay-period end date —
 * the money obligation to disburse net pay. Reads the SAME {@see HrPayrollRun}
 * rows the HR payroll hub renders (tenant resolves to the org id). Runs whose net
 * pay is disbursed are marked processed; others are scheduled.
 */
class PayrollObligationProvider extends ObligationProvider
{
    public function sourceKey(): string
    {
        return 'payroll';
    }

    public function obligations(?int $orgId, Carbon $start, Carbon $end): array
    {
        return HrPayrollRun::query()
            ->when($orgId, fn ($q) => $q->where('tenant_id', $orgId))
            ->whereNotNull('period_end')
            ->whereBetween('period_end', [$start->toDateString(), $end->toDateString()])
            ->orderBy('period_end')
            ->get()
            ->map(function (HrPayrollRun $run) {
                $date = Carbon::parse($run->period_end);

                return new FinanceCalendarItem(
                    id: "payroll-{$run->id}",
                    source: 'payroll',
                    title: "Payroll — period to {$date->format('d M Y')}",
                    start: $this->isoDate($date),
                    status: $run->net_paid_at !== null ? 'processed' : 'scheduled',
                    amount: (float) $run->total_gross,
                    direction: 'outflow',
                    ref: "RUN-{$run->id}",
                    link: route('hr.payroll.index', [], false),
                    meta: ['run_status' => $run->status],
                );
            })
            ->all();
    }
}
