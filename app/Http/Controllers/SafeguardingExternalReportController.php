<?php

namespace App\Http\Controllers;

use App\Models\SafeguardingConcern;
use App\Models\SafeguardingExternalReport;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class SafeguardingExternalReportController extends Controller
{
    /**
     * Store a newly created external report for a safeguarding concern.
     */
    public function store(Request $request, SafeguardingConcern $concern): RedirectResponse
    {
        $this->authorize('reportExternal', $concern);

        $validated = $request->validate([
            'authority_type' => 'required|in:police,health_nz,worksafe,privacy_commissioner,hdc,oranga_tamariki,other',
            'authority_name' => 'required|string',
            'authority_contact' => 'nullable|string',
            'reported_at' => 'required|date',
            'report_method' => 'nullable|in:phone,email,online_form,in_person,letter',
            'report_summary' => 'required|string',
        ]);

        $validated['safeguarding_concern_id'] = $concern->id;
        $validated['reported_by_user_id'] = auth()->id();
        $validated['created_by'] = auth()->id();
        $validated['acknowledgement_received'] = false;

        SafeguardingExternalReport::create($validated);

        return back()->with('success', 'External report created successfully.');
    }

    /**
     * Update the specified external report.
     */
    public function update(Request $request, SafeguardingConcern $concern, SafeguardingExternalReport $report): RedirectResponse
    {
        $this->authorize('reportExternal', $concern);

        $validated = $request->validate([
            'authority_reference' => 'nullable|string',
            'acknowledgement_received' => 'nullable|boolean',
            'acknowledgment_received' => 'nullable|boolean',
            'acknowledged_at' => 'nullable|date',
            'acknowledgment_date' => 'nullable|date',
            'acknowledgement_reference' => 'nullable|string',
            'acknowledgment_reference' => 'nullable|string',
            'authority_action' => 'nullable|string',
            'authority_feedback' => 'nullable|string',
            'authority_feedback_at' => 'nullable|date',
        ]);

        $validated['acknowledgement_received'] = array_key_exists('acknowledgement_received', $validated)
            ? $validated['acknowledgement_received']
            : ($validated['acknowledgment_received'] ?? null);
        $validated['acknowledged_at'] = $validated['acknowledged_at'] ?? ($validated['acknowledgment_date'] ?? null);
        $validated['acknowledgement_reference'] = $validated['acknowledgement_reference']
            ?? ($validated['acknowledgment_reference'] ?? null);
        unset($validated['acknowledgment_received'], $validated['acknowledgment_date'], $validated['acknowledgment_reference']);

        $validated['updated_by'] = auth()->id();

        $report->update($validated);

        return back()->with('success', 'External report updated successfully.');
    }
}
