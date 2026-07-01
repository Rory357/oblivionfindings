<?php

namespace App\Http\Controllers\Hr\Concerns;

use App\Domain\Hr\Models\HrDriverEligibility;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrStaffComplianceStatus;
use App\Models\Shift;
use App\Models\StaffBackgroundCheck;
use App\Models\User;

/**
 * Shared Compliance hero payload (golden band) — rendered above the tab strip on
 * every hub route so the hero "spans the whole hub" per the design handoff. A
 * handful of indexed count queries; cheap enough to run on each tab.
 */
trait BuildsComplianceHero
{
    protected function complianceHero(User $user, ?int $tenantId): array
    {
        $activeStaffUserIds = HrEmployeeProfile::where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->pluck('user_id');

        $totalStaff = $activeStaffUserIds->count();

        $fullyCompliant = $totalStaff === 0 ? 0 : User::whereIn('id', $activeStaffUserIds)
            ->whereDoesntHave('complianceStatuses', fn ($q) => $q->where('tenant_id', $tenantId)
                ->whereIn('status', ['expired', 'expiring_soon', 'not_started']))
            ->count();

        $base = HrStaffComplianceStatus::where('tenant_id', $tenantId)->whereIn('user_id', $activeStaffUserIds);
        $expiringTotal = (clone $base)->where('status', 'expiring_soon')->count();
        $expiredTotal = (clone $base)->where('status', 'expired')->count();

        $hasExpired = $totalStaff === 0 ? 0 : User::whereIn('id', $activeStaffUserIds)
            ->whereHas('complianceStatuses', fn ($q) => $q->where('tenant_id', $tenantId)->where('status', 'expired'))
            ->count();
        $hasExpiring = $totalStaff === 0 ? 0 : User::whereIn('id', $activeStaffUserIds)
            ->whereHas('complianceStatuses', fn ($q) => $q->where('tenant_id', $tenantId)->where('status', 'expiring_soon'))
            ->count();

        $hardStopUserIds = $totalStaff === 0 ? collect() : User::whereIn('id', $activeStaffUserIds)
            ->whereHas('complianceStatuses', fn ($q) => $q->where('tenant_id', $tenantId)
                ->whereIn('status', ['expired', 'not_started'])
                ->whereHas('requirement', fn ($r) => $r->where('hard_stop', true)->where('is_active', true)))
            ->pluck('id');

        $shiftsAffected = $hardStopUserIds->isNotEmpty()
            ? Shift::whereIn('user_id', $hardStopUserIds)
                ->where('status', 'scheduled')
                ->where('starts_at', '>', now())
                ->where('starts_at', '<', now()->addDays(14))
                ->count()
            : 0;

        return [
            'role' => $user->roles->first()?->label ?? $user->roles->first()?->name ?? 'Manager',
            'site' => 'All sites',
            'chips' => $this->complianceHeroChips($activeStaffUserIds, $tenantId),
            'needs' => array_values(array_filter([
                $hardStopUserIds->count() > 0
                    ? ['key' => 'hard_stops', 'label' => $hardStopUserIds->count() . ' expired hard-stop' . ($hardStopUserIds->count() === 1 ? '' : 's'), 'tab' => 'overview', 'status' => 'has_expired']
                    : null,
                $expiringTotal > 0
                    ? ['key' => 'expiring', 'label' => $expiringTotal . ' expiring ≤30d', 'tab' => 'calendar']
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
    private function complianceHeroChips($activeStaffUserIds, ?int $tenantId): array
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

        $drvBase = HrDriverEligibility::where('tenant_id', $tenantId);
        $drvCritical = (clone $drvBase)->where(fn ($q) => $q->where('status', 'suspended')
            ->orWhere(fn ($q2) => $q2->whereNotNull('licence_expires_at')->where('licence_expires_at', '<', now())))
            ->exists();
        $drvWarning = ! $drvCritical && (clone $drvBase)->expiring(30)->exists();
        $drvTone = $drvCritical ? 'critical' : ($drvWarning ? 'warning' : 'success');

        $mandBase = HrStaffComplianceStatus::where('tenant_id', $tenantId)
            ->whereIn('user_id', $activeStaffUserIds)
            ->whereHas('requirement', fn ($r) => $r->where('hard_stop', true)->where('is_active', true));
        $mandExpired = (clone $mandBase)->whereIn('status', ['expired', 'not_started'])->exists();
        $mandWarning = ! $mandExpired && (clone $mandBase)->where('status', 'expiring_soon')->exists();
        $mandTone = $mandExpired ? 'critical' : ($mandWarning ? 'warning' : 'success');

        return [
            ['key' => 'police', 'label' => 'Police vetting', 'tone' => $vetTone],
            ['key' => 'childrens', 'label' => "Children's Act", 'tone' => $vetTone],
            ['key' => 'driver', 'label' => 'Driver licences', 'tone' => $drvTone],
            ['key' => 'training', 'label' => 'Mandatory training', 'tone' => $mandTone],
        ];
    }
}
