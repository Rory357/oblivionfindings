<?php

namespace App\Services\HealthSafety;

use App\Models\LoneWorkerSession;
use App\Models\Site;
use App\Models\User;
use App\Services\UserSiteAccessService;

/** Canonical safety-session ownership and Site checks, shared by both workspaces. */
final class LoneWorkerSessionAccessService
{
    private const SITE_BYPASS_PERMISSIONS = UserSiteAccessService::HEALTH_SAFETY_SITE_BYPASS_PERMISSIONS;

    public function __construct(private readonly UserSiteAccessService $siteAccess) {}

    public function assertCanAccessSession(
        User $user,
        LoneWorkerSession $session,
        bool $allowOwnerWithoutSiteAssignment = false,
    ): void {
        $session->loadMissing([
            'user:id,approved_at',
            'site:id,is_active,archived,archived_at',
            'client:id,site_id',
            'shift:id,site_id,client_id,user_id',
            'shift.client:id,site_id',
        ]);

        abort_unless(
            $session->user,
            403,
            UserSiteAccessService::DEFAULT_MESSAGE,
        );

        $clientSiteId = null;
        if ($session->client_id !== null) {
            abort_unless(
                $session->client
                    && $session->client->site_id !== null,
                403,
                UserSiteAccessService::DEFAULT_MESSAGE,
            );
            $clientSiteId = (int) $session->client->site_id;
        }

        $shiftSiteId = null;
        $shiftClientSiteId = null;
        if ($session->shift_id !== null) {
            abort_unless(
                $session->shift
                    && (int) $session->shift->user_id === (int) $session->user_id
                    && (int) $session->shift->client_id === (int) $session->client_id,
                403,
                UserSiteAccessService::DEFAULT_MESSAGE,
            );

            if ($session->shift->client_id !== null) {
                abort_unless(
                    $session->shift->client
                        && $session->shift->client->site_id !== null,
                    403,
                    UserSiteAccessService::DEFAULT_MESSAGE,
                );
                $shiftClientSiteId = (int) $session->shift->client->site_id;
                abort_if(
                    $session->shift->site_id !== null
                        && (int) $session->shift->site_id !== $shiftClientSiteId,
                    403,
                    UserSiteAccessService::DEFAULT_MESSAGE,
                );
            }

            $shiftSiteId = $session->shift->site_id !== null
                ? (int) $session->shift->site_id
                : $shiftClientSiteId;
            abort_if($shiftSiteId === null, 403, UserSiteAccessService::DEFAULT_MESSAGE);

        }

        $siteIds = collect([
            $session->site_id,
            $clientSiteId,
            $session->shift?->site_id,
            $shiftClientSiteId,
        ])
            ->filter(fn ($siteId) => $siteId !== null)
            ->map(fn ($siteId) => (int) $siteId)
            ->unique()
            ->values();

        abort_unless($siteIds->count() === 1, 403, UserSiteAccessService::DEFAULT_MESSAGE);
        $siteId = (int) $siteIds->first();
        abort_unless(
            Site::query()
                ->whereKey($siteId)
                ->active()
                ->notArchived()
                ->whereNull('archived_at')
                ->exists(),
            403,
            UserSiteAccessService::DEFAULT_MESSAGE,
        );
        abort_unless(
            in_array($siteId, $this->siteAccess->accessibleSiteIds($session->user), true),
            403,
            UserSiteAccessService::DEFAULT_MESSAGE,
        );

        if ($this->siteAccess->canBypass($user, self::SITE_BYPASS_PERMISSIONS)) {
            return;
        }

        if ($allowOwnerWithoutSiteAssignment) {
            abort_unless(
                (int) $session->user_id === (int) $user->id,
                403,
                UserSiteAccessService::DEFAULT_MESSAGE,
            );

            return;
        }

        $this->siteAccess->assertCanAccessSiteId(
            $user,
            $siteId,
            self::SITE_BYPASS_PERMISSIONS,
        );
    }
}
