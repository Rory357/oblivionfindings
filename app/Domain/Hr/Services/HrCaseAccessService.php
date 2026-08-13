<?php

namespace App\Domain\Hr\Services;

use App\Models\User;
use App\Services\UserSiteAccessService;
use Illuminate\Database\Eloquent\Builder;

/**
 * Canonical read boundary for retained HR cases.
 *
 * Record visibility follows historical employee-profile Site provenance;
 * confidentiality remains an independent privacy boundary. Current-staff
 * eligibility for new subjects, assignees and access-list recipients stays in
 * UserSiteAccessService::applyStaffScope().
 */
class HrCaseAccessService
{
    public function __construct(private readonly UserSiteAccessService $siteAccess) {}

    public function applyVisibleCaseScope(Builder $query, User $viewer): Builder
    {
        $historicalSubjects = $this->siteAccess->applyHistoricalStaffSiteScope(
            User::query()->select('users.id'),
            $viewer,
            UserSiteAccessService::HR_EMPLOYEE_SITE_BYPASS_PERMISSIONS,
        );

        $query->whereIn($query->qualifyColumn('user_id'), $historicalSubjects);

        return $this->applyConfidentialityScope($query, $viewer);
    }

    public function applyConfidentialityScope(Builder $query, User $viewer): Builder
    {
        if ($viewer->canDo('hr.cases.manage')) {
            return $query;
        }

        return $query->where(function (Builder $inner) use ($viewer): void {
            $inner->where('is_confidential', false)
                ->orWhereNull('is_confidential')
                ->orWhere('created_by', $viewer->id)
                ->orWhere('reported_by', $viewer->id)
                ->orWhere('assigned_to', $viewer->id)
                // Access-list entries may be stored as integers or strings.
                ->orWhereJsonContains('access_list', $viewer->id)
                ->orWhereJsonContains('access_list', (string) $viewer->id);
        });
    }
}
