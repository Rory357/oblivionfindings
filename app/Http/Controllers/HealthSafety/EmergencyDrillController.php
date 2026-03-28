<?php

namespace App\Http\Controllers\HealthSafety;

use App\Http\Controllers\Controller;
use App\Models\EmergencyDrill;
use App\Models\EmergencyDrillFinding;
use App\Models\EmergencyDrillParticipant;
use App\Models\Site;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class EmergencyDrillController extends Controller
{
    /**
     * List emergency drills with filter by site, type, status.
     */
    public function index(Request $request): \Inertia\Response
    {
        $filters = $request->only(['q', 'site_id', 'drill_type', 'status']);

        $drills = EmergencyDrill::with('site:id,name')
            ->withCount(['participants', 'findings'])
            ->when(!empty($filters['q']), function ($q) use ($filters) {
                $search = $filters['q'];
                $q->where(function ($q2) use ($search) {
                    $q2->where('title', 'like', "%{$search}%")
                       ->orWhere('drill_type', 'like', "%{$search}%");
                });
            })
            ->when(!empty($filters['site_id']), fn ($q) => $q->where('site_id', $filters['site_id']))
            ->when(!empty($filters['drill_type']), fn ($q) => $q->where('drill_type', $filters['drill_type']))
            ->when(!empty($filters['status']), fn ($q) => $q->where('status', $filters['status']))
            ->orderByDesc('scheduled_at')
            ->paginate(25)
            ->withQueryString();

        // Stats
        $scheduled = EmergencyDrill::where('status', 'scheduled')->count();

        $sixMonthsAgo = now()->subMonths(6);
        $completedSixMo = EmergencyDrill::where('status', 'completed')
            ->where('completed_at', '>=', $sixMonthsAgo)
            ->count();

        // Overdue sites: sites with no completed drill in last 6 months
        $allSiteIds = Site::pluck('id');
        $sitesDrilledRecently = EmergencyDrill::where('status', 'completed')
            ->where('completed_at', '>=', $sixMonthsAgo)
            ->distinct()
            ->pluck('site_id');
        $overdueSites = $allSiteIds->diff($sitesDrilledRecently)->count();

        $avgEvacTime = EmergencyDrill::where('status', 'completed')
            ->where('completed_at', '>=', $sixMonthsAgo)
            ->whereNotNull('evacuation_time_seconds')
            ->avg('evacuation_time_seconds');

        // Per-site compliance status
        $siteCompliance = Site::select('id', 'name')
            ->orderBy('name')
            ->get()
            ->map(function ($site) use ($sixMonthsAgo) {
                $lastDrillAt = EmergencyDrill::where('site_id', $site->id)
                    ->where('status', 'completed')
                    ->max('completed_at');

                $lastDrillDate = $lastDrillAt ? \Carbon\Carbon::parse($lastDrillAt) : null;

                if ($lastDrillDate && $lastDrillDate->gte($sixMonthsAgo)) {
                    $status = 'compliant';
                } elseif ($lastDrillDate && $lastDrillDate->gte($sixMonthsAgo->copy()->subMonth())) {
                    $status = 'due_soon';
                } else {
                    $status = 'overdue';
                }

                return [
                    'id' => $site->id,
                    'name' => $site->name,
                    'last_drill_date' => $lastDrillAt,
                    'status' => $status,
                ];
            });

        return Inertia::render('health-safety/drills/index', [
            'drills' => $drills,
            'stats' => [
                'scheduled_drills' => $scheduled,
                'completed_6mo' => $completedSixMo,
                'sites_overdue' => $overdueSites,
                'avg_evacuation_time' => $avgEvacTime ? (string) round($avgEvacTime) . 's' : '-',
            ],
            'site_compliance' => $siteCompliance,
            'sites' => Site::select('id', 'name')->where('is_active', true)->orderBy('name')->get(),
            'filters' => $filters,
        ]);
    }

    /**
     * Show the create form.
     */
    public function create(): \Inertia\Response
    {
        return Inertia::render('health-safety/drills/create', [
            'sites' => Site::select('id', 'name')->where('is_active', true)->orderBy('name')->get(),
        ]);
    }

    /**
     * Store a new emergency drill.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'site_id' => ['required', 'exists:sites,id'],
            'drill_type' => ['required', 'string', 'in:fire,earthquake,lockdown,tsunami,chemical_spill,medical_emergency,other'],
            'title' => ['required', 'string', 'max:255'],
            'scheduled_at' => ['required', 'date'],
            'scenario_description' => ['nullable', 'string', 'max:5000'],
            'conducted_by' => ['nullable', 'exists:users,id'],
        ]);

        EmergencyDrill::create(array_merge($validated, [
            'status' => 'scheduled',
            'created_by' => $request->user()->id,
        ]));

        return redirect()->route('health-safety.drills.index')
            ->with('success', 'Emergency drill scheduled successfully.');
    }

    /**
     * Show a specific drill with participants and findings.
     */
    public function show(EmergencyDrill $drill): \Inertia\Response
    {
        $drill->load(['site:id,name', 'conductor:id,name']);

        $participants = $drill->participants()
            ->with('user:id,name')
            ->get();

        $findings = $drill->findings()
            ->with('assignee:id,name')
            ->orderByDesc('created_at')
            ->get();

        $drillData = $drill->toArray();
        $drillData['participants'] = $participants;
        $drillData['findings'] = $findings;

        return Inertia::render('health-safety/drills/show', [
            'drill' => $drillData,
            'staff' => User::select('id', 'name')->orderBy('name')->get(),
        ]);
    }

    /**
     * Update a drill (complete it, add results).
     */
    public function update(Request $request, EmergencyDrill $drill): RedirectResponse
    {
        $validated = $request->validate([
            'status' => ['sometimes', 'string', 'in:scheduled,in_progress,completed,cancelled'],
            'completed_at' => ['sometimes', 'nullable', 'date'],
            'evacuation_time_seconds' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'total_participants' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'observer_notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'improvements_identified' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ]);

        $drill->update($validated);

        return redirect()->back()->with('success', 'Drill updated successfully.');
    }

    /**
     * Add a participant to a drill.
     */
    public function addParticipant(Request $request, EmergencyDrill $drill): RedirectResponse
    {
        $validated = $request->validate([
            'user_id' => ['required', 'exists:users,id'],
            'role' => ['nullable', 'string', 'max:100'],
            'attended' => ['boolean'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $drill->participants()->create($validated);

        return redirect()->back()->with('success', 'Participant added successfully.');
    }

    /**
     * Add a finding with corrective action.
     */
    public function addFinding(Request $request, EmergencyDrill $drill): RedirectResponse
    {
        $validated = $request->validate([
            'description' => ['required', 'string', 'max:2000'],
            'severity' => ['required', 'string', 'in:critical,high,medium,low'],
            'corrective_action' => ['nullable', 'string', 'max:2000'],
            'assigned_to' => ['nullable', 'exists:users,id'],
            'due_date' => ['nullable', 'date'],
        ]);

        $drill->findings()->create(array_merge($validated, [
            'status' => 'open',
            'created_by' => $request->user()->id,
        ]));

        return redirect()->back()->with('success', 'Finding recorded successfully.');
    }

    /**
     * Update a finding status/resolution.
     */
    public function updateFinding(Request $request, EmergencyDrillFinding $finding): RedirectResponse
    {
        $validated = $request->validate([
            'status' => ['sometimes', 'string', 'in:open,in_progress,resolved,closed'],
            'resolution_notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'resolved_at' => ['sometimes', 'nullable', 'date'],
            'corrective_action' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'assigned_to' => ['sometimes', 'nullable', 'exists:users,id'],
            'due_date' => ['sometimes', 'nullable', 'date'],
        ]);

        $finding->update($validated);

        return redirect()->back()->with('success', 'Finding updated successfully.');
    }
}
