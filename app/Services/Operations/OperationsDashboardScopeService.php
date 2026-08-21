<?php

namespace App\Services\Operations;

use App\Models\Client;
use App\Models\ClientIncident;
use App\Models\Shift;
use App\Models\Site;
use App\Models\Timesheet;
use App\Models\User;
use App\Services\UserSiteAccessService;
use Illuminate\Database\Eloquent\Builder;

/**
 * One Site boundary for both Operations dashboard read surfaces.
 *
 * The dashboard capability authorizes the read. The separately granted global
 * capability may broaden only the Site set; unrelated operational permissions
 * never do so. Every row query still passes through canonical provenance and
 * integrity scopes before any aggregate or UI projection is built.
 */
final class OperationsDashboardScopeService
{
    public const VIEW_PERMISSION = 'operations.dashboard.view';

    public const GLOBAL_PERMISSION = 'operations.dashboard.viewAllSites';

    /** @var list<string> */
    private const SITE_BYPASS_PERMISSIONS = [self::GLOBAL_PERMISSION];

    public function __construct(
        private readonly UserSiteAccessService $siteAccess,
    ) {}

    public function authorize(?User $actor): User
    {
        abort_unless(
            $actor?->canDo(self::VIEW_PERMISSION),
            403,
            UserSiteAccessService::DEFAULT_MESSAGE,
        );

        return $actor;
    }

    /** @return list<int> */
    public function siteIds(User $actor): array
    {
        return $this->siteAccess->accessibleSiteIds($actor, self::SITE_BYPASS_PERMISSIONS);
    }

    public function clients(User $actor): Builder
    {
        return $this->siteAccess->applyClientScope(
            Client::query(),
            $actor,
            self::SITE_BYPASS_PERMISSIONS,
        );
    }

    public function shifts(User $actor): Builder
    {
        return $this->siteAccess->applyShiftScope(
            Shift::query(),
            $actor,
            self::SITE_BYPASS_PERMISSIONS,
        );
    }

    public function timesheets(User $actor): Builder
    {
        $query = $this->siteAccess->applyTimesheetScope(
            Timesheet::query(),
            $actor,
            self::SITE_BYPASS_PERMISSIONS,
        );

        return $query->whereHas('staff', fn (Builder $staff) => $this->applyStaffScope($staff, $actor));
    }

    public function incidents(User $actor): Builder
    {
        $siteIds = $this->siteIds($actor);
        $query = $this->siteAccess->applyClientIncidentScope(
            ClientIncident::query(),
            $actor,
            self::SITE_BYPASS_PERMISSIONS,
        );

        if ($siteIds === []) {
            return $query;
        }

        // Direct Site, Client and optional Shift provenance must converge even
        // for global viewers. Broken cross-Site rows are never dashboard data.
        return $query
            ->whereHas('client', fn (Builder $client) => $client
                ->whereIn('clients.site_id', $siteIds)
                ->where(function (Builder $siteAgreement): void {
                    $siteAgreement->whereNull('client_incidents.site_id')
                        ->orWhereColumn('clients.site_id', 'client_incidents.site_id');
                }))
            ->where(function (Builder $shiftAgreement) use ($actor): void {
                $shiftAgreement->whereNull('client_incidents.shift_id')
                    ->orWhereHas('shift', fn (Builder $shift) => $this->siteAccess->applyShiftScope(
                        $shift,
                        $actor,
                        self::SITE_BYPASS_PERMISSIONS,
                    )->whereColumn('shifts.client_id', 'client_incidents.client_id'));
            });
    }

    public function sites(User $actor): Builder
    {
        return $this->siteAccess->applySiteScope(
            Site::query()->active()->notArchived()->whereNull('archived_at'),
            $actor,
            self::SITE_BYPASS_PERMISSIONS,
        );
    }

    public function staff(User $actor): Builder
    {
        return $this->applyStaffScope(User::query(), $actor);
    }

    private function applyStaffScope(Builder $query, User $actor): Builder
    {
        $siteIds = $this->siteIds($actor);
        $query = $this->siteAccess->applyStaffScope($query, $actor, self::SITE_BYPASS_PERMISSIONS);
        if ($siteIds === []) {
            return $query->whereRaw('1 = 0');
        }

        // The shared global staff scope deliberately includes current staff
        // without an assigned Site. Dashboard rows are stricter: even a global
        // viewer may see only staff attached to one of the active Site records.
        return $query->whereHas('hrEmployeeProfile', function (Builder $profile) use ($siteIds): void {
            $profile->where(function (Builder $assignedSite) use ($siteIds): void {
                $assignedSite->whereIn('primary_site_id', $siteIds);
                foreach ($siteIds as $siteId) {
                    $assignedSite->orWhereJsonContains('secondary_site_ids', $siteId);
                }
            });
        });
    }
}
