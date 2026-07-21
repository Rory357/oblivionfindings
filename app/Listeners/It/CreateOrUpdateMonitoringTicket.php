<?php

namespace App\Listeners\It;

use App\Domain\It\Services\ItTicketLinkService;
use App\Domain\SecurityDevices\Events\DeviceSignalPublished;
use App\Domain\SecurityDevices\Models\Device;
use App\Models\ControlRoomAlert;
use App\Models\ItTicket;
use App\Models\ItTicketEvent;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class CreateOrUpdateMonitoringTicket implements ShouldQueueAfterCommit
{
    public string $queue = 'monitoring';

    private const AUTO_TICKET_DOMAINS = ['it_infrastructure'];

    private const FAILURE_EVENTS = ['offline'];

    private const RECOVERY_EVENTS = ['online'];

    public function __construct(
        private readonly ItTicketLinkService $links,
    ) {}

    public function handle(DeviceSignalPublished $event): void
    {
        if (! in_array($event->device->domain, self::AUTO_TICKET_DOMAINS, true)) {
            return;
        }

        $eventType = $event->originalEventType();

        if (in_array($eventType, self::FAILURE_EVENTS, true)) {
            $this->handleFailure($event);

            return;
        }

        if (in_array($eventType, self::RECOVERY_EVENTS, true)) {
            $this->handleRecovery($event);
        }
    }

    private function handleFailure(DeviceSignalPublished $event): void
    {
        $event->signal->loadMissing(['alert', 'correlatedAlert']);
        $alert = $event->signal->alert ?? $event->signal->correlatedAlert;

        if (! $alert) {
            return;
        }

        DB::transaction(function () use ($event, $alert): void {
            $lockedAlert = ControlRoomAlert::query()->lockForUpdate()->findOrFail($alert->id);
            $siteId = $this->links->canonicalMonitoringSiteId($event->device, $lockedAlert, true);
            if ($siteId === null) {
                return;
            }
            $ticket = $this->ticketForAlert($event->device, $lockedAlert, $siteId);

            if ($ticket) {
                $this->links->linkMonitoringEvidence($ticket, $event->device, $lockedAlert, [
                    'source' => 'oblivion_monitoring',
                ]);
                $ticket->forceFill([
                    'status_reason' => 'monitoring_outage',
                    'monitoring_recovered_at' => null,
                ])->save();

                if (! $this->hasMonitoringEvidence($ticket, $event->deviceEvent->id)) {
                    ItTicketEvent::record($ticket, 'monitoring_evidence_added', null, $this->eventEvidence($event, $lockedAlert));
                }

                return;
            }

            $ticket = ItTicket::createWithReference([
                'tenant_id' => 0,
                'site_id' => $siteId,
                'is_organisation_wide' => false,
                'title' => Str::limit('Monitoring outage: '.$event->device->name, 255, ''),
                'description' => $this->failureDescription($event),
                'requester_user_id' => null,
                'category' => $this->ticketCategory($event->device),
                'subcategory' => $event->device->subcategory,
                'source' => 'system',
                'work_type' => 'incident',
                'priority' => $this->ticketPriority($lockedAlert->severity),
                'impact' => 'site',
                'urgency' => $this->ticketUrgency($lockedAlert->severity),
                'status' => 'open',
                'status_reason' => 'monitoring_outage',
                'requires_approval' => false,
            ]);
            $ticket->stampSlaDueDates();
            $ticket->save();

            $this->links->linkMonitoringEvidence($ticket, $event->device, $lockedAlert, [
                'source' => 'oblivion_monitoring',
            ]);

            ItTicketEvent::record($ticket, 'created_from_monitoring', null, $this->eventEvidence($event, $lockedAlert));
        });
    }

    private function handleRecovery(DeviceSignalPublished $event): void
    {
        DB::transaction(function () use ($event): void {
            $siteId = $this->links->canonicalDeviceSiteId($event->device, true);
            if ($siteId === null) {
                return;
            }

            $tickets = ItTicket::query()
                ->where('source', 'system')
                ->where('work_type', 'incident')
                ->where('site_id', $siteId)
                ->where('is_organisation_wide', false)
                ->whereIn('status', ItTicket::OPEN_STATUSES)
                ->whereHas('links', function ($query) use ($event): void {
                    $query
                        ->where('relationship', 'affected_device')
                        ->where('linkable_type', $event->device->getMorphClass())
                        ->where('linkable_id', $event->device->id)
                        ->where('context->system_principal', ItTicketLinkService::MONITORING_PRINCIPAL)
                        ->where('context->operation', ItTicketLinkService::MONITORING_OPERATION);
                })
                ->whereHas('events', function ($events): void {
                    $events
                        ->where('type', 'created_from_monitoring')
                        ->where('payload->system_principal', ItTicketLinkService::MONITORING_PRINCIPAL)
                        ->where('payload->operation', ItTicketLinkService::MONITORING_OPERATION);
                })
                ->lockForUpdate()
                ->get();

            foreach ($tickets as $ticket) {
                if ($ticket->monitoring_recovered_at !== null) {
                    continue;
                }

                $ticket->forceFill([
                    'status_reason' => 'monitoring_recovered',
                    'monitoring_recovered_at' => $event->deviceEvent->occurred_at ?? now(),
                ])->save();

                ItTicketEvent::record($ticket, 'monitoring_recovered', null, [
                    'device_id' => $event->device->id,
                    'device_event_id' => $event->deviceEvent->id,
                    'signal_id' => $event->signal->id,
                ]);
            }
        });
    }

    private function ticketForAlert(Device $device, ControlRoomAlert $alert, int $siteId): ?ItTicket
    {
        return ItTicket::query()
            ->where('source', 'system')
            ->where('work_type', 'incident')
            ->whereIn('status', ItTicket::OPEN_STATUSES)
            ->where('site_id', $siteId)
            ->where('is_organisation_wide', false)
            ->whereHas('links', function ($query) use ($alert): void {
                $query
                    ->where('relationship', 'source_alert')
                    ->where('linkable_type', $alert->getMorphClass())
                    ->where('linkable_id', $alert->id)
                    ->where('context->system_principal', ItTicketLinkService::MONITORING_PRINCIPAL)
                    ->where('context->operation', ItTicketLinkService::MONITORING_OPERATION);
            })
            ->whereHas('links', function ($query) use ($device): void {
                $query
                    ->where('relationship', 'affected_device')
                    ->where('linkable_type', $device->getMorphClass())
                    ->where('linkable_id', $device->id)
                    ->where('context->system_principal', ItTicketLinkService::MONITORING_PRINCIPAL)
                    ->where('context->operation', ItTicketLinkService::MONITORING_OPERATION);
            })
            ->whereHas('events', function ($events): void {
                $events
                    ->where('type', 'created_from_monitoring')
                    ->where('payload->system_principal', ItTicketLinkService::MONITORING_PRINCIPAL)
                    ->where('payload->operation', ItTicketLinkService::MONITORING_OPERATION);
            })
            ->lockForUpdate()
            ->first();
    }

    private function hasMonitoringEvidence(ItTicket $ticket, int $deviceEventId): bool
    {
        return $ticket->events()
            ->whereIn('type', ['created_from_monitoring', 'monitoring_evidence_added'])
            ->where('payload->device_event_id', $deviceEventId)
            ->exists();
    }

    /**
     * @return array<string, int|string|null>
     */
    private function eventEvidence(DeviceSignalPublished $event, ControlRoomAlert $alert): array
    {
        return [
            'device_id' => $event->device->id,
            'device_event_id' => $event->deviceEvent->id,
            'signal_id' => $event->signal->id,
            'alert_id' => $alert->id,
            'severity' => $alert->severity,
            'message' => data_get($event->deviceEvent->payload, 'message'),
            'system_principal' => ItTicketLinkService::MONITORING_PRINCIPAL,
            'operation' => ItTicketLinkService::MONITORING_OPERATION,
        ];
    }

    private function failureDescription(DeviceSignalPublished $event): string
    {
        $message = trim((string) data_get($event->deviceEvent->payload, 'message'));

        return $message !== ''
            ? $message
            : "Oblivion monitoring confirmed that {$event->device->name} is offline.";
    }

    private function ticketCategory(Device $device): string
    {
        return in_array($device->category, ['hardware', 'network'], true)
            ? $device->category
            : 'other';
    }

    private function ticketPriority(?string $severity): string
    {
        return match ($severity) {
            'critical' => 'urgent',
            'high', 'medium' => 'high',
            'low' => 'normal',
            default => 'low',
        };
    }

    private function ticketUrgency(?string $severity): string
    {
        return match ($severity) {
            'critical' => 'critical',
            'high', 'medium' => 'high',
            'low' => 'normal',
            default => 'low',
        };
    }
}
