<?php

namespace App\Http\Controllers\Emar;

use App\Http\Controllers\Concerns\HandlesOfflineSubmission;
use App\Http\Controllers\Controller;
use App\Models\ClientMedication;
use App\Models\MedicationRound;
use App\Models\Shift;
use App\Models\User;
use App\Services\EnhancedMarService;
use App\Services\GuidedRoundService;
use App\Services\MarScheduleService;
use App\Support\EmarUrl;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * PR 12 — Worker-facing medication surface.
 *
 * `/meds/today` is the frontline medication home. It is deliberately narrow:
 * what a support worker needs to know *right now* to give medications safely
 * and on time. Admin-level compliance, stock, review and register screens live
 * on `/emar` and are intentionally not mirrored here.
 *
 * Reuses:
 *   - `GuidedRoundService` for active-round progress (matches `/my-day`).
 *   - The same dose-window logic used by `MyTasksController::getMedicationsDue`.
 *
 * Entry is gated by `medications.administer.record|clients.update|medications.orders.manage`
 * so that both frontline workers and manager/leads who also want the
 * operational view can load it.
 */
class WorkerMedsController extends Controller
{
    use HandlesOfflineSubmission;

    public function __construct(
        protected GuidedRoundService $guidedRoundService,
        protected EnhancedMarService $marService,
        protected MarScheduleService $scheduleService,
    ) {
    }

    public function today(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user, 403);

        $now = Carbon::now($this->workerTimezone());
        $today = $now->copy()->startOfDay()->utc();
        $tomorrowEnd = $now->copy()->addDay()->endOfDay()->utc();

        $assignedClientIds = $this->assignedClientIdsFor($user, $today, $tomorrowEnd);

        $medsDue = $this->medicationsDue($assignedClientIds, $now);
        $activeRound = $this->activeRound($user, $now);
        $upcomingRounds = $this->upcomingRounds($user, $now);
        $prnMedications = $this->prnMedications($assignedClientIds);

        $dueNow = array_values(array_filter(
            $medsDue,
            fn ($m) => $m['status'] === 'overdue' || $m['status'] === 'due',
        ));
        $dueLater = array_values(array_filter(
            $medsDue,
            fn ($m) => $m['status'] === 'upcoming',
        ));
        $overdue = array_values(array_filter(
            $medsDue,
            fn ($m) => $m['status'] === 'overdue',
        ));

