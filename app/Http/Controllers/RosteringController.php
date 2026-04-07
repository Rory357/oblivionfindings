<?php

namespace App\Http\Controllers;

use App\Domain\Hr\Models\HrLeaveRequest;
use App\Domain\Hr\Models\HrStaffComplianceStatus;
use App\Models\Client;
use App\Models\Shift;
use App\Models\StaffTimeOff;
use App\Models\User;
use App\Services\ShiftCoverageService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class RosteringController extends Controller
{
    public function __construct(
        protected ShiftCoverageService $shiftCoverageService,
    ) {
    }

    public function index(Request $request)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('rostering.viewAny'), 403);

        $canManageAny = $auth->canDo('shifts.manageAny');

        $data = $request->validate([
            'week' => ['nullable', 'date'],
            'staff_id' => ['nullable', 'integer', 'exists:users,id'],
            'client_id' => ['nullable', 'integer', 'exists:clients,id'],
        ]);

        $week = !empty($data['week'])
            ? Carbon::parse($data['week'])
            : now();

        // NZ: week starts on Monday.
        $weekStart = (clone $week)->startOfWeek(Carbon::MONDAY)->startOfDay();
        $weekEnd = (clone $weekStart)->addDays(7);

        $staff = [];
        $clients = [];

        if ($canManageAny) {
            $staff = User::staff()->orderBy('name')->get(['id', 'name', 'email']);
            $clients = Client::query()->orderBy('first_name')->get(['id', 'first_name', 'last_name']);
        }

        $query = Shift::query()
            ->with([
                'client:id,first_name,last_name',
                'site:id,name,type',
                'staff:id,name,email',
                'serviceContext:id,name,type,is_active',
                'series:id,client_id,service_context_id,user_id,start_date,end_date,by_weekday,starts_time,ends_time,location,status,shift_type,is_sleepover,is_on_call',
                'series.client:id,first_name,last_name',
                'series.staff:id,name',
                'series.serviceContext:id,name,type',
                'replacementRequests' => fn ($q) => $q->active()
                    ->with([
                        'requester:id,name',
                        'currentStaff:id,name',
                        'replacementStaff:id,name',
                        'openPosition:id,replacement_request_id,status,claimed_by,approved_by,expires_at',
                        'openPosition.claimer:id,name',
                    ]),
                'timesheets' => fn ($q) => $q->orderByDesc('id')->limit(1),
            ])
            ->withCount([
                'incidents as incidents_count',
                'tasks as tasks_total',
                'tasks as tasks_completed' => fn ($q) => $q->where('is_completed', true),
            ])
            // overlap window
            ->where('starts_at', '<', $weekEnd)
            ->where('ends_at', '>', $weekStart)
            ->orderBy('starts_at');

        if (!$canManageAny) {
            $query->where('user_id', $auth->id);
        } else {
            if (!empty($data['staff_id'])) {
                $query->where('user_id', $data['staff_id']);
            }
            if (!empty($data['client_id'])) {
                $query->where('client_id', $data['client_id']);
            }
        }

        $shifts = $query->get();

        // Time-off / one-off unavailability blocks
        $timeOffQuery = StaffTimeOff::query()
            ->with(['user:id,name'])
            ->where('starts_at', '<', $weekEnd)
            ->where('ends_at', '>', $weekStart)
            ->orderBy('starts_at');

        if (!$canManageAny) {
            $timeOffQuery->where('user_id', $auth->id);
        } else {
            if (!empty($data['staff_id'])) {
                $timeOffQuery->where('user_id', $data['staff_id']);
            }
        }

        $timeOffs = $timeOffQuery->get();

        // Conflict detection (UI-only warnings): actionable overlaps only.
        // Completed shifts are immutable (locked) and cancelled shifts are non-actionable.
        $actionableShifts = $shifts->filter(fn ($s) => !in_array($s->status, ['completed', 'cancelled'], true))->values();

        // Overlaps per staff and per client.
        $staffOverlapCount = 0;
        $clientOverlapCount = 0;

        if ($canManageAny) {
            $staffGroups = $actionableShifts
                ->filter(fn ($s) => !empty($s->user_id))
                ->groupBy('user_id');

            foreach ($staffGroups as $group) {
                $sorted = $group->sortBy('starts_at')->values();
                for ($i = 1; $i < $sorted->count(); $i++) {
                    $prev = $sorted[$i - 1];
                    $cur = $sorted[$i];
                    if ($prev->ends_at && $cur->starts_at && $prev->ends_at->gt($cur->starts_at)) {
                        $staffOverlapCount++;
                    }
                }
            }

            $clientGroups = $actionableShifts->groupBy('client_id');
            foreach ($clientGroups as $group) {
                $sorted = $group->sortBy('starts_at')->values();
                for ($i = 1; $i < $sorted->count(); $i++) {
                    $prev = $sorted[$i - 1];
                    $cur = $sorted[$i];
                    if ($prev->ends_at && $cur->starts_at && $prev->ends_at->gt($cur->starts_at)) {
                        $clientOverlapCount++;
                    }
                }
            }
        }

        // Time-off conflicts: where a shift overlaps a staff time-off block
        $timeOffConflicts = 0;
        if ($canManageAny) {
            $byUser = $timeOffs->groupBy('user_id');
            foreach ($actionableShifts->filter(fn($s) => !empty($s->user_id)) as $s) {
                $blocks = $byUser->get($s->user_id);
                if (!$blocks) continue;
                foreach ($blocks as $b) {
                    if ($b->starts_at < $s->ends_at && $b->ends_at > $s->starts_at) {
                        $timeOffConflicts++;
                        break;
                    }
                }
            }
        }

        $stats = [
            'total' => $shifts->count(),
            'open' => $shifts->whereNull('user_id')->count(),
            'draft' => $shifts->where('status', 'draft')->count(),
            'scheduled' => $shifts->where('status', 'scheduled')->count(),
            'in_progress' => $shifts->where('status', 'in_progress')->count(),
            'completed' => $shifts->where('status', 'completed')->count(),
            'cancelled' => $shifts->where('status', 'cancelled')->count(),
            'incidents' => (int) $shifts->sum('incidents_count'),
            'staff_overlaps' => $staffOverlapCount,
            'client_overlaps' => $clientOverlapCount,
            'timesheets_pending' => (int) $shifts->filter(function ($s) {
                $ts = $s->timesheets->first();
                if (!$ts) return false;
                return in_array($ts->status, ['draft', 'submitted', 'returned'], true);
            })->count(),
            'time_off_conflicts' => $timeOffConflicts,
        ];

        // Capacity (hours per staff for the week)
        $capacity = [];
        if ($canManageAny) {
            $staffForCapacity = $staff;
            if (!empty($data['staff_id'])) {
                $staffForCapacity = $staffForCapacity->where('id', (int) $data['staff_id']);
            }

            $grouped = $shifts->filter(fn ($s) => !empty($s->user_id) && $s->status !== 'cancelled')
                ->groupBy('user_id');

            foreach ($staffForCapacity as $u) {
                $hrs = 0.0;
                foreach (($grouped->get($u->id) ?? collect()) as $s) {
                    $start = $s->starts_at->copy()->max($weekStart);
                    $end = $s->ends_at->copy()->min($weekEnd);
                    $mins = max(0, $end->diffInMinutes($start));
                    $hrs += $mins / 60.0;
                }
                $capacity[] = [
                    'user_id' => $u->id,
                    'name' => $u->name,
                    'hours' => round($hrs, 2),
                    'warn' => $hrs >= 50 ? 'high' : ($hrs >= 40 ? 'medium' : null),
                ];
            }
        }

        // --- Analytics Data ---

        // Daily shift coverage (scheduled vs filled per day)
        $dailyCoverage = [];
        for ($d = 0; $d < 7; $d++) {
            $day = (clone $weekStart)->addDays($d);
            $dayEnd = (clone $day)->addDay();
            $dayShifts = $shifts->filter(fn ($s) => $s->starts_at && $s->starts_at->gte($day) && $s->starts_at->lt($dayEnd));
            $dailyCoverage[] = [
                'day' => $day->format('D'),
                'date' => $day->toDateString(),
                'scheduled' => $dayShifts->count(),
                'filled' => $dayShifts->whereNotNull('user_id')->count(),
                'open' => $dayShifts->whereNull('user_id')->count(),
            ];
        }

        // Shift type distribution
        $shiftTypeDistribution = $shifts
            ->where('status', '!=', 'cancelled')
            ->groupBy(fn ($s) => $s->shift_type ?? 'standard')
            ->map(fn ($group, $type) => [
                'type' => ucfirst(str_replace('_', ' ', $type)),
                'value' => $group->count(),
            ])
            ->values()
            ->all();

        // Staff on leave this week
        $onLeaveCount = $canManageAny ? HrLeaveRequest::where('status', 'approved')
            ->where('starts_at', '<', $weekEnd)
            ->where('ends_at', '>', $weekStart)
            ->distinct('user_id')
            ->count('user_id') : 0;

        // Compliance overview
        $complianceExpiring = 0;
        $complianceExpired = 0;
        if ($canManageAny && $auth->tenant_id) {
            $complianceExpiring = HrStaffComplianceStatus::where('tenant_id', $auth->tenant_id)
                ->where('status', 'expiring_soon')
                ->count();
            $complianceExpired = HrStaffComplianceStatus::where('tenant_id', $auth->tenant_id)
                ->where('status', 'expired')
                ->whereHas('requirement', fn ($q) => $q->where('hard_stop', true))
                ->count();
        }

        // 4-week historical trend (shifts completed vs cancelled per week)
        $historicalTrend = [];
        if ($canManageAny) {
            for ($w = 3; $w >= 0; $w--) {
                $wStart = (clone $weekStart)->subWeeks($w);
                $wEnd = (clone $wStart)->addDays(7);
                $weekShifts = Shift::where('starts_at', '>=', $wStart)
                    ->where('starts_at', '<', $wEnd)
                    ->selectRaw("SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed")
                    ->selectRaw("SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled")
                    ->selectRaw('COUNT(*) as total')
                    ->first();
                $historicalTrend[] = [
                    'week' => $wStart->format('d M'),
                    'completed' => (int) ($weekShifts->completed ?? 0),
                    'cancelled' => (int) ($weekShifts->cancelled ?? 0),
                    'total' => (int) ($weekShifts->total ?? 0),
                ];
            }
        }

        // Coverage rate
        $totalShiftsThisWeek = $shifts->where('status', '!=', 'cancelled')->count();
        $filledShiftsThisWeek = $shifts->where('status', '!=', 'cancelled')->whereNotNull('user_id')->count();
        $coverageRate = $totalShiftsThisWeek > 0 ? round(($filledShiftsThisWeek / $totalShiftsThisWeek) * 100, 0) : 100;

        // Unique staff rostered
        $staffRostered = $shifts->where('status', '!=', 'cancelled')->whereNotNull('user_id')->pluck('user_id')->unique()->count();

        $replacementQueue = $shifts
            ->map(function (Shift $shift) {
                $replacement = $shift->replacementRequests
                    ->sortByDesc('requested_at')
                    ->first();

                if (! $replacement) {
                    return null;
                }

                return [
                    'id' => $replacement->id,
                    'shift_id' => $shift->id,
                    'status' => $replacement->status,
                    'reason' => $replacement->reason,
                    'requested_at' => optional($replacement->requested_at)->toIso8601String(),
                    'starts_at' => optional($shift->starts_at)->toIso8601String(),
                    'ends_at' => optional($shift->ends_at)->toIso8601String(),
                    'client' => $shift->client ? trim($shift->client->first_name.' '.$shift->client->last_name) : null,
                    'location' => $shift->location,
                    'current_staff' => $replacement->currentStaff?->name,
                    'requested_by' => $replacement->requester?->name,
                    'replacement_staff' => $replacement->replacementStaff?->name,
                    'open_position_id' => $replacement->openPosition?->id,
                    'open_position_status' => $replacement->openPosition?->status,
                    'open_position_claimed_by' => $replacement->openPosition?->claimer?->name,
                    'expires_at' => optional($replacement->openPosition?->expires_at)->toIso8601String(),
                ];
            })
            ->filter()
            ->sortBy('starts_at')
            ->values();

        $selectedSiteId = ! empty($data['client_id'])
            ? Client::query()->whereKey($data['client_id'])->value('site_id')
            : null;

        $coverageSites = $canManageAny
            ? $this->shiftCoverageService->buildSiteSummaries($weekStart, $weekEnd, $selectedSiteId)
            : [];

        $coverageAlerts = collect($coverageSites)
            ->flatMap(fn (array $site) => collect($site['alerts'] ?? [])->map(function (array $alert) use ($site) {
                return [
                    ...$alert,
                    'site_id' => $site['site_id'],
                    'site_name' => $site['site_name'],
                ];
            }))
            ->sortByDesc(fn (array $alert) => (
                (($alert['unfilled_after_open_shifts'] ?? 0) * 100)
                + ((count($alert['planned_role_shortages'] ?? []) > 0 ? 1 : 0) * 75)
                + ((count($alert['role_shortages'] ?? []) > 0 ? 1 : 0) * 50)
                + ($alert['missing_staff'] ?? 0)
            ))
            ->values()
            ->all();

        $stats['coverage_gaps'] = count($coverageAlerts);
        $recurringCoverageAlignment = $canManageAny
            ? $this->shiftCoverageService->buildRecurringAlignment($weekStart, $weekEnd, $selectedSiteId)
            : ['rule_drift' => [], 'orphan_series' => []];

        $recurringPatterns = $shifts
            ->filter(fn (Shift $shift) => ! empty($shift->shift_series_id) && $shift->series)
            ->groupBy('shift_series_id')
            ->map(function ($group) {
                /** @var Shift $sample */
                $sample = $group->first();
                $series = $sample->series;
                $nextShift = $group
                    ->filter(fn (Shift $shift) => ! in_array($shift->status, ['completed', 'cancelled'], true))
                    ->sortBy('starts_at')
                    ->first() ?? $group->sortBy('starts_at')->first();

                return [
                    'id' => $series->id,
                    'client' => $series->client ? trim($series->client->first_name.' '.$series->client->last_name) : ($sample->client ? trim($sample->client->first_name.' '.$sample->client->last_name) : null),
                    'staff' => $series->staff?->name ?? $sample->staff?->name,
                    'service_context' => $series->serviceContext?->name ?? $sample->serviceContext?->name,
                    'location' => $series->location ?? $sample->location,
                    'status' => $series->status ?? $sample->status,
                    'shift_type' => $series->shift_type ?? $sample->shift_type ?? 'standard',
                    'is_sleepover' => (bool) ($series->is_sleepover ?? $sample->is_sleepover),
                    'is_on_call' => (bool) ($series->is_on_call ?? $sample->is_on_call),
                    'weekdays' => $series->by_weekday ?? [],
                    'starts_time' => $series->starts_time,
                    'ends_time' => $series->ends_time,
                    'occurrences_this_week' => $group->count(),
                    'open_occurrences' => $group->whereNull('user_id')->count(),
                    'active_replacement_count' => $group->filter(fn (Shift $shift) => $shift->replacementRequests->isNotEmpty())->count(),
                    'next_shift_id' => $nextShift?->id,
                    'next_starts_at' => optional($nextShift?->starts_at)->toIso8601String(),
                ];
            })
            ->sortBy('next_starts_at')
            ->values();

        return inertia('operations/rostering/index', [
            'canManageAny' => $canManageAny,
            'weekStart' => $weekStart->toDateString(),
            'weekEnd' => $weekEnd->toDateString(),
            'filters' => [
                'week' => $weekStart->toDateString(),
                'staff_id' => $data['staff_id'] ?? null,
                'client_id' => $data['client_id'] ?? null,
            ],
            'staff' => $staff,
            'clients' => $clients,
            'stats' => $stats,
            'shifts' => $shifts->map(function (Shift $shift) {
                $clientName = $shift->client ? ($shift->client->first_name . ' ' . $shift->client->last_name) : null;
                $staffName = $shift->staff ? $shift->staff->name : null;
                $ts = $shift->timesheets->first();
                $activeReplacement = $shift->replacementRequests
                    ->sortByDesc('requested_at')
                    ->first();

                return [
                    'id' => $shift->id,
                    'client_id' => $shift->client_id,
                    'user_id' => $shift->user_id,
                    'shift_series_id' => $shift->shift_series_id,
                    'starts_at' => optional($shift->starts_at)->toIso8601String(),
                    'ends_at' => optional($shift->ends_at)->toIso8601String(),
                    'location' => $shift->location,
                    'status' => $shift->status,
                    'shift_type' => $shift->shift_type ?? 'standard',
                    'service_context' => $shift->serviceContext ? $shift->serviceContext->name : null,
                    'client' => $clientName,
                    'staff' => $staffName,
                    'tasks_total' => (int) ($shift->tasks_total ?? 0),
                    'tasks_completed' => (int) ($shift->tasks_completed ?? 0),
                    'incidents_count' => (int) ($shift->incidents_count ?? 0),
                    'timesheet_status' => $ts ? $ts->status : null,
                    'has_active_replacement' => (bool) $activeReplacement,
                    'replacement_status' => $activeReplacement?->status,
                    'replacement_reason' => $activeReplacement?->reason,
                    'replacement_requested_by' => $activeReplacement?->requester?->name,
                    'replacement_current_staff' => $activeReplacement?->currentStaff?->name,
                    'open_position_status' => $activeReplacement?->openPosition?->status,
                ];
            })->values(),
            'replacementQueue' => $replacementQueue,
            'recurringPatterns' => $recurringPatterns,
            'coverageSites' => $coverageSites,
            'coverageAlerts' => $coverageAlerts,
            'recurringCoverageAlignment' => $recurringCoverageAlignment,
            'timeOffs' => $timeOffs->map(fn ($b) => [
                'id' => $b->id,
                'user_id' => $b->user_id,
                'user' => $b->user ? $b->user->name : null,
                'starts_at' => optional($b->starts_at)->toIso8601String(),
                'ends_at' => optional($b->ends_at)->toIso8601String(),
                'type' => $b->type,
                'label' => $b->label,
                'notes' => $b->notes,
            ])->values(),
            'capacity' => $capacity,

            // HR leave overlay: approved leave requests overlapping this week
            'approvedLeave' => $canManageAny ? HrLeaveRequest::where('status', 'approved')
                ->where('starts_at', '<', $weekEnd)
                ->where('ends_at', '>', $weekStart)
                ->with('user:id,name')
                ->get()
                ->map(fn ($l) => [
                    'id' => $l->id,
                    'user_id' => $l->user_id,
                    'user' => $l->user?->name,
                    'leave_type' => $l->leave_type,
                    'starts_at' => $l->starts_at?->toIso8601String(),
                    'ends_at' => $l->ends_at?->toIso8601String(),
                ])->values() : [],

            // HR compliance badges per staff member
            'complianceBadges' => $canManageAny ? $this->getComplianceBadges($auth->tenant_id) : [],

            // Analytics data
            'analytics' => [
                'dailyCoverage' => $dailyCoverage,
                'shiftTypeDistribution' => $shiftTypeDistribution,
                'historicalTrend' => $historicalTrend,
                'coverageRate' => $coverageRate,
                'staffRostered' => $staffRostered,
                'onLeaveCount' => $onLeaveCount,
                'complianceExpiring' => $complianceExpiring,
                'complianceExpired' => $complianceExpired,
            ],
        ]);
    }

    public function conflicts(Request $request)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('rostering.viewAny'), 403);

        $data = $request->validate([
            'week' => ['nullable', 'date'],
        ]);

        $week = !empty($data['week']) ? Carbon::parse($data['week']) : now();
        $weekStart = (clone $week)->startOfWeek(Carbon::MONDAY)->startOfDay();
        $weekEnd = (clone $weekStart)->addDays(7);

        $shifts = Shift::query()
            ->with([
                'client:id,first_name,last_name',
                'site:id,name,type',
                'staff:id,name,email',
                'serviceContext:id,name,type,is_active',
                'replacementRequests' => fn ($query) => $query->active()->with([
                    'requester:id,name',
                    'currentStaff:id,name',
                    'replacementStaff:id,name',
                    'openPosition:id,replacement_request_id,status,claimed_by',
                    'openPosition.claimer:id,name',
                ]),
            ])
            ->where('starts_at', '<', $weekEnd)
            ->where('ends_at', '>', $weekStart)
            ->orderBy('starts_at')
            ->get();

        $actionableShifts = $shifts
            ->filter(fn (Shift $shift) => ! in_array($shift->status, ['completed', 'cancelled'], true))
            ->values();

        $staffOverlaps = [];
        foreach ($actionableShifts->filter(fn (Shift $shift) => ! empty($shift->user_id))->groupBy('user_id') as $userId => $group) {
            $sorted = $group->sortBy('starts_at')->values();
            for ($i = 1; $i < $sorted->count(); $i++) {
                $previous = $sorted[$i - 1];
                $current = $sorted[$i];
                if ($previous && $current && $previous->ends_at && $current->starts_at && $previous->ends_at->gt($current->starts_at)) {
                    $staffOverlaps[] = [
                        'staff_id' => (int) $userId,
                        'staff_name' => $current->staff?->name ?? $previous->staff?->name ?? 'Staff member',
                        'first' => $this->serializeConflictShift($previous),
                        'second' => $this->serializeConflictShift($current),
                    ];
                }
            }
        }

        $clientOverlaps = [];
        foreach ($actionableShifts->groupBy('client_id') as $clientId => $group) {
            $sorted = $group->sortBy('starts_at')->values();
            for ($i = 1; $i < $sorted->count(); $i++) {
                $previous = $sorted[$i - 1];
                $current = $sorted[$i];
                if ($previous && $current && $previous->ends_at && $current->starts_at && $previous->ends_at->gt($current->starts_at)) {
                    $clientOverlaps[] = [
                        'client_id' => (int) $clientId,
                        'client_name' => $current->client ? trim($current->client->first_name.' '.$current->client->last_name) : 'Client',
                        'first' => $this->serializeConflictShift($previous),
                        'second' => $this->serializeConflictShift($current),
                    ];
                }
            }
        }

        $timeOffs = StaffTimeOff::query()
            ->with('user:id,name')
            ->where('starts_at', '<', $weekEnd)
            ->where('ends_at', '>', $weekStart)
            ->orderBy('starts_at')
            ->get();

        $timeOffConflicts = [];
        foreach ($actionableShifts->filter(fn (Shift $shift) => ! empty($shift->user_id)) as $shift) {
            foreach ($timeOffs->where('user_id', $shift->user_id) as $timeOff) {
                if ($timeOff->starts_at < $shift->ends_at && $timeOff->ends_at > $shift->starts_at) {
                    $timeOffConflicts[] = [
                        'shift' => $this->serializeConflictShift($shift),
                        'time_off' => [
                            'id' => $timeOff->id,
                            'user_name' => $timeOff->user?->name ?? 'Staff member',
                            'type' => $timeOff->type,
                            'label' => $timeOff->label,
                            'starts_at' => optional($timeOff->starts_at)->toIso8601String(),
                            'ends_at' => optional($timeOff->ends_at)->toIso8601String(),
                        ],
                    ];
                    break;
                }
            }
        }

        $tightTurnarounds = [];
        foreach ($actionableShifts->filter(fn (Shift $shift) => ! empty($shift->user_id))->groupBy('user_id') as $userId => $group) {
            $sorted = $group->sortBy('starts_at')->values();
            for ($i = 1; $i < $sorted->count(); $i++) {
                $previous = $sorted[$i - 1];
                $current = $sorted[$i];

                if (! $previous?->ends_at || ! $current?->starts_at) {
                    continue;
                }

                if ($previous->ends_at->gt($current->starts_at)) {
                    continue;
                }

                $gapMinutes = $previous->ends_at->diffInMinutes($current->starts_at);
                if ($gapMinutes > 30) {
                    continue;
                }

                $tightTurnarounds[] = [
                    'staff_id' => (int) $userId,
                    'staff_name' => $current->staff?->name ?? $previous->staff?->name ?? 'Staff member',
                    'gap_minutes' => $gapMinutes,
                    'first' => $this->serializeConflictShift($previous),
                    'second' => $this->serializeConflictShift($current),
                ];
            }
        }

        $openShifts = $actionableShifts
            ->whereNull('user_id')
            ->map(fn (Shift $shift) => $this->serializeConflictShift($shift))
            ->values();

        $activeReplacements = $actionableShifts
            ->map(function (Shift $shift) {
                $replacement = $shift->replacementRequests->sortByDesc('requested_at')->first();
                if (! $replacement) {
                    return null;
                }

                return [
                    'id' => $replacement->id,
                    'shift' => $this->serializeConflictShift($shift),
                    'status' => $replacement->status,
                    'reason' => $replacement->reason,
                    'requested_by' => $replacement->requester?->name,
                    'current_staff' => $replacement->currentStaff?->name,
                    'replacement_staff' => $replacement->replacementStaff?->name,
                    'claimed_by' => $replacement->openPosition?->claimer?->name,
                    'open_position_id' => $replacement->openPosition?->id,
                ];
            })
            ->filter()
            ->values();

        $coverageGaps = collect($this->shiftCoverageService->buildSiteSummaries($weekStart, $weekEnd))
            ->flatMap(fn (array $site) => collect($site['alerts'] ?? [])->map(function (array $alert) use ($site) {
                return [
                    ...$alert,
                    'site_id' => $site['site_id'],
                    'site_name' => $site['site_name'],
                ];
            }))
            ->sortByDesc(fn (array $alert) => (
                (($alert['unfilled_after_open_shifts'] ?? 0) * 100)
                + ((count($alert['planned_role_shortages'] ?? []) > 0 ? 1 : 0) * 75)
                + ((count($alert['role_shortages'] ?? []) > 0 ? 1 : 0) * 50)
                + ($alert['missing_staff'] ?? 0)
            ))
            ->values()
            ->all();
        $recurringCoverageAlignment = $this->shiftCoverageService->buildRecurringAlignment($weekStart, $weekEnd);

        return inertia('operations/rostering/conflicts', [
            'weekStart' => $weekStart->toDateString(),
            'weekEnd' => $weekEnd->toDateString(),
            'staffOverlaps' => array_values($staffOverlaps),
            'clientOverlaps' => array_values($clientOverlaps),
            'timeOffConflicts' => array_values($timeOffConflicts),
            'tightTurnarounds' => array_values($tightTurnarounds),
            'openShifts' => $openShifts,
            'activeReplacements' => $activeReplacements,
            'coverageGaps' => $coverageGaps,
            'recurringCoverageAlignment' => $recurringCoverageAlignment,
        ]);
    }

    /**
     * Get compliance status badges for all active staff (for rostering overlays).
     */
    protected function getComplianceBadges(?int $tenantId): array
    {
        if (!$tenantId) {
            return [];
        }

        return HrStaffComplianceStatus::where('tenant_id', $tenantId)
            ->whereIn('status', ['expired', 'expiring_soon'])
            ->whereHas('requirement', fn ($q) => $q->where('is_active', true))
            ->with('requirement:id,code,name,hard_stop')
            ->get()
            ->groupBy('user_id')
            ->map(fn ($statuses, $userId) => [
                'user_id' => $userId,
                'has_hard_stop' => $statuses->contains(fn ($s) => $s->requirement?->hard_stop && $s->status === 'expired'),
                'expired_count' => $statuses->where('status', 'expired')->count(),
                'expiring_count' => $statuses->where('status', 'expiring_soon')->count(),
            ])
            ->values()
            ->toArray();
    }

    protected function serializeConflictShift(Shift $shift): array
    {
        return [
            'id' => $shift->id,
            'client_name' => $shift->client ? trim($shift->client->first_name.' '.$shift->client->last_name) : 'Client',
            'staff_name' => $shift->staff?->name,
            'service_context' => $shift->serviceContext?->name,
            'status' => $shift->status,
            'shift_type' => $shift->shift_type ?? 'standard',
            'location' => $shift->location,
            'starts_at' => optional($shift->starts_at)->toIso8601String(),
            'ends_at' => optional($shift->ends_at)->toIso8601String(),
            'shift_series_id' => $shift->shift_series_id,
        ];
    }
}
