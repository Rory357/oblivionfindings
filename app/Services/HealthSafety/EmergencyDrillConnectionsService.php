<?php

namespace App\Services\HealthSafety;

use App\Models\ControlRoomAlert;
use App\Models\EmergencyDrill;
use App\Models\EmergencyDrillFinding;
use App\Models\HsCorrectiveAction;
use App\Models\HsEvent;
use App\Models\User;
use App\Services\ControlRoom\ControlRoomAlertAccessService;
use App\Services\CurrentAuthorizationReads;
use App\Services\Medication\MedicationGovernanceScopeService;
use App\Services\Tasks\TaskAggregator;
use App\Services\Tasks\TaskItem;
use App\Support\HealthSafety\EmergencyDrillReturn;

/** Source drill, local findings and central verification remain separate records. */
final class EmergencyDrillConnectionsService
{
    public function __construct(
        private readonly EmergencyDrillAccessService $access,
        private readonly HsEventAccessService $events,
        private readonly TaskAggregator $tasks,
        private readonly ControlRoomAlertAccessService $alerts,
    ) {}

    public function forViewer(EmergencyDrill $drill, User $viewer, ?string $returnTo = null): array
    {
        return HealthSafetyReadContext::within($viewer, self::readPermissions(), fn (HealthSafetyReadContext $context): array =>
            $this->access->withReadContext($context, function (User $current, CurrentAuthorizationReads $reads, array $sites) use ($drill, $returnTo, $context) {
                $record = $reads->query($this->access->scopeForSites(EmergencyDrill::query(), $sites))->findOrFail($drill->id);
                $this->access->assertRecord($record, $current, $sites);
                $back = EmergencyDrillReturn::href($record->id, $returnTo, 'connections');
                $result = ['safety_reviews' => [], 'corrective_actions' => [], 'control_room' => [], 'previous_drill' => null, 'next_drills' => []];
                $query = HsEvent::query()
                    ->whereIn('source_type', array_unique([EmergencyDrill::class, $record->getMorphClass()]))
                    ->where('source_id', $record->id)->where('site_id', $record->site_id)
                    ->whereNull('client_id')->whereNull('staff_id')->where('event_category', HsEvent::CATEGORY_DRILL_FAILURE);
                $events = $this->events->applyReadableScope(clone $query, $current, $context, $query)
                    ->with('owner:id,name')->orderBy('id')->get();
                foreach ($events as $event) {
                    $result['safety_reviews'][] = ['id' => $event->id, 'reference_number' => $event->reference_number, 'status' => $event->status,
                        'owner' => $event->owner ? ['id' => $event->owner->id, 'name' => $event->owner->name] : null,
                        'href' => $this->withReturn('/health-safety/events/'.$event->id, $back)];
                }
                $actions = $reads->query(HsCorrectiveAction::query())->whereIn('hs_event_id', $events->modelKeys())->orderBy('id')->get();
                foreach ($actions as $action) {
                    $item = $this->tasks->findItemFor($current, 'corrective_action', $action->id, ['include_done' => true], $context);
                    if ($item?->link) {
                        $result['corrective_actions'][] = $this->actionSummary($action, $item, $back);
                    }
                }
                // A drill signal is not evidence of notification delivery. Project only an
                // actual alert with the exact normalized source/Site and its own read scope.
                $alerts = $reads->query($this->alerts->applyReadableScope(ControlRoomAlert::query(), $current))
                    ->where('site_id', $record->site_id)
                    ->where('context->normalized_data->source_module', 'health_safety')
                    ->where('context->normalized_data->signal_type', HsSignalService::TYPE_DRILL_FAILURE)
                    ->where('context->normalized_data->emergency_drill_id', $record->id)->orderBy('id')->get();
                foreach ($alerts as $alert) {
                    $href = $this->alerts->destinationForScopedAlert($alert, $current, $back)['href'];
                    if ($href) {
                        $result['control_room'][] = ['id' => $alert->id, 'reference_number' => $alert->reference_number,
                            'status' => $alert->status, 'priority' => $alert->priority, 'href' => $href];
                    }
                }
                if ($record->previous_drill_id && (int) $record->previous_drill_id !== (int) $record->id) {
                    $previous = $reads->query($this->access->scopeForSites(EmergencyDrill::query(), $sites))
                        ->where('site_id', $record->site_id)->find($record->previous_drill_id);
                    if ($previous && (int) $previous->previous_drill_id !== (int) $record->id) {
                        $result['previous_drill'] = $this->drillSummary($previous, $returnTo);
                    }
                }
                $next = $reads->query($this->access->scopeForSites(EmergencyDrill::query(), $sites))
                    ->where('previous_drill_id', $record->id)->where('site_id', $record->site_id)->whereKeyNot($record->id)
                    ->orderBy('scheduled_at')->orderBy('id')->get();
                foreach ($next as $child) {
                    if ((int) $record->previous_drill_id !== (int) $child->id) {
                        $result['next_drills'][] = $this->drillSummary($child, $returnTo);
                    }
                }

                return $result;
            }));
    }

