<?php

namespace App\Http\Controllers;

use App\Models\SafeguardingConcern;
use App\Models\SafeguardingRiskAssessment;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class SafeguardingRiskAssessmentController extends Controller
{
    /**
     * Store a newly created risk assessment for a safeguarding concern.
     */
    public function store(Request $request, SafeguardingConcern $concern): RedirectResponse
    {
        $this->authorize('update', $concern);

        $validated = $request->validate([
            'risk_factors' => 'nullable|array',
            'protective_factors' => 'nullable|array',
            'risk_to_self' => 'nullable|in:low,medium,high,critical',
            'risk_to_others' => 'nullable|in:low,medium,high,critical',
            'risk_from_others' => 'nullable|in:low,medium,high,critical',
            'overall_risk_level' => 'required|in:low,medium,high,critical',
            'capacity_assessed' => 'nullable|boolean',
            'mental_capacity' => 'nullable|string',
            'capacity_notes' => 'nullable|string',
            'immediate_actions_required' => 'nullable|string',
            'protective_measures' => 'nullable|array',
            'multi_agency_required' => 'nullable|boolean',
            'agencies_involved' => 'nullable|array',
            'next_review_date' => 'nullable|date',
            'assessment_notes' => 'nullable|string',
        ]);

        $validated['safeguarding_concern_id'] = $concern->id;
        $validated['assessor_id'] = auth()->id();
        $validated['assessed_at'] = now();
        $validated['created_by'] = auth()->id();

        SafeguardingRiskAssessment::create($validated);

        return back()->with('success', 'Risk assessment created successfully.');
    }
}
