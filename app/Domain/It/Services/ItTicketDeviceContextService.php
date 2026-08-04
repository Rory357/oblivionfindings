<?php

namespace App\Domain\It\Services;

use App\Domain\Monitoring\Services\CanonicalDeviceSiteResolver;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Models\ItTicket;
use App\Models\ItTicketEvent;
use App\Models\ItTicketLink;
use App\Models\User;
use App\Services\AuditLogger;
use DomainException;
use Illuminate\Support\Facades\DB;
use UnexpectedValueException;

/**
 * Governs affected-Device context on ordinary helpdesk tickets.
 *
 * Security & Devices remains the canonical Device owner. This lifecycle adds
 * and removes only the typed relationship, never a Device or parallel asset
 * record. Monitoring evidence is immutable from the human workspace.
 */
final class ItTicketDeviceContextService
{
    public function __construct(
        private readonly ItTicketLinkService $links,
        private readonly ItWorkAccessService $workAccess,
        private readonly CanonicalDeviceSiteResolver $deviceSites,
        private readonly SecurityDevicesAccessService $deviceAccess,
    ) {}

    /** Used inside the existing ticket-intake transaction. */
    public function linkAtIntake(ItTicket $ticket, Device $device, User $actor): ItTicketLink
    {
        $this->assertAvailableInScope(
            $device,
            $ticket->site_id !== null ? (int) $ticket->site_id : null,
            (bool) $ticket->is_organisation_wide,
        );

        return $this->links->link(
            $ticket,
            $device,
            'affected_device',
            ['canonical_owner' => 'security_devices', 'source' => 'ticket_intake'],
            $actor->id,
        );
    }

    public function add(ItTicket $ticket, int $deviceId, User $actor): bool
    {
        return DB::transaction(function () use ($ticket, $deviceId, $actor): bool {
            $ticket = $this->lockedMutableTicket($ticket, $actor);
            $device = $this->visibleLockedDevice($actor, $deviceId);

            $this->assertAvailableInScope(
                $device,
                $ticket->site_id !== null ? (int) $ticket->site_id : null,
                (bool) $ticket->is_organisation_wide,
            );

            $link = $this->links->link(
                $ticket,
                $device,
                'affected_device',
                ['canonical_owner' => 'security_devices', 'source' => 'ticket_workspace'],
                $actor->id,
            );
            if (! $link->wasRecentlyCreated) {
                return false;
            }

            ItTicketEvent::record($ticket, 'context_linked', $actor->id, [
                'relationship' => 'affected_device',
                'device_id' => $device->id,
                'device_name' => $device->name,
                'via' => 'workspace',
            ]);
            AuditLogger::logOrFail('it.ticket.device.linked', $ticket, $this->auditMeta($ticket, $device, $actor));

            return true;
        });
    }

    public function remove(ItTicket $ticket, Device $device, User $actor): bool
    {
        return DB::transaction(function () use ($ticket, $device, $actor): bool {
            $ticket = $this->lockedMutableTicket($ticket, $actor);
            $device = $this->visibleLockedDevice($actor, (int) $device->getKey());

            $link = $ticket->links()
                ->where('relationship', 'affected_device')
                ->where('linkable_type', $device->getMorphClass())
                ->where('linkable_id', $device->id)
                ->lockForUpdate()
                ->first();
            if (! $link) {
                return false;
            }
            if (($link->context['system_principal'] ?? null) === ItTicketLinkService::MONITORING_PRINCIPAL) {
                throw new DomainException('Monitoring evidence is managed by Oblivion monitoring and cannot be removed here.');
            }

            if (! $this->links->unlink($ticket, $device, 'affected_device', $actor->id)) {
                return false;
            }

            ItTicketEvent::record($ticket, 'context_unlinked', $actor->id, [
                'relationship' => 'affected_device',
                'device_id' => $device->id,
                'device_name' => $device->name,
                'via' => 'workspace',
            ]);
            AuditLogger::logOrFail('it.ticket.device.unlinked', $ticket, $this->auditMeta($ticket, $device, $actor));

            return true;
        });
    }

    public function assertAvailableInScope(Device $device, ?int $siteId, bool $isApplicationWide): void
    {
        if ($isApplicationWide && $siteId === null) {
            return;
        }
        if ($siteId === null || $isApplicationWide) {
            throw new DomainException('Choose a Device in the ticket Site.');
        }

        try {
            $deviceSiteId = $this->deviceSites->resolveForContext((int) $device->id);
        } catch (UnexpectedValueException) {
            throw new DomainException('The selected Device does not resolve to one active Site.');
        }

        if ($deviceSiteId !== $siteId) {
            throw new DomainException('Choose a Device in the ticket Site.');
        }
    }

    private function lockedMutableTicket(ItTicket $ticket, User $actor): ItTicket
    {
        $locked = ItTicket::query()->whereKey($ticket->getKey())->lockForUpdate()->firstOrFail();
        if (! $this->workAccess->canWork($actor, $locked)) {
            throw new DomainException('The ticket is not available for Device context changes.');
        }
        if (! in_array($locked->work_type, ItTicket::INTAKE_WORK_TYPES, true)) {
            throw new DomainException('Manage Device context from the dedicated work-item page.');
        }
        if ($locked->isMerged() || ! in_array($locked->status, ItTicket::OPEN_STATUSES, true)) {
            throw new DomainException('Reopen this ticket before changing its Device context.');
        }

        return $locked;
    }

    private function visibleLockedDevice(User $actor, int $deviceId): Device
    {
        if (! $actor->canDo('securityDevices.devices.view')) {
            throw new DomainException('The selected Device is not available.');
        }

        $device = $this->deviceAccess->visibleDevices($actor)
            ->whereKey($deviceId)
            ->lockForUpdate()
            ->first();
        if (! $device) {
            throw new DomainException('The selected Device is not available.');
        }

        return $device;
    }

    /** @return array<string, mixed> */
    private function auditMeta(ItTicket $ticket, Device $device, User $actor): array
    {
        return [
            'actor_id' => $actor->id,
            'relationship' => 'affected_device',
            'device_id' => $device->id,
            'site_id' => $ticket->site_id,
            'is_organisation_wide' => (bool) $ticket->is_organisation_wide,
            'source' => 'ticket_workspace',
            'application_scope' => 'single_application',
        ];
    }
}
