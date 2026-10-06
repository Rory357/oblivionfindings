<?php

namespace App\Http\Controllers\Emar;

use App\Http\Controllers\Concerns\HandlesMedicationSync;
use App\Http\Controllers\Concerns\HandlesOfflineSubmission;
use App\Http\Controllers\Controller;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Client;
use App\Models\ClientMedication;
use App\Models\ClientMedicationAdministration;
use App\Models\ClientMedicationStock;
use App\Models\MedicationFollowup;
use App\Models\MedicationRefusalFollowup;
use App\Models\MedicationRound;
use App\Models\Shift;
use App\Models\TimelineEvent;
use App\Models\User;
use App\Services\Emar\MedsBoardPayloadService;
use App\Services\EnhancedMarService;
use App\Services\GuidedRoundService;
use App\Services\MarScheduleService;
use App\Services\Medication\Alerts\OnCallResolver;
use App\Services\Medication\Audit\MedicationEventData;
use App\Services\Medication\Audit\MedicationEventRecorder;
use App\Services\Medication\DoseSlots\DoseSlotReaderScope;
use App\Services\Medication\Followups\LegacyEffectFollowupAdapter;
use App\Services\Medication\ForgottenWitnessPinService;
use App\Services\Medication\MarLinkService;
use App\Services\Medication\MedicationScopeDecision;
use App\Services\Medication\MedicationScopeDecisionService;
use App\Services\Medication\Recording\DoseRecordingRequirements;
use App\Services\Medication\Recording\PrnEffectCheckQueue;
use App\Services\Medication\Recording\RecordingContract;
use App\Services\Medication\Reporting\MedicationReportAccess;
use App\Services\Medication\SecondPersonConfirmationPayload;
use App\Services\Medication\StaffEligibilityRegister;
use App\Services\MedicationScanVerificationService;
use App\Services\Timeline\TimelineEmitter;
use App\Services\UserSiteAccessService;
use App\Support\Medication\MedicationStockQuantity;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
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
 * Reads are gated by a medication read/record capability. Every administration
 * write is separately gated by `medications.administer.record`.
 */
class WorkerMedsController extends Controller
{
    use HandlesMedicationSync;
    use HandlesOfflineSubmission;

    public function __construct(
        protected GuidedRoundService $guidedRoundService,
        protected EnhancedMarService $marService,
        protected MarScheduleService $scheduleService,
        protected MedsBoardPayloadService $boardPayload,
        protected MedicationScopeDecisionService $medicationScope,
        protected UserSiteAccessService $siteAccess,
    ) {}

    public function today(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user, 403);
        abort_unless(
            $user->canDo('medications.view') || $user->canDo('medications.administer.record'),
            403,
        );
        // EM-12: only controlled view reveals controlled rows. Record authority
        // alone gates the write path, never what the board shows.
        $includeControlled = $user->canDo('medications.controlled.view');

        $timezone = $this->scheduleService->workerTimezone();
        $now = Carbon::now($timezone);
        $date = $this->scheduleService->dateFromInput($request->query('date'));
        $isToday = $date->isSameDay($now);

        [$assignedClientIds, $peopleAfterClockIn, $hasShift] = $this->boardPeopleFor(
            $user,
            $date->copy()->utc(),
            $date->copy()->addDay()->endOfDay()->utc(),
        );

        // Keep the complete authorized board for selector options and for the
        // off-shift exclusion: choosing one person must not move their fellow
        // on-shift people into the off-shift list.
        $boardClientIds = array_values(array_map('intval', $assignedClientIds));
        $offShiftClientIds = $isToday ? $this->offShiftPeople($user, $boardClientIds) : [];
        $personOptionIds = array_values(array_unique(array_merge($boardClientIds, $offShiftClientIds)));
        $selectedClientId = $this->selectedClientId($request, $personOptionIds);
        if ($selectedClientId !== null) {
            $assignedClientIds = in_array($selectedClientId, $boardClientIds, true) ? [$selectedClientId] : [];
            $offShiftClientIds = in_array($selectedClientId, $offShiftClientIds, true) ? [$selectedClientId] : [];
            $peopleAfterClockIn = 0;
        }
        $personOptions = $this->boardPayload->clientsPayload($personOptionIds);
        $clients = array_values(array_filter(
            $personOptions,
            fn (array $person): bool => in_array((int) $person['id'], $assignedClientIds, true),
        ));

        // One detailed administrations query for the whole selected day. It is
        // reused for (a) matching scheduled dose slots, and (b) deriving the
        // PRN follow-up queue — keeping the today() path at a single
        // client_medication_administrations query regardless of slot count.
        $dayAdministrations = $this->boardPayload->administrationsForDay($assignedClientIds, $date, $includeControlled);
        $bySlot = $this->boardPayload->slotIndex($dayAdministrations);

        // EM-12: controlled doses are left off the list for a reader without
        // controlled-medicine access; the list says how many (and how many of
        // those are overdue), naming none, so it reconciles with the badge
        // (which counts them, P09 Q6).
        $schedule = $this->boardPayload->scheduleForDate($assignedClientIds, $date, $now, $bySlot, $includeControlled, $hiddenControlled);
        // The MAR link: only charts this worker may open (a lead's fallback
        // board can include people outside the frontline person rule).
        $marLinks = app(MarLinkService::class);
        $schedule = array_map(fn (array $row): array => [
            ...$row,
            'mar_url' => $marLinks->canOpen($user, (int) $row['client_id']) ? $row['mar_url'] : null,
        ], $schedule);
        // P01 C3: what each open dose needs and allows for this worker, from
        // the one requirements source the dialog reads (block, competency,
        // second person, allergy match) — the rows' state lines and the
        // "Needs help" meter say the same as the dialog.
        $schedule = $this->withRequirements($user, $schedule);

        // Legacy due lists (kept for the established payload contract): the
        // operational "what needs me" window of -2h … +8h around now.
        $windowStart = $now->copy()->subHours(2);
        $windowEnd = $now->copy()->addHours(8);
        $medsDue = array_values(array_filter($schedule, function (array $row) use ($windowStart, $windowEnd) {
            // Recorded (Missed (recorded) included), or waiting for the order
            // check (can't be recorded yet).
            if ($row['recorded'] !== null || ! in_array($row['status'], ['overdue', 'due', 'upcoming'], true)) {
                return false;
            }
            $scheduled = Carbon::parse($row['scheduled_for']);

            return $scheduled->gte($windowStart) && $scheduled->lte($windowEnd);
        }));

        $dueNow = array_values(array_filter($medsDue, fn ($m) => in_array($m['status'], ['overdue', 'due'], true)));
        $dueLater = array_values(array_filter($medsDue, fn ($m) => $m['status'] === 'upcoming'));
        $overdue = array_values(array_filter($medsDue, fn ($m) => $m['status'] === 'overdue'));

