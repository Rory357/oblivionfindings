<?php

namespace App\Services\Sites;

use App\Models\ControlRoomAlert;
use App\Models\HsCorrectiveAction;
use App\Models\HsEvent;
use App\Models\SiteChecklistRun;
use App\Models\SiteHazard;
use App\Models\SiteInspectionRecord;
use App\Models\User;
use App\Services\ControlRoom\ControlRoomAlertAccessService;
use App\Services\ControlRoom\ControlRoomAlertProvenanceService;
use App\Services\HealthSafety\HsEventAccessService;
use App\Services\SiteTypeAccessService;
use App\Services\Tasks\TaskAggregator;
use App\Services\Tasks\TaskItem;
use App\Services\UserSiteAccessService;
use DomainException;
use Illuminate\Support\Facades\Gate;

/** Read-only connections; each source retains its ownership and lifecycle. */
final class HazardWorkspaceConnectionsService
{
    public function __construct(
        private readonly HsEventAccessService $events,
        private readonly ControlRoomAlertAccessService $alerts,
        private readonly ControlRoomAlertProvenanceService $provenance,
        private readonly TaskAggregator $tasks,
        private readonly UserSiteAccessService $sites,
        private readonly SiteTypeAccessService $siteTypes,
    ) {}

    public function forViewer(SiteHazard $hazard, ?User $viewer): array
    {
        $empty = ['safety_reviews' => [], 'corrective_actions' => [], 'control_room' => [],
            'sources' => [], 'tasks' => [], 'tasks_href' => null];
        if (! $viewer) {
            return $empty;
        }
        $permissions = array_values(array_unique([
            ...\App\Services\HealthSafety\FirstAidAccessService::PERMISSIONS,
            ...\App\Services\HealthSafety\RestraintAccessService::PERMISSIONS,
            ...\App\Services\HealthSafety\InjuryAccessService::PERMISSIONS,
            ...\App\Services\HealthSafety\SafeWorkProcedureAccessService::PERMISSIONS,
            ...\App\Services\HealthSafety\EmergencyDrillAccessService::PERMISSIONS, ...\App\Services\ControlRoom\ControlRoomGovernanceReadService::PERMISSIONS,
            'sites.viewAny', 'sites.viewAll', 'checklists.view',
            'sites.type.head_office.view', 'sites.type.house.view', 'sites.type.facility.view',
        ]));
        return \App\Services\HealthSafety\HealthSafetyReadContext::within($viewer, $permissions, fn ($context) =>
            $context->consumeSourceViewer($permissions, function ($viewer, $reads) use ($hazard, $context, $empty) {
                $result = $empty;
                if (! $viewer->canDo('hazards.view')) {
                    return $result;
                }
                $hazard = $reads->query(SiteHazard::query())->whereKey($hazard->id)->with('site')->first();
                if (! $hazard || ! $hazard->site
                    || ! in_array((int) $hazard->site_id, $this->sites->accessibleSiteIds($viewer, ['sites.viewAll'], $reads), true)
                    || ! in_array($hazard->site->type, $this->siteTypes->allowedTypes($viewer), true)) {
                    return $result;
                }

                // The source tuple, not copied alert context or a browser ID, establishes
                // the relationship. Site agreement also rejects corrupted legacy links.
                $candidates = HsEvent::query()->whereIn('source_type', array_unique([SiteHazard::class, $hazard->getMorphClass()]))
                    ->where('source_id', $hazard->id)->where('site_id', $hazard->site_id)
                    ->where('event_category', HsEvent::CATEGORY_HAZARD);
                $events = $this->events->applyReadableScope(clone $candidates, $viewer, $context, $candidates)
                    ->with('owner:id,name')->orderByDesc('id')->get();

                $taskCandidates = [['hazard', (int) $hazard->id, $hazard->due_date?->toDateString(), null]];
                foreach ($events as $event) {
                    $result['safety_reviews'][] = [
                        'id' => $event->id, 'reference_number' => $event->reference_number,
                        'status' => $event->status,
                        'owner' => $event->owner ? ['id' => $event->owner->id, 'name' => $event->owner->name] : null,
                        'href' => '/health-safety/events/'.$event->id.'?return_to='.rawurlencode('/hazards/'.$hazard->id),
                    ];
                    $taskCandidates[] = ['hs_event', (int) $event->id, null, null];

                    if ($event->control_room_alert_id) {
                        $alert = $this->alerts->findVisible($viewer, (int) $event->control_room_alert_id);
                        if ($alert && $this->matchesEvent($alert, $event)) {
                            $owner = $this->provenance->safeAssignedTo($alert, $viewer);
                            // Deliberately omit notes, context, source narrative, and all
                            // evidence: operational status needs no copied private content.
                            $result['control_room'][$alert->id] = [
                                'id' => $alert->id, 'reference_number' => $alert->reference_number,
                                'status' => $alert->status, 'priority' => $alert->priority,
                                'assigned_to' => $owner ? ['id' => $owner->id, 'name' => $owner->name] : null,
                                'href' => $this->alerts->destinationForScopedAlert($alert, $viewer, '/hazards/'.$hazard->id)['href'],
                            ];
                        }
                    }
                }
                $result['control_room'] = array_values($result['control_room']);

                // SiteHazardAction is intentionally absent here: those local controls
                // already belong to detail.actions and never become central actions.
                $actions = HsCorrectiveAction::query()->whereIn('hs_event_id', $events->modelKeys())
                    ->orderByDesc('id')->get(['id', 'due_date']);
                foreach ($actions as $action) {
                    $id = (int) $action->id;
                    $item = $this->taskInContext($viewer, 'corrective_action', (int) $id, $context);
                    if (! $item || ! $item->link) {
                        continue;
                    }
                    $result['corrective_actions'][] = $this->taskSummary($item, $id, $hazard, $action->due_date?->toDateString());
                    $taskCandidates[] = ['corrective_action', $id, $action->due_date?->toDateString(), $item];
                }

                $result['sources'] = $this->sources($hazard, $viewer);
                foreach ($taskCandidates as [$source, $id, $dueDate, $item]) {
                    $item ??= $source === 'hazard' ? $this->tasks->findItemFor($viewer, $source, $id, ['include_done' => true]) : $this->taskInContext($viewer, $source, $id, $context);
                    if ($item?->link) {
                        $result['tasks'][] = [...$this->taskSummary($item, $id, $hazard, $dueDate),
                            'id' => $item->id, 'source' => $item->source];
                    }
                }
                if ($result['tasks'] !== []) {
                    $result['tasks_href'] = '/tasks';
                }

                return $result;
            }));
    }

