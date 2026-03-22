<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\ClientIncident;
use App\Models\IncidentFollowup;
use App\Models\Shift;
use App\Models\TimelineEvent;
use App\Models\Timesheet;
use App\Models\User;
use App\Services\WorkstreamService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function __invoke(Request $request)
    {
        $user = $request->user();
        abort_unless($user, 403);

        // Client/next-of-kin portal users
        if ($user->hasRole('client', 'next_of_kin')) {
            // If they're linked to exactly one client, drop them straight into that profile.
            // If they're linked to multiple (common for next-of-kin), show a picker.
            $portalClients = $user->portalClients();
            $count = (clone $portalClients)->count();

            if ($count === 1) {
                $first = (clone $portalClients)->first();
                if ($first) {
                    return redirect()->route('portal.clients.show', ['client' => $first->id]);
                }
            }

            return redirect()->route('portal.index');
        }

        $today = now()->startOfDay();
        $tomorrow = (clone $today)->addDay();
        $weekEnd = (clone $today)->addDays(config('dashboard.short_range_days', 7));

        // Dashboard filters (used mainly for staff workflow)
        $range = (string) ($request->query('range') ?? 'week'); // today|week
        if (!in_array($range, ['today', 'week'], true)) {
            $range = 'week';
        }
        $status = $request->query('status'); // scheduled|in_progress|completed|cancelled|all
        if ($status && !in_array($status, ['scheduled', 'in_progress', 'completed', 'cancelled', 'all'], true)) {
            $status = null;
        }
        $clientId = $request->query('client_id');
        $clientId = $clientId ? (int) $clientId : null;

        // Legacy: if there's a Client linked directly to this user (older installs)
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

        $upcomingEvents = TimelineEvent::query()
            ->where('actor_user_id', $user->id)
            ->whereBetween('occurred_at', [$today, $weekEnd])
            ->orderBy('occurred_at')
            ->with(['client:id,first_name,last_name', 'site:id,name'])
            ->limit(config('dashboard.max_upcoming_events', 200))
            ->get();

        $todayShifts = Shift::query()
            ->when(!$user->canDo('shifts.manageAny'), fn($q) => $q->where('user_id', $user->id))
            ->when($clientId, fn ($q) => $q->where('client_id', $clientId))
            ->when($status && $status !== 'all', fn ($q) => $q->where('status', $status))
            ->whereBetween('starts_at', [$today, $tomorrow])
            ->orderBy('starts_at')
            ->with('client:id,first_name,last_name')
            ->get();

        $upcomingShifts = collect();
        if ($range === 'week') {
            $upcomingShifts = Shift::query()
                ->when(!$user->canDo('shifts.manageAny'), fn($q) => $q->where('user_id', $user->id))
                ->when($clientId, fn ($q) => $q->where('client_id', $clientId))
                ->when($status && $status !== 'all', fn ($q) => $q->where('status', $status))
                ->whereBetween('starts_at', [$today, $weekEnd])
                ->orderBy('starts_at')
                ->with('client:id,first_name,last_name')
                ->limit(config('dashboard.max_upcoming_shifts', 75))
                ->get();
        }

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

        // Dashboard analytics (used for graphs). Only include what the user is allowed to see.
        $canSeeIncidents = $user->canDo('incidents.viewAny') || $user->canDo('incidents.viewAssigned');

        // We expose both short-range (next 7 days) and rolling history (last 30 days)
        // so the dashboard can render richer graphs without extra API calls.
        $range7Start = (clone $today);
        $range7End = (clone $weekEnd);
        $range30Start = (clone $today)->subDays(config('dashboard.history_days', 30));
        $range30End = (clone $tomorrow);
        $driver = DB::connection()->getDriverName();
        $shiftHoursExpr = $driver === 'sqlite'
            ? "SUM((strftime('%s', ends_at) - strftime('%s', starts_at)) / 3600.0)"
            : "SUM(TIMESTAMPDIFF(MINUTE, starts_at, ends_at)) / 60";
        $timesheetHoursExpr = $driver === 'sqlite'
            ? "SUM(((strftime('%s', ends_at) - strftime('%s', starts_at)) / 60.0 - COALESCE(break_minutes, 0)) / 60.0)"
            : 'SUM((TIMESTAMPDIFF(MINUTE, starts_at, ends_at) - COALESCE(break_minutes, 0)))/60';

        $shiftScope = Shift::query()
            ->when(!$user->canDo('shifts.manageAny'), fn ($q) => $q->where('user_id', $user->id));

        $shiftSeries = (clone $shiftScope)
            ->whereBetween('starts_at', [$range7Start, $range7End])
            ->selectRaw('DATE(starts_at) as d')
            ->selectRaw('COUNT(*) as c')
            ->selectRaw("{$shiftHoursExpr} as h")
            ->selectRaw("SUM(CASE WHEN status = 'scheduled' THEN 1 ELSE 0 END) as scheduled")
            ->selectRaw("SUM(CASE WHEN status = 'in_progress' THEN 1 ELSE 0 END) as in_progress")
            ->selectRaw("SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed")
            ->selectRaw("SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled")
            ->groupBy('d')
            ->orderBy('d')
            ->get()
            ->map(fn ($r) => [
                'date' => (string) $r->d,
                'count' => (int) ($r->c ?? 0),
                'hours' => (float) ($r->h ?? 0),
                'status' => [
                    'scheduled' => (int) ($r->scheduled ?? 0),
                    'in_progress' => (int) ($r->in_progress ?? 0),
                    'completed' => (int) ($r->completed ?? 0),
                    'cancelled' => (int) ($r->cancelled ?? 0),
                ],
            ])
            ->values();

        $shiftSeries30 = (clone $shiftScope)
            ->whereBetween('starts_at', [$range30Start, $range30End])
            ->selectRaw('DATE(starts_at) as d')
            ->selectRaw('COUNT(*) as c')
            ->selectRaw("{$shiftHoursExpr} as h")
            ->selectRaw("SUM(CASE WHEN status = 'scheduled' THEN 1 ELSE 0 END) as scheduled")
            ->selectRaw("SUM(CASE WHEN status = 'in_progress' THEN 1 ELSE 0 END) as in_progress")
            ->selectRaw("SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed")
            ->selectRaw("SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled")
            ->groupBy('d')
            ->orderBy('d')
            ->get()
            ->map(fn ($r) => [
                'date' => (string) $r->d,
                'count' => (int) ($r->c ?? 0),
                'hours' => (float) ($r->h ?? 0),
                'status' => [
                    'scheduled' => (int) ($r->scheduled ?? 0),
                    'in_progress' => (int) ($r->in_progress ?? 0),
                    'completed' => (int) ($r->completed ?? 0),
                    'cancelled' => (int) ($r->cancelled ?? 0),
                ],
            ])
            ->values();

        $timesheetScope = Timesheet::query()
            ->when(!$user->canDo('timesheets.manageAny'), fn ($q) => $q->where('user_id', $user->id));

        $timesheetByStatus = (clone $timesheetScope)
            ->select('status', DB::raw('COUNT(*) as c'))
            ->groupBy('status')
            ->get()
            ->map(fn ($r) => ['status' => (string) $r->status, 'count' => (int) $r->c])
            ->values();

        $timesheetSeries30 = (clone $timesheetScope)
            ->whereBetween('work_date', [$range30Start->toDateString(), $today->toDateString()])
            ->selectRaw('DATE(work_date) as d')
            ->selectRaw('COUNT(*) as c')
            ->selectRaw("{$timesheetHoursExpr} as h")
            ->groupBy('d')
            ->orderBy('d')
            ->get()
            ->map(fn ($r) => [
                'date' => (string) $r->d,
                'count' => (int) ($r->c ?? 0),
                'hours' => (float) ($r->h ?? 0),
            ])
            ->values();

        $incidentSeries = collect();
        $incidentSeries30 = collect();
        $incidentBySeverity30 = collect();
        $incidentKpis = null;
        if ($canSeeIncidents) {
            $incidentStart = (clone $today)->subDays(config('dashboard.incident_short_days', 14));
            $incidentStart30 = (clone $today)->subDays(config('dashboard.incident_history_days', 30));

            $incidentQuery = ClientIncident::query()->whereBetween('occurred_at', [$incidentStart, $tomorrow]);
            if ($user->canDo('incidents.viewAssigned') && !$user->canDo('incidents.viewAny')) {
                $assignedIds = $user->assignedClients()->pluck('clients.id')->values();
                $incidentQuery->whereIn('client_id', $assignedIds);
            }

            $incidentSeries = $incidentQuery
                ->selectRaw('DATE(occurred_at) as d')
                ->selectRaw('COUNT(*) as c')
                ->groupBy('d')
                ->orderBy('d')
                ->get()
                ->map(fn ($r) => ['date' => (string) $r->d, 'count' => (int) ($r->c ?? 0)])
                ->values();

            $incidentQuery30 = ClientIncident::query()->whereBetween('occurred_at', [$incidentStart30, $tomorrow]);
            if ($user->canDo('incidents.viewAssigned') && !$user->canDo('incidents.viewAny')) {
                $assignedIds = $user->assignedClients()->pluck('clients.id')->values();
                $incidentQuery30->whereIn('client_id', $assignedIds);
            }

            $incidentSeries30 = (clone $incidentQuery30)
                ->selectRaw('DATE(occurred_at) as d')
                ->selectRaw('COUNT(*) as c')
                ->groupBy('d')
                ->orderBy('d')
                ->get()
                ->map(fn ($r) => ['date' => (string) $r->d, 'count' => (int) ($r->c ?? 0)])
                ->values();

            $incidentBySeverity30 = (clone $incidentQuery30)
                ->select('severity', DB::raw('COUNT(*) as c'))
                ->groupBy('severity')
                ->orderByDesc('c')
                ->get()
                ->map(fn ($r) => ['severity' => (string) ($r->severity ?? 'unspecified'), 'count' => (int) ($r->c ?? 0)])
                ->values();

            // KPI-style metrics used for manager/admin cards.
            // Scoped to the incidents the current user is allowed to see.
            $incidentKpis = [
                'incidentsLast30' => (clone $incidentQuery30)->count(),
                'incidentsHighLast30' => (clone $incidentQuery30)->where('severity', 'high')->count(),
                'reviewedLast30' => (clone $incidentQuery30)->whereNotNull('reviewed_at')->count(),
                'unreviewedLast30' => (clone $incidentQuery30)->whereNull('reviewed_at')->count(),
            ];

            // Follow-up KPIs: open + overdue (also scoped).
            $followupQuery = IncidentFollowup::query()->whereHas('incident', function ($q) use ($incidentQuery30) {
                // Mirror the same scope as $incidentQuery30, without re-running the full builder logic.
                // We do this by constraining to the incident IDs from the scoped query.
                $q->whereIn('id', (clone $incidentQuery30)->select('id'));
            });

            $incidentKpis['followupsOpen'] = (clone $followupQuery)->whereNull('completed_at')->count();
            $incidentKpis['followupsOverdue'] = (clone $followupQuery)
                ->whereNull('completed_at')
                ->whereNotNull('due_at')
                ->where('due_at', '<', now())
                ->count();
        }

        // Unified workstream (My Day) for staff workflow
        $workstreamTo = $range === 'today' ? (clone $tomorrow) : (clone $weekEnd);
        $myDayItems = app(WorkstreamService::class)
            ->forStaff($user, (clone $today), $workstreamTo)
            ->take(200)
            ->values();

        // HR quick stats for dashboard
        $hrWidgets = null;
        if ($user->canDo('hr.leave.viewAny') || $user->canDo('hr.performance.view') || $user->canDo('hr.compliance.view')) {
            $hrWidgets = [
                'pending_leave' => \App\Domain\Hr\Models\HrLeaveRequest::where('status', 'pending')->count(),
                'expiring_compliance' => \App\Domain\Hr\Models\HrStaffComplianceStatus::where('status', 'expiring_soon')->count(),
                'pending_signatures' => \App\Domain\Hr\Models\HrDocumentSignature::where('signer_user_id', $user->id)->where('status', 'pending')->count(),
                'due_attestations' => \App\Domain\Hr\Models\HrPolicy::where('is_active', true)
                    ->where('requires_attestation', true)
                    ->whereDoesntHave('attestations', fn ($q) => $q->where('user_id', $user->id))
                    ->count(),
            ];
        }

        return inertia('dashboard', [
            'mode' => $user->canDo('shifts.manageAny') || $user->canDo('timesheets.manageAny') ? 'manager' : 'staff',
            'hrWidgets' => $hrWidgets,
            'filters' => [
                'range' => $range,
                'status' => $status ?? 'all',
                'client_id' => $clientId,
            ],
            'assignedClients' => $assignedClients,
            'myDayItems' => $myDayItems,
            'todayShifts' => $todayShifts,
            'upcomingShifts' => $upcomingShifts,
            'upcomingEvents' => $upcomingEvents->map(function ($e) {
                return [
                    'id' => $e->id,
                    'type' => $e->type,
                    'occurred_at' => optional($e->occurred_at)->toISOString(),
                    'subject' => $e->subject,
                    'body' => $e->body,
                    'meta' => $e->meta ?? [],
                    'client' => $e->client ? ['id' => $e->client->id, 'first_name' => $e->client->first_name, 'last_name' => $e->client->last_name] : null,
                    'site' => $e->site ? ['id' => $e->site->id, 'name' => $e->site->name] : null,
                ];
            })->values(),
            'todayTimesheets' => $todayTimesheets,
            'managerSummary' => $managerSummary,
            'incidentKpis' => $incidentKpis,
            'analytics' => [
                'shiftSeries' => $shiftSeries,
                'shiftSeries30' => $shiftSeries30,
                'incidentSeries' => $incidentSeries,
                'incidentSeries30' => $incidentSeries30,
                'incidentBySeverity30' => $incidentBySeverity30,
                'timesheetByStatus' => $timesheetByStatus,
                'timesheetSeries30' => $timesheetSeries30,
            ],
        ]);
    }
}