        $roundContext = ['client_id' => $selectedClientId];
        $siteContext = $request->query('site_id', $request->query('site'));
        $siteId = is_string($siteContext) || is_int($siteContext)
            ? filter_var($siteContext, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : false;
        if ($siteId !== false) {
            $roundContext['site_id'] = $siteId;
        }
        $activeRound = $isToday ? $this->activeRound($user, $now, $assignedClientIds, $roundContext) : null;
        $rounds = $this->roundsForDate($user, $date, $assignedClientIds, $roundContext);
        $upcomingRounds = array_values(array_filter(
            $rounds,
            fn ($r) => in_array($r['status'], ['pending', 'in_progress'], true),
        ));

        $prnMedications = $this->boardPayload->prnMedications($assignedClientIds, $now, $includeControlled);

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
            'guidedRound' => $request->filled('round')
                ? $this->guidedRound($user, $request->integer('round'), $assignedClientIds, $includeControlled, $boardClientIds)
                : null,
            'due_now' => $dueNow,
            'due_later' => $dueLater,
            'schedule' => $schedule,
            'clients' => $clients,
            'person_options' => $personOptions,
            'selected_client_id' => $selectedClientId,
            'sites' => $this->boardPayload->sitesPayload($assignedClientIds),
            'prn_medications' => $prnMedications,
            'prn_follow_ups' => app(PrnEffectCheckQueue::class)->payload(
                DoseSlotReaderScope::forViewerClients($user, $assignedClientIds), $now, $timezone,
            ),
            'stock_alerts' => $this->stockAlerts($assignedClientIds, $includeControlled),
            'activity' => $this->activityForDate($assignedClientIds, $date, $dayAdministrations),
            'witnesses' => $this->boardPayload->witnesses($user, $assignedClientIds),
            'not_given_reasons' => $this->boardPayload->notGivenReasons(),
            'shift_label' => $this->shiftLabel($user, $date, $timezone),
            'board_user' => $this->boardPayload->boardUser($user),
            // P11: the "My eligibility" meter — their competency, and a new
            // assessment waiting for their acknowledgement.
            'my_eligibility' => app(StaffEligibilityRegister::class)->myEligibility($user),
            'board_can' => [
                'export_round' => app(MedicationReportAccess::class)->canExport($user, 'doses'),
                'view_emar' => $user->canDo('medications.view'),
                'view_audit' => $user->canDo('medications.audit.view'),
                'record_administration' => $user->canDo('medications.administer.record'),
                'record_controlled' => $user->canDo('medications.controlled.record'),
                'view_controlled' => $user->canDo('medications.view')
                    && $user->canDo('medications.controlled.view'),
                'manage_stock' => $user->canDo('medications.view')
                    && $user->canDo('medications.stock.update'),
            ],
            'has_shift_context' => $hasShift || ! empty($assignedClientIds),
            // People on their shift whose medicines show once they're clocked
            // in to it (the person rule), and controlled doses not shown.
            'people_after_clock_in' => $peopleAfterClockIn,
            'hidden_controlled_doses' => $hiddenControlled['total'],
            'hidden_controlled_overdue' => $hiddenControlled['overdue'],
            'concealed_schedule' => $hiddenControlled,
            // P01 C3 (approved Meds today): the header's state chip and
            // subline, the not-clocked-in banner's on-call contact, the
            // people you may open who aren't on your shift, your refusal
            // follow-ups, and the paginated activity list.
            'clocked_in' => $this->isClockedIn($user, $now),
            'house_label' => $this->houseLabel($assignedClientIds),
            'on_call' => $this->onCallFor($assignedClientIds, $now),
            'off_shift' => $isToday
                ? $this->offShiftRows($user, $offShiftClientIds, $date, $now, $includeControlled)
                : [],
            'refusal_follow_ups' => $this->refusalFollowUps($assignedClientIds, $includeControlled, $now, $timezone),
            'second_person_confirmations' => $this->secondPersonConfirmations($user, $now),
            'prn_recorded_today' => $this->prnRecordedToday($dayAdministrations, $timezone),
            // Activity is loaded when its tab is open (or asked for by name on
            // a partial reload), so the default board load stays one fixed
            // administrations query.
            'activity_page' => $request->query('view') === 'activity'
                ? $this->activityPageFor($request, $assignedClientIds, $now, $includeControlled)
                : Inertia::optional(fn () => $this->activityPageFor($request, $assignedClientIds, $now, $includeControlled)),
            'board_extra_can' => [
                'report_error' => $user->canDo('medications.administer.record'),
                'view_handovers' => $user->canDo('medications.view'),
            ],
            // Whose medication record (MAR) the row menus may offer to open.
            'mar_client_ids' => $user->canDo('medications.view')
                ? $marLinks->openableClientIds($user, $assignedClientIds)
                : [],
        ]);
    }

    /**
     * Each open row's recording requirements for this worker (keys only:
     * block_all / block_given, competency, second person, allergy match,
     * window). Done or not-yet-due rows carry none.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function withRequirements(User $user, array $rows): array
    {
        $open = array_values(array_filter(
            $rows,
            fn (array $row): bool => $row['recorded'] === null
                && in_array($row['status'], ['due', 'overdue', 'pending_check'], true),
        ));
        $board = [];
        if ($open !== []) {
            try {
                $orders = ClientMedication::query()
                    ->with('client')
                    ->whereIn('id', array_values(array_unique(array_map(fn (array $row): int => (int) $row['medication_id'], $open))))
                    ->get()
                    ->keyBy('id');
                $doses = [];
                foreach ($open as $row) {
                    $order = $orders->get((int) $row['medication_id']);
                    if ($order !== null) {
                        $doses[] = ['order' => $order, 'due_at' => Carbon::parse($row['scheduled_for'])];
                    }
                }
                $board = app(DoseRecordingRequirements::class)->forBoard($user, $doses);
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return array_map(fn (array $row): array => [
            ...$row,
            'req' => $board[DoseRecordingRequirements::boardKey((int) $row['medication_id'], Carbon::parse($row['scheduled_for']))] ?? null,
        ], $rows);
    }

    /**
     * Meds today › Activity: the board's recorded doses, 10 a page, with the
     * range / outcome / search the tab's filters put in the query string.
     *
     * @param  array<int, int>  $clientIds
     */
    private function activityPageFor(Request $request, array $clientIds, Carbon $now, bool $includeControlled): LengthAwarePaginator
    {
        $search = $request->query('q');

        return $this->boardPayload->activityPage(
            $clientIds,
            $now,
            $includeControlled,
            $request->query('range') === 'today' ? 'today' : '24h',
            in_array($request->query('outcome'), ['given', 'notgiven'], true) ? (string) $request->query('outcome') : 'all',
            is_string($search) && trim($search) !== '' ? mb_substr(trim($search), 0, 80) : null,
        );
    }

    /** Clocked in now: a shift of theirs that has started and not ended. */
    private function isClockedIn(User $user, Carbon $now): bool
    {
        return Shift::query()
            ->where('user_id', $user->id)
            ->whereNotNull('actual_starts_at')
            ->where('actual_starts_at', '<=', $now->copy()->utc())
            ->where(fn ($live) => $live->whereNull('actual_ends_at')->orWhere('actual_ends_at', '>=', $now->copy()->utc()))
            ->exists();
    }

    /** "Kōwhai House", or "2 houses" — the header subline's place. */
    private function houseLabel(array $clientIds): ?string
    {
        if ($clientIds === []) {
            return null;
        }
        $names = Client::query()->whereIn('id', $clientIds)->with('site:id,name')->get(['id', 'site_id'])
            ->map(fn (Client $client): ?string => $client->site?->name)
            ->filter()
            ->unique()
            ->values();

        return match ($names->count()) {
            0 => null,
            1 => $names->first(),
            default => $names->count().' houses',
        };
    }

    /**
     * The on-call contact at the board's house now (Settings › On-call), for
     * "Can't clock in? Contact the coordinator on call".
     *
     * @return array{configured: bool, name: ?string, phone: ?string, warning: ?string}|null
     */
    private function onCallFor(array $clientIds, Carbon $now): ?array
    {
        $siteId = $clientIds === [] ? null : Client::query()->whereIn('id', $clientIds)->value('site_id');
        if (! $siteId) {
            return null;
        }
        $resolver = app(OnCallResolver::class);
        $resolved = $resolver->at((int) $siteId, $now);

        return [
            'configured' => $resolved['configured'],
            'name' => $resolved['user']?->name,
            'phone' => $resolver->phoneOf($resolved['user']),
            'warning' => $resolved['warning'],
        ];
    }

    /** @param list<int> $personOptionIds */
    private function selectedClientId(Request $request, array $personOptionIds): ?int
    {
        foreach (['client_id', 'client', 'pp'] as $key) {
            if (! $request->query->has($key)) {
                continue;
            }
            $value = $request->query($key);
            if ($value === null || (is_string($value) && trim($value) === '')) {
                return null;
            }
            $id = is_string($value) || is_int($value)
                ? filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
                : false;
            abort_unless($id !== false && in_array($id, $personOptionIds, true), 404);

            return $id;
        }

        return null;
    }

    /**
     * People this worker may open (assigned to them, at a house in their
     * access) who aren't on their shift today: their due or late doses are
     * listed so nothing is missed, each saying why it can't be recorded
     * (P01: "At Kōwhai House, not on your shift").
     *
     * @param  array<int, int>  $boardClientIds
     * @return list<int>
     */
    private function offShiftPeople(User $user, array $boardClientIds): array
    {
        try {
            $siteIds = $this->siteAccess->accessibleSiteIds($user, ['clinical.accessAllSites', 'sites.viewAll']);
            if ($siteIds === []) {
                return [];
            }
            $candidates = Client::query()
                ->whereIn('site_id', $siteIds)
                ->whereNotIn('id', $boardClientIds === [] ? [0] : $boardClientIds)
                ->whereHas('supportWorkers', fn ($workers) => $workers->whereKey($user->id))
                ->pluck('id')
                ->map(fn ($id): int => (int) $id)
                ->all();

            return $candidates === [] ? [] : app(MarLinkService::class)->openableClientIds($user, $candidates);
        } catch (\Throwable $e) {
            report($e);

            return [];
        }
    }

    /** @param list<int> $people */
    private function offShiftRows(User $user, array $people, Carbon $date, Carbon $now, bool $includeControlled): array
    {
        if ($people === []) {
            return [];
        }
        try {
            $bySlot = $this->boardPayload->slotIndex($this->boardPayload->administrationsForDay($people, $date, $includeControlled));
            $rows = array_values(array_filter(
                $this->boardPayload->scheduleForDate($people, $date, $now, $bySlot, $includeControlled),
                fn (array $row): bool => $row['recorded'] === null && in_array($row['status'], ['due', 'overdue'], true),
            ));

            return $this->withRequirements($user, $rows);
        } catch (\Throwable $e) {
            report($e);

            return [];
        }
    }

    /** Current named confirmation duties, independent of the board's selected date/person. */
    private function secondPersonConfirmations(User $user, Carbon $now): array
    {
        $rows = app(ForgottenWitnessPinService::class)->pendingFor($user);
        if ($rows->isEmpty()) {
            return [];
        }
        $followups = MedicationFollowup::query()->where('type', 'confirm')
            ->whereIn('source_key', $rows->map(fn ($row): string => 'confirm:'.$row->id))
            ->get(['id', 'source_key', 'client_id', 'client_medication_id', 'administration_id', 'owner_id'])
            ->keyBy('source_key');

        return $rows->flatMap(function ($row) use ($followups, $now): array {
            $dose = $row->administration;
            $followup = $followups->get('confirm:'.$row->id);
            if ($followup === null || (int) $followup->owner_id !== (int) $row->nominated_user_id
                || (int) $followup->administration_id !== (int) $dose->id
                || (int) $followup->client_id !== (int) $dose->client_id
                || (int) $followup->client_medication_id !== (int) $dose->client_medication_id) {
                return [];
            }

            return [[
                'id' => (int) $row->id,
                'client_id' => (int) $dose->client_id,
                'client_name' => $dose->client->full_name,
                'medication_name' => $dose->medication->name,
                'due_at' => $row->due_at->toIso8601String(),
                'status' => $row->due_at->lte($now) ? 'expired' : 'pending',
                'followup_url' => '/medication-followups?open='.$followup->id,
            ]];
        })->values()->all();
    }

    /**
     * As-needed doses recorded this day for the board's people, newest first
     * (Meds today › As-needed: "As-needed doses recorded today").
     *
     * @return list<array<string, mixed>>
     */
    private function prnRecordedToday(Collection $dayAdministrations, string $timezone): array
    {
        return $dayAdministrations
            ->filter(fn (ClientMedicationAdministration $a): bool => $a->getRawOriginal('scheduled_for') === null
                && ($a->medication?->is_prn ?? false))
            ->sortByDesc(fn (ClientMedicationAdministration $a): string => (string) $a->getRawOriginal('administered_at'))
            ->map(function (ClientMedicationAdministration $a) use ($timezone): array {
                $at = $a->getRawOriginal('administered_at')
                    ? $this->boardPayload->rawUtcInstant($a, 'administered_at')->setTimezone($timezone)
                    : null;
                $check = $a->getRawOriginal('effect_check_due_at')
                    ? $this->boardPayload->rawUtcInstant($a, 'effect_check_due_at')->setTimezone($timezone)
                    : null;

                return [
                    'id' => $a->id,
                    'client_id' => (int) $a->client_id,
                    'medication_name' => $a->medication?->name,
                    'status' => $a->status,
                    'time' => $at?->format('g:i a'),
                    'dose_given' => $a->dose_given,
                    'reason' => $a->reason,
                    'by' => $a->administeredBy?->name,
                    'check_at' => $check?->format('g:i a'),
                    'effect_recorded' => (bool) $a->prnEffectiveness,
                    ...app(SecondPersonConfirmationPayload::class)->forAdministration($a),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Open refusal follow-ups for the board's people (P01: a refused dose
     * opens one, owned by whoever recorded it; a re-offer closes it).
     *
     * @param  array<int, int>  $clientIds
     * @return list<array<string, mixed>>
     */
    private function refusalFollowUps(array $clientIds, bool $includeControlled, Carbon $now, string $timezone): array
    {
        if ($clientIds === []) {
            return [];
        }
        try {
            // EM-12: a reader without controlled access never sees a
            // controlled refusal (filtered after the eager load, so the board
            // keeps its one fixed administrations query when none are open).
            return MedicationRefusalFollowup::query()
                ->whereIn('client_id', $clientIds)
                ->whereNull('follow_up_completed_at')
                ->whereHas('administration', function (Builder $administrations) use ($includeControlled): void {
                    $administrations->effectiveClinicalEvidence()
                        ->where('status', 'refused')
                        ->whereColumn('client_medication_administrations.client_id', 'medication_refusal_followups.client_id')
                        ->whereHas('medication', function (Builder $medications) use ($includeControlled): void {
                            $medications->whereColumn('client_medications.client_id', 'client_medication_administrations.client_id');
                            if (! $includeControlled) {
                                $medications->where('controlled_drug', false);
                            }
                        });
                })
                ->with([
                    'administration:id,client_medication_id,scheduled_for,administered_at,status',
                    'administration.medication:id,name,controlled_drug',
                    'client:id,first_name,last_name,preferred_name',
                    'owner:id,name',
                ])
                ->orderBy('follow_up_due_at')
                ->get()
                ->filter(fn (MedicationRefusalFollowup $followUp): bool => $followUp->administration?->medication !== null
                    && ($includeControlled || ! $followUp->administration->medication->controlled_drug))
                ->map(function (MedicationRefusalFollowup $followUp) use ($now, $timezone): array {
                    $due = $followUp->getRawOriginal('follow_up_due_at')
                        ? Carbon::parse((string) $followUp->getRawOriginal('follow_up_due_at'), 'UTC')->setTimezone($timezone)
                        : null;
                    $refusedAt = $followUp->administration?->getRawOriginal('administered_at')
                        ? Carbon::parse((string) $followUp->administration->getRawOriginal('administered_at'), 'UTC')->setTimezone($timezone)
                        : null;
                    $scheduled = $followUp->administration?->getRawOriginal('scheduled_for')
                        ? Carbon::parse((string) $followUp->administration->getRawOriginal('scheduled_for'), 'UTC')
                        : null;

                    return [
                        'id' => $followUp->id,
                        'refusal_id' => (int) $followUp->client_medication_administration_id,
                        'client_id' => (int) $followUp->client_id,
                        'preferred' => $followUp->client ? ($followUp->client->preferred_name ?: $followUp->client->first_name) : 'Unknown',
                        'medication_id' => (int) ($followUp->administration?->client_medication_id ?? 0),
                        'medication_name' => $followUp->administration?->medication?->name,
                        'is_controlled' => (bool) ($followUp->administration?->medication?->controlled_drug ?? false),
                        'scheduled_for' => $scheduled?->toIso8601String(),
                        'refused_time' => $refusedAt?->format('g:i a'),
                        'due_at' => $due?->toIso8601String(),
                        'due_time' => $due?->format('g:i a'),
                        'overdue' => $due !== null && $due->lt($now),
                        'owner' => $followUp->owner?->name,
                        'escalated' => (bool) $followUp->escalated_to_manager,
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
     * Record the outcome of a scheduled dose (given / refused / withheld) from
     * the desktop Record Dose wizard.
     *
     * Delegates to the same EnhancedMarService the round and admin paths use,
     * so the five safety layers (verification state, not-given reasons,
     * required observations, witness + credential, time window) all run
     * exactly as they do everywhere else. No second administration path.
     */
    public function recordDose(Request $request): RedirectResponse|JsonResponse
    {
        $user = $request->user();
        abort_unless($user, 403);

        abort_unless(
            $user->canDo('medications.administer.record'),
            403,
            'You do not have permission to record medication administrations.'
        );

        $data = $request->validate([
            'client_medication_id' => ['required', 'integer'],
            'medication_round_id' => ['nullable', 'integer', 'min:1'],
            'scheduled_for' => ['required', 'date'],
            'status' => ['required', 'in:given,refused,withheld'],
            'reason_code' => ['nullable', 'string', 'max:60', 'required_unless:status,given'],
            'reason' => ['nullable', 'string', 'max:500'],
            'administered_at' => ['nullable', 'date'],
            'witnessed_by' => ['nullable', 'integer', 'min:1'],
            'witness_credential' => ['nullable', 'string', 'max:255'],
            'quantity_administered' => ['nullable', 'numeric', MedicationStockQuantity::VALIDATION_RULE, 'min:0.01', 'max:10000'],
            'cd_balance' => ['nullable', 'numeric', MedicationStockQuantity::VALIDATION_RULE, 'min:0', 'max:100000'],
            'blood_glucose_level' => ['nullable', 'numeric', 'min:0', 'max:999.9'],
            'pulse_bpm' => ['nullable', 'integer', 'min:20', 'max:250'],
            'blood_pressure_systolic' => ['nullable', 'integer', 'min:40', 'max:300'],
            'blood_pressure_diastolic' => ['nullable', 'integer', 'min:20', 'max:200'],
            'notes' => ['nullable', 'string', 'max:2000'],
            ...RecordingContract::rules(),
            ...$this->recordingContextRules(),
            ...$this->medicationOfflineSubmissionRules($request),
        ]);

        $medication = ClientMedication::with('client')->findOrFail($data['client_medication_id']);
        $round = isset($data['medication_round_id']) ? MedicationRound::findOrFail($data['medication_round_id']) : null;
        abort_unless($medication->client, 404);
        $scheduledFor = $this->scheduleService->parseWorkerDateTime((string) $data['scheduled_for']);
        $submittedAdministrationAt = $this->medicationSubmittedAdministrationAt($data);
        $actionAt = $this->scheduleService->parseWorkerDateTime((string) (
            $submittedAdministrationAt ?? now()->toIso8601String()
        ));

        return $this->medicationScope->forAdministration(
            $user,
            $medication->client,
            $medication,
            $actionAt,
            $scheduledFor,
            isset($data['shift_id']) ? (int) $data['shift_id'] : null,
            $round,
            function (MedicationScopeDecision $scope) use ($request, $user, $data, $submittedAdministrationAt) {
                $medication = $scope->medication;
                $shiftId = $scope->shiftId();
                $scan = $this->validatedRecordingScan($scope, $data);

                $notes = trim((string) ($data['notes'] ?? ''));
                if (($data['cd_balance'] ?? null) !== null) {
                    $balanceLine = 'CD register balance after dose: '.$data['cd_balance'];
                    $notes = $notes === '' ? $balanceLine : $notes."\n".$balanceLine;
                }

                $result = $this->marService->recordAdministration(
                    $scope->client,
                    $medication,
                    [
                        'status' => $data['status'],
                        'reason' => $data['reason'] ?? null,
                        'reason_code' => $data['status'] === 'given' ? null : ($data['reason_code'] ?? null),
                        'dose_given' => $data['status'] === 'given' ? $medication->dosage : null,
                        'quantity_administered' => $data['quantity_administered'] ?? null,
                        'cd_balance' => $data['cd_balance'] ?? null,
                        'scheduled_for' => $data['scheduled_for'],
                        'medication_round_id' => $scope->round?->id,
                        'administered_at' => $submittedAdministrationAt,
                        'witnessed_by' => $data['witnessed_by'] ?? null,
                        'witness_credential' => $data['witness_credential'] ?? null,
                        'blood_glucose_level' => $data['blood_glucose_level'] ?? null,
                        'pulse_bpm' => $data['pulse_bpm'] ?? null,
                        'blood_pressure_systolic' => $data['blood_pressure_systolic'] ?? null,
                        'blood_pressure_diastolic' => $data['blood_pressure_diastolic'] ?? null,
                        'notes' => $notes !== '' ? $notes : null,
                        'client_request_uuid' => $data['client_request_uuid'] ?? null,
                        'captured_offline_at' => $data['captured_offline_at'] ?? null,
                        'origin_device_id' => $data['origin_device_id'] ?? null,
                        'queued_offline' => (bool) ($data['queued_offline'] ?? false),
                        'scope_authorized' => true,
                        // P01 C1 recording contract (all optional).
                        ...Arr::only($data, RecordingContract::fields()),
                        ...$scan,
                    ],
                    $user->id,
                    $shiftId,
                    $user->canDo('medications.controlled.view'),
                    prelockedPresenceShifts: $scope->lockedPresenceShifts,
                    prelockedPresenceEffectiveAt: $scope->lockedPresenceEffectiveAt,
                );

                if (! ($result['success'] ?? false)) {
                    $field = $result['error_field'] ?? 'status';
                    $message = $result['error'] ?? 'Could not record this dose.';

                    if ($request->expectsJson()) {
                        return response()->json(
                            $this->withMedicationSync(
                                array_filter([
                                    'success' => false,
                                    'message' => $message,
                                    'error' => $message,
                                    'error_field' => $field,
                                    'errors' => [$field => [$message]],
                                    'competency_state' => $result['competency_state'] ?? null,
                                    'safety_check' => $result['safety_check'] ?? null,
                                ], fn ($value) => $value !== null),
                                $data,
                                'rejected',
                                false,
                                $message,
                            ),
                            422,
                        );
                    }

                    return back()->withErrors([$field => $message]);
                }

                $clientName = trim(($medication->client->first_name ?? '').' '.($medication->client->last_name ?? ''));

                if ($result['duplicate'] ?? false) {
                    // P01 (JSON): someone else's record for this dose is
                    // "Already recorded"; a retry of this same request is the
                    // worker's own record coming back, so it confirms it.
                    if ($request->expectsJson()) {
                        $replayed = (bool) ($result['replayed'] ?? false);

                        return response()->json($this->withMedicationSync(
                            [
                                ...$this->doseAdministrationPayload($result),
                                'replayed' => $replayed,
                                'duplicate_of' => $replayed ? null : ($result['duplicate_of'] ?? null),
                            ],
                            $data,
                            'duplicate',
                            true,
                            $replayed
                                ? $medication->name.' is on '.$clientName.'’s chart.'
                                : 'Already recorded — nothing new was saved.',
                        ));
                    }

                    return back()->with('warning', 'This dose was already recorded — no changes made.');
                }

                $this->emitMedicationTimelineEvent($result['administration'], $medication, $user, $shiftId, $data);
                $this->medicationScope->recordBreakGlassUse(
                    $scope,
                    'recorded_dose',
                    $medication->name.' · '.$data['status'],
                );

                // The sidebar overdue badge caches for 60s — recording a dose is
                // the one action that should drop it immediately.
                Cache::forget(HandleInertiaRequests::medsOverdueBadgeCacheKey(
                    (int) $user->id,
                    Carbon::now($this->scheduleService->workerTimezone())->toDateString(),
                ));

                $this->appendDoseEvent($scope, $result['administration']);

                $outcome = match ($data['status']) {
                    'refused' => 'recorded as refused',
                    'withheld' => 'recorded as withheld',
                    default => 'recorded to the MAR',
                };

                if ($request->expectsJson()) {
                    return response()->json($this->withMedicationSync(
                        $this->doseAdministrationPayload($result),
                        $data,
                        $this->medicationProcessedStatus($data),
                        false,
                        $medication->name.' '.$outcome.' for '.$clientName,
                    ));
                }

                return back()->with('success', $medication->name.' '.$outcome.' for '.$clientName);
            }, authorizationUserIds: array_filter([
                is_numeric($data['witnessed_by'] ?? null) ? (int) $data['witnessed_by'] : null,
            ]));
    }

    /**
     * Record a PRN (as-needed) dose from the frontline quick-entry flow.
     *
     * Delegates to the same EnhancedMarService the rest of the medication
     * module uses, so safety checks, controlled-drug witness rules, audit and
     * PRN over-limit incident handling all run exactly as they do from the
     * admin recording path. This avoids creating a second administration path
     * for as-needed doses.
     *
     * The PRN wizard and the offline queue submit through axios with
     * `Accept: application/json`, and axios silently follows a redirect to a
     * 200 page. JSON callers therefore get an explicit 422 `rejected` sync
     * envelope for every service rejection (over limit, awaiting verification,
     * competency, witness) and a `processed`/`synced` envelope on success, so a
     * refused PRN is never reported to the worker as recorded.
     */
    private function recordingContextRules(): array
    {
        return [
            'shift_id' => ['nullable', 'integer', 'min:1'],
            'scan_code' => ['nullable', 'string', 'max:255'],
            'scan_source' => ['nullable', 'string', 'in:manual,scanner'],
            'scan_verified' => ['nullable', 'boolean'],
            'scan_match_source' => ['nullable', 'string', 'max:50'],
        ];
    }

    /** Recheck the supplied pack code against the locked canonical medicine. */
    private function validatedRecordingScan(MedicationScopeDecision $scope, array $data): array
    {
        if (blank($data['scan_code'] ?? null)) {
            return [];
        }
        $verified = app(MedicationScanVerificationService::class)->verify($scope->client, $scope->medication, $data['scan_code']);
        if (! $verified['matched']) {
            throw ValidationException::withMessages(['scan_code' => $verified['message']]);
        }

        return [
            'scan_code' => $data['scan_code'],
            'scan_source' => $data['scan_source'] ?? 'manual',
            'scan_verified' => true,
            'scan_match_source' => $verified['match_source'],
        ];
    }

    public function recordPrn(Request $request): RedirectResponse|JsonResponse
    {
        $user = $request->user();
        abort_unless($user, 403);

        abort_unless(
            $user->canDo('medications.administer.record'),
            403,
            'You do not have permission to record medication administrations.'
        );

        $data = $request->validate([
            'client_medication_id' => ['required', 'integer'],
            'reason' => ['required', 'string', 'max:500'],
            'dose_given' => ['nullable', 'string', 'max:255'],
            'quantity_administered' => ['nullable', 'numeric', MedicationStockQuantity::VALIDATION_RULE, 'min:0.01', 'max:10000'],
            // P01 Q-C2a: a controlled as-needed dose states the balance left,
            // checked against the register as a scheduled dose's is.
            'cd_balance' => ['nullable', 'numeric', MedicationStockQuantity::VALIDATION_RULE, 'min:0', 'max:100000'],
            'administered_at' => ['nullable', 'date'],
            'witnessed_by' => ['nullable', 'integer', 'min:1'],
            'witness_credential' => ['nullable', 'string', 'max:255'],
            'blood_glucose_level' => ['nullable', 'numeric', 'min:0', 'max:999.9'],
            'pulse_bpm' => ['nullable', 'integer', 'min:20', 'max:250'],
            'blood_pressure_systolic' => ['nullable', 'integer', 'min:40', 'max:300'],
            'blood_pressure_diastolic' => ['nullable', 'integer', 'min:20', 'max:200'],
            'notes' => ['nullable', 'string', 'max:2000'],
            ...RecordingContract::rules(),
            ...$this->recordingContextRules(),
            ...$this->medicationOfflineSubmissionRules($request),
        ]);

        $medication = ClientMedication::with('client')->findOrFail($data['client_medication_id']);
        abort_unless($medication->client, 404);
        $submittedAdministrationAt = $this->medicationSubmittedAdministrationAt($data);
        $actionAt = $this->scheduleService->parseWorkerDateTime((string) (
            $submittedAdministrationAt ?? now()->toIso8601String()
        ));

        return $this->medicationScope->forAdministration(
            $user,
            $medication->client,
            $medication,
            $actionAt,
            null,
            isset($data['shift_id']) ? (int) $data['shift_id'] : null,
            null,
            function (MedicationScopeDecision $scope) use ($request, $user, $data, $submittedAdministrationAt) {
                $medication = $scope->medication;
                $shiftId = $scope->shiftId();
                $scan = $this->validatedRecordingScan($scope, $data);

                $result = $this->marService->recordAdministration(
                    $scope->client,
                    $medication,
                    [
                        'status' => 'given',
                        'reason' => trim($data['reason']),
                        'dose_given' => $data['dose_given'] ?? $medication->dosage,
                        'quantity_administered' => $data['quantity_administered'] ?? null,
                        'cd_balance' => $data['cd_balance'] ?? null,
                        'witnessed_by' => $data['witnessed_by'] ?? null,
                        'witness_credential' => $data['witness_credential'] ?? null,
                        'blood_glucose_level' => $data['blood_glucose_level'] ?? null,
                        'pulse_bpm' => $data['pulse_bpm'] ?? null,
                        'blood_pressure_systolic' => $data['blood_pressure_systolic'] ?? null,
                        'blood_pressure_diastolic' => $data['blood_pressure_diastolic'] ?? null,
                        'notes' => $data['notes'] ?? null,
                        'client_request_uuid' => $data['client_request_uuid'] ?? null,
                        'captured_offline_at' => $data['captured_offline_at'] ?? null,
                        'origin_device_id' => $data['origin_device_id'] ?? null,
                        'queued_offline' => (bool) ($data['queued_offline'] ?? false),
                        'administered_at' => $submittedAdministrationAt,
                        'scope_authorized' => true,
                        // P01 C1: amount, second person and the effect-check time.
                        ...Arr::only($data, [
                            'amount_mode',
                            'amount_reason',
                            'quantity_given',
                            'more_severity',
                            'more_immediate_action',
                            'second_person_unavailable',
                            'second_person_pin_forgotten',
                            'effect_check_due_at',
                        ]),
                        ...$scan,
                    ],
                    $user->id,
                    $shiftId,
                    $user->canDo('medications.controlled.view'),
                    prelockedPresenceShifts: $scope->lockedPresenceShifts,
                    prelockedPresenceEffectiveAt: $scope->lockedPresenceEffectiveAt,
                );

                if (! ($result['success'] ?? false)) {
                    $field = $result['error_field'] ?? 'reason';
                    $message = $result['error'] ?? 'Could not record this PRN dose.';

                    if ($request->expectsJson()) {
                        return response()->json(
                            $this->withMedicationSync(
                                array_filter([
                                    'success' => false,
                                    'message' => $message,
                                    'error' => $message,
                                    'error_field' => $field,
                                    'errors' => [$field => [$message]],
                                    'competency_state' => $result['competency_state'] ?? null,
                                    'safety_check' => $result['safety_check'] ?? null,
                                ], fn ($value) => $value !== null),
                                $data,
                                'rejected',
                                false,
                                $message,
                            ),
                            422,
                        );
                    }

                    return back()->withErrors([$field => $message]);
                }

                if ($result['duplicate'] ?? false) {
                    if ($request->expectsJson()) {
                        return response()->json($this->withMedicationSync(
                            $this->prnAdministrationPayload($result),
                            $data,
                            'duplicate',
                            true,
                            'This PRN dose was already recorded — no changes made.',
                        ));
                    }

                    return $this->onDuplicateOfflineSubmission('prn', $data);
                }

                $this->emitMedicationTimelineEvent($result['administration'], $medication, $user, $shiftId, $data);
                $this->medicationScope->recordBreakGlassUse(
                    $scope,
                    'recorded_prn_dose',
                    $medication->name,
                );

                $savedMessage = 'Saved — '.$medication->name.' recorded for '.trim(($medication->client->first_name ?? '').' '.($medication->client->last_name ?? ''));

                $this->appendDoseEvent($scope, $result['administration']);

                if ($request->expectsJson()) {
                    return response()->json($this->withMedicationSync(
                        $this->prnAdministrationPayload($result),
                        $data,
                        $this->medicationProcessedStatus($data),
                        false,
                        $savedMessage,
                    ));
                }

                return back()->with('success', $savedMessage);
            }, authorizationUserIds: array_filter([
                is_numeric($data['witnessed_by'] ?? null) ? (int) $data['witnessed_by'] : null,
            ]));
    }

    /**
     * Confirmation of the worker's own recorded scheduled dose for JSON
     * callers (the P01 dialog): the record, the warnings it carries, and the
     * medication error "more than ordered" raised.
     *
     * @return array<string, mixed>
     */
    private function appendDoseEvent(MedicationScopeDecision $scope, ClientMedicationAdministration $administration): void
    {
        // Last domain write in the authorized source transaction. A recorder
        // failure rolls the dose back; exact replays return before this call.
        app(MedicationEventRecorder::class)->append(new MedicationEventData(
            siteId: (int) $scope->client->site_id,
            kind: 'dose.recorded',
            subjectType: 'medication_administration',
            subjectId: (string) $administration->id,
            actorId: (int) $scope->performer->id,
            occurredAt: CarbonImmutable::now('UTC'),
            summary: 'Medication dose outcome recorded.',
            facts: ['outcome' => $administration->status, 'round_id' => $administration->medication_round_id],
            clientId: (int) $scope->client->id,
            controlled: (bool) $scope->medication->controlled_drug,
        ));
    }

    private function doseAdministrationPayload(array $result): array
    {
        $administration = $result['administration'] ?? null;

        return [
            'success' => true,
            'administration' => $administration instanceof ClientMedicationAdministration ? [
                'id' => $administration->id,
                'status' => $administration->status,
                'administered_at' => $administration->administered_at?->toIso8601String(),
                'reoffer_of_id' => $administration->reoffer_of_id,
                'amount_mode' => $administration->amount_mode,
                'quantity_given' => $administration->quantity_given,
                ...app(SecondPersonConfirmationPayload::class)->forAdministration($administration),
                'late_reason' => $administration->late_reason,
            ] : null,
            'medication_error' => $result['medication_error'] ?? null,
            'safety_check' => $result['safety_check'] ?? null,
        ];
    }

    /**
     * Minimal confirmation of the worker's own recorded PRN administration for
     * JSON callers.
     *
     * @return array<string, mixed>
     */
    private function prnAdministrationPayload(array $result): array
    {
        $administration = $result['administration'] ?? null;

        return [
            'success' => true,
            'administration' => $administration instanceof ClientMedicationAdministration ? [
                'id' => $administration->id,
                'status' => $administration->status,
                'administered_at' => $administration->administered_at?->toIso8601String(),
                'effect_check_due_at' => $administration->effect_check_due_at?->toIso8601String(),
                ...app(SecondPersonConfirmationPayload::class)->forAdministration($administration),
            ] : null,
            'medication_error' => $result['medication_error'] ?? null,
            'safety_check' => $result['safety_check'] ?? null,
        ];
    }

    /**
     * Record the effect of a PRN dose (the follow-up check). Worker-scoped
     * mirror of the admin `emar.prn_effectiveness.store` endpoint so the
     * follow-up queue on the board clears through the same register.
     */
    public function recordPrnEffect(Request $request): RedirectResponse
    {
        return app(LegacyEffectFollowupAdapter::class)->save($request, $this->medicationScope);
    }

    /**
     * The people on this worker's board: those they have a shift with on the
     * selected day (or the day after, so late shifts crossing midnight keep
     * their context) whom they may open — the person rule My Day and the
     * badge use (C6): people they're assigned to at their Sites, or on a
     * shift they're clocked in to now. So before clock-in a worker sees no
     * other person's medicines unless they're assigned to them. With no
     * shift context (e.g. a medication lead opening the worker view) the
     * board falls back to the people at their Sites they may open.
     *
     * @return array{0: list<int>, 1: int, 2: bool} the people; how many more
     *                                              on their shift show once they clock in; whether
     *                                              they have a shift context
     */
    private function boardPeopleFor(User $user, Carbon $from, Carbon $to): array
    {
        $siteIds = $this->siteAccess->accessibleSiteIds(
            $user,
            ['clinical.accessAllSites', 'sites.viewAll'],
        );
        if ($siteIds === []) {
            return [[], 0, false];
        }

        try {
            $shiftClientIds = Shift::where('user_id', $user->id)
                ->where('starts_at', '<=', $to)
                ->where(function ($overlap) use ($from): void {
                    $overlap
                        ->where('actual_ends_at', '>=', $from)
                        ->orWhere(function ($scheduledEnd) use ($from): void {
                            $scheduledEnd
                                ->whereNull('actual_ends_at')
                                ->where(function ($effectiveEnd) use ($from): void {
                                    $effectiveEnd
                                        ->where('ends_at', '>=', $from)
                                        ->orWhere(function ($openShift): void {
                                            $openShift
                                                ->whereNull('ends_at')
                                                ->where('status', 'in_progress');
                                        });
                                });
                        });
                })
                ->where('status', '!=', 'cancelled')
                ->whereHas('client', fn ($clients) => $clients->whereIn('site_id', $siteIds))
                ->pluck('client_id')
                ->filter()
                ->unique()
                ->values()
                ->all();

            if (! empty($shiftClientIds)) {
                $people = DoseSlotReaderScope::forViewerClients($user, $shiftClientIds)->clientIds ?? [];
                $people = array_values(array_filter(
                    $shiftClientIds,
                    fn ($clientId): bool => in_array((int) $clientId, $people, true),
                ));

                return [$people, count($shiftClientIds) - count($people), true];
            }
        } catch (\Throwable $e) {
            report($e);
            // fall through
        }

        // Administration authority is assignment-bound. Only a medication
        // reader/lead may expand an empty shift context to all approved-Site
        // medication clients.
        if (! $user->canDo('medications.view')) {
            return [[], 0, false];
        }

        // A medication lead with no shift today still gets a useful board, but
        // only for clients at Sites that are currently approved for them —
        // and only the people they may open (the P02 person rule, C6b). A
        // person whose order change waits for the order check is still owed
        // its doses (shown as Waiting for the order check).
        try {
            return [app(MarLinkService::class)->openableClientIds($user, ClientMedication::query()
                ->where(fn ($orders) => $orders->active()->orWhere(fn ($waiting) => $waiting->awaitingVerification()))
                ->whereHas('client', fn ($clients) => $clients->whereIn('site_id', $siteIds))
                ->pluck('client_id')
                ->filter()
                ->unique()
                ->values()
                ->all()), 0, false];
        } catch (\Throwable $e) {
            report($e);

            return [[], 0, false];
        }
    }

    /**
     * Mirror the guided-round controller's timeline emission so worker-board
     * recordings (scheduled doses and PRNs) appear in client timelines and
     * the board's activity feed. Callers invoke this inside the same scope
     * transaction so a timeline failure rolls the administration back.
     */
    private function emitMedicationTimelineEvent(
        ClientMedicationAdministration $administration,
        ClientMedication $medication,
        User $user,
        ?int $shiftId,
        array $submission,
    ): void {
        $statusLabel = ucfirst(str_replace('_', ' ', (string) $administration->status));

        app(TimelineEmitter::class)->record([
            'source_type' => ClientMedicationAdministration::class,
            'source_id' => $administration->id,
            'occurred_at' => $administration->administered_at ?? now(),
            'type' => 'medication_'.$administration->status,
            'actor_user_id' => $user->id,
            'client_id' => $medication->client_id,
            'shift_id' => $shiftId,
            'site_id' => $medication->client?->site_id,
            'subject' => $statusLabel.': '.$medication->name.($medication->dosage ? ' '.$medication->dosage : ''),
            'body' => null,
            'meta' => [
                'medication_name' => $medication->name,
                'dosage' => $medication->dosage,
                'status' => $administration->status,
                'reason' => $administration->reason,
                'witnessed_by' => $administration->witnessed_by,
                'is_prn' => $medication->is_prn ? true : null,
                'client_request_uuid' => $administration->client_request_uuid,
                'captured_offline_at' => $submission['captured_offline_at'] ?? null,
                'origin_device_id' => $submission['origin_device_id'] ?? null,
                'queued_offline' => (bool) ($submission['queued_offline'] ?? false),
            ],
        ]);
    }

    /**
     * Stock pressure for the assigned clients' active medications: low stock,
     * expiring within 30 days, or already expired. Always anchored to today —
     * stock is a now problem regardless of which day the board shows.
     */
    private function stockAlerts(array $clientIds, bool $includeControlled): array
    {
        if (empty($clientIds)) {
            return [];
        }

        try {
            $today = Carbon::today($this->scheduleService->workerTimezone());

            return ClientMedicationStock::query()
                ->whereHas('medication', fn ($q) => $q
                    ->whereIn('client_id', $clientIds)
                    ->active()
                    ->when(! $includeControlled, fn ($medications) => $medications->where('controlled_drug', false)))
                ->where(fn ($query) => $query->where(fn ($q) => $q->lowStock())
                    ->orWhere(fn ($q) => $q->expired())->orWhere(fn ($q) => $q->expiringSoon()))
                ->with('medication.client:id,first_name,last_name')
                ->orderBy('expiry_date')
                ->limit(12)
                ->get()
                ->map(function (ClientMedicationStock $stock) {
                    $med = $stock->medication;
                    $clientName = $med?->client
                        ? trim($med->client->first_name.' '.$med->client->last_name)
                        : null;

                    $expiredPack = $stock->packExpiries(true)->sortBy('date')->first();
                    $expiringPack = $stock->packExpiries(false)->sortBy('date')->first();
                    $expired = $expiredPack !== null;
                    $expiringSoon = ! $expired && $expiringPack !== null;
                    $available = $stock->usableQuantity();
                    $low = $stock->isLowStock();

                    if ($expired) {
                        $type = 'expired';
                        $tone = 'crit';
                        $detail = 'Expired '.$expiredPack['date']->format('j M Y');
                    } elseif ($low) {
                        $type = 'stock_low';
                        $tone = MedicationStockQuantity::lessThanOrEqual($available ?? 0, 0) ? 'crit' : 'warn';
                        $detail = $available.' '.($stock->unit ?: 'units').' left · reorder at '.$stock->reorder_level;
                    } else {
                        $type = 'expiring_soon';
                        $tone = 'warn';
                        $detail = 'Expires '.$expiringPack['date']->format('j M Y');
                    }

                    return [
                        'id' => $stock->id,
                        'type' => $type,
                        'tone' => $tone,
                        'label' => trim(($med?->name ?? 'Medication').($med?->dosage ? ' '.$med->dosage : '')).($clientName ? ' — '.$clientName : ''),
                        'detail' => $detail.($expiringSoon && $low ? ' · low stock' : ''),
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
            $administrationIds = $administrationsById->keys()->all();
            if ($administrationIds === []) {
                return [];
            }

            return TimelineEvent::query()
                ->whereIn('client_id', $clientIds)
                ->where('type', 'like', 'medication%')
                ->where('source_type', ClientMedicationAdministration::class)
                ->whereIn('source_id', $administrationIds)
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
                        ? trim($event->client->first_name.' '.$event->client->last_name)
                        : null;

                    $witness = $source?->witnessedBy?->name;

                    return [
                        'id' => $event->id,
                        'occurred_at' => $event->occurred_at?->toIso8601String(),
                        'time' => $event->occurred_at?->copy()->setTimezone($timezone)->format('H:i'),
                        'icon' => $icon,
                        'text' => trim(($event->subject ?: Str::headline($event->type)).($clientName ? ' — '.$clientName : '')),
                        'by' => trim(($event->actor?->name ?? 'System').($witness ? ' · wit. '.$witness : '')),
                    ];
                })
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

            return $start->format('g:i a').($end ? ' – '.$end->format('g:i a') : '');
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }

    /**
     * The single round the worker should walk right now, matching the
     * `/my-day` banner exactly (same service, same precedence).
     */
    private function activeRound(User $user, Carbon $now, array $clientIds, array $context): ?array
    {
        if (! $user->canDo('medications.administer.record')) {
            return null;
        }

        $siteIds = $this->siteAccess->accessibleSiteIds(
            $user,
            ['clinical.accessAllSites', 'sites.viewAll'],
        );
        if ($siteIds === []) {
            return null;
        }

        try {
            $rounds = MedicationRound::query()
                ->whereDate('round_date', $now->toDateString())
                ->whereIn('site_id', $siteIds)
                ->where(function ($q) use ($user) {
                    $q->where('assigned_to', $user->id)
                        ->orWhere('started_by', $user->id);
                })
                ->whereIn('status', ['in_progress', 'pending'])
                ->orderByRaw("CASE status WHEN 'in_progress' THEN 0 WHEN 'pending' THEN 1 ELSE 2 END")
                ->orderBy('scheduled_time')
                ->get();

            foreach ($rounds as $round) {
                $items = $this->guidedRoundService->items(
                    $round,
                    $user->canDo('medications.controlled.view'),
                    $clientIds,
                );
                $progress = $this->guidedRoundService->summarise($items);

                if ($progress['total'] === 0) {
                    continue;
                }

                return [
                    'id' => $round->id,
                    'name' => $round->name,
                    'status' => $round->status,
                    'scheduled_time' => $round->scheduled_time,
                    'scheduled_at' => $round->scheduledAt()?->toIso8601String(),
                    'dose_keys' => $this->roundDoseKeys($items),
                    'given' => $progress['given'],
                    'total' => $progress['total'],
                    'completed' => $progress['completed'],
                    'percent' => $progress['percent'],
                    'url' => $this->roundUrl($round, $context),
                ];
            }

            return null;
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
    private function guidedRound(User $user, int $roundId, array $clientIds, bool $includeControlled, array $boardClientIds): array
    {
        $round = MedicationRound::query()->findOrFail($roundId);

        return $this->medicationScope->forRound($user, $round, now(), function (MedicationScopeDecision $scope) use ($clientIds, $includeControlled, $boardClientIds): array {
            $round = $scope->round;
            $round->load(['template:id,name', 'assignedTo:id,name', 'startedBy:id,name', 'completedBy:id,name']);
            $items = $this->guidedRoundService->items($round, $includeControlled, $clientIds);
            $boardItems = $clientIds === $boardClientIds ? $items
                : $this->guidedRoundService->items($round, $includeControlled, $boardClientIds);

            return [
                'can_record' => $round->status === 'in_progress',
                'can_start' => in_array($round->status, ['pending', 'partial'], true),
                'can_complete' => $this->guidedRoundService->canCompleteCanonicalRound($round),
                'round' => [
                    'id' => $round->id, 'name' => $round->name, 'status' => $round->status,
                    'scheduled_time' => $round->scheduled_time, 'window_minutes' => $round->window_minutes,
                    'round_date' => $round->round_date?->toDateString(),
                    'template_name' => $round->template?->name,
                    'assignee' => $round->assignedTo?->name,
                    'created_at' => $round->created_at?->toIso8601String(),
                    'started_at' => $round->started_at?->toIso8601String(),
                    'started_by' => $round->startedBy?->name,
                    'completed_at' => $round->completed_at?->toIso8601String(),
                    'completed_by' => $round->completedBy?->name,
                ],
                'items' => $items,
                // Person selection narrows visible doses, never the round's
                // completion decision or its other authorized members.
                'progress' => $this->guidedRoundService->summarise($boardItems),
                'selected_progress' => $this->guidedRoundService->summarise($items),
            ];
        }, ['pending', 'partial', 'in_progress', 'completed']);
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return list<string>
     */
    private function roundDoseKeys(array $items): array
    {
        return array_map(
            fn (array $item): string => $item['medication_id'].':'.Carbon::parse($item['scheduled_for'])->utc()->format('YmdHi'),
            $items,
        );
    }

    private function roundUrl(MedicationRound $round, array $context): string
    {
        $query = ['round' => $round->id, 'date' => $round->round_date->toDateString()];
        if (($context['client_id'] ?? null) !== null) {
            $query['client_id'] = $context['client_id'];
        }
        // The list already approved this round's Site. Do not carry a
        // different Site into the canonical round's return context.
        if (($context['site_id'] ?? null) === (int) $round->site_id) {
            $query['site_id'] = $context['site_id'];
        }

        return route('meds.round.show', $query);
    }

    private function roundsForDate(User $user, Carbon $date, array $clientIds, array $context): array
    {
        $siteIds = $this->siteAccess->accessibleSiteIds(
            $user,
            ['clinical.accessAllSites', 'sites.viewAll'],
        );
        if ($siteIds === []) {
            return [];
        }

        try {
            $rounds = MedicationRound::query()
                ->whereDate('round_date', $date->toDateString())
                ->whereIn('site_id', $siteIds)
                ->where(function ($q) use ($user) {
                    $q->where('assigned_to', $user->id)
                        ->orWhere('started_by', $user->id);
                })
                ->orderBy('scheduled_time')
                ->limit(12)
                ->get();

            $includeControlled = $user->canDo('medications.controlled.view');

            return $rounds
                ->map(function (MedicationRound $round) use ($includeControlled, $clientIds, $context) {
                    $items = $this->guidedRoundService->items($round, $includeControlled, $clientIds);
                    $progress = $this->guidedRoundService->summarise($items);

                    return [
                        'id' => $round->id,
                        'name' => $round->name,
                        'status' => $round->status,
                        'scheduled_time' => $round->scheduled_time,
                        'scheduled_at' => $round->scheduledAt()?->toIso8601String(),
                        'dose_keys' => $this->roundDoseKeys($items),
                        'total' => $progress['total'],
                        'completed' => $progress['completed'],
                        'percent' => $progress['percent'],
                        'url' => $this->roundUrl($round, $context),
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
}
