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
use Inertia\Inertia;

class ActionItemController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $accessService = app(GovernanceRecordAccessService::class);

        $query = ActionItem::with(['assignedTo', 'completedBy', 'createdBy']);
        $accessService->scopeActionItems($query, $user);

        // Filter by assignment
        if ($request->has('assigned_to_me') || $request->input('filter') === 'my_work') {
            $query->forUser($user->id);
        }

        if ($request->filled('status')) {
            if ($request->status === 'overdue') {
                $query->overdue();
            } else {
                $query->where('status', $request->status);
            }
        }

        if ($request->boolean('overdue')) {
            $query->overdue();
        }

        if ($request->filled('priority')) {
            $query->where('priority', $request->priority);
        }

        if ($request->filled('source_type')) {
            $query->where('source_type', $request->source_type);
        }

        if ($request->filled('search')) {
            $search = '%' . $request->search . '%';
            $query->where(function ($q) use ($search) {
                $q->where('action_reference', 'like', $search)
                    ->orWhere('title', 'like', $search)
                    ->orWhere('description', 'like', $search);
            });
        }

        $items = $query->orderBy('due_date')->paginate(20)->withQueryString();

        // Scoped base query for accurate summary counts
        $baseCountQuery = ActionItem::query();
        $accessService->scopeActionItems($baseCountQuery, $user);

        $summary = [
            'total_open' => (clone $baseCountQuery)->open()->count(),
            'overdue' => (clone $baseCountQuery)->overdue()->count(),
            'my_open' => (clone $baseCountQuery)->forUser($user->id)->open()->count(),
            'high_priority' => (clone $baseCountQuery)->highPriority()->open()->count(),
        ];

        // Active assignees for filters or task assignments
        $assignees = User::query()
            ->whereNotNull('approved_at')
            ->orderBy('name')
            ->get(['id', 'name', 'email']);

        return Inertia::render('Governance/Actions/Index', [
            'items' => $items,
            'summary' => $summary,
            'filters' => $request->only(['status', 'priority', 'source_type', 'assigned_to_me', 'search']),
            'assignees' => $assignees,
        ]);
    }

    public function show(ActionItem $action)
    {
        $this->authorize('view', $action);

        $action->load(['assignedTo', 'completedBy', 'createdBy']);

        $user = auth()->user();
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

        $assignees = User::query()
            ->whereNotNull('approved_at')
            ->orderBy('name')
            ->get(['id', 'name', 'email']);

        return Inertia::render('Governance/Actions/Show', [
            'action' => $action,
            'source_details' => $sourceDetails,
            'assignees' => $assignees,
            'can_update' => $user->can('update', $action),
        ]);
    }

    public function complete(Request $request, ActionItem $action)
    {
        $this->authorize('update', $action);

        $validated = $request->validate([
            'completion_notes' => 'required|string|min:3|max:2000',
            'evidence_files' => 'nullable|array',
            'expected_version' => 'nullable|integer',
        ]);

        if ($action->evidence_required && empty($validated['evidence_files']) && empty($action->evidence_attachments)) {
            return redirect()->back()->with('error', 'Evidence documentation is required to complete this action item.');
        }

        try {
            $receipt = $action->markComplete(
                auth()->id(),
                $validated['completion_notes'],
                $validated['evidence_files'] ?? null,
                $validated['expected_version'] ?? null,
            );

            return redirect()->back()->with('success', "Action item completed. Receipt: {$receipt}");
        } catch (\DomainException $e) {
            if (str_contains($e->getMessage(), 'modified by another user')) {
                abort(409, $e->getMessage());
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
            'title' => $validated['title'] ?? ($validated['description'] ? \Illuminate\Support\Str::limit($validated['description'], 60) : null),
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
            'expected_version' => 'nullable|integer',
        ]);

        try {
            $action->updateProgress(
                $validated['progress_pct'],
                $validated['progress_notes'] ?? null,
                $validated['expected_version'] ?? null,
            );

            return redirect()->back()->with('success', 'Progress updated.');
        } catch (\DomainException $e) {
            if (str_contains($e->getMessage(), 'modified by another user')) {
                abort(409, $e->getMessage());
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
            'expected_version' => 'nullable|integer',
        ]);

        try {
            $action->block($validated['blocked_reason'], $validated['expected_version'] ?? null);

            return redirect()->back()->with('success', 'Action item marked as blocked.');
        } catch (\DomainException $e) {
            if (str_contains($e->getMessage(), 'modified by another user')) {
                abort(409, $e->getMessage());
            }
            return redirect()->back()->with('error', $e->getMessage());
        }
    }

    public function unblock(Request $request, ActionItem $action)
    {
        $this->authorize('update', $action);

        $validated = $request->validate([
            'expected_version' => 'nullable|integer',
        ]);

        try {
            $action->unblock($validated['expected_version'] ?? null);

            return redirect()->back()->with('success', 'Action item unblocked.');
        } catch (\DomainException $e) {
            if (str_contains($e->getMessage(), 'modified by another user')) {
                abort(409, $e->getMessage());
            }
            return redirect()->back()->with('error', $e->getMessage());
        }
    }

    public function escalate(Request $request, ActionItem $action)
    {
        $this->authorize('update', $action);

        $validated = $request->validate([
            'escalation_reason' => 'required|string|max:500',
            'expected_version' => 'nullable|integer',
        ]);

        try {
            $action->escalate(auth()->id(), $validated['escalation_reason'], $validated['expected_version'] ?? null);

            return redirect()->back()->with('success', 'Action item escalated.');
        } catch (\DomainException $e) {
            if (str_contains($e->getMessage(), 'modified by another user')) {
                abort(409, $e->getMessage());
            }
            return redirect()->back()->with('error', $e->getMessage());
        }
    }

    public function reassign(Request $request, ActionItem $action)
    {
        $this->authorize('update', $action);

        $validated = $request->validate([
            'assigned_to' => 'required|exists:users,id',
            'expected_version' => 'nullable|integer',
        ]);

        if (isset($validated['expected_version']) && (int) ($action->version_number ?? 1) !== (int) $validated['expected_version']) {
            abort(409, 'Action item was modified by another user. Please reload and review the latest changes.');
        }

        $action->update([
            'assigned_to' => $validated['assigned_to'],
            'version_number' => ($action->version_number ?? 1) + 1,
        ]);

        return redirect()->back()->with('success', 'Action item reassigned.');
    }
}
