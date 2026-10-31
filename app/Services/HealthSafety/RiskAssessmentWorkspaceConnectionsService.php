<?php

namespace App\Services\HealthSafety;

use App\Models\Client;
use App\Models\HsCorrectiveAction;
use App\Models\HsEvent;
use App\Support\HealthSafety\QueryPaging;
use App\Models\HsRiskAssessment;
use App\Models\Site;
use App\Models\User;
use App\Policies\ClientPolicy;
use App\Services\Tasks\TaskAggregator;
use App\Services\Tasks\TaskItem;
use App\Services\UserSiteAccessService;
use App\Support\HealthSafety\RiskAssessmentReturn;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;

/** Linked reviews retain their action ownership; no risk-owned task is fabricated. */
final class RiskAssessmentWorkspaceConnectionsService
{
    public function __construct(
        private readonly UserSiteAccessService $sites,
        private readonly HsEventAccessService $events,
        private readonly TaskAggregator $tasks,
    ) {}

    public function forViewer(HsRiskAssessment $assessment, ?User $viewer, ?string $returnTo = null, ?HealthSafetyReadContext $providedContext = null): array
    {
        $empty = ['source' => null, 'safety_review' => null, 'corrective_actions' => [],
            'tasks' => [], 'tasks_href' => null, 'versions' => ['previous' => [], 'next' => null]];
        if (! $viewer) {
            return $empty;
        }
        $consume = fn ($context) =>
            $context->consumeSourceViewer($this->permissions(), function ($viewer, $reads) use ($assessment, $returnTo, $context, $empty) {
                $result = $empty;
                $canonicalQuery = $this->readable($viewer, HsRiskAssessment::query()->whereKey($assessment->id)->with(['assessable', 'hsEvent']), $context, $reads);
                $canonical = (clone $canonicalQuery)->first();
                if (! $canonical || ! $viewer) {
                    return $result;
                }
                $recordHref = RiskAssessmentReturn::REGISTER.'/'.$canonical->id.'?section=connections';
                if ($origin = RiskAssessmentReturn::sanitize($returnTo)) {
                    $recordHref = $this->withReturn($recordHref, $origin);
                }
                $source = $canonical->assessable;
                if ($source instanceof Site
                    && in_array((int) $source->id, $this->sites->accessibleSiteIds($viewer, ['sites.viewAll'], $reads), true)
                    && Gate::forUser($viewer)->allows('view', $source)) {
                    $result['source'] = ['type' => 'site', 'id' => $source->id, 'title' => $source->name,
                        'href' => '/sites/'.$source->id.'?tab=risk_assessments'];
                } elseif ($source instanceof Client && app(ClientPolicy::class)->viewFromCurrentEvidence($viewer, $source, $reads)) {
                    $result['source'] = ['type' => 'client', 'id' => $source->id, 'title' => $source->full_name,
                        'href' => '/operations/clients/'.$source->id.'?tab=risk_management'];
                }

                $result['versions']['next'] = $this->nextForViewer($canonical, $viewer, $returnTo, $context);
                $previousQuery = $this->sameContext(HsRiskAssessment::query()->where('superseded_by_id', $canonical->id), $canonical)
                    ->whereKeyNot($canonical->id)->whereIn('status', [HsRiskAssessment::STATUS_SUPERSEDED, HsRiskAssessment::STATUS_ARCHIVED]);
                $previous = $this->readable($viewer, $previousQuery, $context, $reads)->orderByDesc('id')->get();
                foreach ($previous as $version) {
                    $result['versions']['previous'][] = $this->version($version, $returnTo);
                }

                $eventId = $canonical->hs_event_id ?? ($source instanceof HsEvent ? $source->id : null);
                $eventBinding = $canonical->hs_event_id !== null ? 'hs_event_id' : 'assessable_id';
                $eventCandidates = HsEvent::query()->whereKey($eventId);
                $eventQuery = $this->events->applyReadableScope(clone $eventCandidates, $viewer, $context, $eventCandidates)
                    ->whereIn('hs_events.id', (clone $canonicalQuery)->setEagerLoads([])->when(true, QueryPaging::clear(...))->select('hs_risk_assessments.'.$eventBinding));
                $context->reconsumeIncidentPredicate($eventQuery, $canonicalQuery);
                $eventQuery->with(['owner' => function ($relation) use ($eventQuery, $context): void {
                    $relation->select(['id', 'name'])->whereIn('users.id', (clone $eventQuery)->setEagerLoads([])->when(true, QueryPaging::clear(...))->select('hs_events.owner_user_id'));
                    $context->reconsumeIncidentPredicate($relation->getQuery(), $eventQuery);
                    HealthSafetyReadMaterialisationProof::current($context)?->protect($relation->getQuery());
                }]);
                $event = $eventId ? (HealthSafetyReadMaterialisationProof::current($context)?->protect($eventQuery) ?? $eventQuery)->first() : null;
                if (! $event) {
                    return $result;
                }
                $result['safety_review'] = ['id' => $event->id, 'reference_number' => $event->reference_number,
                    'status' => $event->status,
                    'owner' => $event->owner ? ['id' => $event->owner->id, 'name' => $event->owner->name] : null,
                    'href' => $this->withReturn('/health-safety/events/'.$event->id, $recordHref)];
                $task = $this->taskInContext($viewer, 'hs_event', (int) $event->id, $context);
                if ($task?->link) {
                    $result['tasks'][] = [...$this->taskSummary($task, (int) $event->id, null, $recordHref),
                        'id' => $task->id, 'source' => $task->source];
                }
                $actionQuery = HsCorrectiveAction::query()->where('hs_event_id', $event->id)->whereIn('hs_event_id', (clone $eventQuery)->setEagerLoads([])->when(true, QueryPaging::clear(...))->select('hs_events.id'))->orderByDesc('id');
                $context->reconsumeIncidentPredicate($actionQuery, $eventQuery);
                $actions = (HealthSafetyReadMaterialisationProof::current($context)?->protect($actionQuery) ?? $actionQuery)->get(['id', 'due_date']);
                foreach ($actions as $action) {
                    $task = $this->taskInContext($viewer, 'corrective_action', (int) $action->id, $context);
                    if (! $task?->link) {
                        continue;
                    }
                    $summary = $this->taskSummary($task, (int) $action->id, $action->due_date?->toDateString(), $recordHref);
                    $result['corrective_actions'][] = $summary;
                    $result['tasks'][] = [...$summary, 'id' => $task->id, 'source' => $task->source];
                }
                $result['tasks_href'] = $result['tasks'] === [] ? null : '/tasks';

                return $result;
            });
        $providedContext?->assertViewer($viewer);
        return $providedContext ? HealthSafetyReadMaterialisationProof::within($providedContext, fn () => $consume($providedContext))
            : HealthSafetyReadContext::within($viewer, $this->permissions(), fn ($active) => HealthSafetyReadMaterialisationProof::within($active, fn () => $consume($active)));
    }

