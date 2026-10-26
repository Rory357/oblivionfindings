<?php

namespace App\Services\Tasks;

use App\Exceptions\TaskProviderNavigationException;
use App\Models\TaskWatcher;
use App\Models\User;
use App\Services\ControlRoom\ControlRoomGovernanceReadService;
use App\Services\HealthSafety\EmergencyDrillAccessService;
use App\Services\HealthSafety\FirstAidAccessService;
use App\Services\HealthSafety\HealthSafetyReadContext;
use App\Services\HealthSafety\InjuryAccessService;
use App\Services\HealthSafety\RestraintAccessService;
use App\Services\HealthSafety\SafeWorkProcedureAccessService;
use App\Services\Tasks\Contracts\ContextualHealthSafetyTaskProvider;
use App\Services\Tasks\Contracts\ProvidesTaskSourceAliases;
use App\Services\Tasks\Contracts\TaskProvider;
use App\Services\Tasks\Providers\ClientIncidentProvider;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

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
    public const NAVIGATION_REQUEST_ATTRIBUTE = 'healthSafety.task_navigation';

    public const NAVIGATION_USER_ATTRIBUTE = 'healthSafety.task_navigation_user_id';

    public const NAVIGATION_EVENT_ATTRIBUTE = 'healthSafety.task_navigation_event_id';

    private const SEVERITY_RANK = ['critical' => 0, 'high' => 1, 'medium' => 2, 'low' => 3, 'info' => 4];

    /** @var TaskProvider[] */
    private array $providers;

    /**
     * Per-request memo of each user's watched item-key set, keyed by user id.
     * The aggregator is container-scoped (see AppServiceProvider), so this
     * stays request-scoped and lets the index render, badge helper and
     * the `following` filter share a single task_watchers read.
     *
     * @var array<int, array<string, true>>
     */
    private array $watchedMemo = [];

    /**
     * Per-request memo of provider passes, keyed by provider class, user id
     * and filters hash. Because the aggregator is container-scoped, the
     * middleware badge pass and the /my-day and /tasks controllers share ONE
     * hydration per provider per request instead of re-running the full
     * fan-out. A throwing provider is never memoised, so
     * navigationBadgeFor()'s degraded handling and itemsFor()'s fail-fast
     * behaviour both keep their semantics.
     *
     * @var array<string, TaskItem[]>
     */
    private array $providerPassMemo = [];

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
            new Providers\ProcedureReviewRequestProvider,
            new Providers\EmergencyDrillProvider,
            new Providers\EmergencyDrillFindingProvider,
            new Providers\WorkplaceInjuryProvider,
            new Providers\SafeguardingConcernProvider,
            new Providers\SafeguardingActionPlanProvider,
            new Providers\ControlRoomAlertProvider,
            new Providers\ItWorkTaskProvider,
            new Providers\ItApprovalTaskProvider,
            new Providers\ItKnowledgeReviewTaskProvider,
            new Providers\ItAutomationReviewTaskProvider,
            new Providers\ItRecurrenceFailureTaskProvider,
            new Providers\VendorRenewalTaskProvider,
            new Providers\ItProvisioningTaskProvider,
            new Providers\FleetIncidentProvider,
            new Providers\FleetMaintenanceProvider,
            new Providers\FleetFinanceReviewProvider,
            new Providers\MedicationErrorProvider,
            new Providers\MedicationFollowupProvider,
            new Providers\LegacyMedicationEffectProvider,
            new Providers\MedicationErrorActionProvider,
            new Providers\MedicationReviewProvider,
            new Providers\MedicationReviewChangeProvider,
            new Providers\MedicationEmergencyAccessReviewProvider,
            new Providers\MedicationPaperGiverConfirmationProvider,
            new Providers\MedicationPaperWitnessConfirmationProvider,
            new Providers\MedicationPrescriberRequestProvider,
            new Providers\MedicationRoundProvider,
            new Providers\CdLossReportProvider,
            new Providers\DataBreachProvider,
            new Providers\DataSubjectRequestProvider,
            new Providers\ActionItemProvider,
            new Providers\HrCaseProvider,
            new Providers\SiteChecklistRunProvider,
            new Providers\ShiftTaskProvider,
            new Providers\RespiteTaskProvider,
            new Providers\ReferralTaskProvider,
            new Providers\FirstAidFollowupProvider,
            new Providers\RestraintReviewProvider,
            // Private follow-ups remain source-owned; generic Tasks cannot mutate them.
            new Providers\ChemicalExposureFollowupProvider,
            // Exposures that haven't reached the H&S register, for exposure managers (job 3).
            new Providers\ChemicalExposureDeliveryProvider,
            // H&S events whose source changed after a signed WorkSafe decision or after close (job 3).
            new Providers\HsEventReviewFlagProvider,
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
            if ($provider->sourceKey() === $sourceKey
                || ($provider instanceof ProvidesTaskSourceAliases
                    && in_array($sourceKey, $provider->sourceAliases(), true))) {
                return $provider;
            }
        }

        return null;
    }

    /**
     * Resolve one item through its provider's real permission and record scope.
     *
     * @param  array{
     *   q?: string|null,
     *   return_to?: string
     * }  $filters
     */
    public function findItemFor(
        User $user,
        string $source,
        int $id,
        array $filters = [],
        ?HealthSafetyReadContext $providedContext = null,
    ): ?TaskItem {
        $provider = $this->providerFor($source);
        if ($provider instanceof ContextualHealthSafetyTaskProvider) {
            $permissions = array_values(array_unique([
                ...FirstAidAccessService::PERMISSIONS, ...RestraintAccessService::PERMISSIONS,
                ...InjuryAccessService::PERMISSIONS, ...SafeWorkProcedureAccessService::PERMISSIONS,
                ...EmergencyDrillAccessService::PERMISSIONS, ...($provider instanceof Providers\ControlRoomAlertProvider ? ControlRoomGovernanceReadService::PERMISSIONS : []),
            ]));
            $consume = fn (HealthSafetyReadContext $context): ?TaskItem => $context->consumeSourceViewer($permissions,
                function (User $current) use ($provider, $user, $source, $id, $filters, $context): ?TaskItem {
                    if (! $provider->canView($current)) {
                        return null;
                    }
                    $itemIds = ["{$source}-{$id}"];
                    if ($provider->sourceKey() === $source && $provider instanceof ProvidesTaskSourceAliases) {
                        $alias = $this->legacySourceAliasFor($provider, $current, $id);
                        $itemIds = $alias ? ["{$alias}-{$id}"] : $itemIds;
                    }
                    $exactFilters = [...$filters, 'id' => $id];
                    foreach ([false, true] as $includeDone) {
                        $lookup = $includeDone ? [...$exactFilters, 'include_done' => true] : $exactFilters;
                        foreach ($provider->authorizedTasksInContext($user, $lookup, $context) as $item) {
                            if (in_array($item->id, $itemIds, true)) {
                                return $item;
                            }
                        }
                    }
                    return null;
                });
            try {
                if ($providedContext !== null) {
                    $providedContext->assertViewer($user);

                    return $consume($providedContext);
                }

                return HealthSafetyReadContext::within($user, $permissions, $consume);
            } catch (HttpException $denial) {
                if (! in_array($denial->getStatusCode(), [403, 404], true)) {
                    throw $denial;
                }
                return null;
            }
        }
        if (! $provider || ! $provider->canView($user)) {
            return null;
        }

        $itemIds = ["{$source}-{$id}"];
        if ($provider->sourceKey() === $source
            && $provider instanceof ProvidesTaskSourceAliases) {
            $legacyAlias = $this->legacySourceAliasFor($provider, $user, $id);
            $itemIds = $legacyAlias ? ["{$legacyAlias}-{$id}"] : $itemIds;
        }
        $exactFilters = [
            ...$filters,
            'id' => $id,
        ];
        $item = collect($provider->authorizedTasks($user, $exactFilters))
            ->first(fn (TaskItem $candidate) => in_array($candidate->id, $itemIds, true));
        if ($item) {
            return $item;
        }

        return collect($provider->authorizedTasks($user, [
            ...$exactFilters,
            'include_done' => true,
        ]))->first(fn (TaskItem $candidate) => in_array($candidate->id, $itemIds, true));
    }

    public function canWatchItemFor(User $user, string $source, int $id): bool
    {
        return $this->findItemFor($user, $source, $id)?->link !== null;
    }

    /**
     * Return only watchers who can still open the exact record, pruning stale
     * cross-site, confidential, revoked-permission, and no-destination rows.
     *
     * @param  array<int, int>  $excludeUserIds
     * @return array<int, int>
     */
    public function authorizedWatcherIdsFor(
        string $source,
        int $id,
        array $excludeUserIds = [],
    ): array {
        $access = $this->resolveWatcherAccess($source, $id);
        $this->pruneStaleWatchers($access);

        return array_values(array_diff(
            $access['authorized'],
            array_map('intval', $excludeUserIds),
        ));
    }

    /**
     * Read-only counterpart for detail payloads: stale rows are filtered from
     * names/counts but remain untouched until a write/notification path prunes.
     *
     * @return array<int, int>
     */
    public function visibleWatcherIdsFor(string $source, int $id): array
    {
        return $this->resolveWatcherAccess($source, $id)['authorized'];
    }

    /**
     * Raw candidate ids for a delivery pass. Callers MUST resolve every id
     * through authorizedWatcherItemForDelivery() immediately before sending;
     * a follower row is durable, but permission, Site, assignment, and privacy
     * visibility can all change after that row was created.
     *
     * @param  array<int, int>  $excludeUserIds
     * @return array<int, int>
     */
    public function candidateWatcherIdsForDelivery(
        string $source,
        int $id,
        array $excludeUserIds = [],
    ): array {
        return TaskWatcher::query()
            ->whereIn('source', $this->watcherSourceKeysFor($source))
            ->where('item_id', $id)
            ->when(
                $excludeUserIds !== [],
                fn ($query) => $query->whereNotIn('user_id', array_map('intval', $excludeUserIds)),
            )
            ->distinct()
            ->pluck('user_id')
            ->map(fn ($userId) => (int) $userId)
            ->values()
            ->all();
    }

    /**
     * Re-authorize one follower at the delivery boundary and return the item
     * projected for that recipient. Returning the recipient's projection (not
     * the actor/command projection) prevents restricted metadata from leaking
     * through notification title, context, or link fields.
     */
    public function authorizedWatcherItemForDelivery(
        string $source,
        int $id,
        int $userId,
    ): ?TaskItem {
        $access = $this->resolveWatcherAccess($source, $id, $userId);
        $this->pruneStaleWatchers($access);

        return $access['items'][$userId] ?? null;
    }

    public function isUserWatching(User $user, string $source, int $id): bool
    {
        $rows = TaskWatcher::query()
            ->whereIn('source', $this->watcherSourceKeysFor($source))
            ->where('item_id', $id)
            ->where('user_id', $user->id)
            ->pluck('source');

        if ($rows->contains($source)) {
            return true;
        }

        $provider = $this->providerFor($source);

        return $provider instanceof ProvidesTaskSourceAliases
            && $provider->sourceKey() !== $source
            && $rows->contains($provider->sourceKey())
            && $this->legacySourceAliasFor($provider, $user, $id) === $source;
    }

    /**
     * Include the old composite provider key when reading/deleting followers,
     * while all new writes use the exact subtype alias supplied by the item.
     *
     * @return string[]
     */
    public function watcherSourceKeysFor(string $source): array
    {
        $provider = $this->providerFor($source);
        if (! $provider instanceof ProvidesTaskSourceAliases
            || $provider->sourceKey() === $source) {
            return [$source];
        }

        return [$source, $provider->sourceKey()];
    }

    /**
     * @return array{
     *   authorized: array<int, int>,
     *   items: array<int, TaskItem>,
     *   stale_rows: array<int, int>,
     *   stale_users: array<int, int>
     * }
     */
    private function resolveWatcherAccess(string $source, int $id, ?int $onlyUserId = null): array
    {
        $authorized = [];
        $items = [];
        $staleRows = [];
        $staleUsers = [];
        $watchers = TaskWatcher::query()
            ->with('user')
            ->whereIn('source', $this->watcherSourceKeysFor($source))
            ->where('item_id', $id)
            ->when($onlyUserId !== null, fn ($query) => $query->where('user_id', $onlyUserId))
            ->get();
        $provider = $this->providerFor($source);
        foreach ($watchers as $watcher) {
            if (! $watcher->user) {
                $staleRows[] = (int) $watcher->id;
                $staleUsers[] = (int) $watcher->user_id;

                continue;
            }

            $isLegacyCompositeRow = $provider instanceof ProvidesTaskSourceAliases
                && $provider->sourceKey() !== $source
                && $watcher->source === $provider->sourceKey();

            if ($isLegacyCompositeRow) {
                $legacyOwnerAlias = $this->legacySourceAliasFor(
                    $provider,
                    $watcher->user,
                    $id,
                );
                // A legacy row owned by another subtype is ignored, never
                // pruned. Ownership comes from record existence, so it cannot
                // switch when a record completes or leaves a due-date window.
                if ($legacyOwnerAlias !== $source) {
                    continue;
                }
            }

            $item = $this->findItemFor($watcher->user, $source, $id);
            if ($item?->link !== null) {
                $authorized[(int) $watcher->user_id] = (int) $watcher->user_id;
                $items[(int) $watcher->user_id] = $item;

                continue;
            }

            $staleRows[] = (int) $watcher->id;
            $staleUsers[] = (int) $watcher->user_id;
        }

        return [
            'authorized' => array_values($authorized),
            'items' => $items,
            'stale_rows' => array_values(array_unique($staleRows)),
            'stale_users' => array_values(array_unique($staleUsers)),
        ];
    }

    /**
     * @param  array{stale_rows: array<int, int>, stale_users: array<int, int>}  $access
     */
    private function pruneStaleWatchers(array $access): void
    {
        if ($access['stale_rows'] === []) {
            return;
        }

        TaskWatcher::query()
            ->whereIn('id', $access['stale_rows'])
            ->delete();

        foreach ($access['stale_users'] as $userId) {
            unset($this->watchedMemo[$userId]);
        }
    }

    /**
     * Modules the user may see, for the dashboard's source filter.
     *
     * @return array<int, array{key: string, label: string}>
     */
    public function sourcesFor(User $user): array
    {
        $currentVisibility = [];
        $contextual = array_filter($this->providers, fn ($provider): bool => $provider instanceof ContextualHealthSafetyTaskProvider);
        if ($contextual !== []) {
            $permissions = array_values(array_unique([
                ...FirstAidAccessService::PERMISSIONS, ...RestraintAccessService::PERMISSIONS,
                ...InjuryAccessService::PERMISSIONS, ...SafeWorkProcedureAccessService::PERMISSIONS,
                ...EmergencyDrillAccessService::PERMISSIONS, ...(array_filter($contextual, fn ($provider) => $provider instanceof Providers\ControlRoomAlertProvider) !== [] ? ControlRoomGovernanceReadService::PERMISSIONS : []),
            ]));
            try {
                $currentVisibility = HealthSafetyReadContext::within($user, $permissions,
                    fn (HealthSafetyReadContext $context): array => $context->consumeSourceViewer($permissions,
                        function (User $current) use ($contextual): array {
                            $visibility = [];
                            foreach ($contextual as $provider) {
                                // Chooser authority only: never hydrate tasks or source children here.
                                $visibility[spl_object_id($provider)] = $provider->canView($current);
                            }
                            return $visibility;
                        }));
            } catch (HttpException $denial) {
                if (! in_array($denial->getStatusCode(), [403, 404], true)) {
                    throw $denial;
                }
            }
        }
        $sources = [];
        foreach ($this->providers as $provider) {
            $visible = $provider instanceof ContextualHealthSafetyTaskProvider
                ? ($currentVisibility[spl_object_id($provider)] ?? false)
                : $provider->canView($user);
            if ($visible) {
                $sources[] = ['key' => $provider->sourceKey(), 'label' => $provider->label()];
            }
        }
        return $sources;
    }

    /**
     * Build the shared-navigation projection without allowing one provider to
     * take down every authenticated Inertia response. Failures are reported
     * with provider context and surfaced as `degraded`; the strict /tasks feed
     * continues to use itemsFor(), where provider failures are not suppressed.
     *
     * @return array{view: bool, badge: int, degraded: bool}
     */
    public function navigationBadgeFor(User $user): array
    {
        return $this->navigationBadge($user);
    }

    /** Computes scalar navigation inside the caller's active read callback. */
    public function navigationBadgeInContext(User $viewer, HealthSafetyReadContext $context): array
    {
        $context->assertViewer($viewer);

        return $this->navigationBadge($viewer, $context);
    }

    /** @return array{view: bool, badge: int, degraded: bool} */
    private function navigationBadge(User $user, ?HealthSafetyReadContext $context = null): array
    {
        $view = false;
        $degraded = false;
        $items = [];
        $healthSafety = $this->healthSafetyProviderPasses($user, [], true, $context);

        foreach ($this->providers as $provider) {
            $sourceKey = $provider::class;

            try {
                $sourceKey = $provider->sourceKey();
                if ($provider instanceof ContextualHealthSafetyTaskProvider) {
                    $pass = $healthSafety[spl_object_id($provider)] ?? ['view' => false, 'items' => [], 'failed' => false];
                    $degraded = $degraded || $pass['failed'];
                    if (! $pass['view']) {
                        continue;
                    }
                    $providerItems = $pass['items'];
                } else {
                    if (! $provider->canView($user)) {
                        continue;
                    }
                    // A readable module still owns a destination when its task read fails.
                    $view = true;
                    $providerItems = $this->authorizedTasksMemo($provider, $user);
                }

                $view = true;
                foreach ($providerItems as $item) {
                    // Match itemsFor(): retries or legacy aliases cannot
                    // inflate the shared count for one canonical record.
                    $items[$item->id] ??= $item;
                }
            } catch (Throwable $exception) {
                $degraded = true;
                report(new TaskProviderNavigationException(
                    providerClass: $provider::class,
                    sourceKey: $sourceKey,
                    userId: (int) $user->id,
                    previous: $exception,
                ));
            }
        }

        return [
            'view' => $view,
            'badge' => $this->countBadgeItems(array_values($items), $user),
            'degraded' => $degraded,
        ];
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
        $healthSafety = $this->healthSafetyProviderPasses($user, $filters);

        foreach ($this->providers as $provider) {
            if ($sources !== null && $sources !== [] && ! in_array($provider->sourceKey(), $sources, true)) {
                continue;
            }

            if ($provider instanceof ContextualHealthSafetyTaskProvider) {
                $pass = $healthSafety[spl_object_id($provider)] ?? ['view' => false, 'items' => []];
                if (! $pass['view']) {
                    continue;
                }
                $providerItems = $pass['items'];
            } else {
                if (! $provider->canView($user)) {
                    continue;
                }
                $providerItems = $this->authorizedTasksMemo($provider, $user, $filters);
            }

            foreach ($providerItems as $item) {
                // A provider retry or legacy duplicate must not create two
                // tickets for the same canonical source record.
                $items[$item->id] ??= $item;
            }
        }

        $items = $this->filterItems(array_values($items), $user, $filters);

        return $this->sortItems($items);
    }

    /**
     * These source projections share one fresh local read; only DTOs leave it.
     * Unrelated providers retain the original viewer and their existing boundary.
     *
     * @return array<int, array{view: bool, items: TaskItem[], failed: bool}>
     */
    private function healthSafetyProviderPasses(User $viewer, array $filters, bool $navigation = false, ?HealthSafetyReadContext $providedContext = null): array
    {
        $sources = $filters['sources'] ?? null;
        $providers = array_values(array_filter($this->providers,
            fn (TaskProvider $provider): bool => $provider instanceof ContextualHealthSafetyTaskProvider
                && ($sources === null || $sources === [] || in_array($provider->sourceKey(), $sources, true))));
        if ($providers === []) {
            return [];
        }
        $permissions = array_values(array_unique([
            ...FirstAidAccessService::PERMISSIONS,
            ...RestraintAccessService::PERMISSIONS,
            ...InjuryAccessService::PERMISSIONS,
            ...SafeWorkProcedureAccessService::PERMISSIONS,
            ...EmergencyDrillAccessService::PERMISSIONS, ...(array_filter($providers, fn ($provider) => $provider instanceof Providers\ControlRoomAlertProvider) !== [] ? ControlRoomGovernanceReadService::PERMISSIONS : []),
        ]));

        try {
            $consume = fn (HealthSafetyReadContext $context): array => $context->consumeSourceViewer($permissions,
                function (User $current) use ($context, $viewer, $filters, $providers, $navigation): array {
                    $passes = [];
                    foreach ($providers as $provider) {
                        $pass = ['view' => false, 'items' => [], 'failed' => false];
                        try {
                            $pass['view'] = $provider->canView($current);
                            if ($pass['view']) {
                                $pass['items'] = $navigation
                                    ? $provider->authorizedNavigationTasksInContext($viewer, $context)
                                    : $provider->authorizedTasksInContext($viewer, $filters, $context);
                            }
                        } catch (HttpException $exception) {
                            if (! in_array($exception->getStatusCode(), [403, 404], true)) {
                                if (! $navigation) {
                                    throw $exception;
                                }
                                $this->reportNavigationFailure($provider, $viewer, $exception);
                                $pass['failed'] = true;
                            }
                            $pass['items'] = [];
                        } catch (Throwable $exception) {
                            if (! $navigation) {
                                throw $exception;
                            }
                            $this->reportNavigationFailure($provider, $viewer, $exception);
                            $pass['failed'] = true;
                        }
                        $passes[spl_object_id($provider)] = $pass;
                    }

                    return $passes;
                });
            if ($providedContext !== null) {
                $providedContext->assertViewer($viewer);

                return $consume($providedContext);
            }

            return HealthSafetyReadContext::within($viewer, $permissions, $consume);
        } catch (HttpException $exception) {
            if (in_array($exception->getStatusCode(), [403, 404], true)) {
                return [];
            }
            if (! $navigation) {
                throw $exception;
            }
        } catch (Throwable $exception) {
            if (! $navigation) {
                throw $exception;
            }
        }

        $passes = [];
        foreach ($providers as $provider) {
            $this->reportNavigationFailure($provider, $viewer, $exception);
            $passes[spl_object_id($provider)] = ['view' => false, 'items' => [], 'failed' => true];
        }

        return $passes;
    }

    private function reportNavigationFailure(TaskProvider $provider, User $viewer, Throwable $exception): void
    {
        report(new TaskProviderNavigationException(
            providerClass: $provider::class,
            sourceKey: $provider->sourceKey(),
            userId: (int) $viewer->id,
            previous: $exception,
        ));
    }

    /**
     * One provider pass per (provider, user, filters) per request. TaskItem
     * DTOs are read-only downstream, so sharing the arrays between the badge
     * and feed passes is safe.
     *
     * @return TaskItem[]
     */
    private function authorizedTasksMemo(TaskProvider $provider, User $user, array $filters = []): array
    {
        if ($provider instanceof ContextualHealthSafetyTaskProvider) {
            return $provider->authorizedTasks($user, $filters);
        }

        // Keyed by instance (not class): registries and tests may hold
        // several providers of one class. Instances live in $this->providers
        // for the aggregator's lifetime, so the object id cannot be reused.
        $key = spl_object_id($provider).'|'.$user->id.'|'.md5(serialize($filters));

        return $this->providerPassMemo[$key] ??= $provider->authorizedTasks($user, $filters);
    }

    /**
     * Merge canonical task sets and restore the normal queue order.
     *
     * @param  TaskItem[]  ...$itemSets
     * @return TaskItem[]
     */
    public function mergeItems(array ...$itemSets): array
    {
        $items = [];

        foreach ($itemSets as $itemSet) {
            foreach ($itemSet as $item) {
                $items[$item->id] = $item;
            }
        }

        return $this->sortItems(array_values($items));
    }

    /**
     * @param  TaskItem[]  $items
     * @return TaskItem[]
     */
    private function sortItems(array $items): array
    {
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
                fn (TaskItem $i) => $this->isWatched($i, $user, $watched),
            )),
        ];
    }

    /** Sidebar badge: my open + overdue item count, resolved per request. */
    public function badgeCountFor(User $user): int
    {
        return $this->countBadgeItems($this->itemsFor($user, []), $user);
    }

    /**
     * @param  TaskItem[]  $items
     */
    private function countBadgeItems(array $items, User $user): int
    {
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
                ->flatten()
                ->filter()
                ->implode(' ');
            $haystack = strtolower(implode(' ', array_filter([
                $item->ref,
                $journeyReferences,
                $item->title,
                $item->description,
                $item->sourceContext,
                $item->displayState,
                data_get($item->client, 'name'),
                data_get($item->site, 'name'),
                data_get($item->assignee, 'name'),
                ...$item->searchTerms,
            ])));
            if (! str_contains($haystack, strtolower($q))) {
                return false;
            }
        }

        // following=true: only items the user is watching (task_watchers).
        if (! empty($filters['following'])) {
            if (! $this->isWatched($item, $user, $this->watchedKeysFor($user))) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, true>  $watched
     */
    private function isWatched(TaskItem $item, User $user, array $watched): bool
    {
        $id = $item->numericId();
        $identityKey = $item->identitySource().'|'.$id;
        if (isset($watched[$identityKey])) {
            return true;
        }

        if ($item->identitySource() === $item->source
            || ! isset($watched[$item->source.'|'.$id])) {
            return false;
        }

        $provider = $this->providerFor($item->source);

        // Compatibility for rows written before composite providers gained
        // subtype identities. Record existence fixes one historical owner;
        // open/done presentation changes can never remap it.
        return $provider instanceof ProvidesTaskSourceAliases
            && $this->legacySourceAliasFor($provider, $user, $id) === $item->identitySource();
    }

    private function legacySourceAliasFor(
        ProvidesTaskSourceAliases $provider,
        User $user,
        int $id,
    ): ?string {
        return $provider->legacySourceAliasForId($user, $id);
    }
}
