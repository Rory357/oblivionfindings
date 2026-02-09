<?php

namespace App\Http\Controllers\Sites;

use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Models\SiteHazard;
use App\Services\AuditLogger;
use App\Services\Sites\SiteHazardRiskCalculator;
use Illuminate\Http\Request;

class SiteHazardController extends Controller
{
    public function __construct(
        private SiteHazardRiskCalculator $riskCalculator
    ) {}

    public function index(Request $request, Site $site)
    {
        $this->authorize('view', $site);

        $hazards = SiteHazard::where('site_id', $site->id)
            ->with(['reportedBy:id,name', 'assignedTo:id,name'])
            ->when($request->status, fn($q) => $q->where('status', $request->status))
            ->when($request->severity, fn($q) => $q->where('severity', $request->severity))
            ->orderByDesc('created_at')
            ->paginate(20);

        return inertia('sites/hazards/index', [
            'site' => [
                'id' => $site->id,
                'name' => $site->name,
                'type' => $site->type,
            ],
            'hazards' => $hazards,
            'filters' => $request->only(['status', 'severity']),
            'severityOptions' => SiteHazardRiskCalculator::severities(),
            'likelihoodOptions' => SiteHazardRiskCalculator::likelihoods(),
            'riskRatings' => SiteHazardRiskCalculator::riskRatings(),
        ]);
    }

    public function create(Request $request, Site $site)
    {
        $this->authorize('update', $site);

        $hazardTypes = function_exists('settings') 
            ? settings('sites.hazard_types', [])
            : ['safety', 'health', 'environmental', 'security', 'maintenance', 'other'];

        return inertia('sites/hazards/create', [
            'site' => [
                'id' => $site->id,
                'name' => $site->name,
                'type' => $site->type,
            ],
            'hazardTypes' => $hazardTypes,
            'severityOptions' => SiteHazardRiskCalculator::severities(),
            'likelihoodOptions' => SiteHazardRiskCalculator::likelihoods(),
        ]);
    }

    public function show(SiteHazard $hazard)
    {
        $this->authorize('view', $hazard->site);

        $hazard->load([
            'site:id,name,type',
            'reportedBy:id,name',
            'assignedTo:id,name',
            'actions.assignedTo:id,name',
            'actions.completedBy:id,name',
        ]);

        // Get users for assignment dropdown
        $users = \App\Models\User::select(['id', 'name'])
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        return inertia('sites/hazards/show', [
            'hazard' => $hazard,
            'users' => $users,
            'canAssign' => auth()->user()->canDo('hazards.assign'),
            'canClose' => auth()->user()->canDo('hazards.close'),
        ]);
    }

    public function store(Request $request, Site $site)
    {
        $this->authorize('update', $site);

        $validated = $request->validate([
            'hazard_type' => 'required|string|max:50',
            'custom_hazard_type' => 'nullable|string|max:100',
            'severity' => 'required|in:' . implode(',', SiteHazardRiskCalculator::severities()),
            'likelihood' => 'required|in:' . implode(',', SiteHazardRiskCalculator::likelihoods()),
            'description' => 'required|string',
            'photo_paths' => 'nullable|array',
            'immediate_action_applied' => 'boolean',
            'immediate_action_taken' => 'nullable|string',
        ]);

        $hazard = SiteHazard::create([
            ...$validated,
            'site_id' => $site->id,
            'reported_by_user_id' => $request->user()->id,
            'status' => 'open',
        ]);

        return redirect()
            ->route('sites.hazards.show', $hazard->id)
            ->with('success', 'Hazard logged successfully.');
    }

    public function update(Request $request, SiteHazard $hazard)
    {
        $this->authorize('update', $hazard->site);

        $validated = $request->validate([
            'description' => 'required|string',
            'severity' => 'required|in:' . implode(',', SiteHazardRiskCalculator::severities()),
            'likelihood' => 'required|in:' . implode(',', SiteHazardRiskCalculator::likelihoods()),
        ]);

        $hazard->update($validated);

        return redirect()->back()->with('success', 'Hazard updated.');
    }

    public function assign(Request $request, SiteHazard $hazard)
    {
        $this->authorize('update', $hazard->site);

        $validated = $request->validate([
            'assigned_to_user_id' => 'required|exists:users,id',
        ]);

        $hazard->update([
            'assigned_to_user_id' => $validated['assigned_to_user_id'],
            'assigned_at' => now(),
        ]);

        return redirect()->back()->with('success', 'Hazard assigned.');
    }

    public function close(Request $request, SiteHazard $hazard)
    {
        $this->authorize('update', $hazard->site);

        $validated = $request->validate([
            'resolution_summary' => 'required|string',
            'resolution_evidence' => 'nullable|array',
        ]);

        $hazard->update([
            ...$validated,
            'status' => 'closed',
            'closed_at' => now(),
            'closed_by_user_id' => $request->user()->id,
        ]);

        return redirect()->back()->with('success', 'Hazard closed.');
    }

    public function globalIndex(Request $request)
    {
        $this->authorize('hazards.view');

        $query = SiteHazard::query()
            ->with(['site:id,name,type', 'assignedTo:id,name'])
            ->when($request->site_id, fn($q) => $q->where('site_id', $request->site_id))
            ->when($request->status, fn($q) => $q->where('status', $request->status))
            ->when($request->severity, fn($q) => $q->where('severity', $request->severity))
            ->when($request->risk_rating, fn($q) => $q->where('risk_rating', $request->risk_rating))
            ->when($request->assigned_to_me, fn($q) => $q->where('assigned_to_user_id', $request->user()->id));

        $hazards = $query->orderByDesc('created_at')->paginate(25);

        $sites = Site::active()->select(['id', 'name', 'type'])->orderBy('name')->get();

        return inertia('compliance/hazards/index', [
            'hazards' => $hazards,
            'sites' => $sites,
            'filters' => $request->only(['site_id', 'status', 'severity', 'risk_rating', 'assigned_to_me']),
            'severityOptions' => SiteHazardRiskCalculator::severities(),
            'likelihoodOptions' => SiteHazardRiskCalculator::likelihoods(),
            'riskRatings' => SiteHazardRiskCalculator::riskRatings(),
        ]);
    }
}
