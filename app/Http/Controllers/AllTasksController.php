<?php

namespace App\Http\Controllers;

use App\Exceptions\RecoverableTaskAuthorizationException;
use App\Models\AuditLog;
use App\Models\TaskWatcher;
use App\Models\User;
use App\Services\NotificationService;
use App\Services\Tasks\Contracts\AssignableTaskProvider;
use App\Services\Tasks\Contracts\HasModelClass;
use App\Services\Tasks\Contracts\SplittableTaskProvider;
use App\Services\Tasks\TaskAggregator;
use App\Services\Tasks\TaskAssignmentNotifier;
use App\Services\Tasks\TaskItem;
use App\Services\Tasks\TaskSearch;
use App\Services\UserSiteAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Company-wide "All Tasks" dashboard: every open incident, corrective
 * action, alert, concern and follow-up across the app in one
 * permission-filtered, ticket-numbered list. Rows deep-link back to the
 * module that owns the record; assignment is the one write action, and it
 * is delegated to providers that mirror their module's own rules.
 */
class AllTasksController extends Controller
{
    private const PER_PAGE = 50;

    public function index(Request $request, TaskAggregator $aggregator): Response|StreamedResponse
    {
        $user = $request->user();

        // A bare visit applies the user's saved default view (if any).
        $params = $request->query();
        $usingDefaultView = false;

        if ($params === [] && is_array($user->tasks_default_view) && $user->tasks_default_view !== []) {
            $params = $user->tasks_default_view;
            $usingDefaultView = true;
        }

        $bucketFilter = $this->csv($params['bucket'] ?? null);
        $includeDone = filter_var(
            $params['done'] ?? false,
            FILTER_VALIDATE_BOOL,
        ) || in_array(TaskItem::BUCKET_DONE, $bucketFilter ?? [], true);
        $filters = [
            'sources' => $this->csv($params['sources'] ?? null),
            'severity' => $this->csv($params['severity'] ?? null),
            'bucket' => $bucketFilter,
            'assigned' => in_array($params['assigned'] ?? null, ['me', 'unassigned'], true)
                ? $params['assigned']
                : null,
            'overdue' => filter_var($params['overdue'] ?? false, FILTER_VALIDATE_BOOL),
            'due' => ($params['due'] ?? null) === 'week' ? 'week' : null,
            'q' => ($q = trim((string) ($params['q'] ?? ''))) === '' ? null : $q,
            'following' => filter_var($params['following'] ?? false, FILTER_VALIDATE_BOOL),
            'include_done' => $includeDone,
        ];
        $returnTo = RecoverableTaskAuthorizationException::validatedReturnTo(
            $request->getRequestUri(),
        ) ?? '/tasks';

        // Stable stats and ordinary results use the normal capped dashboard
        // feed. Explicit incident-journey search adds one scoped deep pass
        // rather than re-running every provider or expanding unrelated feeds.
        $items = $aggregator->itemsFor($user, [
            'include_done' => $filters['include_done'],
            'return_to' => $returnTo,
        ]);
        $searchItems = $items;

        if (TaskSearch::hasQuery($filters)) {
            $journeySources = TaskSearch::incidentJourneySources($filters['sources']);

            if ($journeySources !== []) {
                $deepMatches = $aggregator->itemsFor($user, [
                    'sources' => $journeySources,
                    'include_done' => $filters['include_done'],
                    'q' => $filters['q'],
                    'return_to' => $returnTo,
                ]);
                $searchItems = $aggregator->mergeItems($items, $deepMatches);
            }
        }

        $filtered = $aggregator->filterItems($searchItems, $user, $filters);

        if ($request->query('format') === 'csv') {
            return $this->exportCsv($filtered);
        }

        $total = count($filtered);
        $lastPage = max(1, (int) ceil($total / self::PER_PAGE));
        // Clamp so a shrunken result set (or stale bookmark) can't land past
        // the end and render a false "all clear".
        $page = min(max(1, (int) ($params['page'] ?? 1)), $lastPage);
        $pageItems = array_slice($filtered, ($page - 1) * self::PER_PAGE, self::PER_PAGE);

        return Inertia::render('tasks/index', [
            'items' => array_map(fn (TaskItem $item) => $item->toArray(), $pageItems),
            'stats' => $aggregator->stats($items, $user),
            'sources' => $aggregator->sourcesFor($user),
            'filters' => $filters,
            'pagination' => [
                'page' => $page,
                'perPage' => self::PER_PAGE,
                'total' => $total,
            ],
            'usingDefaultView' => $usingDefaultView,
            'assignableSources' => $this->assignableSources($aggregator, $user),
        ]);
    }

