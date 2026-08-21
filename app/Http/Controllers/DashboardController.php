<?php

namespace App\Http\Controllers;

use App\Domain\Hr\Models\HrDepartment;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrFeedPost;
use App\Domain\Hr\Models\HrLeaveRequest;
use App\Domain\Hr\Models\HrPosition;
use App\Domain\Hr\Models\HrStaffComplianceStatus;
use App\Models\Client;
use App\Models\ClientIncident;
use App\Models\IncidentFollowup;
use App\Models\Shift;
use App\Models\TimelineEvent;
use App\Models\Timesheet;
use App\Models\User;
use App\Services\Medication\MedicationGovernanceScopeService;
use App\Services\WorkstreamService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function __invoke(Request $request)
    {
        $user = $request->user();
        abort_unless($user, 403);

        // Client/next-of-kin portal users → family dashboard
        if ($user->hasRole('client', 'next_of_kin')) {
            $portalClients = $user->portalClients();
            $count = (clone $portalClients)->count();

            if ($count === 1) {
                $first = (clone $portalClients)->first();
                if ($first) {
                    return redirect()->route('portal.clients.dashboard', ['client' => $first->id]);
                }
            }

            // Multiple clients → show picker
            return redirect()->route('portal.index');
        }

        // Frontline/staff users → `/my-day` is the single canonical home.
        // Managers (`shifts.manageAny` / `timesheets.manageAny`) and HR admins
        // (`hr.analytics.view`) keep the existing dashboard. This mirrors the
        // `$mode` resolution further down so the redirect never fires for a
        // non-staff user — no redirect loops possible.
        $isManager = $user->canDo('shifts.manageAny') || $user->canDo('timesheets.manageAny');
        $isHrAdmin = $user->canDo('hr.analytics.view') && ! $user->canDo('shifts.manageAny');
        if (! $isManager && ! $isHrAdmin) {
            return redirect()->route('my-day');
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
                ->visibleToFrontline()
                ->whereBetween('starts_at', [$today, $tomorrow])
                ->orderBy('starts_at')
                ->with('staff:id,name,email')
                ->get();

            $upcomingShifts = Shift::query()
                ->where('client_id', $client->id)
                ->visibleToFrontline()
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
            ->when(!$user->canDo('shifts.manageAny'), fn($q) => $q
                ->where('user_id', $user->id)
                ->visibleToFrontline())
            ->when($clientId, fn ($q) => $q->where('client_id', $clientId))
            ->when($status && $status !== 'all', fn ($q) => $q->where('status', $status))
            ->whereBetween('starts_at', [$today, $tomorrow])
            ->orderBy('starts_at')
            ->with('client:id,first_name,last_name')
            ->get();

        $upcomingShifts = collect();
        if ($range === 'week') {
            $upcomingShifts = Shift::query()
                ->when(!$user->canDo('shifts.manageAny'), fn($q) => $q
                    ->where('user_id', $user->id)
                    ->visibleToFrontline())
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
            ->when(!$user->canDo('shifts.manageAny'), fn ($q) => $q
                ->where('user_id', $user->id)
                ->visibleToFrontline());

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

        // Determine dashboard mode
        $mode = 'staff';
        if ($user->canDo('shifts.manageAny') || $user->canDo('timesheets.manageAny')) {
            $mode = 'manager';
        }
        // HR Admin override - if user primarily has HR permissions
        if ($user->canDo('hr.analytics.view') && !$user->canDo('shifts.manageAny')) {
            $mode = 'hr_admin';
        }

        // HR quick stats for dashboard — only for manager/HR-admin surfaces.
        // PR 10 removed these from the staff-facing dashboard so the frontline
        // home stays operational; staff see their HR items on `/hr/my` instead.
        $hrWidgets = null;
        if ($mode !== 'staff' && ($user->canDo('hr.leave.viewAny') || $user->canDo('hr.performance.view') || $user->canDo('hr.compliance.view'))) {
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

        // HR Admin dashboard data
        $hrAdmin = null;
        if ($mode === 'hr_admin') {
            $headcount = HrEmployeeProfile::where('is_active', true)->count();

            // Headcount trend sparkline (last 12 months of start_date counts)
            $headcountTrend = [];
            $headcountSeries = [];
            for ($m = 11; $m >= 0; $m--) {
                $monthStart = now()->subMonths($m)->startOfMonth();
                $monthEnd = (clone $monthStart)->endOfMonth();
                $monthLabel = $monthStart->format('M Y');
                $activeAtMonth = HrEmployeeProfile::where('is_active', true)
                    ->where('start_date', '<=', $monthEnd)
                    ->where(fn ($q) => $q->whereNull('end_date')->orWhere('end_date', '>=', $monthStart))
                    ->count();
                $headcountTrend[] = $activeAtMonth;
                $headcountSeries[] = ['month' => $monthLabel, 'count' => $activeAtMonth];
            }

            $vacancies = HrPosition::where('is_active', true)
                ->whereColumn('current_headcount', '<', 'headcount_budget')
                ->count();

            $pendingLeave = HrLeaveRequest::where('status', 'pending')->count();

            // Compliance score: % of compliance statuses that are 'compliant'
            $totalCompliance = HrStaffComplianceStatus::count();
            $compliantCount = HrStaffComplianceStatus::where('status', 'compliant')->count();
            $complianceScore = $totalCompliance > 0
                ? (int) round(($compliantCount / $totalCompliance) * 100)
                : 100;

            // Department breakdown
            $departmentBreakdown = HrDepartment::query()
                ->where('is_active', true)
                ->withCount(['employees' => fn ($q) => $q->where('is_active', true)])
                ->orderByDesc('employees_count')
                ->get()
                ->map(fn ($dept) => [
                    'department' => $dept->name,
                    'count' => (int) $dept->employees_count,
                ])
                ->values();

            // Recent feed posts
            $recentFeedPosts = HrFeedPost::with('user:id,name')
                ->orderByDesc('created_at')
                ->limit(10)
                ->get()
                ->map(fn ($p) => [
                    'id' => $p->id,
                    'post_type' => $p->post_type,
                    'content' => $p->content,
                    'created_at' => $p->created_at?->toISOString(),
                    'user' => $p->user ? ['id' => $p->user->id, 'name' => $p->user->name] : null,
                ])
                ->values();

            // Expiring compliance items
            $expiringCompliance = HrStaffComplianceStatus::where('status', 'expiring_soon')
                ->with(['user:id,name', 'requirement:id,name'])
                ->orderBy('expires_at')
                ->limit(10)
                ->get()
                ->map(fn ($s) => [
                    'user_id' => $s->user_id,
                    'user_name' => $s->user?->name ?? 'Unknown',
                    'requirement_name' => $s->requirement?->name ?? 'Unknown',
                    'expires_at' => $s->expires_at?->toISOString(),
                ])
                ->values();

            $hrAdmin = [
                'headcount' => $headcount,
                'headcountTrend' => $headcountTrend,
                'vacancies' => $vacancies,
                'pendingLeave' => $pendingLeave,
                'complianceScore' => $complianceScore,
                'headcountSeries' => $headcountSeries,
                'departmentBreakdown' => $departmentBreakdown,
                'recentFeedPosts' => $recentFeedPosts,
                'expiringCompliance' => $expiringCompliance,
            ];
        }

        // Staff-specific KPIs — operational only.
        // PR 10 removed the `leaveBalance` and `compliancePercent` values that
        // previously surfaced on the staff dashboard. Staff now see those on
        // `/hr/my`, and in practice they are redirected to `/my-day` before
        // ever reaching the dashboard fallback.
        $staffKpis = null;
        if ($mode === 'staff') {
            $myShiftsToday = Shift::where('user_id', $user->id)
                ->visibleToFrontline()
                ->whereBetween('starts_at', [$today, $tomorrow])
                ->count();

            $staffKpis = [
                'myShiftsToday' => $myShiftsToday,
                'pendingTasks' => $myDayItems->count(),
            ];
        }

        // eMAR widget
        $emarWidgets = null;
        if ($user->canDo('medications.view')) {
            $medicationScope = app(MedicationGovernanceScopeService::class);
            $siteIds = $medicationScope->readerSiteIds(
                $user,
                MedicationGovernanceScopeService::MODULE_VIEW_CAPABILITY,
            );
            $todayAdminQuery = \App\Models\ClientMedicationAdministration::query()
                ->where(function ($query) use ($today): void {
                    $query->whereDate('scheduled_for', $today)
                        ->orWhereDate('administered_at', $today);
                });
            $todayAdmins = $medicationScope
                ->scopeCanonicalClientMedicationRows($todayAdminQuery, $siteIds, false)
                ->selectRaw("COUNT(*) as total, SUM(CASE WHEN status='given' THEN 1 ELSE 0 END) as given")
                ->first();
            $alertQuery = $medicationScope->scopeCanonicalClientMedicationRows(
                \App\Models\MedicationDashboardAlert::query()->where('status', 'active'),
                $siteIds,
            );
            $emarTotal = (int) ($todayAdmins->total ?? 0);
            $emarGiven = (int) ($todayAdmins->given ?? 0);
            $emarWidgets = [
                'adminRate' => $emarTotal > 0 ? round(($emarGiven / $emarTotal) * 100, 1) : 0,
                'pending' => $emarTotal - $emarGiven,
                'activeAlerts' => $alertQuery->count(),
                'overdueReviews' => \App\Models\MedicationReview::where('status', 'scheduled')
                    ->whereHas('client', fn ($query) => $query->whereIn('site_id', $siteIds))
                    ->where('scheduled_date', '<', $today->toDateString())
                    ->count(),
                'lowStock' => \App\Models\ClientMedicationStock::whereHas('medication', fn ($query) => $query
                    ->whereHas('client', fn ($client) => $client->whereIn('site_id', $siteIds))
                    ->where('state', 'active')
                    ->where('active', true))
                    ->whereNotNull('reorder_level')->whereColumn('on_hand', '<=', 'reorder_level')->count(),
            ];
        }

        return inertia('dashboard', [
            'mode' => $mode,
            'emarWidgets' => $emarWidgets,
            'hrWidgets' => $hrWidgets,
            'hrAdmin' => $hrAdmin,
            'staffKpis' => $staffKpis,
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
