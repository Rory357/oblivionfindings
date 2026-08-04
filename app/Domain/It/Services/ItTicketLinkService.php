<?php

namespace App\Domain\It\Services;

use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Models\ControlRoomAlert;
use App\Models\ItService;
use App\Models\ItTicket;
use App\Models\ItTicketLink;
use App\Models\Site;
use App\Models\SiteRoom;
use App\Models\User;
use App\Services\ControlRoom\ControlRoomAlertProvenanceService;
use App\Services\UserSiteAccessService;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class ItTicketLinkService
{
    public const MONITORING_PRINCIPAL = 'oblivion_monitoring_ticketing';

    public const MONITORING_OPERATION = 'work:create-monitoring';

    public function __construct(
        private readonly ItWorkAccessService $workAccess,
        private readonly SecurityDevicesAccessService $deviceAccess,
        private readonly UserSiteAccessService $siteAccess,
        private readonly ControlRoomAlertProvenanceService $alertProvenance,
    ) {}

    /** @param array<string, mixed> $context */
    public function link(
        ItTicket $ticket,
        Model $target,
        string $relationship,
        array $context = [],
        ?int $actorUserId = null,
    ): ItTicketLink {
        return DB::transaction(function () use ($ticket, $target, $relationship, $context, $actorUserId): ItTicketLink {
            $actor = $this->responsibleActor($actorUserId);
            [$canonicalTicket, $canonicalTarget] = $this->lockCanonicalRecords($ticket, $target);
            $this->assertHumanLinkAccess($actor, $canonicalTicket, $canonicalTarget, $relationship);

            return $this->persist(
                $canonicalTicket,
                $canonicalTarget,
                $relationship,
                $context,
                $actor->id,
            );
        });
    }

    public function unlink(
        ItTicket $ticket,
        Model $target,
        string $relationship,
        ?int $actorUserId = null,
    ): bool {
        return DB::transaction(function () use ($ticket, $target, $relationship, $actorUserId): bool {
            $actor = $this->responsibleActor($actorUserId);
            [$canonicalTicket, $canonicalTarget] = $this->lockCanonicalRecords($ticket, $target);
            $this->assertHumanLinkAccess($actor, $canonicalTicket, $canonicalTarget, $relationship);

            return $canonicalTicket->links()
                ->where('relationship', $relationship)
                ->where('linkable_type', $canonicalTarget->getMorphClass())
                ->where('linkable_id', $canonicalTarget->getKey())
                ->delete() > 0;
        });
    }

    /**
     * Dedicated least-privileged operation used only by the native monitoring
     * listener. It cannot link arbitrary records or organisation-wide work.
     *
     * @param  array<string, mixed>  $context
     */
    public function linkMonitoringEvidence(
        ItTicket $ticket,
        Device $device,
        ControlRoomAlert $alert,
        array $context = [],
    ): void {
        DB::transaction(function () use ($ticket, $device, $alert, $context): void {
            $ticket = ItTicket::query()->whereKey($ticket->getKey())->lockForUpdate()->first();
            $device = Device::query()->whereKey($device->getKey())->lockForUpdate()->first();
            $alert = ControlRoomAlert::query()->whereKey($alert->getKey())->lockForUpdate()->first();
            if (! $ticket || ! $device || ! $alert
                || $ticket->source !== 'system'
                || $ticket->work_type !== 'incident'
                || $ticket->site_id === null
                || $ticket->is_organisation_wide
                || $device->domain !== 'it_infrastructure') {
                throw new DomainException('Monitoring ticket context is not canonical.');
            }

            $siteId = $this->canonicalMonitoringSiteId($device, $alert, true);
            if ($siteId === null || $siteId !== (int) $ticket->site_id) {
                throw new DomainException('Monitoring Device, Site, and alert evidence do not agree.');
            }

            $principalContext = [
                ...$context,
                'system_principal' => self::MONITORING_PRINCIPAL,
                'operation' => self::MONITORING_OPERATION,
                'site_id' => $siteId,
            ];
            $this->persistMonitoring($ticket, $device, 'affected_device', $principalContext);
            $this->persistMonitoring($ticket, $alert, 'source_alert', $principalContext);
        });
    }

    public function canonicalDeviceSiteId(Device $device, bool $lockForUpdate = false): ?int
    {
        $assignments = $device->assignments()->active();
        if ($lockForUpdate) {
            $assignments->lockForUpdate();
        }
        $assignments = $assignments->get();
        if ($assignments->count() !== 1) {
            return null;
        }

        $assignment = $assignments->first();
        $siteId = match ($assignment->assignable_type) {
            DeviceAssignment::TARGET_SITE => (int) $assignment->assignable_id,
            DeviceAssignment::TARGET_ROOM => (int) (SiteRoom::query()
                ->whereKey($assignment->assignable_id)
                ->value('site_id') ?? 0),
            default => 0,
        };

        if ($siteId < 1) {
            return null;
        }

        $site = Site::query()
            ->whereKey($siteId)
            ->where('is_active', true)
            ->where('archived', false)
            ->whereNull('archived_at')
            ->when($lockForUpdate, fn ($query) => $query->lockForUpdate())
            ->first(['id']);

        return $site ? $siteId : null;
    }

    public function canonicalMonitoringSiteId(
        Device $device,
        ControlRoomAlert $alert,
        bool $lockForUpdate = false,
    ): ?int {
        $siteId = $this->canonicalDeviceSiteId($device, $lockForUpdate);
        $alertSiteId = $this->alertProvenance->authoritativeSiteId($alert);
        $alertDeviceId = $this->alertProvenance->authoritativeCanonicalDeviceId($alert);

        return $siteId !== null
            && $alertSiteId === $siteId
            && $alertDeviceId === (int) $device->id
                ? $siteId
                : null;
    }

    private function responsibleActor(?int $actorUserId): User
    {
        $actor = $actorUserId === null
            ? null
            : User::query()->whereKey($actorUserId)->whereNotNull('approved_at')->first();
        if (! $actor) {
            throw new DomainException('A current responsible actor is required for ticket links.');
        }

        return $actor;
    }

    /** @return array{0: ItTicket, 1: Model} */
    private function lockCanonicalRecords(ItTicket $ticket, Model $target): array
    {
        if ($target instanceof ItTicket) {
            $ids = collect([(int) $ticket->getKey(), (int) $target->getKey()])
                ->unique()
                ->sort()
                ->values()
                ->all();
            $lockedTickets = ItTicket::query()
                ->whereKey($ids)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');
            $canonicalTicket = $lockedTickets->get((int) $ticket->getKey());
            $canonicalTarget = $lockedTickets->get((int) $target->getKey());
        } else {
            $canonicalTicket = ItTicket::query()
                ->whereKey($ticket->getKey())
                ->lockForUpdate()
                ->first();
            $canonicalTarget = $target->newQuery()
                ->whereKey($target->getKey())
                ->lockForUpdate()
                ->first();
        }

        if (! $canonicalTicket || ! $canonicalTarget) {
            throw new DomainException('The ticket or linked record is no longer available.');
        }

        return [$canonicalTicket, $canonicalTarget];
    }

    private function assertHumanLinkAccess(
        User $actor,
        ItTicket $ticket,
        Model $target,
        string $relationship,
    ): void {
        if (! in_array($relationship, ItTicketLink::RELATIONSHIPS, true)) {
            throw new DomainException('That ticket-link relationship is not supported.');
        }
        if (! $this->workAccess->canWork($actor, $ticket)
            || ! $this->targetIsAccessible($actor, $target, $relationship)) {
            throw new DomainException('The ticket or linked record is not accessible to this actor.');
        }
    }

    private function targetIsAccessible(User $actor, Model $target, string $relationship): bool
    {
        if ($target instanceof Device) {
            return $relationship === 'affected_device'
                && $actor->canDo('securityDevices.devices.view')
                && $this->deviceAccess->visibleDevices($actor)->whereKey($target->getKey())->exists();
        }

        if ($target instanceof ControlRoomAlert) {
            if ($relationship !== 'source_alert' || ! $actor->canDo('controlRoom.alerts.view')) {
                return false;
            }
            $query = ControlRoomAlert::query()->whereKey($target->getKey());
            $this->siteAccess->applyAlertScope($query, $actor);

            return $query->exists();
        }

        if ($target instanceof ItTicket) {
            return in_array($relationship, [
                'related_incident',
                'related_problem',
                'related_change',
                'major_incident_member',
            ], true)
                && Gate::forUser($actor)->allows('view', $target);
        }

        if ($target instanceof Site) {
            return $relationship === 'affected_site'
                && in_array((int) $target->getKey(), $this->workAccess->approvedSiteIds($actor), true);
        }

        return $target instanceof ItService
            && $relationship === 'affected_service'
            && $target->is_active
            && $actor->canDo('it.manage');
    }

    /** @param array<string, mixed> $context */
    private function persist(
        ItTicket $ticket,
        Model $target,
        string $relationship,
        array $context,
        ?int $actorUserId,
    ): ItTicketLink {
        return $ticket->links()->firstOrCreate([
            'relationship' => $relationship,
            'linkable_type' => $target->getMorphClass(),
            'linkable_id' => $target->getKey(),
        ], [
            'context' => $context,
            'created_by_user_id' => $actorUserId,
        ]);
    }

    /** @param array<string, mixed> $context */
    private function persistMonitoring(
        ItTicket $ticket,
        Model $target,
        string $relationship,
        array $context,
    ): ItTicketLink {
        return $ticket->links()->updateOrCreate([
            'relationship' => $relationship,
            'linkable_type' => $target->getMorphClass(),
            'linkable_id' => $target->getKey(),
        ], [
            'context' => $context,
            'created_by_user_id' => null,
        ]);
    }
}
