<?php

namespace App\Services\Incidents;

use App\Models\ClientIncident;
use App\Models\ControlRoomAlert;
use App\Models\HsEvent;
use App\Models\User;

class IncidentJourneyPresenter
{
    /** @return array<string, mixed> */
    public function present(IncidentJourney $journey, User $viewer): array
    {
        $incident = $journey->incident;
        $alert = $journey->alert;
        $hsEvent = $journey->hsEvent;

        $incident->loadMissing([
            'site:id,name',
            'client:id,first_name,last_name,site_id,organization_id',
            'client.site:id,name',
            'shift:id,site_id',
            'reporter:id,name',
            'attachments:id,incident_id,original_name',
            'followups:id,client_incident_id,notes,due_at,completed_at,assigned_to_user_id',
        ]);
        $incident->shift?->loadMissing('site:id,name');

        $alert?->loadMissing([
            'sla',
            'playbookRun:id,playbook_id,status,current_step,completed_steps,total_steps',
            'playbookRun.playbook:id,name,code',
            'tasks:id,alert_id,title,status,priority,due_at,assigned_to_user_id',
            'tasks.assignedTo:id,name',
            'evidencePacks:id,alert_id,title,status,item_count',
            'communications:id,alert_id,subject,status,sent_at,delivered_at',
        ]);
        $hsEvent?->loadMissing([
            'site:id,name',
            'owner:id,name',
            'acceptedBy:id,name',
            'activeInvestigation:hs_investigations.id,hs_investigations.hs_event_id,hs_investigations.reference_number,hs_investigations.status,hs_investigations.lead_investigator_id,hs_investigations.target_completion_date',
            'activeInvestigation.leadInvestigator:id,name',
        ]);

        $site = $incident->site ?? $hsEvent?->site ?? $incident->shift?->site ?? $incident->client?->site;
        $canViewPerson = $this->canViewPerson($viewer);
        $canOpenAlert = $alert !== null && $this->canOpenAlert($viewer);
        $canOpenIncident = $this->canOpenIncident($viewer);
        $canOpenHs = $hsEvent !== null && $viewer->canDo('hazards.view');

        return [
            'references' => [
                'control_room' => $alert?->reference_number ?: null,
                'incident' => $incident->reference_number ?: null,
                'health_safety' => $hsEvent?->reference_number ?: null,
            ],
            'occurred_at' => $incident->occurred_at?->toIso8601String(),
            'incident' => [
                'id' => $incident->id,
                'reference_number' => $incident->reference_number ?: null,
                'status' => $incident->status,
                'severity' => $incident->severity,
                'type' => $incident->type,
                'site' => $site ? ['id' => $site->id, 'name' => $site->name] : null,
                'person' => $canViewPerson && $incident->client ? [
                    'id' => $incident->client->id,
                    'name' => trim($incident->client->first_name.' '.$incident->client->last_name),
                ] : null,
                'reporter' => $incident->reporter ? [
                    'id' => $incident->reporter->id,
                    'name' => $incident->reporter->name,
                ] : null,
                'narrative' => [
                    'title' => $incident->title,
                    'description' => $incident->description,
                    'location' => $incident->location,
                    'immediate_controls' => $incident->immediate_action_taken ?: $incident->immediate_action,
                    'witnesses' => $incident->witnesses,
                    'potential_severity' => $incident->potential_severity,
                    'potential_consequence' => $incident->potential_consequence,
                ],
                'attachments' => $incident->attachments->map(fn ($attachment) => [
                    'id' => $attachment->id,
                    'name' => $attachment->original_name,
                ])->values()->all(),
                'followups' => $incident->followups->map(fn ($followup) => [
                    'id' => $followup->id,
                    'title' => $followup->notes,
                    'status' => $followup->completed_at ? 'complete' : 'open',
                    'due_at' => $followup->due_at?->toIso8601String(),
                ])->values()->all(),
                'href' => $canOpenIncident ? '/incidents?incident='.$incident->id : null,
            ],
            'control_room' => $this->presentAlert($alert, $canOpenAlert),
            'health_safety' => $this->presentHealthSafety($hsEvent, $canOpenHs),
            'lifecycle' => [
                $this->stage('control_room', 'Control Room', $alert?->reference_number, $this->alertState($alert), $canOpenAlert ? '/control-room/alerts/'.$alert->id : null),
                $this->stage('incident', 'Incident report', $incident->reference_number, $this->incidentState($incident), $canOpenIncident ? '/incidents?incident='.$incident->id : null),
                $this->stage('health_safety', 'Health & Safety', $hsEvent?->reference_number, $this->healthSafetyState($hsEvent), $canOpenHs ? '/health-safety/events/'.$hsEvent->id : null),
            ],
            'next_action' => $this->nextAction($incident, $alert, $hsEvent, $viewer),
        ];
    }

