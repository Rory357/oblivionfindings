<?php

namespace App\Domain\Governance\Http\Controllers;

use App\Domain\Governance\Models\RiskAcceptance;
use App\Domain\Governance\Models\RiskRegisterEntry;
use App\Domain\Governance\Models\RiskTreatment;
use App\Domain\Governance\Services\RiskScoringService;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;

class RiskRegisterController extends Controller
{
    public function __construct(
        protected RiskScoringService $riskService
    ) {}

    public function create()
    {
        return Inertia::render('Governance/Risks/Create', [
            'categories' => $this->getCategories(),
        ]);
    }

    public function index(Request $request)
    {
        $query = RiskRegisterEntry::with(['riskOwner', 'treatments', 'acceptances']);

        // Filters
        if ($request->has('category')) {
            $query->byCategory($request->category);
        }

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        if ($request->has('severity')) {
            match($request->severity) {
                'critical' => $query->critical(),
                'high' => $query->high(),
                default => $query,
            };
        }

        $risks = $query->orderByDesc('residual_score')
            ->paginate(20);

        return Inertia::render('Governance/Risks/Index', [
            'risks' => $risks,
            'categories' => $this->getCategories(),
            'summary' => $this->riskService->getCategorySummary(),
            'filters' => $request->only(['category', 'status', 'severity']),
        ]);
    }

    public function show(RiskRegisterEntry $risk)
    {
        $risk->load([
            'riskOwner',
            'treatments.assignedTo',
            'acceptances.acceptedBy',
            'events',
        ]);

        return Inertia::render('Governance/Risks/Show', [
            'risk' => $risk,
            'assignees' => User::staff()
                ->select('id', 'name', 'email')
                ->orderBy('name')
                ->get(),
            'canEdit' => auth()->user()->can('update', $risk),
            'canAccept' => auth()->user()->can('accept', $risk),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'category' => 'required|string',
            'title' => 'required|string|max:255',
            'description' => 'required|string',
            'likelihood_score' => 'nullable|integer|min:1|max:5',
            'impact_score' => 'nullable|integer|min:1|max:5',
            'control_effectiveness' => 'nullable|in:none,weak,moderate,strong',
            'risk_owner_id' => 'nullable|exists:users,id',
            'mitigation_strategy' => 'nullable|in:treat,transfer,terminate,tolerate',
            'review_frequency' => 'nullable|in:monthly,quarterly,annual',
        ]);

        $inherentScore = $this->riskService->calculateInherentScore(
            $validated['likelihood_score'],
            $validated['impact_score']
        );

        $residualScore = $this->riskService->calculateResidualScore(
            $inherentScore,
            $validated['control_effectiveness']
        );

        $threshold = $this->riskService->getAppetiteThreshold($validated['category']);

        $risk = RiskRegisterEntry::create([
            'category' => $validated['category'],
            'title' => $validated['title'],
            'description' => $validated['description'],
            'likelihood_score' => $validated['likelihood_score'] ?? 3,
            'impact_score' => $validated['impact_score'] ?? 3,
            'control_effectiveness' => $validated['control_effectiveness'] ?? 'moderate',
            'risk_owner_id' => $validated['risk_owner_id'] ?? auth()->id(),
            'mitigation_strategy' => $validated['mitigation_strategy'] ?? 'treat',
            'review_frequency' => $validated['review_frequency'] ?? 'quarterly',
            'inherent_score' => $inherentScore,
            'residual_score' => $residualScore,
            'appetite_threshold' => $threshold,
            'within_appetite' => $residualScore <= $threshold,
            'identified_at' => now(),
            'identified_by' => auth()->id(),
            'next_review_date' => now()->addMonths(
                match($validated['review_frequency'] ?? 'quarterly') {
                    'monthly' => 1,
                    'quarterly' => 3,
                    'annual' => 12,
                }
            ),
        ]);

        return redirect()->route('governance.risks.show', $risk)
            ->with('success', 'Risk registered successfully.');
    }

    public function update(Request $request, RiskRegisterEntry $risk)
    {
        $this->authorize('update', $risk);

        $validated = $request->validate([
            'title' => 'sometimes|string|max:255',
            'description' => 'sometimes|string',
            'likelihood_score' => 'sometimes|integer|min:1|max:5',
            'impact_score' => 'sometimes|integer|min:1|max:5',
            'control_effectiveness' => 'sometimes|in:none,weak,moderate,strong',
            'risk_owner_id' => 'sometimes|exists:users,id',
            'mitigation_strategy' => 'sometimes|in:treat,transfer,terminate,tolerate',
        ]);

        $risk->update($validated);

        // Recalculate scores if necessary
        if (isset($validated['likelihood_score']) || 
            isset($validated['impact_score']) || 
            isset($validated['control_effectiveness'])) {
            $this->riskService->recalculateRisk($risk);
        }

        return redirect()->back()->with('success', 'Risk updated.');
    }

