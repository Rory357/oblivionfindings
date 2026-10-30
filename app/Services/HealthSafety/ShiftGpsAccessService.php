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

    private const SITE_BYPASS_PERMISSIONS = UserSiteAccessService::HEALTH_SAFETY_SITE_BYPASS_PERMISSIONS;

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

    /**
     * The linked Shift's latest GPS ping for the lone-worker session page (Stephan, 25 Oct: "show it, with time, to
     * GPS users"). Read live from the Shift's telemetry, never copied to the session. Only for a viewer with
     * assets.telemetry.view and the Shift's Site scope, only while the session is live, and only pings from the
     * Shift's own time. A ping older than the freshness window is marked old, never shown as current.
     *
     * @return array{latitude: float, longitude: float, captured_at: string, is_old: bool, fresh_minutes: int}|null
     */
    public function latestPingForSessionViewer(User $viewer, LoneWorkerSession $session): ?array
    {
        if ($session->shift_id === null || $session->ended_at !== null
            || ! in_array($session->status, self::LIVE_SESSION_STATUSES, true) || ! $viewer->canDo('assets.telemetry.view')) {
            return null;
        }
        $shift = Shift::query()->with('client:id,site_id')->find($session->shift_id);
        $startedAt = $shift?->actual_starts_at;
        if (! $shift || $startedAt === null || (int) $shift->user_id !== (int) $session->user_id) {
            return null;
        }
        $siteId = $this->siteAccess->shiftSiteId($shift);
        if ($siteId === null || (int) $siteId !== (int) $session->site_id) {
            return null;
        }
        try {
            $this->siteAccess->assertCanAccessShift($viewer, $shift, self::SITE_BYPASS_PERMISSIONS);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $outsideScope) {
            if (! in_array($outsideScope->getStatusCode(), [403, 404], true)) { throw $outsideScope; }

            return null;
        }
        $until = now()->addMinute();
        if ($shift->actual_ends_at !== null && $shift->actual_ends_at->lessThan($until)) {
            $until = $shift->actual_ends_at;
        }
        $ping = ShiftGpsLog::query()
            ->where('shift_id', $shift->id)
            ->where('user_id', $shift->user_id)
            ->where('captured_at', '>=', $startedAt)
            ->where('captured_at', '<=', $until)
            ->latest('captured_at')
            ->latest('id')
            ->first(['id', 'latitude', 'longitude', 'captured_at']);
        if ($ping === null || $ping->captured_at === null || ! is_numeric($ping->latitude) || ! is_numeric($ping->longitude)) {
            return null;
        }

        return [
            'latitude' => (float) $ping->latitude,
            'longitude' => (float) $ping->longitude,
            'captured_at' => $ping->captured_at->toIso8601String(),
            'is_old' => $ping->captured_at->lessThan(now()->subMinutes(self::FRESH_LOCATION_MINUTES)),
            'fresh_minutes' => self::FRESH_LOCATION_MINUTES,
        ];
    }
}
