<?php

namespace App\Domain\Hr\Services;

use App\Domain\Hr\Models\HrPayrollRun;
use App\Models\User;
use App\Services\UserSiteAccessService;
use Illuminate\Database\Eloquent\Builder;

/**
 * Canonical access to indivisible payroll-run summaries.
 *
 * A caller may see a historical run only when every employee represented by
 * the run retains provenance at a Site the caller can access. Mixed-Site and
 * provenance-free runs fail closed because their totals cannot be safely
 * partitioned. The payroll action capability and the explicit HR all-Sites
 * scope are deliberately independent.
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

        $accessibleSiteIds = $this->siteAccess->accessibleSiteIds(
            $viewer,
            UserSiteAccessService::HR_EMPLOYEE_SITE_BYPASS_PERMISSIONS,
        );
        $legacyVisibleStaffIds = $this->siteAccess->applyHistoricalHrEmployeeStaffScope(
            User::query()->select('users.id'),
            $viewer,
        );

        return $runs->where(function (Builder $scope) use (
            $accessibleSiteIds,
            $legacyVisibleStaffIds,
        ): void {
            // Verified runs are indivisible and therefore visible only when
            // every immutable source Site is in scope. Current employee
            // assignments must never rewrite historical payroll visibility.
            $scope->where(function (Builder $verified) use ($accessibleSiteIds): void {
                $verified->where('source_provenance_status', 'verified')
                    ->whereHas('sourceUses')
                    ->whereDoesntHave('items', fn (Builder $items) => $items
                        ->whereDoesntHave('sourceUses', fn (Builder $uses) => $uses
                            ->whereColumn(
                                'hr_payroll_source_uses.user_id',
                                'hr_payroll_run_items.user_id',
                            )))
                    ->whereDoesntHave('sourceUses', fn (Builder $uses) => $uses
                        ->whereNotIn('site_id', $accessibleSiteIds));
            })->orWhere(function (Builder $legacy) use ($legacyVisibleStaffIds): void {
                $legacy->where(function (Builder $status): void {
                    $status->whereNull('source_provenance_status')
                        ->orWhere('source_provenance_status', '!=', 'verified');
                })->whereHas('items', fn (Builder $items) => $items->whereIn(
                    'user_id',
                    clone $legacyVisibleStaffIds,
                ))->whereDoesntHave('items', fn (Builder $items) => $items->whereNotIn(
                    'user_id',
                    clone $legacyVisibleStaffIds,
                ));
            });
        });
    }

    public function payrollRun(User $viewer, HrPayrollRun|int $run): HrPayrollRun
    {
        $runId = $run instanceof HrPayrollRun ? $run->getKey() : $run;

        return $this->visibleRunsQuery($viewer)->findOrFail($runId);
    }

    public function canManageApplicationPayroll(User $viewer): bool
    {
        return $viewer->canDo('hr.payroll.view')
            && $viewer->canDo('hr.payroll.export')
            && $viewer->canDo('hr.employees.viewAllSites');
    }

    public function assertCanManageApplicationPayroll(User $viewer): void
    {
        abort_unless($this->canManageApplicationPayroll($viewer), 403);
    }
}
