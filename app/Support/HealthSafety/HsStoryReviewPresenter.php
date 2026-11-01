<?php

namespace App\Support\HealthSafety;

use App\Models\ClientIncident;
use App\Models\HsCorrectiveAction;
use App\Models\HsEvent;
use App\Models\User;
use App\Services\HealthSafety\HsEventAccessService;
use App\Services\HealthSafety\HsEventActivityService;
use App\Services\HealthSafety\HsEventClosureService;
use App\Services\HealthSafety\HsIncidentStoryReadService;
use Illuminate\Support\Carbon;

/**
 * Q1 + answer 23 (Stephan, 31 Oct / 1 Nov 2026; G3 board M5b): the safety review page for a person who reads the
 * incident's story but can't read the review. Built only when HsEventAccessService::canReadEvent() says no AND
 * HsIncidentStoryReadService::storyFor() says yes, so it never widens the normal read.
 *
 * It carries: the review's reference, title, status, Site and owner; the round steps (counts, never other people's
 * action titles); "What happened" and "What we did straight away" as the reporter wrote them; the person's OWN actions
 * (with their own files); activity about their own actions only; and how many closing checks are left. It carries no
 * investigation, findings, other people's actions, WorkSafe details, linked incident, client details or History.
 */
final class HsStoryReviewPresenter
{
    private const DONE = [HsCorrectiveAction::STATUS_COMPLETED, HsCorrectiveAction::STATUS_VERIFIED, HsCorrectiveAction::STATUS_CLOSED];

    private const CHECKED = [HsCorrectiveAction::STATUS_VERIFIED, HsCorrectiveAction::STATUS_CLOSED];

    public function __construct(
        private readonly HsIncidentStoryReadService $stories,
        private readonly HsEventAccessService $access,
        private readonly HsEventClosureService $closures,
        private readonly HsCorrectiveActionPresenter $actions,
        private readonly HsEventActivityService $activity,
    ) {}

    /** @return array<string, mixed>|null */
    public function present(User $viewer, int $eventId): ?array
    {
        // A refused read touches no review body: check the key first, then load only what the story view shows.
        if (! $viewer->canDo(HsIncidentStoryReadService::PERMISSION) || ! $viewer->canDo('hazards.view')) {
            return null;
        }
        $event = HsEvent::query()->with(['site:id,name', 'owner:id,name'])->whereKey($eventId)->first([
            'id', 'reference_number', 'event_category', 'severity', 'status', 'site_id', 'owner_user_id', 'source_type',
            'source_id', 'worksafe_notifiable', 'worksafe_decided_at', 'worksafe_decided_by_user_id', 'worksafe_decision_source',
            'worksafe_decision_reason', 'worksafe_decision_tree_version', 'worksafe_source_effective_date',
            'investigation_required', 'accepted_at', 'reported_at', 'closed_at',
        ]);
        if ($event === null || $this->access->canReadEvent($viewer, $event)) {
            return null;
        }
        $story = $this->stories->storyFor($viewer, $event);
        if ($story === null) {
            return null;
        }

        $all = HsCorrectiveAction::query()->where('hs_event_id', $event->id)
            ->get(['id', 'status', 'effectiveness_confirmed', 'replaces_action_id', 'completed_at', 'verified_at']);
        $own = HsCorrectiveAction::query()
            ->where('hs_event_id', $event->id)
            ->where('assigned_to_user_id', $viewer->id)
            ->with(['hsEvent:id,created_by', 'assignedTo:id,name', 'completedBy:id,name', 'verifiedBy:id,name', 'attachments.uploader:id,name',
                'auditLogs' => fn ($query) => $query->with('user:id,name')->orderBy('created_at')->orderBy('id')])
            ->orderByRaw("FIELD(status, 'open', 'in_progress', 'completed', 'verified', 'closed')")
            ->orderBy('due_date')
            ->get();

        $readiness = $this->closures->readiness($event);
        $left = count($readiness->blockers());
        $done = count($readiness->requirements) - $left;
        $site = $event->site?->name;
        $owner = $event->owner?->name;
        $incident = ClientIncident::query()->whereKey($story['incident_id'])->first(['id', 'type', 'title']);
        $nearMiss = $incident?->type === 'near_miss';
        $category = HsLabels::label('review_category', $event->event_category);

        return [
            'id' => (int) $event->id,
            'reference_number' => $event->reference_number,
            // Decision 14 (Stephan, 1 Nov): the team lead's title uses the incident's own words (it reads them in the
            // story anyway); with no incident title, the category and Site.
            'title' => filled($incident?->title) ? (string) $incident->title : $category.($site ? ' — '.$site : ''),
            'status_label' => HsLabels::label('review_status', $event->status),
            'severity_label' => $event->severity ? ($nearMiss ? 'Potential: ' : '').HsLabels::label('severity', $event->severity) : null,
            'category_label' => $nearMiss ? 'Near miss · no one hurt' : HsLabels::label('review_category', $event->event_category),
            'from_label' => $nearMiss ? 'from a near miss' : 'from an incident',
            'site_name' => $site,
            'owner_name' => $owner,
            'steps' => $this->steps($event, $all),
            'story' => [
                'what_happened' => $story['what_happened'],
                'did_straight_away' => $story['did_straight_away'],
                'reported_by' => $story['reported_by'],
                'report_label' => $nearMiss ? 'near-miss report' : 'incident report',
            ],
            'actions' => $own->map(fn (HsCorrectiveAction $action): array => $this->actions->present($action, $viewer, false, true))->values()->all(),
            'activity' => $this->activity->forOwnActions($event, $viewer),
            'closure' => [
                'left' => $left,
                'done' => $done,
                'closed_by' => $owner
                    ? "closed by {$owner} (owner) or a health and safety manager".($site ? " at {$site}" : '')
                    : 'closed by a health and safety manager'.($site ? " at {$site}" : ''),
            ],
            'worksafe_with' => $event->hasSignedWorksafeDecision()
                ? ''
                : 'The WorkSafe decision is for '.($owner ? "{$owner} (owner) or " : '').'a health and safety manager.',
        ];
    }

