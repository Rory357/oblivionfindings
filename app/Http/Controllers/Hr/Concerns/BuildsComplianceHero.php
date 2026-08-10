<?php

namespace App\Http\Controllers\Hr\Concerns;

use App\Domain\Hr\Models\HrDriverEligibility;
use App\Domain\Hr\Services\ComplianceMatrixService;
use App\Models\Shift;
use App\Models\Site;
use App\Models\StaffBackgroundCheck;
use App\Models\User;
use App\Services\UserSiteAccessService;

/**
 * Shared Compliance hero payload (golden band) — rendered above the tab strip on
 * every hub route so the hero "spans the whole hub" per the design handoff. A
 * handful of indexed count queries; cheap enough to run on each tab.
 */
trait BuildsComplianceHero
{
    protected function complianceHero(User $user): array
    {
        $staffQuery = User::query();
        app(UserSiteAccessService::class)->applyStaffScope($staffQuery, $user);
        $activeStaff = $staffQuery
            ->with([
                'roles:id,name',
                'hrEmployeeProfile:id,user_id,primary_site_id,secondary_site_ids',
                'complianceStatuses:id,user_id,requirement_id,status',
            ])
            ->get();
        $activeStaffUserIds = $activeStaff->pluck('id');
        $summaries = app(ComplianceMatrixService::class)->summariesForUsers($activeStaff);

        $totalStaff = $activeStaffUserIds->count();
        $fullyCompliant = $summaries->where('fully_compliant', true)->count();
        $expiringTotal = (int) $summaries->sum('expiring_soon');
        $expiredTotal = (int) $summaries->sum('expired');
        $hasExpired = $summaries->filter(fn (array $summary) => $summary['expired'] > 0)->count();
        $hasExpiring = $summaries->filter(fn (array $summary) => $summary['expiring_soon'] > 0)->count();
        $hardStopUserIds = $summaries
            ->filter(fn (array $summary) => $summary['hard_stop_failures'] > 0)
            ->keys();

        $shiftsAffected = 0;
        if ($hardStopUserIds->isNotEmpty()) {
            $shiftQuery = Shift::query()
                ->whereIn('user_id', $hardStopUserIds)
                ->where('status', 'scheduled')
                ->where('starts_at', '>', now())
                ->where('starts_at', '<', now()->addDays(14));
            app(UserSiteAccessService::class)->applyShiftScope($shiftQuery, $user);
            $shiftsAffected = $shiftQuery->count();
        }

        return [
            'role' => $user->roles->first()?->label ?? $user->roles->first()?->name ?? 'Manager',
            'site' => $this->complianceSiteLabel($user),
            'chips' => $this->complianceHeroChips($activeStaffUserIds, $summaries),
            'needs' => array_values(array_filter([
                $hardStopUserIds->count() > 0
                    ? ['key' => 'hard_stops', 'label' => $hardStopUserIds->count().' failed hard-stop'.($hardStopUserIds->count() === 1 ? '' : 's'), 'tab' => 'overview', 'status' => 'hard_stop']
                    : null,
                $expiringTotal > 0
                    ? ['key' => 'expiring', 'label' => $expiringTotal.' expiring ≤30d', 'tab' => 'calendar']
                    : null,
            ])),
            'summary' => [
                'total_staff' => $totalStaff,
                'fully_compliant' => $fullyCompliant,
                'has_expired' => $hasExpired,
                'has_expiring' => $hasExpiring,
                'expiring_total' => $expiringTotal,
                'expired_total' => $expiredTotal,
                'hard_stops' => $hardStopUserIds->count(),
                'shifts_affected' => $shiftsAffected,
            ],
        ];
    }

    /** @return array<int,array{key:string,label:string,tone:string}> */
    private function complianceHeroChips($activeStaffUserIds, $summaries): array
    {
        if ($activeStaffUserIds->isEmpty()) {
            return [];
        }

        $vetExpired = StaffBackgroundCheck::whereIn('user_id', $activeStaffUserIds)
            ->where(fn ($q) => $q->where('status', 'expired')
                ->orWhere(fn ($q2) => $q2->whereNotNull('expires_at')->where('expires_at', '<', now())))
            ->exists();
        $vetPending = ! $vetExpired && StaffBackgroundCheck::whereIn('user_id', $activeStaffUserIds)
            ->whereIn('status', ['pending', 'requested', 'in_progress', 'renewal_due', 'flagged'])
            ->exists();
        $vetTone = $vetExpired ? 'critical' : ($vetPending ? 'warning' : 'success');

        $drvBase = HrDriverEligibility::query()->whereIn('user_id', $activeStaffUserIds);
        $drvCritical = (clone $drvBase)->where(fn ($q) => $q->where('status', 'suspended')
            ->orWhere(fn ($q2) => $q2->whereNotNull('licence_expires_at')->where('licence_expires_at', '<', now())))
            ->exists();
        $drvWarning = ! $drvCritical && (clone $drvBase)->expiring(30)->exists();
        $drvTone = $drvCritical ? 'critical' : ($drvWarning ? 'warning' : 'success');

        $mandExpired = $summaries->contains(fn (array $summary) => $summary['hard_stop_failures'] > 0);
        $mandWarning = ! $mandExpired
            && $summaries->contains(fn (array $summary) => $summary['hard_stop_expiring'] > 0);
        $mandTone = $mandExpired ? 'critical' : ($mandWarning ? 'warning' : 'success');

        return [
            ['key' => 'police', 'label' => 'Police vetting', 'tone' => $vetTone],
            ['key' => 'childrens', 'label' => "Children's Act", 'tone' => $vetTone],
            ['key' => 'driver', 'label' => 'Driver licences', 'tone' => $drvTone],
            ['key' => 'training', 'label' => 'Mandatory training', 'tone' => $mandTone],
        ];
    }

    private function complianceSiteLabel(User $user): string
    {
        $siteIds = app(UserSiteAccessService::class)->accessibleSiteIds($user);
        if (count($siteIds) === 1) {
            return Site::query()->whereKey($siteIds[0])->value('name') ?? '1 site';
        }

        return count($siteIds) > 1 ? count($siteIds).' sites' : 'No sites';
    }
}