    public function accept(Request $request, RiskRegisterEntry $risk)
    {
        $this->authorize('accept', $risk);

        $validated = $request->validate([
            'justification' => 'required|string|min:50',
            'expiry_months' => 'required|integer|min:1|max:24',
            'conditions' => 'nullable|array',
            'resolution_id' => 'nullable|exists:resolutions,id',
        ]);

        if (!$risk->within_appetite && empty($validated['resolution_id'])) {
            return redirect()->back()->with('error', 'Above-appetite risks require a Board resolution for acceptance. Please create and link a resolution first.');
        }

        $acceptance = $this->riskService->acceptRisk(
            $risk,
            $validated['resolution_id'] ? 'board_resolution' : 'delegated_authority',
            $validated['justification'],
            auth()->user(),
            $validated['resolution_id'] ?? null,
            null,
            $validated['expiry_months'],
            $validated['conditions'] ?? []
        );

        $risk->update(['status' => 'accepted']);

        return redirect()->back()->with('success', 'Risk acceptance recorded.');
    }

    public function close(Request $request, RiskRegisterEntry $risk)
    {
        $this->authorize('close', $risk);

        $validated = $request->validate([
            'rationale' => 'required|string|min:20',
        ]);

        $risk->close($validated['rationale'], auth()->id());

        return redirect()->route('governance.risks.index')
            ->with('success', 'Risk closed.');
    }

    public function addTreatment(Request $request, RiskRegisterEntry $risk)
    {
        $validated = $request->validate([
            'action_description' => 'required|string',
            'assigned_to' => 'required|exists:users,id',
            'due_date' => 'required|date|after:today',
            'expected_score_reduction' => 'nullable|integer|min:1|max:24',
            'evidence_required' => 'boolean',
        ]);

        $treatment = $this->riskService->createTreatment(
            $risk,
            $validated['action_description'],
            \App\Models\User::find($validated['assigned_to']),
            new \DateTime($validated['due_date']),
            auth()->user(),
            $validated['expected_score_reduction'] ?? null,
            $validated['evidence_required'] ?? false
        );

        return redirect()->back()->with('success', 'Treatment action added.');
    }

    public function linkEvent(Request $request, RiskRegisterEntry $risk)
    {
        $validated = $request->validate([
            'event_type' => 'required|in:incident,alert,safeguarding,audit,breach,complaint',
            'event_id' => 'required|integer',
            'event_reference' => 'nullable|string',
            'event_severity' => 'required|string',
            'event_occurred_at' => 'required|date',
            'link_rationale' => 'nullable|string',
        ]);

        $risk->events()->create([
            ...$validated,
            'linked_by' => auth()->id(),
            'linked_at' => now(),
        ]);

        return redirect()->back()->with('success', 'Event linked to risk.');
    }

    public function edit(RiskRegisterEntry $risk)
    {
        return Inertia::render('Governance/Risks/Edit', [
            'risk' => $risk,
        ]);
    }

    public function heatmap()
    {
        return Inertia::render('Governance/Risks/Heatmap', [
            'heatmap' => $this->riskService->generateHeatmapData(),
            'trend' => $this->riskService->getTrendAnalysis(),
        ]);
    }

    public function trends()
    {
        $snapshots = \App\Domain\Governance\Models\RiskHeatmapSnapshot::orderByDesc('snapshot_date')
            ->limit(12)
            ->get();

        return Inertia::render('Governance/Risks/Trends', [
            'snapshots' => $snapshots,
        ]);
    }

    public function committeeView(string $committee)
    {
        $validCommittees = ['audit_risk', 'people', 'finance'];
        if (!in_array($committee, $validCommittees)) {
            abort(404);
        }

        $categoryMap = [
            'audit_risk' => ['financial', 'legal_compliance', 'it_cyber'],
            'people' => ['workforce', 'client_safety'],
            'finance' => ['financial', 'operational'],
        ];

        $risks = RiskRegisterEntry::active()
            ->whereIn('category', $categoryMap[$committee])
            ->orderByDesc('residual_score')
            ->get();

        return Inertia::render('Governance/Risks/Committee', [
            'committee' => $committee,
            'risks' => $risks,
        ]);
    }

    protected function getCategories(): array
    {
        return [
            ['value' => 'client_safety', 'label' => 'Client Safety'],
            ['value' => 'reputational', 'label' => 'Reputational'],
            ['value' => 'financial', 'label' => 'Financial'],
            ['value' => 'it_cyber', 'label' => 'IT/Cyber'],
            ['value' => 'workforce', 'label' => 'Workforce'],
            ['value' => 'legal_compliance', 'label' => 'Legal/Compliance'],
            ['value' => 'operational', 'label' => 'Operational'],
            ['value' => 'clinical', 'label' => 'Clinical'],
        ];
    }
}
