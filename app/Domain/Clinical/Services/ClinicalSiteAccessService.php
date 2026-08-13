<?php

namespace App\Domain\Clinical\Services;

use App\Domain\Clinical\Models\ClinicalEvent;
use App\Domain\Clinical\Models\ClinicalObservation;
use App\Domain\Clinical\Models\ClinicalProtocol;
use App\Domain\Clinical\Models\ClinicalProtocolSchedule;
use App\Models\Client;
use App\Models\Shift;
use App\Models\Site;
use App\Models\User;
use App\Services\UserSiteAccessService;
use Illuminate\Database\Eloquent\Builder;

/**
 * Canonical Site boundary for authenticated Health & Clinical work.
 *
 * Clinical capabilities (viewAny, record, review, manage) never imply access
 * to every Site. Cross-Site access is a separate, explicit permission granted
 * only to authorised central roles.
 */
class ClinicalSiteAccessService
{
    public const ACCESS_ALL_SITES_PERMISSION = 'clinical.accessAllSites';

    /** @var list<string> */
    public const SITE_BYPASS_PERMISSIONS = [
        self::ACCESS_ALL_SITES_PERMISSION,
        'sites.viewAll',
    ];

    public function __construct(
        private readonly UserSiteAccessService $siteAccess,
    ) {}

    /** @return list<int> */
    public function allowedSiteIds(?User $user): array
    {
        return $this->siteAccess->accessibleSiteIds($user, self::SITE_BYPASS_PERMISSIONS);
    }

    public function applyClientScope(Builder $query, ?User $user): Builder
    {
        return $this->siteAccess->applyClientScope($query, $user, self::SITE_BYPASS_PERMISSIONS);
    }

    public function applySiteScope(Builder $query, ?User $user): Builder
    {
        return $this->siteAccess->applySiteScope($query, $user, self::SITE_BYPASS_PERMISSIONS);
    }

    public function applyStaffScope(Builder $query, ?User $user): Builder
    {
        return $this->siteAccess->applyStaffScope($query, $user, self::SITE_BYPASS_PERMISSIONS);
    }

    /** Apply canonical Client Site ownership to any model with a client relation. */
    public function applyClientOwnedScope(Builder $query, ?User $user, string $relation = 'client'): Builder
    {
        return $query->whereHas(
            $relation,
            fn (Builder $clients) => $this->applyClientScope($clients, $user),
        );
    }

    /**
     * Scope a Client-owned record that also carries a Site snapshot.
     *
     * A null snapshot may fall back to the canonical Client Site for legacy
     * rows. A non-null snapshot must agree with that Client Site; conflicting
     * provenance is hidden from every Site rather than assigned by guesswork.
     */
    public function applyClientRecordScope(Builder $query, ?User $user): Builder
    {
        $this->applyClientOwnedScope($query, $user);

        return $this->applyClientRecordIntegrity($query);
    }

    /** Hide records whose Site snapshot conflicts with their canonical Client. */
    public function applyClientRecordIntegrity(Builder $query): Builder
    {
        $table = $query->getModel()->getTable();

        return $query->where(function (Builder $recordSite) use ($table): void {
            $recordSite->whereNull($table.'.site_id')
                ->orWhereExists(function ($clients) use ($table): void {
                    $clients->selectRaw('1')
                        ->from('clients')
                        ->whereColumn('clients.id', $table.'.client_id')
                        ->whereColumn('clients.site_id', $table.'.site_id')
                        ->whereNull('clients.deleted_at');
                });
        });
    }

    public function applyObservationScope(Builder $query, ?User $user): Builder
    {
        return $this->applyClientRecordScope($query, $user);
    }

    public function applyEventScope(Builder $query, ?User $user): Builder
    {
        return $this->applyClientRecordScope($query, $user);
    }

    public function applyProtocolScope(Builder $query, ?User $user): Builder
    {
        return $this->applyClientOwnedScope($query, $user);
    }

    public function applyScheduleScope(Builder $query, ?User $user): Builder
    {
        return $query->whereHas(
            'protocol.client',
            fn (Builder $clients) => $this->applyClientScope($clients, $user),
        );
    }

    public function canAccessClient(?User $user, Client $client): bool
    {
        return $this->applyClientScope(Client::query()->whereKey($client->getKey()), $user)->exists();
    }

    public function canAccessSite(?User $user, Site $site): bool
    {
        return $this->applySiteScope(Site::query()->whereKey($site->getKey()), $user)->exists();
    }

    public function canAccessObservation(?User $user, ClinicalObservation $observation): bool
    {
        return $this->applyObservationScope(
            ClinicalObservation::query()->whereKey($observation->getKey()),
            $user,
        )->exists();
    }

    public function canAccessEvent(?User $user, ClinicalEvent $event): bool
    {
        return $this->applyEventScope(
            ClinicalEvent::query()->whereKey($event->getKey()),
            $user,
        )->exists();
    }

    public function canAccessProtocol(?User $user, ClinicalProtocol $protocol): bool
    {
        return $this->applyProtocolScope(
            ClinicalProtocol::query()->whereKey($protocol->getKey()),
            $user,
        )->exists();
    }

    public function assertCanAccessClient(?User $user, Client $client): void
    {
        abort_unless($this->canAccessClient($user, $client), 403, UserSiteAccessService::DEFAULT_MESSAGE);
    }

    public function assertCanAccessSite(?User $user, Site $site): void
    {
        abort_unless($this->canAccessSite($user, $site), 403, UserSiteAccessService::DEFAULT_MESSAGE);
    }

    public function assertCanAccessEvent(?User $user, ClinicalEvent $event): void
    {
        abort_unless($this->canAccessEvent($user, $event), 403, UserSiteAccessService::DEFAULT_MESSAGE);
    }

    public function assertCanAccessProtocol(?User $user, ClinicalProtocol $protocol): void
    {
        abort_unless($this->canAccessProtocol($user, $protocol), 403, UserSiteAccessService::DEFAULT_MESSAGE);
    }

    public function assertCanAccessShift(?User $user, Shift $shift): void
    {
        $this->siteAccess->assertCanAccessShift($user, $shift, self::SITE_BYPASS_PERMISSIONS);
    }

    public function assertCanUseProtocolSchedule(
        ?User $user,
        Client $client,
        int $scheduleId,
        ?string $observationType = null,
    ): void {
        $schedule = $this->applyScheduleScope(
            ClinicalProtocolSchedule::query()->whereKey($scheduleId),
            $user,
        )
            ->whereHas('protocol', function (Builder $protocols) use ($client, $observationType): void {
                $protocols->where('client_id', $client->getKey());

                if ($observationType !== null) {
                    $protocols->where('observation_type', $observationType);
                }
            })
            ->where('status', 'pending')
            ->exists();

        abort_unless($schedule, 403, UserSiteAccessService::DEFAULT_MESSAGE);
    }
}
