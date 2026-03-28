<?php

namespace App\Http\Controllers\HealthSafety;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;

class EmergencyDrillController extends Controller
{
    /**
     * List emergency drills with filter by site, type, status.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('health-safety.view'), 403);

        $tenantId = $user->tenant_id;
        $filters = $request->only(['site_id', 'drill_type', 'status']);

        $query = \DB::table('hs_emergency_drills')
            ->where('tenant_id', $tenantId)
            ->when(!empty($filters['site_id']), fn ($q) => $q->where('site_id', $filters['site_id']))
            ->when(!empty($filters['drill_type']), fn ($q) => $q->where('drill_type', $filters['drill_type']))
            ->when(!empty($filters['status']), fn ($q) => $q->where('status', $filters['status']));

        $drills = (clone $query)
            ->leftJoin('sites', 'hs_emergency_drills.site_id', '=', 'sites.id')
            ->select('hs_emergency_drills.*', 'sites.name as site_name')
            ->orderByDesc('hs_emergency_drills.scheduled_at')
            ->paginate(25)
            ->withQueryString();

        // Stats
        $scheduled = \DB::table('hs_emergency_drills')
            ->where('tenant_id', $tenantId)
            ->where('status', 'scheduled')
            ->count();

        $sixMonthsAgo = now()->subMonths(6);
        $completedSixMo = \DB::table('hs_emergency_drills')
            ->where('tenant_id', $tenantId)
            ->where('status', 'completed')
            ->where('completed_at', '>=', $sixMonthsAgo)
            ->count();

        // Overdue sites: sites with no completed drill in last 6 months
        $allSiteIds = \DB::table('sites')
            ->where('tenant_id', $tenantId)
            ->pluck('id');

        $sitesDrilledRecently = \DB::table('hs_emergency_drills')
            ->where('tenant_id', $tenantId)
            ->where('status', 'completed')
            ->where('completed_at', '>=', $sixMonthsAgo)
            ->distinct()
            ->pluck('site_id');

        $overdueSites = $allSiteIds->diff($sitesDrilledRecently)->count();

        $avgEvacTime = \DB::table('hs_emergency_drills')
            ->where('tenant_id', $tenantId)
            ->where('status', 'completed')
            ->where('completed_at', '>=', $sixMonthsAgo)
            ->whereNotNull('evacuation_time_seconds')
            ->avg('evacuation_time_seconds');

        // Per-site compliance status
        $siteCompliance = \DB::table('sites')
            ->where('sites.tenant_id', $tenantId)
            ->leftJoin('hs_emergency_drills', function ($join) use ($sixMonthsAgo) {
                $join->on('sites.id', '=', 'hs_emergency_drills.site_id')
                    ->where('hs_emergency_drills.status', '=', 'completed')
                    ->where('hs_emergency_drills.completed_at', '>=', $sixMonthsAgo);
            })
            ->select(
                'sites.id',
                'sites.name',
                \DB::raw('COUNT(hs_emergency_drills.id) as drills_completed'),
                \DB::raw('MAX(hs_emergency_drills.completed_at) as last_drill_at')
            )
            ->groupBy('sites.id', 'sites.name')
            ->orderBy('sites.name')
            ->get()
            ->map(function ($site) {
                $site->compliant = $site->drills_completed > 0;
                return $site;
            });

        $sites = \DB::table('sites')
            ->where('tenant_id', $tenantId)
            ->orderBy('name')
            ->get(['id', 'name']);

        $staff = \DB::table('users')
            ->where('tenant_id', $tenantId)
            ->where('status', 'active')
            ->orderBy('name')
            ->get(['id', 'name']);

        return Inertia::render('health-safety/drills/index', [
            'drills' => $drills,
            'stats' => [
                'scheduled' => $scheduled,
                'completed_6mo' => $completedSixMo,
                'overdue_sites' => $overdueSites,
                'avg_evacuation_time' => $avgEvacTime ? round($avgEvacTime) : null,
            ],
            'siteCompliance' => $siteCompliance,
            'sites' => $sites,
            'staff' => $staff,
            'filters' => $filters,
        ]);
    }

    /**
     * Show the create form.
     */
    public function create(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('health-safety.manage'), 403);

        $sites = \DB::table('sites')
            ->where('tenant_id', $user->tenant_id)
            ->orderBy('name')
            ->get(['id', 'name']);

        $staff = \DB::table('users')
            ->where('tenant_id', $user->tenant_id)
            ->where('status', 'active')
            ->orderBy('name')
            ->get(['id', 'name']);

