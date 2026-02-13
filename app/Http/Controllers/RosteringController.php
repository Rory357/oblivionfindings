<?php

namespace App\Http\Controllers;

use App\Domain\Hr\Models\HrLeaveRequest;
use App\Domain\Hr\Models\HrStaffComplianceStatus;
use App\Models\Client;
use App\Models\Shift;
use App\Models\StaffTimeOff;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class RosteringController extends Controller
{
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
                'staff:id,name,email',
                'serviceContext:id,name,type,is_active',
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

        return inertia('rostering/index', [
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

                return [
                    'id' => $shift->id,
                    'client_id' => $shift->client_id,
                    'user_id' => $shift->user_id,
                    'starts_at' => optional($shift->starts_at)->toIso8601String(),
                    'ends_at' => optional($shift->ends_at)->toIso8601String(),
                    'location' => $shift->location,
                    'status' => $shift->status,
                    'service_context' => $shift->serviceContext ? $shift->serviceContext->name : null,
                    'client' => $clientName,
                    'staff' => $staffName,
                    'tasks_total' => (int) ($shift->tasks_total ?? 0),
                    'tasks_completed' => (int) ($shift->tasks_completed ?? 0),
                    'incidents_count' => (int) ($shift->incidents_count ?? 0),
                    'timesheet_status' => $ts ? $ts->status : null,
                ];
            })->values(),
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
}
