<?php

namespace App\Http\Controllers;

use App\Models\SafeguardingActionPlan;
use App\Models\SafeguardingConcern;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class SafeguardingActionPlanController extends Controller
{
    /**
     * Store a newly created action plan for a safeguarding concern.
     */
    public function store(Request $request, SafeguardingConcern $concern): RedirectResponse
    {
        $this->authorize('update', $concern);

        $validated = $request->validate([
            'action_description' => 'required|string',
            'action_type' => 'nullable|in:immediate,short_term,long_term,preventive',
            'assigned_to_user_id' => 'required|exists:users,id',
            'due_date' => 'required|date',
            'priority' => 'nullable|integer|min:1|max:5',
        ]);

        $validated['safeguarding_concern_id'] = $concern->id;
        $validated['status'] = 'pending';
        $validated['created_by'] = auth()->id();

        SafeguardingActionPlan::create($validated);

        return back()->with('success', 'Action plan created successfully.');
    }

    /**
     * Update the specified action plan.
     */
    public function update(Request $request, SafeguardingConcern $concern, SafeguardingActionPlan $actionPlan): RedirectResponse
    {
        $this->authorize('update', $concern);

        $validated = $request->validate([
            'action_description' => 'nullable|string',
            'status' => 'nullable|in:pending,in_progress,completed,cancelled',
            'due_date' => 'nullable|date',
            'completion_notes' => 'nullable|string',
        ]);

        $validated['updated_by'] = auth()->id();

        $actionPlan->update($validated);

        return back()->with('success', 'Action plan updated successfully.');
    }

    /**
     * Mark the specified action plan as completed.
     */
    public function complete(Request $request, SafeguardingConcern $concern, SafeguardingActionPlan $actionPlan): RedirectResponse
    {
        $this->authorize('update', $concern);

        $validated = $request->validate([
            'completion_notes' => 'nullable|string',
        ]);

        $actionPlan->update([
            'status' => 'completed',
            'completed_at' => now(),
            'completed_by_user_id' => auth()->id(),
            'completion_notes' => $validated['completion_notes'] ?? null,
            'updated_by' => auth()->id(),
        ]);

        return back()->with('success', 'Action plan marked as completed.');
    }
}
