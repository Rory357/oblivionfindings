<?php

namespace App\Listeners\It;

use App\Domain\It\Services\ItMonitoringDeliveryService;
use App\Domain\It\Services\ItTicketLinkService;
use App\Domain\It\Services\ItTicketPriorityService;
use App\Domain\It\Services\ItTicketRoutingService;
use App\Domain\Monitoring\Services\MonitoringAvailabilityEpisode;
use App\Domain\Monitoring\Services\MonitoringIncidentEvidenceService;
use App\Domain\Monitoring\Services\MonitoringTechnicalSummary;
use App\Domain\Monitoring\Services\MonitoringWorkRouting;
use App\Domain\SecurityDevices\Events\DeviceSignalPublished;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceEventSignalOutbox;
use App\Models\ControlRoomAlert;
use App\Models\ItTicket;
use App\Models\ItTicketEvent;
use DomainException;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class CreateOrUpdateMonitoringTicket implements ShouldQueueAfterCommit
{
    public string $queue = 'monitoring';

    public function __construct(
        private readonly ItTicketLinkService $links,
        private readonly MonitoringIncidentEvidenceService $incidentEvidence,
        private readonly ItTicketRoutingService $routing,
        private readonly ItMonitoringDeliveryService $deliveries,
    ) {}

    public function handle(DeviceSignalPublished $event): void
    {
        $outbox = DeviceEventSignalOutbox::query()->where('device_event_id', $event->deviceEvent->id)->first();
        // Pre-contract queued events have no trustworthy outcome. The existing
        // recovery report identifies them as legacy_unverified; never recreate work blindly.
        if (! $outbox || $outbox->it_status === null) {
            return;
        }
        if ((int) $outbox->it_signal_id !== (int) $event->signal->id) {
            throw new DomainException('Monitoring delivery signal does not match its recorded intent.');
        }
        $this->processOutbox((int) $outbox->id);
    }

    public function processOutbox(int $outboxId): void
    {
        $this->deliveries->deliver($outboxId, fn (DeviceSignalPublished $event): array => $event->originalEventType() === 'offline'
            ? $this->handleFailure($event)
            : $this->handleRecovery($event));
    }

    /** @return array{outcome: string, ticket_ids: list<int>} */
    private function handleFailure(DeviceSignalPublished $event): array
    {
        $event->signal->loadMissing(['alert', 'correlatedAlert']);
        $alert = $event->signal->alert ?? $event->signal->correlatedAlert;

        $direct = $alert === null ? MonitoringWorkRouting::directDecision($event->signal, $event->deviceEvent) : null;
        if (! $alert && $direct === null) {
            throw new DomainException('technical_routing_unavailable');
        }

        return DB::transaction(function () use ($event, $alert, $direct): array {
            $lockedAlert = $alert ? ControlRoomAlert::query()->lockForUpdate()->findOrFail($alert->id) : null;
            $siteId = $lockedAlert ? $this->links->canonicalMonitoringSiteId($event->device, $lockedAlert, true)
                : $this->links->canonicalDeviceSiteId($event->device, true);
            if ($siteId === null) {
                throw new DomainException('source_scope_changed');
            }
            $episode = MonitoringAvailabilityEpisode::fromEvent($event->deviceEvent, $siteId);
            if (data_get($event->deviceEvent->payload, 'availability_episode_version') === 1 && $episode === null) {
                throw new DomainException('source_scope_changed');
            }
            $ticket = $this->ticketForAlert($event->device, $lockedAlert, $siteId, $episode['key'] ?? null);

            if ($ticket) {
                $this->links->linkMonitoringEvidence($ticket, $event->device, $lockedAlert, [
                    'source' => 'oblivion_monitoring',
                    'monitor_correlation_key' => $this->monitorCorrelationKey($event),
                ], $event->deviceEvent);
                $this->incidentEvidence->captureIfMissing(
                    $ticket,
                    $event->device,
                    $lockedAlert,
                    $event->deviceEvent,
                    $this->monitorCorrelationKey($event),
                );
                if (in_array($ticket->status, ItTicket::OPEN_STATUSES, true)
                    && ($episode === null || $ticket->monitoring_recovered_at === null)) {
                    $ticket->forceFill([
                        'status_reason' => 'monitoring_outage',
                        'monitoring_recovered_at' => null,
                    ])->save();
                }

                if (! $this->hasMonitoringEvidence($ticket, $event->deviceEvent->id)) {
                    ItTicketEvent::record($ticket, 'monitoring_evidence_added', null, $this->eventEvidence($event, $lockedAlert));
                }

                if (in_array($ticket->status, ItTicket::OPEN_STATUSES, true)) {
                    $this->applyObservedRecovery($ticket, $event);
                }

                return ['outcome' => 'ticket_updated', 'ticket_ids' => [(int) $ticket->id]];
            }

            $assessment = $direct !== null ? app(ItTicketPriorityService::class)->decide([
                'impact' => 'individual',
                'urgency' => match ($direct['severity'] ?? null) {
                    'critical' => 'critical', 'high' => 'high', 'low', 'info' => 'low', default => 'normal',
                },
            ]) : [
                'priority' => $this->ticketPriority($lockedAlert->severity),
                'impact' => 'site', 'urgency' => $this->ticketUrgency($lockedAlert->severity),
            ];
            $ticket = ItTicket::createWithReference([
                'site_id' => $siteId,
                'is_organisation_wide' => false,
                'title' => Str::limit('Monitoring outage: '.$event->device->name, 255, ''),
                'description' => $this->failureDescription($event),
                'requester_user_id' => null,
                'category' => $this->ticketCategory($event->device),
                'subcategory' => $event->device->subcategory,
                'source' => 'system',
                'work_type' => 'incident',
                ...$assessment,
                'status' => 'open',
                'status_reason' => 'monitoring_outage',
                'requires_approval' => false,
            ]);
            $ticket->stampSlaDueDates();
            $ticket->save();

            $this->links->linkMonitoringEvidence($ticket, $event->device, $lockedAlert, [
                'source' => 'oblivion_monitoring',
                'monitor_correlation_key' => $this->monitorCorrelationKey($event),
            ], $event->deviceEvent);
            $this->incidentEvidence->captureIfMissing(
                $ticket,
                $event->device,
                $lockedAlert,
                $event->deviceEvent,
                $this->monitorCorrelationKey($event),
            );

            ItTicketEvent::record($ticket, 'created_from_monitoring', null, $this->eventEvidence($event, $lockedAlert));
            $this->routing->route($ticket);
            $this->applyObservedRecovery($ticket, $event);

            return ['outcome' => 'ticket_created', 'ticket_ids' => [(int) $ticket->id]];
        });
    }

    /** @return array{outcome: string, ticket_ids: list<int>} */
    private function handleRecovery(DeviceSignalPublished $event): array
    {
        return DB::transaction(function () use ($event): array {
            $siteId = $this->links->canonicalDeviceSiteId($event->device, true);
            if ($siteId === null) {
                throw new DomainException('source_scope_changed');
            }
            $correlationKey = $this->monitorCorrelationKey($event);
            $nativeEpisode = data_get($event->deviceEvent->payload, 'availability_episode_version') === 1;
            $episode = MonitoringAvailabilityEpisode::fromEvent($event->deviceEvent, $siteId);
            if ($nativeEpisode && $episode === null) {
                return ['outcome' => 'recovery_unmatched', 'ticket_ids' => []];
            }
            $legacyRecovery = data_get($event->deviceEvent->payload, 'legacy_monitoring_recovery') === true;
            if ($correlationKey === null && ! $legacyRecovery && ! $nativeEpisode) {
                return ['outcome' => 'recovery_unmatched', 'ticket_ids' => []];
            }

            $ticketsQuery = ItTicket::query()
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
                });

            if ($nativeEpisode) {
                $ticketsQuery->whereHas('events', fn ($events) => $events->where('type', 'created_from_monitoring')
                    ->where('payload->availability_episode_key', $episode['key']));
            } elseif ($correlationKey !== null) {
                $ticketsQuery->whereHas('events', function ($events) use ($correlationKey): void {
                    $events
                        ->where('type', 'created_from_monitoring')
                        ->where('payload->monitor_correlation_key', $correlationKey);
                });
            } else {
                $ticketsQuery->whereHas('events', function ($events): void {
                    $events
                        ->where('type', 'created_from_monitoring')
                        ->whereNull('payload->monitor_correlation_key');
                });
            }
            if (! $nativeEpisode) {
                $ticketsQuery->whereHas('events', fn ($events) => $events->where('type', 'created_from_monitoring')
                    ->whereNull('payload->availability_episode_key'));
            }

            $tickets = $ticketsQuery->lockForUpdate()->get();

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
                    'monitor_correlation_key' => $correlationKey,
                    'availability_episode_key' => $episode['key'] ?? null,
                ]);
            }

            return ['outcome' => $tickets->isEmpty() ? 'recovery_unmatched' : 'recovery_recorded',
                'ticket_ids' => $tickets->modelKeys()];
        });
    }

    private function ticketForAlert(Device $device, ?ControlRoomAlert $alert, int $siteId, ?string $episodeKey): ?ItTicket
    {
        return ItTicket::query()
            ->where('source', 'system')
            ->where('work_type', 'incident')
            ->when($episodeKey === null, fn ($tickets) => $tickets->whereIn('status', ItTicket::OPEN_STATUSES))
            ->where('site_id', $siteId)
            ->where('is_organisation_wide', false)
            ->when($episodeKey === null, fn ($tickets) => $tickets->whereHas('links', function ($query) use ($alert): void {
                $query
                    ->where('relationship', 'source_alert')
                    ->where('linkable_type', $alert->getMorphClass())
                    ->where('linkable_id', $alert->id)
                    ->where('context->system_principal', ItTicketLinkService::MONITORING_PRINCIPAL)
                    ->where('context->operation', ItTicketLinkService::MONITORING_OPERATION);
            }))
            ->when($episodeKey !== null, fn ($tickets) => $tickets->whereHas('events', fn ($events) => $events
                ->where('type', 'created_from_monitoring')->where('payload->availability_episode_key', $episodeKey)))
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

    private function applyObservedRecovery(ItTicket $ticket, DeviceSignalPublished $failure): void
    {
        $recovery = MonitoringAvailabilityEpisode::recoveryFor($failure->deviceEvent, (int) $ticket->site_id);
        if ($recovery === null || $ticket->monitoring_recovered_at !== null) {
            return;
        }
        $ticket->forceFill(['status_reason' => 'monitoring_recovered', 'monitoring_recovered_at' => $recovery->occurred_at])->save();
        ItTicketEvent::record($ticket, 'monitoring_recovered', null, [
            'device_id' => $recovery->device_id, 'device_event_id' => $recovery->id,
            'signal_id' => $recovery->signalOutbox?->it_signal_id,
            'monitor_correlation_key' => data_get($recovery->payload, 'monitor_correlation_key'),
            'availability_episode_key' => MonitoringAvailabilityEpisode::fromEvent($recovery, (int) $ticket->site_id)['key'] ?? null,
        ]);
    }

    /**
     * @return array<string, int|string|null>
     */
    private function eventEvidence(DeviceSignalPublished $event, ?ControlRoomAlert $alert): array
    {
        return [
            'device_id' => $event->device->id,
            'device_event_id' => $event->deviceEvent->id,
            'signal_id' => $event->signal->id,
            'alert_id' => $alert?->id,
            'severity' => $alert?->severity ?? data_get($event->signal->normalized_data, 'it_work_routing.severity'),
            'message' => MonitoringTechnicalSummary::observation($event->originalEventType()),
            'monitor_correlation_key' => $this->monitorCorrelationKey($event),
            'availability_episode_key' => MonitoringAvailabilityEpisode::fromEvent($event->deviceEvent, (int) $event->signal->site_id)['key'] ?? null,
            'system_principal' => ItTicketLinkService::MONITORING_PRINCIPAL,
            'operation' => ItTicketLinkService::MONITORING_OPERATION,
        ];
    }

    private function monitorCorrelationKey(DeviceSignalPublished $event): ?string
    {
        $key = data_get($event->deviceEvent->payload, 'monitor_correlation_key');

        return is_string($key) && preg_match('/\A[a-f0-9]{64}\z/', $key) === 1
            ? $key
            : null;
    }

    private function failureDescription(DeviceSignalPublished $event): string
    {
        return MonitoringTechnicalSummary::observation($event->originalEventType());
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
