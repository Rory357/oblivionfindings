<?php

namespace App\Services\HealthSafety;

use App\Models\Client;
use App\Models\ClientIncident;
use App\Models\ControlRoomAlert;
use App\Models\FirstAidRecord;
use App\Models\HsCorrectiveAction;
use App\Models\HsEvent;
use App\Models\User;
use App\Policies\ClientPolicy;
use App\Services\ControlRoom\ControlRoomAlertAccessService;
use App\Services\ControlRoom\ControlRoomAlertProvenanceService;
use App\Services\CurrentAuthorizationReads;
use App\Services\Incidents\IncidentAccessService;
use App\Services\Medication\MedicationGovernanceScopeService;
use App\Services\Tasks\TaskAggregator;
use App\Support\HealthSafety\FirstAidReturn;
use DomainException;
use Illuminate\Support\Facades\Gate;

/** Treatment, incident decisions, central review and response each retain ownership. */
final class FirstAidConnectionsService
{
    public function __construct(private readonly FirstAidAccessService $access, private readonly IncidentAccessService $incidents,
        private readonly HsEventAccessService $events, private readonly TaskAggregator $tasks, private readonly ControlRoomAlertAccessService $alerts) {}

    public function forViewer(FirstAidRecord $source, User $viewer, ?string $returnTo = null): array
    {
        return HealthSafetyReadContext::within($viewer, self::readPermissions(), fn (HealthSafetyReadContext $context): array =>
            $this->access->withReadContext($context, function (User $current, CurrentAuthorizationReads $reads, array $sites) use ($source, $returnTo, $context) {
                $record = $this->access->readableQuery($current, $reads, $sites, true, $context)->findOrFail($source->id);
                $this->access->assertSource($record, $current, $reads, $sites);
                $back = FirstAidReturn::href($record->id, $returnTo, 'connections');
                $result = ['site' => null, 'person' => null, 'equipment' => [], 'incident' => null, 'safety_reviews' => [], 'corrective_actions' => [], 'control_room' => []];
                if ($record->site && Gate::forUser($current)->allows('view', $record->site)) {
                    $result['site'] = ['id' => (int) $record->site->id, 'name' => $record->site->name, 'href' => '/sites/'.$record->site->id.'?tab=first_aid'];
                }
                $client = $record->treated_person_type === 'client' && $record->client_id ? $reads->query(Client::query())->find($record->client_id) : null;
                if ($client && (int) $client->site_id === (int) $record->site_id && app(ClientPolicy::class)->viewFromCurrentEvidence($current, $client, $reads)) {
                    $result['person'] = ['id' => (int) $client->id, 'name' => trim($client->first_name.' '.$client->last_name), 'href' => '/operations/clients/'.$client->id.'?tab=first_aid'];
                }
                // Existing unmatched legacy FKs are not relabelled as valid provenance.
                $incident = $record->treated_person_type === 'client' && $record->client_id && $record->related_incident_id
                    ? $this->incidents->apply(ClientIncident::query(), $current, $reads)->where('client_id', $record->client_id)->where('site_id', $record->site_id)->find($record->related_incident_id) : null;
                if ($incident) {
                    $result['incident'] = ['id' => (int) $incident->id, 'reference' => $incident->reference_number, 'title' => $incident->title,
                        'status' => $incident->status, 'href' => $this->withReturn('/incidents/'.$incident->id, $back)];
                }
                $query = HsEvent::query()->where('site_id', $record->site_id);
                $query->where(function ($sources) use ($record, $incident) {
                    $sources->where(function ($own) use ($record) {
                        $own->whereIn('source_type', array_unique([FirstAidRecord::class, $record->getMorphClass()]))->where('source_id', $record->id)
                            ->where('event_category', HsEvent::CATEGORY_INJURY)->where('client_id', $record->treated_person_type === 'client' ? $record->client_id : null)
                            ->where('staff_id', $record->treated_person_type === 'staff' ? $record->treated_person_id : null);
                    });
                    if ($incident) {
                        $sources->orWhere(fn ($linked) => $linked->whereIn('source_type', array_unique([ClientIncident::class, $incident->getMorphClass()]))
                            ->where('source_id', $incident->id)->where('client_id', $record->client_id)
                            ->where('staff_id', $incident->reported_by)->where('event_category', $incident->type === 'near_miss' ? HsEvent::CATEGORY_NEAR_MISS : HsEvent::CATEGORY_INCIDENT));
                    }
                });
                $reviews = $this->events->applyReadableScope(clone $query, $current, $context, $query)
                    ->with('owner:id,name')->orderBy('id')->get();
                foreach ($reviews as $event) {
                    $result['safety_reviews'][] = ['id' => (int) $event->id, 'reference_number' => $event->reference_number, 'status' => $event->status,
                        'owner' => $event->owner ? ['id' => (int) $event->owner->id, 'name' => $event->owner->name] : null,
                        'href' => $this->withReturn('/health-safety/events/'.$event->id, $back)];
                }
                foreach ($reads->query(HsCorrectiveAction::query())->whereIn('hs_event_id', $reviews->modelKeys())->orderBy('id')->get() as $action) {
                    $task = $this->tasks->findItemFor($current, 'corrective_action', $action->id, ['include_done' => true], $context);
                    if ($task?->link) {
                        $result['corrective_actions'][] = ['id' => (int) $action->id, 'reference_number' => $task->ref, 'title' => $task->title, 'status' => $task->status,
                            'display_state' => $task->displayState, 'assigned_to' => $task->assignee, 'due_date' => $action->due_date?->toDateString(), 'href' => $this->withReturn($task->link, $back)];
                    }
                }
                // Only a persisted canonical review FK anchors response context. No broad signal enumeration.
                $alertIds = $reviews->pluck('control_room_alert_id')->filter()->unique()->all();
                foreach ($reads->query($this->alerts->applyReadableScope(ControlRoomAlert::query(), $current))->whereIn('id', $alertIds)->where('site_id', $record->site_id)->orderBy('id')->get() as $alert) {
                    $matching = false;
                    foreach ($reviews->where('control_room_alert_id', $alert->id) as $review) {
                        try {
                            app(ControlRoomAlertProvenanceService::class)->assertHealthSafetyEventTuple($alert, $review);
                            $matching = true;
                            break;
                        } catch (DomainException) { /* A foreign legacy FK is not source response evidence. */
                        }
                    }
                    if (! $matching) {
                        continue;
                    }
                    $href = $this->alerts->destinationForScopedAlert($alert, $current, $back)['href'] ?? null;
                    if ($href) {
                        $result['control_room'][] = ['id' => (int) $alert->id, 'reference_number' => $alert->reference_number, 'status' => $alert->status, 'priority' => $alert->priority, 'href' => $href];
                    }
                }

                return $result;
            }));
    }

    private static function readPermissions(): array
    {
        return array_values(array_unique([
            ...FirstAidAccessService::PERMISSIONS, ...RestraintAccessService::PERMISSIONS, ...InjuryAccessService::PERMISSIONS,
            ...SafeWorkProcedureAccessService::PERMISSIONS, ...EmergencyDrillAccessService::PERMISSIONS,
            'sites.viewAny', 'sites.viewAll', 'sites.type.house.view', 'sites.type.facility.view', 'sites.type.head_office.view',
            'clients.viewAny', 'clients.viewAssigned', 'clinical.accessAllSites', 'incidents.viewAny', 'incidents.viewAssigned', 'hr.cases.view',
            'controlRoom.viewAny', 'controlRoom.alerts.view', 'controlRoom.alerts.manage', 'reports.viewAny', MedicationGovernanceScopeService::CONTROLLED_VIEW_CAPABILITY,
        ]));
    }

    private function withReturn(string $href, string $back): string
    {
        $parts = parse_url($href);
        parse_str($parts['query'] ?? '', $query);
        $query['return_to'] = $back;

        return $parts['path'].'?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }
}
