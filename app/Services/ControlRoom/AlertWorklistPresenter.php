<?php

namespace App\Services\ControlRoom;

use App\Models\ControlRoomAlert;
use App\Models\User;
use Illuminate\Support\Str;

class AlertWorklistPresenter
{
    public function __construct(
        private readonly AlertPriorityService $priority,
        private readonly ControlRoomAlertProvenanceService $provenance,
    ) {}

    /** @return array<string, mixed> */
    public function present(ControlRoomAlert $alert, User $viewer): array
    {
        $alert->loadMissing([
            'site:id,name',
            'client:id,first_name,last_name,site_id,organization_id',
            'client.site:id,name,tenant_id',
            'sla',
            'queue:id,name,tier',
            'clientIncident:id,reference_number,control_room_alert_id,status',
            'hsEvent:id,reference_number,control_room_alert_id,handover_status,status',
        ]);

        $client = $this->provenance->safeClient($alert);
        $assignee = $alert->relationLoaded('assignedTo')
            ? $alert->assignedTo
            : $this->provenance->safeAssignedTo($alert, $viewer);
        $context = $this->provenance->sanitiseContextForRead($alert);
        $site = $alert->site ?? $client?->site;
        $deadline = $this->priority->nextDeadline($alert);
        $slaStatus = $alert->sla?->getStatus();

        return [
            'id' => $alert->id,
            'reference_number' => $alert->reference_number ?: null,
            'summary' => $this->summary($alert, $context),
            'source' => [
                'key' => $alert->source,
                'label' => Str::headline((string) $alert->source),
            ],
            'status' => $alert->status,
            'severity' => $alert->severity,
            'priority' => $this->priority->describe($alert),
            'playbook' => $alert->playbookRun ? [
                'name' => $alert->playbookRun->playbook?->name,
                'status' => $alert->playbookRun->status,
                'completed_steps' => (int) $alert->playbookRun->completed_steps,
                'total_steps' => (int) $alert->playbookRun->total_steps,
            ] : null,
            'triggered_at' => $alert->triggered_at?->toIso8601String(),
            'next_deadline_at' => $deadline?->toIso8601String(),
            'sla' => [
                'status' => $slaStatus,
                'next_deadline_at' => $deadline?->toIso8601String(),
            ],
            'site' => $site ? ['id' => $site->id, 'name' => $site->name] : null,
            'person' => $client ? [
                'id' => $client->id,
                'name' => trim($client->first_name.' '.$client->last_name),
            ] : null,
            'assignee' => $assignee ? ['id' => $assignee->id, 'name' => $assignee->name] : null,
            'queue' => $alert->queue ? ['id' => $alert->queue->id, 'name' => $alert->queue->name] : null,
            'journey' => [
                'incident_reference' => $alert->clientIncident?->reference_number ?: null,
                'health_safety_reference' => $alert->hsEvent?->reference_number ?: null,
                'handover_status' => $alert->hsEvent?->handover_status,
            ],
            'next_action' => $viewer->canDo('controlRoom.alerts.manage')
                ? ['label' => 'Continue response', 'href' => '/control-room/alerts/'.$alert->id]
                : ['label' => 'View alert', 'href' => '/control-room/alerts/'.$alert->id],
            'href' => '/control-room/alerts/'.$alert->id,
        ];
    }

    /** @param array<string, mixed> $context */
    private function summary(ControlRoomAlert $alert, array $context): string
    {
        foreach ([
            data_get($context, 'title'),
            data_get($context, 'summary'),
            data_get($context, 'description'),
            data_get($context, 'normalized_data.title'),
            data_get($context, 'normalized_data.description'),
            $alert->notes,
        ] as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return Str::limit(trim($candidate), 160);
            }
        }

        return Str::headline((string) $alert->alert_type);
    }
}
