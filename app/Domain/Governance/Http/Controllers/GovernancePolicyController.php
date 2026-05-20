<?php

namespace App\Domain\Governance\Http\Controllers;

use App\Domain\Governance\Models\GovernancePolicy;
use App\Domain\Governance\Models\PolicyAttestation;
use App\Domain\Governance\Models\BoardMember;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;

class GovernancePolicyController extends Controller
{
    public function index(Request $request)
    {
        $this->authorize('viewAny', GovernancePolicy::class);

        $policies = GovernancePolicy::query()
            ->withCount('attestations')
            ->when($request->category, fn($q, $cat) => $q->where('category', $cat))
            ->when($request->status, fn($q, $s) => $q->where('status', $s))
            ->orderBy('title')
            ->paginate(20)
            ->through(fn (GovernancePolicy $policy) => $this->presentPolicyListItem($policy));

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
        $this->authorize('create', GovernancePolicy::class);

        return Inertia::render('Governance/Policies/Create');
    }

    public function store(Request $request)
    {
        $this->authorize('create', GovernancePolicy::class);

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
            'policy_code' => $this->generatePolicyCode(),
            'title' => $validated['title'],
            'category' => $validated['category'],
            'purpose' => $validated['description'] ?? null,
            'content' => $validated['content'],
            'version_number' => 1,
            'status' => 'draft',
            'owner_id' => auth()->id(),
            'created_by' => auth()->id(),
            'effective_from' => $validated['effective_date'],
            'review_due' => $validated['review_date'],
            'next_review_date' => $validated['review_date'],
        ]);

        return redirect()->route('governance.policies.show', $policy)
            ->with('success', 'Policy created.');
    }

    public function show(GovernancePolicy $policy)
    {
        $this->authorize('view', $policy);

        $policy->load(['attestations.user', 'approvedBy']);

        $attestationStats = [
            'total_required' => BoardMember::active()->count(),
            'completed' => $policy->attestations()->where('acknowledged', true)->count(),
        ];

        return Inertia::render('Governance/Policies/Show', [
            'policy' => $this->presentPolicy($policy),
            'attestationStats' => $attestationStats,
            'canEdit' => auth()->user()?->canDo('governance.policies.manage') ?? false,
        ]);
    }

    public function edit(GovernancePolicy $policy)
    {
        $this->authorize('update', $policy);

        return Inertia::render('Governance/Policies/Edit', [
            'policy' => $this->presentPolicy($policy),
        ]);
    }

    public function update(Request $request, GovernancePolicy $policy)
    {
        $this->authorize('update', $policy);

        $validated = $request->validate([
            'title' => 'sometimes|string|max:255',
            'category' => 'sometimes|string',
            'description' => 'nullable|string',
            'content' => 'sometimes|string',
            'review_date' => 'sometimes|date',
            'status' => 'sometimes|in:draft,active,under_review,archived',
            'requires_attestation' => 'boolean',
        ]);

        $payload = [];

        if (array_key_exists('title', $validated)) {
            $payload['title'] = $validated['title'];
        }

        if (array_key_exists('category', $validated)) {
            $payload['category'] = $validated['category'];
        }

        if (array_key_exists('description', $validated)) {
            $payload['purpose'] = $validated['description'];
        }

        if (array_key_exists('content', $validated)) {
            $payload['content'] = $validated['content'];
        }

        if (array_key_exists('review_date', $validated)) {
            $payload['review_due'] = $validated['review_date'];
            $payload['next_review_date'] = $validated['review_date'];
        }

        if (array_key_exists('status', $validated)) {
            $payload['status'] = $this->normalizeStatus($validated['status']);
        }

        $policy->update($payload);

        return redirect()->back()->with('success', 'Policy updated.');
    }

    public function approve(Request $request, GovernancePolicy $policy)
    {
        $this->authorize('approve', $policy);

        $policy->update([
            'status' => 'approved',
            'approved_by' => auth()->id(),
            'approved_at' => now(),
            'effective_from' => $policy->effective_from ?? now()->toDateString(),
            'next_review_date' => $policy->next_review_date ?? $policy->review_due ?? now()->addYear()->toDateString(),
        ]);

        return redirect()->back()->with('success', 'Policy approved and published.');
    }

    public function attest(Request $request, GovernancePolicy $policy)
    {
        $this->authorize('attest', $policy);

        $validated = $request->validate([
            'acknowledged' => 'required|accepted',
            'notes' => 'nullable|string|max:500',
        ]);

        PolicyAttestation::updateOrCreate(
            [
                'governance_policy_id' => $policy->id,
                'user_id' => auth()->id(),
            ],
            [
                'acknowledged' => true,
                'acknowledged_at' => now(),
                'notes' => $validated['notes'] ?? null,
            ]
        );

        \App\Domain\Governance\Services\GovernanceAuditService::log(
            'policy.attested',
            'GovernancePolicy',
            $policy->id,
            ['user_id' => auth()->id(), 'version_number' => $policy->version_number]
        );

        return redirect()->back()->with('success', 'Policy attestation recorded.');
    }

    /**
     * Show all governance policies and the current user's attestation state
     * for each — "things I still need to acknowledge" + "things I've already
     * acknowledged". Manage permission also surfaces the org-wide attestation
     * gap (% of board members who have attested per policy).
     */
    public function attestations(Request $request)
    {
        $this->authorize('viewAny', GovernancePolicy::class);

        $userId = $request->user()->id;
        $canManage = (bool) $request->user()?->canDo('governance.policies.manage');

        $policies = GovernancePolicy::query()
            ->where('status', 'approved')
            ->orderBy('title')
            ->get()
            ->map(function (GovernancePolicy $policy) use ($userId, $canManage) {
                $mine = $policy->attestations->firstWhere('user_id', $userId);

                $totalRequired = BoardMember::active()->count();
                $totalAttested = $canManage ? $policy->attestations->where('acknowledged', true)->count() : null;

                return [
                    'id' => $policy->id,
                    'title' => $policy->title,
                    'category' => $policy->category,
                    'version' => (int) $policy->version_number,
                    'effective_from' => $policy->effective_from?->toDateString(),
                    'next_review_date' => $policy->next_review_date?->toDateString(),
                    'my_attestation' => $mine ? [
                        'acknowledged' => (bool) $mine->acknowledged,
                        'acknowledged_at' => $mine->acknowledged_at?->toIso8601String(),
                        'notes' => $mine->notes,
                    ] : null,
                    'total_required' => $totalRequired,
                    'total_attested' => $totalAttested,
                ];
            });

        $outstanding = $policies->filter(fn ($p) => empty($p['my_attestation']) || ! ($p['my_attestation']['acknowledged'] ?? false))->values();
        $completed = $policies->filter(fn ($p) => ! empty($p['my_attestation']) && ($p['my_attestation']['acknowledged'] ?? false))->values();

        return Inertia::render('Governance/Policies/Attestations', [
            'policies' => $policies->values(),
            'outstanding' => $outstanding,
            'completed' => $completed,
            'canManage' => $canManage,
            'summary' => [
                'outstanding_count' => $outstanding->count(),
                'completed_count' => $completed->count(),
                'board_member_count' => BoardMember::active()->count(),
            ],
        ]);
    }

    public function newVersion(Request $request, GovernancePolicy $policy)
    {
        $this->authorize('newVersion', $policy);

        $validated = $request->validate([
            'content' => 'required|string',
            'change_summary' => 'required|string|max:500',
        ]);

        $newPolicy = $policy->createNewVersion(auth()->id());
        $newPolicy->update([
            'content' => $validated['content'],
            'review_due' => $policy->review_due,
            'next_review_date' => $policy->next_review_date,
        ]);

        return redirect()->route('governance.policies.show', $newPolicy)
            ->with('success', 'New policy draft created.');
    }

    protected function presentPolicyListItem(GovernancePolicy $policy): array
    {
        return [
            'id' => $policy->id,
            'title' => $policy->title,
            'category' => $policy->category,
            'version' => (int) $policy->version_number,
            'status' => $this->presentStatus($policy->status),
            'effective_date' => $policy->effective_from?->toDateString(),
            'review_date' => $policy->next_review_date?->toDateString() ?? $policy->review_due?->toDateString(),
            'requires_attestation' => false,
            'attestations_count' => $policy->attestations_count,
        ];
    }

    protected function presentPolicy(GovernancePolicy $policy): array
    {
        return [
            'id' => $policy->id,
            'title' => $policy->title,
            'category' => $policy->category,
            'description' => $policy->purpose,
            'content' => $policy->content,
            'version' => (int) $policy->version_number,
            'status' => $this->presentStatus($policy->status),
            'effective_date' => $policy->effective_from?->toDateString(),
            'review_date' => $policy->next_review_date?->toDateString() ?? $policy->review_due?->toDateString(),
            'requires_attestation' => false,
            'approved_by_user' => $policy->approvedBy ? ['name' => $policy->approvedBy->name] : null,
            'approved_at' => $policy->approved_at?->toIso8601String(),
            'attestations' => $policy->attestations->map(fn (PolicyAttestation $attestation) => [
                'id' => $attestation->id,
                'user' => ['id' => $attestation->user?->id, 'name' => $attestation->user?->name ?? 'Unknown'],
                'version' => (int) $policy->version_number,
                'attested_at' => $attestation->acknowledged_at?->toIso8601String(),
                'notes' => $attestation->notes,
            ])->values()->all(),
        ];
    }

    protected function normalizeStatus(string $status): string
    {
        return match ($status) {
            'active' => 'approved',
            'archived' => 'archived',
            default => $status,
        };
    }

    protected function presentStatus(string $status): string
    {
        return match ($status) {
            'approved' => 'active',
            'superseded' => 'archived',
            default => $status,
        };
    }

    protected function generatePolicyCode(): string
    {
        do {
            $code = 'POL-' . strtoupper(Str::random(6));
        } while (GovernancePolicy::query()->where('policy_code', $code)->exists());

        return $code;
    }
}
