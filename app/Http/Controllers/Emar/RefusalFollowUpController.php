<?php

namespace App\Http\Controllers\Emar;

use App\Http\Controllers\Controller;
use App\Models\ClientMedicationAdministration;
use App\Models\MedicationRefusalFollowup;
use App\Services\MedicationIncidentIntegrationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class RefusalFollowUpController extends Controller
{
    /**
     * Store a new refusal/withholding follow-up record.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'client_id' => ['required', 'exists:clients,id'],
            'client_medication_administration_id' => ['required', 'exists:client_medication_administrations,id'],
            'reason_category' => ['required', 'in:personal_choice,side_effects,difficulty_swallowing,nausea,pain,cognitive,behavioural,sleeping,other'],
            'detailed_reason' => ['nullable', 'string', 'max:2000'],
            'client_capacity_at_time' => ['required', 'in:has_capacity,lacks_capacity,fluctuating,not_assessed'],
            'offered_alternative' => ['boolean'],
            'alternative_details' => ['nullable', 'string', 'max:1000'],
            'gp_notification_required' => ['boolean'],
            'family_notified' => ['boolean'],
            'follow_up_action' => ['nullable', 'string', 'max:2000'],
            'follow_up_due_at' => ['nullable', 'date'],
        ]);

        $validated['created_by'] = Auth::id();

        // If family was notified, record the timestamp
        if (!empty($validated['family_notified'])) {
            $validated['family_notified_at'] = now();
        }

        // Check for refusal cluster: 3+ refusals in 7 days for the same medication
        $administration = ClientMedicationAdministration::findOrFail($validated['client_medication_administration_id']);
        $recentRefusals = ClientMedicationAdministration::where('client_id', $validated['client_id'])
            ->where('client_medication_id', $administration->client_medication_id)
            ->whereIn('status', ['refused', 'withheld'])
            ->where('administered_at', '>=', now()->subDays(7))
            ->count();

        if ($recentRefusals >= 3) {
            $validated['escalated_to_manager'] = true;
            $validated['escalated_at'] = now();
            // Auto-flag GP notification when cluster detected
            $validated['gp_notification_required'] = true;
        }

        $followup = MedicationRefusalFollowup::create($validated);

        if (! empty($validated['escalated_to_manager'])) {
            app(MedicationIncidentIntegrationService::class)
                ->handleRefusalEscalation($followup, $recentRefusals);
        }

        return redirect()->back()->with('success', 'Refusal follow-up recorded successfully.');
    }

    /**
     * Mark a follow-up as completed.
     */
    public function complete(Request $request, MedicationRefusalFollowup $followup)
    {
        // Completion must record what was actually done/decided — a bare
        // timestamp left auditors unable to verify the resolution action.
        $validated = $request->validate([
            'outcome' => ['required', 'string', 'max:2000'],
        ]);

        $followup->update([
            'follow_up_completed_at' => now(),
            'follow_up_completed_by' => Auth::id(),
            'follow_up_outcome' => $validated['outcome'],
        ]);

        app(MedicationIncidentIntegrationService::class)->resolveRefusalEscalation(
            $followup,
            'Medication refusal follow-up completed.',
            Auth::id()
        );

        return redirect()->back()->with('success', 'Follow-up marked as completed.');
    }

    /**
     * Record that the GP has been notified.
     */
    public function notifyGp(Request $request, MedicationRefusalFollowup $followup)
    {
        $validated = $request->validate([
            'gp_response' => ['nullable', 'string', 'max:2000'],
        ]);

        $followup->update([
            'gp_notified_at' => now(),
            'gp_notified_by' => Auth::id(),
            'gp_response' => $validated['gp_response'] ?? null,
        ]);

        return redirect()->back()->with('success', 'GP notification recorded.');
    }
}