        return Inertia::render('health-safety/drills/create', [
            'sites' => $sites,
            'staff' => $staff,
        ]);
    }

    /**
     * Store a new emergency drill.
     */
    public function store(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('health-safety.manage'), 403);

        $validated = $request->validate([
            'site_id' => ['required', 'exists:sites,id'],
            'drill_type' => ['required', 'string', 'in:fire,earthquake,lockdown,tsunami,chemical_spill,medical_emergency,other'],
            'title' => ['required', 'string', 'max:255'],
            'scheduled_at' => ['required', 'date'],
            'scenario_description' => ['nullable', 'string', 'max:5000'],
            'objectives' => ['nullable', 'array'],
            'objectives.*' => ['string', 'max:500'],
            'coordinator_id' => ['nullable', 'exists:users,id'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        if (isset($validated['objectives'])) {
            $validated['objectives'] = json_encode($validated['objectives']);
        }

        \DB::table('hs_emergency_drills')->insert(array_merge($validated, [
            'tenant_id' => $user->tenant_id,
            'status' => 'scheduled',
            'created_by' => $user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]));

        return redirect()->route('health-safety.drills.index')
            ->with('success', 'Emergency drill scheduled successfully.');
    }

    /**
     * Show a specific drill with participants and findings.
     */
    public function show(Request $request, int $drill)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('health-safety.view'), 403);

        $record = \DB::table('hs_emergency_drills')
            ->leftJoin('sites', 'hs_emergency_drills.site_id', '=', 'sites.id')
            ->where('hs_emergency_drills.id', $drill)
            ->where('hs_emergency_drills.tenant_id', $user->tenant_id)
            ->select('hs_emergency_drills.*', 'sites.name as site_name')
            ->firstOrFail();

        $participants = \DB::table('hs_emergency_drill_participants')
            ->leftJoin('users', 'hs_emergency_drill_participants.user_id', '=', 'users.id')
            ->where('hs_emergency_drill_participants.drill_id', $drill)
            ->select('hs_emergency_drill_participants.*', 'users.name as user_name')
            ->get();

        $findings = \DB::table('hs_emergency_drill_findings')
            ->leftJoin('users', 'hs_emergency_drill_findings.assigned_to', '=', 'users.id')
            ->where('hs_emergency_drill_findings.drill_id', $drill)
            ->select('hs_emergency_drill_findings.*', 'users.name as assigned_to_name')
            ->orderByDesc('hs_emergency_drill_findings.created_at')
            ->get();

        $staff = \DB::table('users')
            ->where('tenant_id', $user->tenant_id)
            ->where('status', 'active')
            ->orderBy('name')
            ->get(['id', 'name']);

        return Inertia::render('health-safety/drills/show', [
            'drill' => $record,
            'participants' => $participants,
            'findings' => $findings,
            'staff' => $staff,
        ]);
    }

    /**
     * Update a drill (complete it, add results).
     */
    public function update(Request $request, int $drill)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('health-safety.manage'), 403);

        $record = \DB::table('hs_emergency_drills')
            ->where('id', $drill)
            ->where('tenant_id', $user->tenant_id)
            ->firstOrFail();

        $validated = $request->validate([
            'status' => ['sometimes', 'string', 'in:scheduled,in_progress,completed,cancelled'],
            'completed_at' => ['sometimes', 'nullable', 'date'],
            'evacuation_time_seconds' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'total_participants' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'overall_rating' => ['sometimes', 'nullable', 'string', 'in:excellent,good,adequate,poor'],
            'lessons_learned' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);

        \DB::table('hs_emergency_drills')
            ->where('id', $drill)
            ->update(array_merge($validated, [
                'updated_at' => now(),
            ]));

        return redirect()->back()->with('success', 'Drill updated successfully.');
    }

    /**
     * Add a participant to a drill.
     */
    public function addParticipant(Request $request, int $drill)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('health-safety.manage'), 403);

        $record = \DB::table('hs_emergency_drills')
            ->where('id', $drill)
            ->where('tenant_id', $user->tenant_id)
            ->firstOrFail();

        $validated = $request->validate([
            'user_id' => ['required', 'exists:users,id'],
            'role' => ['nullable', 'string', 'max:100'],
            'attended' => ['boolean'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        \DB::table('hs_emergency_drill_participants')->insert(array_merge($validated, [
            'drill_id' => $drill,
            'created_at' => now(),
            'updated_at' => now(),
        ]));

        return redirect()->back()->with('success', 'Participant added successfully.');
    }

    /**
     * Add a finding with corrective action.
     */
    public function addFinding(Request $request, int $drill)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('health-safety.manage'), 403);

        $record = \DB::table('hs_emergency_drills')
            ->where('id', $drill)
            ->where('tenant_id', $user->tenant_id)
            ->firstOrFail();

        $validated = $request->validate([
            'description' => ['required', 'string', 'max:2000'],
            'severity' => ['required', 'string', 'in:critical,high,medium,low'],
            'corrective_action' => ['nullable', 'string', 'max:2000'],
            'assigned_to' => ['nullable', 'exists:users,id'],
            'due_date' => ['nullable', 'date'],
        ]);

        \DB::table('hs_emergency_drill_findings')->insert(array_merge($validated, [
            'drill_id' => $drill,
            'status' => 'open',
            'created_by' => $user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]));

        return redirect()->back()->with('success', 'Finding recorded successfully.');
    }

    /**
     * Update a finding status/resolution.
     */
    public function updateFinding(Request $request, int $finding)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('health-safety.manage'), 403);

        $record = \DB::table('hs_emergency_drill_findings')
            ->join('hs_emergency_drills', 'hs_emergency_drill_findings.drill_id', '=', 'hs_emergency_drills.id')
            ->where('hs_emergency_drill_findings.id', $finding)
            ->where('hs_emergency_drills.tenant_id', $user->tenant_id)
            ->select('hs_emergency_drill_findings.*')
            ->firstOrFail();

        $validated = $request->validate([
            'status' => ['sometimes', 'string', 'in:open,in_progress,resolved,closed'],
            'resolution' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'resolved_at' => ['sometimes', 'nullable', 'date'],
            'corrective_action' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'assigned_to' => ['sometimes', 'nullable', 'exists:users,id'],
            'due_date' => ['sometimes', 'nullable', 'date'],
        ]);

        \DB::table('hs_emergency_drill_findings')
            ->where('id', $finding)
            ->update(array_merge($validated, [
                'updated_at' => now(),
            ]));

        return redirect()->back()->with('success', 'Finding updated successfully.');
    }
}
