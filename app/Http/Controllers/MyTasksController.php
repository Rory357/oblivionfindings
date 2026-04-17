<?php

namespace App\Http\Controllers;

use App\Domain\Hr\Models\HrAttendanceSession;
use App\Domain\Hr\Services\AttendanceService;
use App\Models\ClientIncident;
use App\Models\ClientMedication;
use App\Models\ControlRoom\OperatorNote;
use App\Models\ControlRoomAlert;
use App\Models\IncidentFollowup;
use App\Models\MedicationRound;
use App\Models\Shift;
use App\Models\ShiftHandover;
use App\Models\Timesheet;
use App\Models\User;
use App\Services\GuidedRoundService;
use App\Services\ShiftHandoverService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use App\Support\EmarUrl;
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
        $now = Carbon::now();
        $today = $now->copy()->startOfDay();
        $tomorrowEnd = $now->copy()->addDay()->endOfDay();

        // 1. Today formatted
        $todayFormatted = $now->format('l, j F Y');

        // 2. Shifts today + tomorrow
        $shifts = $this->getShifts($userId, $today, $tomorrowEnd, $now);

        // 3. Medications due
        $clientIds = $shifts->pluck('client.id')->filter()->unique()->values()->all();
        $medicationsDue = $this->getMedicationsDue($clientIds, $now);

        // 4. Timesheets
        $timesheets = $this->getTimesheets($userId);

        // 5. Incidents
        $incidents = $this->getIncidents($userId, $now);

        // 6. Tasks (CR alerts + followups + notes - existing aggregation)
        $tasks = $this->getCrTasks($userId);

        // 7. Leave
        $leave = $this->getLeave($userId, $now);

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
        $clock = $this->getClockState($user, $now);

        // 12. Active guided medication round (PR 9). Surfaces a resume / start
        //     banner on /my-day for the worker assigned to today's round.
        $activeRound = $this->getActiveRound($user, $now);

        return Inertia::render('my-day/index', [
            'today' => $todayFormatted,
            'shifts' => $shifts->values()->all(),
            'medications_due' => $medicationsDue,
            'timesheets' => $timesheets,
            'incidents' => $incidents,
            'tasks' => $tasks,
            'stats' => $stats,
            'leave' => $leave,
            'is_manager' => $isManager,
            'manager_data' => $managerData,
            'clock' => $clock,
            'active_round' => $activeRound,
        ]);
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
                ->with(['shift.client:id,first_name,last_name'])
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
                ? trim($shift->client->first_name . ' ' . $shift->client->last_name)
                : null,
        ];

        $activeShiftCard = $activeShift ? $shiftToCard($activeShift) : null;
        if ($activeShiftCard && $activeShift) {
            $activeShiftCard['incoming_handover'] = $this->findIncomingHandover($user, $activeShift);
        }

        return [
            'can_clock' => $canClock,
            'open_session' => $openSession ? [
                'id' => $openSession->id,
                'clock_in_at' => optional($openSession->clock_in_at)->toIso8601String(),
                'shift_id' => $openSession->shift_id,
                'client_name' => $openSession->shift?->client
                    ? trim($openSession->shift->client->first_name . ' ' . $openSession->shift->client->last_name)
                    : null,
                'shift_starts_at' => optional($openSession->shift?->starts_at)->toIso8601String(),
                'shift_ends_at' => optional($openSession->shift?->ends_at)->toIso8601String(),
                'location' => $openSession->shift?->location ?? $openSession->location,
                'handover_submitted' => $openSession->shift_id
                    ? $this->hasSubmittedHandoverForShift((int) $openSession->shift_id)
                    : false,
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
                                ->where(function ($inner) use ($activeShift, $user) {
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
                    ? trim($handover->client->first_name . ' ' . $handover->client->last_name)
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

    private function getShifts(int $userId, Carbon $today, Carbon $tomorrowEnd, Carbon $now): \Illuminate\Support\Collection
    {
        try {
            return Shift::where('user_id', $userId)
                ->whereBetween('starts_at', [$today, $tomorrowEnd])
                ->with(['client:id,first_name,last_name,profile_photo_path', 'serviceContext:id,name', 'tasks'])
                ->orderBy('starts_at')
                ->get()
                ->map(function (Shift $shift) use ($now, $today) {
                    $totalTasks = $shift->tasks->count();
                    $completedTasks = $shift->tasks->where('is_completed', true)->count();

                    return [
                        'id' => $shift->id,
                        'starts_at' => $shift->starts_at->toIso8601String(),
                        'ends_at' => $shift->ends_at?->toIso8601String(),
                        'actual_starts_at' => $shift->actual_starts_at?->toIso8601String(),
                        'actual_ends_at' => $shift->actual_ends_at?->toIso8601String(),
                        'status' => $shift->status,
                        'location' => $shift->location,
                        'client' => $shift->client ? [
                            'id' => $shift->client->id,
                            'name' => trim($shift->client->first_name . ' ' . $shift->client->last_name),
                            'photo_url' => $shift->client->profile_photo_url ?? null,
                        ] : null,
                        'service_type' => $shift->serviceContext?->name,
                        'tasks' => $shift->tasks->map(fn ($task) => [
                            'id' => $task->id,
                            'label' => $task->label,
                            'is_completed' => (bool) $task->is_completed,
                            'completed_at' => $task->completed_at?->toIso8601String(),
                        ])->all(),
                        'task_progress' => $totalTasks > 0
                            ? round(($completedTasks / $totalTasks) * 100)
                            : 100,
                        'is_today' => $shift->starts_at->isSameDay($today),
                    ];
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
                        ? trim($med->client->first_name . ' ' . $med->client->last_name)
                        : 'Unknown';

                    $result[] = [
                        'client_name' => $clientName,
                        'medication_name' => $med->name,
                        'dose' => $med->dosage,
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
                        ? trim($ts->client->first_name . ' ' . $ts->client->last_name)
                        : null;

                    return [
                        'id' => $ts->id,
                        'work_date' => Carbon::parse($ts->work_date)->format('D, j M Y'),
                        'client_name' => $clientName,
                        'hours' => $ts->total_hours,
                        'status' => $ts->status,
                        'return_notes' => $ts->returned_notes,
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
                        ? trim($incident->client->first_name . ' ' . $incident->client->last_name)
                        : null;

                    return [
                        'id' => $incident->id,
                        'title' => $incident->title,
                        'client_name' => $clientName,
                        'severity' => $incident->severity,
                        'status' => $incident->status,
                        'occurred_at' => $incident->occurred_at?->toIso8601String(),
                        'url' => '/incidents/' . $incident->id,
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
                        ? trim($alert->client->first_name . ' ' . $alert->client->last_name)
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
                        'id' => 'alert-' . $alert->id,
                        'type' => 'alert',
                        'title' => $alert->alert_type,
                        'priority' => $alert->severity ?? 'medium',
                        'status' => $alert->status,
                        'source_url' => '/control-room/alerts/' . $alert->id,
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
                        ? trim($incident->client->first_name . ' ' . $incident->client->last_name)
                        : null;

                    return [
                        'id' => 'followup-' . $followup->id,
                        'type' => 'followup',
                        'title' => 'Incident follow-up: ' . ($incident?->title ?? 'Unknown incident'),
                        'priority' => $incident?->severity ?? 'medium',
                        'status' => 'pending',
                        'source_url' => '/incidents/' . ($followup->client_incident_id),
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
                        ? '/control-room/alerts/' . $note->alert_id
                        : '/control-room/shifts';

                    return [
                        'id' => 'note-' . $note->id,
                        'type' => 'note_followup',
                        'title' => 'Follow-up: ' . Str::limit($note->content, 60),
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
}