        return Inertia::render('meds/today/index', [
            'today' => $now->format('l, j F Y'),
            'stats' => [
                'meds_due' => count($medsDue),
                'meds_overdue' => count($overdue),
                'due_now' => count($dueNow),
                'due_later' => count($dueLater),
                'upcoming_rounds' => count($upcomingRounds),
            ],
            'active_round' => $activeRound,
            'upcoming_rounds' => $upcomingRounds,
            'due_now' => $dueNow,
            'due_later' => $dueLater,
            'prn_medications' => $prnMedications,
            'has_shift_context' => ! empty($assignedClientIds),
        ]);
    }

    /**
     * Record a PRN (as-needed) dose from the frontline quick-entry sheet.
     *
     * Delegates to the same EnhancedMarService the rest of the medication
     * module uses, so safety checks, controlled-drug witness rules, audit and
     * PRN over-limit incident handling all run exactly as they do from the
     * admin recording path. This avoids creating a second administration path
     * for as-needed doses.
     */
    public function recordPrn(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user, 403);

        abort_unless(
            $user->canDo('medications.administer.record') || $user->canDo('clients.update'),
            403,
            'You do not have permission to record medication administrations.'
        );

        $data = $request->validate([
            'client_medication_id' => ['required', 'integer', 'exists:client_medications,id'],
            'reason' => ['required', 'string', 'max:500'],
            'dose_given' => ['nullable', 'string', 'max:255'],
            'witnessed_by' => ['nullable', 'integer', 'exists:users,id'],
            'witness_credential' => ['nullable', 'string', 'max:255'],
            'blood_glucose_level' => ['nullable', 'numeric', 'min:0', 'max:999.9'],
            'pulse_bpm' => ['nullable', 'integer', 'min:20', 'max:250'],
            'blood_pressure_systolic' => ['nullable', 'integer', 'min:40', 'max:300'],
            'blood_pressure_diastolic' => ['nullable', 'integer', 'min:20', 'max:200'],
            'notes' => ['nullable', 'string', 'max:2000'],
            ...$this->offlineSubmissionRules(),
        ]);

        return $this->runOfflineSubmissionOnce('prn', $data, function () use ($user, $data) {
            $medication = ClientMedication::with('client')->findOrFail($data['client_medication_id']);

            abort_unless($medication->is_prn, 422, 'This medication is not configured as an as-needed (PRN) med.');
            abort_unless($medication->active, 422, 'This medication is not currently active.');
            abort_unless($medication->client, 404);

            // Best-effort shift linkage: an active shift for this worker with this
            // client. Falls back to null if the worker is outside a shift (e.g. a
            // medication lead helping out) so the administration still records.
            $shiftId = Shift::query()
                ->where('user_id', $user->id)
                ->where('client_id', $medication->client_id)
                ->whereNotNull('actual_starts_at')
                ->whereNull('actual_ends_at')
                ->latest('actual_starts_at')
                ->value('id');

            $result = $this->marService->recordAdministration(
                $medication->client,
                $medication,
                [
                    'status' => 'given',
                    'reason' => trim($data['reason']),
                    'dose_given' => $data['dose_given'] ?? null,
                    'witnessed_by' => $data['witnessed_by'] ?? null,
                    'witness_credential' => $data['witness_credential'] ?? null,
                    'blood_glucose_level' => $data['blood_glucose_level'] ?? null,
                    'pulse_bpm' => $data['pulse_bpm'] ?? null,
                    'blood_pressure_systolic' => $data['blood_pressure_systolic'] ?? null,
                    'blood_pressure_diastolic' => $data['blood_pressure_diastolic'] ?? null,
                    'notes' => $data['notes'] ?? null,
                    'administered_at' => $data['captured_offline_at']
                        ?? now()->toIso8601String(),
                ],
                $user->id,
                $shiftId,
            );

            if (! ($result['success'] ?? false)) {
                return back()->withErrors([
                    'reason' => $result['error'] ?? 'Could not record this PRN dose.',
                ]);
            }

            return back()->with(
                'success',
                'Saved — ' . $medication->name . ' recorded for ' . trim(($medication->client->first_name ?? '') . ' ' . ($medication->client->last_name ?? '')),
            );
        });
    }

    /**
     * Clients this worker has a shift for today/tomorrow. When a worker has no
     * shift context (e.g. a medication lead opening the worker view) we still
     * want to degrade gracefully rather than wipe the page — fall back to all
     * clients they can see meds for.
     */
    private function assignedClientIdsFor(User $user, Carbon $today, Carbon $tomorrowEnd): array
    {
        try {
            $shiftClientIds = Shift::where('user_id', $user->id)
                ->whereBetween('starts_at', [$today, $tomorrowEnd])
                ->pluck('client_id')
                ->filter()
                ->unique()
                ->values()
                ->all();

            if (! empty($shiftClientIds)) {
                return $shiftClientIds;
            }
        } catch (\Throwable $e) {
            report($e);
            // fall through
        }

        // Fallback: a manager / medication lead with no shift today still gets
        // a useful worker view across every client currently on a medication.
        if ($user->canDo('medications.orders.manage') || $user->canDo('medications.view')) {
            try {
                return ClientMedication::active()
                    ->pluck('client_id')
                    ->filter()
                    ->unique()
                    ->values()
                    ->all();
            } catch (\Throwable $e) {
                report($e);

                return [];
            }
        }

        return [];
    }

    /**
     * Non-PRN scheduled doses for today inside the operational window. Mirrors
     * `MyTasksController::getMedicationsDue` so `/my-day` and `/meds/today`
     * always agree about what's due.
     */
    private function medicationsDue(array $clientIds, Carbon $now): array
    {
        if (empty($clientIds)) {
            return [];
        }

        try {
            $windowStart = $now->copy()->subHours(2);
            $windowEnd = $now->copy()->addHours(8);

            $medications = ClientMedication::whereIn('client_id', $clientIds)
                ->active()
                ->where('is_prn', false)
                ->where(function ($query) {
                    $query->whereNotNull('dose_times')
                        ->orWhereNotNull('frequency');
                })
                ->with('client:id,first_name,last_name')
                ->get();

            // One administration query for the whole window, matched in memory
            // per slot — replaces the old per-dose-slot query (an N+1 that
            // re-ran every 60s with the page's live refresh).
            $administrations = $this->scheduleService->administrationsForWindow($clientIds, $windowStart, $windowEnd);

            $result = [];

            foreach ($medications as $med) {
                $day = $windowStart->copy()->startOfDay();
                $lastDay = $windowEnd->copy()->startOfDay();

                while ($day->lessThanOrEqualTo($lastDay)) {
                    foreach ($this->scheduleService->scheduledTimesForDate($med, $day) as $scheduled) {
                        if ($scheduled->lt($windowStart) || $scheduled->gt($windowEnd)) {
                            continue;
                        }

                        $administration = $administrations->get(
                            $this->scheduleService->slotKey((int) $med->client_id, (int) $med->id, $scheduled),
                        );

                        if ($administration && in_array($administration->status, ['given', 'refused', 'withheld', 'missed'], true)) {
                            continue;
                        }

                        if ($scheduled->lt($now)) {
                            $status = 'overdue';
                        } elseif ($scheduled->lte($now->copy()->addHour())) {
                            $status = 'due';
                        } else {
                            $status = 'upcoming';
                        }

                        $clientName = $med->client
                            ? trim($med->client->first_name . ' ' . $med->client->last_name)
                            : 'Unknown';

                        $result[] = [
                            'client_id' => $med->client_id,
                            'client_name' => $clientName,
                            'medication_id' => $med->id,
                            'medication_name' => $med->name,
                            'dose' => $med->dosage,
                            'route' => $med->route,
                            'is_controlled' => (bool) ($med->controlled_drug ?? false),
                            'scheduled_for' => $scheduled->toIso8601String(),
                            'status' => $status,
                            'mar_url' => EmarUrl::mar($med->client_id, $scheduled->toDateString()),
                        ];
                    }

                    $day->addDay();
                }
            }

            usort($result, function ($a, $b) {
                $order = ['overdue' => 0, 'due' => 1, 'upcoming' => 2];
                $statusCmp = ($order[$a['status']] ?? 3) <=> ($order[$b['status']] ?? 3);
                if ($statusCmp !== 0) {
                    return $statusCmp;
                }

                return strcmp($a['scheduled_for'], $b['scheduled_for']);
            });

            return $result;
        } catch (\Throwable $e) {
            report($e);

            return [];
        }
    }

    /**
     * PRN (as-needed) medications available for quick recording from the
     * /meds/today sheet. Scoped to the same assigned-client set the rest of
     * the page uses, so a worker only ever sees PRN meds for people they're
     * actively supporting.
     *
     * Keeps the payload small: just the fields the quick sheet needs to show
     * client, med, default dose and PRN pressure (near/over daily limit).
     */
    private function prnMedications(array $clientIds): array
    {
        if (empty($clientIds)) {
            return [];
        }

        try {
            $medications = ClientMedication::whereIn('client_id', $clientIds)
                ->active()
                ->prn()
                ->with('client:id,first_name,last_name')
                ->orderBy('client_id')
                ->orderBy('name')
                ->get();

            $result = [];

            foreach ($medications as $med) {
                if (! $med->client) {
                    continue;
                }

                $maxPerDay = $med->max_per_day ? (int) $med->max_per_day : null;
                $givenLast24h = $med->prnCountLast24Hours;
                $remaining = $maxPerDay !== null ? max(0, $maxPerDay - $givenLast24h) : null;

                $result[] = [
                    'id' => $med->id,
                    'client_id' => $med->client_id,
                    'client_name' => trim($med->client->first_name . ' ' . $med->client->last_name),
                    'name' => $med->name,
                    'dose' => $med->dosage,
                    'route' => $med->route,
                    'form' => $med->form,
                    'instructions' => $med->instructions,
                    'prn_reason' => $med->prn_reason,
                    'max_per_day' => $maxPerDay,
                    'given_last_24h' => $givenLast24h,
                    'remaining_today' => $remaining,
                    'near_limit' => $med->isPrnNearLimit(),
                    'over_limit' => $med->isPrnOverLimit(),
                    'is_controlled' => (bool) ($med->controlled_drug ?? false),
                    'requires_witness' => (bool) ($med->witness_required ?? false) || (bool) ($med->controlled_drug ?? false),
                ];
            }

            return $result;
        } catch (\Throwable $e) {
            report($e);

            return [];
        }
    }

    /**
     * The single round the worker should walk right now, matching the
     * `/my-day` banner exactly (same service, same precedence).
     */
    private function activeRound(User $user, Carbon $now): ?array
    {
        if (! $user->canDo('medications.administer.record')
            && ! $user->canDo('clients.update')
            && ! $user->canDo('medications.orders.manage')) {
            return null;
        }

        try {
            $round = MedicationRound::query()
                ->whereDate('round_date', $now->toDateString())
                ->where(function ($q) use ($user) {
                    $q->where('assigned_to', $user->id)
                        ->orWhere('started_by', $user->id);
                })
                ->whereIn('status', ['in_progress', 'pending'])
                ->orderByRaw("CASE status WHEN 'in_progress' THEN 0 WHEN 'pending' THEN 1 ELSE 2 END")
                ->orderBy('scheduled_time')
                ->first();

            if (! $round) {
                return null;
            }

            $progress = $this->guidedRoundService->progress($round);

            if ($progress['total'] === 0) {
                return null;
            }

            return [
                'id' => $round->id,
                'name' => $round->name,
                'status' => $round->status,
                'scheduled_time' => $round->scheduled_time,
                'given' => $progress['given'],
                'total' => $progress['total'],
                'completed' => $progress['completed'],
                'percent' => $progress['percent'],
                'url' => route('meds.round.show', $round),
            ];
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }

    /**
     * Other scheduled rounds later today the worker can see / jump to. Kept
     * intentionally light — no admin filtering controls — so the page reads as
     * "here's what's coming" rather than a full round-management screen.
     */
    private function upcomingRounds(User $user, Carbon $now): array
    {
        try {
            $rounds = MedicationRound::query()
                ->whereDate('round_date', $now->toDateString())
                ->where(function ($q) use ($user) {
                    // Surface the worker's own rounds, unassigned rounds, and
                    // anything starting soon so a worker covering for someone
                    // else still sees what's next.
                    $q->where('assigned_to', $user->id)
                        ->orWhereNull('assigned_to');
                })
                ->whereIn('status', ['pending', 'in_progress'])
                ->orderBy('scheduled_time')
                ->limit(6)
                ->get();

            return $rounds
                ->map(function (MedicationRound $round) {
                    $progress = $this->guidedRoundService->progress($round);

                    return [
                        'id' => $round->id,
                        'name' => $round->name,
                        'status' => $round->status,
                        'scheduled_time' => $round->scheduled_time,
                        'total' => $progress['total'],
                        'completed' => $progress['completed'],
                        'percent' => $progress['percent'],
                        'url' => route('meds.round.show', $round),
                    ];
                })
                ->filter(fn ($r) => $r['total'] > 0)
                ->values()
                ->all();
        } catch (\Throwable $e) {
            report($e);

            return [];
        }
    }

    private function workerTimezone(): string
    {
        return (string) config('app.worker_timezone', 'Pacific/Auckland');
    }
}