    public function forFinding(EmergencyDrill $record, EmergencyDrillFinding $finding, User $viewer, ?string $returnTo = null): ?array
    {
        return HealthSafetyReadContext::within($viewer, self::readPermissions(), fn (HealthSafetyReadContext $context): ?array =>
            $this->access->withReadContext($context, function (User $current, CurrentAuthorizationReads $reads, array $sites) use ($record, $finding, $returnTo, $context) {
                $drill = $reads->query($this->access->scopeForSites(EmergencyDrill::query(), $sites))->findOrFail($record->id);
                $this->access->assertRecord($drill, $current, $sites);
                $local = $reads->query(EmergencyDrillFinding::query())->where('emergency_drill_id', $drill->id)->findOrFail($finding->id);
                $identity = $local->hs_corrective_action_id ? $reads->query(HsCorrectiveAction::query())->select(['id', 'hs_event_id'])->find($local->hs_corrective_action_id) : null;
                $query = HsEvent::query()->whereKey($identity?->hs_event_id)
                    ->whereIn('source_type', array_unique([EmergencyDrill::class, $drill->getMorphClass()]))
                    ->where('source_id', $drill->id)->where('site_id', $drill->site_id)
                    ->whereNull('client_id')->whereNull('staff_id')->where('event_category', HsEvent::CATEGORY_DRILL_FAILURE);
                $event = $identity ? $this->events->applyReadableScope(clone $query, $current, $context, $query)->first() : null;
                $action = $event ? $reads->query(HsCorrectiveAction::query())->where('hs_event_id', $event->id)->find($identity->id) : null;
                $task = $event && $action ? $this->tasks->findItemFor($current, 'corrective_action', $action->id, ['include_done' => true], $context) : null;
                if (! $task?->link) {
                    return null;
                }

                return $this->actionSummary($action, $task, EmergencyDrillReturn::href($drill->id, $returnTo, 'findings', $local->id));
            }));
    }

    private static function readPermissions(): array
    {
        return array_values(array_unique([
            ...FirstAidAccessService::PERMISSIONS, ...RestraintAccessService::PERMISSIONS, ...InjuryAccessService::PERMISSIONS,
            ...SafeWorkProcedureAccessService::PERMISSIONS, ...EmergencyDrillAccessService::PERMISSIONS,
            'controlRoom.viewAny', 'controlRoom.alerts.view', 'controlRoom.alerts.manage', 'reports.viewAny',
            MedicationGovernanceScopeService::CONTROLLED_VIEW_CAPABILITY,
        ]));
    }

    private function actionSummary(HsCorrectiveAction $action, TaskItem $item, string $back): array
    {
        return ['id' => $action->id, 'reference_number' => $item->ref, 'title' => $item->title, 'status' => $item->status,
            'display_state' => $item->displayState, 'assigned_to' => $item->assignee, 'due_date' => $action->due_date?->toDateString(),
            'effectiveness_confirmed' => $action->effectiveness_confirmed, 'verified_at' => $action->verified_at?->toIso8601String(),
            'closed_at' => $action->closed_at?->toIso8601String(), 'href' => $this->withReturn($item->link, $back)];
    }

    private function drillSummary(EmergencyDrill $drill, ?string $returnTo): array
    {
        return ['id' => $drill->id, 'reference' => 'DR-'.$drill->id, 'title' => $drill->title, 'status' => $drill->status,
            'outcome' => $drill->outcome, 'scheduled_at' => $drill->scheduled_at?->toIso8601String(),
            'href' => EmergencyDrillReturn::href($drill->id, $returnTo)];
    }

    private function withReturn(string $href, string $back): string
    {
        $parts = parse_url($href);
        parse_str($parts['query'] ?? '', $query);
        $query['return_to'] = $back;

        return $parts['path'].'?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }
}
