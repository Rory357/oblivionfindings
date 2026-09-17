<?php

namespace App\Domain\Governance\Http\Controllers;

use App\Domain\Governance\Http\Requests\StoreActionItemRequest;
use App\Domain\Governance\Models\ActionItem;
use App\Domain\Governance\Models\GovernanceMeeting;
use App\Domain\Governance\Models\Resolution;
use App\Domain\Governance\Services\ActionItemEscalationNotifier;
use App\Domain\Governance\Services\GovernanceAuditService;
use App\Domain\Governance\Services\GovernanceRecordAccessService;
use App\Domain\Governance\Support\GovernanceLabels;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
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

        $query = (clone $baseQuery)
            ->with(['assignedTo:id,name', 'completedBy:id,name', 'createdBy:id,name'])
            ->withCount('evidence');

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

        $items = $query->orderBy('due_date')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (ActionItem $item) => $this->presentIndexRow($item));

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
                'label' => GovernanceLabels::label('action_source', (string) $type),
            ])
            ->unique('label')
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

        $action->load(['assignedTo:id,name', 'completedBy:id,name', 'createdBy:id,name', 'escalatedBy:id,name', 'evidence.uploadedBy:id,name']);

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
            'action' => $this->presentAction($action, $user),
            'source_details' => $sourceDetails,
            // Reassignment options are only needed by people who can reassign —
            // names only, never contact details.
            'assignees' => $canUpdate
                ? User::query()->whereNotNull('approved_at')->orderBy('name')->get(['id', 'name'])
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
            'evidence_ids' => 'nullable|array',
            'evidence_ids.*' => 'integer',
            'expected_version' => 'required|integer',
            'return_to' => 'nullable|string|max:2048',
        ], [
            'completion_notes.required' => 'Add a short note about what was done.',
            'completion_notes.min' => 'Add a little more detail about what was done.',
            'completion_notes.max' => 'Keep the note under 2,000 characters.',
            'evidence_ids.*.integer' => "One of the evidence files couldn't be found. Reload the page and try again.",
            'expected_version.required' => 'Reload the page and try again.',
        ]);

        if ($action->evidence_required
            && empty($validated['evidence_files'])
            && empty($validated['evidence_ids'])
            && empty($action->evidence_attachments)
            && ! $action->evidence()->exists()) {
            return redirect()->back()->with('error', ActionItem::EVIDENCE_NEEDED_MESSAGE);
        }

        try {
            $receipt = $action->markComplete(
                auth()->id(),
                $validated['completion_notes'],
                $validated['evidence_files'] ?? null,
                $validated['expected_version'],
                $validated['evidence_ids'] ?? null,
            );

            return $this->redirectAfterMutation($request, "Action marked as done. Receipt {$receipt}.");
        } catch (\DomainException $e) {
            if ($e->getMessage() === ActionItem::STALE_VERSION_MESSAGE) {
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

        return redirect()->back()->with('success', 'Action added.');
    }

    public function updateProgress(Request $request, ActionItem $action)
    {
        $this->authorize('update', $action);

        $validated = $request->validate([
            'progress_pct' => 'required|integer|min:0|max:100',
            'progress_notes' => 'nullable|string|max:1000',
            'expected_version' => 'required|integer',
            'return_to' => 'nullable|string|max:2048',
        ], [
            'progress_pct.required' => 'Choose how far along the work is.',
            'progress_pct.min' => 'Progress must be between 0% and 100%.',
            'progress_pct.max' => 'Progress must be between 0% and 100%.',
            'progress_notes.max' => 'Keep the update under 1,000 characters.',
            'expected_version.required' => 'Reload the page and try again.',
        ]);

        try {
            $action->updateProgress(
                $validated['progress_pct'],
                $validated['progress_notes'] ?? null,
                $validated['expected_version'],
            );

            return $this->redirectAfterMutation($request, 'Progress saved.');
        } catch (\DomainException $e) {
            if ($e->getMessage() === ActionItem::STALE_VERSION_MESSAGE) {
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
        ], [
            'blocked_reason.required' => "Say what's stopping the work.",
            'blocked_reason.max' => 'Keep the reason under 500 characters.',
            'expected_version.required' => 'Reload the page and try again.',
        ]);

        try {
            $action->block($validated['blocked_reason'], $validated['expected_version']);

            return redirect()->back()->with('success', 'Action marked as blocked.');
        } catch (\DomainException $e) {
            if ($e->getMessage() === ActionItem::STALE_VERSION_MESSAGE) {
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
        ], [
            'expected_version.required' => 'Reload the page and try again.',
        ]);

        try {
            $action->unblock($validated['expected_version']);

            return redirect()->back()->with('success', 'Blocker removed. The action is back in progress.');
        } catch (\DomainException $e) {
            if ($e->getMessage() === ActionItem::STALE_VERSION_MESSAGE) {
                return $this->conflictResponse($request, $e->getMessage());
            }

            return redirect()->back()->with('error', $e->getMessage());
        }
    }

    /**
     * "Raise with the board": record why, raise the priority and tell the
     * board chair and secretary (the dialog promises exactly this).
     */
    public function escalate(Request $request, ActionItem $action, ActionItemEscalationNotifier $notifier)
    {
        $this->authorize('update', $action);

        $validated = $request->validate([
            'escalation_reason' => 'required|string|max:500',
            'expected_version' => 'required|integer',
        ], [
            'escalation_reason.required' => 'Say why the board needs to look at this action.',
            'escalation_reason.max' => 'Keep the reason under 500 characters.',
            'expected_version.required' => 'Reload the page and try again.',
        ]);

        try {
            $action->escalate(auth()->id(), $validated['escalation_reason'], $validated['expected_version']);
        } catch (\DomainException $e) {
            if ($e->getMessage() === ActionItem::STALE_VERSION_MESSAGE) {
                return $this->conflictResponse($request, $e->getMessage());
            }

            return redirect()->back()->with('error', $e->getMessage());
        }

        $action->refresh()->loadMissing('assignedTo:id,name');
        $notified = $notifier->notifyBoardLeaders($action, $request->user());

        GovernanceAuditService::log('action.escalated', 'ActionItem', (int) $action->id, [
            'chair_notified' => $notified['chair'],
            'secretary_notified' => $notified['secretary'],
        ]);

        $message = ActionItemEscalationNotifier::summary($notified);

        return redirect()->back()->with(
            $notified['chair'] || $notified['secretary'] ? 'success' : 'warning',
            $message,
        );
    }

    public function reassign(Request $request, ActionItem $action)
    {
        $this->authorize('update', $action);

        $validated = $request->validate([
            'assigned_to' => ['required', Rule::exists('users', 'id')->whereNotNull('approved_at')],
            'expected_version' => 'required|integer',
        ], [
            'assigned_to.required' => 'Choose who should own this action.',
            'assigned_to.exists' => "That person can't own actions. Choose someone with an approved login.",
            'expected_version.required' => 'Reload the page and try again.',
        ]);

        if ($action->status === 'complete') {
            return redirect()->back()->with('error', ActionItem::ALREADY_DONE_MESSAGE);
        }

        if ((int) ($action->version_number ?? 1) !== (int) $validated['expected_version']) {
            return $this->conflictResponse($request, ActionItem::STALE_VERSION_MESSAGE);
        }

        $action->update([
            'assigned_to' => $validated['assigned_to'],
            'version_number' => ($action->version_number ?? 1) + 1,
        ]);

        $ownerName = User::query()->whereKey($validated['assigned_to'])->value('name');

        return redirect()->back()->with('success', $ownerName ? "{$ownerName} now owns this action." : 'Owner changed.');
    }

    /**
     * @return array<string, mixed>
     */
    private function presentIndexRow(ActionItem $item): array
    {
        $uploaded = (int) ($item->evidence_count ?? 0);
        $legacy = is_array($item->evidence_attachments) ? count($item->evidence_attachments) : 0;
        $evidenceCount = $uploaded + $legacy;

        return [
            'id' => (int) $item->id,
            'action_reference' => $item->action_reference,
            'title' => $item->title,
            'description' => $item->description,
            'due_date' => $item->due_date?->toDateString(),
            'status' => $item->status,
            'priority' => $item->priority,
            'assigned_to' => $item->assignedTo ? ['id' => (int) $item->assignedTo->id, 'name' => $item->assignedTo->name] : null,
            'source_type' => $item->source_type,
            'source_id' => $item->source_id,
            'progress_pct' => (int) ($item->progress_pct ?? 0),
            'evidence_required' => (bool) $item->evidence_required,
            'evidence_count' => $evidenceCount,
        ];
    }

    /**
     * The action as the page needs it. Evidence is listed by file name with an
     * authorised download link; storage paths never leave the server.
     *
     * @return array<string, mixed>
     */
    private function presentAction(ActionItem $action, User $viewer): array
    {
        $person = fn (?User $user) => $user ? ['id' => (int) $user->id, 'name' => $user->name] : null;
        $legacyPaths = is_array($action->evidence_attachments) ? array_values($action->evidence_attachments) : [];
        $canManageEvidence = $viewer->canDo('governance.actions.manage');

        return [
            'id' => (int) $action->id,
            'action_reference' => $action->action_reference,
            'title' => $action->title,
            'description' => $action->description,
            'due_date' => $action->due_date?->toDateString(),
            'status' => $action->status,
            'priority' => $action->priority,
            'source_type' => $action->source_type,
            'source_id' => $action->source_id,
            'evidence_required' => (bool) $action->evidence_required,
            'completion_notes' => $action->completion_notes,
            'completion_receipt' => $action->completion_receipt,
            'completed_at' => $action->completed_at?->toIso8601String(),
            'progress_pct' => (int) ($action->progress_pct ?? 0),
            'progress_notes' => $action->progress_notes,
            'version_number' => (int) ($action->version_number ?? 1),
            'blocked_at' => $action->blocked_at?->toIso8601String(),
            'blocked_reason' => $action->blocked_reason,
            'escalated_at' => $action->escalated_at?->toIso8601String(),
            'escalation_reason' => $action->wasEscalatedAutomatically() ? null : $action->escalation_reason,
            'escalated_automatically' => $action->wasEscalatedAutomatically(),
            'assigned_to' => $person($action->assignedTo),
            'completed_by' => $person($action->completedBy),
            'created_by' => $person($action->createdBy),
            'escalated_by' => $action->wasEscalatedAutomatically() ? null : $person($action->escalatedBy),
            'evidence' => $action->evidence
                ->map(fn ($evidence) => [
                    ...$evidence->present(),
                    'can_remove' => $action->status !== 'complete'
                        && ($canManageEvidence || (int) $evidence->uploaded_by === (int) $viewer->id),
                ])
                ->values()
                ->all(),
            'legacy_evidence' => collect($legacyPaths)
                ->map(fn ($path, int $index) => [
                    'index' => $index,
                    'original_name' => basename((string) (is_array($path) ? ($path['path'] ?? $path['file_path'] ?? '') : $path)),
                    'download_url' => "/governance/actions/{$action->id}/evidence/earlier/{$index}/download",
                ])
                ->values()
                ->all(),
        ];
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
