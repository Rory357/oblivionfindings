<?php

namespace App\Domain\Governance\Http\Controllers;

use App\Domain\Governance\Http\Requests\StoreRiskRegisterRequest;
use App\Domain\Governance\Http\Requests\UpdateRiskRegisterRequest;
use App\Domain\Governance\Models\ComplianceObligation;
use App\Domain\Governance\Models\Resolution;
use App\Domain\Governance\Models\RiskAcceptance;
use App\Domain\Governance\Models\RiskEventLink;
use App\Domain\Governance\Models\RiskHeatmapSnapshot;
use App\Domain\Governance\Models\RiskRegisterEntry;
use App\Domain\Governance\Models\RiskTreatment;
use App\Domain\Governance\Services\GovernanceAuditService;
use App\Domain\Governance\Services\RiskScoringService;
use App\Domain\Governance\Support\GovernanceLabels;
use App\Domain\Governance\Support\RiskCommitteeScope;
use App\Http\Controllers\Controller;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class RiskRegisterController extends Controller
{
    /** Resolution statuses once voting has finished (mirrors spend approvals). */
    private const PASSED_RESOLUTION_STATUSES = ['closed', 'implemented', 'archived', 'carried'];

    private const STATUS_FILTERS = ['current', 'open', 'accepted', 'closed', 'all'];

    public function __construct(
        protected RiskScoringService $riskService
    ) {}

    /**
     * The full-page create form was retired for the shared wizard dialog on the
     * register index; old deep links open that dialog instead.
     */
    public function create()
    {
        return redirect()->route('governance.risks.index', ['create' => 1]);
    }

    public function index(Request $request)
    {
        $filters = $this->registerFilters($request);

        $risks = $this->applyRegisterFilters(RiskRegisterEntry::query(), $filters)
            ->with(['riskOwner:id,name', 'acceptances' => fn ($q) => $q->orderByDesc('accepted_at')])
            ->withCount('treatments')
            ->orderByDesc('residual_score')
            ->orderBy('title')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (RiskRegisterEntry $risk) => $this->presentListItem($risk));

        $canCreate = $this->canCreateRisks($request->user());

        return Inertia::render('Governance/Risks/Index', [
            'risks' => $risks,
            'categories' => $this->getCategories(),
            // Every header count uses the same filter scope as the list it opens.
            'summary' => [
                'current' => $this->countWith(['status' => 'current']),
                'critical' => $this->countWith(['status' => 'current', 'severity' => 'critical']),
                'high' => $this->countWith(['status' => 'current', 'severity' => 'high']),
                'above_limit' => $this->countWith(['status' => 'current', 'above_appetite' => true]),
                'accepted' => $this->countWith(['status' => 'accepted']),
                'closed' => $this->countWith(['status' => 'closed']),
            ],
            'filters' => array_filter([
                'category' => $filters['category'],
                'status' => $filters['status'] !== 'current' ? $filters['status'] : null,
                'severity' => $filters['severity'],
                'score' => $filters['severity'] && $filters['score'] === 'before' ? 'before' : null,
                'above_appetite' => $filters['above_appetite'] ? '1' : null,
                'likelihood' => $filters['likelihood'] ? (string) $filters['likelihood'] : null,
                'impact' => $filters['impact'] ? (string) $filters['impact'] : null,
                'search' => $filters['search'],
            ], fn ($value) => $value !== null && $value !== ''),
            'committees' => RiskCommitteeScope::committeeOptions(),
            'canCreate' => $canCreate,
            // Wizard reference data — only for users who can actually register a risk.
            'formOptions' => $canCreate ? fn () => $this->riskFormOptions() : null,
        ]);
    }

    public function show(Request $request, RiskRegisterEntry $risk)
    {
        $risk->load([
            'riskOwner:id,name',
            'closedBy:id,name',
            'treatments.assignedTo:id,name',
            'treatments.completedBy:id,name',
            'acceptances' => fn ($q) => $q->orderByDesc('accepted_at'),
            'acceptances.acceptedBy:id,name',
            'acceptances.resolution:id,title,resolution_reference,status,outcome,board_committee_id,governance_meeting_id',
            'events',
        ]);

        $user = $request->user();
        $today = ComplianceObligation::nzToday();
        $canManage = (bool) $user->canDo('governance.risks.manage');

        // Mirrors the update route (governance.risks.manage) and the policy.
        $canEdit = $user->can('update', $risk) && $canManage;
        $canAccept = $user->can('accept', $risk) && $canManage;
        $canClose = $user->can('close', $risk) && $canManage;

        $treatmentsPayload = $risk->treatments
            ->sortBy('due_date')
            ->map(fn (RiskTreatment $treatment) => $this->presentTreatment($risk, $treatment, $today))
            ->values()
            ->all();

        return Inertia::render('Governance/Risks/Show', [
            'risk' => [
                ...$this->presentRisk($risk, $today),
                'treatments' => $treatmentsPayload,
                'acceptances' => $risk->acceptances
                    ->map(fn (RiskAcceptance $acceptance) => $this->presentAcceptance($acceptance, $user, $today))
                    ->values()
                    ->all(),
                'events' => $risk->events
                    ->sortByDesc('linked_at')
                    ->map(fn (RiskEventLink $event) => $this->presentEvent($event, $user))
                    ->values()
                    ->all(),
            ],
            // Names only — never staff email addresses.
            'assignees' => $canEdit
                ? User::staff()->select('id', 'name')->orderBy('name')->get()
                : [],
            'canEdit' => $canEdit,
            'canAccept' => $canAccept,
            'canClose' => $canClose && ! $risk->isClosedStatus(),
            // Passed resolutions the board can rely on to accept this risk.
            'resolutionOptions' => $canAccept && $risk->isOpenStatus()
                ? $this->passedResolutionOptions($user)
                : [],
            'canViewResolutions' => Gate::forUser($user)->allows('viewAny', Resolution::class),
            // The board committees that oversee this kind of risk.
            'overseenBy' => RiskCommitteeScope::committeesOverseeing($risk),
            // Edit wizard reference data — only for users who can edit this risk.
            'formOptions' => $canEdit ? fn () => $this->riskFormOptions() : null,
        ]);
    }

    public function store(StoreRiskRegisterRequest $request)
    {
        $validated = $request->validated();

        $inherentScore = $this->riskService->calculateInherentScore(
            $validated['likelihood_score'] ?? 3,
            $validated['impact_score'] ?? 3
        );

        $residualScore = $this->riskService->calculateResidualScore(
            $inherentScore,
            $validated['control_effectiveness'] ?? 'moderate'
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
                match ($validated['review_frequency'] ?? 'quarterly') {
                    'monthly' => 1,
                    'quarterly' => 3,
                    'annual' => 12,
                }
            ),
        ]);

        // The register's wizard dialog stays on the page and shows its success
        // pane — preserve that context instead of redirecting to the record.
        if ($request->boolean('_modal')) {
            return back()->with('success', "“{$risk->title}” is now on the risk register.");
        }

        return redirect()->route('governance.risks.show', $risk)
            ->with('success', 'Risk added to the register.');
    }

    public function update(UpdateRiskRegisterRequest $request, RiskRegisterEntry $risk)
    {
        $validated = $request->validated();

        $risk->update($validated);

        // Recalculate scores if necessary
        if (isset($validated['likelihood_score']) ||
            isset($validated['impact_score']) ||
            isset($validated['control_effectiveness'])) {
            $this->riskService->recalculateRisk($risk);
        }

        return redirect()->back()->with('success', 'Risk saved. Its scores have been recalculated.');
    }

    /**
     * The board accepts a risk for a set period. A risk above the board's
     * limit can only be accepted with a resolution the board has passed.
     */
    public function accept(Request $request, RiskRegisterEntry $risk)
    {
        $this->authorize('accept', $risk);

        if ($risk->isClosedStatus()) {
            throw ValidationException::withMessages([
                'justification' => 'This risk is closed, so it cannot be accepted.',
            ]);
        }

        if ($risk->status === RiskRegisterEntry::ACCEPTED_STATUS) {
            throw ValidationException::withMessages([
                'justification' => 'The board has already accepted this risk.',
            ]);
        }

        $validated = $request->validate([
            'justification' => 'required|string|min:50',
            'expiry_months' => 'required|integer|min:1|max:24',
            'conditions' => 'nullable|array',
            'conditions.*' => 'nullable|string|max:500',
            'resolution_id' => 'nullable|integer|min:1',
        ], [
            'justification.required' => 'Explain why the board accepts this risk.',
            'justification.min' => 'Explain why the board accepts this risk in at least 50 characters.',
            'expiry_months.required' => 'Choose how long the acceptance lasts.',
            'expiry_months.integer' => 'Choose how long the acceptance lasts.',
            'expiry_months.min' => 'An acceptance lasts between 1 and 24 months.',
            'expiry_months.max' => 'An acceptance lasts between 1 and 24 months.',
            'conditions.*.max' => 'Keep each condition to 500 characters or fewer.',
            'resolution_id.integer' => 'Choose the resolution from the list.',
        ]);

        $resolution = $this->resolvePassedResolution($request->user(), $validated['resolution_id'] ?? null);

        if (! $risk->within_appetite && $resolution === null) {
            throw ValidationException::withMessages([
                'resolution_id' => "This risk is above the board's limit, so it can only be accepted with a resolution the board has passed. Choose it from the list.",
            ]);
        }

        $conditions = array_values(array_filter(
            array_map(fn ($condition) => trim((string) $condition), $validated['conditions'] ?? []),
            fn (string $condition) => $condition !== '',
        ));

        $acceptance = $this->riskService->acceptRisk(
            $risk,
            $resolution ? 'board_resolution' : 'delegated_authority',
            $validated['justification'],
            $request->user(),
            $resolution?->id,
            null,
            (int) $validated['expiry_months'],
            $conditions,
        );

        $risk->update(['status' => RiskRegisterEntry::ACCEPTED_STATUS]);

        GovernanceAuditService::log('risk.accepted', 'RiskRegisterEntry', $risk->id, [
            'acceptance_id' => $acceptance->id,
            'resolution_id' => $resolution?->id,
            'expires_at' => $acceptance->expires_at?->toDateString(),
        ]);

        return redirect()->back()->with('success', 'Risk accepted by the board until '.GovernanceLabels::date($acceptance->expires_at?->toDateString()).'.');
    }

    public function close(Request $request, RiskRegisterEntry $risk)
    {
        $this->authorize('close', $risk);

        if ($risk->isClosedStatus()) {
            throw ValidationException::withMessages([
                'rationale' => 'This risk is already closed.',
            ]);
        }

        $validated = $request->validate([
            'rationale' => 'required|string|min:20',
        ], [
            'rationale.required' => 'Say why this risk is being closed.',
            'rationale.min' => 'Say why this risk is being closed in at least 20 characters.',
        ]);

        $risk->close($validated['rationale'], auth()->id());

        GovernanceAuditService::log('risk.closed', 'RiskRegisterEntry', $risk->id, [
            'rationale' => $validated['rationale'],
        ]);

        return redirect()->route('governance.risks.show', $risk)
            ->with('success', 'Risk closed. It stays on the register marked as closed.');
    }

    public function addTreatment(Request $request, RiskRegisterEntry $risk)
    {
        $validated = $request->validate([
            'action_description' => 'required|string',
            'assigned_to' => 'required|exists:users,id',
            'due_date' => 'required|date|after:today',
            'expected_score_reduction' => 'nullable|integer|min:1|max:24',
            'evidence_required' => 'boolean',
        ], [
            'action_description.required' => 'Describe the action that will reduce this risk.',
            'assigned_to.required' => 'Choose who will do this action.',
            'assigned_to.exists' => 'Choose who will do this action from the list.',
            'due_date.required' => 'Choose when the action is due.',
            'due_date.date' => 'Enter the due date as a date.',
            'due_date.after' => 'Choose a due date after today.',
            'expected_score_reduction.integer' => 'Enter how much the score should drop as a whole number.',
            'expected_score_reduction.min' => 'The score can drop by between 1 and 24.',
            'expected_score_reduction.max' => 'The score can drop by between 1 and 24.',
        ]);

        $this->riskService->createTreatment(
            $risk,
            $validated['action_description'],
            User::find($validated['assigned_to']),
            new \DateTime($validated['due_date']),
            auth()->user(),
            $validated['expected_score_reduction'] ?? null,
            $validated['evidence_required'] ?? false
        );

        return redirect()->back()->with('success', 'Action added.');
    }

    /**
     * Mark an action to reduce a risk as done. When it was set up to need
     * evidence, a file must be attached first.
     */
    public function completeTreatment(Request $request, RiskRegisterEntry $risk, RiskTreatment $treatment)
    {
        $this->authorize('update', $risk);
        $this->ensureTreatmentBelongsToRisk($risk, $treatment);

        if ($treatment->isComplete()) {
            throw ValidationException::withMessages([
                'treatment' => 'This action is already marked as done.',
            ]);
        }

        if ($treatment->status === 'cancelled') {
            throw ValidationException::withMessages([
                'treatment' => 'This action was cancelled, so it cannot be marked as done.',
            ]);
        }

        if ($treatment->evidence_required && ! $treatment->hasEvidence()) {
            throw ValidationException::withMessages([
                'treatment' => 'Attach evidence before marking this action as done — it was set up to need evidence.',
            ]);
        }

        $validated = $request->validate([
            'completion_notes' => 'nullable|string|max:2000',
        ], [
            'completion_notes.max' => 'Keep the note to 2,000 characters or fewer.',
        ]);

        $treatment->complete((int) auth()->id(), $validated['completion_notes'] ?? null);

        GovernanceAuditService::log('risk_treatment.completed', 'RiskTreatment', $treatment->id, [
            'risk_id' => $risk->id,
        ]);

        return redirect()->back()->with('success', 'Action marked as done.');
    }

    public function updateTreatmentDueDate(Request $request, RiskRegisterEntry $risk, RiskTreatment $treatment)
    {
        $this->authorize('update', $risk);
        $this->ensureTreatmentBelongsToRisk($risk, $treatment);

        if ($treatment->isComplete() || $treatment->status === 'cancelled') {
            throw ValidationException::withMessages([
                'due_date' => 'This action is finished, so its due date can no longer change.',
            ]);
        }

        $today = ComplianceObligation::nzToday();

        $validated = $request->validate([
            'due_date' => 'required|date|after_or_equal:'.$today,
            'reason' => 'nullable|string|max:500',
        ], [
            'due_date.required' => 'Choose the new due date.',
            'due_date.date' => 'Enter the new due date as a date.',
            'due_date.after_or_equal' => 'Choose today or a later date.',
            'reason.max' => 'Keep the reason to 500 characters or fewer.',
        ]);

        $previous = $treatment->due_date?->toDateString();

        $treatment->update([
            'due_date' => $validated['due_date'],
            // A stored "overdue" flag no longer applies once the date moves.
            'status' => $treatment->status === 'overdue' ? 'planned' : $treatment->status,
        ]);

        GovernanceAuditService::log('risk_treatment.due_date_changed', 'RiskTreatment', $treatment->id, [
            'risk_id' => $risk->id,
            'from' => $previous,
            'to' => $validated['due_date'],
            'reason' => $validated['reason'] ?? null,
        ]);

        return redirect()->back()->with('success', 'Due date changed to '.GovernanceLabels::date($validated['due_date']).'.');
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

        return redirect()->back()->with('success', 'Event linked to this risk.');
    }

    /**
     * The full-page edit form was retired for the shared wizard dialog on the
     * risk record; old deep links open that dialog instead.
     */
    public function edit(RiskRegisterEntry $risk)
    {
        return redirect()->route('governance.risks.show', ['risk' => $risk, 'edit' => 1]);
    }

    /**
     * The 5×5 grid defaults to risks still on the register (open or accepted);
     * "Include closed" adds closed ones. The band counts switch between the
     * score before controls and after controls.
     */
    public function heatmap(Request $request)
    {
        $validCategories = array_column($this->getCategories(), 'value');
        $category = in_array($request->query('category'), $validCategories, true)
            ? $request->query('category')
            : null;
        $includeClosed = $request->boolean('include_closed');
        $basis = $request->query('score') === 'after' ? 'after' : 'before';
        $status = $includeClosed ? 'all' : 'current';

        $bands = [];
        foreach (['before', 'after'] as $scoreBasis) {
            foreach (array_keys(RiskRegisterEntry::SEVERITY_BANDS) as $level) {
                $bands[$scoreBasis][$level] = $this->countWith(array_filter([
                    'status' => $status,
                    'category' => $category,
                    'severity' => $level,
                    'score' => $scoreBasis,
                ]));
            }
        }

        return Inertia::render('Governance/Risks/Heatmap', [
            'heatmap' => $this->heatmapCells($category, $status),
            'bands' => $bands,
            'trend' => $this->newRiskTrend($category),
            'categories' => $this->getCategories(),
            'filters' => array_filter([
                'category' => $category,
                'include_closed' => $includeClosed ? '1' : null,
                'score' => $basis,
            ]),
        ]);
    }

    public function trends()
    {
        $snapshots = RiskHeatmapSnapshot::query()
            ->orderByDesc('snapshot_date')
            ->limit(12)
            ->get()
            ->map(fn (RiskHeatmapSnapshot $snapshot) => [
                'id' => $snapshot->id,
                'snapshot_date' => $snapshot->snapshot_date?->toDateString(),
                'summary' => $snapshot->summary,
                'by_category' => collect($snapshot->by_category ?? [])
                    ->map(fn ($data, $category) => [
                        'category' => (string) $category,
                        'label' => GovernanceLabels::label('risk_category', (string) $category),
                        'count' => (int) ($data['count'] ?? 0),
                        'avg_score' => (float) ($data['avg_score'] ?? 0),
                    ])
                    ->values()
                    ->all(),
            ]);

        return Inertia::render('Governance/Risks/Trends', [
            'snapshots' => $snapshots,
            // Taken on the 1st of each month (routes/console.php).
            'nextSnapshotOn' => $this->nextMonthlySnapshotDate(),
        ]);
    }

    /**
     * The risks a committee oversees, driven by the real committee record,
     * its current appointments and the one shared category map.
     */
    public function committeeView(string $committee)
    {
        $model = RiskCommitteeScope::resolve($committee);
        abort_if($model === null, 404);

        $risks = RiskCommitteeScope::currentRisks($model)
            ->map(fn (RiskRegisterEntry $risk) => $this->presentListItem($risk))
            ->values();

        return Inertia::render('Governance/Risks/Committee', [
            'committee' => [
                'id' => (int) $model->id,
                'name' => (string) $model->name,
                'type' => (string) $model->committee_type,
                'description' => $model->description,
                'members' => RiskCommitteeScope::members($model),
                'categories' => RiskCommitteeScope::categoryOptions($model),
            ],
            'committees' => RiskCommitteeScope::committeeOptions(),
            'risks' => $risks,
        ]);
    }

    /**
     * @return array{category: ?string, status: string, severity: ?string, score: string, above_appetite: bool, likelihood: ?int, impact: ?int, search: ?string}
     */
    protected function registerFilters(Request $request): array
    {
        $status = (string) $request->query('status', 'current');
        $status = match ($status) {
            'active' => 'open',
            'voided' => 'closed',
            default => in_array($status, self::STATUS_FILTERS, true) ? $status : 'current',
        };

        $severity = (string) $request->query('severity', '');
        $likelihood = (int) $request->query('likelihood', 0);
        $impact = (int) $request->query('impact', 0);
        $search = trim((string) $request->query('search', ''));
        $category = (string) $request->query('category', '');

        return [
            'category' => in_array($category, array_column($this->getCategories(), 'value'), true) ? $category : null,
            'status' => $status,
            'severity' => array_key_exists($severity, RiskRegisterEntry::SEVERITY_BANDS) ? $severity : null,
            'score' => $request->query('score') === 'before' ? 'before' : 'after',
            'above_appetite' => $request->boolean('above_appetite'),
            'likelihood' => $likelihood >= 1 && $likelihood <= 5 ? $likelihood : null,
            'impact' => $impact >= 1 && $impact <= 5 ? $impact : null,
            'search' => $search !== '' ? $search : null,
        ];
    }

    /**
     * The one filter scope behind the register list AND its header counts.
     *
     * @param  array<string, mixed>  $filters
     */
    protected function applyRegisterFilters(Builder $query, array $filters): Builder
    {
        match ($filters['status'] ?? 'current') {
            'open' => $query->openStatus(),
            'accepted' => $query->where('status', RiskRegisterEntry::ACCEPTED_STATUS),
            'closed' => $query->closedStatus(),
            'all' => $query,
            default => $query->current(),
        };

        if (! empty($filters['category'])) {
            $query->byCategory($filters['category']);
        }

        if (! empty($filters['severity'])) {
            $query->severity($filters['severity'], $filters['score'] ?? 'after');
        }

        // Above the board's limit and not accepted — the risks that need action.
        if (! empty($filters['above_appetite'])) {
            $query->aboveAppetite()->openStatus();
        }

        if (! empty($filters['likelihood'])) {
            $query->where('likelihood_score', $filters['likelihood']);
        }

        if (! empty($filters['impact'])) {
            $query->where('impact_score', $filters['impact']);
        }

        if (! empty($filters['search'])) {
            $term = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], (string) $filters['search']).'%';
            $query->where(fn ($q) => $q->where('title', 'like', $term)
                ->orWhere('risk_reference', 'like', $term));
        }

        return $query;
    }

    /** @param  array<string, mixed>  $filters */
    protected function countWith(array $filters): int
    {
        return $this->applyRegisterFilters(RiskRegisterEntry::query(), $filters)->count();
    }

    /**
     * @return array<string, mixed>
     */
    protected function presentListItem(RiskRegisterEntry $risk): array
    {
        $acceptance = $risk->relationLoaded('acceptances') ? $risk->acceptances->sortByDesc('accepted_at')->first() : null;

        return [
            'id' => $risk->id,
            'risk_reference' => $risk->risk_reference,
            'title' => $risk->title,
            'category' => $risk->category,
            'category_label' => GovernanceLabels::label('risk_category', $risk->category),
            'likelihood_score' => (int) $risk->likelihood_score,
            'impact_score' => (int) $risk->impact_score,
            'inherent_score' => (int) $risk->inherent_score,
            'residual_score' => (int) $risk->residual_score,
            'appetite_threshold' => (int) $risk->appetite_threshold,
            'within_appetite' => (bool) $risk->within_appetite,
            'status' => $risk->presentedStatus(),
            'accepted_until' => $risk->status === RiskRegisterEntry::ACCEPTED_STATUS
                ? $acceptance?->expires_at?->toDateString()
                : null,
            'mitigation_strategy' => $risk->mitigation_strategy,
            'next_review_date' => $risk->next_review_date?->toDateString(),
            'risk_owner' => $risk->riskOwner ? ['name' => $risk->riskOwner->name] : null,
            'treatments_count' => (int) ($risk->treatments_count ?? 0),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function presentRisk(RiskRegisterEntry $risk, string $today): array
    {
        $current = $risk->acceptances->first();

        return [
            'id' => $risk->id,
            'risk_reference' => $risk->risk_reference,
            'title' => $risk->title,
            'description' => $risk->description,
            'category' => $risk->category,
            'category_label' => GovernanceLabels::label('risk_category', $risk->category),
            'likelihood_score' => (int) $risk->likelihood_score,
            'impact_score' => (int) $risk->impact_score,
            'inherent_score' => (int) $risk->inherent_score,
            'residual_score' => (int) $risk->residual_score,
            'control_effectiveness' => $risk->control_effectiveness,
            'within_appetite' => (bool) $risk->within_appetite,
            'appetite_threshold' => (int) $risk->appetite_threshold,
            'status' => $risk->presentedStatus(),
            'mitigation_strategy' => $risk->mitigation_strategy,
            'review_frequency' => $risk->review_frequency,
            'next_review_date' => $risk->next_review_date?->toDateString(),
            'risk_owner_id' => $risk->risk_owner_id,
            'risk_owner' => $risk->riskOwner ? ['id' => $risk->riskOwner->id, 'name' => $risk->riskOwner->name] : null,
            'closure_rationale' => $risk->closure_rationale,
            'closed_at' => $risk->closed_at?->toIso8601String(),
            'closed_by' => $risk->closedBy ? ['name' => $risk->closedBy->name] : null,
            'accepted_until' => $risk->status === RiskRegisterEntry::ACCEPTED_STATUS
                ? $current?->expires_at?->toDateString()
                : null,
            'acceptance_ended' => $risk->status === RiskRegisterEntry::ACCEPTED_STATUS
                && $current?->expires_at !== null
                && $current->expires_at->toDateString() < $today,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function presentTreatment(RiskRegisterEntry $risk, RiskTreatment $treatment, string $today): array
    {
        $status = $treatment->presentedStatus($today);
        $finished = in_array($status, ['complete', 'cancelled'], true);
        $needsEvidence = $treatment->evidence_required && ! $treatment->hasEvidence();

        return [
            'id' => $treatment->id,
            'action_description' => $treatment->action_description,
            'assigned_to' => $treatment->assignedTo
                ? ['id' => $treatment->assignedTo->id, 'name' => $treatment->assignedTo->name]
                : null,
            'due_date' => $treatment->due_date?->toDateString(),
            'status' => $status,
            'expected_score_reduction' => $treatment->expected_score_reduction,
            'evidence_required' => (bool) $treatment->evidence_required,
            'evidence_attachments' => $this->presentTreatmentAttachments($risk, $treatment),
            'completed_at' => $treatment->completed_at?->toIso8601String(),
            'completed_by' => $treatment->completedBy ? ['name' => $treatment->completedBy->name] : null,
            'completion_notes' => $treatment->completion_evidence,
            'can_complete' => ! $finished && ! $needsEvidence,
            'complete_blocked_reason' => ! $finished && $needsEvidence
                ? 'Attach evidence first — this action was set up to need evidence before it is marked done.'
                : null,
            'can_change_due_date' => ! $finished,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function presentAcceptance(RiskAcceptance $acceptance, User $user, string $today): array
    {
        $resolution = $acceptance->resolution;
        $canViewResolution = $resolution !== null && Gate::forUser($user)->allows('view', $resolution);

        return [
            'id' => $acceptance->id,
            'acceptance_type' => $acceptance->acceptance_type,
            'justification' => $acceptance->justification,
            'conditions' => array_values(array_filter((array) ($acceptance->conditions ?? []), fn ($c) => is_string($c) && trim($c) !== '')),
            'accepted_by' => $acceptance->acceptedBy ? ['name' => $acceptance->acceptedBy->name] : null,
            'accepted_at' => $acceptance->accepted_at?->toIso8601String(),
            'expires_at' => $acceptance->expires_at?->toDateString(),
            'expired' => $acceptance->expires_at !== null && $acceptance->expires_at->toDateString() < $today,
            // The resolution is shown only to viewers who can open it.
            'resolution' => $canViewResolution ? [
                'id' => (int) $resolution->id,
                'title' => (string) $resolution->title,
                'reference' => $resolution->resolution_reference,
            ] : null,
            'has_resolution' => $resolution !== null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function presentEvent(RiskEventLink $event, User $user): array
    {
        $href = match ($event->event_type) {
            'incident' => $user->canDo('incidents.viewAny') ? "/incidents/{$event->event_id}" : null,
            'alert' => $user->canDo('controlRoom.viewAny') ? "/control-room/alerts/{$event->event_id}" : null,
            default => null,
        };

        return [
            'id' => $event->id,
            'event_type' => $event->event_type,
            'event_type_label' => GovernanceLabels::label('risk_event_type', $event->event_type),
            'event_reference' => $event->event_reference,
            'event_severity' => $event->event_severity,
            'link_rationale' => $event->link_rationale,
            'linked_at' => $event->linked_at?->toIso8601String(),
            'href' => $href,
        ];
    }

    /**
     * Passed resolutions the viewer can open, newest first.
     *
     * @return array<int, array{id: int, title: string, reference: ?string, decided_at: ?string}>
     */
    protected function passedResolutionOptions(User $user): array
    {
        return Resolution::query()
            ->whereIn('status', self::PASSED_RESOLUTION_STATUSES)
            ->where(fn ($q) => $q->where('outcome', 'carried')->orWhere('status', 'carried'))
            ->orderByDesc('closed_at')
            ->orderByDesc('id')
            ->limit(100)
            ->get(['id', 'title', 'resolution_reference', 'status', 'outcome', 'closed_at', 'board_committee_id', 'governance_meeting_id'])
            ->filter(fn (Resolution $resolution) => Gate::forUser($user)->allows('view', $resolution))
            ->map(fn (Resolution $resolution) => [
                'id' => (int) $resolution->id,
                'title' => (string) $resolution->title,
                'reference' => $resolution->resolution_reference,
                'decided_at' => $resolution->closed_at?->toIso8601String(),
            ])
            ->values()
            ->all();
    }

    protected function resolvePassedResolution(User $user, mixed $resolutionId): ?Resolution
    {
        if (blank($resolutionId)) {
            return null;
        }

        $resolution = Resolution::query()->find((int) $resolutionId);

        if ($resolution === null || Gate::forUser($user)->denies('view', $resolution)) {
            throw ValidationException::withMessages([
                'resolution_id' => 'Choose the resolution from the list.',
            ]);
        }

        $passed = in_array($resolution->status, self::PASSED_RESOLUTION_STATUSES, true)
            && ($resolution->outcome === 'carried' || $resolution->status === 'carried');

        if (! $passed) {
            throw ValidationException::withMessages([
                'resolution_id' => "This resolution can't be used yet — voting must be finished and the result recorded as passed.",
            ]);
        }

        return $resolution;
    }

    protected function nextMonthlySnapshotDate(): string
    {
        $timezone = config('app.worker_timezone');
        $now = CarbonImmutable::now(is_string($timezone) && $timezone !== '' ? $timezone : GovernanceLabels::TIMEZONE);
        $thisMonth = $now->startOfMonth()->setTime(6, 0);

        return ($now->lessThan($thisMonth) ? $thisMonth : $thisMonth->addMonthNoOverflow())->toDateString();
    }

    protected function canCreateRisks(?User $user): bool
    {
        return $user !== null
            && $user->can('create', RiskRegisterEntry::class)
            && $user->canDo('governance.risks.manage');
    }

    /**
     * Reference data for the risk wizard dialog (add on the index, edit on the record).
     *
     * @return array<string, mixed>
     */
    protected function riskFormOptions(): array
    {
        return [
            'categories' => $this->getCategories(),
            'owners' => User::staff()
                ->select('id', 'name')
                ->orderBy('name')
                ->get(),
            'limits' => collect(array_column($this->getCategories(), 'value'))
                ->mapWithKeys(fn (string $category) => [$category => $this->riskService->getAppetiteThreshold($category)])
                ->all(),
        ];
    }

    /**
     * The 5×5 likelihood × impact matrix (rows: likelihood 5→1, columns:
     * impact 1→5). Each cell counts the risks assessed at exactly that
     * likelihood and impact, so a risk is never counted in two cells.
     *
     * @return array<int, array<int, array{score: int, count: int, likelihood: int, impact: int}>>
     */
    protected function heatmapCells(?string $category, string $status): array
    {
        $counts = $this->applyRegisterFilters(RiskRegisterEntry::query(), ['status' => $status, 'category' => $category])
            ->selectRaw('likelihood_score, impact_score, COUNT(*) as aggregate')
            ->groupBy('likelihood_score', 'impact_score')
            ->get()
            ->mapWithKeys(fn ($row) => ["{$row->likelihood_score}:{$row->impact_score}" => (int) $row->aggregate]);

        $heatmap = [];
        for ($likelihood = 5; $likelihood >= 1; $likelihood--) {
            $row = [];
            for ($impact = 1; $impact <= 5; $impact++) {
                $row[] = [
                    'score' => $this->riskService->calculateInherentScore($likelihood, $impact),
                    'count' => $counts["{$likelihood}:{$impact}"] ?? 0,
                    'likelihood' => $likelihood,
                    'impact' => $impact,
                ];
            }
            $heatmap[] = $row;
        }

        return $heatmap;
    }

    /**
     * New risks identified per month over the last 12 months.
     *
     * @return array<int, array{month: string, new_risks: int}>
     */
    protected function newRiskTrend(?string $category): array
    {
        if ($category === null) {
            return $this->riskService->getTrendAnalysis();
        }

        $trend = [];
        for ($i = 11; $i >= 0; $i--) {
            $date = now()->subMonths($i);
            $trend[] = [
                'month' => $date->format('Y-m'),
                'new_risks' => RiskRegisterEntry::byCategory($category)
                    ->whereYear('created_at', $date->year)
                    ->whereMonth('created_at', $date->month)
                    ->count(),
            ];
        }

        return $trend;
    }

    /** @return array<int, array{value: string, label: string}> */
    protected function getCategories(): array
    {
        return collect(array_keys(RiskScoringService::DEFAULT_APPETITE_THRESHOLDS))
            ->map(fn (string $category) => [
                'value' => $category,
                'label' => GovernanceLabels::label('risk_category', $category),
            ])
            ->all();
    }

    /**
     * Upload evidence files (control test results, vendor audit reports, SOC2 docs)
     * against a specific treatment action under a risk.
     */
    public function attachTreatmentFiles(Request $request, RiskRegisterEntry $risk, RiskTreatment $treatment)
    {
        $this->authorize('update', $risk);
        $this->ensureTreatmentBelongsToRisk($risk, $treatment);

        $request->validate([
            'files' => 'required|array|min:1|max:10',
            'files.*' => [
                'required',
                'file',
                'max:20480',
                'mimes:pdf,doc,docx,xls,xlsx,ppt,pptx,jpg,jpeg,png,gif,webp,csv,txt,md',
            ],
        ], [
            'files.required' => 'Choose at least one file to attach.',
            'files.max' => 'Attach up to 10 files at a time.',
            'files.*.max' => 'Each file can be up to 20 MB.',
            'files.*.mimes' => 'Attach PDF, Word, Excel, PowerPoint, image, CSV or text files.',
        ]);

        $existing = is_array($treatment->evidence_attachments) ? $treatment->evidence_attachments : [];

        foreach ($request->file('files') as $file) {
            $directory = "governance/risks/{$risk->id}/treatments/{$treatment->id}";
            $extension = $file->getClientOriginalExtension() ?: $file->extension();
            $storedName = Str::uuid()->toString().($extension ? ".{$extension}" : '');
            $path = $file->storeAs($directory, $storedName, 'local');

            $existing[] = [
                'id' => Str::uuid()->toString(),
                'path' => $path,
                'original_name' => $file->getClientOriginalName(),
                'mime_type' => $file->getMimeType(),
                'size_bytes' => $file->getSize(),
                'uploaded_at' => now()->toIso8601String(),
                'uploaded_by_id' => auth()->id(),
                'uploaded_by_name' => auth()->user()?->name,
            ];
        }

        $treatment->update(['evidence_attachments' => $existing]);

        GovernanceAuditService::log(
            'risk_treatment.attachment_added',
            'RiskTreatment',
            $treatment->id,
            ['risk_id' => $risk->id, 'count' => count($request->file('files'))],
        );

        return $request->wantsJson()
            ? response()->json(['attachments' => $this->presentTreatmentAttachments($risk, $treatment->fresh())])
            : redirect()->back()->with('success', 'Evidence attached.');
    }

    public function deleteTreatmentAttachment(
        Request $request,
        RiskRegisterEntry $risk,
        RiskTreatment $treatment,
        string $attachment,
    ) {
        $this->authorize('update', $risk);
        $this->ensureTreatmentBelongsToRisk($risk, $treatment);

        $existing = is_array($treatment->evidence_attachments) ? $treatment->evidence_attachments : [];
        $target = collect($existing)->firstWhere('id', $attachment);

        if (! $target) {
            abort(404, 'That file is no longer attached.');
        }

        if (isset($target['path']) && Storage::disk('local')->exists($target['path'])) {
            Storage::disk('local')->delete($target['path']);
        }

        $remaining = array_values(
            array_filter($existing, fn (array $row) => ($row['id'] ?? null) !== $attachment),
        );

        $treatment->update(['evidence_attachments' => $remaining]);

        GovernanceAuditService::log(
            'risk_treatment.attachment_removed',
            'RiskTreatment',
            $treatment->id,
            ['risk_id' => $risk->id, 'attachment_id' => $attachment, 'original_name' => $target['original_name'] ?? null],
        );

        return $request->wantsJson()
            ? response()->json(['attachments' => $this->presentTreatmentAttachments($risk, $treatment->fresh())])
            : redirect()->back()->with('success', 'Evidence removed.');
    }

    public function downloadTreatmentAttachment(
        RiskRegisterEntry $risk,
        RiskTreatment $treatment,
        string $attachment,
    ) {
        $this->authorize('view', $risk);
        $this->ensureTreatmentBelongsToRisk($risk, $treatment);

        $existing = is_array($treatment->evidence_attachments) ? $treatment->evidence_attachments : [];
        $target = collect($existing)->firstWhere('id', $attachment);

        if (! $target || empty($target['path']) || ! Storage::disk('local')->exists($target['path'])) {
            abort(404, 'That file is no longer attached.');
        }

        return Storage::disk('local')->download(
            $target['path'],
            $target['original_name'] ?? 'attachment',
            ['Content-Type' => $target['mime_type'] ?? 'application/octet-stream'],
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function presentTreatmentAttachments(RiskRegisterEntry $risk, RiskTreatment $treatment): array
    {
        $existing = is_array($treatment->evidence_attachments) ? $treatment->evidence_attachments : [];

        return collect($existing)->map(fn (array $row) => [
            'id' => $row['id'] ?? null,
            'original_name' => $row['original_name'] ?? 'attachment',
            'mime_type' => $row['mime_type'] ?? null,
            'size_bytes' => $row['size_bytes'] ?? null,
            'uploaded_at' => $row['uploaded_at'] ?? null,
            'uploaded_by_name' => $row['uploaded_by_name'] ?? null,
            'download_url' => isset($row['id'])
                ? "/governance/risks/{$risk->id}/treatments/{$treatment->id}/attachments/{$row['id']}/download"
                : null,
        ])->all();
    }

    /**
     * Guard rail — make sure the URL pair (risk, treatment) actually matches.
     */
    protected function ensureTreatmentBelongsToRisk(RiskRegisterEntry $risk, RiskTreatment $treatment): void
    {
        if ($treatment->risk_register_entry_id !== $risk->id) {
            abort(404, 'That action is not part of this risk.');
        }
    }
}
