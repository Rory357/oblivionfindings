<?php

namespace App\Http\Controllers\Emar;

use App\Enums\Medication\NotGivenReason;
use App\Http\Controllers\Concerns\HandlesOfflineSubmission;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\ClientMedication;
use App\Models\ClientMedicationAdministration;
use App\Models\ClientMedicationStock;
use App\Models\MedicationPrnEffectiveness;
use App\Models\MedicationRound;
use App\Models\Shift;
use App\Models\TimelineEvent;
use App\Models\User;
use App\Services\EnhancedMarService;
use App\Services\GuidedRoundService;
use App\Services\MarScheduleService;
use App\Services\Timeline\TimelineEmitter;
use App\Support\EmarUrl;
use App\Http\Middleware\HandleInertiaRequests;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Worker-facing medication surface.
 *
 * `/meds/today` is the frontline medication home: a full-day medication board
 * for the clients on the worker's shift — every scheduled dose (including the
 * ones already recorded), guided rounds, PRN meds with limits, stock alerts
 * and today's activity. Admin-level compliance, registers and review screens
 * stay on `/emar`.
 *
 * Reuses:
 *   - `GuidedRoundService` for round progress (matches `/my-day`).
 *   - `MarScheduleService` for dose-time parsing and slot matching.
 *   - `EnhancedMarService::recordAdministration` for every write, so safety
 *     checks, witness rules, CD register entries and audit run identically to
 *     the admin recording path.
 *
 * Entry is gated by `medications.administer.record|clients.update|medications.orders.manage`
 * so that both frontline workers and manager/leads who also want the
 * operational view can load it.
 */
class WorkerMedsController extends Controller
{
    use HandlesOfflineSubmission;

    /** Statuses that mean a dose slot has been actioned and needs no chasing. */
    private const RECORDED_STATUSES = ['given', 'refused', 'withheld', 'missed'];

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

        $timezone = $this->scheduleService->workerTimezone();
        $now = Carbon::now($timezone);
        $date = $this->scheduleService->dateFromInput($request->query('date'));
        $isToday = $date->isSameDay($now);

        $assignedClientIds = $this->assignedClientIdsFor(
            $user,
            $date->copy()->utc(),
            $date->copy()->addDay()->endOfDay()->utc(),
        );

        // One detailed administrations query for the whole selected day. It is
        // reused for (a) matching scheduled dose slots, and (b) deriving the
        // PRN follow-up queue — keeping the today() path at a single
        // client_medication_administrations query regardless of slot count.
        $dayAdministrations = $this->administrationsForDay($assignedClientIds, $date);
        $bySlot = $dayAdministrations
            ->filter(fn (ClientMedicationAdministration $a) => $a->getRawOriginal('scheduled_for') !== null)
            ->keyBy(fn (ClientMedicationAdministration $a) => $this->scheduleService->slotKey(
                (int) $a->client_id,
                (int) $a->client_medication_id,
                $this->rawUtcInstant($a, 'scheduled_for'),
            ));

        $schedule = $this->scheduleForDate($assignedClientIds, $date, $now, $bySlot);

        // Legacy due lists (kept for the established payload contract): the
        // operational "what needs me" window of -2h … +8h around now.
        $windowStart = $now->copy()->subHours(2);
        $windowEnd = $now->copy()->addHours(8);
        $medsDue = array_values(array_filter($schedule, function (array $row) use ($windowStart, $windowEnd) {
            if ($row['recorded'] !== null) {
                return false;
            }
            $scheduled = Carbon::parse($row['scheduled_for']);

            return $scheduled->gte($windowStart) && $scheduled->lte($windowEnd);
        }));

        $dueNow = array_values(array_filter($medsDue, fn ($m) => in_array($m['status'], ['overdue', 'due'], true)));
        $dueLater = array_values(array_filter($medsDue, fn ($m) => $m['status'] === 'upcoming'));
        $overdue = array_values(array_filter($medsDue, fn ($m) => $m['status'] === 'overdue'));

        $activeRound = $isToday ? $this->activeRound($user, $now) : null;
        $rounds = $this->roundsForDate($user, $date);
        $upcomingRounds = array_values(array_filter(
            $rounds,
            fn ($r) => in_array($r['status'], ['pending', 'in_progress'], true),
        ));

        $prnMedications = $this->prnMedications($assignedClientIds, $now);

