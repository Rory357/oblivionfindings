<?php

namespace App\Http\Controllers\Emar;

use App\Http\Controllers\Controller;
use App\Models\ClientMedication;
use App\Models\MedicationRound;
use App\Services\EnhancedMarService;
use App\Services\GuidedRoundService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

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
    public function __construct(
        protected GuidedRoundService $guidedRoundService,
        protected EnhancedMarService $marService,
    ) {
    }

    /**
     * Render the guided round page.
     */
    public function show(Request $request, MedicationRound $round)
    {
        $user = $request->user();
        abort_unless($this->canWork($user), 403);

        $items = $this->guidedRoundService->items($round);
        $progress = $this->guidedRoundService->summarise($items);

        // Auto-start on first entry when the round is still pending and the
        // worker is cleared to administer. Completing is left explicit.
        if ($round->status === 'pending') {
            $round->forceFill([
                'status' => 'in_progress',
                'started_by' => $user->id,
                'started_at' => now(),
            ])->save();
            $round->refresh();
        }

        return Inertia::render('meds/round/guided', [
            'round' => [
                'id' => $round->id,
                'name' => $round->name,
                'status' => $round->status,
                'scheduled_time' => $round->scheduled_time,
                'window_minutes' => $round->window_minutes,
                'round_date' => $round->round_date?->toDateString(),
                'started_at' => $round->started_at?->toIso8601String(),
                'completed_at' => $round->completed_at?->toIso8601String(),
            ],
            'items' => $items,
            'progress' => $progress,
        ]);
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
            'scheduled_for' => ['required', 'date'],
            'witnessed_by' => ['nullable', 'integer', 'exists:users,id'],
        ]);

        // Worker vocabulary: "held" → backend "withheld".
        $backendStatus = $data['status'] === 'held' ? 'withheld' : $data['status'];

        if ($backendStatus !== 'given' && empty($data['reason'])) {
            return back()->withErrors([
                'reason' => 'Please tell us why this dose was not given.',
            ]);
        }

        $scheduled = Carbon::parse($data['scheduled_for']);

        return DB::transaction(function () use ($round, $medication, $data, $backendStatus, $scheduled, $user) {
            // Guard against double administration for the same dose in the
            // same round (covers a worker tapping twice, or resuming after a
            // partial network error).
            $existing = $medication->administrations()
                ->where('medication_round_id', $round->id)
                ->whereBetween('scheduled_for', [
                    $scheduled->copy()->subSeconds(30),
                    $scheduled->copy()->addSeconds(30),
                ])
                ->first();

            if ($existing) {
                return back()->with('status', 'Dose already recorded for this round.');
            }

            $result = $this->marService->recordAdministration(
                $medication->client,
                $medication,
                [
                    'status' => $backendStatus,
                    'reason' => $data['reason'] ?? null,
                    'scheduled_for' => $scheduled->toIso8601String(),
                    'administered_at' => now()->toIso8601String(),
                    'witnessed_by' => $data['witnessed_by'] ?? null,
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
                return back()->withErrors([
                    'status' => $result['error'] ?? 'Could not record this dose.',
                ]);
            }

            // Link the administration to the round so progress stays honest
            // and the round counters can be updated off a single query.
            $admin = $result['administration'];
            $admin->medication_round_id = $round->id;
            $admin->save();

            $round->updateCounts();

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
