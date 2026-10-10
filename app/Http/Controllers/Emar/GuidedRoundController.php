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
use App\Services\Medication\DoseSlots\DoseSlotReaderScope;
use App\Services\Medication\MedicationScopeDecision;
use App\Services\Medication\MedicationScopeDecisionService;
use App\Services\Medication\SecondPersonConfirmationPayload;
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

        return $this->medicationScope->forRound($user, $round, now(), function (MedicationScopeDecision $scope) use ($request) {
            // Keep the selected day when opening the shared board walk-through.
            $dateStr = $scope->round->round_date instanceof \DateTimeInterface
                ? $scope->round->round_date->format('Y-m-d')
                : (string) $scope->round->round_date;

            return redirect()->route('meds.today', ['view' => 'rounds', 'round' => $scope->round->id, 'date' => $dateStr, ...$this->boardContext($request, $scope)]);
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
            function (MedicationScopeDecision $scope) use ($request) {
                $context = $this->boardContext($request, $scope);
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

                if ($request->input('return_to') === 'meds-today') {
                    return redirect()->route('meds.today', ['view' => 'rounds', 'round' => $scope->round->id, 'date' => $dateStr, ...$context]);
                }

                return redirect()->route('emar.rounds', [
                    'date' => $dateStr,
                    'guided' => $scope->round->id,
                ]);
            },
            ['pending', 'partial', 'in_progress'],
        );
    }

    /** Shown on the device when a retired round item (including a queued offline one) is refused. */
    public const RETIRED_MESSAGE = 'Round doses are recorded in Meds today now. Nothing was recorded from this round item — record the dose in Meds today, where its time window and checks apply.';

    /**
     * EA-151 (Lane C10): retired. The guided round records each dose through
     * Meds today (RecordDoseDialog → /meds/today/record), which enforces the
     * dose window and the full recording contract. This endpoint forced
     * override_window and so saved late doses with no late reason. It now
     * refuses without any effect; the answer is 409 so a device's queued
     * offline round item shows as rejected with this message rather than
     * replaying. Remove the route once offline queues have drained.
     */
    public function administer(Request $request, MedicationRound $round, int $medication)
    {
        abort_unless($this->canWork($request->user()), 403);

        return $request->expectsJson()
            ? response()->json(['success' => false, 'message' => self::RETIRED_MESSAGE], 409)
            : back()->with('error', self::RETIRED_MESSAGE);
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
            function (MedicationScopeDecision $scope) use ($request) {
                $context = $this->boardContext($request, $scope);
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

                if ($request->input('return_to') === 'meds-today') {
                    return redirect()->route('meds.today', [
                        'view' => 'rounds', 'round' => $scope->round->id,
                        'date' => $scope->round->round_date->toDateString(), ...$context,
                    ]);
                }

                return redirect()->route('meds.round.show', ['round' => $scope->round->id, ...$context]);
            },
            ['in_progress', 'completed'],
            lockCanonicalMembership: true,
        );
    }

    /** Only canonical person/Site context, never a caller-provided return URL. */
    private function boardContext(Request $request, MedicationScopeDecision $scope): array
    {
        $context = [];
        foreach (['client_id', 'client', 'pp'] as $key) {
            if (! $request->exists($key)) {
                continue;
            }
            $value = $request->input($key);
            if ($value === null || (is_string($value) && trim($value) === '')) {
                break;
            }
            $id = is_string($value) || is_int($value)
                ? filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : false;
            abort_unless($id !== false
                && in_array($id, DoseSlotReaderScope::forViewerClients($scope->performer, [$id])->clientIds ?? [], true), 404);
            abort_unless($this->guidedRoundService->items($scope->round, true, [$id]) !== [], 404);
            $context['client_id'] = $id;
            break;
        }
        foreach (['site_id', 'site'] as $key) {
            if (! $request->exists($key)) {
                continue;
            }
            $value = $request->input($key);
            if ($value === null || (is_string($value) && trim($value) === '')) {
                break;
            }
            $id = is_string($value) || is_int($value)
                ? filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : false;
            // forRound already rechecked current approval and this exact Site.
            abort_unless($id !== false && $id === (int) $scope->round->site_id, 404);
            $context['site_id'] = $id;
            break;
        }

        return $context;
    }

    private function canWork($user): bool
    {
        return (bool) $user && $user->canDo('medications.administer.record');
    }
}