        return Inertia::render('meds/today/index', [
            'today' => $now->format('l, j F Y'),
            'date' => $date->toDateString(),
            'date_label' => $date->format('l j F Y'),
            'is_today' => $isToday,
            'server_now' => $now->toIso8601String(),
            'now_label' => $now->format('g:i a'),
            'stats' => [
                'meds_due' => count($medsDue),
                'meds_overdue' => count($overdue),
                'due_now' => count($dueNow),
                'due_later' => count($dueLater),
                'upcoming_rounds' => count($upcomingRounds),
            ],
            'active_round' => $activeRound,
            'upcoming_rounds' => $upcomingRounds,
            'rounds' => $rounds,
            'due_now' => $dueNow,
            'due_later' => $dueLater,
            'schedule' => $schedule,
            'clients' => $this->clientsPayload($assignedClientIds),
            'sites' => $this->sitesPayload($assignedClientIds),
            'prn_medications' => $prnMedications,
            'prn_follow_ups' => $this->prnFollowUps($dayAdministrations, $timezone),
            'stock_alerts' => $this->stockAlerts($assignedClientIds),
            'activity' => $this->activityForDate($assignedClientIds, $date, $dayAdministrations),
            'witnesses' => $this->witnesses($user),
            'not_given_reasons' => NotGivenReason::options(),
            'shift_label' => $this->shiftLabel($user, $date, $timezone),
            'board_user' => [
                'first_name' => Str::before(trim((string) $user->name), ' ') ?: $user->name,
                'name' => $user->name,
                'role_label' => $user->role ? Str::headline($user->role) : null,
                'med_competent' => $user->canDo('medications.administer.record'),
                'cd_witness' => $user->canDo('medications.controlled.witness'),
            ],
            'board_can' => [
                'view_emar' => $user->canDo('medications.view'),
                'view_audit' => $user->canDo('medications.audit.view'),
            ],
            'has_shift_context' => ! empty($assignedClientIds),
        ]);
    }

    /**
     * Record the outcome of a scheduled dose (given / refused / withheld) from
     * the desktop Record Dose wizard.
     *
     * Delegates to the same EnhancedMarService the round and admin paths use,
     * so the five safety layers (verification state, not-given reasons,
     * required observations, witness + credential, time window) all run
     * exactly as they do everywhere else. No second administration path.
     */
    public function recordDose(Request $request): RedirectResponse
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
            'scheduled_for' => ['required', 'date'],
            'status' => ['required', 'in:given,refused,withheld'],
            'reason_code' => ['nullable', 'string', 'max:60', 'required_unless:status,given'],
            'reason' => ['nullable', 'string', 'max:500'],
            'administered_at' => ['nullable', 'date'],
            'witnessed_by' => ['nullable', 'integer', 'exists:users,id'],
            'witness_credential' => ['nullable', 'string', 'max:255'],
            'cd_balance' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'blood_glucose_level' => ['nullable', 'numeric', 'min:0', 'max:999.9'],
            'pulse_bpm' => ['nullable', 'integer', 'min:20', 'max:250'],
            'blood_pressure_systolic' => ['nullable', 'integer', 'min:40', 'max:300'],
            'blood_pressure_diastolic' => ['nullable', 'integer', 'min:20', 'max:200'],
            'notes' => ['nullable', 'string', 'max:2000'],
            ...$this->offlineSubmissionRules(),
        ]);

        return $this->runOfflineSubmissionOnce('dose', $data, function () use ($user, $data) {
            $medication = ClientMedication::with('client')->findOrFail($data['client_medication_id']);

            abort_if($medication->is_prn, 422, 'As-needed medications are recorded through the PRN flow.');
            abort_unless($medication->client, 404);

            $shiftId = $this->activeShiftIdFor($user, (int) $medication->client_id);

            $notes = trim((string) ($data['notes'] ?? ''));
            if (($data['cd_balance'] ?? null) !== null) {
                $balanceLine = 'CD register balance after dose: ' . $data['cd_balance'];
                $notes = $notes === '' ? $balanceLine : $notes . "\n" . $balanceLine;
            }

            $result = $this->marService->recordAdministration(
                $medication->client,
                $medication,
                [
                    'status' => $data['status'],
                    'reason' => $data['reason'] ?? null,
                    'reason_code' => $data['status'] === 'given' ? null : ($data['reason_code'] ?? null),
                    'dose_given' => $data['status'] === 'given' ? $medication->dosage : null,
                    'scheduled_for' => $data['scheduled_for'],
                    'administered_at' => $data['administered_at']
                        ?? $data['captured_offline_at']
                        ?? now()->toIso8601String(),
                    'witnessed_by' => $data['witnessed_by'] ?? null,
                    'witness_credential' => $data['witness_credential'] ?? null,
                    'blood_glucose_level' => $data['blood_glucose_level'] ?? null,
                    'pulse_bpm' => $data['pulse_bpm'] ?? null,
                    'blood_pressure_systolic' => $data['blood_pressure_systolic'] ?? null,
                    'blood_pressure_diastolic' => $data['blood_pressure_diastolic'] ?? null,
                    'notes' => $notes !== '' ? $notes : null,
                ],
                $user->id,
                $shiftId,
            );

            if (! ($result['success'] ?? false)) {
                $field = $result['error_field'] ?? 'status';

                return back()->withErrors([
                    $field => $result['error'] ?? 'Could not record this dose.',
                ]);
            }

            $clientName = trim(($medication->client->first_name ?? '') . ' ' . ($medication->client->last_name ?? ''));

            if ($result['duplicate'] ?? false) {
                return back()->with('warning', 'This dose was already recorded — no changes made.');
            }

            $this->emitMedicationTimelineEvent($result['administration'], $medication, $user, $shiftId);

            // The sidebar overdue badge caches for 60s — recording a dose is
            // the one action that should drop it immediately.
            Cache::forget(HandleInertiaRequests::medsOverdueBadgeCacheKey(
                (int) $user->id,
                Carbon::now($this->scheduleService->workerTimezone())->toDateString(),
            ));

            $outcome = match ($data['status']) {
                'refused' => 'recorded as refused',
                'withheld' => 'recorded as withheld',
                default => 'recorded to the MAR',
            };

            return back()->with('success', $medication->name . ' ' . $outcome . ' for ' . $clientName);
        });
    }

    /**
     * Record a PRN (as-needed) dose from the frontline quick-entry flow.
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
            'administered_at' => ['nullable', 'date'],
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

            $shiftId = $this->activeShiftIdFor($user, (int) $medication->client_id);

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
                    'administered_at' => $data['administered_at']
                        ?? $data['captured_offline_at']
                        ?? now()->toIso8601String(),
                ],
                $user->id,
                $shiftId,
            );

            if (! ($result['success'] ?? false)) {
                $field = $result['error_field'] ?? 'reason';

                return back()->withErrors([
                    $field => $result['error'] ?? 'Could not record this PRN dose.',
                ]);
            }

            $this->emitMedicationTimelineEvent($result['administration'], $medication, $user, $shiftId);

            return back()->with(
                'success',
                'Saved — ' . $medication->name . ' recorded for ' . trim(($medication->client->first_name ?? '') . ' ' . ($medication->client->last_name ?? '')),
            );
        });
    }

    /**
     * Record the effect of a PRN dose (the follow-up check). Worker-scoped
     * mirror of the admin `emar.prn_effectiveness.store` endpoint so the
     * follow-up queue on the board clears through the same register.
     */
    public function recordPrnEffect(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user, 403);

        abort_unless(
            $user->canDo('medications.administer.record') || $user->canDo('clients.update'),
            403,
            'You do not have permission to record medication administrations.'
        );

        $data = $request->validate([
            'client_medication_administration_id' => ['required', 'integer', 'exists:client_medication_administrations,id'],
            'effectiveness' => ['required', 'in:effective,partially_effective,not_effective'],
            'observations' => ['nullable', 'string', 'max:2000'],
            'escalation_needed' => ['nullable', 'boolean'],
            'escalation_action' => ['nullable', 'string', 'max:500'],
        ]);

        $administration = ClientMedicationAdministration::with(['medication:id,name,is_prn', 'prnEffectiveness'])
            ->findOrFail($data['client_medication_administration_id']);

        abort_unless($administration->medication?->is_prn, 422, 'Only PRN doses take an effectiveness check.');

        if ($administration->prnEffectiveness) {
            return back()->with('warning', 'The effect of this dose has already been recorded.');
        }

        $reviewMinutes = $administration->administered_at
            ? max(0, (int) round($this->rawUtcInstant($administration, 'administered_at')->diffInMinutes(now('UTC'))))
            : null;

        MedicationPrnEffectiveness::create([
            'client_medication_administration_id' => $administration->id,
            'client_id' => $administration->client_id,
            'client_medication_id' => $administration->client_medication_id,
            'effectiveness' => $data['effectiveness'],
            'review_minutes_after' => $reviewMinutes,
            'observations' => $data['observations'] ?? null,
            'escalation_needed' => (bool) ($data['escalation_needed'] ?? false),
            'escalation_action' => $data['escalation_action'] ?? null,
            'reviewed_by' => $user->id,
            'reviewed_at' => now(),
        ]);

        return back()->with('success', 'Follow-up recorded — effect noted on the PRN register.');
    }

    /**
     * Clients this worker has a shift for on the selected day (or the day
     * after, so late shifts crossing midnight keep their context). When a
     * worker has no shift context (e.g. a medication lead opening the worker
     * view) we still want to degrade gracefully rather than wipe the page —
     * fall back to all clients they can see meds for.
     */
    private function assignedClientIdsFor(User $user, Carbon $from, Carbon $to): array
    {
        try {
            $shiftClientIds = Shift::where('user_id', $user->id)
                ->whereBetween('starts_at', [$from, $to])
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

    /** The worker's currently clocked-in shift for this client, if any. */
    private function activeShiftIdFor(User $user, int $clientId): ?int
    {
        return Shift::query()
            ->where('user_id', $user->id)
            ->where('client_id', $clientId)
            ->whereNotNull('actual_starts_at')
            ->whereNull('actual_ends_at')
            ->latest('actual_starts_at')
            ->value('id');
    }

    /**
     * Mirror the guided-round controller's timeline emission so worker-board
     * recordings (scheduled doses and PRNs) appear in client timelines and
     * the board's activity feed. Best-effort: the administration is already
     * saved, so a timeline failure must never fail the request.
     */
    private function emitMedicationTimelineEvent(
        ClientMedicationAdministration $administration,
        ClientMedication $medication,
        User $user,
        ?int $shiftId,
    ): void {
        try {
            $statusLabel = ucfirst(str_replace('_', ' ', (string) $administration->status));

            app(TimelineEmitter::class)->record([
                'source_type' => ClientMedicationAdministration::class,
                'source_id' => $administration->id,
                'occurred_at' => $administration->administered_at ?? now(),
                'type' => 'medication_' . $administration->status,
                'actor_user_id' => $user->id,
                'client_id' => $medication->client_id,
                'shift_id' => $shiftId,
                'site_id' => $medication->client?->site_id,
                'subject' => $statusLabel . ': ' . $medication->name . ($medication->dosage ? ' ' . $medication->dosage : ''),
                'body' => null,
                'meta' => array_filter([
                    'medication_name' => $medication->name,
                    'dosage' => $medication->dosage,
                    'status' => $administration->status,
                    'reason' => $administration->reason,
                    'witnessed_by' => $administration->witnessed_by,
                    'is_prn' => $medication->is_prn ? true : null,
                ]),
            ]);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * Every administration touching the selected worker-local day, in one
     * query: scheduled-slot rows (matched by `scheduled_for`) plus PRN rows
     * (no slot — matched by `administered_at`).
     *
     * @return Collection<int, ClientMedicationAdministration>
     */
    private function administrationsForDay(array $clientIds, Carbon $date): Collection
    {
        if (empty($clientIds)) {
            return collect();
        }

        try {
            [$dayStartUtc, $dayEndUtc] = $this->scheduleService->utcDayWindow($date);

            return ClientMedicationAdministration::query()
                ->whereIn('client_id', $clientIds)
                ->where(function ($query) use ($dayStartUtc, $dayEndUtc) {
                    $query->whereBetween('scheduled_for', [$dayStartUtc, $dayEndUtc])
                        ->orWhere(function ($query) use ($dayStartUtc, $dayEndUtc) {
                            $query->whereNull('scheduled_for')
                                ->whereBetween('administered_at', [$dayStartUtc, $dayEndUtc]);
                        });
                })
                ->with([
                    'administeredBy:id,name',
                    'witnessedBy:id,name',
                    'medication:id,client_id,name,dosage,route,is_prn,controlled_drug,witness_required',
                    'prnEffectiveness:id,client_medication_administration_id',
                ])
                ->orderBy('id')
                ->get();
        } catch (\Throwable $e) {
            report($e);

            return collect();
        }
    }

    /**
     * The full-day medication board: every scheduled (non-PRN) dose slot for
     * the selected day — recorded or not — for the assigned clients.
     *
     * @param  Collection<string, ClientMedicationAdministration>  $bySlot
     */
    private function scheduleForDate(array $clientIds, Carbon $date, Carbon $now, Collection $bySlot): array
    {
        if (empty($clientIds)) {
            return [];
        }

        try {
            $timezone = $this->scheduleService->workerTimezone();

            $medications = ClientMedication::whereIn('client_id', $clientIds)
                ->active()
                ->where('is_prn', false)
                ->where(function ($query) {
                    $query->whereNotNull('dose_times')
                        ->orWhereNotNull('frequency');
                })
                ->with('client:id,first_name,last_name,site_id')
                ->get();

            $rows = [];

            foreach ($medications as $med) {
                foreach ($this->scheduleService->scheduledTimesForDate($med, $date) as $scheduled) {
                    $administration = $bySlot->get(
                        $this->scheduleService->slotKey((int) $med->client_id, (int) $med->id, $scheduled),
                    );

                    if ($administration && ! in_array($administration->status, self::RECORDED_STATUSES, true)) {
                        $administration = null;
                    }

                    if ($administration) {
                        $status = $administration->status;
                    } elseif ($scheduled->lt($now)) {
                        $status = 'overdue';
                    } elseif ($scheduled->lte($now->copy()->addHour())) {
                        $status = 'due';
                    } else {
                        $status = 'upcoming';
                    }

                    $clientName = $med->client
                        ? trim($med->client->first_name . ' ' . $med->client->last_name)
                        : 'Unknown';

                    $rows[] = [
                        'key' => $med->id . ':' . $scheduled->copy()->utc()->format('YmdHi'),
                        'client_id' => $med->client_id,
                        'client_name' => $clientName,
                        'medication_id' => $med->id,
                        'medication_name' => $med->name,
                        'dose' => $med->dosage,
                        'route' => $med->route,
                        'is_controlled' => (bool) ($med->controlled_drug ?? false),
                        'requires_witness' => (bool) ($med->witness_required ?? false) || (bool) ($med->controlled_drug ?? false),
                        'scheduled_for' => $scheduled->toIso8601String(),
                        'time' => $scheduled->copy()->timezone($timezone)->format('H:i'),
                        'round_label' => $this->roundLabelFor($scheduled->copy()->timezone($timezone)),
                        'status' => $status,
                        'recorded' => $administration ? $this->recordedPayload($administration, $timezone) : null,
                        'mar_url' => EmarUrl::mar($med->client_id, $scheduled->toDateString()),
                    ];
                }
            }

            usort($rows, function ($a, $b) {
                $timeCmp = strcmp($a['scheduled_for'], $b['scheduled_for']);
                if ($timeCmp !== 0) {
                    return $timeCmp;
                }

                return strcmp($a['client_name'], $b['client_name']);
            });

            return $rows;
        } catch (\Throwable $e) {
            report($e);

            return [];
        }
    }

    private function recordedPayload(ClientMedicationAdministration $administration, string $timezone): array
    {
        $administeredAt = $administration->getRawOriginal('administered_at')
            ? $this->rawUtcInstant($administration, 'administered_at')->setTimezone($timezone)
            : null;

        return [
            'id' => $administration->id,
            'status' => $administration->status,
            'administered_at' => $administeredAt?->toIso8601String(),
            'time' => $administeredAt?->format('H:i'),
            'by' => $administration->administeredBy?->name,
            'witness' => $administration->witnessedBy?->name,
            'reason' => $administration->reason,
            'reason_label' => $administration->reason_code
                ? NotGivenReason::tryFrom($administration->reason_code)?->label()
                : null,
            'notes' => $administration->notes,
        ];
    }

    /** Friendly time-of-day bucket shown under the slot time. */
    private function roundLabelFor(Carbon $localTime): string
    {
        $hour = (int) $localTime->format('G');

        return match (true) {
            $hour < 11 => 'Morning',
            $hour < 14 => 'Midday',
            $hour < 17 => 'Afternoon',
            $hour < 21 => 'Evening',
            default => 'Night',
        };
    }

    /** @return array<int, array<string, mixed>> */
    private function clientsPayload(array $clientIds): array
    {
        if (empty($clientIds)) {
            return [];
        }

        try {
            $timezone = $this->scheduleService->workerTimezone();

            return Client::query()
                ->whereIn('id', $clientIds)
                ->with(['site:id,name', 'medicationAllergies' => fn ($q) => $q->whereNull('deleted_at')])
                ->orderBy('first_name')
                ->get()
                ->map(function (Client $client) use ($timezone) {
                    $name = trim($client->first_name . ' ' . $client->last_name);
                    $dob = $client->date_of_birth;

                    return [
                        'id' => $client->id,
                        'name' => $name,
                        'preferred' => $client->preferred_name ?: $client->first_name,
                        'nhi' => $client->nhi_number,
                        'dob' => $dob?->format('j M Y'),
                        'age' => $dob ? (int) $dob->copy()->timezone($timezone)->diffInYears(now($timezone)) : null,
                        'site_id' => $client->site_id,
                        'site_name' => $client->site?->name,
                        'allergies' => $client->medicationAllergies
                            ->map(fn ($a) => trim((string) $a->allergen))
                            ->filter()
                            ->values()
                            ->all(),
                    ];
                })
                ->values()
                ->all();
        } catch (\Throwable $e) {
            report($e);

            return [];
        }
    }

    /** @return array<int, array{id: int, name: string}> */
    private function sitesPayload(array $clientIds): array
    {
        if (empty($clientIds)) {
            return [];
        }

        try {
            return Client::query()
                ->whereIn('id', $clientIds)
                ->whereNotNull('site_id')
                ->with('site:id,name')
                ->get()
                ->pluck('site')
                ->filter()
                ->unique('id')
                ->sortBy('name')
                ->map(fn ($site) => ['id' => $site->id, 'name' => $site->name])
                ->values()
                ->all();
        } catch (\Throwable $e) {
            report($e);

            return [];
        }
    }

    /**
     * PRN (as-needed) medications available for quick recording. Scoped to
     * the same assigned-client set the rest of the page uses, so a worker
     * only ever sees PRN meds for people they're actively supporting.
     */
    private function prnMedications(array $clientIds, Carbon $now): array
    {
        if (empty($clientIds)) {
            return [];
        }

        try {
            $timezone = $this->scheduleService->workerTimezone();

            $medications = ClientMedication::whereIn('client_id', $clientIds)
                ->active()
                ->prn()
                ->with('client:id,first_name,last_name')
                ->orderBy('client_id')
                ->orderBy('name')
                ->get();

            // One grouped query for "last given" across every PRN med (the
            // 24h pressure counts go through the model accessors as before).
            $lastGivenByMed = $medications->isEmpty()
                ? collect()
                : ClientMedicationAdministration::query()
                    ->whereIn('client_medication_id', $medications->pluck('id'))
                    ->where('status', 'given')
                    ->selectRaw('client_medication_id, MAX(administered_at) as last_given_at')
                    ->groupBy('client_medication_id')
                    ->pluck('last_given_at', 'client_medication_id');

            $result = [];

            foreach ($medications as $med) {
                if (! $med->client) {
                    continue;
                }

                $maxPerDay = $med->max_per_day ? (int) $med->max_per_day : null;
                $givenLast24h = $med->prnCountLast24Hours;
                $remaining = $maxPerDay !== null ? max(0, $maxPerDay - $givenLast24h) : null;

                $lastGivenRaw = $lastGivenByMed->get($med->id);
                $lastGiven = $lastGivenRaw ? Carbon::parse((string) $lastGivenRaw, 'UTC')->setTimezone($timezone) : null;
                $minHours = $med->min_hours_between_doses ? (float) $med->min_hours_between_doses : null;
                $nextAllowed = ($lastGiven && $minHours)
                    ? $lastGiven->copy()->addMinutes((int) round($minHours * 60))
                    : null;

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
                    'min_hours_between' => $minHours,
                    'last_given_at' => $lastGiven?->toIso8601String(),
                    'last_given_label' => $lastGiven ? $this->friendlyTimeLabel($lastGiven, $now) : null,
                    'next_allowed_at' => $nextAllowed?->toIso8601String(),
                    'interval_blocked' => $nextAllowed !== null && $nextAllowed->isAfter($now),
                    'next_allowed_label' => $nextAllowed?->format('g:i a'),
                ];
            }

            return $result;
        } catch (\Throwable $e) {
            report($e);

            return [];
        }
    }

    private function friendlyTimeLabel(Carbon $instant, Carbon $now): string
    {
        if ($instant->isSameDay($now)) {
            return 'Today ' . $instant->format('g:i a');
        }

        if ($instant->isSameDay($now->copy()->subDay())) {
            return 'Yesterday ' . $instant->format('g:i a');
        }

        return $instant->format('j M, g:i a');
    }

    /**
     * PRN doses given on the selected day that have no effectiveness check
     * yet — the follow-up queue on the board. Derived from the day's
     * administration collection (no extra query).
     *
     * @param  Collection<int, ClientMedicationAdministration>  $dayAdministrations
     */
    private function prnFollowUps(Collection $dayAdministrations, string $timezone): array
    {
        try {
            return $dayAdministrations
                ->filter(fn (ClientMedicationAdministration $a) => $a->getRawOriginal('scheduled_for') === null
                    && $a->status === 'given'
                    && ($a->medication?->is_prn ?? false)
                    && ! $a->prnEffectiveness)
                ->map(function (ClientMedicationAdministration $a) use ($timezone) {
                    $givenAt = $a->getRawOriginal('administered_at')
                        ? $this->rawUtcInstant($a, 'administered_at')->setTimezone($timezone)
                        : null;

                    return [
                        'administration_id' => $a->id,
                        'client_id' => $a->client_id,
                        'medication_name' => $a->medication?->name,
                        'dose_given' => $a->dose_given,
                        'given_at' => $givenAt?->toIso8601String(),
                        'given_time' => $givenAt?->format('g:i a'),
                        'check_at' => $givenAt?->copy()->addHour()->format('g:i a'),
                    ];
                })
                ->sortBy('given_at')
                ->values()
                ->all();
        } catch (\Throwable $e) {
            report($e);

            return [];
        }
    }

    /**
     * Stock pressure for the assigned clients' active medications: low stock,
     * expiring within 30 days, or already expired. Always anchored to today —
     * stock is a now problem regardless of which day the board shows.
     */
    private function stockAlerts(array $clientIds): array
    {
        if (empty($clientIds)) {
            return [];
        }

        try {
            $today = Carbon::today($this->scheduleService->workerTimezone());

            return ClientMedicationStock::query()
                ->whereHas('medication', fn ($q) => $q->whereIn('client_id', $clientIds)->active())
                ->where(function ($query) {
                    $query->where(function ($q) {
                        $q->whereNotNull('reorder_level')->whereColumn('on_hand', '<=', 'reorder_level');
                    })->orWhere(function ($q) {
                        $q->whereNotNull('expiry_date')->where('expiry_date', '<=', Carbon::today()->addDays(30));
                    });
                })
                ->with('medication.client:id,first_name,last_name')
                ->orderBy('expiry_date')
                ->limit(12)
                ->get()
                ->map(function (ClientMedicationStock $stock) use ($today) {
                    $med = $stock->medication;
                    $clientName = $med?->client
                        ? trim($med->client->first_name . ' ' . $med->client->last_name)
                        : null;

                    $expired = $stock->expiry_date && $stock->expiry_date->lte($today);
                    $expiringSoon = ! $expired && $stock->expiry_date && $stock->expiry_date->lte($today->copy()->addDays(30));
                    $low = $stock->reorder_level !== null && $stock->on_hand <= $stock->reorder_level;

                    if ($expired) {
                        $type = 'expired';
                        $tone = 'crit';
                        $detail = 'Expired ' . $stock->expiry_date->format('j M Y');
                    } elseif ($low) {
                        $type = 'stock_low';
                        $tone = $stock->on_hand <= 0 ? 'crit' : 'warn';
                        $detail = $stock->on_hand . ' ' . ($stock->unit ?: 'units') . ' left · reorder at ' . $stock->reorder_level;
                    } else {
                        $type = 'expiring_soon';
                        $tone = 'warn';
                        $detail = 'Expires ' . $stock->expiry_date->format('j M Y');
                    }

                    return [
                        'id' => $stock->id,
                        'type' => $type,
                        'tone' => $tone,
                        'label' => trim(($med?->name ?? 'Medication') . ($med?->dosage ? ' ' . $med->dosage : '')) . ($clientName ? ' — ' . $clientName : ''),
                        'detail' => $detail . ($expiringSoon && $low ? ' · low stock' : ''),
                        'is_controlled' => (bool) ($med?->controlled_drug ?? false),
                    ];
                })
                ->values()
                ->all();
        } catch (\Throwable $e) {
            report($e);

            return [];
        }
    }

    /**
     * Today's medication activity feed for the assigned clients, sourced from
     * the timeline events EnhancedMarService writes on every administration.
     *
     * @param  Collection<int, ClientMedicationAdministration>  $dayAdministrations
     */
    private function activityForDate(array $clientIds, Carbon $date, Collection $dayAdministrations): array
    {
        if (empty($clientIds)) {
            return [];
        }

        try {
            $timezone = $this->scheduleService->workerTimezone();
            [$dayStartUtc, $dayEndUtc] = $this->scheduleService->utcDayWindow($date);
            $administrationsById = $dayAdministrations->keyBy('id');

            return TimelineEvent::query()
                ->whereIn('client_id', $clientIds)
                ->where('type', 'like', 'medication%')
                ->whereBetween('occurred_at', [$dayStartUtc, $dayEndUtc])
                ->with(['actor:id,name', 'client:id,first_name,last_name'])
                ->orderByDesc('occurred_at')
                ->limit(50)
                ->get()
                ->map(function (TimelineEvent $event) use ($administrationsById, $timezone) {
                    $source = $event->source_type === ClientMedicationAdministration::class
                        ? $administrationsById->get($event->source_id)
                        : null;

                    $icon = match (true) {
                        in_array($event->type, ['medication_refused', 'medication_withheld', 'medication_missed'], true) => 'refused',
                        (bool) ($source?->medication?->controlled_drug) => 'cd',
                        (bool) ($source?->medication?->is_prn) => 'prn',
                        default => 'check',
                    };

                    $clientName = $event->client
                        ? trim($event->client->first_name . ' ' . $event->client->last_name)
                        : null;

                    $witness = $source?->witnessedBy?->name;

                    return [
                        'id' => $event->id,
                        'occurred_at' => $event->occurred_at?->toIso8601String(),
                        'time' => $event->occurred_at?->copy()->setTimezone($timezone)->format('H:i'),
                        'icon' => $icon,
                        'text' => trim(($event->subject ?: Str::headline($event->type)) . ($clientName ? ' — ' . $clientName : '')),
                        'by' => trim(($event->actor?->name ?? 'System') . ($witness ? ' · wit. ' . $witness : '')),
                    ];
                })
                ->values()
                ->all();
        } catch (\Throwable $e) {
            report($e);

            return [];
        }
    }

    /**
     * Staff authorised to witness controlled-drug administrations. The
     * recording worker can't witness their own administration, so they're
     * excluded from their own list.
     *
     * @return array<int, array{id: int, name: string}>
     */
    private function witnesses(User $user): array
    {
        try {
            return User::staff()
                ->orderBy('name')
                ->get(['id', 'name'])
                ->filter(fn (User $candidate) => $candidate->id !== $user->id
                    && $candidate->canDo('medications.controlled.witness'))
                ->map(fn (User $candidate) => ['id' => $candidate->id, 'name' => $candidate->name])
                ->values()
                ->all();
        } catch (\Throwable $e) {
            report($e);

            return [];
        }
    }

    /** "7:00 am – 3:30 pm" for the worker's shift(s) on the selected day. */
    private function shiftLabel(User $user, Carbon $date, string $timezone): ?string
    {
        try {
            [$dayStartUtc, $dayEndUtc] = $this->scheduleService->utcDayWindow($date);

            $shifts = Shift::query()
                ->where('user_id', $user->id)
                ->whereBetween('starts_at', [$dayStartUtc, $dayEndUtc])
                ->orderBy('starts_at')
                ->get(['id', 'starts_at', 'ends_at']);

            if ($shifts->isEmpty()) {
                return null;
            }

            $start = $shifts->first()->starts_at?->copy()->setTimezone($timezone);
            $end = $shifts->max('ends_at')?->copy()->setTimezone($timezone);

            if (! $start) {
                return null;
            }

            return $start->format('g:i a') . ($end ? ' – ' . $end->format('g:i a') : '');
        } catch (\Throwable $e) {
            report($e);

            return null;
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
     * Every round on the selected day the worker can see — their own,
     * unassigned ones they may pick up, and anything they already walked —
     * so the board can show completed rounds alongside what's next.
     */
    private function roundsForDate(User $user, Carbon $date): array
    {
        try {
            $rounds = MedicationRound::query()
                ->whereDate('round_date', $date->toDateString())
                ->where(function ($q) use ($user) {
                    $q->where('assigned_to', $user->id)
                        ->orWhere('started_by', $user->id)
                        ->orWhereNull('assigned_to');
                })
                ->orderBy('scheduled_time')
                ->limit(12)
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

    /**
     * Read a datetime column back as a true UTC Carbon from the raw value —
     * avoiding the Eloquent accessor, which can re-introduce the worker-tz
     * offset (see the house timezone convention in MarScheduleService).
     */
    private function rawUtcInstant(ClientMedicationAdministration $administration, string $column): Carbon
    {
        $raw = $administration->getRawOriginal($column);

        return $raw
            ? Carbon::parse((string) $raw, 'UTC')
            : Carbon::createFromTimestamp(0, 'UTC');
    }
}
