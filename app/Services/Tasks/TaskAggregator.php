<?php

namespace App\Services\Tasks;

use App\Models\User;
use App\Services\Tasks\Contracts\TaskProvider;
use App\Services\Tasks\Providers\ClientIncidentProvider;
use Illuminate\Support\Facades\Cache;

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
            new ClientIncidentProvider(),
            new Providers\IncidentFollowupProvider(),
            new Providers\HsEventProvider(),
            new Providers\HsInvestigationProvider(),
            new Providers\HsCorrectiveActionProvider(),
            new Providers\SiteHazardProvider(),
            new Providers\WorkplaceInjuryProvider(),
            new Providers\SafeguardingConcernProvider(),
            new Providers\SafeguardingActionPlanProvider(),
            new Providers\ControlRoomAlertProvider(),
            new Providers\FleetIncidentProvider(),
            new Providers\MedicationErrorProvider(),
            new Providers\CdLossReportProvider(),
            new Providers\DataBreachProvider(),
            new Providers\DataSubjectRequestProvider(),
            new Providers\ActionItemProvider(),
            new Providers\HrCaseProvider(),
        ];
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
                $items[] = $item;
            }
        }

        $items = $this->filterItems($items, $user, $filters);

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

        return [
            'open' => count($open),
            'overdue' => count(array_filter($open, fn (TaskItem $i) => $i->isOverdue())),
            'critical' => count(array_filter($open, fn (TaskItem $i) => in_array($i->severity, ['critical', 'high'], true))),
            'mine' => count(array_filter($open, fn (TaskItem $i) => ($i->assignee['id'] ?? null) === $user->id)),
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

        if (($filters['assigned'] ?? null) === 'me' && ($item->assignee['id'] ?? null) !== $user->id) {
            return false;
        }

        if (! empty($filters['overdue']) && ! $item->isOverdue()) {
            return false;
        }

        $q = trim((string) ($filters['q'] ?? ''));
        if ($q !== '') {
            $haystack = strtolower(($item->ref ?? '').' '.$item->title.' '.($item->description ?? ''));
            if (! str_contains($haystack, strtolower($q))) {
                return false;
            }
        }

        return true;
    }
}
