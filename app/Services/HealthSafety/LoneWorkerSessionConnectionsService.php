<?php

namespace App\Services\HealthSafety;

use App\Models\ClientIncident;
use App\Models\ControlRoomAlert;
use App\Models\HsCorrectiveAction;
use App\Models\HsEvent;
use App\Models\LoneWorkerSession;
use App\Models\Shift;
use App\Models\User;
use App\Policies\ClientPolicy;
use App\Services\ControlRoom\ControlRoomAlertAccessService;
use App\Services\CurrentAuthorizationReads;
use App\Services\Incidents\IncidentAccessService;
use App\Services\Tasks\TaskAggregator;
use App\Services\UserSiteAccessService;
use App\Support\HealthSafety\LoneWorkerSessionReturn;
use Illuminate\Support\Facades\Gate;

/** Independent source and destination authority; no copied response or review workflow. */
final class LoneWorkerSessionConnectionsService
{
    public function __construct(private readonly LoneWorkerSessionAccessService $access, private readonly ControlRoomAlertAccessService $alerts,
        private readonly HsEventAccessService $events, private readonly TaskAggregator $tasks) {}

    public function forViewer(LoneWorkerSession $session, User $viewer, ?string $returnTo = null): array
    {
        return HealthSafetyReadContext::within($viewer, self::readPermissions(), fn (HealthSafetyReadContext $context): array =>
            $this->access->withReadContext($context, function (User $current, CurrentAuthorizationReads $reads, array $sites) use ($session, $returnTo, $context) {
                $record = $reads->query(LoneWorkerSession::query())->findOrFail($session->id);
                $this->access->assertRecord($record, $current, $sites);
                $back = LoneWorkerSessionReturn::href($record->id, $returnTo, 'connections');
                $result = ['shift' => null, 'site' => null, 'client' => null, 'control_room' => [], 'incidents' => [], 'safety_reviews' => [], 'corrective_actions' => []];
                if ($record->site && Gate::forUser($current)->allows('view', $record->site)) {
                    $result['site'] = ['id' => $record->site->id, 'name' => $record->site->name, 'href' => '/sites/'.$record->site->id];
                }
                if ($record->client && app(ClientPolicy::class)->viewFromCurrentEvidence($current, $record->client, $reads)) {
                    $result['client'] = ['id' => $record->client->id, 'name' => trim($record->client->first_name.' '.$record->client->last_name), 'href' => '/operations/clients/'.$record->client->id];
                }
                $shift = $record->shift;
                if ($shift && ($current->canDo('shifts.viewAny') || $current->canDo('shifts.viewAssigned'))
                    && ($current->canDo('shifts.manageAny') || ((int) $shift->user_id === (int) $current->id && $reads->query(Shift::query()->visibleToFrontline())->whereKey($shift->id)->exists()))
                    && in_array((int) $record->site->id, app(UserSiteAccessService::class)->accessibleSiteIds($current, ['reports.viewAny'], $reads), true)) {
                    $result['shift'] = ['id' => $shift->id, 'status' => $shift->status, 'starts_at' => $shift->starts_at?->toIso8601String(),
                        'ends_at' => $shift->ends_at?->toIso8601String(), 'actual_starts_at' => $shift->actual_starts_at?->toIso8601String(),
                        'actual_ends_at' => $shift->actual_ends_at?->toIso8601String(), 'href' => $this->withReturn('/operations/shifts/'.$shift->id, $back)];
                }
                $alerts = $reads->query(ControlRoomAlert::query())->where('source', 'lone_worker')->where('site_id', $record->site->id)
                    ->where('client_id', $record->client_id)->where('context->normalized_data->lone_worker_session_id', $record->id)
                    ->orderBy('id')->get(['id', 'source', 'origin_signal_id', 'alert_type', 'site_id', 'client_id', 'context']);
                $reviewIds = [];
                foreach ($alerts as $alert) {
                    if (! $this->access->alertMatchesSession($alert, $record, $reads)) {
                        continue;
                    }
                    // Independent readable incident/review work does not require CR entry.
                    $incident = app(IncidentAccessService::class)->apply(ClientIncident::query(), $current, $reads)
                        ->where('control_room_alert_id', $alert->id)->where('site_id', $record->site->id)->where('client_id', $record->client_id)->first();
                    if ($incident) {
                        $result['incidents'][] = ['id' => $incident->id, 'reference_number' => $incident->reference_number, 'status' => $incident->status,
                            'href' => $this->withReturn('/incidents/'.$incident->id, $back)];
                    }
                    // A direct canonical alert FK anchors review provenance; Site/client must agree.
                    $query = HsEvent::query()->where('control_room_alert_id', $alert->id)
                        ->where('site_id', $record->site->id)->where('client_id', $record->client_id);
                    $events = $this->events->applyReadableScope(clone $query, $current, $context, $query)
                        ->with('owner:id,name')->orderBy('id')->get();
                    foreach ($events as $event) {
                        if (in_array((int) $event->id, $reviewIds, true)) {
                            continue;
                        }
                        $reviewIds[] = (int) $event->id;
                        $result['safety_reviews'][] = ['id' => $event->id, 'reference_number' => $event->reference_number, 'status' => $event->status,
                            'owner' => $event->owner ? ['id' => $event->owner->id, 'name' => $event->owner->name] : null,
                            'href' => $this->withReturn('/health-safety/events/'.$event->id, $back)];
                    }
                    $response = $this->alerts->canRead($current)
                        ? $reads->query($this->alerts->applyReadableScope(ControlRoomAlert::query(), $current))
                            ->whereKey($alert->id)->with('assignedTo:id,name')->first()
                        : null;
                    if ($response) {
                        $destination = $this->alerts->destinationForScopedAlert($response, $current, $back);
                        if ($destination['href'] ?? null) {
                            $result['control_room'][] = ['id' => $response->id, 'reference_number' => $response->reference_number, 'status' => $response->status,
                                'alert_type' => $response->alert_type, 'priority' => $response->priority, 'triggered_at' => $response->triggered_at?->toIso8601String(),
                                'acknowledged_at' => $response->acknowledged_at?->toIso8601String(), 'resolved_at' => $response->resolved_at?->toIso8601String(),
                                'assigned_to' => $response->assignedTo ? ['id' => $response->assignedTo->id, 'name' => $response->assignedTo->name] : null, 'href' => $destination['href']];
                        }
                    }
                }
                $actions = $reads->query(HsCorrectiveAction::query())->whereIn('hs_event_id', $reviewIds)->orderBy('id')->get();
                foreach ($actions as $action) {
                    $item = $this->tasks->findItemFor($current, 'corrective_action', $action->id, ['include_done' => true], $context);
                    if ($item?->link) {
                        $result['corrective_actions'][] = ['id' => $action->id, 'reference_number' => $item->ref, 'title' => $item->title, 'status' => $item->status,
                            'display_state' => $item->displayState, 'assigned_to' => $item->assignee, 'due_date' => $action->due_date?->toDateString(),
                            'href' => $this->withReturn($item->link, $back)];
                    }
                }

                return $result;
            }));
    }

    private static function readPermissions(): array
    {
        return array_values(array_unique([
            ...FirstAidAccessService::PERMISSIONS, ...RestraintAccessService::PERMISSIONS, ...InjuryAccessService::PERMISSIONS,
            ...SafeWorkProcedureAccessService::PERMISSIONS, ...EmergencyDrillAccessService::PERMISSIONS, ...LoneWorkerSessionAccessService::PERMISSIONS,
            'sites.viewAny', 'sites.viewAll', 'sites.type.house.view', 'sites.type.facility.view', 'sites.type.head_office.view',
            'clients.viewAny', 'clients.viewAssigned', 'clinical.accessAllSites', 'shifts.viewAny', 'shifts.viewAssigned', 'shifts.manageAny',
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
