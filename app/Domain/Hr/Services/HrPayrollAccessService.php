<?php

namespace App\Domain\Hr\Services;

use App\Domain\Hr\Models\HrPayrollRun;
use App\Models\User;
use App\Services\UserSiteAccessService;
use Illuminate\Database\Eloquent\Builder;

/**
 * Canonical access to indivisible payroll-run summaries.
 *
 * Payroll export is the explicit application-wide authority. A view-only
 * caller may see a historical run only when every employee represented by the
 * run retains provenance at a Site the caller can access. Mixed-Site and
 * provenance-free runs fail closed because their totals cannot be safely
 * partitioned.
 */
class HrPayrollAccessService
{
    public function __construct(private readonly UserSiteAccessService $siteAccess) {}

    /** @return Builder<HrPayrollRun> */
    public function visibleRunsQuery(User $viewer): Builder
    {
        $runs = HrPayrollRun::query();

        if (! $viewer->canDo('hr.payroll.view')) {
            return $runs->whereRaw('1 = 0');
        }

        if ($viewer->canDo('hr.payroll.export')) {
            return $runs;
        }

        $visibleStaffIds = $this->siteAccess->applyHistoricalStaffSiteScope(
            User::query()->select('users.id'),
            $viewer,
        );

        return $runs
            ->whereHas('items', fn (Builder $items) => $items->whereIn(
                'user_id',
                clone $visibleStaffIds,
            ))
            ->whereDoesntHave('items', fn (Builder $items) => $items->whereNotIn(
                'user_id',
                clone $visibleStaffIds,
            ));
    }

    public function payrollRun(User $viewer, HrPayrollRun|int $run): HrPayrollRun
    {
        $runId = $run instanceof HrPayrollRun ? $run->getKey() : $run;

        return $this->visibleRunsQuery($viewer)->findOrFail($runId);
    }
}
