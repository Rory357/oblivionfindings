<?php

namespace App\Http\Controllers\Operations;

use App\Http\Controllers\Controller;
use App\Services\Operations\OperationsDashboardScopeService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ActivityFeedController extends Controller
{
    public function __construct(
        private readonly OperationsDashboardScopeService $scope,
    ) {}

    public function index(Request $request)
    {
        $auth = $this->scope->authorize($request->user());

        $filter = $request->get('filter', 'all');
        $perPage = 25;

        $activities = collect();

        // Recent shifts (completed, started, cancelled)
        if ($filter === 'all' || $filter === 'shifts') {
            $shifts = $this->scope->shifts($auth)
                ->with(['client:id,first_name,last_name', 'staff:id,name'])
                ->whereIn('status', ['completed', 'in_progress', 'cancelled'])
                ->where('updated_at', '>=', now()->subDays(7))
                ->latest('updated_at')
                ->limit(50)
                ->get()
                ->map(fn ($s) => [
                    'id' => 'shift-'.$s->id,
                    'type' => 'shift',
                    'action' => match ($s->status) {
                        'completed' => 'completed',
                        'in_progress' => 'started',
                        'cancelled' => 'cancelled',
                        default => 'updated',
                    },
                    'title' => 'Shift '.$s->status,
                    'description' => ($s->staff?->name ?? 'Unassigned').' — '.
                        ($s->client ? $s->client->first_name.' '.$s->client->last_name : 'No client'),
                    'timestamp' => $s->updated_at?->toISOString(),
                    'link' => '/operations/shifts/'.$s->id,
                ]);
            $activities = $activities->concat($shifts);
        }

        // Recent timesheet submissions/approvals
        if ($filter === 'all' || $filter === 'timesheets') {
            $timesheets = $this->scope->timesheets($auth)
                ->with(['client:id,first_name,last_name', 'staff:id,name'])
                ->whereIn('status', ['submitted', 'approved', 'rejected'])
                ->where('updated_at', '>=', now()->subDays(7))
                ->latest('updated_at')
                ->limit(50)
                ->get()
                ->map(fn ($ts) => [
                    'id' => 'timesheet-'.$ts->id,
                    'type' => 'timesheet',
                    'action' => $ts->status,
                    'title' => 'Timesheet '.$ts->status,
                    'description' => ($ts->staff?->name ?? 'Unknown').' — '.
                        ($ts->work_date?->format('d M Y') ?? ''),
                    'timestamp' => $ts->updated_at?->toISOString(),
                    'link' => '/operations/timesheets/'.$ts->id,
                ]);
            $activities = $activities->concat($timesheets);
        }

        // New clients
        if ($filter === 'all' || $filter === 'clients') {
            $newClients = $this->scope->clients($auth)
                ->where('created_at', '>=', now()->subDays(7))
                ->latest('created_at')
                ->limit(20)
                ->get()
                ->map(fn ($c) => [
                    'id' => 'client-'.$c->id,
                    'type' => 'client',
                    'action' => 'created',
                    'title' => 'New client added',
                    'description' => $c->first_name.' '.$c->last_name,
                    'timestamp' => $c->created_at?->toISOString(),
                    'link' => '/operations/clients/'.$c->id,
                ]);
            $activities = $activities->concat($newClients);
        }

        $sorted = $activities->sortByDesc('timestamp')->values()->take($perPage);

        return Inertia::render('operations/activity/Index', [
            'activities' => $sorted,
            'filter' => $filter,
        ]);
    }
}
