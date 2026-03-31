<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\ClientMedicationAdministration;
use App\Services\NotificationService;
use Illuminate\Http\Request;

class MedicationAdministrationCorrectionController extends Controller
{
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

        $corr->save();

        app(NotificationService::class)->notifyCrud($user, 'created', 'medication correction', $corr, $client, [
            'title' => 'Medication administration corrected',
            'url' => url("/clients/{$client->id}/mar"),
        ]);

        return back()->with('success', 'Correction recorded.');
    }
}
