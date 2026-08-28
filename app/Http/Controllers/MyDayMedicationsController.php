<?php

namespace App\Http\Controllers;

use App\Enums\Medication\NotGivenReason;
use App\Models\ClientMedication;
use App\Services\AuditLogger;
use App\Services\EnhancedMarService;
use App\Services\MarScheduleService;
use App\Services\Medication\MedicationScopeDecision;
use App\Services\Medication\MedicationScopeDecisionService;
use App\Support\Medication\MedicationStockQuantity;
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
        protected MarScheduleService $scheduleService,
        protected MedicationScopeDecisionService $medicationScope,
    ) {}

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
        abort_unless($user->canDo('medications.administer.record'), 403);

        $actionAt = now();

        return $this->medicationScope->forAdministration(
            $user,
            null,
            $medication,
            $actionAt,
            null,
            null,
            null,
            function (MedicationScopeDecision $scope, ?array $data) use ($user) {
                $data ??= [];
                $result = $this->marService->recordAdministration(
                    $scope->client,
                    $scope->medication,
                    [
                        'status' => 'given',
                        'scheduled_for' => $data['scheduled_for'],
                        'dose_given' => $data['dose_given'] ?? $scope->medication->dosage,
                        'quantity_administered' => $data['quantity_administered'] ?? null,
                        'notes' => $data['notes'] ?? null,
                        'witnessed_by' => $data['witnessed_by'] ?? null,
                        'witness_credential' => $data['witness_credential'] ?? null,
                        'scope_authorized' => true,
                    ],
                    $user->id,
                    $scope->shiftId(),
                    $user->canDo('medications.controlled.view'),
                    prelockedPresenceShifts: $scope->lockedPresenceShifts,
                    prelockedPresenceEffectiveAt: $scope->lockedPresenceEffectiveAt,
                );

                if (! ($result['success'] ?? false)) {
                    return back()->withInput()->withErrors([
                        $result['error_field'] ?? 'medication' => $result['error'] ?? 'Could not record this dose.',
                    ]);
                }

                if (empty($result['duplicate'])) {
                    AuditLogger::log('meds.administer', $result['administration'], [
                        'medication_id' => $scope->medication->id,
                        'client_id' => $scope->client->id,
                        'via' => 'my-day',
                    ]);
                    $this->medicationScope->recordBreakGlassUse($scope, 'recorded_dose', 'Via My Day');
                }

                return back()->with('success', empty($result['duplicate']) ? 'Dose given.' : 'Dose already recorded.');
            },
            scopedInputResolver: function () use ($request, $actionAt): array {
                $data = $request->validate([
                    'scheduled_for' => ['required', 'date'],
                    'dose_given' => ['nullable', 'string', 'max:255'],
                    'quantity_administered' => ['nullable', 'numeric', MedicationStockQuantity::VALIDATION_RULE, 'min:0.01', 'max:10000'],
                    'notes' => ['nullable', 'string', 'max:2000'],
                    'witnessed_by' => ['nullable', 'integer', 'min:1'],
                    'witness_credential' => ['nullable', 'string', 'max:255'],
                ]);

                return [
                    'scheduled_for' => $this->scheduleService->parseWorkerDateTime($data['scheduled_for']),
                    'action_at' => $actionAt,
                    'payload' => $data,
                ];
            },
        );
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
        abort_unless($user->canDo('medications.administer.record'), 403);

        $actionAt = now();

        return $this->medicationScope->forAdministration(
            $user,
            null,
            $medication,
            $actionAt,
            null,
            null,
            null,
            function (MedicationScopeDecision $scope, ?array $data) use ($user) {
                $data ??= [];
                $reasonCode = $data['reason_code'] ?? NotGivenReason::Refused->value;
                $result = $this->marService->recordAdministration(
                    $scope->client,
                    $scope->medication,
                    [
                        'status' => 'refused',
                        'scheduled_for' => $data['scheduled_for'],
                        'reason_code' => $reasonCode,
                        'reason' => $data['reason'] ?? NotGivenReason::tryFrom($reasonCode)?->label(),
                        'scope_authorized' => true,
                    ],
                    $user->id,
                    $scope->shiftId(),
                    $user->canDo('medications.controlled.view'),
                    prelockedPresenceShifts: $scope->lockedPresenceShifts,
                    prelockedPresenceEffectiveAt: $scope->lockedPresenceEffectiveAt,
                );

                if (! ($result['success'] ?? false)) {
                    return back()->withInput()->withErrors([
                        $result['error_field'] ?? 'medication' => $result['error'] ?? 'Could not mark this dose refused.',
                    ]);
                }

                if (empty($result['duplicate'])) {
                    AuditLogger::log('meds.refuse', $result['administration'], [
                        'medication_id' => $scope->medication->id,
                        'client_id' => $scope->client->id,
                        'reason' => $data['reason'] ?? null,
                        'reason_code' => $reasonCode,
                        'via' => 'my-day',
                    ]);
                    $this->medicationScope->recordBreakGlassUse($scope, 'recorded_refused_dose', 'Via My Day');
                }

                return back()->with('success', empty($result['duplicate']) ? 'Dose marked refused.' : 'Dose already recorded.');
            },
            scopedInputResolver: function () use ($request, $actionAt): array {
                $data = $request->validate([
                    'scheduled_for' => ['required', 'date'],
                    'reason_code' => ['nullable', 'string', 'max:60'],
                    'reason' => ['nullable', 'string', 'max:500'],
                ]);

                return [
                    'scheduled_for' => $this->scheduleService->parseWorkerDateTime($data['scheduled_for']),
                    'action_at' => $actionAt,
                    'payload' => $data,
                ];
            },
        );
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
        abort_unless($user->canDo('medications.administer.record'), 403);

        return $this->medicationScope->forMedication(
            $user,
            $medication,
            now(),
            function (MedicationScopeDecision $scope) use ($request, $user) {
                if ((bool) $scope->medication->controlled_drug) {
                    abort_unless($user->canDo('medications.controlled.record'), 404);
                }
                $data = $request->validate([
                    'minutes' => ['nullable', 'integer', 'min:1', 'max:120'],
                    'scheduled_for' => ['nullable', 'date'],
                ]);

                $minutes = $data['minutes'] ?? 15;
                $key = sprintf(
                    'my-day.med-snooze.user-%d.med-%d.%s',
                    $user->id,
                    $scope->medication->id,
                    ($data['scheduled_for'] ?? now()->toIso8601String()),
                );
                Cache::put($key, true, now()->addMinutes($minutes));

                AuditLogger::log('meds.snooze', $scope->medication, [
                    'medication_id' => $scope->medication->id,
                    'client_id' => $scope->client->id,
                    'minutes' => $minutes,
                    'via' => 'my-day',
                ]);

                $this->medicationScope->recordBreakGlassUse($scope, 'snoozed_dose', 'Via My Day');

                return back()->with('success', "Snoozed {$minutes}m.");
            },
            requireAdministrable: true,
        );
    }
}
