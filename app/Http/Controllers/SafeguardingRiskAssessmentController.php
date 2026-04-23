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
            'risk_factors' => 'nullable',
            'protective_factors' => 'nullable',
            'risk_to_self' => 'nullable|in:low,medium,high,critical',
            'risk_to_others' => 'nullable|in:low,medium,high,critical',
            'risk_from_others' => 'nullable|in:low,medium,high,critical',
            'overall_risk_level' => 'required|in:low,medium,high,critical',
            'capacity_assessed' => 'nullable|boolean',
            'mental_capacity' => 'nullable|string',
            'capacity_notes' => 'nullable|string',
            'immediate_actions_required' => 'nullable|string',
            'protective_measures' => 'nullable',
            'multi_agency_required' => 'nullable|boolean',
            'agencies_involved' => 'nullable',
            'next_review_date' => 'nullable|date',
            'assessment_notes' => 'nullable|string',
        ]);

        $validated['risk_factors'] = $this->normalizeListField($validated['risk_factors'] ?? null);
        $validated['protective_factors'] = $this->normalizeListField($validated['protective_factors'] ?? null);
        $validated['protective_measures'] = $this->normalizeListField($validated['protective_measures'] ?? null);
        $validated['agencies_involved'] = $this->normalizeListField($validated['agencies_involved'] ?? null);
        $validated['risk_to_self'] = $validated['risk_to_self'] ?? $validated['overall_risk_level'];
        $validated['risk_to_others'] = $validated['risk_to_others'] ?? $validated['overall_risk_level'];
        $validated['risk_from_others'] = $validated['risk_from_others'] ?? $validated['overall_risk_level'];
        $validated['capacity_assessed'] = (bool) ($validated['capacity_assessed'] ?? false);
        $validated['multi_agency_required'] = (bool) ($validated['multi_agency_required'] ?? false);

        $validated['safeguarding_concern_id'] = $concern->id;
        $validated['assessor_id'] = auth()->id();
        $validated['assessed_at'] = now();
        $validated['created_by'] = auth()->id();

        SafeguardingRiskAssessment::create($validated);

        return back()->with('success', 'Risk assessment created successfully.');
    }

    private function normalizeListField(mixed $value): ?array
    {
        if (is_array($value)) {
            $entries = array_values(array_filter(array_map(
                fn (mixed $entry) => is_string($entry) ? trim($entry) : '',
                $value,
            )));

            return $entries === [] ? null : $entries;
        }

        if (! is_string($value)) {
            return null;
        }

        $entries = array_values(array_filter(array_map(
            fn (string $entry) => trim($entry),
            preg_split('/\r\n|\r|\n/', $value) ?: [],
        )));

        return $entries === [] ? null : $entries;
    }
}
