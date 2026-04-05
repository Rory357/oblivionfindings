<?php

namespace App\Domain\Governance\Http\Controllers;

use App\Domain\Governance\Models\GovernancePolicy;
use App\Domain\Governance\Models\PolicyAttestation;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;

class GovernancePolicyController extends Controller
{
    public function index(Request $request)
    {
        abort_unless($request->user()?->canDo('governance.policies.view'), 403);

        $policies = GovernancePolicy::query()
            ->withCount('attestations')
            ->when($request->category, fn($q, $cat) => $q->where('category', $cat))
            ->when($request->status, fn($q, $s) => $q->where('status', $s))
            ->orderBy('title')
            ->paginate(20);

        return Inertia::render('Governance/Policies/Index', [
            'policies' => $policies,
            'categories' => [
                ['value' => 'governance', 'label' => 'Governance'],
                ['value' => 'financial', 'label' => 'Financial'],
                ['value' => 'hr', 'label' => 'Human Resources'],
                ['value' => 'health_safety', 'label' => 'Health & Safety'],
                ['value' => 'privacy', 'label' => 'Privacy'],
                ['value' => 'clinical', 'label' => 'Clinical'],
                ['value' => 'operational', 'label' => 'Operational'],
                ['value' => 'other', 'label' => 'Other'],
            ],
        ]);
    }

    public function create()
    {
        abort_unless(request()->user()?->canDo('governance.policies.manage'), 403);

        return Inertia::render('Governance/Policies/Create');
    }

    public function store(Request $request)
    {
        abort_unless($request->user()?->canDo('governance.policies.manage'), 403);

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'category' => 'required|string',
            'description' => 'nullable|string',
            'content' => 'required|string',
            'effective_date' => 'required|date',
            'review_date' => 'required|date|after:effective_date',
            'requires_attestation' => 'boolean',
            'attestation_frequency' => 'nullable|in:annual,biannual,quarterly',
        ]);

        $policy = GovernancePolicy::create([
            ...$validated,
            'version' => 1,
            'status' => 'draft',
            'created_by' => auth()->id(),
        ]);

        return redirect()->route('governance.policies.show', $policy)
            ->with('success', 'Policy created.');
    }

    public function show(GovernancePolicy $policy)
    {
        abort_unless(request()->user()?->canDo('governance.policies.view'), 403);

        $policy->load(['attestations.user', 'approvedByUser']);

        $attestationStats = [
            'total_required' => $policy->requires_attestation ? \App\Domain\Governance\Models\BoardMember::active()->count() : 0,
            'completed' => $policy->attestations()->where('version', $policy->version)->count(),
        ];

        return Inertia::render('Governance/Policies/Show', [
            'policy' => $policy,
            'attestationStats' => $attestationStats,
            'canEdit' => auth()->user()->can('update', $policy),
        ]);
    }

    public function edit(GovernancePolicy $policy)
    {
        abort_unless(request()->user()?->canDo('governance.policies.manage'), 403);

        return Inertia::render('Governance/Policies/Edit', [
            'policy' => $policy,
        ]);
    }

    public function update(Request $request, GovernancePolicy $policy)
    {
        abort_unless($request->user()?->canDo('governance.policies.manage'), 403);

        $validated = $request->validate([
            'title' => 'sometimes|string|max:255',
            'category' => 'sometimes|string',
            'description' => 'nullable|string',
            'content' => 'sometimes|string',
            'review_date' => 'sometimes|date',
            'status' => 'sometimes|in:draft,active,under_review,archived',
            'requires_attestation' => 'boolean',
        ]);

        $policy->update($validated);

        return redirect()->back()->with('success', 'Policy updated.');
    }

    public function approve(Request $request, GovernancePolicy $policy)
    {
        abort_unless($request->user()?->canDo('governance.policies.manage'), 403);

        $policy->update([
            'status' => 'active',
            'approved_by' => auth()->id(),
            'approved_at' => now(),
        ]);

        return redirect()->back()->with('success', 'Policy approved and published.');
    }

    public function attest(Request $request, GovernancePolicy $policy)
    {
        abort_unless($request->user()?->canDo('governance.policies.view'), 403);

        $validated = $request->validate([
            'acknowledged' => 'required|accepted',
            'notes' => 'nullable|string|max:500',
        ]);

        PolicyAttestation::updateOrCreate(
            [
                'governance_policy_id' => $policy->id,
                'user_id' => auth()->id(),
                'version' => $policy->version,
            ],
            [
                'attested_at' => now(),
                'notes' => $validated['notes'] ?? null,
                'ip_address' => $request->ip(),
            ]
        );

        return redirect()->back()->with('success', 'Policy attestation recorded.');
    }

    public function newVersion(Request $request, GovernancePolicy $policy)
    {
        abort_unless($request->user()?->canDo('governance.policies.manage'), 403);

        $validated = $request->validate([
            'content' => 'required|string',
            'change_summary' => 'required|string|max:500',
        ]);

        $policy->update([
            'content' => $validated['content'],
            'version' => $policy->version + 1,
            'change_summary' => $validated['change_summary'],
            'status' => 'draft',
            'approved_by' => null,
            'approved_at' => null,
        ]);

        return redirect()->back()->with('success', 'New policy version created (v' . $policy->version . ').');
    }
}