    /**
     * Drawer payload: the (permission-scoped) item plus its audit timeline.
     */
    public function detail(Request $request, TaskAggregator $aggregator): JsonResponse
    {
        $user = $request->user();
        $source = (string) $request->query('source');
        $id = (int) $request->query('id');

        $provider = $aggregator->providerFor($source);
        abort_unless($provider !== null && $provider->canView($user), 404);
        $returnTo = RecoverableTaskAuthorizationException::validatedReturnTo(
            $request->query('return_to'),
        ) ?? '/tasks';

        // Resolve through the provider's exact-record path so per-row
        // visibility rules (confidentiality, need-to-know redaction) hold for
        // the drawer without inheriting presentation caps or search predicates.
        // Try the open state first, then include terminal records.
        $item = $aggregator->findItemFor(
            $user,
            $source,
            $id,
            $this->providerFiltersForReturnTo($returnTo),
        );

        abort_if($item === null, 404);

        $canOpen = $item->link !== null;
        $withholdSecondaryDetail = $item->restricted || ! $canOpen;

        $timeline = [];
        // Need-to-know rows get no timeline: audit actors reveal the reporter
        // and investigators — exactly what the owning register redacts. A
        // no-destination row likewise exposes only its owner guidance.
        if ($provider instanceof HasModelClass && ! $withholdSecondaryDetail) {
            $timeline = AuditLog::query()
                ->where('auditable_type', $provider->modelClass())
                ->where('auditable_id', $id)
                ->with('user:id,name')
                ->latest()
                ->limit(15)
                ->get()
                ->map(fn (AuditLog $log) => [
                    'id' => $log->id,
                    'action' => (string) $log->action,
                    'user' => $log->user?->name,
                    'at' => optional($log->created_at)->toIso8601String(),
                ])
                ->all();
        }

        // Watchers ("Following") for this exact source+id. On a need-to-know
        // row we withhold the follower list (revealing who is watching a
        // sensitive concern points at the investigators the register redacts)
        // — but the caller's OWN follow-state is always returned so the toggle
        // still works.
        $visibleWatcherIds = $withholdSecondaryDetail
            ? []
            : $aggregator->visibleWatcherIdsFor($source, $id);
        $isWatching = $canOpen
            && $aggregator->isUserWatching($user, $source, $id);
        $watchers = $withholdSecondaryDetail
            ? []
            : TaskWatcher::query()
                ->whereIn('source', $aggregator->watcherSourceKeysFor($source))
                ->where('item_id', $id)
                ->whereIn('user_id', $visibleWatcherIds)
                ->join('users', 'users.id', '=', 'task_watchers.user_id')
                ->distinct()
                ->orderBy('users.name')
                ->get(['users.id', 'users.name'])
                ->map(fn ($row) => ['id' => (int) $row->id, 'name' => (string) $row->name])
                ->all();

        return response()->json([
            'item' => $item->toArray(),
            'timeline' => $timeline,
            'canOpen' => $canOpen,
            'canWatch' => $canOpen,
            'canAssign' => $canOpen
                && $provider instanceof AssignableTaskProvider
                && $provider->canAssign($user),
            'watchers' => $watchers,
            'watchersHidden' => $withholdSecondaryDetail,
            'isWatching' => $isWatching,
            'canSplit' => $canOpen
                && $provider instanceof SplittableTaskProvider
                && $provider->canView($user),
        ]);
    }

