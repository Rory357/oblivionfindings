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
            'acknowledged_at' => 'nullable|date',
            'acknowledgement_reference' => 'nullable|string',
            'authority_action' => 'nullable|string',
            'authority_feedback' => 'nullable|string',
            'authority_feedback_at' => 'nullable|date',
        ]);

        $validated['updated_by'] = auth()->id();

        $report->update($validated);

        return back()->with('success', 'External report updated successfully.');
    }
}
