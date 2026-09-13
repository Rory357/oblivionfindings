<?php

namespace App\Http\Controllers\Operations;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Operations\OperationsDashboardScopeService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
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

        // One window for the feed AND the header instruments: the last 7
        // calendar days, so the meter counts/series match what the feed shows.
        $since = now()->subDays(6)->startOfDay();

        $activities = collect();

        // Recent shifts (completed, started, cancelled)
        if ($filter === 'all' || $filter === 'shifts') {
            $shifts = $this->scope->shifts($auth)
                ->with(['client:id,first_name,last_name', 'staff:id,name'])
                ->whereIn('status', ['completed', 'in_progress', 'cancelled'])
                ->where('updated_at', '>=', $since)
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
                ->where('updated_at', '>=', $since)
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
                ->where('created_at', '>=', $since)
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
            'summary' => $this->summary($auth, $since),
        ]);
    }

    /**
     * Header instruments for the Event Horizon band — per-stream totals,
     * status breakdowns and per-day series over the same window as the
     * feed, computed regardless of the active view filter so the rail
     * counts stay honest on every tab.
     */
    private function summary(User $auth, Carbon $since): array
    {
        $days = collect(range(6, 0))
            ->map(fn (int $i) => now()->subDays($i)->toDateString());

        $shiftRows = $this->scope->shifts($auth)
            ->whereIn('status', ['completed', 'in_progress', 'cancelled'])
            ->where('updated_at', '>=', $since)
            ->selectRaw('status, DATE(updated_at) as day, COUNT(*) as total')
            ->groupBy('status', 'day')
            ->get();

        $timesheetRows = $this->scope->timesheets($auth)
            ->whereIn('status', ['submitted', 'approved', 'rejected'])
            ->where('updated_at', '>=', $since)
            ->selectRaw('status, DATE(updated_at) as day, COUNT(*) as total')
            ->groupBy('status', 'day')
            ->get();

        $clientRows = $this->scope->clients($auth)
            ->where('created_at', '>=', $since)
            ->selectRaw('DATE(created_at) as day, COUNT(*) as total')
            ->groupBy('day')
            ->get();

        $series = fn ($rows) => $days
            ->map(fn (string $day) => (int) $rows->where('day', $day)->sum('total'))
            ->values()
            ->all();
        $byStatus = fn ($rows, string $status) => (int) $rows->where('status', $status)->sum('total');

        return [
            'shifts' => [
                'total' => (int) $shiftRows->sum('total'),
                'series' => $series($shiftRows),
                'completed' => $byStatus($shiftRows, 'completed'),
                'started' => $byStatus($shiftRows, 'in_progress'),
                'cancelled' => $byStatus($shiftRows, 'cancelled'),
            ],
            'timesheets' => [
                'total' => (int) $timesheetRows->sum('total'),
                'series' => $series($timesheetRows),
                'submitted' => $byStatus($timesheetRows, 'submitted'),
                'approved' => $byStatus($timesheetRows, 'approved'),
                'rejected' => $byStatus($timesheetRows, 'rejected'),
            ],
            'clients' => [
                'total' => (int) $clientRows->sum('total'),
                'series' => $series($clientRows),
            ],
        ];
    }
}
