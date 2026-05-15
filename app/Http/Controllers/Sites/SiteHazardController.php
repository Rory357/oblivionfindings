<?php

namespace App\Http\Controllers\Sites;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Sites\Concerns\ResolvesAllowedSiteTypes;
use App\Models\Site;
use App\Models\SiteHazard;
use App\Services\Sites\SiteHazardRiskCalculator;
use Illuminate\Http\Request;

class SiteHazardController extends Controller
{
    use ResolvesAllowedSiteTypes;

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
            'prefill' => [
                'type' => is_string($request->query('type')) ? $request->query('type') : null,
                'label' => is_string($request->query('label')) ? $request->query('label') : null,
            ],
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

        // Get staff for assignment dropdown
        $users = \App\Models\User::staff()
            ->select(['id', 'name'])
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
            'assigned_to_user_id' => 'nullable|exists:users,id',
            'due_date' => 'nullable|date',
        ]);

        // Calculate risk rating from severity + likelihood
        $riskRating = $this->riskCalculator->calculate(
            $validated['severity'],
            $validated['likelihood']
        );

        // Generate unique reference number
        $latestRef = SiteHazard::withTrashed()
            ->where('reference_number', 'like', 'HAZ-%')
            ->orderByDesc('id')
            ->value('reference_number');
        $nextNum = 1;
        if ($latestRef && preg_match('/HAZ-(\d+)/', $latestRef, $matches)) {
            $nextNum = (int) $matches[1] + 1;
        }
        $referenceNumber = 'HAZ-' . str_pad($nextNum, 5, '0', STR_PAD_LEFT);

        $assignedToUserId = $validated['assigned_to_user_id'] ?? null;
        if ($this->riskCalculator->requiresAssignment($riskRating) && !$assignedToUserId) {
            $assignedToUserId = \App\Models\User::query()
                ->whereHas('roles', fn ($q) => $q->where('name', 'health_safety_officer'))
                ->value('id');
        }

        $hazard = SiteHazard::create([
            ...$validated,
            'site_id' => $site->id,
            'tenant_id' => $site->tenant_id,
            'reference_number' => $referenceNumber,
            'risk_rating' => $riskRating,
            'reported_by_user_id' => $request->user()->id,
            'assigned_to_user_id' => $assignedToUserId,
            'assigned_at' => $assignedToUserId ? now() : null,
            'status' => 'open',
            'due_date' => $validated['due_date'] ?? now()->addDays($this->riskCalculator->suggestedDueDays($riskRating))->toDateString(),
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

        // Recalculate risk rating when severity or likelihood changes
        $validated['risk_rating'] = $this->riskCalculator->calculate(
            $validated['severity'],
            $validated['likelihood']
        );

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
        abort_unless($request->user()?->canDo('hazards.view'), 403);

        $allowedSiteTypes = $this->allowedSiteTypes($request);

        $query = SiteHazard::query()
            ->with(['site:id,name,type', 'assignedTo:id,name'])
            ->whereHas('site', fn ($q) => $q->whereIn('type', $allowedSiteTypes))
            ->when($request->site_id, fn($q) => $q->where('site_id', $request->site_id))
            ->when($request->site_type, fn($q) => $q->whereHas('site', fn ($sq) => $sq->where('type', $request->site_type)))
            ->when($request->status, fn($q) => $q->where('status', $request->status))
            ->when($request->severity, fn($q) => $q->where('severity', $request->severity))
            ->when($request->risk_rating, fn($q) => $q->where('risk_rating', $request->risk_rating))
            ->when($request->assignee_id, fn($q) => $q->where('assigned_to_user_id', $request->assignee_id))
            ->when($request->due_state === 'overdue', fn($q) => $q->whereDate('due_date', '<', now()->toDateString())->whereIn('status', ['open', 'in_progress']))
            ->when($request->due_state === 'due_soon', fn($q) => $q->whereBetween('due_date', [now()->toDateString(), now()->addDays(7)->toDateString()])->whereIn('status', ['open', 'in_progress']))
            ->when($request->assigned_to_me, fn($q) => $q->where('assigned_to_user_id', $request->user()->id));

        $hazards = $query
            ->orderByDesc('created_at')
            ->limit(500)
            ->get()
            ->map(fn (SiteHazard $hazard) => [
                'id' => $hazard->id,
                'reference_number' => $hazard->reference_number,
                'site_id' => $hazard->site_id,
                'site_name' => $hazard->site?->name,
                'site_type' => $hazard->site?->type,
                'hazard_type' => $hazard->hazard_type,
                'severity' => $hazard->severity,
                'likelihood' => $hazard->likelihood,
                'risk_rating' => $hazard->risk_rating,
                'description' => $hazard->description,
                'status' => $hazard->status,
                'assigned_to_name' => $hazard->assignedTo?->name,
                'due_date' => $hazard->due_date?->toDateString(),
                'created_at' => $hazard->created_at?->toDateTimeString(),
            ]);

        $sites = Site::active()
            ->whereIn('type', $allowedSiteTypes)
            ->select(['id', 'name', 'type'])
            ->orderBy('name')
            ->get();

        $assignees = \App\Models\User::staff()
            ->select(['id', 'name'])
            ->orderBy('name')
            ->get();

        return inertia('compliance/hazards/index', [
            'hazards' => $hazards,
            'sites' => $sites,
            'assignees' => $assignees,
            'filters' => $request->only(['site_id', 'site_type', 'status', 'severity', 'risk_rating', 'assigned_to_me', 'assignee_id', 'due_state']),
            'severityOptions' => collect(SiteHazardRiskCalculator::severities())->map(fn ($severity) => [
                'key' => $severity,
                'label' => ucfirst($severity),
            ])->values(),
            'likelihoodOptions' => SiteHazardRiskCalculator::likelihoods(),
            'riskRatings' => SiteHazardRiskCalculator::riskRatings(),
        ]);
    }

}
