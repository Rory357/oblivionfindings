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
                'requiring_referral' => SafeguardingConcern::requiringExternalReferral()->count(),
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
            'clients' => Client::select('id', 'first_name', 'last_name')
                ->orderBy('last_name')
                ->orderBy('first_name')
                ->get(),
            'staff' => User::staff()->select('id', 'name')->orderBy('name')->get(),
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
            'subject_type' => 'nullable|in:client,staff,other',
            'subject_id' => 'nullable|integer',
            'subject_name' => 'nullable|string|max:255',
            'concern_type' => 'required|string',
            'abuse_category' => 'nullable|string',
            'severity' => 'required|in:low,medium,high,critical',
            'description' => 'required|string',
            'occurred_at' => 'nullable|date',
            'location' => 'nullable|string',
            'alleged_perpetrator_type' => 'nullable|in:client,staff,family,other',
            'alleged_perpetrator_id' => 'nullable|integer',
            'alleged_perpetrator_name' => 'nullable|string|max:255',
            'alleged_perpetrator_details' => 'nullable|string',
            'reporter_notes' => 'nullable|string',
            'witnesses' => 'nullable',
            'immediate_actions' => 'nullable|string',
            'requires_external_referral' => 'boolean',
            'site_id' => 'nullable|exists:sites,id',
            'related_incident_id' => 'nullable|exists:client_incidents,id',
        ]);

        $validated = $this->normalizeConcernInput($request, $validated);

        $validated['reported_by_user_id'] = auth()->id();
        $validated['reported_at'] = now();
        $validated['created_by'] = auth()->id();
        // W1: every concern starts in the explicit 'reported' (awaiting triage) stage,
        // rather than relying on the DB column default.
        $validated['status'] = 'reported';

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
            'concern' => $this->serializeConcernForShow($concern),
            'canUpdate' => auth()->user()->can('update', $concern),
            'canInvestigate' => auth()->user()->can('investigate', $concern),
            'canReportExternal' => auth()->user()->can('reportExternal', $concern),
            'staff' => User::staff()->select('id', 'name')->orderBy('name')->get(),
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
            'concern' => $this->serializeConcernForForm($concern),
            'clients' => Client::select('id', 'first_name', 'last_name')
                ->orderBy('last_name')
                ->orderBy('first_name')
                ->get(),
            'staff' => User::staff()->select('id', 'name')->orderBy('name')->get(),
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
            'subject_type' => 'nullable|in:client,staff,other',
            'subject_id' => 'nullable|integer',
            'subject_name' => 'nullable|string|max:255',
            'concern_type' => 'required|string',
            'abuse_category' => 'nullable|string',
            'severity' => 'required|in:low,medium,high,critical',
            'description' => 'required|string',
            'occurred_at' => 'nullable|date',
            'location' => 'nullable|string',
            'alleged_perpetrator_type' => 'nullable|in:client,staff,family,other',
            'alleged_perpetrator_id' => 'nullable|integer',
            'alleged_perpetrator_name' => 'nullable|string|max:255',
            'alleged_perpetrator_details' => 'nullable|string',
            'witnesses' => 'nullable',
            'immediate_actions' => 'nullable|string',
            'requires_external_referral' => 'boolean',
            'protective_measures' => 'nullable|string',
            'site_id' => 'nullable|exists:sites,id',
        ]);

        $validated = $this->normalizeConcernInput($request, $validated);

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
            'status' => 'required|in:reported,triaged,investigating,action_plan,monitoring,closed,referred_external,no_action_required',
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

    private function normalizeConcernInput(Request $request, array $validated): array
    {
        $subjectType = (string) $request->input('subject_type', '');
        $validated['subject_type'] = match ($subjectType) {
            'client' => Client::class,
            'staff' => User::class,
            default => null,
        };
        $validated['subject_id'] = in_array($subjectType, ['client', 'staff'], true)
            ? ($validated['subject_id'] ?? null)
            : null;
        $validated['subject_name'] = $subjectType === 'other'
            ? $this->nullableString($request->input('other_subject_name'))
            : null;

        $perpetratorType = (string) $request->input('alleged_perpetrator_type', '');
        $validated['alleged_perpetrator_type'] = match ($perpetratorType) {
            'client' => Client::class,
            'staff' => User::class,
            default => null,
        };
        $validated['alleged_perpetrator_id'] = in_array($perpetratorType, ['client', 'staff'], true)
            ? ($validated['alleged_perpetrator_id'] ?? null)
            : null;
        $validated['alleged_perpetrator_name'] = in_array($perpetratorType, ['family', 'other'], true)
            ? $this->nullableString($request->input('other_perpetrator_name'))
            : null;
        $validated['alleged_perpetrator_details'] = $this->nullableString($request->input('perpetrator_relationship'))
            ?? ($validated['alleged_perpetrator_details'] ?? null);

        $validated['immediate_actions'] = $request->boolean('immediate_action_taken')
            ? $this->nullableString($request->input('immediate_action_description'))
            : null;
        $validated['witnesses'] = $this->normalizeWitnesses($request->input('witnesses'));
        $validated['subject_informed'] = $request->boolean('subject_informed');
        $validated['requires_external_referral'] = $request->boolean('requires_external_referral');

        return $validated;
    }

    private function serializeConcernForForm(SafeguardingConcern $concern): array
    {
        return [
            ...$concern->toArray(),
            'subject_type' => match ($concern->subject_type) {
                Client::class, 'client' => 'client',
                User::class, 'staff' => 'staff',
                default => $concern->subject_name ? 'other' : '',
            },
            'other_subject_name' => $concern->subject_name,
            'alleged_perpetrator_type' => match ($concern->alleged_perpetrator_type) {
                Client::class, 'client' => 'client',
                User::class, 'staff' => 'staff',
                default => $concern->alleged_perpetrator_name ? 'other' : '',
            },
            'other_perpetrator_name' => $concern->alleged_perpetrator_name,
            'perpetrator_relationship' => $concern->alleged_perpetrator_details,
            'immediate_action_taken' => filled($concern->immediate_actions),
            'immediate_action_description' => $concern->immediate_actions,
        ];
    }

    private function serializeConcernForShow(SafeguardingConcern $concern): array
    {
        return [
            ...$concern->toArray(),
            'reportedBy' => $this->serializeUser($concern->reportedBy),
            'assignedTo' => $this->serializeUser($concern->assignedTo),
            'closedBy' => $this->serializeUser($concern->closedBy),
            'allegedPerpetrator' => $concern->allegedPerpetrator?->toArray(),
            'investigations' => $concern->investigations
                ->map(fn ($investigation) => [
                    ...$investigation->toArray(),
                    'evidence_summary' => $this->serializeList($investigation->evidence_collected),
                ])
                ->values()
                ->all(),
            'externalReports' => $concern->externalReports
                ->map(fn ($report) => [
                    ...$report->toArray(),
                    'reported_by' => $this->serializeUser($report->reportedBy),
                    'acknowledgment_received' => (bool) $report->acknowledgement_received,
                    'acknowledgment_date' => $report->acknowledged_at?->toISOString(),
                    'acknowledgment_reference' => $report->acknowledgement_reference,
                ])
                ->values()
                ->all(),
            'riskAssessments' => $concern->riskAssessments
                ->map(fn ($assessment) => [
                    ...$assessment->toArray(),
                    'risk_factors' => $this->serializeList($assessment->risk_factors),
                    'protective_factors' => $this->serializeList($assessment->protective_factors),
                    'protective_measures' => $this->serializeList($assessment->protective_measures),
                ])
                ->values()
                ->all(),
            'actionPlans' => $concern->actionPlans
                ->map(fn ($plan) => [
                    ...$plan->toArray(),
                    'assigned_to' => $this->serializeUser($plan->assignedTo),
                ])
                ->values()
                ->all(),
        ];
    }

    private function normalizeWitnesses(mixed $witnesses): ?array
    {
        if (is_array($witnesses)) {
            $entries = array_values(array_filter(array_map(
                fn (mixed $entry) => is_string($entry) ? trim($entry) : '',
                $witnesses,
            )));

            return $entries === [] ? null : $entries;
        }

        if (! is_string($witnesses)) {
            return null;
        }

        $entries = array_values(array_filter(array_map(
            fn (string $entry) => trim($entry),
            preg_split('/\r\n|\r|\n/', $witnesses) ?: [],
        )));

        return $entries === [] ? null : $entries;
    }

    private function serializeList(mixed $value): ?string
    {
        if (is_array($value)) {
            $entries = array_values(array_filter(array_map(
                fn (mixed $entry) => is_string($entry) ? trim($entry) : '',
                $value,
            )));

            return $entries === [] ? null : implode("\n", $entries);
        }

        return $this->nullableString($value);
    }

    private function serializeUser(?User $user): ?array
    {
        if (! $user) {
            return null;
        }

        return [
            'id' => $user->id,
            'name' => $user->name,
        ];
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
