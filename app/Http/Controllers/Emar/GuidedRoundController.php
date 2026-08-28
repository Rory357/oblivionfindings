<?php

namespace App\Http\Controllers\Emar;

use App\Http\Controllers\Concerns\HandlesMedicationSync;
use App\Http\Controllers\Controller;
use App\Models\ClientMedication;
use App\Models\ClientMedicationAdministration;
use App\Models\MedicationRound;
use App\Services\EnhancedMarService;
use App\Services\GuidedRoundService;
use App\Services\MarScheduleService;
use App\Services\Medication\MedicationScopeDecision;
use App\Services\Medication\MedicationScopeDecisionService;
use App\Services\Timeline\TimelineEmitter;
use App\Support\Medication\MedicationStockQuantity;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Frontline guided medication round flow.
 *
 * One-med-at-a-time full-screen experience. Launch and resume safely from
 * /my-day or the existing rounds surface. Administrations still flow through
 * the trusted EnhancedMarService so audit / safety / controlled-drug logic is
 * preserved.
 */
class GuidedRoundController extends Controller
{
    use HandlesMedicationSync;

    public function __construct(
        protected GuidedRoundService $guidedRoundService,
        protected EnhancedMarService $marService,
        protected MarScheduleService $scheduleService,
        protected MedicationScopeDecisionService $medicationScope,
    ) {}

    /**
     * Render the guided round page.
     */
    public function show(Request $request, MedicationRound $round)
    {
        $user = $request->user();
        abort_unless($this->canWork($user), 403);

        return $this->medicationScope->forRound($user, $round, now(), function (MedicationScopeDecision $scope) {
            // The guided walk-through is now a modal on /emar/rounds (and surfaced
            // on /meds/today). Redirect deep links there with the round pre-opened.
            $dateStr = $scope->round->round_date instanceof \DateTimeInterface
                ? $scope->round->round_date->format('Y-m-d')
                : (string) $scope->round->round_date;

            return redirect()->route('emar.rounds', ['date' => $dateStr, 'guided' => $scope->round->id]);
        });
    }

    /**
     * Explicitly start an assigned frontline round.
     *
     * Starting used to happen as a side effect of loading the rounds page. Keep
     * the state transition behind POST and the same canonical Site, assignment,
     * and covering-shift decision used by every guided-round action.
     */
    public function start(Request $request, MedicationRound $round)
    {
        $user = $request->user();
        abort_unless($this->canWork($user), 403);

        return $this->medicationScope->forRound(
            $user,
            $round,
            now(),
            function (MedicationScopeDecision $scope) {
                if ($scope->round->status === 'pending') {
                    $scope->round->forceFill([
                        'status' => 'in_progress',
                        'started_by' => $scope->performer->id,
                        'started_at' => now(),
                    ])->save();
                } elseif ($scope->round->status === 'partial') {
                    $scope->round->forceFill(['status' => 'in_progress'])->save();
                }

                $dateStr = $scope->round->round_date instanceof \DateTimeInterface
                    ? $scope->round->round_date->format('Y-m-d')
                    : (string) $scope->round->round_date;

                return redirect()->route('emar.rounds', [
                    'date' => $dateStr,
                    'guided' => $scope->round->id,
                ]);
            },
            ['pending', 'partial', 'in_progress'],
        );
    }

