<?php

namespace App\Services\Tasks;

use App\Models\TaskWatcher;
use App\Models\User;
use App\Services\Tasks\Contracts\TaskProvider;
use App\Services\Tasks\Providers\ClientIncidentProvider;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * The company-wide work-item feed: unions open incidents, corrective
 * actions, alerts, concerns and follow-ups from every module into one
 * normalised, permission-filtered list ({@see TaskItem}).
 *
 * Mirrors the SiteCalendarAggregator provider pattern. Nothing here is
 * persisted — every row deep-links back to the module that owns it.
 */
class TaskAggregator
{
    private const SEVERITY_RANK = ['critical' => 0, 'high' => 1, 'medium' => 2, 'low' => 3, 'info' => 4];

    /** @var TaskProvider[] */
    private array $providers;

    /**
     * Per-request memo of each user's watched item-key set, keyed by user id.
     * The aggregator is resolved fresh per request (no singleton binding), so
     * this stays request-scoped and lets the index render, badge helper and
     * the `following` filter share a single task_watchers read.
     *
     * @var array<int, array<string, true>>
     */
    private array $watchedMemo = [];

    /**
     * @param  TaskProvider[]|null  $providers  Defaults to the full registry.
     */
    public function __construct(?array $providers = null)
    {
        $this->providers = $providers ?? self::defaultProviders();
    }

    /**
     * The full provider registry.
     *
     * @return TaskProvider[]
     */
    public static function defaultProviders(): array
    {
        return [
            new ClientIncidentProvider,
            new Providers\IncidentFollowupProvider,
            new Providers\HsEventProvider,
            new Providers\HsInvestigationProvider,
            new Providers\HsCorrectiveActionProvider,
            new Providers\SiteHazardProvider,
            new Providers\WorkplaceInjuryProvider,
            new Providers\SafeguardingConcernProvider,
            new Providers\SafeguardingActionPlanProvider,
            new Providers\ControlRoomAlertProvider,
            new Providers\FleetIncidentProvider,
            new Providers\FleetMaintenanceProvider,
            new Providers\MedicationErrorProvider,
            new Providers\CdLossReportProvider,
            new Providers\DataBreachProvider,
            new Providers\DataSubjectRequestProvider,
            new Providers\ActionItemProvider,
            new Providers\HrCaseProvider,
            new Providers\SiteChecklistRunProvider,
            new Providers\ShiftTaskProvider,
            new Providers\RespiteTaskProvider,
            new Providers\FirstAidFollowupProvider,
            new Providers\RestraintReviewProvider,
        ];
    }

    /**
     * The user's watched item keys as a set ("{source}|{item_id}" => true).
     * Memoised per request so the `following` filter, the `watching` stat and
     * the badge helper all share ONE task_watchers read.
     *
     * @return array<string, true>
     */
    public function watchedKeysFor(User $user): array
    {
        if (isset($this->watchedMemo[$user->id])) {
            return $this->watchedMemo[$user->id];
        }

        $keys = TaskWatcher::query()
            ->where('user_id', $user->id)
            ->get(['source', 'item_id'])
            ->mapWithKeys(fn (TaskWatcher $w) => [$w->source.'|'.$w->item_id => true])
            ->all();

        return $this->watchedMemo[$user->id] = $keys;
    }

    /**
     * The registered provider for a source key, or null.
     */
    public function providerFor(string $sourceKey): ?TaskProvider
    {
        foreach ($this->providers as $provider) {
            if ($provider->sourceKey() === $sourceKey) {
                return $provider;
            }
        }

        return null;
    }

    /**
     * Modules the user may see, for the dashboard's source filter.
     *
     * @return array<int, array{key: string, label: string}>
     */
    public function sourcesFor(User $user): array
    {
        $sources = [];

        foreach ($this->providers as $provider) {
            if ($provider->canView($user)) {
                $sources[] = ['key' => $provider->sourceKey(), 'label' => $provider->label()];
            }
        }

        return $sources;
    }

    /**
     * Permission-filtered, normalised, sorted work items.
     *
     * @param  array{
     *   sources?: string[]|null,
     *   severity?: string[]|null,
     *   bucket?: string[]|null,
     *   assigned?: 'me'|null,
     *   overdue?: bool,
     *   q?: string|null,
     *   include_done?: bool,
     * }  $filters
     * @return TaskItem[]
     */
    public function itemsFor(User $user, array $filters = []): array
    {
        $sources = $filters['sources'] ?? null;
        $items = [];

        foreach ($this->providers as $provider) {
            if ($sources !== null && $sources !== [] && ! in_array($provider->sourceKey(), $sources, true)) {
                continue;
            }

            if (! $provider->canView($user)) {
                continue;
            }

            foreach ($provider->tasks($user, $filters) as $item) {
                // A provider retry or legacy duplicate must not create two
                // tickets for the same canonical source record.
                $items[$item->id] ??= $item;
            }
        }

        $items = $this->filterItems(array_values($items), $user, $filters);

        usort($items, function (TaskItem $a, TaskItem $b) {
            // Overdue first, then severity, then due date (nulls last), newest last.
            if ($a->isOverdue() !== $b->isOverdue()) {
                return $a->isOverdue() ? -1 : 1;
            }

            $rank = (self::SEVERITY_RANK[$a->severity] ?? 9) <=> (self::SEVERITY_RANK[$b->severity] ?? 9);
            if ($rank !== 0) {
                return $rank;
            }

            if ($a->dueAt !== $b->dueAt) {
                if ($a->dueAt === null) {
                    return 1;
                }
                if ($b->dueAt === null) {
                    return -1;
                }

                return strcmp($a->dueAt, $b->dueAt);
            }

            return strcmp((string) $b->createdAt, (string) $a->createdAt);
        });

        return $items;
    }

