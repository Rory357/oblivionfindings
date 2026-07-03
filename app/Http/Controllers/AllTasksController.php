<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Services\Tasks\Contracts\AssignableTaskProvider;
use App\Services\Tasks\Contracts\HasModelClass;
use App\Services\Tasks\TaskAggregator;
use App\Services\Tasks\TaskItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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

        $filters = [
            'sources' => $this->csv($params['sources'] ?? null),
            'severity' => $this->csv($params['severity'] ?? null),
            'bucket' => $this->csv($params['bucket'] ?? null),
            'assigned' => in_array($params['assigned'] ?? null, ['me', 'unassigned'], true)
                ? $params['assigned']
                : null,
            'overdue' => filter_var($params['overdue'] ?? false, FILTER_VALIDATE_BOOL),
            'due' => ($params['due'] ?? null) === 'week' ? 'week' : null,
            'q' => ($q = trim((string) ($params['q'] ?? ''))) === '' ? null : $q,
            'include_done' => filter_var($params['done'] ?? false, FILTER_VALIDATE_BOOL),
        ];

        // One provider pass; stats stay stable while filters slice the list.
        $items = $aggregator->itemsFor($user, ['include_done' => $filters['include_done']]);
        $filtered = $aggregator->filterItems($items, $user, $filters);

        if ($request->query('format') === 'csv') {
            return $this->exportCsv($filtered);
        }

        $page = max(1, (int) ($params['page'] ?? 1));
        $total = count($filtered);
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

        // Resolve through the provider's own feed so per-row visibility rules
        // (confidentiality, need-to-know redaction) hold for the drawer too.
        $item = collect($provider->tasks($user, ['include_done' => true]))
            ->first(fn (TaskItem $i) => $i->id === "{$source}-{$id}");

        abort_if($item === null, 404);

        $timeline = [];
        if ($provider instanceof HasModelClass) {
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

        return response()->json([
            'item' => $item->toArray(),
            'timeline' => $timeline,
            'canAssign' => $provider instanceof AssignableTaskProvider && $provider->canAssign($user),
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
        abort_unless($provider instanceof AssignableTaskProvider, 400, 'This record type cannot be assigned from the queue.');

        if (! $provider->canAssign($user)) {
            throw ValidationException::withMessages([
                'assignee_id' => 'You do not have permission to assign this record.',
            ]);
        }

        $assigneeId = isset($validated['assignee_id']) ? (int) $validated['assignee_id'] : null;

        $provider->assign($user, $id, $assigneeId);

        // Sidebar badges react to assignment immediately.
        Cache::forget("tasks.nav.{$user->id}");
        if ($assigneeId !== null) {
            Cache::forget("tasks.nav.{$assigneeId}");
        }

        \App\Services\Tasks\TaskAssignmentNotifier::notify($user, $provider, $id, $assigneeId);

        return back()->with('success', $assigneeId === null ? 'Task unassigned.' : 'Task assigned.');
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
            'view.*' => ['nullable', 'string', 'max:200'],
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
                    $item->status,
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
                ? \Illuminate\Support\Carbon::parse($item->createdAt)
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

        $modules = array_values($modules);
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
