<?php

namespace App\Domain\Monitoring\Presenters;

use App\Domain\It\Services\ItTicketLinkService;
use App\Domain\It\Services\ItWorkAccessService;
use App\Domain\Monitoring\Models\MonitoringIncidentEvidenceSnapshot;
use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Models\ControlRoomAlert;
use App\Models\ItTicket;
use App\Models\User;
use App\Services\UserSiteAccessService;
use Illuminate\Support\Collection;

final class MonitoringIncidentEvidencePresenter
{
    public function __construct(
        private readonly ItWorkAccessService $workAccess,
        private readonly SecurityDevicesAccessService $deviceAccess,
        private readonly UserSiteAccessService $siteAccess,
    ) {}

    /** @return array<int, array<string, mixed>> */
    public function forTicket(ItTicket $ticket, User $viewer): array
    {
        if (! $this->workAccess->canView($viewer, $ticket)) {
            return [];
        }

        return MonitoringIncidentEvidenceSnapshot::query()
            ->where('it_ticket_id', $ticket->id)
            ->with(['alert', 'device'])
            ->get()
            ->map(fn (MonitoringIncidentEvidenceSnapshot $snapshot): ?array => $this->presentEvidence($snapshot, $viewer))
            ->filter()
            ->values()
            ->all();
    }

    /** @return array{linked_it_work: array<string, mixed>|null, incident_evidence: array<string, mixed>|null} */
    public function forAlert(ControlRoomAlert $alert, User $viewer): array
    {
        if (! $this->canViewAlert($alert, $viewer)) {
            return ['linked_it_work' => null, 'incident_evidence' => null];
        }

        $tickets = ItTicket::query()
            ->whereHas('links', function ($links) use ($alert): void {
                $links
                    ->where('relationship', 'source_alert')
                    ->where('linkable_type', $alert->getMorphClass())
                    ->where('linkable_id', $alert->id)
                    ->where('context->system_principal', ItTicketLinkService::MONITORING_PRINCIPAL)
                    ->where('context->operation', ItTicketLinkService::MONITORING_OPERATION);
            })
            ->with('assignee:id,name')
            ->latest('id')
            ->limit(20)
            ->get()
            ->filter(fn (ItTicket $ticket): bool => $this->ticketMatchesAlertSite($ticket, $alert));

        $ticket = $this->preferredTicket(
            $tickets->filter(fn (ItTicket $candidate): bool => $this->workAccess->canView($viewer, $candidate)),
        );
        if (! $ticket) {
            if (! $viewer->canDo('it.view') && $tickets->isNotEmpty()) {
                return [
                    'linked_it_work' => $this->restrictedItWork(),
                    'incident_evidence' => null,
                ];
            }

            return ['linked_it_work' => null, 'incident_evidence' => null];
        }

        $snapshot = MonitoringIncidentEvidenceSnapshot::query()
            ->where('it_ticket_id', $ticket->id)
            ->where('control_room_alert_id', $alert->id)
            ->with(['alert', 'device'])
            ->first();

        return [
            'linked_it_work' => [
                'id' => $ticket->id,
                'reference' => $ticket->reference,
                'title' => $ticket->title,
                'status' => $ticket->status,
                'status_reason' => $ticket->status_reason,
                'priority' => $ticket->priority,
                'sla_state' => $ticket->sla_state,
                'resolution_due_at' => $ticket->resolution_due_at?->toIso8601String(),
                'monitoring_recovered_at' => $ticket->monitoring_recovered_at?->toIso8601String(),
                'assignee' => $ticket->assignee ? [
                    'id' => $ticket->assignee->id,
                    'name' => $ticket->assignee->name,
                ] : null,
                'href' => route('it.tickets.show', $ticket),
                'access' => [
                    'state' => 'available',
                    'message' => null,
                ],
            ],
            'incident_evidence' => $snapshot ? $this->presentEvidence($snapshot, $viewer) : null,
        ];
    }

    /** @param Collection<int, ItTicket> $tickets */
    private function preferredTicket(Collection $tickets): ?ItTicket
    {
        return $tickets->first(fn (ItTicket $ticket): bool => in_array($ticket->status, ItTicket::OPEN_STATUSES, true))
            ?? $tickets->first();
    }

    private function ticketMatchesAlertSite(ItTicket $ticket, ControlRoomAlert $alert): bool
    {
        return is_numeric($ticket->site_id)
            && (int) $ticket->site_id > 0
            && is_numeric($alert->site_id)
            && (int) $alert->site_id > 0
            && (int) $ticket->site_id === (int) $alert->site_id;
    }

    /** @return array<string, mixed> */
    private function restrictedItWork(): array
    {
        return [
            'id' => null,
            'reference' => null,
            'title' => null,
            'status' => null,
            'status_reason' => null,
            'priority' => null,
            'sla_state' => null,
            'resolution_due_at' => null,
            'monitoring_recovered_at' => null,
            'assignee' => null,
            'href' => null,
            'access' => [
                'state' => 'restricted',
                'message' => 'IT workspace access is required to open this record.',
            ],
        ];
    }

    /** @return array<string, mixed>|null */
    private function presentEvidence(MonitoringIncidentEvidenceSnapshot $snapshot, User $viewer): ?array
    {
        if (! $snapshot->hasValidChecksum()
            || ! $snapshot->alert
            || ! $snapshot->device
            || ! $viewer->canDo('controlRoom.alerts.view')
            || ! $viewer->canDo('securityDevices.devices.view')
            || ! $this->canViewAlert($snapshot->alert, $viewer)
            || ! $this->deviceAccess->visibleDevices($viewer)->whereKey($snapshot->device_id)->exists()) {
            return null;
        }

        $source = is_array($snapshot->snapshot) ? $snapshot->snapshot : [];

        return [
            'id' => $snapshot->id,
            'version' => $snapshot->evidence_version,
            'captured_at' => $snapshot->captured_at?->toIso8601String(),
            'checksum' => $snapshot->checksum,
            'integrity' => 'verified',
            'site' => $this->allow($source['site'] ?? [], ['id', 'name']),
            'alert' => $this->allow($source['alert'] ?? [], ['id', 'reference', 'type', 'severity', 'source', 'triggered_at']),
            'ticket' => $this->allow($source['ticket'] ?? [], ['id', 'reference', 'title']),
            'device' => $this->allow($source['device'] ?? [], ['id', 'uid', 'name', 'domain', 'category', 'subcategory', 'status', 'health_status', 'last_seen_at']),
            'observation' => $this->allow($source['observation'] ?? [], ['id', 'event_type', 'severity', 'source', 'occurred_at', 'message', 'monitor_correlation_key']),
        ];
    }

    private function canViewAlert(ControlRoomAlert $alert, User $viewer): bool
    {
        $query = ControlRoomAlert::query()->whereKey($alert->id);
        $this->siteAccess->applyAlertScope($query, $viewer);

        return $query->exists();
    }

    /** @param array<int, string> $keys
     * @return array<string, mixed>
     */
    private function allow(mixed $source, array $keys): array
    {
        if (! is_array($source)) {
            return [];
        }

        return collect($source)->only($keys)->all();
    }
}
