<?php

namespace App\Services\Tasks;

use App\Models\ClientIncident;
use App\Models\ControlRoomAlert;
use App\Models\HsEvent;

final class IncidentJourneyTaskContext
{
    /**
     * @return array<string, mixed>|null
     */
    public static function make(
        ?ClientIncident $incident,
        ?ControlRoomAlert $alert = null,
        ?HsEvent $event = null,
        bool $includeSearchContext = false,
    ): ?array {
        if ($incident === null) {
            return null;
        }

        $incidentLoads = [
            'client:id,first_name,last_name',
            'site:id,name',
        ];

        if ($alert === null) {
            $incidentLoads[] = $includeSearchContext
                ? 'controlRoomAlert:id,reference_number,assigned_to_user_id'
                : 'controlRoomAlert:id,reference_number';
        }

        if ($event === null) {
            $incidentLoads[] = $includeSearchContext
                ? 'hsEvent:id,reference_number,owner_user_id'
                : 'hsEvent:id,reference_number';
        }

        if ($includeSearchContext) {
            array_push(
                $incidentLoads,
                'investigator:id,name',
                'followups:id,client_incident_id,assigned_to_user_id',
                'followups.assignedTo:id,name',
            );

            if ($alert === null) {
                array_push(
                    $incidentLoads,
                    'controlRoomAlert.assignedTo:id,name',
                    'controlRoomAlert.tasks:id,alert_id,title,description,assigned_to_user_id',
                    'controlRoomAlert.tasks.assignedTo:id,name',
                );
            }

            if ($event === null) {
                array_push(
                    $incidentLoads,
                    'hsEvent.owner:id,name',
                    'hsEvent.investigations:id,hs_event_id,reference_number,lead_investigator_id',
                    'hsEvent.investigations.leadInvestigator:id,name',
                    'hsEvent.correctiveActions:id,hs_event_id,reference_number,assigned_to_user_id',
                    'hsEvent.correctiveActions.assignedTo:id,name',
                );
            }
        }

        $incident->loadMissing($incidentLoads);

        $alert ??= $incident->controlRoomAlert;
        $event ??= $incident->hsEvent;

        $context = [
            'key' => 'incident-'.$incident->id,
            'source' => $incident->source ?: 'incident',
            'occurred_at' => $incident->occurred_at?->toIso8601String(),
            'references' => [
                'control_room' => $alert?->reference_number ?: null,
                'incident' => $incident->reference_number ?: null,
                'health_safety' => $event?->reference_number ?: null,
            ],
            'person' => $incident->client ? [
                'id' => $incident->client->id,
                'name' => trim($incident->client->first_name.' '.$incident->client->last_name),
            ] : null,
            'site' => $incident->site ? [
                'id' => $incident->site->id,
                'name' => $incident->site->name,
            ] : null,
        ];

        if (! $includeSearchContext) {
            return $context;
        }

        $alert?->loadMissing([
            'assignedTo:id,name',
            'tasks:id,alert_id,title,description,assigned_to_user_id',
            'tasks.assignedTo:id,name',
        ]);
        $event?->loadMissing([
            'owner:id,name',
            'investigations:id,hs_event_id,reference_number,lead_investigator_id',
            'investigations.leadInvestigator:id,name',
            'correctiveActions:id,hs_event_id,reference_number,assigned_to_user_id',
            'correctiveActions.assignedTo:id,name',
        ]);

        $sourceTaskTerms = $alert?->tasks
            ->flatMap(fn ($task) => [$task->title, $task->description])
            ->filter()
            ->values()
            ->all() ?? [];
        $ownerTerms = collect([
            $incident->investigator?->name,
            $alert?->assignedTo?->name,
            $event?->owner?->name,
        ])
            ->merge($incident->followups->pluck('assignedTo.name'))
            ->merge($alert?->tasks->pluck('assignedTo.name') ?? [])
            ->merge($event?->investigations->pluck('leadInvestigator.name') ?? [])
            ->merge($event?->correctiveActions->pluck('assignedTo.name') ?? [])
            ->filter(fn ($term) => is_string($term) && filled($term))
            ->unique()
            ->values()
            ->all();

        $context['search_terms'] = array_values(array_filter([
            ...($event?->investigations->pluck('reference_number')->all() ?? []),
            ...($event?->correctiveActions->pluck('reference_number')->all() ?? []),
            $incident->title,
            $incident->description,
            $incident->immediate_action_taken,
            $incident->immediate_action,
            $incident->witnesses,
            $incident->potential_consequence,
            ...$sourceTaskTerms,
            ...$ownerTerms,
        ], fn ($term) => is_string($term) && filled($term)));

        return $context;
    }
}
