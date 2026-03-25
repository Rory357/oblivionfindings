<?php

namespace App\Http\Controllers\Operations;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Shift;
use App\Models\Timesheet;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;

class DashboardController extends Controller
{
    public function __invoke(Request $request)
    {
        $auth = $request->user();
        $orgId = $auth->organization_id ?? null;

        // ── Client stats ────────────────────────────────────────────
        $totalClients = Client::query()
            ->when($orgId, fn ($q) => $q->where('organization_id', $orgId))
            ->count();

        $activeClients = Client::query()
            ->when($orgId, fn ($q) => $q->where('organization_id', $orgId))
            ->where('status', 'active')
            ->count();

        $newClientsThisMonth = Client::query()
            ->when($orgId, fn ($q) => $q->where('organization_id', $orgId))
            ->whereMonth('created_at', now()->month)
            ->whereYear('created_at', now()->year)
            ->count();

        $clientStatusBreakdown = Client::query()
            ->when($orgId, fn ($q) => $q->where('organization_id', $orgId))
            ->selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();

        // ── Shift stats ─────────────────────────────────────────────
        $today = Carbon::today();
        $weekStart = Carbon::now()->startOfWeek(Carbon::MONDAY);
        $weekEnd = Carbon::now()->endOfWeek(Carbon::SUNDAY);

        $shiftsToday = Shift::query()
            ->whereDate('starts_at', $today)
            ->selectRaw("status, COUNT(*) as count")
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();

        $shiftsTodayTotal = array_sum($shiftsToday);

        $shiftStatusBreakdown = Shift::query()
            ->where('starts_at', '>=', $weekStart)
            ->where('starts_at', '<=', $weekEnd)
            ->selectRaw("status, COUNT(*) as count")
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();

        $unassignedShifts = Shift::query()
            ->whereNull('user_id')
            ->where('starts_at', '>', now())
            ->where('status', 'scheduled')
            ->count();

        $urgentUnassigned = Shift::query()
            ->whereNull('user_id')
            ->where('starts_at', '>', now())
            ->where('starts_at', '<', now()->addHours(24))
            ->where('status', 'scheduled')
            ->count();

        // ── Hours this week ─────────────────────────────────────────
        $hoursThisWeek = Timesheet::query()
            ->where('status', 'approved')
            ->whereBetween('work_date', [$weekStart, $weekEnd])
            ->get()
            ->sum(function ($ts) {
                if ($ts->starts_at && $ts->ends_at) {
                    $hours = $ts->starts_at->diffInMinutes($ts->ends_at) / 60;
                    return max(0, $hours - ($ts->break_minutes ?? 0) / 60);
                }
                return 0;
            });

        $lastWeekStart = (clone $weekStart)->subWeek();
        $lastWeekEnd = (clone $weekEnd)->subWeek();
        $hoursLastWeek = Timesheet::query()
            ->where('status', 'approved')
            ->whereBetween('work_date', [$lastWeekStart, $lastWeekEnd])
            ->get()
            ->sum(function ($ts) {
                if ($ts->starts_at && $ts->ends_at) {
                    $hours = $ts->starts_at->diffInMinutes($ts->ends_at) / 60;
                    return max(0, $hours - ($ts->break_minutes ?? 0) / 60);
                }
                return 0;
            });

        // ── Timesheet stats ─────────────────────────────────────────
        $timesheetsPending = Timesheet::query()
            ->where('status', 'submitted')
            ->count();

        $timesheetsOverdue = Timesheet::query()
            ->where('status', 'submitted')
            ->where('submitted_at', '<', now()->subDays(3))
            ->count();

        $timesheetStatusBreakdown = Timesheet::query()
            ->where('work_date', '>=', now()->subDays(30))
            ->selectRaw("status, COUNT(*) as count")
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();

        // ── Weekly hours trend (last 8 weeks) ───────────────────────
        $weeklyHoursTrend = [];
        for ($i = 7; $i >= 0; $i--) {
            $ws = Carbon::now()->startOfWeek(Carbon::MONDAY)->subWeeks($i);
            $we = (clone $ws)->endOfWeek(Carbon::SUNDAY);
            $hours = Timesheet::query()
                ->where('status', 'approved')
                ->whereBetween('work_date', [$ws, $we])
                ->get()
                ->sum(function ($ts) {
                    if ($ts->starts_at && $ts->ends_at) {
                        return max(0, $ts->starts_at->diffInMinutes($ts->ends_at) / 60 - ($ts->break_minutes ?? 0) / 60);
                    }
                    return 0;
                });
            $weeklyHoursTrend[] = round($hours, 1);
        }

        // ── Recent activity ─────────────────────────────────────────
        $recentShifts = Shift::query()
            ->with(['client:id,first_name,last_name', 'staff:id,name'])
            ->latest('updated_at')
            ->limit(10)
            ->get()
            ->map(fn ($s) => [
                'id' => $s->id,
                'type' => 'shift',
                'status' => $s->status,
                'client' => $s->client ? $s->client->first_name . ' ' . $s->client->last_name : null,
                'staff' => $s->staff?->name,
                'starts_at' => $s->starts_at?->toISOString(),
                'ends_at' => $s->ends_at?->toISOString(),
                'updated_at' => $s->updated_at?->toISOString(),
            ]);

        $recentTimesheets = Timesheet::query()
            ->with(['client:id,first_name,last_name', 'staff:id,name'])
            ->whereIn('status', ['submitted', 'approved', 'rejected'])
            ->latest('updated_at')
            ->limit(5)
            ->get()
            ->map(fn ($ts) => [
                'id' => $ts->id,
                'type' => 'timesheet',
                'status' => $ts->status,
                'client' => $ts->client ? $ts->client->first_name . ' ' . $ts->client->last_name : null,
                'staff' => $ts->staff?->name,
                'work_date' => $ts->work_date?->toDateString(),
                'updated_at' => $ts->updated_at?->toISOString(),
            ]);

        // ── Shifts per day (next 7 days) ────────────────────────────
        $shiftsPerDay = [];
        for ($i = 0; $i < 7; $i++) {
            $day = Carbon::today()->addDays($i);
            $count = Shift::query()->whereDate('starts_at', $day)->count();
            $shiftsPerDay[] = [
                'date' => $day->format('D'),
                'count' => $count,
            ];
        }

        return Inertia::render('operations/Index', [
            'stats' => [
                'active_clients' => $activeClients,
                'total_clients' => $totalClients,
                'new_clients_this_month' => $newClientsThisMonth,
                'shifts_today_total' => $shiftsTodayTotal,
                'shifts_today' => $shiftsToday,
                'hours_this_week' => round($hoursThisWeek, 1),
                'hours_last_week' => round($hoursLastWeek, 1),
                'timesheets_pending' => $timesheetsPending,
                'timesheets_overdue' => $timesheetsOverdue,
                'unassigned_shifts' => $unassignedShifts,
                'urgent_unassigned' => $urgentUnassigned,
            ],
            'client_status_breakdown' => $clientStatusBreakdown,
            'shift_status_breakdown' => $shiftStatusBreakdown,
            'timesheet_status_breakdown' => $timesheetStatusBreakdown,
            'weekly_hours_trend' => $weeklyHoursTrend,
            'shifts_per_day' => $shiftsPerDay,
            'recent_activity' => $recentShifts->concat($recentTimesheets)
                ->sortByDesc('updated_at')
                ->values()
                ->take(15),
        ]);
    }
}