    private function taskInContext(User $viewer, string $source, int $id, \App\Services\HealthSafety\HealthSafetyReadContext $context): ?TaskItem
    {
        return $this->tasks->findItemFor($viewer, $source, $id, ['include_done' => true], $context);
    }

    private function matchesEvent(ControlRoomAlert $alert, HsEvent $event): bool
    {
        try {
            $this->provenance->assertHealthSafetyEventTuple($alert, $event);

            return true;
        } catch (DomainException) {
            return false;
        }
    }

    private function taskSummary(TaskItem $item, int $id, SiteHazard $hazard, ?string $dueDate): array
    {
        return ['id' => $id, 'reference_number' => $item->ref, 'title' => $item->title,
            'status' => $item->status, 'display_state' => $item->displayState,
            'assigned_to' => $item->assignee,
            'due_date' => $dueDate,
            'href' => in_array($item->source, ['hs_event', 'corrective_action'], true)
                ? $this->withReturnTo($item->link, $hazard)
                : $item->link];
    }

    private function withReturnTo(string $href, SiteHazard $hazard): string
    {
        $parts = parse_url($href);
        $query = [];
        parse_str($parts['query'] ?? '', $query);
        $query['return_to'] = '/hazards/'.$hazard->id;

        return $parts['path'].'?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986)
            .(isset($parts['fragment']) ? '#'.$parts['fragment'] : '');
    }

    private function sources(SiteHazard $hazard, User $viewer): array
    {
        if (! $viewer->canDo('checklists.view') || ! Gate::forUser($viewer)->allows('view', $hazard->site)) {
            return [];
        }
        $sources = [];
        $records = SiteInspectionRecord::query()->where('site_id', $hazard->site_id)
            ->whereHas('schedule', fn ($query) => $query->where('site_id', $hazard->site_id))
            ->where(fn ($query) => $query->where('linked_hazard_id', $hazard->id)
                ->when($hazard->linked_inspection_id, fn ($query) => $query->orWhere(fn ($direct) => $direct
                    ->whereKey($hazard->linked_inspection_id)
                    ->where(fn ($reciprocal) => $reciprocal->whereNull('linked_hazard_id')->orWhere('linked_hazard_id', $hazard->id)))))
            ->with('schedule:id,title')->orderByDesc('id')->get();
        foreach ($records as $record) {
            $sources[] = ['type' => 'inspection', 'id' => $record->id,
                'title' => $record->schedule->title ?: 'Site inspection',
                'status' => $record->result ?: 'pending',
                'occurred_at' => $record->completed_at?->toISOString(),
                'href' => '/sites/'.$hazard->site_id.'/inspections'];
        }
        if ($hazard->linked_checklist_run_id) {
            $run = SiteChecklistRun::query()->whereKey($hazard->linked_checklist_run_id)
                ->where('site_id', $hazard->site_id)->with(['site', 'assignment', 'template:id,name'])->first();
            if ($run && Gate::forUser($viewer)->allows('view', $run)) {
                $sources[] = ['type' => 'checklist', 'id' => $run->id,
                    'title' => $run->template?->name ?: 'Site checklist', 'status' => $run->status,
                    'occurred_at' => $run->completed_at?->toISOString(),
                    'href' => '/checklists/runs/'.$run->id];
            }
        }

        return $sources;
    }
}
