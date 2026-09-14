<?php

namespace App\Domain\Governance\Http\Controllers;

use App\Domain\Governance\Http\Requests\StoreActionItemRequest;
use App\Domain\Governance\Models\ActionItem;
use App\Domain\Governance\Models\GovernanceMeeting;
use App\Domain\Governance\Models\Resolution;
use App\Domain\Governance\Services\GovernanceRecordAccessService;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;

class ActionItemController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $accessService = app(GovernanceRecordAccessService::class);

        // Scoped base query shared by the list, the summary and filter options.
        $baseQuery = ActionItem::query();
        $accessService->scopeActionItems($baseQuery, $user);

        $query = (clone $baseQuery)->with(['assignedTo:id,name', 'completedBy:id,name', 'createdBy:id,name']);

        // Filter by assignment
        $mine = ($request->has('assigned_to_me') && ! in_array((string) $request->input('assigned_to_me'), ['0', 'false'], true))
            || $request->input('filter') === 'my_work';
        if ($mine) {
            $query->forUser($user->id);
        }

        $status = $request->filled('status')
            ? (string) $request->input('status')
            : ($request->boolean('overdue') ? 'overdue' : null);

        if ($status === 'overdue') {
            $query->overdue();
        } elseif ($status === 'active') {
            $query->open();
        } elseif ($status !== null) {
            $query->where('status', $status);
        }

        if ($request->boolean('overdue') && $status !== 'overdue') {
            $query->overdue();
        }

        $priority = $request->filled('priority') ? (string) $request->input('priority') : null;
        if ($priority === 'elevated') {
            $query->highPriority();
        } elseif ($priority !== null) {
            $query->where('priority', $priority);
        }

        if ($request->filled('source_type')) {
            $query->where('source_type', (string) $request->input('source_type'));
        }

        $assignee = filter_var($request->input('assignee'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($assignee !== false) {
            $query->forUser($assignee);
        }

        if ($request->filled('search')) {
            $search = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], trim((string) $request->input('search'))).'%';
            $query->where(function ($q) use ($search) {
                $q->where('action_reference', 'like', $search)
                    ->orWhere('title', 'like', $search)
                    ->orWhere('description', 'like', $search);
            });
        }

        $items = $query->orderBy('due_date')->paginate(20)->withQueryString();

        $summary = [
            'total_open' => (clone $baseQuery)->open()->count(),
            'overdue' => (clone $baseQuery)->overdue()->count(),
            'my_open' => (clone $baseQuery)->forUser($user->id)->open()->count(),
            'high_priority' => (clone $baseQuery)->highPriority()->open()->count(),
            'blocked' => (clone $baseQuery)->blocked()->count(),
        ];

        // Filter options come from records this viewer can already see.
        $sourceTypes = (clone $baseQuery)
            ->whereNotNull('source_type')
            ->distinct()
            ->orderBy('source_type')
            ->pluck('source_type')
            ->map(fn ($type) => [
                'value' => (string) $type,
                'label' => Str::headline(class_basename((string) $type)),
            ])
            ->values();

        $assignees = User::query()
            ->whereIn('id', (clone $baseQuery)->whereNotNull('assigned_to')->select('assigned_to'))
            ->orderBy('name')
            ->get(['id', 'name']);

        return Inertia::render('Governance/Actions/Index', [
            'items' => $items,
            'summary' => $summary,
            'filters' => [
                'status' => $status,
                'priority' => $priority,
                'source_type' => $request->filled('source_type') ? (string) $request->input('source_type') : null,
                'assignee' => $assignee !== false ? (string) $assignee : null,
                'assigned_to_me' => $mine,
                'search' => $request->filled('search') ? (string) $request->input('search') : null,
            ],
            'source_types' => $sourceTypes,
            'assignees' => $assignees,
        ]);
    }

    public function show(Request $request, ActionItem $action)
    {
        $this->authorize('view', $action);

        $action->load(['assignedTo', 'completedBy', 'createdBy', 'escalatedBy:id,name']);

        $user = $request->user();
        $accessService = app(GovernanceRecordAccessService::class);

        // Safe resolution of polymorphic source details without private data leaks
        $sourceDetails = null;
        if ($action->source_type && $action->source_id) {
            if (in_array($action->source_type, [Resolution::class, 'resolution'])) {
                $res = Resolution::find($action->source_id);
                if ($res && $accessService->canViewResolution($user, $res)) {
                    $sourceDetails = [
                        'type' => 'resolution',
                        'id' => $res->id,
                        'reference' => $res->resolution_reference,
                        'title' => $res->title,
                        'status' => $res->status,
                        'outcome' => $res->outcome,
                        'url' => route('governance.resolutions.show', $res),
                    ];
                } else {
                    $sourceDetails = [
                        'type' => 'resolution',
                        'id' => $action->source_id,
                        'is_restricted' => true,
                    ];
                }
            } elseif (in_array($action->source_type, [GovernanceMeeting::class, 'meeting', 'governance_meeting'])) {
                $meeting = GovernanceMeeting::find($action->source_id);
                if ($meeting && $accessService->canViewMeeting($user, $meeting)) {
                    $sourceDetails = [
                        'type' => 'meeting',
                        'id' => $meeting->id,
                        'title' => $meeting->title,
                        'scheduled_at' => $meeting->scheduled_at?->toIso8601String(),
                        'url' => route('governance.meetings.show', $meeting),
                    ];
                } else {
                    $sourceDetails = [
                        'type' => 'meeting',
                        'id' => $action->source_id,
                        'is_restricted' => true,
                    ];
                }
            }
        }

        $canUpdate = $user->can('update', $action);

        return Inertia::render('Governance/Actions/Show', [
            'action' => $action,
            'source_details' => $sourceDetails,
            // Reassignment options are only needed by people who can reassign.
            'assignees' => $canUpdate
                ? User::query()->whereNotNull('approved_at')->orderBy('name')->get(['id', 'name', 'email'])
                : [],
            'can_update' => $canUpdate,
            // Contextual opening (e.g. from a meeting paper): a same-origin
            // Governance path only — anything else is ignored.
            'return_to' => $this->safeReturnPath($request->query('return')),
        ]);
    }

    public function complete(Request $request, ActionItem $action)
    {
        $this->authorize('update', $action);

        $validated = $request->validate([
            'completion_notes' => 'required|string|min:3|max:2000',
            'evidence_files' => 'nullable|array',
            'expected_version' => 'required|integer',
            'return_to' => 'nullable|string|max:2048',
        ]);

        if ($action->evidence_required && empty($validated['evidence_files']) && empty($action->evidence_attachments)) {
            return redirect()->back()->with('error', 'Evidence documentation is required to complete this action item.');
        }

        try {
            $receipt = $action->markComplete(
                auth()->id(),
                $validated['completion_notes'],
                $validated['evidence_files'] ?? null,
                $validated['expected_version'],
            );

            return $this->redirectAfterMutation($request, "Action item completed. Receipt: {$receipt}");
        } catch (\DomainException $e) {
            if (str_contains($e->getMessage(), 'modified by another user')) {
                return $this->conflictResponse($request, $e->getMessage());
            }
            if ($request->wantsJson()) {
                return response()->json(['error' => $e->getMessage()], 422);
            }

            return redirect()->back()->with('error', $e->getMessage());
        }
    }

    public function store(StoreActionItemRequest $request)
    {
        $validated = $request->validated();

        ActionItem::create([
            ...$validated,
            'title' => $validated['title'] ?? ($validated['description'] ? Str::limit($validated['description'], 60) : null),
            'created_by' => auth()->id(),
            'status' => 'open',
            'version_number' => 1,
        ]);

        return redirect()->back()->with('success', 'Action item created.');
    }

    public function updateProgress(Request $request, ActionItem $action)
    {
        $this->authorize('update', $action);

        $validated = $request->validate([
            'progress_pct' => 'required|integer|min:0|max:100',
            'progress_notes' => 'nullable|string|max:1000',
            'expected_version' => 'required|integer',
            'return_to' => 'nullable|string|max:2048',
        ]);

        try {
            $action->updateProgress(
                $validated['progress_pct'],
                $validated['progress_notes'] ?? null,
                $validated['expected_version'],
            );

            return $this->redirectAfterMutation($request, 'Progress updated.');
        } catch (\DomainException $e) {
            if (str_contains($e->getMessage(), 'modified by another user')) {
                return $this->conflictResponse($request, $e->getMessage());
            }
            if ($request->wantsJson()) {
                return response()->json(['error' => $e->getMessage()], 422);
            }

            return redirect()->back()->with('error', $e->getMessage());
        }
    }

    public function block(Request $request, ActionItem $action)
    {
        $this->authorize('update', $action);

        $validated = $request->validate([
            'blocked_reason' => 'required|string|max:500',
            'expected_version' => 'required|integer',
        ]);

        try {
            $action->block($validated['blocked_reason'], $validated['expected_version']);

            return redirect()->back()->with('success', 'Action item marked as blocked.');
        } catch (\DomainException $e) {
            if (str_contains($e->getMessage(), 'modified by another user')) {
                return $this->conflictResponse($request, $e->getMessage());
            }

            return redirect()->back()->with('error', $e->getMessage());
        }
    }

    public function unblock(Request $request, ActionItem $action)
    {
        $this->authorize('update', $action);

        $validated = $request->validate([
            'expected_version' => 'required|integer',
        ]);

        try {
            $action->unblock($validated['expected_version']);

            return redirect()->back()->with('success', 'Action item unblocked.');
        } catch (\DomainException $e) {
            if (str_contains($e->getMessage(), 'modified by another user')) {
                return $this->conflictResponse($request, $e->getMessage());
            }

            return redirect()->back()->with('error', $e->getMessage());
        }
    }

    public function escalate(Request $request, ActionItem $action)
    {
        $this->authorize('update', $action);

        $validated = $request->validate([
            'escalation_reason' => 'required|string|max:500',
            'expected_version' => 'required|integer',
        ]);

        try {
            $action->escalate(auth()->id(), $validated['escalation_reason'], $validated['expected_version']);

            return redirect()->back()->with('success', 'Action item escalated.');
        } catch (\DomainException $e) {
            if (str_contains($e->getMessage(), 'modified by another user')) {
                return $this->conflictResponse($request, $e->getMessage());
            }

            return redirect()->back()->with('error', $e->getMessage());
        }
    }

    public function reassign(Request $request, ActionItem $action)
    {
        $this->authorize('update', $action);

        $validated = $request->validate([
            'assigned_to' => 'required|exists:users,id',
            'expected_version' => 'required|integer',
        ]);

        if ((int) ($action->version_number ?? 1) !== (int) $validated['expected_version']) {
            return $this->conflictResponse($request, 'Action item was modified by another user. Please reload and review the latest changes.');
        }

        $action->update([
            'assigned_to' => $validated['assigned_to'],
            'version_number' => ($action->version_number ?? 1) + 1,
        ]);

        return redirect()->back()->with('success', 'Action item reassigned.');
    }

    /**
     * After a successful update/completion, return the member to the place
     * they opened the action from (a validated Governance path), otherwise
     * back to the action itself.
     */
    private function redirectAfterMutation(Request $request, string $message)
    {
        $returnTo = $this->safeReturnPath($request->input('return_to'));

        return ($returnTo !== null ? redirect()->to($returnTo) : redirect()->back())
            ->with('success', $message);
    }

    /**
     * A stale expected_version. Inertia visits get a flash they can show in
     * place; other clients keep the explicit 409.
     */
    private function conflictResponse(Request $request, string $message)
    {
        if ($request->header('X-Inertia')) {
            return redirect()->back()->with('error', $message);
        }

        abort(409, $message);
    }

    /**
     * Only a same-origin, relative Governance path is an acceptable return
     * destination — never an absolute/protocol-relative URL, a backslash or
     * control-character trick, or a dot-segment escape (no open redirect).
     */
    private function safeReturnPath(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);
        if ($value === '' || strlen($value) > 2048) {
            return null;
        }

        if (preg_match('/[\x00-\x1F\x7F\\\\]/', $value) === 1 || ! str_starts_with($value, '/governance/')) {
            return null;
        }

        $parts = parse_url($value);
        if ($parts === false || isset($parts['scheme']) || isset($parts['host']) || isset($parts['user']) || isset($parts['port'])) {
            return null;
        }

        $path = rawurldecode($parts['path'] ?? '');
        if (! str_starts_with($path, '/governance/')
            || str_contains($path, '//')
            || str_contains($path, '\\')
            || preg_match('#(^|/)\.{1,2}(/|$)#', $path) === 1) {
            return null;
        }

        return $value;
    }
}