    /**
     * Counts only: never another person's action title. Dates go out as ISO instants; the page shows them in the
     * worker's time.
     *
     * @param  \Illuminate\Support\Collection<int, HsCorrectiveAction>  $all
     * @return list<array{key: string, label: string, meta: ?string, date: ?string, state: string}>
     */
    private function steps(HsEvent $event, $all): array
    {
        $closed = $event->status === HsEvent::STATUS_CLOSED;
        $doneCount = $all->filter(fn ($a): bool => in_array($a->status, self::DONE, true))->count();
        $implemented = $all->isNotEmpty() && $doneCount === $all->count();
        $revised = $all->pluck('replaces_action_id')->filter()->map(fn ($id): int => (int) $id)->all();
        $failed = $all->first(fn ($a): bool => $a->checkedIneffective() && ! in_array((int) $a->id, $revised, true));
        $checked = $implemented && $all->every(fn ($a): bool => in_array($a->status, self::CHECKED, true))
            && $failed === null && HsCorrectiveAction::unresolvedFailedReferences((int) $event->id) === [];
        $investigated = $event->hasSignedWorksafeDecision() && (! $event->investigation_required || $event->hasCompletedInvestigation());
        $iso = fn ($at): ?string => $at ? Carbon::parse($at)->toIso8601String() : null;

        $states = [
            ['key' => 'assigned', 'label' => 'Assigned', 'done' => $event->owner_user_id !== null || $closed,
                'meta' => $event->owner_user_id !== null ? null : 'No owner yet',
                'date' => $event->owner_user_id !== null ? $iso($event->accepted_at ?? $event->reported_at) : null],
            ['key' => 'investigated', 'label' => 'Investigated', 'done' => $closed || $investigated,
                'meta' => $investigated ? 'Done' : 'Not yet'],
            ['key' => 'implemented', 'label' => 'Implemented', 'done' => $closed || $implemented,
                'meta' => $implemented ? 'Done' : ($all->isNotEmpty() ? "{$doneCount} of {$all->count()} done" : 'No actions yet')],
            ['key' => 'check', 'label' => 'Check it worked', 'done' => $closed || $checked, 'failed' => $failed !== null && ! $closed,
                'meta' => $failed !== null ? 'Failed' : ($checked ? 'Done' : 'Not yet')],
            ['key' => 'closed', 'label' => 'Closed', 'done' => $closed, 'meta' => $closed ? null : 'Not yet',
                'date' => $closed ? $iso($event->closed_at) : null],
        ];
        $current = collect($states)->first(fn (array $s): bool => ! empty($s['failed']))
            ?? collect($states)->first(fn (array $s): bool => ! $s['done'])
            ?? end($states);

        return array_map(fn (array $s): array => [
            'key' => $s['key'],
            'label' => $s['label'],
            'meta' => $s['meta'],
            'date' => $s['date'] ?? null,
            'state' => $s['done'] && empty($s['failed']) ? 'done' : ($s['key'] === $current['key'] ? (! empty($s['failed']) ? 'failed' : 'now') : 'todo'),
        ], $states);
    }
}
