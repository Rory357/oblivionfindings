<?php

namespace App\Http\Controllers\Hr;

use App\Http\Controllers\Controller;
use App\Domain\Hr\Models\HrLeaveRequest;
use App\Domain\Hr\Models\HrEmployeeProfile;
use Illuminate\Http\Request;
use Inertia\Inertia;

class TimeOffCalendarController extends Controller
{
    /**
     * Show time-off calendar with leave data filterable by team/department/site.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.leave.view'), 403);

        $month = $request->query('month', now()->format('Y-m'));
        $startOfMonth = \Carbon\Carbon::parse($month . '-01')->startOfMonth();
        $endOfMonth = $startOfMonth->copy()->endOfMonth();

        $leaveQuery = HrLeaveRequest::query()
            ->forTenant($user->tenant_id)
            ->approved()
            ->where('starts_at', '<=', $endOfMonth)
            ->where('ends_at', '>=', $startOfMonth)
            ->with('user:id,name');

        // Filter by department/team/site if provided
        if ($request->query('department') || $request->query('team') || $request->query('site_id')) {
            $profileUserIds = HrEmployeeProfile::query()
                ->where('tenant_id', $user->tenant_id)
                ->where('is_active', true)
                ->when($request->query('department'), fn ($q, $dept) => $q->where('department', $dept))
                ->when($request->query('team'), fn ($q, $team) => $q->where('team', $team))
                ->when($request->query('site_id'), fn ($q, $siteId) => $q->where('primary_site_id', $siteId))
                ->pluck('user_id');

            $leaveQuery->whereIn('user_id', $profileUserIds);
        }

        $leaveRequests = $leaveQuery->get();

        // Build calendar data: array of days with who's off
        $calendarDays = [];
        $current = $startOfMonth->copy();
        while ($current->lte($endOfMonth)) {
            $dateStr = $current->toDateString();
            $dayLeave = $leaveRequests->filter(function ($lr) use ($current) {
                return $current->between($lr->starts_at->startOfDay(), $lr->ends_at->endOfDay());
            })->map(fn ($lr) => [
                'id' => $lr->id,
                'user_name' => $lr->user?->name ?? 'Unknown',
                'leave_type' => $lr->leave_type,
                'status' => $lr->status,
            ])->values();

            $calendarDays[] = [
                'date' => $dateStr,
                'day' => $current->day,
                'day_of_week' => $current->dayOfWeek,
                'is_weekend' => $current->isWeekend(),
                'leave' => $dayLeave,
            ];

            $current->addDay();
        }

        // Get filter options
        $departments = HrEmployeeProfile::query()
            ->where('tenant_id', $user->tenant_id)
            ->where('is_active', true)
            ->whereNotNull('department')
            ->distinct()
            ->pluck('department');

        $teams = HrEmployeeProfile::query()
            ->where('tenant_id', $user->tenant_id)
            ->where('is_active', true)
            ->whereNotNull('team')
            ->distinct()
            ->pluck('team');

        return Inertia::render('hr/calendar/time-off', [
            'calendarDays' => $calendarDays,
            'month' => $month,
            'monthLabel' => $startOfMonth->format('F Y'),
            'filters' => [
                'department' => $request->query('department'),
                'team' => $request->query('team'),
                'site_id' => $request->query('site_id'),
            ],
            'departments' => $departments,
            'teams' => $teams,
        ]);
    }
}
