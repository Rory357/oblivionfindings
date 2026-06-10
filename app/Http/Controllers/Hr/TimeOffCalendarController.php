<?php

namespace App\Http\Controllers\Hr;

use App\Domain\Hr\Models\HrDepartment;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrLeaveRequest;
use App\Domain\Hr\Models\HrPublicHoliday;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Hr\Concerns\ResolvesHrTenant;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Inertia\Inertia;

class TimeOffCalendarController extends Controller
{
    use ResolvesHrTenant;

    /**
     * Show time-off calendar with leave data filterable by team/department/site.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($user, 403);

        $tenantId = $this->resolveHrTenantIdForUser($user);
        $month = $request->query('month', now()->format('Y-m'));
        $startOfMonth = Carbon::parse($month.'-01')->startOfMonth();
        $endOfMonth = $startOfMonth->copy()->endOfMonth();

        $leaveQuery = HrLeaveRequest::query()
            ->forTenant($tenantId)
            ->approved()
            ->where('starts_at', '<=', $endOfMonth)
            ->where('ends_at', '>=', $startOfMonth)
            ->with('user:id,name');

        // Filter by department/team/site if provided
        if ($request->query('department') || $request->query('team') || $request->query('site_id')) {
            $profileUserIds = HrEmployeeProfile::query()
                ->where('tenant_id', $tenantId)
                ->where('is_active', true)
                ->when($request->query('department'), fn ($q, $dept) => $q->where('department_id', (int) $dept))
                ->when($request->query('team'), fn ($q, $team) => $q->where('team', $team))
                ->when($request->query('site_id'), fn ($q, $siteId) => $q->where('primary_site_id', $siteId))
                ->pluck('user_id');

            $leaveQuery->whereIn('user_id', $profileUserIds);
        }

        $leaveRequests = $leaveQuery->get();
        $holidaysByDate = HrPublicHoliday::query()
            ->where(function ($query) use ($tenantId) {
                $query->whereNull('tenant_id')
                    ->orWhere('tenant_id', $tenantId);
            })
            ->whereBetween('date', [$startOfMonth->toDateString(), $endOfMonth->toDateString()])
            ->orderBy('date')
            ->orderByDesc('is_national')
            ->get()
            ->groupBy(fn (HrPublicHoliday $holiday) => $holiday->date->toDateString());

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
                'public_holidays' => ($holidaysByDate->get($dateStr) ?? collect())
                    ->map(fn (HrPublicHoliday $holiday) => [
                        'id' => $holiday->id,
                        'name' => $holiday->name,
                        'region' => $holiday->region,
                        'is_national' => (bool) $holiday->is_national,
                    ])
                    ->values(),
            ];

            $current->addDay();
        }

        // Get filter options
        $departments = HrDepartment::query()
            ->where(fn ($q) => $q->where('tenant_id', $tenantId)->orWhereNull('tenant_id'))
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name']);

        $teams = HrEmployeeProfile::query()
            ->where('tenant_id', $tenantId)
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