    /** @return array<string, mixed>|null */
    private function presentAlert(?ControlRoomAlert $alert, bool $canOpen): ?array
    {
        if ($alert === null) {
            return null;
        }

        return [
            'id' => $alert->id,
            'reference_number' => $alert->reference_number ?: null,
            'status' => $alert->status,
            'severity' => $alert->severity,
            'notes' => $alert->notes,
            'sla_status' => $alert->sla?->getStatus(),
            'playbook' => $alert->playbookRun ? [
                'name' => $alert->playbookRun->playbook?->name,
                'status' => $alert->playbookRun->status,
                'current_step' => $alert->playbookRun->current_step,
                'completed_steps' => $alert->playbookRun->completed_steps,
                'total_steps' => $alert->playbookRun->total_steps,
            ] : null,
            'tasks' => $alert->tasks->map(fn ($task) => [
                'id' => $task->id,
                'title' => $task->title,
                'status' => $task->status,
                'priority' => $task->priority,
                'due_at' => $task->due_at?->toIso8601String(),
                'owner' => $task->assignedTo ? ['id' => $task->assignedTo->id, 'name' => $task->assignedTo->name] : null,
            ])->values()->all(),
            'evidence' => $alert->evidencePacks->map(fn ($pack) => [
                'id' => $pack->id,
                'title' => $pack->title,
                'status' => $pack->status,
                'item_count' => (int) $pack->item_count,
            ])->values()->all(),
            'communications' => $alert->communications->map(fn ($communication) => [
                'id' => $communication->id,
                'subject' => $communication->subject,
                'status' => $communication->status,
                'sent_at' => $communication->sent_at?->toIso8601String(),
            ])->values()->all(),
            'href' => $canOpen ? '/control-room/alerts/'.$alert->id : null,
        ];
    }

    /** @return array<string, mixed>|null */
    private function presentHealthSafety(?HsEvent $event, bool $canOpen): ?array
    {
        if ($event === null) {
            return null;
        }

        return [
            'id' => $event->id,
            'reference_number' => $event->reference_number ?: null,
            'status' => $event->status,
            'severity' => $event->severity,
            'handover' => [
                'status' => $event->handover_status,
                'owner' => $event->owner ? ['id' => $event->owner->id, 'name' => $event->owner->name] : null,
                'accepted_by' => $event->acceptedBy ? ['id' => $event->acceptedBy->id, 'name' => $event->acceptedBy->name] : null,
                'accepted_at' => $event->accepted_at?->toIso8601String(),
                'notes' => $event->acceptance_notes,
            ],
            'worksafe' => [
                'notifiable' => (bool) $event->worksafe_notifiable,
                'status' => $event->worksafe_status,
                'reference' => $event->worksafe_reference,
                'notified_at' => $event->worksafe_notified_at?->toIso8601String(),
                'acknowledged_at' => $event->worksafe_acknowledged_at?->toIso8601String(),
            ],
            'investigation' => $event->activeInvestigation ? [
                'reference_number' => $event->activeInvestigation->reference_number,
                'status' => $event->activeInvestigation->status,
                'lead' => $event->activeInvestigation->leadInvestigator ? [
                    'id' => $event->activeInvestigation->leadInvestigator->id,
                    'name' => $event->activeInvestigation->leadInvestigator->name,
                ] : null,
                'due_at' => $event->activeInvestigation->target_completion_date?->toIso8601String(),
            ] : null,
            'href' => $canOpen ? '/health-safety/events/'.$event->id : null,
        ];
    }

