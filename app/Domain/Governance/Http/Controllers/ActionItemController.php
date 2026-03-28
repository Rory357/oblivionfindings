<?php

namespace App\Domain\Governance\Http\Controllers;

use App\Domain\Governance\Http\Requests\StoreActionItemRequest;
use App\Domain\Governance\Models\ActionItem;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ActionItemController extends Controller
{
    public function index(Request $request)
    {
        $query = ActionItem::with(['assignedTo', 'completedBy', 'createdBy']);

        // Filter by assignment
        if ($request->has('assigned_to_me')) {
            $query->forUser(auth()->id());
        }

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        if ($request->has('overdue')) {
            $query->overdue();
        }

        $items = $query->orderBy('due_date')->paginate(20);

        // Summary counts
        $summary = [
            'total_open' => ActionItem::open()->count(),
            'overdue' => ActionItem::overdue()->count(),
            'my_open' => ActionItem::forUser(auth()->id())->open()->count(),
            'high_priority' => ActionItem::highPriority()->open()->count(),
        ];

        return Inertia::render('Governance/Actions/Index', [
            'items' => $items,
            'summary' => $summary,
        ]);
    }

    public function show(ActionItem $action)
    {
        $action->load(['assignedTo', 'completedBy', 'createdBy']);

        return Inertia::render('Governance/Actions/Show', [
            'action' => $action,
        ]);
    }

    public function complete(Request $request, ActionItem $action)
    {
        $this->authorize('update', $action);

        $validated = $request->validate([
            'completion_notes' => 'nullable|string',
            'evidence_files' => 'nullable|array',
        ]);

        $action->markComplete(auth()->id(), $validated['completion_notes'] ?? null);

        if ($validated['evidence_files'] ?? false) {
            $action->update(['evidence_attachments' => $validated['evidence_files']]);
        }

        return redirect()->back()->with('success', 'Action item completed.');
    }

    public function store(StoreActionItemRequest $request)
    {
        $validated = $request->validated();

        ActionItem::create([
            ...$validated,
            'created_by' => auth()->id(),
            'status' => 'open',
        ]);

        return redirect()->back()->with('success', 'Action item created.');
    }

    public function updateProgress(Request $request, ActionItem $action)
    {
        $this->authorize('update', $action);

        $validated = $request->validate([
            'progress_pct' => 'required|integer|min:0|max:100',
            'progress_notes' => 'nullable|string',
        ]);

        $action->updateProgress($validated['progress_pct'], $validated['progress_notes'] ?? null);

        return redirect()->back()->with('success', 'Progress updated.');
    }

    public function block(Request $request, ActionItem $action)
    {
        $this->authorize('update', $action);

        $validated = $request->validate([
            'blocked_reason' => 'required|string|max:500',
        ]);

        $action->block($validated['blocked_reason']);

        return redirect()->back()->with('success', 'Action item marked as blocked.');
    }

    public function unblock(ActionItem $action)
    {
        $this->authorize('update', $action);

        $action->unblock();

        return redirect()->back()->with('success', 'Action item unblocked.');
    }

    public function escalate(Request $request, ActionItem $action)
    {
        $this->authorize('update', $action);

        $validated = $request->validate([
            'escalation_reason' => 'required|string|max:500',
        ]);

        $action->escalate(auth()->id(), $validated['escalation_reason']);

        return redirect()->back()->with('success', 'Action item escalated.');
    }
}