    /**
     * Record one administration from inside the guided flow.
     *
     * Uses EnhancedMarService so all existing safety checks, witness rules,
     * controlled-drug register entries and audit trails still run. The only
     * guided-round-specific concerns handled here are:
     *   - linking the administration to the round (medication_round_id)
     *   - blocking duplicate administration of the same dose in the same round
     */
    public function administer(Request $request, MedicationRound $round, ClientMedication $medication)
    {
        $user = $request->user();
        abort_unless($this->canWork($user), 403);
        $actionAt = $this->medicationConcealmentActionAt(
            $request,
            $this->scheduleService,
            now(),
            acceptAdministeredAt: false,
        );

        return $this->medicationScope->forAdministration(
            $user,
            null,
            $medication,
            $actionAt,
            null,
            null,
            $round,
            function (MedicationScopeDecision $scope, ?array $data) use ($request, $user) {
                abort_unless($data !== null, 404);
                $scheduled = $this->scheduleService->parseWorkerDateTime((string) $data['scheduled_for']);
                $submittedAdministrationAt = $this->medicationSubmittedAdministrationAt($data);
                // Worker vocabulary: "held" → backend "withheld".
                $backendStatus = $data['status'] === 'held' ? 'withheld' : $data['status'];

                if ($backendStatus !== 'given' && empty($data['reason_code'])) {
                    if ($request->expectsJson()) {
                        return response()->json(
                            $this->withMedicationSync(
                                ['success' => false, 'error' => 'Please choose why this dose was not given.', 'error_field' => 'reason_code'],
                                $data,
                                'rejected',
                                false,
                                'Please choose why this dose was not given.',
                            ),
                            422,
                        );
                    }

                    return back()->withErrors([
                        'reason_code' => 'Please choose why this dose was not given.',
                    ]);
                }

                $round = $scope->round;
                $medication = $scope->medication;

                // Offline queues retain their stale-state conflict contract,
                // but only effective clinical evidence can occupy the round
                // slot. Online retries always reach EnhancedMarService so the
                // durable request fingerprint is checked before a duplicate is
                // disclosed.
                if (($data['queued_offline'] ?? false) && $request->expectsJson()) {
                    $sameRequestReplay = filled($data['client_request_uuid'] ?? null)
                        && ClientMedicationAdministration::withTrashed()
                            ->where('client_id', $scope->client->id)
                            ->where('client_medication_id', $medication->id)
                            ->where('client_request_uuid', $data['client_request_uuid'])
                            ->exists();
                    $existing = $sameRequestReplay
                        ? null
                        : $medication->administrations()
                            ->effectiveClinicalEvidence()
                            ->where('medication_round_id', $round->id)
                            ->whereBetween('scheduled_for', $this->scheduleService->utcSlotWindow($scheduled))
                            ->first();

                    if ($existing !== null) {
                        return response()->json(
                            $this->buildMedicationConflictPayload(
                                $data,
                                'Medication state changed before this offline round item could sync. Supervisor review is required.',
                            ),
                            409,
                        );
                    }
                }

                $result = $this->marService->recordAdministration(
                    $scope->client,
                    $medication,
                    [
                        'status' => $backendStatus,
                        'reason' => $data['reason'] ?? null,
                        'reason_code' => $data['reason_code'] ?? null,
                        'scheduled_for' => $scheduled->toIso8601String(),
                        'administered_at' => $submittedAdministrationAt,
                        'witnessed_by' => $data['witnessed_by'] ?? null,
                        'witness_credential' => $data['witness_credential'] ?? null,
                        'quantity_administered' => $data['quantity_administered'] ?? null,
                        'blood_glucose_level' => $data['blood_glucose_level'] ?? null,
                        'pulse_bpm' => $data['pulse_bpm'] ?? null,
                        'blood_pressure_systolic' => $data['blood_pressure_systolic'] ?? null,
                        'blood_pressure_diastolic' => $data['blood_pressure_diastolic'] ?? null,
                        'client_request_uuid' => $data['client_request_uuid'] ?? null,
                        'captured_offline_at' => $data['captured_offline_at'] ?? null,
                        'origin_device_id' => $data['origin_device_id'] ?? null,
                        'queued_offline' => (bool) ($data['queued_offline'] ?? false),
                        'scope_authorized' => true,
                        'medication_round_id' => $round->id,
                        // The round's own window_minutes is the authoritative
                        // schedule for a guided round; skip the narrower MAR
                        // time-window check here so workers aren't blocked by it
                        // while walking through a round that's still inside its
                        // own legitimate window.
                        'override_window' => true,
                    ],
                    $user->id,
                    $scope->shiftId(),
                    $user->canDo('medications.controlled.view'),
                    prelockedPresenceShifts: $scope->lockedPresenceShifts,
                    prelockedPresenceEffectiveAt: $scope->lockedPresenceEffectiveAt,
                );

                if (! ($result['success'] ?? false)) {
                    if ($request->expectsJson()) {
                        return response()->json(
                            $this->withMedicationSync(
                                $result,
                                $data,
                                'rejected',
                                false,
                                $result['error'] ?? 'Could not record this dose.',
                            ),
                            422,
                        );
                    }

                    return back()->withErrors([
                        'status' => $result['error'] ?? 'Could not record this dose.',
                    ]);
                }

                // EnhancedMarService links an unclaimed effective row while the
                // Client, medication, round and administration remain locked.
                // Original shift/actor/request provenance is never rewritten.
                $admin = $result['administration'];
                abort_unless(
                    (int) $admin->medication_round_id === (int) $round->id,
                    409,
                    'Medication state changed before this round item could be recorded.',
                );

                $duplicate = (bool) ($result['duplicate'] ?? false);
                if (! $duplicate) {
                    $statusLabel = ucfirst(str_replace('_', ' ', $backendStatus));
                    app(TimelineEmitter::class)->record([
                        'source_type' => ClientMedicationAdministration::class,
                        'source_id' => $admin->id,
                        'occurred_at' => $admin->administered_at ?? now(),
                        'type' => 'medication_'.$backendStatus,
                        'actor_user_id' => $user->id,
                        'client_id' => $medication->client_id,
                        'shift_id' => $scope->shiftId(),
                        'site_id' => $medication->client?->site_id,
                        'subject' => $statusLabel.': '.$medication->name.($medication->dosage ? ' '.$medication->dosage : ''),
                        'body' => null,
                        'meta' => array_filter([
                            'medication_name' => $medication->name,
                            'dosage' => $medication->dosage,
                            'status' => $backendStatus,
                            'reason' => $data['reason'] ?? null,
                            'witnessed_by' => $data['witnessed_by'] ?? null,
                            'medication_round_id' => $round->id,
                            'client_request_uuid' => $data['client_request_uuid'] ?? null,
                            'captured_offline_at' => $data['captured_offline_at'] ?? null,
                            'origin_device_id' => $data['origin_device_id'] ?? null,
                            'queued_offline' => (bool) ($data['queued_offline'] ?? false),
                        ]),
                        'visibility' => 'internal',
                        'is_pinned' => false,
                        'created_by' => $user->id,
                    ]);

                    $this->medicationScope->recordBreakGlassUse(
                        $scope,
                        'recorded_round_dose',
                        'Administration '.$admin->id,
                    );
                }

                $round->updateCounts();

                $payload = $this->withMedicationSync([
                    'success' => true,
                    'administration' => [
                        'id' => $admin->id,
                        'status' => $admin->status,
                        'administered_at' => $admin->administered_at?->toIso8601String(),
                        'round_id' => $round->id,
                    ],
                    'safety_check' => $result['safety_check'] ?? null,
                ],
                    $data,
                    $duplicate ? 'duplicate' : $this->medicationProcessedStatus($data),
                    $duplicate,
                    $duplicate ? 'Dose already recorded for this round.' : null,
                );

                $this->rememberMedicationSyncResponse('round_admin', $data, $payload);

                if ($request->expectsJson()) {
                    return response()->json($payload);
                }

                return $duplicate
                    ? back()->with('status', 'Dose already recorded for this round.')
                    : back();
            },
            scopedInputResolver: function () use ($actionAt, $request): array {
                $data = $request->validate([
                    'status' => ['required', 'in:given,refused,held'],
                    'reason' => ['nullable', 'string', 'max:500'],
                    'reason_code' => ['nullable', 'string', 'max:60'],
                    'scheduled_for' => ['required', 'date'],
                    'witnessed_by' => ['nullable', 'integer', 'min:1'],
                    'witness_credential' => ['nullable', 'string', 'max:255'],
                    'quantity_administered' => ['nullable', 'numeric', MedicationStockQuantity::VALIDATION_RULE, 'min:0.01', 'max:10000'],
                    'blood_glucose_level' => ['nullable', 'numeric', 'min:0', 'max:999.9'],
                    'pulse_bpm' => ['nullable', 'integer', 'min:20', 'max:250'],
                    'blood_pressure_systolic' => ['nullable', 'integer', 'min:40', 'max:300'],
                    'blood_pressure_diastolic' => ['nullable', 'integer', 'min:20', 'max:200'],
                    ...$this->medicationOfflineSubmissionRules($request),
                ]);

                $submittedAdministrationAt = $this->medicationSubmittedAdministrationAt($data);

                return [
                    'payload' => $data,
                    'scheduled_for' => $this->scheduleService->parseWorkerDateTime((string) $data['scheduled_for']),
                    'action_at' => $submittedAdministrationAt !== null
                        ? $this->scheduleService->parseWorkerDateTime($submittedAdministrationAt)
                        : $actionAt,
                ];
            },
        );
    }

    /**
     * Explicitly mark the round complete after the worker has walked through
     * every item. Safe to call on an already-completed round.
     */
    public function complete(Request $request, MedicationRound $round)
    {
        $user = $request->user();
        abort_unless($this->canWork($user), 403);

        return $this->medicationScope->forRound(
            $user,
            $round,
            now(),
            function (MedicationScopeDecision $scope) {
                if ($scope->round->status !== 'completed') {
                    if (! $this->guidedRoundService->canCompleteCanonicalRoundUnderLock($scope->round)) {
                        throw ValidationException::withMessages([
                            'round' => 'This round cannot be completed yet.',
                        ]);
                    }

                    $scope->round->forceFill([
                        'status' => 'completed',
                        'completed_by' => $scope->performer->id,
                        'completed_at' => now(),
                    ])->save();
                    $scope->round->updateCounts();
                    $this->medicationScope->recordBreakGlassUse($scope, 'completed_medication_round');
                }

                return redirect()->route('meds.round.show', $scope->round);
            },
            ['in_progress', 'completed'],
            lockCanonicalMembership: true,
        );
    }

    private function canWork($user): bool
    {
        return (bool) $user && $user->canDo('medications.administer.record');
    }
}