    public function nextForViewer(HsRiskAssessment $assessment, ?User $viewer, ?string $returnTo = null, ?HealthSafetyReadContext $context = null): ?array
    {
        if (! $viewer) {
            return null;
        }
        $operation = fn (HealthSafetyReadContext $context) => $context->consumeSourceViewer($this->permissions(),
            function ($viewer, $reads) use ($assessment, $returnTo, $context) {
                $canonical = $this->readable($viewer, HsRiskAssessment::query()->whereKey($assessment->id), $context, $reads)->first();
                if (! $canonical || ! $canonical->superseded_by_id || (int) $canonical->superseded_by_id === (int) $canonical->id
                    || ! in_array($canonical->status, [HsRiskAssessment::STATUS_SUPERSEDED, HsRiskAssessment::STATUS_ARCHIVED], true)) {
                    return null;
                }
                $nextQuery = $this->sameContext(HsRiskAssessment::query()->whereKey($canonical->superseded_by_id), $canonical);
                $next = $this->readable($viewer, $nextQuery, $context, $reads)->first();
                return $next && (int) $next->superseded_by_id !== (int) $canonical->id ? $this->version($next, $returnTo) : null;
            });
        $context?->assertViewer($viewer);
        return $context ? HealthSafetyReadMaterialisationProof::within($context, fn () => $operation($context))
            : HealthSafetyReadContext::within($viewer, $this->permissions(), fn ($active) => HealthSafetyReadMaterialisationProof::within($active, fn () => $operation($active)));
    }

    private function permissions(): array
    {
        return \App\Support\HealthSafety\RiskAssessmentPresenter::readPermissions();
    }

    private function readable(User $viewer, Builder $query, HealthSafetyReadContext $context, \App\Services\CurrentAuthorizationReads $reads): Builder
    {
        if (! $viewer->canDo('hazards.view')) {
            return $context->query($query->whereRaw('1 = 0'));
        }
        return \App\Support\HealthSafety\RiskAssessmentPresenter::guardEagerQuery($query, $viewer, $context);
    }

    private function taskInContext(User $viewer, string $source, int $id, \App\Services\HealthSafety\HealthSafetyReadContext $context): ?TaskItem
    {
        return $this->tasks->findItemFor($viewer, $source, $id, ['include_done' => true], $context);
    }

    private function sameContext(Builder $query, HsRiskAssessment $assessment): Builder
    {
        foreach (['assessable_type', 'assessable_id', 'hs_event_id'] as $key) {
            $assessment->$key === null ? $query->whereNull($key) : $query->where($key, $assessment->$key);
        }

        return $query;
    }

    private function version(HsRiskAssessment $assessment, ?string $returnTo): array
    {
        return ['id' => $assessment->id, 'reference_number' => $assessment->reference_number,
            'status' => $assessment->status, 'href' => RiskAssessmentReturn::href((int) $assessment->id, $returnTo)];
    }

    private function taskSummary(TaskItem $item, int $id, ?string $dueDate, string $returnTo): array
    {
        return ['id' => $id, 'reference_number' => $item->ref, 'title' => $item->title,
            'status' => $item->status, 'display_state' => $item->displayState,
            'assigned_to' => $item->assignee, 'due_date' => $dueDate,
            'href' => $this->withReturn($item->link, $returnTo)];
    }

    private function withReturn(string $href, string $returnTo): string
    {
        $parts = parse_url($href);
        $query = [];
        parse_str($parts['query'] ?? '', $query);
        $query['return_to'] = $returnTo;

        return $parts['path'].'?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986)
            .(isset($parts['fragment']) ? '#'.$parts['fragment'] : '');
    }
}
