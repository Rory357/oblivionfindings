<?php

namespace App\Http\Controllers;

use App\Domain\Governance\Services\BoardPackAccessService;
use App\Domain\Hr\Models\HrAttendanceSession;
use App\Domain\Hr\Services\AttendanceService;
use App\Http\Resources\MyShiftResource;
use App\Models\Client;
use App\Models\ClientIncident;
use App\Models\ClientMedication;
use App\Models\ControlRoom\OperatorNote;
use App\Models\ControlRoomAlert;
use App\Models\FirstAidFollowup;
use App\Models\IncidentFollowup;
use App\Models\LoneWorkerSession;
use App\Models\MedicationRound;
use App\Models\PpeAllocation;
use App\Models\Shift;
use App\Models\ShiftHandover;
use App\Models\ShiftOpenPosition;
use App\Models\Site;
use App\Models\SiteChecklistRun;
use App\Models\Timesheet;
use App\Models\User;
use App\Services\GuidedRoundService;
use App\Services\MarScheduleService;
use App\Services\Medication\MedicationGovernanceScopeService;
use App\Services\ShiftHandoverService;
use App\Services\Tasks\TaskAggregator;
use App\Services\Tasks\TaskItem;
use App\Services\UserSiteAccessService;
use App\Support\EmarUrl;
use App\Support\ResidentHue;
use App\Support\RunDetailPresenter;
use App\Support\ShiftTaskSupport;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class MyTasksController extends Controller
{
    private const PRIORITY_ORDER = [
        'critical' => 0,
        'high' => 1,
        'medium' => 2,
        'low' => 3,
    ];

    public function __construct(
        private readonly UserSiteAccessService $siteAccess,
        private readonly BoardPackAccessService $boardPackAccess,
    ) {}

    public function __invoke(Request $request): Response
    {
        abort_unless($request->user(), 403);

        $user = $request->user();
        $userId = $user->id;
        $canOpenEmar = $user->canDo('medications.view');
        $canRecordMedications = $user->canDo('medications.administer.record');
        $canViewMedications = $canOpenEmar || $canRecordMedications;
        $canRecordControlledMedications = $canRecordMedications
            && $user->canDo('medications.controlled.record');
        $canAccessControlledMedications = $user->canDo('medications.controlled.view')
            || $canRecordControlledMedications;
        $workerNow = Carbon::now($this->workerTimezone());
        $queryNow = $workerNow->copy()->utc();
        $today = $workerNow->copy()->startOfDay()->utc();
        $tomorrowEnd = $workerNow->copy()->addDay()->endOfDay()->utc();

        // 1. Today formatted
        $todayFormatted = $workerNow->format('l, j F Y');

        // 2. Shifts today + tomorrow
        $shifts = $this->getShifts($user, $today, $tomorrowEnd, $workerNow);

        // 2b. Active shift + site (site-first redesign). Multi-resident houses
        //     expose every co-resident so the hero/avatar stack and resident
        //     filter tabs render correctly. Falls back to the shift's
        //     single client for 1:1 visits.
        $activeShift = $this->resolveActiveShiftModel($user, $workerNow);
        $activeSitePayload = $this->buildActiveSitePayload($activeShift);

        // 3. Medications due — aggregate across every resident at the active
        //    site when present, otherwise fall back to the shifts' client list
        //    (preserves the legacy single-client behaviour).
        $clientIds = $activeSitePayload
            ? array_column($activeSitePayload['residents'], 'id')
            : $shifts->pluck('client.id')->filter()->unique()->values()->all();
        $medicationsDue = $canViewMedications
            ? $this->getMedicationsDue(
                $clientIds,
                $workerNow,
                $canRecordMedications,
                $canRecordControlledMedications,
                $canAccessControlledMedications,
                $canOpenEmar,
            )
            : [];

        // 4. Timesheets
        $timesheets = $this->getTimesheets($userId);

        // 5. Incidents
        $incidents = $this->getIncidents($userId, $queryNow);

        // 6. Tasks (CR alerts + followups + notes - existing aggregation)
        $tasks = $this->getCrTasks($user);
        $notifications = $this->getDigestNotifications($user);
        $pendingClaimsCount = $this->getPendingClaimsCount($user);

        // 8. Stats
        $todayShifts = $shifts->filter(fn ($s) => $s['is_today']);
        $stats = [
            'shifts_today' => $todayShifts->count(),
            'meds_due' => count($medicationsDue),
            'meds_overdue' => collect($medicationsDue)->where('status', 'overdue')->count(),
            'tasks_open' => $todayShifts->sum(fn ($s) => collect($s['tasks'])->where('is_completed', false)->count()),
            'timesheets_pending' => collect($timesheets)->count(),
            'incidents_open' => count($incidents),
            'cr_alerts' => collect($tasks)->where('type', 'alert')->count(),
            'notifications_unread' => $this->boardPackAccess
                ->visibleNotificationQuery($user, unreadOnly: true)
                ->count(),
        ];

        // 11. Frontline clock state (PR 4)
        $clock = $this->getClockState($user, $queryNow);

        // 12. Active guided medication round (PR 9). Surfaces a resume / start
        //     banner on /my-day for the worker assigned to today's round.
        $activeRound = $this->getActiveRound($user, $workerNow);

        // 13. Shift lifecycle hero payloads.
        $nextShiftBriefing = $clock['open_session']
            ? null
            : $this->getNextShiftBriefing($user, $workerNow, $canOpenEmar, $canAccessControlledMedications);
        $previousShift = $clock['open_session'] || $nextShiftBriefing
            ? null
            : $this->getPreviousShift($user, $workerNow);
        $handover = $this->buildDigestHandover(
            $activeShift ? $this->findIncomingHandover($user, $activeShift) : ($nextShiftBriefing['incoming_handover'] ?? null),
        );

        // 14. Active-shift card (site-first). The new /my-day hero reads this
        //     instead of the legacy `clock.open_session.shift`. The card
        //     mirrors MyShiftResource so the front-end can use one TS type.
        $activeShiftCard = $activeShift
            ? array_merge(
                MyShiftResource::fromShift($activeShift, $workerNow),
                ['site' => $activeSitePayload]
            )
            : null;
        $activeShiftSiteId = $activeShift?->site_id ? (int) $activeShift->site_id : null;
        $workerToday = $workerNow->toDateString();
        $shiftChecklists = $this->buildShiftChecklists($user, $activeShiftSiteId, $workerToday);

        return Inertia::render('my-day/index', [
            'today' => $todayFormatted,
            'today_iso' => $workerNow->toDateString(),
            'shifts' => $shifts->values()->all(),
            'medications_due' => $medicationsDue,
            'timesheets' => $timesheets,
            'incidents' => $incidents,
            'tasks' => $tasks,
            'notifications' => $notifications,
            'pending_claims_count' => $pendingClaimsCount,
            'stats' => $stats,
            'clock' => $clock,
            'active_round' => $activeRound,
            // Worker-facing Lone Worker Safety check-in card. Null unless the
            // signed-in user is the subject of a live (active/overdue/emergency)
            // LoneWorkerSession. The one-tap card POSTs to the existing
            // health-safety.lone-workers.sessions.check-in endpoint.
            'active_lone_worker_session' => $this->getActiveLoneWorkerSession($user),
            // Read-only "First-aid follow-ups assigned to me" card. Lists open
            // (uncompleted) FirstAidFollowup rows owned by the signed-in worker
            // so re-checks / ACC45 lodgements / whānau calls don't slip. Each
            // row deep-links to the register's record modal (no write here).
            'first_aid_followups' => $this->getFirstAidFollowups($user),
            // Worker-facing "My PPE" card — the worker's own active allocations that
            // still need acknowledgement or an RPE fit-test. Empty unless action is
            // needed. One-tap acknowledge POSTs to ppe.allocations.acknowledge-own
            // (auth-only + ownership), so support workers (no hazards.* perms) can use it.
            'my_ppe' => $this->getMyPpe($user),
            // Cross-module "My tasks" card — the signed-in user's open work
            // items from the /tasks aggregator (assigned=me), capped at 8
            // rows. `total` carries the uncapped count for the footer link.
            'myTasks' => $this->getMyAggregatedTasks($user),
            'active_shift' => $activeShiftCard,
            'shiftChecklists' => $shiftChecklists,
            'checklistConfig' => $this->buildChecklistConfig($user, $workerToday),
            'runDetail' => RunDetailPresenter::for(
                $request->integer('run'),
                $activeShiftSiteId,
                $request->user(),
            ),
            'next_shift_briefing' => $nextShiftBriefing,
            'previous_shift' => $previousShift,
            'handover' => $handover,
            // Per-worker observation capabilities, used by the Vitals & obs
            // flow on /my-day to gate the observation-type list. We resolve
            // them here (rather than client-side via auth) because permission
            // resolution lives behind canDo() and shouldn't round-trip.
            'can_record_observation' => $user->canDo('clinical.observations.record'),
            'can_record_clinical' => $user->canDo('clinical.observations.recordClinical'),
            'can_view_medications' => $canViewMedications,
            'can_record_medications' => $canRecordMedications,
            'can_record_controlled_medications' => $canRecordControlledMedications,
            // The admin eMAR chart has an exact medications.view boundary.
            // Record-only workers stay on the frontline Meds Today/My Day flow.
            'can_open_emar' => $canOpenEmar,
            // Namespaced as `my_day_labels` so it does not collide with the
            // `labels` prop shared globally by HandleInertiaRequests for
            // terminology overrides (client.singular, etc.).
            'my_day_labels' => Lang::get('my-day'),
        ]);
    }

    private function buildShiftChecklists(User $user, ?int $siteId, string $today): array
    {
        if (! $siteId) {
            return [];
        }

        return SiteChecklistRun::query()
            ->where('site_id', $siteId)
            ->whereIn('status', ['scheduled', 'in_progress'])
            ->whereDate('scheduled_date', '<=', $today)
            ->with([
                'site:id,name,type',
                'assignment:id,site_id,template_id,assigned_to_user_id',
                'template:id,name,frequency,category',
            ])
            ->orderBy('scheduled_date')
            ->limit(50)
            ->get()
            ->map(fn (SiteChecklistRun $run) => [
                'id' => $run->id,
                'status' => $run->status,
                'can_run' => Gate::forUser($user)->allows('execute', $run),
                'scheduled_date' => $run->scheduled_date?->toDateString(),
                'is_overdue' => $run->scheduled_date
                    ? $run->scheduled_date->toDateString() < $today
                    : false,
                'pct' => (int) round((float) $run->completion_percentage),
                'template' => $run->template ? [
                    'id' => $run->template->id,
                    'name' => $run->template->name,
                    'frequency' => $run->template->frequency,
                    'category' => $run->template->category,
                ] : null,
            ])
            ->values()
            ->all();
    }

    /**
     * Keep the worker's claimed-cover next action on /my-day without leaking
     * positions outside their canonical Site scope.
     */
    private function getPendingClaimsCount(User $user): int
    {
        if (! $user->canDo('job_board.viewAny')
            && ! $user->canDo('job_board.claim')
            && ! $user->canDo('shifts.viewAny')
            && ! $user->canDo('shifts.viewAssigned')) {
            return 0;
        }

        try {
            return ShiftOpenPosition::query()
                ->tap(fn ($query) => $this->siteAccess->applyShiftOpenPositionScope($query, $user, ['reports.viewAny']))
                ->where('claimed_by', $user->id)
                ->where('status', 'claimed')
                ->count();
        } catch (\Throwable $e) {
            report($e);

            return 0;
        }
    }

    private function buildChecklistConfig(User $user, string $today): array
    {
        return [
            'categories' => config('checklists.categories'),
            'frequencyLabels' => config('checklists.frequency_labels'),
            'typeLabels' => config('checklists.type_labels'),
            'today' => $today,
            'can' => [
                'view' => (bool) $user->canDo('checklists.view'),
                'run' => (bool) $user->canDo('checklists.run'),
            ],
        ];
    }

    /**
     * Find the worker's currently-relevant shift model (in progress or imminent),
     * eager-loading the site + all co-resident clients. Used by the new site-
     * first hero so the avatar stack and resident filter render the full house.
     *
     * Returns null when no in-progress / upcoming shift exists today.
     */
    private function resolveActiveShiftModel(User $user, Carbon $workerNow): ?Shift
    {
        try {
            $nowUtc = $workerNow->copy()->utc();
            $workerDayStart = $workerNow->copy()->startOfDay()->utc();
            $workerDayEnd = $workerNow->copy()->endOfDay()->utc();
            $relations = [
                'client:id,first_name,last_name,profile_photo_path,site_id',
                'site:id,name,type,address_line_1,address_line_2,suburb,city,postcode',
                'site.clients:id,first_name,last_name,profile_photo_path,site_id,status',
                'serviceContext:id,name',
                'tasks',
            ];

            $openSession = HrAttendanceSession::query()
                ->where('user_id', $user->id)
                ->open()
                ->whereHas('shift', fn ($query) => $query
                    ->where('user_id', $user->id)
                    ->visibleToFrontline())
                ->with(['shift' => fn ($query) => $query->with($relations)])
                ->latest('clock_in_at')
                ->first();

            if ($openSession?->shift) {
                return $openSession->shift;
            }

            // Fall back to a current/worker-day shift. The worker-day bounds
            // are calculated before converting to UTC so overnight NZ shifts
            // do not disappear from the hero after the UTC date rolls over.
            $shift = Shift::query()
                ->where('user_id', $user->id)
                ->visibleToFrontline()
                ->whereIn('status', ['in_progress', 'scheduled', 'draft'])
                ->where(function ($query) use ($nowUtc, $workerDayStart, $workerDayEnd) {
                    $query
                        ->where(function ($overlap) use ($nowUtc) {
                            $overlap
                                ->where('starts_at', '<=', $nowUtc)
                                ->where('ends_at', '>=', $nowUtc);
                        })
                        ->orWhereBetween('starts_at', [$workerDayStart, $workerDayEnd]);
                })
                ->orderByRaw("CASE status WHEN 'in_progress' THEN 0 WHEN 'scheduled' THEN 1 WHEN 'draft' THEN 2 ELSE 3 END")
                ->orderBy('starts_at')
                ->with($relations)
                ->first();

            return $shift;
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }

    /**
     * Assemble the `active_shift.site` payload — the multi-resident house
     * snapshot used by the new /my-day hero. Returns null when the active
     * shift has no site or no residents.
     */
    private function buildActiveSitePayload(?Shift $shift): ?array
    {
        if (! $shift || ! $shift->site) {
            return null;
        }
        $site = $shift->site;
        $residents = $site->clients
            ->filter(fn (Client $c) => ($c->status ?? 'active') !== 'archived')
            ->values();

        if ($residents->isEmpty()) {
            return null;
        }

        return [
            'id' => $site->id,
            'name' => $site->name,
            'type' => $site->type ? Str::headline((string) $site->type) : 'Site',
            'address' => $this->formatSiteAddress($site),
            'href' => '/sites/'.$site->id,
            'residents' => $residents->map(function (Client $c) {
                $name = trim($c->first_name.' '.$c->last_name);

                return [
                    'id' => $c->id,
                    'first_name' => $c->first_name,
                    'name' => $name === '' ? 'Resident #'.$c->id : $name,
                    'initials' => ResidentHue::initials($c->first_name, $c->last_name),
                    'hue' => ResidentHue::for($c->id),
                    'photo_url' => $c->profile_photo_url ?? null,
                    'care_note_preview' => null, // populated by a future query; null is safe today
                ];
            })->all(),
        ];
    }

    /**
     * Render the human-readable address line shown in the hero meta.
     */
    private function formatSiteAddress(Site $site): string
    {
        $parts = array_filter([
            $site->address_line_1,
            $site->address_line_2,
            $site->suburb,
            $site->city,
            $site->postcode,
        ], fn ($p) => $p !== null && $p !== '');

        return implode(', ', $parts) ?: ($site->name.' — address unavailable');
    }

    /**
     * Build the clock-in/out payload for the frontline home card.
     *
     * Reuses the existing AttendanceService / HrAttendanceSession pipeline so
     * that starting a shift from `/my-day` behaves identically to starting it
     * from the full Attendance page (a real session row is created, and
     * clock-out drafts a timesheet).
     */
    private function getClockState(User $user, Carbon $now): array
    {
        $canClock = $user->canDo('timesheets.create')
            || $user->canDo('shifts.viewAssigned')
            || $user->canDo('shifts.update')
            || $user->canDo('shifts.manageAny');

        $openSession = null;
        try {
            $openSession = HrAttendanceSession::query()
                ->with([
                    'shift.client:id,first_name,last_name,profile_photo_path',
                    'shift.serviceContext:id,name',
                    'shift.tasks',
                    'breakEvents',
                ])
                ->where('user_id', $user->id)
                ->open()
                ->latest('clock_in_at')
                ->first();
        } catch (\Throwable $e) {
            report($e);
            // Fail soft — home should still render without the clock card.
        }

        $openShift = $openSession?->shift;
        $openShiftTasks = $openShift?->tasks ?? collect();
        $openShiftTaskTotal = $openShiftTasks->count();
        $openShiftTaskDone = $openShiftTasks->where('is_completed', true)->count();
        $endOfShiftBlockers = $openSession
            ? app(AttendanceService::class)->getEndOfShiftBlockers($openSession)
            : [];

        return [
            'can_clock' => $canClock,
            'open_session' => $openSession ? [
                'id' => $openSession->id,
                'clock_in_at' => optional($openSession->clock_in_at)->toIso8601String(),
                'shift_id' => $openSession->shift_id,
                'client_name' => $openSession->shift?->client
                    ? trim($openSession->shift->client->first_name.' '.$openSession->shift->client->last_name)
                    : null,
                'client_photo_url' => $openSession->shift?->client?->profile_photo_url,
                'shift_starts_at' => optional($openSession->shift?->starts_at)->toIso8601String(),
                'shift_ends_at' => optional($openSession->shift?->ends_at)->toIso8601String(),
                'location' => $openSession->shift?->location ?? $openSession->location,
                'service_type' => $openShift?->serviceContext?->name,
                'break_started_at' => optional($openSession->break_started_at)->toIso8601String(),
                'break_minutes' => (int) $openSession->break_minutes,
                'break_count' => (int) $openSession->break_count,
                'is_on_break' => (bool) $openSession->break_started_at,
                'tasks' => $openShiftTasks->map(fn ($task) => [
                    'id' => $task->id,
                    'label' => $task->label,
                    'scheduled_time' => ShiftTaskSupport::normalizeTime($task->scheduled_time),
                    'scheduled_for' => $openShift ? $task->setRelation('shift', $openShift)->scheduledFor()?->toIso8601String() : null,
                    'is_completed' => (bool) $task->is_completed,
                    'completed_at' => $task->completed_at?->toIso8601String(),
                ])->values()->all(),
                'task_progress' => $openShiftTaskTotal > 0
                    ? round(($openShiftTaskDone / $openShiftTaskTotal) * 100)
                    : 100,
                'quick_action_urls' => [
                    'incident' => $openSession->shift_id
                        ? '/incidents/create?shift_id='.$openSession->shift_id
                        : '/incidents',
                    'emar' => $openShift?->client_id
                        ? EmarUrl::mar($openShift->client_id, $now->toDateString())
                        : '/meds/today',
                    'escalate' => $openSession->shift_id
                        ? '/control-room?shift_id='.$openSession->shift_id
                        : '/control-room',
                ],
                'handover_submitted' => $openSession->shift_id
                    ? $this->hasSubmittedHandoverForShift((int) $openSession->shift_id, $user)
                    : false,
                'end_of_shift_blockers' => $endOfShiftBlockers,
                'end_of_shift_ready' => $endOfShiftBlockers === [],
                'can_force_clinical_blockers' => $user->canDo('shifts.manageAny')
                    || $user->canDo('timesheets.manageAny')
                    || $user->canDo('clients.update'),
            ] : null,
        ];
    }

    /**
     * Find the most recent submitted handover that the arriving worker should
     * read before starting this shift. Looks for a submitted handover either
     * explicitly targeted at this incoming shift, or — if nothing matches
     * directly — the most recent submitted handover for the same client from
     * the last 24 hours. Never returns acknowledged handovers (they've been
     * read) so this prompt only appears once.
     */
    private function findIncomingHandover(User $user, Shift $activeShift): ?array
    {
        try {
            $handover = ShiftHandover::query()
                ->tap(fn ($query) => $this->siteAccess->applyHandoverScope($query, $user))
                ->where('status', ShiftHandoverService::STATUS_SUBMITTED)
                ->where(function ($q) use ($activeShift, $user) {
                    $q->where('incoming_shift_id', $activeShift->id)
                        ->orWhere(function ($nested) use ($activeShift, $user) {
                            $nested->whereNull('incoming_shift_id')
                                ->where(function ($inner) use ($user) {
                                    $inner->where('incoming_staff_id', $user->id)
                                        ->orWhereNull('incoming_staff_id');
                                })
                                ->when($activeShift->client_id, fn ($c) => $c->where('client_id', $activeShift->client_id))
                                ->where('created_at', '>=', now()->subHours(24));
                        });
                })
                ->with([
                    'outgoingStaff:id,name',
                    'client:id,first_name,last_name',
                    'outgoingShift:id,ends_at',
                ])
                ->latest('submitted_at')
                ->latest('id')
                ->first();

            if (! $handover) {
                return null;
            }

            return [
                'id' => $handover->id,
                'handover_notes' => $handover->handover_notes,
                'client_mood' => $handover->client_mood,
                'medications_due' => $handover->medications_due ?? [],
                'incidents_to_note' => $handover->incidents_to_note ?? [],
                'follow_up_items' => $handover->follow_up_items ?? [],
                'submitted_at' => optional($handover->submitted_at)->toIso8601String(),
                'outgoing_staff_name' => $handover->outgoingStaff?->name,
                'outgoing_shift_ends_at' => optional($handover->outgoingShift?->ends_at)->toIso8601String(),
                'client_name' => $handover->client
                    ? trim($handover->client->first_name.' '.$handover->client->last_name)
                    : null,
            ];
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }

    private function buildDigestHandover(?array $handover): ?array
    {
        if (! $handover) {
            return null;
        }

        $outgoingName = $handover['outgoing_staff_name'] ?? null;

        return [
            'id' => $handover['id'] ?? null,
            'from' => $outgoingName ? [
                'name' => $outgoingName,
                'initials' => $this->initialsFromName($outgoingName),
                'hue' => ResidentHue::for('staff:'.$outgoingName),
                'role' => 'Previous shift',
            ] : null,
            'summary' => $handover['handover_notes'] ?? null,
            'flags' => $this->handoverDigestFlags($handover),
            'unread' => true,
            'recorded_at' => $handover['submitted_at'] ?? null,
        ];
    }

    private function handoverDigestFlags(array $handover): array
    {
        $flags = [];
        foreach (($handover['medications_due'] ?? []) as $item) {
            $label = $this->handoverItemLabel($item, 'Medication follow-up');
            if ($label) {
                $flags[] = ['tone' => 'warn', 'label' => $label];
            }
        }

        foreach (($handover['incidents_to_note'] ?? []) as $item) {
            $label = $this->handoverItemLabel($item, 'Incident noted');
            if ($label) {
                $flags[] = ['tone' => 'warn', 'label' => $label];
            }
        }

        foreach (($handover['follow_up_items'] ?? []) as $item) {
            $label = $this->handoverItemLabel($item, 'Follow-up needed');
            if ($label) {
                $flags[] = ['tone' => 'info', 'label' => $label];
            }
        }

        return array_slice($flags, 0, 6);
    }

    private function handoverItemLabel(mixed $item, string $fallback): ?string
    {
        if (! is_array($item)) {
            return $fallback;
        }

        foreach (['label', 'title', 'type', 'status'] as $key) {
            $value = trim((string) ($item[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return $fallback;
    }

    private function initialsFromName(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];
        $first = $parts[0] ?? '';
        $last = count($parts) > 1 ? $parts[count($parts) - 1] : '';

        return ResidentHue::initials($first, $last ?: null) ?: mb_strtoupper(mb_substr($name, 0, 1));
    }

    /**
     * Whether the worker has already submitted a handover for the shift tied
     * to their open attendance session. Used to suppress the clock-out write
     * prompt once a handover has been captured.
     */
    private function hasSubmittedHandoverForShift(int $shiftId, User $user): bool
    {
        try {
            return ShiftHandover::query()
                ->tap(fn ($query) => $this->siteAccess->applyHandoverScope($query, $user))
                ->where('outgoing_shift_id', $shiftId)
                ->whereIn('status', [
                    ShiftHandoverService::STATUS_SUBMITTED,
                    ShiftHandoverService::STATUS_ACKNOWLEDGED,
                ])
                ->exists();
        } catch (\Throwable $e) {
            report($e);

            return false;
        }
    }

    private function getShifts(User $user, Carbon $today, Carbon $tomorrowEnd, Carbon $workerNow): Collection
    {
        try {
            return Shift::where('user_id', $user->id)
                ->visibleToFrontline()
                ->whereBetween('starts_at', [$today, $tomorrowEnd])
                ->with(['client:id,first_name,last_name,profile_photo_path', 'serviceContext:id,name', 'tasks'])
                ->orderBy('starts_at')
                ->get()
                ->map(function (Shift $shift) use ($workerNow) {
                    return MyShiftResource::fromShift($shift, $workerNow);
                });
        } catch (\Throwable $e) {
            report($e);

            return collect();
        }
    }

    private function getMedicationsDue(
        array $clientIds,
        Carbon $now,
        bool $canRecord,
        bool $canRecordControlled,
        bool $canAccessControlled,
        bool $canOpenEmar,
    ): array {
        if (empty($clientIds)) {
            return [];
        }

        try {
            $scheduleService = app(MarScheduleService::class);
            $windowStart = $now->copy()->subHours(2);
            $windowEnd = $now->copy()->addHours(4);

            $medications = ClientMedication::whereIn('client_id', $clientIds)
                ->active()
                ->where('is_prn', false)
                ->when(! $canAccessControlled, fn ($query) => $query->where('controlled_drug', false))
                ->where(function ($query) {
                    $query->whereNotNull('dose_times')
                        ->orWhereNotNull('frequency');
                })
                ->with('client:id,first_name,last_name')
                ->get();

            // One administration query for the whole window, matched in memory
            // per slot — replaces the old per-dose-slot query (an N+1 that
            // re-ran every 60s with the /my-day live refresh).
            $administrations = $scheduleService->administrationsForWindow($clientIds, $windowStart, $windowEnd);

            $result = [];

            foreach ($medications as $med) {
                $day = $windowStart->copy()->startOfDay();
                $lastDay = $windowEnd->copy()->startOfDay();

                while ($day->lessThanOrEqualTo($lastDay)) {
                    foreach ($scheduleService->scheduledTimesForDate($med, $day) as $scheduled) {
                        if ($scheduled->lt($windowStart) || $scheduled->gt($windowEnd)) {
                            continue;
                        }

                        $scheduledIso = $scheduled->toIso8601String();
                        $snoozeKey = sprintf(
                            'my-day.med-snooze.user-%d.med-%d.%s',
                            auth()->id(),
                            $med->id,
                            $scheduledIso,
                        );

                        if (Cache::has($snoozeKey)) {
                            continue;
                        }

                        $administration = $administrations->get(
                            $scheduleService->slotKey((int) $med->client_id, (int) $med->id, $scheduled),
                        );

                        if ($administration && in_array($administration->status, ['given', 'refused', 'withheld'], true)) {
                            $status = $administration->status;
                        } elseif ($scheduled->lt($now)) {
                            $status = 'overdue';
                        } elseif ($scheduled->lte($now->copy()->addHour())) {
                            $status = 'due';
                        } else {
                            $status = 'upcoming';
                        }

                        $clientName = $med->client
                            ? trim($med->client->first_name.' '.$med->client->last_name)
                            : 'Unknown';

                        $result[] = [
                            // Compound id: medication + dose-time slot. Stable per
                            // dose-row so the front-end can key rows and target
                            // mutations (administer/refuse/snooze) at the right
                            // occurrence. A medication with two in-window doses
                            // (e.g. Paracetamol 09:00 + 13:00) yields distinct ids.
                            // `medication_id` carries the bare ClientMedication id
                            // the action endpoints still resolve via route-model
                            // binding — the occurrence is addressed by that id plus
                            // `scheduled_for`.
                            'id' => $med->id.':'.$scheduledIso,
                            'medication_id' => $med->id,
                            'client_id' => $med->client_id,
                            'client_name' => $clientName,
                            'medication_name' => $med->name,
                            'dose' => $med->dosage,
                            'route' => $med->route ?? 'Oral',
                            'flag' => $med->is_prn ? 'PRN' : null,
                            'is_controlled' => (bool) $med->controlled_drug,
                            'can_record' => $canRecord
                                && (! $med->controlled_drug || $canRecordControlled),
                            // My Day has no authenticated second-checker flow;
                            // controlled doses must be given from an eMAR surface.
                            'can_give' => $canRecord && ! $med->controlled_drug,
                            'scheduled_for' => $scheduledIso,
                            'status' => $status,
                            'emar_url' => $canOpenEmar
                                ? EmarUrl::mar($med->client_id, $scheduled->toDateString())
                                : null,
                        ];
                    }

                    $day->addDay();
                }
            }

            // Sort: overdue first, then due, then upcoming
            usort($result, function ($a, $b) {
                $order = ['overdue' => 0, 'due' => 1, 'upcoming' => 2, 'given' => 3, 'refused' => 4, 'withheld' => 5];

                return ($order[$a['status']] ?? 3) <=> ($order[$b['status']] ?? 3);
            });

            return $result;
        } catch (\Throwable $e) {
            report($e);

            return [];
        }
    }

    private function getNextShiftBriefing(
        User $user,
        Carbon $workerNow,
        bool $canOpenEmar,
        bool $canAccessControlled,
    ): ?array {
        try {
            // 36-hour lookahead — wide enough for the desktop "Tomorrow" panel
            // (a shift starting at 07:30 tomorrow is ~22h away when viewed
            // late-morning today) but narrow enough that "next week's shift"
            // doesn't bleed into the briefing card.
            $shift = Shift::query()
                ->where('user_id', $user->id)
                ->visibleToFrontline()
                ->whereIn('status', ['scheduled', 'draft'])
                ->where('starts_at', '<=', $workerNow->copy()->addHours(36)->utc())
                ->where('ends_at', '>=', $workerNow->copy()->utc())
                ->with([
                    'client:id,first_name,last_name,profile_photo_path',
                    'serviceContext:id,name',
                    'tasks',
                ])
                ->orderBy('starts_at')
                ->first();

            if (! $shift) {
                return null;
            }

            $briefing = MyShiftResource::fromShift($shift, $workerNow);
            $startsAt = $shift->starts_at?->copy()->timezone($workerNow->getTimezone());
            $briefing['minutes_until_start'] = $startsAt
                ? (int) floor($workerNow->diffInMinutes($startsAt, false))
                : null;
            $briefing['incoming_handover'] = $this->findIncomingHandover($user, $shift);
            $briefing['medications_due_during_shift'] = (
                $user->canDo('medications.view')
                || $user->canDo('medications.administer.record')
            )
                ? $this->getShiftMedicationsDue($shift, $workerNow, $canOpenEmar, $canAccessControlled)
                : [];
            $briefing['what_to_know'] = $shift->notes;

            return $briefing;
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }

    private function getPreviousShift(User $user, Carbon $workerNow): ?array
    {
        try {
            $shift = Shift::query()
                ->where('user_id', $user->id)
                ->visibleToFrontline()
                ->where(function ($query) use ($workerNow) {
                    $query->where('actual_ends_at', '>=', $workerNow->copy()->subHours(12)->utc())
                        ->orWhere(function ($fallback) use ($workerNow) {
                            $fallback->whereNull('actual_ends_at')
                                ->where('ends_at', '>=', $workerNow->copy()->subHours(12)->utc());
                        });
                })
                ->where(function ($query) use ($workerNow) {
                    $query->whereIn('status', ['completed', 'clocked_out', 'finished'])
                        ->orWhere('actual_ends_at', '<=', $workerNow->copy()->utc());
                })
                ->with([
                    'client:id,first_name,last_name,profile_photo_path',
                    'serviceContext:id,name',
                    'tasks',
                    'timesheets' => fn ($query) => $query->latest('updated_at'),
                    'outgoingHandovers' => function ($relation) use ($user): void {
                        $this->siteAccess
                            ->applyHandoverScope($relation->getQuery(), $user)
                            ->select(['id', 'outgoing_shift_id', 'status', 'submitted_at']);
                    },
                ])
                ->orderByDesc('actual_ends_at')
                ->orderByDesc('ends_at')
                ->first();

            if (! $shift) {
                return null;
            }

            $summary = MyShiftResource::fromShift($shift, $workerNow);
            $summary['handover_sent'] = $shift->outgoingHandovers->contains(
                fn (ShiftHandover $handover) => in_array($handover->status, [
                    ShiftHandoverService::STATUS_SUBMITTED,
                    ShiftHandoverService::STATUS_ACKNOWLEDGED,
                ], true),
            );

            return $summary;
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }

    private function getShiftMedicationsDue(
        Shift $shift,
        Carbon $workerNow,
        bool $canOpenEmar,
        bool $canAccessControlled,
    ): array {
        if (! $shift->client_id || ! $shift->starts_at || ! $shift->ends_at) {
            return [];
        }

        try {
            $scheduleService = app(MarScheduleService::class);
            $start = $shift->starts_at->copy()->timezone($workerNow->getTimezone());
            $end = $shift->ends_at->copy()->timezone($workerNow->getTimezone());

            return ClientMedication::query()
                ->where('client_id', $shift->client_id)
                ->active()
                ->where('is_prn', false)
                ->when(! $canAccessControlled, fn ($query) => $query->where('controlled_drug', false))
                ->where(function ($query) {
                    $query->whereNotNull('dose_times')
                        ->orWhereNotNull('frequency');
                })
                ->get()
                ->flatMap(function (ClientMedication $medication) use ($start, $end, $scheduleService, $canOpenEmar) {
                    $items = [];
                    $day = $start->copy()->startOfDay();
                    $lastDay = $end->copy()->startOfDay();

                    while ($day->lessThanOrEqualTo($lastDay)) {
                        foreach ($scheduleService->scheduledTimesForDate($medication, $day) as $scheduled) {
                            if ($scheduled->betweenIncluded($start, $end)) {
                                $items[] = [
                                    'medication_name' => $medication->name,
                                    'dose' => $medication->dosage,
                                    'scheduled_for' => $scheduled->toIso8601String(),
                                    'emar_url' => $canOpenEmar
                                        ? EmarUrl::mar($medication->client_id, $scheduled->toDateString())
                                        : null,
                                ];
                            }
                        }

                        $day->addDay();
                    }

                    return $items;
                })
                ->sortBy('scheduled_for')
                ->values()
                ->take(6)
                ->all();
        } catch (\Throwable $e) {
            report($e);

            return [];
        }
    }

    /**
     * Return the guided-round summary for whatever round the worker should
     * focus on right now, or null if nothing relevant is assigned.
     *
     * Priority:
     *   1. Rounds still in_progress for today that this user started or was
     *      assigned to (so resuming always wins).
     *   2. Pending rounds assigned to this user whose window overlaps "now".
     *
     * Progress numbers come from GuidedRoundService — the same source used by
     * the guided page itself — so the banner never disagrees with what the
     * worker sees when they tap it.
     */
    private function getActiveRound(User $user, Carbon $now): ?array
    {
        if (! $user->canDo('medications.administer.record')) {
            return null;
        }

        try {
            $siteIds = $this->siteAccess->accessibleSiteIds(
                $user,
                MedicationGovernanceScopeService::SITE_BYPASS_PERMISSIONS,
            );
            if ($siteIds === []) {
                return null;
            }

            $round = MedicationRound::query()
                ->whereNotNull('site_id')
                ->whereIn('site_id', $siteIds)
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

            $service = app(GuidedRoundService::class);
            $progress = $service->progress(
                $round,
                $user->canDo('medications.controlled.view') || $user->canDo('medications.controlled.record'),
            );

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
     * The signed-in worker's live Lone Worker Safety session, if any.
     *
     * Returns the most recent session in a live state (active / overdue /
     * emergency) where the user is the monitored worker — the data behind the
     * My Day "You're being monitored — check in" card. Null when the worker is
     * not currently being monitored (the card is hidden entirely).
     *
     * `next_check_in_at` / `is_check_in_overdue` are derived in PHP from the
     * model's UTC-stored Carbons (never SQL NOW(), which can sit in a different
     * timezone and skew the comparison — the same trap the coordinator hero hit).
     */
    private function getActiveLoneWorkerSession(User $user): ?array
    {
        try {
            $session = LoneWorkerSession::query()
                ->where('user_id', $user->id)
                ->whereIn('status', ['active', 'overdue', 'emergency'])
                ->with(['site:id,name', 'client:id,first_name,last_name'])
                ->latest('started_at')
                ->first();

            if (! $session) {
                return null;
            }

            $base = $session->last_check_in_at ?? $session->started_at;
            $nextCheckInAt = $base
                ? $base->copy()->addMinutes((int) $session->check_in_interval_minutes)
                : null;

            return [
                'id' => $session->id,
                'status' => $session->status,
                'started_at' => optional($session->started_at)->toIso8601String(),
                'expected_end_at' => optional($session->expected_end_at)->toIso8601String(),
                'last_check_in_at' => optional($session->last_check_in_at)->toIso8601String(),
                'check_in_interval_minutes' => (int) $session->check_in_interval_minutes,
                'next_check_in_at' => optional($nextCheckInAt)->toIso8601String(),
                // Overdue when the 5-min job has already flipped it, or the next
                // check-in window has lapsed on a still-active session.
                'is_check_in_overdue' => $session->status === 'overdue' || $session->isCheckInOverdue(),
                'activity_description' => $session->activity_description,
                'site' => $session->site
                    ? ['id' => $session->site->id, 'name' => $session->site->name]
                    : null,
                'client' => $session->client
                    ? ['id' => $session->client->id, 'name' => trim($session->client->first_name.' '.$session->client->last_name)]
                    : null,
            ];
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }

    /**
     * Open first-aid follow-ups assigned to the signed-in worker.
     *
     * Read-only digest data for the My Day "First-aid follow-ups assigned to
     * me" card. Returns uncompleted FirstAidFollowup rows (whose parent record
     * still exists) ordered by due date, each with a deep-link into the First
     * Aid Register's record modal. Fail-soft — the home renders without the
     * card if anything throws.
     */
    private function getFirstAidFollowups(User $user): array
    {
        try {
            return FirstAidFollowup::query()
                ->where('assigned_to_user_id', $user->id)
                ->whereNull('completed_at')
                ->whereHas('record')
                ->with([
                    'record:id,treated_person_name,site_id,treatment_date,injury_illness_type',
                    'record.site:id,name',
                ])
                ->orderBy('due_at')
                ->limit(10)
                ->get()
                ->map(fn ($f) => [
                    'id' => $f->id,
                    'notes' => $f->notes,
                    'due_at' => $f->due_at?->toIso8601String(),
                    'is_overdue' => $f->due_at ? $f->due_at->isPast() : false,
                    'record_id' => $f->first_aid_record_id,
                    'treated_person_name' => $f->record?->treated_person_name,
                    'site_name' => $f->record?->site?->name,
                    'url' => '/health-safety/first-aid?record='.$f->first_aid_record_id,
                ])
                ->all();
        } catch (\Throwable $e) {
            report($e);

            return [];
        }
    }

    /**
     * The signed-in worker's own active PPE allocations that still need attention —
     * unacknowledged, or an RPE item missing a fit-test (AS/NZS 1715). Empty when
     * nothing is outstanding so the card hides. Read-only over the worker's own rows.
     *
     * @return array<int, array<string, mixed>>
     */
    private function getMyPpe(User $user): array
    {
        try {
            return PpeAllocation::query()
                ->where('user_id', $user->id)
                ->whereNull('returned_at')
                ->where(function ($q) {
                    $q->where('acknowledged', false)
                        ->orWhere(fn ($r) => $r->where('fit_test_completed', false)
                            ->whereHas('ppeInventory.ppeType', fn ($t) => $t->where('category', 'respiratory')));
                })
                ->with(['ppeInventory.ppeType:id,name,category', 'ppeInventory.site:id,name'])
                ->latest('allocated_at')
                ->get()
                ->map(function (PpeAllocation $a) {
                    $item = $a->ppeInventory;
                    $isRpe = $item?->ppeType?->category === 'respiratory';

                    return [
                        'id' => $a->id,
                        'type_name' => $item?->ppeType?->name ?? 'PPE',
                        'category' => $item?->ppeType?->category,
                        'serial_number' => $item?->serial_number,
                        'site' => $item?->site?->name,
                        'allocated_at' => optional($a->allocated_at)->toIso8601String(),
                        'acknowledged' => (bool) $a->acknowledged,
                        'fit_test_required' => $isRpe,
                        'fit_test_completed' => (bool) $a->fit_test_completed,
                    ];
                })
                ->all();
        } catch (\Throwable $e) {
            report($e);

            return [];
        }
    }

    /**
     * The signed-in user's open work items from the company-wide /tasks
     * aggregator (assigned=me), for the My Day "My tasks" card. Permission-
     * scoped exactly like /tasks — the aggregator runs as this user. Capped
     * at 8 rows; `total` is the uncapped open count. Fail-soft: the home
     * renders without the card if a provider throws.
     *
     * @return array{total: int, items: array<int, array<string, mixed>>}
     */
    private function getMyAggregatedTasks(User $user): array
    {
        try {
            $aggregator = app(TaskAggregator::class);
            $items = $aggregator->filterItems(
                $aggregator->itemsFor($user),
                $user,
                ['assigned' => 'me'],
            );
            // itemsFor() excludes done items by default; keep a guard anyway.
            $items = array_values(array_filter($items, fn (TaskItem $i) => $i->bucket !== TaskItem::BUCKET_DONE));

            return [
                'total' => count($items),
                'items' => array_map(fn (TaskItem $i) => [
                    'id' => $i->id,
                    'ref' => $i->ref,
                    'title' => $i->title,
                    'severity' => $i->severity,
                    'dueAt' => $i->dueAt,
                    'overdue' => $i->isOverdue(),
                    'link' => $i->link,
                    'sourceLabel' => $i->sourceLabel,
                ], array_slice($items, 0, 8)),
            ];
        } catch (\Throwable $e) {
            report($e);

            return ['total' => 0, 'items' => []];
        }
    }

    private function getTimesheets(int $userId): array
    {
        try {
            return Timesheet::where('user_id', $userId)
                ->whereIn('status', ['draft', 'submitted', 'returned'])
                ->with([
                    'client:id,first_name,last_name',
                    'clientAllocations',
                    // Eligible-client roster for the per-client allocation popup
                    // (residents at the shift's site, plus any explicit group
                    // pivot rows when the dormant schema starts being used).
                    'shift.site.clients:id,site_id,first_name,last_name',
                ])
                ->orderByDesc('work_date')
                ->limit(10)
                ->get()
                ->map(function (Timesheet $ts) {
                    $clientName = $ts->client
                        ? trim($ts->client->first_name.' '.$ts->client->last_name)
                        : null;

                    // The popup needs to know which residents/clients the
                    // worker may attribute time to. Combine the timesheet's
                    // primary client + the site's residents into a
                    // deduplicated roster keyed by id.
                    $candidatesById = [];
                    if ($ts->client) {
                        $candidatesById[$ts->client->id] = [
                            'id' => (int) $ts->client->id,
                            'name' => $clientName,
                            'is_primary' => true,
                        ];
                    }
                    $siteClients = $ts->shift?->site?->clients ?? collect();
                    foreach ($siteClients as $sc) {
                        if (! isset($candidatesById[$sc->id])) {
                            $candidatesById[$sc->id] = [
                                'id' => (int) $sc->id,
                                'name' => trim($sc->first_name.' '.$sc->last_name),
                                'is_primary' => false,
                            ];
                        }
                    }

                    return [
                        'id' => $ts->id,
                        'work_date' => Carbon::parse($ts->work_date)->format('D, j M Y'),
                        'work_date_iso' => Carbon::parse($ts->work_date)->toDateString(),
                        'client_name' => $clientName,
                        'client_id' => $ts->client_id,
                        'hours' => $ts->total_hours,
                        'status' => $ts->status,
                        'return_notes' => $ts->returned_notes,
                        'starts_at' => $ts->starts_at?->toIso8601String(),
                        'ends_at' => $ts->ends_at?->toIso8601String(),
                        'break_minutes' => (int) ($ts->break_minutes ?? 0),
                        'mileage_km' => $ts->mileage_km !== null ? (float) $ts->mileage_km : null,
                        'notes' => $ts->notes,
                        'is_residential_billable' => (bool) $ts->is_residential_billable,
                        'can_edit_inline' => in_array($ts->status, ['draft', 'returned'], true)
                            && ! $ts->is_protected_from_changes,
                        // Multi-client allocation breakdown (see
                        // `database/migrations/2026_05_23_000010_create_timesheet_client_allocations_table.php`).
                        // `effectiveClientAllocations` returns a synthesised
                        // single-row representation when no allocations have
                        // been saved yet, so the front-end always has a
                        // consistent shape.
                        'client_allocations' => $ts->effectiveClientAllocations()->all(),
                        'allocation_method' => $ts->dominantAllocationMethod(),
                        // Eligible client roster the worker can attribute time to.
                        'clients_candidates' => array_values($candidatesById),
                    ];
                })
                ->all();
        } catch (\Throwable $e) {
            report($e);

            return [];
        }
    }

    private function getIncidents(int $userId, Carbon $now): array
    {
        try {
            return ClientIncident::where('reported_by', $userId)
                ->whereNotIn('status', ['closed'])
                ->where('occurred_at', '>=', $now->copy()->subDays(14))
                ->with('client:id,first_name,last_name')
                ->orderByDesc('occurred_at')
                ->get()
                ->map(function (ClientIncident $incident) {
                    $clientName = $incident->client
                        ? trim($incident->client->first_name.' '.$incident->client->last_name)
                        : null;

                    return [
                        'id' => $incident->id,
                        'title' => $incident->title,
                        // Single-line summary for the Needs You digest row.
                        // ClientIncident::description is free-text so we
                        // truncate to avoid overflowing the right column.
                        'description' => $incident->description
                            ? Str::limit(trim((string) $incident->description), 140)
                            : null,
                        'client_name' => $clientName,
                        'severity' => $incident->severity,
                        'status' => $incident->status,
                        'occurred_at' => $incident->occurred_at?->toIso8601String(),
                        'url' => '/incidents/'.$incident->id,
                        'requires_followup' => (bool) $incident->requires_followup,
                    ];
                })
                ->all();
        } catch (\Throwable $e) {
            report($e);

            return [];
        }
    }

    private function getCrTasks(User $user): array
    {
        $userId = (int) $user->id;
        $tasks = collect()
            ->merge($this->getAlertTasks($user))
            ->merge($this->getFollowupTasks($userId))
            ->merge($this->getNoteFollowupTasks($userId));

        return $tasks->sort(function ($a, $b) {
            $aPriority = self::PRIORITY_ORDER[$a['priority']] ?? 3;
            $bPriority = self::PRIORITY_ORDER[$b['priority']] ?? 3;

            if ($aPriority !== $bPriority) {
                return $aPriority - $bPriority;
            }

            if ($a['due_at'] === null && $b['due_at'] === null) {
                return 0;
            }
            if ($a['due_at'] === null) {
                return 1;
            }
            if ($b['due_at'] === null) {
                return -1;
            }

            return Carbon::parse($a['due_at'])->timestamp - Carbon::parse($b['due_at'])->timestamp;
        })->values()->all();
    }

    /**
     * One-line summary used as the open-item description.
     *
     * Preference order: free-text notes → context-driven summary
     * (e.g. fall detection location, asset+source pairing) → null. Always
     * truncated to 140 chars so the digest row stays single-line.
     */
    private static function summariseAlert(ControlRoomAlert $alert): ?string
    {
        $notes = is_string($alert->notes) ? trim($alert->notes) : '';
        if ($notes !== '') {
            return Str::limit($notes, 140);
        }

        $context = is_array($alert->context) ? $alert->context : [];
        // Common context keys observed across alert sources.
        $candidates = array_filter([
            $context['summary'] ?? null,
            $context['description'] ?? null,
            $context['message'] ?? null,
            $context['detail'] ?? null,
            $context['location'] ?? null,
        ], fn ($v) => is_string($v) && trim($v) !== '');

        if (! empty($candidates)) {
            return Str::limit(trim((string) reset($candidates)), 140);
        }

        // Fall back to a tiny composed summary so the row never lies blank.
        $assetName = $alert->asset?->name;
        $source = $alert->source;
        if ($assetName && $source) {
            return Str::limit("{$source} · {$assetName}", 140);
        }
        if ($assetName) {
            return Str::limit($assetName, 140);
        }

        return null;
    }

    private function getAlertTasks(User $user): array
    {
        try {
            $now = now();
            $currentUser = User::query()->find($user->id);
            if (! $currentUser) {
                return [];
            }
            $query = ControlRoomAlert::query()
                ->where('assigned_to_user_id', $currentUser->id);
            $this->siteAccess->applyAlertScope($query, $currentUser);

            return $query
                ->unresolved()
                // PR 17 — hide snoozed alerts from /my-day until the window
                // elapses. The alert row remains fully live on the CR side.
                ->where(function ($q) use ($now) {
                    $q->whereNull('snoozed_until')->orWhere('snoozed_until', '<=', $now);
                })
                ->with(['asset:id,name', 'client:id,first_name,last_name', 'sla'])
                ->get()
                ->map(function (ControlRoomAlert $alert) {
                    $clientName = $alert->client
                        ? trim($alert->client->first_name.' '.$alert->client->last_name)
                        : null;

                    $sla = $alert->sla?->isApplicable() ? $alert->sla : null;
                    $slaStatus = null;
                    if ($sla) {
                        if ($sla->response_breached) {
                            $slaStatus = 'breached';
                        } elseif ($sla->response_deadline && $sla->response_deadline->lt(now()->addMinutes(15))) {
                            $slaStatus = 'at_risk';
                        } else {
                            $slaStatus = 'on_track';
                        }
                    }

                    $severity = strtolower((string) ($alert->severity ?? 'medium'));
                    $canAck = in_array($alert->status, [
                        ControlRoomAlert::STATUS_OPEN,
                    ], true);
                    $canSnooze = ! $alert->isTerminal() && $severity !== 'critical';

                    return [
                        'id' => 'alert-'.$alert->id,
                        'type' => 'alert',
                        'title' => $alert->alert_type,
                        // PR – /my-day desktop redesign: surface a one-line
                        // description on each open item so workers don't have
                        // to click through to read what the alert is about.
                        // Falls back to a context-derived summary when the
                        // alert has no free-text notes.
                        'description' => self::summariseAlert($alert),
                        'priority' => $alert->severity ?? 'medium',
                        'status' => $alert->status,
                        'source_url' => '/control-room/alerts/'.$alert->id,
                        'due_at' => $sla?->response_deadline?->toIso8601String(),
                        'created_at' => $alert->triggered_at?->toIso8601String() ?? $alert->created_at->toIso8601String(),
                        'meta' => [
                            'source' => $alert->source,
                            'client_name' => $clientName,
                            'sla_status' => $slaStatus,
                            'asset_name' => $alert->asset?->name,
                            'alert_id' => $alert->id,
                            'can_ack' => $canAck,
                            'can_snooze' => $canSnooze,
                        ],
                    ];
                })
                ->all();
        } catch (\Throwable $e) {
            report($e);

            return [];
        }
    }

    private function getFollowupTasks(int $userId): array
    {
        try {
            return IncidentFollowup::where('assigned_to_user_id', $userId)
                ->whereNull('completed_at')
                ->with(['incident.client:id,first_name,last_name'])
                ->get()
                ->map(function (IncidentFollowup $followup) {
                    $incident = $followup->incident;
                    $clientName = $incident?->client
                        ? trim($incident->client->first_name.' '.$incident->client->last_name)
                        : null;

                    // Prefer the followup's own action text, fall back to a
                    // snippet of the parent incident so the row carries real
                    // detail rather than just a generic title.
                    $description = null;
                    foreach (['action_required', 'detail', 'notes', 'description'] as $key) {
                        $value = $followup->{$key} ?? null;
                        if (is_string($value) && trim($value) !== '') {
                            $description = Str::limit(trim($value), 140);
                            break;
                        }
                    }
                    if ($description === null && $incident?->description) {
                        $description = Str::limit(trim((string) $incident->description), 140);
                    }

                    return [
                        'id' => 'followup-'.$followup->id,
                        'type' => 'followup',
                        'title' => 'Incident follow-up: '.($incident?->title ?? 'Unknown incident'),
                        'description' => $description,
                        'priority' => $incident?->severity ?? 'medium',
                        'status' => 'pending',
                        'source_url' => '/incidents/'.($followup->client_incident_id),
                        'due_at' => $followup->due_at?->toIso8601String(),
                        'created_at' => $followup->created_at->toIso8601String(),
                        'meta' => [
                            'client_name' => $clientName,
                        ],
                    ];
                })
                ->all();
        } catch (\Throwable $e) {
            report($e);

            return [];
        }
    }

    private function getNoteFollowupTasks(int $userId): array
    {
        try {
            return OperatorNote::where('user_id', $userId)
                ->where('requires_followup', true)
                ->get()
                ->map(function (OperatorNote $note) {
                    $sourceUrl = $note->alert_id
                        ? '/control-room/alerts/'.$note->alert_id
                        : '/control-room/shifts';

                    $content = trim((string) $note->content);

                    // Title stays as the short headline so cards align; the
                    // longer body becomes the row description.
                    return [
                        'id' => 'note-'.$note->id,
                        'type' => 'note_followup',
                        'title' => 'Follow-up: '.Str::limit($content, 60),
                        'description' => mb_strlen($content) > 60 ? Str::limit($content, 200) : null,
                        'priority' => 'medium',
                        'status' => 'pending',
                        'source_url' => $sourceUrl,
                        'due_at' => $note->followup_at?->toIso8601String(),
                        'created_at' => $note->created_at->toIso8601String(),
                        'meta' => [],
                    ];
                })
                ->values()
                ->all();
        } catch (\Throwable $e) {
            report($e);

            return [];
        }
    }

    private function getDigestNotifications(User $user): array
    {
        try {
            $workerNow = Carbon::now($this->workerTimezone());

            return $this->boardPackAccess
                ->visibleNotificationQuery($user, unreadOnly: true)
                ->latest()
                ->limit(5)
                ->get(['id', 'type', 'data', 'created_at'])
                ->map(function ($notification) use ($workerNow) {
                    $data = is_array($notification->data) ? $notification->data : [];
                    $createdAt = $this->digestNotificationCreatedAt($notification);

                    return [
                        'id' => (string) $notification->id,
                        'title' => $this->digestNotificationTitle($data, (string) $notification->type),
                        'at' => $this->digestNotificationAge($createdAt, $workerNow),
                        'tone' => $this->digestNotificationTone($data),
                    ];
                })
                ->values()
                ->all();
        } catch (\Throwable $e) {
            report($e);

            return [];
        }
    }

    private function digestNotificationTitle(array $data, string $type): string
    {
        foreach (['title', 'subject', 'headline', 'message', 'body', 'event_key'] as $key) {
            $value = $data[$key] ?? null;
            if (is_string($value) && trim($value) !== '') {
                $title = trim($value);

                if ($key === 'event_key') {
                    $title = Str::headline(str_replace(['.', '_', '-'], ' ', $title));
                }

                return Str::limit($title, 90);
            }
        }

        $fallback = class_basename($type);

        return Str::headline(str_replace('Notification', '', $fallback)) ?: 'Notification';
    }

    private function digestNotificationTone(array $data): string
    {
        $value = strtolower((string) ($data['tone'] ?? $data['priority'] ?? $data['severity'] ?? $data['kind'] ?? ''));

        if (in_array($value, ['critical', 'high', 'urgent', 'primary', 'warning'], true)) {
            return 'primary';
        }

        if (in_array($value, ['info', 'success', 'medium', 'low', 'operational'], true)) {
            return 'info';
        }

        return 'muted';
    }

    private function digestNotificationCreatedAt($notification): ?Carbon
    {
        $raw = method_exists($notification, 'getRawOriginal')
            ? $notification->getRawOriginal('created_at')
            : null;

        if ($raw) {
            return Carbon::parse((string) $raw, (string) config('app.timezone', 'UTC'))
                ->timezone($this->workerTimezone());
        }

        return $notification->created_at
            ? $notification->created_at->copy()->timezone($this->workerTimezone())
            : null;
    }

    private function digestNotificationAge(?Carbon $createdAt, Carbon $workerNow): string
    {
        if (! $createdAt) {
            return '';
        }

        $seconds = max(0, (int) $createdAt->diffInSeconds($workerNow, false));
        if ($seconds < 60) {
            return 'just now';
        }

        $minutes = intdiv($seconds, 60);
        if ($minutes < 60) {
            return $minutes.' '.Str::plural('minute', $minutes).' ago';
        }

        $hours = intdiv($minutes, 60);
        if ($hours < 24) {
            return $hours.' '.Str::plural('hour', $hours).' ago';
        }

        $days = intdiv($hours, 24);

        return $days.' '.Str::plural('day', $days).' ago';
    }

    private function workerTimezone(): string
    {
        return (string) config('app.worker_timezone', 'Pacific/Auckland');
    }
}
