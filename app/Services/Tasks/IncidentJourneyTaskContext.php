<?php

namespace App\Services\Tasks;

use App\Models\ClientIncident;
use App\Models\ControlRoomAlert;
use App\Models\HsEvent;
use App\Models\User;
use App\Services\ControlRoom\ControlRoomAlertAccessService;
use App\Services\HealthSafety\HealthSafetyReadContext;
use App\Services\HealthSafety\HsEventAccessService;
use App\Services\Incidents\IncidentAccessService;

final class IncidentJourneyTaskContext
{
    /**
     * @return array<string, mixed>|null
     */
    public static function make(
        ?ClientIncident $incident,
        User $viewer,
        ?ControlRoomAlert $alert = null,
        ?HsEvent $event = null,
        bool $includeSearchContext = false,
        ?HealthSafetyReadContext $readContext = null,
    ): ?array {
        $readContext?->assertViewer($viewer);
        if ($incident === null || ! app(IncidentAccessService::class)->canView($viewer, $incident)) {
            return null;
        }

        $incidentLoads = [
            'client:id,first_name,last_name',
            'site:id,name',
        ];

        if ($includeSearchContext) {
            array_push(
                $incidentLoads,
                'investigator:id,name',
                'followups:id,client_incident_id,assigned_to_user_id',
                'followups.assignedTo:id,name',
            );

        }

        $incident->loadMissing($incidentLoads);

        $alert ??= $incident->controlRoomAlert;
        $event ??= $incident->hsEvent;
        // A task's own read authority never grants access to the other domains.
        // Filter before constructing either visible references or search terms.
        if ($alert && ! app(ControlRoomAlertAccessService::class)->canView($alert, $viewer)) {
            $alert = null;
        }
        if ($event && ! app(HsEventAccessService::class)->canView($event, $viewer, $readContext)) {
            $event = null;
        }

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
