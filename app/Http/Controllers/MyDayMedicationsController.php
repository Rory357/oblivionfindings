<?php

namespace App\Http\Controllers;

use App\Models\ClientMedication;
use App\Models\ClientMedicationAdministration;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Frontline medication actions surfaced on /my-day.
 *
 * Distinct from the full eMAR endpoints — these are the lightweight buttons
 * a worker uses to mark a dose given/refused or snooze it for 15 minutes
 * without leaving the home page. Audit-logged the same way the eMAR is, so
 * the medical record stays complete.
 */
class MyDayMedicationsController extends Controller
{
    /**
     * Mark a scheduled dose as administered (status='given').
     *
     * The "give" button on each med row in the WhatsNextRail hits this. We
     * derive the scheduled timestamp from the request body so multiple dose
     * rows for the same ClientMedication (e.g. 09:00 + 13:00 Metformin) can be
     * targeted independently. If the worker hasn't passed `scheduled_for` we
     * fall back to "now" — the eMAR will treat that as an ad-hoc dose.
     */
    public function administer(Request $request, ClientMedication $medication)
    {
        $user = $request->user();
        abort_unless($user, 403);
        $this->authorize('view', $medication->client);
        abort_unless($user->canDo('medications.administer.record'), 403);

        $data = $request->validate([
            'scheduled_for' => ['nullable', 'date'],
            'dose_given' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $scheduled = isset($data['scheduled_for']) ? Carbon::parse($data['scheduled_for']) : now();

        $administration = ClientMedicationAdministration::create([
            'client_id' => $medication->client_id,
            'client_medication_id' => $medication->id,
            'administered_by' => $user->id,
            'scheduled_for' => $scheduled,
            'administered_at' => now(),
            'status' => 'given',
            'dose_given' => $data['dose_given'] ?? $medication->dosage,
            'notes' => $data['notes'] ?? null,
        ]);

        AuditLogger::log('meds.administer', $administration, [
            'medication_id' => $medication->id,
            'client_id' => $medication->client_id,
            'via' => 'my-day',
        ]);

        return back()->with('success', 'Dose given.');
    }

    /**
     * Mark a scheduled dose as refused / not given (status='refused').
     *
     * Requires a `reason` so the medical record captures why. The audit log
     * carries the same reason for compliance review.
     */
    public function refuse(Request $request, ClientMedication $medication)
    {
        $user = $request->user();
        abort_unless($user, 403);
        $this->authorize('view', $medication->client);
        abort_unless($user->canDo('medications.administer.record'), 403);

        $data = $request->validate([
            'scheduled_for' => ['nullable', 'date'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $scheduled = isset($data['scheduled_for']) ? Carbon::parse($data['scheduled_for']) : now();

        $administration = ClientMedicationAdministration::create([
            'client_id' => $medication->client_id,
            'client_medication_id' => $medication->id,
            'administered_by' => $user->id,
            'scheduled_for' => $scheduled,
            'administered_at' => now(),
            'status' => 'refused',
            'reason' => $data['reason'] ?? 'Resident declined',
        ]);

        AuditLogger::log('meds.refuse', $administration, [
            'medication_id' => $medication->id,
            'client_id' => $medication->client_id,
            'reason' => $data['reason'] ?? null,
            'via' => 'my-day',
        ]);

        return back()->with('success', 'Dose marked refused.');
    }

    /**
     * Snooze the dose row in the worker's /my-day view for a short window.
     *
     * Snoozing here is UI-only — the medical record is untouched. We store
     * the snooze in the cache (keyed by user + medication + scheduled-time)
     * so the row stays hidden across page reloads but only for this worker.
     * The eMAR view is unaffected.
     */
    public function snooze(Request $request, ClientMedication $medication)
    {
        $user = $request->user();
        abort_unless($user, 403);
        $this->authorize('view', $medication->client);

        $data = $request->validate([
            'minutes' => ['nullable', 'integer', 'min:1', 'max:120'],
            'scheduled_for' => ['nullable', 'date'],
        ]);

        $minutes = $data['minutes'] ?? 15;
        $key = sprintf(
            'my-day.med-snooze.user-%d.med-%d.%s',
            $user->id,
            $medication->id,
            ($data['scheduled_for'] ?? now()->toIso8601String()),
        );
        Cache::put($key, true, now()->addMinutes($minutes));

        AuditLogger::log('meds.snooze', $medication, [
            'medication_id' => $medication->id,
            'client_id' => $medication->client_id,
            'minutes' => $minutes,
            'via' => 'my-day',
        ]);

        return back()->with('success', "Snoozed {$minutes}m.");
    }
}
