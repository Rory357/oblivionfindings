<?php

namespace App\Http\Controllers;

use App\Enums\Medication\NotGivenReason;
use App\Models\ClientMedication;
use App\Services\AuditLogger;
use App\Services\EnhancedMarService;
use Illuminate\Http\Request;
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
    public function __construct(
        protected EnhancedMarService $marService,
    ) {
    }

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
            'witnessed_by' => ['nullable', 'integer', 'exists:users,id'],
            'witness_credential' => ['nullable', 'string', 'max:255'],
        ]);

        $result = $this->marService->recordAdministration(
            $medication->client,
            $medication,
            [
                'status' => 'given',
                'scheduled_for' => $data['scheduled_for'] ?? now(config('app.worker_timezone', 'Pacific/Auckland'))->toIso8601String(),
                'administered_at' => now(config('app.worker_timezone', 'Pacific/Auckland'))->toIso8601String(),
                'dose_given' => $data['dose_given'] ?? $medication->dosage,
                'notes' => $data['notes'] ?? null,
                'witnessed_by' => $data['witnessed_by'] ?? null,
                'witness_credential' => $data['witness_credential'] ?? null,
            ],
            $user->id,
        );

        if (! ($result['success'] ?? false)) {
            return back()->withInput()->withErrors([
                $result['error_field'] ?? 'medication' => $result['error'] ?? 'Could not record this dose.',
            ]);
        }

        $administration = $result['administration'];

        if (empty($result['duplicate'])) {
            AuditLogger::log('meds.administer', $administration, [
                'medication_id' => $medication->id,
                'client_id' => $medication->client_id,
                'via' => 'my-day',
            ]);
        }

        return back()->with('success', empty($result['duplicate']) ? 'Dose given.' : 'Dose already recorded.');
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
            'reason_code' => ['nullable', 'string', 'max:60'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $reasonCode = $data['reason_code'] ?? NotGivenReason::Refused->value;

        $result = $this->marService->recordAdministration(
            $medication->client,
            $medication,
            [
                'status' => 'refused',
                'scheduled_for' => $data['scheduled_for'] ?? now(config('app.worker_timezone', 'Pacific/Auckland'))->toIso8601String(),
                'administered_at' => now(config('app.worker_timezone', 'Pacific/Auckland'))->toIso8601String(),
                'reason_code' => $reasonCode,
                'reason' => $data['reason'] ?? NotGivenReason::tryFrom($reasonCode)?->label(),
            ],
            $user->id,
        );

        if (! ($result['success'] ?? false)) {
            return back()->withInput()->withErrors([
                $result['error_field'] ?? 'medication' => $result['error'] ?? 'Could not mark this dose refused.',
            ]);
        }

        $administration = $result['administration'];

        if (empty($result['duplicate'])) {
            AuditLogger::log('meds.refuse', $administration, [
                'medication_id' => $medication->id,
                'client_id' => $medication->client_id,
                'reason' => $data['reason'] ?? null,
                'reason_code' => $reasonCode,
                'via' => 'my-day',
            ]);
        }

        return back()->with('success', empty($result['duplicate']) ? 'Dose marked refused.' : 'Dose already recorded.');
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
