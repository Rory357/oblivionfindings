<?php

namespace App\Listeners\It;

use App\Domain\It\Services\ItFleetAvailability;
use App\Domain\It\Services\ItFleetDeliveryService;
use App\Domain\It\Services\ItTicketLinkService;
use App\Domain\It\Services\ItTicketPriorityService;
use App\Domain\It\Services\ItTicketRoutingService;
use App\Domain\Monitoring\Services\MonitoringIncidentEvidenceService;
use App\Domain\Monitoring\Services\MonitoringTechnicalSummary;
use App\Domain\Monitoring\Services\MonitoringWorkRouting;
use App\Models\ControlRoomAlert;
use App\Models\FleetSignal;
use App\Models\ItTicket;
use App\Models\ItTicketEvent;
use App\Services\Fleet\FleetSignalService;
use DomainException;
use Illuminate\Database\Eloquent\Builder;

final class CreateOrUpdateFleetMonitoringTicket
{
    public function __construct(
        private readonly ItFleetDeliveryService $deliveries,
        private readonly ItTicketLinkService $links,
        private readonly ItTicketRoutingService $routing,
        private readonly MonitoringIncidentEvidenceService $evidence,
        private readonly FleetSignalService $fleet,
    ) {}

    public function processOutbox(int $outboxId): void
    {
        $this->deliveries->deliver($outboxId, fn (ItFleetAvailability $source): array => $source->event->signal_type === 'device.offline'
            ? $this->failure($source) : $this->recovery($source));
    }

    /** Called inside the locked source/outbox delivery transaction. */
    private function failure(ItFleetAvailability $source): array
    {
        $alertId = $source->signal->alert_id ?? $source->signal->correlated_alert_id;
        $alert = $alertId ? ControlRoomAlert::query()->whereKey($alertId)->lockForUpdate()->firstOrFail() : null;
        $direct = $alert === null ? MonitoringWorkRouting::directFleetDecision($source->signal, $source->offline) : null;
        if (($alert === null && $direct === null) || $this->links->canonicalFleetSiteId($source->offline, $alert) !== $source->siteId) {
            throw new DomainException('technical_routing_unavailable');
        }
        $ticket = $this->tickets($source)->lockForUpdate()->first();
        $origin = [
            'source' => 'fleet', 'fleet_signal_id' => (int) $source->offline->id,
            'signal_id' => (int) $source->signal->id, 'control_room_alert_id' => $alert?->id,
            'availability_episode_key' => $source->offline->idempotency_key,
            'system_principal' => ItTicketLinkService::MONITORING_PRINCIPAL,
            'operation' => ItTicketLinkService::MONITORING_OPERATION,
        ];
        if ($ticket === null && $alert !== null) {
            $ticket = $this->links->unboundHumanMonitoringTicket($alert);
            if ($ticket !== null) {
                ItTicketEvent::record($ticket, 'monitoring_handoff_bound', null, $origin);
            }
        }
        $created = $ticket === null;
        if ($ticket === null) {
            $severity = $alert?->severity ?? $direct['severity'] ?? null;
            $assessment = app(ItTicketPriorityService::class)->decide([
                'impact' => $alert ? 'site' : 'individual',
                'urgency' => match ($severity) {
                    'critical' => 'critical', 'high' => 'high', 'low', 'info' => 'low', default => 'normal',
                },
            ]);
            $ticket = ItTicket::createWithReference([
                'site_id' => $source->siteId, 'is_organisation_wide' => false,
                'title' => 'Fleet device availability',
                'description' => MonitoringTechnicalSummary::observation('device.offline'),
                'requester_user_id' => null, 'category' => 'hardware', 'subcategory' => null,
                'source' => 'system', 'work_type' => 'incident', ...$assessment,
                'status' => 'open', 'status_reason' => 'monitoring_outage', 'requires_approval' => false,
            ]);
            $ticket->stampSlaDueDates();
            $ticket->save();
        }
        $this->links->linkFleetMonitoringEvidence($ticket, $source->offline, $alert);
        $this->evidence->captureFleetIfMissing($ticket, $source->offline, $alert);
        if ($created) {
            ItTicketEvent::record($ticket, 'created_from_monitoring', null, $origin);
            $this->routing->route($ticket);
        }
        $recovery = $this->fleet->recoveryFor($source->offline);
        if ($recovery !== null) {
            $this->recordRecovery($ticket, $source->offline, $recovery);
        }

        return ['outcome' => $created ? 'ticket_created' : 'ticket_updated', 'ticket_ids' => [(int) $ticket->id]];
    }

    private function recovery(ItFleetAvailability $source): array
    {
        $tickets = $this->tickets($source)->whereIn('status', ItTicket::OPEN_STATUSES)->lockForUpdate()->get();
        foreach ($tickets as $ticket) {
            $this->recordRecovery($ticket, $source->offline, $source->event);
        }

        return ['outcome' => $tickets->isEmpty() ? 'recovery_unmatched' : 'recovery_recorded', 'ticket_ids' => $tickets->modelKeys()];
    }

    private function tickets(ItFleetAvailability $source): Builder
    {
        return $this->links->monitoringTickets()->where('work_type', 'incident')
            ->where('site_id', $source->siteId)->where('is_organisation_wide', false)
            ->whereHas('events', fn ($query) => $query->whereIn('type', ItTicketLinkService::MONITORING_ORIGIN_EVENTS)
                ->where('payload->source', 'fleet')->where('payload->fleet_signal_id', $source->offline->id)
                ->where('payload->availability_episode_key', $source->offline->idempotency_key)
                ->where('payload->system_principal', ItTicketLinkService::MONITORING_PRINCIPAL)
                ->where('payload->operation', ItTicketLinkService::MONITORING_OPERATION))
            ->whereHas('links', fn ($query) => $query->where('relationship', 'affected_device')
                ->where('linkable_type', $source->offline->device->getMorphClass())->where('linkable_id', $source->offline->device_id)
                ->where('context->system_principal', ItTicketLinkService::MONITORING_PRINCIPAL)
                ->where('context->operation', ItTicketLinkService::MONITORING_OPERATION))
            ->orderBy('id');
    }

    private function recordRecovery(ItTicket $ticket, FleetSignal $offline, FleetSignal $recovery): void
    {
        if ($ticket->monitoring_recovered_at !== null || ! in_array($ticket->status, ItTicket::OPEN_STATUSES, true)) {
            return;
        }
        $ticket->forceFill(['status_reason' => 'monitoring_recovered', 'monitoring_recovered_at' => $recovery->occurred_at])->save();
        ItTicketEvent::record($ticket, 'monitoring_recovered', null, [
            'source' => 'fleet', 'fleet_signal_id' => (int) $recovery->id,
            'offline_fleet_signal_id' => (int) $offline->id, 'availability_episode_key' => $offline->idempotency_key,
        ]);
    }
}
