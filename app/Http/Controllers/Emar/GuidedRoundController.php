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
use App\Services\Timeline\TimelineEmitter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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
    ) {}

    /**
     * Render the guided round page.
     */
    public function show(Request $request, MedicationRound $round)
    {
        $user = $request->user();
        abort_unless($this->canWork($user), 403);

        // The guided walk-through is now a modal on /emar/rounds (and surfaced
        // on /meds/today). Redirect deep links there with the round pre-opened
        // — the rounds() controller builds the items/progress payload and
        // auto-starts a pending round for a competent viewer.
        $dateStr = $round->round_date instanceof \DateTimeInterface
            ? $round->round_date->format('Y-m-d')
            : (string) $round->round_date;

        return redirect()->route('emar.rounds', ['date' => $dateStr, 'guided' => $round->id]);
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
        abort_unless($medication->active, 422, 'This medication is not currently active.');

        $data = $request->validate([
            'status' => ['required', 'in:given,refused,held'],
            'reason' => ['nullable', 'string', 'max:500'],
            'reason_code' => ['nullable', 'string', 'max:60'],
            'scheduled_for' => ['required', 'date'],
            'witnessed_by' => ['nullable', 'integer', 'exists:users,id'],
            'witness_credential' => ['nullable', 'string', 'max:255'],
            'blood_glucose_level' => ['nullable', 'numeric', 'min:0', 'max:999.9'],
            'pulse_bpm' => ['nullable', 'integer', 'min:20', 'max:250'],
            'blood_pressure_systolic' => ['nullable', 'integer', 'min:40', 'max:300'],
            'blood_pressure_diastolic' => ['nullable', 'integer', 'min:20', 'max:200'],
            'client_request_uuid' => ['nullable', 'uuid'],
            'captured_offline_at' => ['nullable', 'date'],
            'origin_device_id' => ['nullable', 'string', 'max:255'],
            'queued_offline' => ['nullable', 'boolean'],
        ]);

        if ($cached = $this->getCachedMedicationSyncResponse('round_admin', $data)) {
            if ($request->expectsJson()) {
                return response()->json($cached);
            }

            return back()->with('status', 'Dose already recorded for this round.');
        }

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

        $scheduled = $this->scheduleService->parseWorkerDateTime((string) $data['scheduled_for']);

        return DB::transaction(function () use ($request, $round, $medication, $data, $backendStatus, $scheduled, $user) {
            // Guard against double administration for the same dose in the
            // same round (covers a worker tapping twice, or resuming after a
            // partial network error).
            $existing = $medication->administrations()
                ->where('medication_round_id', $round->id)
                ->whereBetween('scheduled_for', $this->scheduleService->utcSlotWindow($scheduled))
                ->first();

            if ($existing) {
                if (($data['queued_offline'] ?? false) && $request->expectsJson()) {
                    return response()->json(
                        $this->buildMedicationConflictPayload(
                            $data,
                            'Medication state changed before this offline round item could sync. Supervisor review is required.',
                        ),
                        409,
                    );
                }

                if ($request->expectsJson()) {
                    return response()->json(
                        $this->withMedicationSync(
                            [
                                'success' => true,
                                'administration' => [
                                    'id' => $existing->id,
                                    'status' => $existing->status,
                                    'administered_at' => $existing->administered_at?->toIso8601String(),
                                    'round_id' => $round->id,
                                ],
                            ],
                            $data,
                            'duplicate',
                            true,
                            'Dose already recorded for this round.',
                        ),
                    );
                }

                return back()->with('status', 'Dose already recorded for this round.');
            }

            $result = $this->marService->recordAdministration(
                $medication->client,
                $medication,
                [
                    'status' => $backendStatus,
                    'reason' => $data['reason'] ?? null,
                    'reason_code' => $data['reason_code'] ?? null,
                    'scheduled_for' => $scheduled->toIso8601String(),
                    'administered_at' => now()->toIso8601String(),
                    'witnessed_by' => $data['witnessed_by'] ?? null,
                    'witness_credential' => $data['witness_credential'] ?? null,
                    'blood_glucose_level' => $data['blood_glucose_level'] ?? null,
                    'pulse_bpm' => $data['pulse_bpm'] ?? null,
                    'blood_pressure_systolic' => $data['blood_pressure_systolic'] ?? null,
                    'blood_pressure_diastolic' => $data['blood_pressure_diastolic'] ?? null,
                    // The round's own window_minutes is the authoritative
                    // schedule for a guided round; skip the narrower MAR
                    // time-window check here so workers aren't blocked by it
                    // while walking through a round that's still inside its
                    // own legitimate window.
                    'override_window' => true,
                ],
                $user->id,
                null,
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

            // Link the administration to the round so progress stays honest
            // and the round counters can be updated off a single query.
            $admin = $result['administration'];
            $admin->medication_round_id = $round->id;
            $admin->save();

            $statusLabel = ucfirst(str_replace('_', ' ', $backendStatus));
            app(TimelineEmitter::class)->record([
                'source_type' => ClientMedicationAdministration::class,
                'source_id' => $admin->id,
                'occurred_at' => $admin->administered_at ?? now(),
                'type' => 'medication_'.$backendStatus,
                'actor_user_id' => $user->id,
                'client_id' => $medication->client_id,
                'shift_id' => null,
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
            ], $data, $this->medicationProcessedStatus($data));

            $this->rememberMedicationSyncResponse('round_admin', $data, $payload);

            if ($request->expectsJson()) {
                return response()->json($payload);
            }

            return back();
        });
    }

    /**
     * Explicitly mark the round complete after the worker has walked through
     * every item. Safe to call on an already-completed round.
     */
    public function complete(Request $request, MedicationRound $round)
    {
        $user = $request->user();
        abort_unless($this->canWork($user), 403);

        if ($round->status !== 'completed') {
            $round->forceFill([
                'status' => 'completed',
                'completed_by' => $user->id,
                'completed_at' => now(),
            ])->save();
        }

        $round->updateCounts();

        return redirect()->route('meds.round.show', $round);
    }

    private function canWork($user): bool
    {
        return (bool) $user && (
            $user->canDo('medications.administer.record')
            || $user->canDo('clients.update')
            || $user->canDo('medications.orders.manage')
        );
    }
}
