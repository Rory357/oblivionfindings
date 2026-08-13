<?php

namespace App\Http\Controllers;

use App\Models\SafeguardingConcern;
use App\Models\SafeguardingExternalReport;
use App\Services\Safeguarding\SafeguardingLifecycle;
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
            // NZ authorities (current): DSS moved Whaikaha→MSD Sept 2024, so msd_dss is
            // the disability-support notification path; Whaikaha is monitoring/advocacy.
            'authority_type' => 'required|in:police,oranga_tamariki,hdc,health_nz,msd_dss,whaikaha,privacy_commissioner,worksafe,coroner,other',
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

        // W6: a concern parked at triage for referral advances to "Referred external"
        // once the authority report is logged — the promised flow surfaced by both the
        // triage panel and the raise wizard. Guarded so a concern already past triage
        // (mid-investigation, on an action plan, etc.) is never regressed.
        if ($concern->status === 'triaged' && $concern->requires_external_referral) {
            $guard = app(SafeguardingLifecycle::class)->guardTransition($concern->fresh(), 'referred_external');
            if ($guard['allowed']) {
                $concern->update(['status' => 'referred_external']);
            }
        }

        return back()->with('success', 'External report created successfully.');
    }

    /**
     * Update the specified external report.
     */
    public function update(Request $request, SafeguardingConcern $concern, SafeguardingExternalReport $report): RedirectResponse
    {
        $actualConcern = $report->concern()->firstOrFail();
        abort_unless($actualConcern->is($concern), 404);
        $this->authorize('reportExternal', $actualConcern);

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