    /** @return array{key: string, label: string, reference_number: ?string, state: string, href: ?string} */
    private function stage(string $key, string $label, ?string $reference, string $state, ?string $href): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'reference_number' => $reference ?: null,
            'state' => $state,
            'href' => $href,
        ];
    }

    private function alertState(?ControlRoomAlert $alert): string
    {
        if ($alert === null) {
            return 'not_started';
        }

        return $alert->isTerminal() ? 'complete' : 'in_progress';
    }

    private function incidentState(ClientIncident $incident): string
    {
        return $incident->status === 'draft' ? 'in_progress' : 'complete';
    }

    private function healthSafetyState(?HsEvent $event): string
    {
        if ($event === null) {
            return 'not_started';
        }
        if ($event->handover_status === HsEvent::HANDOVER_AWAITING_ACCEPTANCE) {
            return 'waiting';
        }

        return $event->status === HsEvent::STATUS_CLOSED ? 'complete' : 'in_progress';
    }

    /** @return array{label: string, href: ?string, stage: string} */
    private function nextAction(ClientIncident $incident, ?ControlRoomAlert $alert, ?HsEvent $event, User $viewer): array
    {
        if ($incident->status === 'draft' && $viewer->canDo('incidents.create')) {
            return ['label' => 'Finish incident report', 'href' => '/incidents?incident='.$incident->id, 'stage' => 'incident'];
        }

        if ($event?->handover_status === HsEvent::HANDOVER_AWAITING_ACCEPTANCE && $viewer->canDo('hazards.manage')) {
            return ['label' => 'Accept H&S handover', 'href' => '/health-safety/events/'.$event->id, 'stage' => 'health_safety'];
        }

        if ($alert?->isActionable() && $viewer->canDo('controlRoom.alerts.manage')) {
            return ['label' => 'Continue Control Room response', 'href' => '/control-room/alerts/'.$alert->id, 'stage' => 'control_room'];
        }

        if ($event?->isOpen() && $viewer->canDo('hazards.manage')) {
            return ['label' => 'Continue H&S work', 'href' => '/health-safety/events/'.$event->id, 'stage' => 'health_safety'];
        }

        if ($this->canOpenIncident($viewer)) {
            return ['label' => 'View incident', 'href' => '/incidents?incident='.$incident->id, 'stage' => 'incident'];
        }

        if ($alert !== null && $this->canOpenAlert($viewer)) {
            return ['label' => 'View alert', 'href' => '/control-room/alerts/'.$alert->id, 'stage' => 'control_room'];
        }

        if ($event !== null && $viewer->canDo('hazards.view')) {
            return ['label' => 'View H&S event', 'href' => '/health-safety/events/'.$event->id, 'stage' => 'health_safety'];
        }

        return ['label' => 'No action available', 'href' => null, 'stage' => 'complete'];
    }

    private function canOpenAlert(User $viewer): bool
    {
        return $viewer->canDo('controlRoom.viewAny')
            || $viewer->canDo('controlRoom.alerts.view')
            || $viewer->canDo('controlRoom.alerts.manage');
    }

    private function canOpenIncident(User $viewer): bool
    {
        return $viewer->canDo('incidents.viewAny') || $viewer->canDo('incidents.viewAssigned');
    }

    private function canViewPerson(User $viewer): bool
    {
        return $this->canOpenIncident($viewer)
            || $this->canOpenAlert($viewer)
            || $viewer->canDo('hazards.view');
    }
}