    /**
     * As {@see itemsFor()} but pre-serialised for Inertia props.
     *
     * @return array<int, array<string, mixed>>
     */
    public function arrayFor(User $user, array $filters = []): array
    {
        return array_map(fn (TaskItem $item) => $item->toArray(), $this->itemsFor($user, $filters));
    }

    /**
     * Headline stats for the hero tiles, computed over the UNFILTERED
     * (permission-scoped, open-only) item set so tile counts stay stable
     * while the user plays with filters.
     *
     * @param  TaskItem[]  $items
     */
    public function stats(array $items, User $user): array
    {
        $open = array_filter($items, fn (TaskItem $i) => $i->bucket !== TaskItem::BUCKET_DONE);
        $mine = array_filter($open, fn (TaskItem $i) => ($i->assignee['id'] ?? null) === $user->id);
        $weekAhead = now()->addDays(7);
        $watched = $this->watchedKeysFor($user);

        return [
            'open' => count($open),
            'bucketOpen' => count(array_filter($open, fn (TaskItem $i) => $i->bucket === TaskItem::BUCKET_OPEN)),
            'inProgress' => count(array_filter($open, fn (TaskItem $i) => $i->bucket === TaskItem::BUCKET_IN_PROGRESS)),
            'unassigned' => count(array_filter($open, fn (TaskItem $i) => $i->assignee === null)),
            'dueWeek' => count(array_filter(
                $open,
                fn (TaskItem $i) => $i->dueAt !== null
                    && ! $i->isOverdue()
                    && Carbon::parse($i->dueAt)->lte($weekAhead),
            )),
            'overdue' => count(array_filter($open, fn (TaskItem $i) => $i->isOverdue())),
            'critical' => count(array_filter($open, fn (TaskItem $i) => in_array($i->severity, ['critical', 'high'], true))),
            'mine' => count($mine),
            'myOverdue' => count(array_filter($mine, fn (TaskItem $i) => $i->isOverdue())),
            'watching' => count(array_filter(
                $open,
                fn (TaskItem $i) => isset($watched[$i->source.'|'.Str::afterLast($i->id, '-')]),
            )),
        ];
    }

    /**
     * Sidebar badge: my open + overdue item count. Uncached — the caller
     * (HandleInertiaRequests) caches view+badge together per user.
     */
    public function badgeCountFor(User $user): int
    {
        $items = $this->itemsFor($user, []);

        return count(array_filter(
            $items,
            fn (TaskItem $i) => $i->isOverdue() || ($i->assignee['id'] ?? null) === $user->id,
        ));
    }

    /**
     * Slice an already-aggregated item set without re-running providers,
     * so a controller can compute stats over the full set and render a
     * filtered list from one provider pass.
     *
     * @param  TaskItem[]  $items
     * @return TaskItem[]
     */
    public function filterItems(array $items, User $user, array $filters): array
    {
        return array_values(array_filter($items, fn (TaskItem $item) => $this->matches($item, $user, $filters)));
    }

    private function matches(TaskItem $item, User $user, array $filters): bool
    {
        if (! empty($filters['sources']) && ! in_array($item->source, (array) $filters['sources'], true)) {
            return false;
        }

        if (! empty($filters['severity']) && ! in_array($item->severity, (array) $filters['severity'], true)) {
            return false;
        }

        if (! empty($filters['bucket']) && ! in_array($item->bucket, (array) $filters['bucket'], true)) {
            return false;
        }

        $assigned = $filters['assigned'] ?? null;
        if ($assigned === 'me' && ($item->assignee['id'] ?? null) !== $user->id) {
            return false;
        }
        if ($assigned === 'unassigned' && $item->assignee !== null) {
            return false;
        }

        if (! empty($filters['overdue']) && ! $item->isOverdue()) {
            return false;
        }

        // due=week: open items due inside the next 7 days (not yet overdue).
        if (($filters['due'] ?? null) === 'week') {
            if ($item->bucket === TaskItem::BUCKET_DONE
                || $item->dueAt === null
                || $item->isOverdue()
                || Carbon::parse($item->dueAt)->gt(now()->addDays(7))) {
                return false;
            }
        }

        $q = trim((string) ($filters['q'] ?? ''));
        if ($q !== '') {
            $journeyReferences = collect(data_get($item->journey, 'references', []))
                ->filter()
                ->implode(' ');
            $haystack = strtolower(implode(' ', array_filter([
                $item->ref,
                $journeyReferences,
                $item->title,
                $item->description,
                $item->sourceContext,
            ])));
            if (! str_contains($haystack, strtolower($q))) {
                return false;
            }
        }

        // following=true: only items the user is watching (task_watchers).
        if (! empty($filters['following'])) {
            $key = $item->source.'|'.Str::afterLast($item->id, '-');
            if (! isset($this->watchedKeysFor($user)[$key])) {
                return false;
            }
        }

        return true;
    }
}
