<?php

namespace App\Http\Controllers;

use App\Domain\Privacy\Retention\RetentionContractException;
use App\Domain\Privacy\Retention\RetentionExecutionService;
use App\Domain\Privacy\Retention\RetentionOwnerRegistry;
use App\Models\DataRetentionPolicy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
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
    public function create(Request $request): RedirectResponse
    {
        $this->authorizePermission($request);

        return redirect('/privacy/dashboard?new=retention');
    }

    /**
     * Store a newly created policy.
     */
    public function store(Request $request, RetentionOwnerRegistry $registry): RedirectResponse
    {
        $this->authorizePermission($request);

        $validated = $request->validate([
            'model_type' => ['required', 'string', Rule::in($registry->identifiers())],
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
            'next_review_at' => 'nullable|date',
        ]);

        // Legal holds are a mandatory safety boundary, not an optional policy setting.
        $validated['legal_hold_exemption'] = true;
        $validated['created_by'] = auth()->id();
        $validated['execution_state'] = 'draft';

        $policy = new DataRetentionPolicy($validated);
        $this->validateExecutionContract($policy, $registry);
        $policy->save();

        if ($request->boolean('_modal')) {
            return back()->with('success', 'Retention policy created successfully.');
        }

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
    public function update(
        Request $request,
        DataRetentionPolicy $policy,
        RetentionOwnerRegistry $registry,
        RetentionExecutionService $service,
    ): RedirectResponse {
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

        $validated['legal_hold_exemption'] = true;
        $validated['updated_by'] = auth()->id();

        $policy->fill($validated);
        $this->validateExecutionContract($policy, $registry);
        $service->invalidateApproval($policy, auth()->id());
        $policy->save();

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

        $policies = DataRetentionPolicy::query()
            ->where('active', true)
            ->with(['previewedBy:id,name', 'approvedBy:id,name'])
            ->orderBy('policy_name')
            ->get();

        return Inertia::render('privacy/retention/review', [
            'policies' => $policies,
        ]);
    }

    public function preview(
        Request $request,
        DataRetentionPolicy $policy,
        RetentionExecutionService $service,
    ): RedirectResponse {
        $this->authorizePermission($request);

        try {
            $snapshot = $service->preview($policy, $request->user());
        } catch (RetentionContractException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        $message = $snapshot['blocked']
            ? 'Preview created, but execution is blocked by an active legal hold.'
            : "Preview created for {$snapshot['eligible_count']} eligible outcome(s). A different authorised person must approve it.";

        return back()->with($snapshot['blocked'] ? 'error' : 'success', $message);
    }

    public function approve(
        Request $request,
        DataRetentionPolicy $policy,
        RetentionExecutionService $service,
    ): RedirectResponse {
        $this->authorizePermission($request);

        try {
            $service->approve($policy, $request->user());
        } catch (RetentionContractException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', 'Retention preview approved. Manual and scheduled runs now use this same governed contract.');
    }

    private function authorizePermission(Request $request): void
    {
        abort_unless($request->user()?->canDo('privacy.manageRetention'), 403);
    }

    private function validateExecutionContract(
        DataRetentionPolicy $policy,
        RetentionOwnerRegistry $registry,
    ): void {
        try {
            $registry->resolve($policy->model_type)->validateNativeContract($policy);
        } catch (RetentionContractException $exception) {
            $field = str_contains($exception->reasonCode, 'condition') ? 'retention_conditions' : 'model_type';

            throw ValidationException::withMessages([$field => $exception->getMessage()]);
        }
    }
}
