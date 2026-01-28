<?php

namespace App\Http\Controllers;

use App\Models\SafeguardingConcern;
use App\Models\Client;
use App\Models\User;
use App\Models\Site;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Illuminate\Http\RedirectResponse;

class SafeguardingConcernController extends Controller
{
    /**
     * Display a listing of safeguarding concerns.
     */
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', SafeguardingConcern::class);

        $query = SafeguardingConcern::query()
            ->with([
                'subject',
                'reportedBy',
                'assignedTo',
                'site',
                'latestRiskAssessment',
            ]);

        // Filters
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('severity')) {
            $query->where('severity', $request->severity);
        }

        if ($request->filled('concern_type')) {
            $query->where('concern_type', $request->concern_type);
        }

        if ($request->filled('assigned_to')) {
            $query->where('assigned_to_user_id', $request->assigned_to);
        }

        if ($request->filled('search')) {
            $query->where(function ($q) use ($request) {
                $q->where('reference_number', 'like', "%{$request->search}%")
                    ->orWhere('description', 'like', "%{$request->search}%")
                    ->orWhere('subject_name', 'like', "%{$request->search}%");
            });
        }

        // Sorting
        $sortBy = $request->get('sort_by', 'reported_at');
        $sortOrder = $request->get('sort_order', 'desc');
        $query->orderBy($sortBy, $sortOrder);

        $concerns = $query->paginate(20)->withQueryString();

        return Inertia::render('safeguarding/index', [
            'concerns' => $concerns,
            'filters' => $request->only(['status', 'severity', 'concern_type', 'assigned_to', 'search']),
            'stats' => [
                'open' => SafeguardingConcern::open()->count(),
                'critical' => SafeguardingConcern::where('severity', 'critical')->open()->count(),
                'requiring_referral' => SafeguardingConcern::requireingExternalReferral()->count(),
                'assigned_to_me' => SafeguardingConcern::where('assigned_to_user_id', auth()->id())->open()->count(),
            ],
        ]);
    }

    /**
     * Show the form for creating a new concern.
     */
    public function create(): Response
    {
        $this->authorize('create', SafeguardingConcern::class);

        return Inertia::render('safeguarding/create', [
            'clients' => Client::select('id', 'name')->orderBy('name')->get(),
            'staff' => User::select('id', 'name')->orderBy('name')->get(),
            'sites' => Site::select('id', 'name')->where('is_active', true)->orderBy('name')->get(),
        ]);
    }

    /**
     * Store a newly created concern.
     */
    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', SafeguardingConcern::class);

        $validated = $request->validate([
            'subject_type' => 'nullable|string',
            'subject_id' => 'nullable|integer',
            'subject_name' => 'nullable|string|max:255',
            'concern_type' => 'required|string',
            'abuse_category' => 'nullable|string',
            'severity' => 'required|in:low,medium,high,critical',
            'description' => 'required|string',
            'occurred_at' => 'nullable|date',
            'location' => 'nullable|string',
            'alleged_perpetrator_type' => 'nullable|string',
            'alleged_perpetrator_id' => 'nullable|integer',
            'alleged_perpetrator_name' => 'nullable|string|max:255',
            'alleged_perpetrator_details' => 'nullable|string',
            'reporter_notes' => 'nullable|string',
            'witnesses' => 'nullable|array',
            'immediate_actions' => 'nullable|string',
            'requires_external_referral' => 'boolean',
            'site_id' => 'nullable|exists:sites,id',
            'related_incident_id' => 'nullable|exists:client_incidents,id',
        ]);

        $validated['reported_by_user_id'] = auth()->id();
        $validated['reported_at'] = now();
        $validated['organization_id'] = auth()->user()->organization_id;
        $validated['created_by'] = auth()->id();

        $concern = SafeguardingConcern::create($validated);

        return redirect()
            ->route('safeguarding.show', $concern)
            ->with('success', 'Safeguarding concern created successfully with reference: ' . $concern->reference_number);
    }

    /**
     * Display the specified concern.
     */
    public function show(SafeguardingConcern $concern): Response
    {
        $this->authorize('view', $concern);

        $concern->load([
            'subject',
            'allegedPerpetrator',
            'reportedBy',
            'assignedTo',
            'closedBy',
            'site',
            'relatedIncident',
            'investigations.leadInvestigator',
            'externalReports.reportedBy',
            'riskAssessments.assessor',
            'actionPlans.assignedTo',
            'alerts',
        ]);

        return Inertia::render('safeguarding/show', [
            'concern' => $concern,
            'canUpdate' => auth()->user()->can('update', $concern),
            'canInvestigate' => auth()->user()->can('investigate', $concern),
            'canReportExternal' => auth()->user()->can('reportExternal', $concern),
        ]);
    }

    /**
     * Show the form for editing the concern.
     */
    public function edit(SafeguardingConcern $concern): Response
    {
        $this->authorize('update', $concern);

        $concern->load(['subject', 'allegedPerpetrator', 'site']);

        return Inertia::render('safeguarding/edit', [
            'concern' => $concern,
            'clients' => Client::select('id', 'name')->orderBy('name')->get(),
            'staff' => User::select('id', 'name')->orderBy('name')->get(),
            'sites' => Site::select('id', 'name')->where('is_active', true)->orderBy('name')->get(),
        ]);
    }

    /**
     * Update the specified concern.
     */
    public function update(Request $request, SafeguardingConcern $concern): RedirectResponse
    {
        $this->authorize('update', $concern);

        $validated = $request->validate([
            'subject_type' => 'nullable|string',
            'subject_id' => 'nullable|integer',
            'subject_name' => 'nullable|string|max:255',
            'concern_type' => 'required|string',
            'abuse_category' => 'nullable|string',
            'severity' => 'required|in:low,medium,high,critical',
            'description' => 'required|string',
            'occurred_at' => 'nullable|date',
            'location' => 'nullable|string',
            'alleged_perpetrator_type' => 'nullable|string',
            'alleged_perpetrator_id' => 'nullable|integer',
            'alleged_perpetrator_name' => 'nullable|string|max:255',
            'alleged_perpetrator_details' => 'nullable|string',
            'witnesses' => 'nullable|array',
            'immediate_actions' => 'nullable|string',
            'requires_external_referral' => 'boolean',
            'protective_measures' => 'nullable|string',
            'site_id' => 'nullable|exists:sites,id',
        ]);

        $validated['updated_by'] = auth()->id();

        $concern->update($validated);

        return redirect()
            ->route('safeguarding.show', $concern)
            ->with('success', 'Safeguarding concern updated successfully.');
    }

    /**
     * Assign concern to a user.
     */
    public function assign(Request $request, SafeguardingConcern $concern): RedirectResponse
    {
        $this->authorize('update', $concern);

        $request->validate([
            'assigned_to_user_id' => 'required|exists:users,id',
        ]);

        $concern->update([
            'assigned_to_user_id' => $request->assigned_to_user_id,
            'assigned_at' => now(),
            'updated_by' => auth()->id(),
        ]);

        return back()->with('success', 'Concern assigned successfully.');
    }

    /**
     * Update concern status.
     */
    public function updateStatus(Request $request, SafeguardingConcern $concern): RedirectResponse
    {
        $this->authorize('update', $concern);

        $request->validate([
            'status' => 'required|in:reported,triaged,investigating,action_plan,monitoring,closed,referred_external',
        ]);

        $concern->update([
            'status' => $request->status,
            'updated_by' => auth()->id(),
        ]);

        return back()->with('success', 'Status updated successfully.');
    }

    /**
     * Close the concern.
     */
    public function close(Request $request, SafeguardingConcern $concern): RedirectResponse
    {
        $this->authorize('update', $concern);

        $request->validate([
            'closure_summary' => 'required|string',
            'lessons_learned' => 'nullable|string',
        ]);

        $concern->update([
            'status' => 'closed',
            'closure_summary' => $request->closure_summary,
            'lessons_learned' => $request->lessons_learned,
            'closed_by_user_id' => auth()->id(),
            'closed_at' => now(),
            'updated_by' => auth()->id(),
        ]);

        return redirect()
            ->route('safeguarding.show', $concern)
            ->with('success', 'Concern closed successfully.');
    }

    /**
     * Mark subject as informed.
     */
    public function markSubjectInformed(SafeguardingConcern $concern): RedirectResponse
    {
        $this->authorize('update', $concern);

        $concern->update([
            'subject_informed' => true,
            'subject_informed_at' => now(),
            'updated_by' => auth()->id(),
        ]);

        return back()->with('success', 'Subject marked as informed.');
    }
}
