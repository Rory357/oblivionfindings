<?php

namespace App\Http\Controllers;

use App\Domain\Hr\Models\HrAttendanceSession;
use App\Domain\Hr\Services\AttendanceService;
use App\Http\Resources\MyShiftResource;
use App\Models\ClientIncident;
use App\Models\ClientMedication;
use App\Models\ControlRoom\OperatorNote;
use App\Models\ControlRoomAlert;
use App\Models\IncidentFollowup;
use App\Models\MedicationRound;
use App\Models\Shift;
use App\Models\ShiftHandover;
use App\Models\ShiftOpenPosition;
use App\Models\Timesheet;
use App\Models\User;
use App\Models\Client;
use App\Services\GuidedRoundService;
use App\Services\ShiftHandoverService;
use App\Support\EmarUrl;
use App\Support\ResidentHue;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
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

    public function __invoke(Request $request): Response
    {
        abort_unless($request->user(), 403);

        $user = $request->user();
        $userId = $user->id;
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
        $activeShift = $this->resolveActiveShiftModel($user, $queryNow);
        $activeSitePayload = $this->buildActiveSitePayload($activeShift);

        // 3. Medications due — aggregate across every resident at the active
        //    site when present, otherwise fall back to the shifts' client list
        //    (preserves the legacy single-client behaviour).
        $clientIds = $activeSitePayload
            ? array_column($activeSitePayload['residents'], 'id')
            : $shifts->pluck('client.id')->filter()->unique()->values()->all();
        $medicationsDue = $this->getMedicationsDue($clientIds, $workerNow);

        // 4. Timesheets
        $timesheets = $this->getTimesheets($userId);

        // 5. Incidents
        $incidents = $this->getIncidents($userId, $queryNow);

        // 6. Tasks (CR alerts + followups + notes - existing aggregation)
        $tasks = $this->getCrTasks($userId);

        // 7. Leave
        $leave = $this->getLeave($userId, $workerNow);

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
            'notifications_unread' => $user->unreadNotifications()->count(),
        ];

        // 9. Manager check
        $isManager = $user->canDo('shifts.manageAny');

        // 10. Manager data
        $managerData = $isManager ? $this->getManagerData($user, $today, $tomorrowEnd) : null;

        // 11. Frontline clock state (PR 4)
        $clock = $this->getClockState($user, $queryNow);

        // 12. Active guided medication round (PR 9). Surfaces a resume / start
        //     banner on /my-day for the worker assigned to today's round.
        $activeRound = $this->getActiveRound($user, $workerNow);

        // 13. Shift lifecycle hero payloads.
        $nextShiftBriefing = $clock['open_session']
            ? null
            : $this->getNextShiftBriefing($user, $workerNow);
        $previousShift = $clock['open_session'] || $nextShiftBriefing
            ? null
            : $this->getPreviousShift($user, $workerNow);

        // 14. Active-shift card (site-first). The new /my-day hero reads this
        //     instead of the legacy `clock.open_session.shift`. The card
        //     mirrors MyShiftResource so the front-end can use one TS type.
        $activeShiftCard = $activeShift
            ? array_merge(
                MyShiftResource::fromShift($activeShift, $workerNow),
                ['site' => $activeSitePayload]
            )
            : null;

        return Inertia::render('my-day/index', [
            'today' => $todayFormatted,
            'today_iso' => $workerNow->toDateString(),
            'shifts' => $shifts->values()->all(),
            'medications_due' => $medicationsDue,
            'timesheets' => $timesheets,
            'incidents' => $incidents,
            'tasks' => $tasks,
            'stats' => $stats,
            'leave' => $leave,
            'pending_claims_count' => $pendingClaimsCount,
            'is_manager' => $isManager,
            'manager_data' => $managerData,
            'clock' => $clock,
            'active_round' => $activeRound,
            'active_shift' => $activeShiftCard,
            'next_shift_briefing' => $nextShiftBriefing,
            'previous_shift' => $previousShift,
            // Per-worker observation capabilities, used by the Vitals & obs
            // flow on /my-day to gate the observation-type list. We resolve
            // them here (rather than client-side via auth) because permission
            // resolution lives behind canDo() and shouldn't round-trip.
            'can_record_observation' => $user->canDo('clinical.observations.record'),
            'can_record_clinical' => $user->canDo('clinical.observations.recordClinical'),
            // Namespaced as `my_day_labels` so it does not collide with the
            // `labels` prop shared globally by HandleInertiaRequests for
            // terminology overrides (client.singular, etc.).
            'my_day_labels' => Lang::get('my-day'),
        ]);
    }

    /**
     * Find the worker's currently-relevant shift model (in progress or imminent),
     * eager-loading the site + all co-resident clients. Used by the new site-
     * first hero so the avatar stack and resident filter render the full house.
     *
     * Returns null when no in-progress / upcoming shift exists today.
     */
    private function resolveActiveShiftModel(User $user, Carbon $now): ?Shift
    {
        try {
            $todayStart = $now->copy()->startOfDay();
            $todayEnd = $now->copy()->endOfDay();

            // Prefer the shift bound to an open attendance session, falling
            // back to the next "in_progress" shift that overlaps now.
            $shift = Shift::query()
                ->where('user_id', $user->id)
                ->visibleToFrontline($user->organization_id)
                ->whereBetween('starts_at', [$todayStart, $todayEnd])
                ->orderByRaw("FIELD(status, 'in_progress', 'scheduled', 'draft')")
                ->orderBy('starts_at')
                ->with([
                    'client:id,first_name,last_name,profile_photo_path,site_id',
                    'site:id,name,type,address_line_1,address_line_2,suburb,city,postcode',
                    'site.clients:id,first_name,last_name,profile_photo_path,site_id,status',
                    'serviceContext:id,name',
                    'tasks',
                ])
                ->first();

            return $shift;
        } catch (\Throwable) {
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
    private function formatSiteAddress(\App\Models\Site $site): string
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
        $eligibleShifts = collect();
        $activeShift = null;

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

            $eligibleShifts = app(AttendanceService::class)
                ->eligibleShiftsForUser($user, $now)
                ->load('client:id,first_name,last_name');

            $activeShift = $eligibleShifts->count() === 1 ? $eligibleShifts->first() : null;
        } catch (\Throwable) {
            // Fail soft — home should still render without the clock card.
        }

        $shiftToCard = fn ($shift) => [
            'id' => $shift->id,
            'starts_at' => optional($shift->starts_at)->toIso8601String(),
            'ends_at' => optional($shift->ends_at)->toIso8601String(),
            'status' => $shift->status,
            'location' => $shift->location,
            'client_name' => $shift->client
                ? trim($shift->client->first_name.' '.$shift->client->last_name)
                : null,
        ];

        $activeShiftCard = $activeShift ? $shiftToCard($activeShift) : null;
        if ($activeShiftCard && $activeShift) {
            $activeShiftCard['incoming_handover'] = $this->findIncomingHandover($user, $activeShift);
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
                    ? $this->hasSubmittedHandoverForShift((int) $openSession->shift_id)
                    : false,
                'end_of_shift_blockers' => $endOfShiftBlockers,
                'end_of_shift_ready' => $endOfShiftBlockers === [],
            ] : null,
            'active_shift' => $activeShiftCard,
            'eligible_shifts' => $eligibleShifts->map($shiftToCard)->values()->all(),
            'eligible_shift_count' => $eligibleShifts->count(),
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
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Whether the worker has already submitted a handover for the shift tied
     * to their open attendance session. Used to suppress the clock-out write
     * prompt once a handover has been captured.
     */
    private function hasSubmittedHandoverForShift(int $shiftId): bool
    {
        try {
            return ShiftHandover::query()
                ->where('outgoing_shift_id', $shiftId)
                ->whereIn('status', [
                    ShiftHandoverService::STATUS_SUBMITTED,
                    ShiftHandoverService::STATUS_ACKNOWLEDGED,
                ])
                ->exists();
        } catch (\Throwable) {
            return false;
        }
    }

    private function getShifts(User $user, Carbon $today, Carbon $tomorrowEnd, Carbon $workerNow): \Illuminate\Support\Collection
    {
        try {
            return Shift::where('user_id', $user->id)
                ->visibleToFrontline($user->organization_id)
                ->whereBetween('starts_at', [$today, $tomorrowEnd])
                ->with(['client:id,first_name,last_name,profile_photo_path', 'serviceContext:id,name', 'tasks'])
                ->orderBy('starts_at')
                ->get()
                ->map(function (Shift $shift) use ($workerNow) {
                    return MyShiftResource::fromShift($shift, $workerNow);
                });
        } catch (\Throwable) {
            return collect();
        }
    }

    private function getMedicationsDue(array $clientIds, Carbon $now): array
    {
        if (empty($clientIds)) {
            return [];
        }

        try {
            $windowStart = $now->copy()->subHours(2);
            $windowEnd = $now->copy()->addHours(4);

            $medications = ClientMedication::whereIn('client_id', $clientIds)
                ->active()
                ->where('is_prn', false)
                ->whereNotNull('dose_times')
                ->with('client:id,first_name,last_name')
                ->get();

            $result = [];

            foreach ($medications as $med) {
                $doseTimes = $med->dose_times;
                if (empty($doseTimes) || ! is_array($doseTimes)) {
                    continue;
                }

                foreach ($doseTimes as $doseTime) {
                    $scheduled = $now->copy()->startOfDay()->setTimeFromTimeString($doseTime);

                    if ($scheduled->lt($windowStart) || $scheduled->gt($windowEnd)) {
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
                        ? trim($med->client->first_name.' '.$med->client->last_name)
                        : 'Unknown';

                    $result[] = [
                        // Compound id: medication + dose-time slot. Stable per
                        // dose-row so the front-end can target mutations
                        // (administer/refuse/snooze) at the right occurrence.
                        'id' => $med->id,
                        'client_id' => $med->client_id,
                        'client_name' => $clientName,
                        'medication_name' => $med->name,
                        'dose' => $med->dosage,
                        'route' => $med->route ?? 'Oral',
                        'flag' => $med->is_prn ? 'PRN' : null,
                        'scheduled_for' => $scheduled->toIso8601String(),
                        'status' => $status,
                        'emar_url' => EmarUrl::mar($med->client_id, $scheduled->toDateString()),
                    ];
                }
            }

            // Sort: overdue first, then due, then upcoming
            usort($result, function ($a, $b) {
                $order = ['overdue' => 0, 'due' => 1, 'upcoming' => 2];

                return ($order[$a['status']] ?? 3) <=> ($order[$b['status']] ?? 3);
            });

            return $result;
        } catch (\Throwable) {
            return [];
        }
    }

    private function getNextShiftBriefing(User $user, Carbon $workerNow): ?array
    {
        try {
            // 36-hour lookahead — wide enough for the desktop "Tomorrow" panel
            // (a shift starting at 07:30 tomorrow is ~22h away when viewed
            // late-morning today) but narrow enough that "next week's shift"
            // doesn't bleed into the briefing card.
            $shift = Shift::query()
                ->where('user_id', $user->id)
                ->visibleToFrontline($user->organization_id)
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
            $briefing['medications_due_during_shift'] = $this->getShiftMedicationsDue($shift, $workerNow);
            $briefing['what_to_know'] = $shift->notes;

            return $briefing;
        } catch (\Throwable) {
            return null;
        }
    }

    private function getPreviousShift(User $user, Carbon $workerNow): ?array
    {
        try {
            $shift = Shift::query()
                ->where('user_id', $user->id)
                ->visibleToFrontline($user->organization_id)
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
                    'outgoingHandovers:id,outgoing_shift_id,status,submitted_at',
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
        } catch (\Throwable) {
            return null;
        }
    }

    private function getShiftMedicationsDue(Shift $shift, Carbon $workerNow): array
    {
        if (! $shift->client_id || ! $shift->starts_at || ! $shift->ends_at) {
            return [];
        }

        try {
            $start = $shift->starts_at->copy()->timezone($workerNow->getTimezone());
            $end = $shift->ends_at->copy()->timezone($workerNow->getTimezone());

            return ClientMedication::query()
                ->where('client_id', $shift->client_id)
                ->active()
                ->where('is_prn', false)
                ->whereNotNull('dose_times')
                ->get()
                ->flatMap(function (ClientMedication $medication) use ($start, $end) {
                    $items = [];
                    $doseTimes = is_array($medication->dose_times)
                        ? $medication->dose_times
                        : [];
                    $day = $start->copy()->startOfDay();
                    $lastDay = $end->copy()->startOfDay();

                    while ($day->lessThanOrEqualTo($lastDay)) {
                        foreach ($doseTimes as $doseTime) {
                            $scheduled = $day->copy()->setTimeFromTimeString($doseTime);

                            if ($scheduled->betweenIncluded($start, $end)) {
                                $items[] = [
                                    'medication_name' => $medication->name,
                                    'dose' => $medication->dosage,
                                    'scheduled_for' => $scheduled->toIso8601String(),
                                    'emar_url' => EmarUrl::mar($medication->client_id, $scheduled->toDateString()),
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
        } catch (\Throwable) {
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
        if (! $user->canDo('medications.administer.record') && ! $user->canDo('clients.update')) {
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

            $service = app(GuidedRoundService::class);
            $progress = $service->progress($round);

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
        } catch (\Throwable) {
            return null;
        }
    }

    private function getTimesheets(int $userId): array
    {
        try {
            return Timesheet::where('user_id', $userId)
                ->whereIn('status', ['draft', 'submitted', 'returned'])
                ->with('client:id,first_name,last_name')
                ->orderByDesc('work_date')
                ->limit(10)
                ->get()
                ->map(function (Timesheet $ts) {
                    $clientName = $ts->client
                        ? trim($ts->client->first_name.' '.$ts->client->last_name)
                        : null;

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
                        'can_edit_inline' => in_array($ts->status, ['draft', 'returned'], true)
                            && ! $ts->is_protected_from_changes,
                    ];
                })
                ->all();
        } catch (\Throwable) {
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
        } catch (\Throwable) {
            return [];
        }
    }

    private function getCrTasks(int $userId): array
    {
        $tasks = collect()
            ->merge($this->getAlertTasks($userId))
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

    private function getLeave(int $userId, Carbon $now): array
    {
        try {
            $balances = \App\Domain\Hr\Models\HrLeaveBalance::where('user_id', $userId)
                ->where('year', $now->year)
                ->get()
                ->map(fn ($b) => [
                    'type' => $b->leave_type,
                    'remaining_hours' => round($b->balance_hours - $b->used_hours - $b->pending_hours, 1),
                    'total_hours' => $b->balance_hours,
                ])
                ->all();

            $pendingRequests = \App\Domain\Hr\Models\HrLeaveRequest::where('user_id', $userId)
                ->where('status', 'pending')
                ->count();

            return [
                'balances' => $balances,
                'pending_requests' => $pendingRequests,
            ];
        } catch (\Throwable) {
            return [
                'balances' => [],
                'pending_requests' => 0,
            ];
        }
    }

    private function getManagerData($user, Carbon $today, Carbon $tomorrowEnd): array
    {
        try {
            $orgId = $user->organisation_id ?? $user->org_id ?? null;

            $todayEnd = $today->copy()->endOfDay();

            $teamShiftsToday = Shift::whereBetween('starts_at', [$today, $todayEnd]);
            if ($orgId) {
                $teamShiftsToday = $teamShiftsToday->whereHas('client', fn ($q) => $q->where('organisation_id', $orgId));
            }
            $teamShiftsTodayCount = $teamShiftsToday->count();

            $unassignedShifts = Shift::whereNull('user_id')
                ->whereBetween('starts_at', [$today, $tomorrowEnd]);
            if ($orgId) {
                $unassignedShifts = $unassignedShifts->whereHas('client', fn ($q) => $q->where('organisation_id', $orgId));
            }
            $unassignedShiftsCount = $unassignedShifts->count();

            $timesheetsPendingApproval = Timesheet::where('status', 'submitted')->count();

            $staffOnToday = Shift::whereBetween('starts_at', [$today, $todayEnd])
                ->whereNotNull('user_id');
            if ($orgId) {
                $staffOnToday = $staffOnToday->whereHas('client', fn ($q) => $q->where('organisation_id', $orgId));
            }
            $staffOnTodayCount = $staffOnToday->distinct('user_id')->count('user_id');

            return [
                'team_shifts_today' => $teamShiftsTodayCount,
                'unassigned_shifts' => $unassignedShiftsCount,
                'timesheets_pending_approval' => $timesheetsPendingApproval,
                'staff_on_today' => $staffOnTodayCount,
            ];
        } catch (\Throwable) {
            return [
                'team_shifts_today' => 0,
                'unassigned_shifts' => 0,
                'timesheets_pending_approval' => 0,
                'staff_on_today' => 0,
            ];
        }
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

        if (!empty($candidates)) {
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

    private function getAlertTasks(int $userId): array
    {
        try {
            $now = now();

            return ControlRoomAlert::where('assigned_to_user_id', $userId)
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

                    $slaStatus = null;
                    if ($alert->sla) {
                        if ($alert->sla->response_breached) {
                            $slaStatus = 'breached';
                        } elseif ($alert->sla->response_deadline && $alert->sla->response_deadline->lt(now()->addMinutes(15))) {
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
                        'due_at' => $alert->sla?->response_deadline?->toIso8601String(),
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
        } catch (\Throwable) {
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
        } catch (\Throwable) {
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
        } catch (\Throwable) {
            return [];
        }
    }

    private function getPendingClaimsCount(User $user): int
    {
        try {
            return ShiftOpenPosition::query()
                ->when($user->organization_id, fn ($query) => $query->where('organization_id', $user->organization_id))
                ->where('claimed_by', $user->id)
                ->where('status', 'claimed')
                ->count();
        } catch (\Throwable) {
            return 0;
        }
    }

    private function workerTimezone(): string
    {
        return (string) config('app.worker_timezone', 'Pacific/Auckland');
    }
}
