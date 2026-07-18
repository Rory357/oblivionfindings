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
            $ticket = $this->ticketForAlert($event->device, $lockedAlert);

            if ($ticket) {
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
                'tenant_id' => $event->device->tenant_id,
                'title' => Str::limit('Monitoring outage: '.$event->device->name, 255, ''),
                'description' => $this->failureDescription($event),
                'requester_user_id' => null,
                'category' => $this->ticketCategory($event->device),
                'subcategory' => $event->device->subcategory,
                'source' => 'system',
                'work_type' => 'incident',
                'priority' => $this->ticketPriority($lockedAlert->severity),
                'impact' => $lockedAlert->site_id ? 'site' : 'individual',
                'urgency' => $this->ticketUrgency($lockedAlert->severity),
                'status' => 'open',
                'status_reason' => 'monitoring_outage',
                'requires_approval' => false,
            ]);
            $ticket->stampSlaDueDates();
            $ticket->save();

            $this->links->link($ticket, $event->device, 'affected_device', [
                'source' => 'oblivion_monitoring',
            ]);
            $this->links->link($ticket, $lockedAlert, 'source_alert', [
                'source' => 'oblivion_monitoring',
            ]);

            ItTicketEvent::record($ticket, 'created_from_monitoring', null, $this->eventEvidence($event, $lockedAlert));
        });
    }

    private function handleRecovery(DeviceSignalPublished $event): void
    {
        DB::transaction(function () use ($event): void {
            $tickets = ItTicket::query()
                ->forTenant((int) $event->device->tenant_id)
                ->where('source', 'system')
                ->where('work_type', 'incident')
                ->whereIn('status', ItTicket::OPEN_STATUSES)
                ->whereHas('links', function ($query) use ($event): void {
                    $query
                        ->where('relationship', 'affected_device')
                        ->where('linkable_type', $event->device->getMorphClass())
                        ->where('linkable_id', $event->device->id);
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

    private function ticketForAlert(Device $device, ControlRoomAlert $alert): ?ItTicket
    {
        return ItTicket::query()
            ->forTenant((int) $device->tenant_id)
            ->whereHas('links', function ($query) use ($alert): void {
                $query
                    ->where('relationship', 'source_alert')
                    ->where('linkable_type', $alert->getMorphClass())
                    ->where('linkable_id', $alert->id);
            })
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
