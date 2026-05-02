<?php

namespace App\Http\Controllers;

use App\Models\PrivacyImpactAssessment;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DPIAController extends Controller
{
    /**
     * Display a listing of DPIAs.
     */
    public function index(Request $request): Response
    {
        $this->authorizePermission($request);

        $query = PrivacyImpactAssessment::query()
            ->with(['assessor', 'approvedBy']);

        if ($request->filled('q')) {
            $query->where(function ($q) use ($request) {
                $q->where('assessment_name', 'like', "%{$request->q}%")
                    ->orWhere('project_or_process', 'like', "%{$request->q}%");
            });
        }

        if ($request->filled('outcome')) {
            $query->where('outcome', $request->outcome);
        }

        if ($request->filled('risk_level')) {
            $query->where('overall_risk_level', $request->risk_level);
        }

        $query->orderByDesc('assessment_date');

        $dpias = $query->paginate(20)->withQueryString();

        return Inertia::render('privacy/dpia', [
            'dpias' => $dpias,
            'filters' => $request->only(['q', 'outcome', 'risk_level']),
            'stats' => [
                'total' => PrivacyImpactAssessment::count(),
                'pending_review' => PrivacyImpactAssessment::whereNull('outcome')->count(),
                'high_risk' => PrivacyImpactAssessment::whereIn('overall_risk_level', ['high', 'very_high'])->count(),
                'approved' => PrivacyImpactAssessment::where('outcome', 'approved')->count(),
            ],
        ]);
    }

    /**
     * Show the form for creating a new DPIA.
     */
    public function create(Request $request): Response
    {
        $this->authorizePermission($request);

        return Inertia::render('privacy/dpia/create', [
            'staff' => User::staff()->select('id', 'name')->orderBy('name')->get(),
        ]);
    }

    /**
     * Store a newly created DPIA.
     */
    public function store(Request $request): RedirectResponse
    {
        $this->authorizePermission($request);

        $validated = $request->validate([
            'assessment_name' => 'required|string|max:255',
            'project_or_process' => 'required|string|max:255',
            'description' => 'nullable|string',
            'assessment_type' => 'required|in:new_project,process_change,system_upgrade,periodic_review',
            'personal_data_types' => 'nullable|array',
            'data_subjects' => 'nullable|array',
            'processing_purpose' => 'required|string',
            'legal_basis' => 'required|string',
            'identified_risks' => 'nullable|array',
            'overall_risk_level' => 'required|in:low,medium,high,very_high',
            'mitigation_measures' => 'nullable|array',
            'residual_risk_level' => 'nullable|in:low,medium,high,very_high',
            'review_date' => 'nullable|date',
        ]);

        $validated['assessor_id'] = auth()->id();
        $validated['assessment_date'] = now();

        $dpia = PrivacyImpactAssessment::create($validated);

        return redirect()
            ->route('privacy.dpia.show', $dpia)
            ->with('success', 'DPIA created successfully.');
    }

    /**
     * Display the specified DPIA.
     */
    public function show(Request $request, PrivacyImpactAssessment $dpia): Response
    {
        $this->authorizePermission($request);

        $dpia->load(['assessor', 'approvedBy']);

        return Inertia::render('privacy/dpia/show', [
            'dpia' => $dpia,
        ]);
    }

    /**
     * Show the form for editing the DPIA.
     */
    public function edit(Request $request, PrivacyImpactAssessment $dpia): Response
    {
        $this->authorizePermission($request);

        return Inertia::render('privacy/dpia/edit', [
            'dpia' => $dpia,
            'staff' => User::staff()->select('id', 'name')->orderBy('name')->get(),
        ]);
    }

    /**
     * Update the specified DPIA.
     */
    public function update(Request $request, PrivacyImpactAssessment $dpia): RedirectResponse
    {
        $this->authorizePermission($request);

        $validated = $request->validate([
            'assessment_name' => 'sometimes|string|max:255',
            'project_or_process' => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'personal_data_types' => 'nullable|array',
            'data_subjects' => 'nullable|array',
            'processing_purpose' => 'sometimes|string',
            'legal_basis' => 'sometimes|string',
            'identified_risks' => 'nullable|array',
            'overall_risk_level' => 'sometimes|in:low,medium,high,very_high',
            'mitigation_measures' => 'nullable|array',
            'residual_risk_level' => 'nullable|in:low,medium,high,very_high',
            'review_date' => 'nullable|date',
        ]);

        $dpia->update($validated);

        return back()->with('success', 'DPIA updated.');
    }

    /**
     * Approve the DPIA.
     */
    public function approve(Request $request, PrivacyImpactAssessment $dpia): RedirectResponse
    {
        $this->authorizePermission($request);

        $dpia->update([
            'outcome' => 'approved',
            'approved_by_user_id' => auth()->id(),
            'approved_at' => now(),
        ]);

        return back()->with('success', 'DPIA approved.');
    }

    /**
     * Request review of the DPIA.
     */
    public function review(Request $request, PrivacyImpactAssessment $dpia): RedirectResponse
    {
        $this->authorizePermission($request);

        $request->validate([
            'review_notes' => 'required|string',
        ]);

        $dpia->update([
            'outcome' => 'requires_dpo_review',
        ]);

        return back()->with('success', 'DPIA sent for review.');
    }

    private function authorizePermission(Request $request): void
    {
        abort_unless($request->user()?->canDo('privacy.conductDPIA'), 403);
    }
}
