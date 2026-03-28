<?php

namespace App\Http\Controllers;

use App\Models\SafeguardingConcern;
use App\Models\SafeguardingInvestigation;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class SafeguardingInvestigationController extends Controller
{
    /**
     * Store a newly created investigation for a safeguarding concern.
     */
    public function store(Request $request, SafeguardingConcern $concern): RedirectResponse
    {
        $this->authorize('investigate', $concern);

        $validated = $request->validate([
            'investigation_type' => 'required|string',
            'lead_investigator_id' => 'required|exists:users,id',
            'started_at' => 'required|date',
            'target_completion_date' => 'nullable|date',
            'terms_of_reference' => 'nullable|string',
            'methodology' => 'nullable|string',
        ]);

        $validated['safeguarding_concern_id'] = $concern->id;
        $validated['status'] = 'planned';
        $validated['created_by'] = auth()->id();

        SafeguardingInvestigation::create($validated);

        $concern->update(['status' => 'investigating']);

        return back()->with('success', 'Investigation created successfully.');
    }

    /**
     * Update the specified investigation.
     */
    public function update(Request $request, SafeguardingConcern $concern, SafeguardingInvestigation $investigation): RedirectResponse
    {
        $this->authorize('investigate', $concern);

        $validated = $request->validate([
            'status' => 'nullable|in:planned,in_progress,completed,on_hold',
            'evidence_collected' => 'nullable|array',
            'interviews_conducted' => 'nullable|array',
            'findings' => 'nullable|string',
            'outcome' => 'nullable|string',
            'recommendations' => 'nullable|string',
            'completed_at' => 'nullable|date',
            'report_completed' => 'nullable|boolean',
        ]);

        $validated['updated_by'] = auth()->id();

        if (isset($validated['status']) && $validated['status'] === 'completed' && empty($validated['completed_at'])) {
            $validated['completed_at'] = now();
        }

        $investigation->update($validated);

        return back()->with('success', 'Investigation updated successfully.');
    }
}
