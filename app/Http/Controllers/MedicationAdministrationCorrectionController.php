<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\ClientMedicationAdministration;
use App\Services\MedicationIncidentIntegrationService;
use App\Services\NotificationService;
use App\Support\EmarUrl;
use Illuminate\Http\Request;

class MedicationAdministrationCorrectionController extends Controller
{
    public function approve(Request $request, ClientMedicationAdministration $correction)
    {
        abort_unless($request->user()?->canDo('medications.administer.correct'), 403);
        abort_unless($correction->is_correction && $correction->correction_status === 'pending', 404);

        // Two-person rule: the person who raised the correction cannot approve
        // their own — approval must be an independent check.
        if ((int) $correction->administered_by === (int) $request->user()->id) {
            return back()->with('error', 'A correction must be approved by someone other than the person who raised it.');
        }

        $correction->update([
            'correction_status' => 'approved',
            'correction_approved_by' => $request->user()->id,
            'correction_approved_at' => now(),
        ]);

        app(MedicationIncidentIntegrationService::class)->resolveUnsafeCorrection(
            $correction,
            'Unsafe medication correction approved.',
            $request->user()->id
        );

        return back()->with('success', 'Correction approved.');
    }

    public function reject(Request $request, ClientMedicationAdministration $correction)
    {
        abort_unless($request->user()?->canDo('medications.administer.correct'), 403);
        abort_unless($correction->is_correction && $correction->correction_status === 'pending', 404);

        $validated = $request->validate(['reason' => 'required|string|max:1000']);

        $correction->update([
            'correction_status' => 'rejected',
            'correction_approved_by' => $request->user()->id,
            'correction_approved_at' => now(),
            'correction_rejection_reason' => $validated['reason'],
        ]);

        app(MedicationIncidentIntegrationService::class)->resolveUnsafeCorrection(
            $correction,
            'Unsafe medication correction rejected.',
            $request->user()->id
        );

        return back()->with('success', 'Correction rejected.');
    }

    public function store(Request $request, Client $client, ClientMedicationAdministration $administration)
    {
        $this->authorize('viewMedications', $client);
        abort_unless($administration->client_id === $client->id, 404);

        $user = $request->user();
        abort_unless($user && ($user->canDo('medications.administer.correct') || $user->canDo('clients.update')), 403);

        // Guardrail: allow quick edits within 30 minutes, otherwise require a correction reason.
        $data = $request->validate([
            'status' => ['required', 'in:given,refused,missed,withheld'],
            'reason' => ['nullable', 'string', 'max:255'],
            'dose_given' => ['nullable', 'string', 'max:255'],
            'administered_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
            'correction_reason' => ['nullable', 'string', 'max:255'],
        ]);

        $windowAnchor = $administration->administered_at
            ?? $administration->updated_at
            ?? $administration->created_at;
        $minutesSince = $windowAnchor ? $windowAnchor->diffInMinutes(now()) : 999999;
        if ($minutesSince > 30 && empty($data['correction_reason'])) {
            return back()->withInput()->with('error', 'Please provide a correction reason (outside the 30-minute edit window).');
        }

        $corr = $administration->replicate([
            'id',
            'created_at',
            'updated_at',
        ]);
        $corr->is_correction = true;
        $corr->corrected_of_id = $administration->id;
        $corr->correction_reason = $data['correction_reason'] ?? null;
        $corr->status = $data['status'];
        $corr->reason = $data['reason'] ?? null;
        $corr->dose_given = $data['dose_given'] ?? null;
        $corr->administered_at = $data['administered_at'] ?? $administration->administered_at ?? now();
        $corr->notes = $data['notes'] ?? null;
        $corr->administered_by = $user->id;

        $corr->correction_status = 'pending';
        $corr->save();

        app(MedicationIncidentIntegrationService::class)->handleUnsafeCorrection(
            $administration,
            $data,
            $user->id,
            $corr
        );

        app(NotificationService::class)->notifyCrud($user, 'created', 'medication correction (pending approval)', $corr, $client, [
            'title' => 'Medication correction pending approval',
            'url' => url(EmarUrl::mar($client)),
            'notify_roles' => ['manager', 'admin'],
        ]);

        return back()->with('success', 'Correction submitted for approval.');
    }
}
