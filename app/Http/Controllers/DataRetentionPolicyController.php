<?php

namespace App\Http\Controllers;

use App\Models\DataRetentionPolicy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DataRetentionPolicyController extends Controller
{
    /**
     * Display a listing of retention policies.
     */
    public function index(Request $request): Response
    {
        $this->authorizePermission($request);

        $query = DataRetentionPolicy::query()
            ->with(['creator', 'updater']);

        if ($request->filled('q')) {
            $query->where(function ($q) use ($request) {
                $q->where('policy_name', 'like', "%{$request->q}%")
                    ->orWhere('model_type', 'like', "%{$request->q}%");
            });
        }

        if ($request->filled('active')) {
            $query->where('active', $request->active === '1');
        }

        $query->orderBy('model_type');

        $policies = $query->paginate(20)->withQueryString();

        return Inertia::render('privacy/retention', [
            'policies' => $policies,
            'filters' => $request->only(['q', 'active']),
            'stats' => [
                'total' => DataRetentionPolicy::count(),
                'active' => DataRetentionPolicy::where('active', true)->count(),
            ],
        ]);
    }

    /**
     * Show the form for creating a new policy.
     */
    public function create(Request $request): Response
    {
        $this->authorizePermission($request);

        return Inertia::render('privacy/retention/create');
    }

    /**
     * Store a newly created policy.
     */
    public function store(Request $request): RedirectResponse
    {
        $this->authorizePermission($request);

        $validated = $request->validate([
            'model_type' => 'required|string|max:255',
            'policy_name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'retention_period_years' => 'required|integer|min:1|max:100',
            'archive_after_years' => 'nullable|integer|min:1|max:100',
            'hard_delete_after_years' => 'nullable|integer|min:1|max:100',
            'retention_conditions' => 'nullable|array',
            'applies_to_soft_deleted' => 'boolean',
            'legal_hold_exemption' => 'boolean',
            'active_case_exemption' => 'boolean',
            'legal_basis' => 'nullable|string',
            'business_justification' => 'nullable|string',
            'active' => 'boolean',
        ]);

        $validated['created_by'] = auth()->id();

        $policy = DataRetentionPolicy::create($validated);

        return redirect()
            ->route('privacy.retention.index')
            ->with('success', 'Retention policy created successfully.');
    }

    /**
     * Show the form for editing the policy.
     */
    public function edit(Request $request, DataRetentionPolicy $policy): Response
    {
        $this->authorizePermission($request);

        return Inertia::render('privacy/retention/edit', [
            'policy' => $policy,
        ]);
    }

    /**
     * Update the specified policy.
     */
    public function update(Request $request, DataRetentionPolicy $policy): RedirectResponse
    {
        $this->authorizePermission($request);

        $validated = $request->validate([
            'policy_name' => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'retention_period_years' => 'sometimes|integer|min:1|max:100',
            'archive_after_years' => 'nullable|integer|min:1|max:100',
            'hard_delete_after_years' => 'nullable|integer|min:1|max:100',
            'retention_conditions' => 'nullable|array',
            'applies_to_soft_deleted' => 'boolean',
            'legal_hold_exemption' => 'boolean',
            'active_case_exemption' => 'boolean',
            'legal_basis' => 'nullable|string',
            'business_justification' => 'nullable|string',
            'active' => 'boolean',
        ]);

        $validated['updated_by'] = auth()->id();

        $policy->update($validated);

        return redirect()
            ->route('privacy.retention.index')
            ->with('success', 'Retention policy updated.');
    }

    /**
     * Review data for retention.
     */
    public function review(Request $request): Response
    {
        $this->authorizePermission($request);

        $policies = DataRetentionPolicy::where('active', true)->get();

        return Inertia::render('privacy/retention/review', [
            'policies' => $policies,
        ]);
    }

    private function authorizePermission(Request $request): void
    {
        abort_unless($request->user()?->canDo('privacy.manageRetention'), 403);
    }
}
