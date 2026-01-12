<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Shift;
use App\Models\Timesheet;
use App\Models\User;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __invoke(Request $request)
    {
        $user = $request->user();
        abort_unless($user, 403);

        $today = now()->startOfDay();
        $tomorrow = (clone $today)->addDay();
        $weekEnd = (clone $today)->addDays(7);

        // Determine dashboard mode:
        // - client-mode if there's a Client linked to this user
        // - otherwise staff/admin mode
        $client = Client::query()->where('user_id', $user->id)->first();

        if ($client) {
            $client->load(['supportWorkers:id,name,email']);
            $todayShifts = Shift::query()
                ->where('client_id', $client->id)
                ->whereBetween('starts_at', [$today, $tomorrow])
                ->orderBy('starts_at')
                ->with('staff:id,name,email')
                ->get();

            $upcomingShifts = Shift::query()
                ->where('client_id', $client->id)
                ->whereBetween('starts_at', [$today, $weekEnd])
                ->orderBy('starts_at')
                ->with('staff:id,name,email')
                ->get();

            return inertia('dashboard', [
                'mode' => 'client',
                'client' => $client->only(['id', 'first_name', 'last_name', 'status']),
                'assignedStaff' => $client->supportWorkers,
                'todayShifts' => $todayShifts,
                'upcomingShifts' => $upcomingShifts,
            ]);
        }

        // Staff/admin
        $assignedClients = $user->assignedClients()->get(['clients.id', 'first_name', 'last_name', 'status']);

        $todayShifts = Shift::query()
            ->when(!$user->canDo('shifts.manageAny'), fn($q) => $q->where('user_id', $user->id))
            ->whereBetween('starts_at', [$today, $tomorrow])
            ->orderBy('starts_at')
            ->with('client:id,first_name,last_name')
            ->get();

        $upcomingShifts = Shift::query()
            ->when(!$user->canDo('shifts.manageAny'), fn($q) => $q->where('user_id', $user->id))
            ->whereBetween('starts_at', [$today, $weekEnd])
            ->orderBy('starts_at')
            ->with('client:id,first_name,last_name')
            ->limit(75)
            ->get();

        $todayTimesheets = Timesheet::query()
            ->when(!$user->canDo('timesheets.manageAny'), fn($q) => $q->where('user_id', $user->id))
            ->whereDate('work_date', $today->toDateString())
            ->orderByDesc('created_at')
            ->with('client:id,first_name,last_name')
            ->get();

        // Manager summary
        $managerSummary = null;
        if ($user->canDo('timesheets.manageAny') || $user->canDo('shifts.manageAny')) {
            $managerSummary = [
                'shiftsTodayCount' => Shift::query()->whereBetween('starts_at', [$today, $tomorrow])->count(),
                'staffWorkingTodayCount' => Shift::query()->whereBetween('starts_at', [$today, $tomorrow])->distinct('user_id')->count('user_id'),
                'timesheetsPendingCount' => Timesheet::query()->where('status', 'submitted')->count(),
            ];
        }

        return inertia('dashboard', [
            'mode' => $user->canDo('shifts.manageAny') || $user->canDo('timesheets.manageAny') ? 'manager' : 'staff',
            'assignedClients' => $assignedClients,
            'todayShifts' => $todayShifts,
            'todayTimesheets' => $todayTimesheets,
            'managerSummary' => $managerSummary,
        ]);
    }
}