    /**
     * Assign / reassign / unassign a queue item via its module's own rules.
     */
    public function assign(Request $request, TaskAggregator $aggregator, string $source, int $id): RedirectResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'assignee_id' => ['nullable', 'integer', 'exists:users,id'],
        ]);

        $provider = $aggregator->providerFor($source);

        abort_unless($provider !== null && $provider->canView($user), 404);
        abort_unless($aggregator->findItemFor($user, $source, $id) !== null, 404);
        abort_unless($provider instanceof AssignableTaskProvider, 400, 'This record type cannot be assigned from the queue.');

        if (! $provider->canAssign($user)) {
            return back()->with(
                'error',
                'You do not have permission to assign this record.',
            );
        }

        $assigneeId = isset($validated['assignee_id']) ? (int) $validated['assignee_id'] : null;

        try {
            $provider->assign($user, $id, $assigneeId);
        } catch (ValidationException $e) {
            // Surface module-rule rejections through the global flash toast —
            // neither the drawer nor the context menu renders field errors.
            return back()->with('error', collect($e->errors())->flatten()->first()
                ?? 'This record could not be assigned.');
        }

        // Sidebar badges react to assignment immediately.
        Cache::forget("tasks.nav.{$user->id}");
        if ($assigneeId !== null) {
            Cache::forget("tasks.nav.{$assigneeId}");
        }

        TaskAssignmentNotifier::notify(
            $user,
            $provider,
            $id,
            $assigneeId,
            $aggregator,
        );

        return back()->with('success', $assigneeId === null ? 'Task unassigned.' : 'Task assigned.');
    }

    /**
     * Follow / unfollow a queue item. Watchers get FYI notifications when the
     * item is reassigned (TaskAssignmentNotifier) or falls overdue
     * (EscalateOverdueTasks level 3) without owning it. Gated on the same
     * per-module view permission as everything else in the queue.
     */
    public function watch(Request $request, TaskAggregator $aggregator, string $source, int $id): RedirectResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'watching' => ['required', 'boolean'],
            'return_to' => ['nullable', 'string', 'max:2048'],
        ]);

        if (! $validated['watching']) {
            $ownWatcher = TaskWatcher::query()
                ->whereIn('source', $aggregator->watcherSourceKeysFor($source))
                ->where('item_id', $id)
                ->where('user_id', $user->id);

            // Revoked users may remove their own stale follower row without
            // resolving the now-hidden source record. A guessed foreign ID
            // with no caller-owned row retains the same generic 404 shape.
            abort_unless($ownWatcher->exists(), 404);
            $ownWatcher->delete();

            Cache::forget("tasks.nav.{$user->id}");

            return back()->with('success', 'Stopped following.');
        }

        $returnTo = RecoverableTaskAuthorizationException::validatedReturnTo(
            $validated['return_to'] ?? null,
        ) ?? '/tasks';
        $item = $aggregator->findItemFor(
            $user,
            $source,
            $id,
            $this->providerFiltersForReturnTo($returnTo),
        );
        abort_unless($item?->link !== null, 404);

        TaskWatcher::query()->firstOrCreate([
            'source' => $source,
            'item_id' => $id,
            'user_id' => $user->id,
        ]);

        // The badge helper counts watched items, so its cache must refresh.
        Cache::forget("tasks.nav.{$user->id}");

        return back()->with('success', 'Following this task.');
    }

    /**
     * "Split" a queue item into a child work item (follow-up / action plan)
     * via the owning module's own child-create rules. The provider mirrors
     * its module's permission, validation, column mapping and redaction; the
     * controller only validates the thin cross-cutting shape and delegates.
     */
    public function split(Request $request, TaskAggregator $aggregator, string $source, int $id): RedirectResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:2000'],
            'assignee_id' => ['nullable', 'integer', 'exists:users,id'],
            'due_at' => ['nullable', 'date'],
        ]);

        $provider = $aggregator->providerFor($source);
        abort_unless($provider !== null && $provider->canView($user), 404);
        abort_unless($aggregator->findItemFor($user, $source, $id) !== null, 404);
        abort_unless($provider instanceof SplittableTaskProvider, 400, 'This record type cannot be split into a child task.');

        try {
            $childLink = $provider->createChild($user, $id, $validated);
        } catch (ValidationException $e) {
            // Module-rule / permission / redaction rejections flow to the flash
            // toast — the split dialog does not render field errors.
            return back()->with('error', collect($e->errors())->flatten()->first()
                ?? 'This task could not be split.');
        }

        $assigneeId = isset($validated['assignee_id']) ? (int) $validated['assignee_id'] : null;

        // FYI the new child's assignee (never the actor). Personal ping only —
        // the false trio stops NotificationService fanning it to every manager.
        if ($assigneeId !== null && $assigneeId !== $user->id) {
            $parent = $aggregator->findItemFor($user, $source, $id);

            app(NotificationService::class)->notifyCrud(
                actor: $user,
                action: 'assigned',
                entityLabel: 'Task',
                entity: null,
                extra: [
                    'event_key' => 'tasks.assigned',
                    'title' => "You've been assigned a {$provider->childLabel()}",
                    'body' => $validated['title'],
                    'url' => $childLink ?? $parent?->link ?? '/tasks?assigned=me',
                    'target_user_ids' => [$assigneeId],
                    'include_managers' => false,
                    'include_assigned_workers' => false,
                    'include_entity_user' => false,
                ],
            );

            Cache::forget("tasks.nav.{$assigneeId}");
        }

        Cache::forget("tasks.nav.{$user->id}");

        return back()->with('success', 'Child task created.');
    }

    /**
     * Staff picker for the split-assignee field. Mirrors the inline
     * User::staff() pattern used across modules; no standalone staff-search
     * endpoint existed, so this adds a thin typeahead just for the queue.
     */
    public function users(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q'));

        $users = User::query()
            ->staff()
            ->tap(fn ($users) => app(UserSiteAccessService::class)->applyStaffScope(
                $users,
                $request->user(),
                ['reports.viewAny'],
            ))
            ->when($q !== '', fn ($query) => $query->where('name', 'like', '%'.$q.'%'))
            ->orderBy('name')
            ->limit(20)
            ->get(['id', 'name']);

        return response()->json(['users' => $users]);
    }

    /**
     * Exact ticket-number lookup (global search): INC-2026-0042 → deep link.
     */
    public function lookup(Request $request, TaskAggregator $aggregator): JsonResponse
    {
        $user = $request->user();
        $q = strtoupper(trim((string) $request->query('q')));

        if ($q === '' || ! preg_match('/^[A-Z]{2,4}-\d/', $q)) {
            return response()->json(['match' => null]);
        }

        $match = collect($aggregator->itemsFor($user, ['include_done' => true]))
            ->first(fn (TaskItem $i) => strtoupper((string) $i->ref) === $q);

        return response()->json([
            'match' => $match ? [
                'ref' => $match->ref,
                'title' => $match->title,
                'sourceLabel' => $match->sourceLabel,
                'link' => $match->link,
            ] : null,
        ]);
    }

    /**
     * Persist the caller's current filter set as their default /tasks view.
     */
    public function saveDefaultView(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'view' => ['nullable', 'array'],
            // All provider keys as one CSV run past 250 chars — cap generously.
            'view.*' => ['nullable', 'string', 'max:500'],
        ]);

        $view = array_filter($validated['view'] ?? [], fn ($v) => $v !== null && $v !== '');

        $request->user()->forceFill([
            'tasks_default_view' => $view === [] ? null : $view,
        ])->save();

        return back()->with('success', $view === [] ? 'Default view cleared.' : 'Saved as your default view.');
    }

    /**
     * Source keys the user may assign from the queue, for the frontend.
     *
     * @return string[]
     */
    private function assignableSources(TaskAggregator $aggregator, $user): array
    {
        $keys = [];

        foreach ($aggregator->sourcesFor($user) as $source) {
            $provider = $aggregator->providerFor($source['key']);
            if ($provider instanceof AssignableTaskProvider && $provider->canAssign($user)) {
                $keys[] = $source['key'];
            }
        }

        return $keys;
    }

    /**
     * @return array{return_to: string}
     */
    private function providerFiltersForReturnTo(string $returnTo): array
    {
        return ['return_to' => $returnTo];
    }

    /**
     * Stream the current (filtered) queue as a spreadsheet-safe CSV.
     *
     * @param  TaskItem[]  $items
     */
    private function exportCsv(array $items): StreamedResponse
    {
        return response()->streamDownload(function () use ($items): void {
            $out = fopen('php://output', 'w');

            $this->putCsv($out, [
                'Ticket', 'Title', 'Type', 'Module', 'Severity', 'Status',
                'Assignee', 'Client', 'Site', 'Due', 'Overdue', 'Created', 'Link',
            ]);

            foreach ($items as $item) {
                $this->putCsv($out, [
                    $item->ref,
                    $item->title,
                    $item->type,
                    $item->sourceLabel,
                    $item->severity,
                    $item->displayState ?? $item->status,
                    $item->assignee['name'] ?? '',
                    $item->client['name'] ?? '',
                    $item->site['name'] ?? '',
                    $item->dueAt,
                    $item->isOverdue() ? 'yes' : 'no',
                    $item->createdAt,
                    $item->link,
                ]);
            }

            fclose($out);
        }, 'all-tasks-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv']);
    }

    /**
     * @return string[]|null
     */
    private function csv(?string $value): ?array
    {
        $parts = array_values(array_filter(array_map('trim', explode(',', (string) $value))));

        return $parts === [] ? null : $parts;
    }

    /**
     * GET /tasks/reports — read-only analytics over the aggregated queue:
     * per-module open/overdue counts, aging buckets, severity mix and a
     * rough 30-day open-vs-closed throughput.
     *
     * CAVEAT: most providers cap their "done" feed to recently-completed
     * records, so the "closed in last 30 days" figure is rough throughput,
     * not an exact ledger. Ages are measured from each item's createdAt;
     * items with no createdAt are excluded from the aging buckets (so a
     * module's buckets may sum to less than its open count).
     */
    public function reports(Request $request, TaskAggregator $aggregator): Response
    {
        $user = $request->user();

        // One aggregator pass, done items included — same permission scoping
        // as the queue itself.
        $items = $aggregator->itemsFor($user, ['include_done' => true]);

        $now = now();
        $cutoff30 = $now->copy()->subDays(30);

        $severity = array_fill_keys(TaskItem::SEVERITIES, 0);
        $modules = [];

        $openTotal = 0;
        $overdueTotal = 0;
        $doneTotal = 0;
        $opened30 = 0; // any item (open or done) created in the last 30 days
        $closed30 = 0; // done items created in the last 30 days (see caveat)

        foreach ($items as $item) {
            if (! isset($modules[$item->source])) {
                $modules[$item->source] = [
                    'key' => $item->source,
                    'label' => $item->sourceLabel,
                    'open' => 0,
                    'overdue' => 0,
                    // Aging over OPEN items, from createdAt: 0–7d / 8–30d / 31d+.
                    'aging' => ['fresh' => 0, 'aging' => 0, 'stale' => 0],
                ];
            }

            $created = $item->createdAt !== null
                ? Carbon::parse($item->createdAt)
                : null;

            if ($created !== null && $created->gte($cutoff30)) {
                $opened30++;
            }

            if ($item->bucket === TaskItem::BUCKET_DONE) {
                $doneTotal++;
                if ($created !== null && $created->gte($cutoff30)) {
                    $closed30++;
                }

                continue;
            }

            $openTotal++;
            $severity[$item->severity] = ($severity[$item->severity] ?? 0) + 1;
            $modules[$item->source]['open']++;

            if ($item->isOverdue()) {
                $overdueTotal++;
                $modules[$item->source]['overdue']++;
            }

            if ($created !== null) {
                $age = (int) floor($created->diffInDays($now));
                $bucket = $age <= 7 ? 'fresh' : ($age <= 30 ? 'aging' : 'stale');
                $modules[$item->source]['aging'][$bucket]++;
            }
        }

        // Done-only sources have nothing actionable to report — drop the
        // all-zero rows rather than padding the breakdown table.
        $modules = array_values(array_filter(
            $modules,
            fn (array $m) => $m['open'] > 0 || $m['overdue'] > 0,
        ));
        usort($modules, fn (array $a, array $b) => [$b['open'], $b['overdue'], $a['label']] <=> [$a['open'], $a['overdue'], $b['label']]);

        return Inertia::render('tasks/reports', [
            'totals' => [
                'open' => $openTotal,
                'overdue' => $overdueTotal,
                'done' => $doneTotal,
            ],
            'modules' => $modules,
            'severity' => $severity,
            'closure' => [
                'opened30' => $opened30,
                'closed30' => $closed30,
            ],
            'sources' => $aggregator->sourcesFor($user),
            'generatedAt' => $now->toIso8601String(),
        ]);
    }
}
