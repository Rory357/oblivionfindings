<?php

namespace App\Services\HealthSafety;

use App\Models\LoneWorkerSession;
use App\Models\Shift;
use App\Models\ShiftGpsLog;
use App\Models\User;
use App\Services\UserSiteAccessService;

final class ShiftGpsAccessService
{
    private const LIVE_SESSION_STATUSES = ['active', 'overdue', 'emergency'];

    private const SITE_BYPASS_PERMISSIONS = ['healthSafety.viewAllSites'];

    private const FRESH_LOCATION_MINUTES = 15;

    public function __construct(private readonly UserSiteAccessService $siteAccess) {}

    /**
     * Return one fresh location only for the Shift's assigned worker or an
     * authorised H&S manager, and only while the canonical safety session is live.
     */
    public function latestForLiveSession(
        User $actor,
        Shift $shift,
        LoneWorkerSession $session,
    ): ?ShiftGpsLog {
        $canonicalShift = Shift::query()
            ->with('client:id,site_id')
            ->find($shift->getKey());
        abort_unless($canonicalShift, 403, UserSiteAccessService::DEFAULT_MESSAGE);

        $siteId = $this->siteAccess->shiftSiteId($canonicalShift);
        abort_unless($siteId !== null, 403, UserSiteAccessService::DEFAULT_MESSAGE);

        $canonicalSession = LoneWorkerSession::query()
            ->whereKey($session->getKey())
            ->where('shift_id', $canonicalShift->id)
            ->where('user_id', $canonicalShift->user_id)
            ->where('site_id', $siteId)
            ->whereIn('status', self::LIVE_SESSION_STATUSES)
            ->whereNull('ended_at')
            ->first();
        abort_unless($canonicalSession, 403, UserSiteAccessService::DEFAULT_MESSAGE);

        $isAssignedWorker = (int) $actor->id === (int) $canonicalShift->user_id;
        abort_unless(
            $isAssignedWorker || $actor->canDo('hazards.manage'),
            403,
            UserSiteAccessService::DEFAULT_MESSAGE,
        );
        abort_unless(
            $actor->canDo('assets.telemetry.view'),
            403,
            'Asset telemetry permission is required to view Shift location evidence.',
        );
        $this->siteAccess->assertCanAccessShift(
            $actor,
            $canonicalShift,
            self::SITE_BYPASS_PERMISSIONS,
        );

        $shiftStartedAt = $canonicalShift->actual_starts_at;
        abort_unless(
            $shiftStartedAt
                && $shiftStartedAt->lessThanOrEqualTo(now())
                && $canonicalShift->actual_ends_at === null
                && ! in_array($canonicalShift->status, ['cancelled', 'completed'], true),
            403,
            'Shift location is available only while the assigned Shift is in progress.',
        );
        $freshSince = now()->subMinutes(self::FRESH_LOCATION_MINUTES);
        $locationBoundary = $shiftStartedAt->greaterThan($freshSince)
            ? $shiftStartedAt
            : $freshSince;

        return ShiftGpsLog::query()
            ->where('shift_id', $canonicalShift->id)
            ->where('user_id', $canonicalShift->user_id)
            ->where('captured_at', '>=', $locationBoundary)
            ->where('captured_at', '<=', now()->addMinute())
            ->latest('captured_at')
            ->latest('id')
            ->first();
    }
}
