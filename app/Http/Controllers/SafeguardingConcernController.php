<?php

namespace App\Http\Controllers;

use App\Models\ControlRoomAlert;
use App\Models\HsEvent;
use App\Models\SafeguardingConcern;
use App\Models\SafeguardingInvestigation;
use App\Models\SafeguardingExternalReport;
use App\Models\Client;
use App\Models\User;
use App\Models\Site;
use App\Services\Safeguarding\SafeguardingLifecycle;
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

        $user = $request->user();
        $canSensitive = $user->can('viewSensitive', SafeguardingConcern::class);

        $tab = (string) $request->get('tab', 'all');
        $filters = [
            'q' => $request->get('q') ?: null,
            'tab' => $tab,
            'severity' => $request->get('severity') ?: null,
            'category' => $request->get('category') ?: null,
            'site_id' => $request->integer('site_id') ?: null,
            'subject_id' => $request->integer('subject_id') ?: null,
            'from' => $request->get('from') ?: null,
            'to' => $request->get('to') ?: null,
        ];

        // Footer filters applied to any concern query; tab scope is layered on top.
        $applyFilters = function ($q) use ($filters) {
            if ($filters['severity']) {
                $q->where('severity', $filters['severity']);
            }
            if ($filters['category']) {
                $q->where('abuse_category', $filters['category']);
            }
            if ($filters['site_id']) {
                $q->where('site_id', $filters['site_id']);
            }
            if ($filters['subject_id']) {
                $q->where('subject_type', Client::class)->where('subject_id', $filters['subject_id']);
            }
            if ($filters['from']) {
                $q->whereDate('reported_at', '>=', $filters['from']);
            }
            if ($filters['to']) {
                $q->whereDate('reported_at', '<=', $filters['to']);
            }
            if ($filters['q']) {
                $term = $filters['q'];
                $q->where(function ($w) use ($term) {
                    $w->where('reference_number', 'like', "%{$term}%")
                        ->orWhere('description', 'like', "%{$term}%")
                        ->orWhere('subject_name', 'like', "%{$term}%");
                });
            }
        };

        $tabScopes = [
            'all' => fn ($q) => $q,
            'triage' => fn ($q) => $q->where('status', 'reported'),
            'investigation' => fn ($q) => $q->where('status', 'investigating'),
            'action_plan' => fn ($q) => $q->where('status', 'action_plan'),
            'monitoring' => fn ($q) => $q->where('status', 'monitoring'),
            'referrals' => fn ($q) => $q->where(fn ($w) => $w->where('requires_external_referral', true)->orHas('externalReports')),
            'closed' => fn ($q) => $q->whereIn('status', SafeguardingConcern::TERMINAL_STATUSES),
            'mine' => fn ($q) => $q->where('assigned_to_user_id', $user->id)->whereNotIn('status', SafeguardingConcern::TERMINAL_STATUSES),
        ];

        $tabCounts = [];
        foreach ($tabScopes as $key => $scope) {
            $q = SafeguardingConcern::query();
            $applyFilters($q);
            $scope($q);
            $tabCounts[$key] = $q->count();
        }

        // Reviews worklist source (footer-filtered, non-terminal).
        $reviewBase = SafeguardingConcern::query()->whereNotIn('status', SafeguardingConcern::TERMINAL_STATUSES);
        $applyFilters($reviewBase);
        $reviewConcernIds = $reviewBase->pluck('id');
        $tabCounts['reviews'] = $this->riskReviewQuery($reviewConcernIds)->count()
            + $this->acksAwaitedQuery($reviewConcernIds)->count();

        if ($tab === 'reviews') {
            $rows = $this->reviewWorklist($reviewConcernIds, $user, $canSensitive);
            $rowsKind = 'reviews';
        } else {
            $scope = $tabScopes[$tab] ?? $tabScopes['all'];
            $query = SafeguardingConcern::query()
                ->with(['subject', 'assignedTo', 'site', 'latestRiskAssessment'])
                ->withCount([
                    'externalReports',
                    'alerts as active_alerts_count' => fn ($q) => $q->where('active', true),
                    'actionPlans as overdue_actions_count' => fn ($q) => $q->where('due_date', '<', now())->whereNotIn('status', ['completed', 'cancelled']),
                ]);
            $applyFilters($query);
            $scope($query);
            $query->orderByDesc('reported_at');

            $paginated = $query->paginate(20)->withQueryString();
            $paginated->getCollection()->transform(fn (SafeguardingConcern $c) => $this->mapConcernRow($c, $user, $canSensitive));
            $rows = $paginated;
            $rowsKind = 'concerns';
        }

        return Inertia::render('safeguarding/index', [
            'filters' => $filters,
            'tab' => $tab,
            'tabCounts' => $tabCounts,
            'rows' => $rows,
            'rowsKind' => $rowsKind,
            'hero' => [
                'openWork' => [
                    'open' => ['value' => SafeguardingConcern::open()->count()],
                    'awaitingTriage' => ['value' => SafeguardingConcern::where('status', 'reported')->count()],
                    'investigating' => ['value' => SafeguardingConcern::where('status', 'investigating')->count()],
                    'referred' => ['value' => SafeguardingConcern::where('status', 'referred_external')->count()],
                ],
                'attention' => [
                    'overdueActions' => ['value' => $this->overdueActionsCount()],
                    'reviewsDue' => ['value' => $this->riskReviewQuery($this->openConcernIds())->count()],
                    'acksAwaited' => ['value' => $this->acksAwaitedQuery($this->openConcernIds())->count()],
                    'criticalOpen' => ['value' => SafeguardingConcern::open()->where('severity', 'critical')->count()],
                ],
            ],
            'referralOverdueCount' => SafeguardingConcern::query()
                ->where('requires_external_referral', true)
                ->whereDoesntHave('externalReports')
                ->whereNotIn('status', SafeguardingConcern::TERMINAL_STATUSES)
                ->count(),
            'sites' => Site::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'subjects' => Client::query()->orderBy('last_name')->orderBy('first_name')->get(['id', 'first_name', 'last_name'])
                ->map(fn (Client $c) => ['id' => $c->id, 'name' => trim($c->first_name . ' ' . $c->last_name)])
                ->values(),
            'staff' => User::staff()->select('id', 'name')->orderBy('name')->get()
                ->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name])->values(),
            'raise' => $request->boolean('raise'),
            'can' => ['create' => $user->can('create', SafeguardingConcern::class)],
            // Detail-over-list: only fetched (and only authorised) when ?concern={id} is present.
            'detail' => $this->resolveDetail($request, $user, $canSensitive),
        ]);
    }

    /**
     * Serialize a concern for the detail modal when ?concern={id} is present and
     * the viewer is authorised; otherwise null (the dialog stays closed).
     */
    private function resolveDetail(Request $request, User $user, bool $canSensitive): ?array
    {
        $concernId = $request->integer('concern');
        if (! $concernId) {
            return null;
        }

        $concern = SafeguardingConcern::query()->find($concernId);
        if (! $concern || ! $user->can('view', $concern)) {
            return null;
        }

        return $this->buildConcernDetail($concern, $user, $canSensitive);
    }

    /**
     * Show the form for creating a new concern.
     */
    /**
     * The full-page create form is retired — raising is a modal wizard on the
     * list. Redirect deep links to the list with the wizard open.
     */
    public function create(): RedirectResponse
    {
        $this->authorize('create', SafeguardingConcern::class);

        return redirect()->route('safeguarding.index', ['raise' => 1]);
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

        // back() so the raise wizard can show its success pane (preserveState) and
        // the list refreshes in place; created_concern_id powers "Open concern".
        return back()
            ->with('success', 'Safeguarding concern raised — reference ' . $concern->reference_number . '.')
            ->with('created_concern_id', $concern->id);
    }

    /**
     * Display the specified concern.
     */
    /**
     * Thin deep-link shell for /safeguarding/{id} — renders the same
     * SafeguardingConcernDialog content as the list's detail-over-list modal.
     * Stays accessible to reporters/assignees without global viewAny (policy).
     */
    public function show(Request $request, SafeguardingConcern $concern): Response
    {
        $this->authorize('view', $concern);

        $canSensitive = $request->user()->can('viewSensitive', SafeguardingConcern::class);

        return Inertia::render('safeguarding/concern', [
            'detail' => $this->buildConcernDetail($concern, $request->user(), $canSensitive),
        ]);
    }

    /**
     * Show the form for editing the concern.
     */
    /**
     * The full-page edit form is retired — concern fields are maintained through
     * the detail modal's action panes. Redirect deep links to the concern.
     */
    public function edit(SafeguardingConcern $concern): RedirectResponse
    {
        $this->authorize('update', $concern);

        return redirect()->route('safeguarding.show', $concern);
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
    public function updateStatus(Request $request, SafeguardingConcern $concern, SafeguardingLifecycle $lifecycle): RedirectResponse
    {
        $this->authorize('update', $concern);

        $validated = $request->validate([
            'status' => 'required|in:reported,triaged,investigating,action_plan,monitoring,closed,referred_external,no_action_required',
        ]);

        // Enforce the §4 state machine (W3/W6 + legal transitions). Closing and
        // leaving `reported` have their own actions (close / triage).
        $guard = $lifecycle->guardTransition($concern, $validated['status']);

        if (! $guard['allowed']) {
            return back()->withErrors(['status' => $guard['reason']]);
        }

        $concern->update([
            'status' => $validated['status'],
            'updated_by' => auth()->id(),
        ]);

        return back()->with('success', 'Status updated to ' . $lifecycle->label($validated['status']) . '.');
    }

    /**
     * Triage a reported concern (W4): record substantiation + initial risk +
     * lead, then act on the chosen path — investigate (opens an investigation
     * record automatically → `investigating`), refer (flags the concern for an
     * external referral, awaiting the report → stays `triaged`), or no further
     * action (`no_action_required`, terminal, rationale required).
     */
    public function triage(Request $request, SafeguardingConcern $concern): RedirectResponse
    {
        $this->authorize('update', $concern);

        if ($concern->status !== 'reported') {
            return back()->withErrors(['triage' => 'This concern has already been triaged.']);
        }

        $validated = $request->validate([
            'substantiation' => 'required|in:' . implode(',', SafeguardingLifecycle::SUBSTANTIATIONS),
            'initial_risk' => 'required|in:low,medium,high,critical',
            'lead_user_id' => 'nullable|exists:users,id',
            'path' => 'required|in:' . implode(',', SafeguardingLifecycle::TRIAGE_PATHS),
            'notes' => 'nullable|string',
            // For the "investigate" path we may capture an investigation type up front.
            'investigation_type' => 'nullable|string',
        ]);

        // "No further action" must carry a rationale.
        if ($validated['path'] === 'no_action' && blank($validated['notes'] ?? null)) {
            return back()->withErrors(['notes' => 'Record why no further action is required.']);
        }

        $attributes = [
            'triaged_at' => now(),
            'triaged_by_user_id' => auth()->id(),
            'triage_substantiation' => $validated['substantiation'],
            'triage_decision' => $validated['path'],
            'triage_notes' => $validated['notes'] ?? null,
            'current_risk_level' => $validated['initial_risk'],
            'updated_by' => auth()->id(),
        ];

        if (! empty($validated['lead_user_id'])) {
            $attributes['assigned_to_user_id'] = $validated['lead_user_id'];
            $attributes['assigned_at'] = now();
        }

        $message = 'Concern triaged.';

        switch ($validated['path']) {
            case 'investigate':
                // Opening the investigation record is what satisfies the W3 gate
                // for entering the `investigating` stage.
                SafeguardingInvestigation::create([
                    'safeguarding_concern_id' => $concern->id,
                    'investigation_type' => $validated['investigation_type'] ?? 'internal',
                    'lead_investigator_id' => $validated['lead_user_id'] ?? auth()->id(),
                    'started_at' => now(),
                    'status' => 'planned',
                    'created_by' => auth()->id(),
                ]);
                $attributes['status'] = 'investigating';
                $message = 'Concern triaged — investigation opened.';
                break;

            case 'refer':
                // Referral indicated; the concern waits at `triaged` until an
                // external report is logged (W6), which advances it to referred.
                $attributes['requires_external_referral'] = true;
                $attributes['status'] = 'triaged';
                $message = 'Concern triaged — log the external referral to continue.';
                break;

            case 'no_action':
                $attributes['status'] = 'no_action_required';
                $message = 'Concern triaged — no further safeguarding action required.';
                break;
        }

        $concern->update($attributes);

        if (in_array($concern->status, SafeguardingConcern::TERMINAL_STATUSES, true)) {
            $this->syncTerminalState($concern);
        }

        return back()->with('success', $message);
    }

    /**
     * Close the concern.
     */
    public function close(Request $request, SafeguardingConcern $concern, SafeguardingLifecycle $lifecycle): RedirectResponse
    {
        $this->authorize('update', $concern);

        $validated = $request->validate([
            'closure_summary' => 'required|string',
            'lessons_learned' => 'nullable|string',
            'override_reason' => 'nullable|string',
        ]);

        // A concern must be triaged before it can be closed (a reported concern
        // with no safeguarding response is resolved via triage → no further action).
        if ($concern->status === 'reported') {
            return back()->withErrors(['close' => 'Triage the concern before closing.']);
        }

        if (in_array($concern->status, SafeguardingConcern::TERMINAL_STATUSES, true)) {
            return back()->withErrors(['close' => 'This concern is already closed.']);
        }

        // W7: soft-block closure while investigations / action-plan items are still
        // open, or a referral was indicated but never logged — allowed only with an
        // explicit override reason. (Subject-not-informed is a warning, not a block.)
        $referralUnlogged = $concern->requires_external_referral && $concern->externalReports()->count() === 0;
        $needsOverride = $lifecycle->hasOpenWork($concern) || $referralUnlogged;

        if ($needsOverride && blank($validated['override_reason'] ?? null)) {
            return back()->withErrors([
                'override_reason' => 'Open work or an unlogged referral remains — record why you are closing anyway.',
            ]);
        }

        $closureSummary = $validated['closure_summary'];
        if ($needsOverride && filled($validated['override_reason'] ?? null)) {
            $closureSummary .= "\n\nClosed with open work. Override reason: " . trim($validated['override_reason']);
        }

        $concern->update([
            'status' => 'closed',
            'closure_summary' => $closureSummary,
            'lessons_learned' => $validated['lessons_learned'] ?? null,
            'closed_by_user_id' => auth()->id(),
            'closed_at' => now(),
            'updated_by' => auth()->id(),
        ]);

        $this->syncTerminalState($concern);

        return back()->with('success', 'Concern closed.');
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

    /* ------------------------------------------------------------------ */
    /*  Index helpers (rows, redaction, worklist, counts)                  */
    /* ------------------------------------------------------------------ */

    /**
     * Need-to-know: a sensitive concern is restricted for a viewer who lacks
     * `viewSensitive` and is neither the assignee nor the reporter.
     */
    private function isConcernRestricted(SafeguardingConcern $concern, User $user, bool $canSensitive): bool
    {
        if (! $concern->is_sensitive || $canSensitive) {
            return false;
        }

        return $concern->assigned_to_user_id !== $user->id
            && $concern->reported_by_user_id !== $user->id;
    }

    private function subjectDisplayName(SafeguardingConcern $concern): ?string
    {
        $subject = $concern->subject;

        if ($subject instanceof Client) {
            return trim($subject->first_name . ' ' . $subject->last_name) ?: null;
        }
        if ($subject instanceof User) {
            return $subject->name;
        }

        return $concern->subject_name;
    }

    private function mapConcernRow(SafeguardingConcern $concern, User $user, bool $canSensitive): array
    {
        $restricted = $this->isConcernRestricted($concern, $user, $canSensitive);
        $review = $concern->latestRiskAssessment;
        $reviewDue = $review && $review->next_review_date && $review->next_review_date->isPast();

        return [
            'id' => $concern->id,
            'reference_number' => $concern->reference_number,
            'occurred_at' => $concern->occurred_at?->toISOString(),
            'reported_at' => $concern->reported_at?->toISOString(),
            'concern_type' => $concern->concern_type,
            'abuse_category' => $restricted ? null : $concern->abuse_category,
            'severity' => $concern->severity,
            'status' => $concern->status,
            'current_risk_level' => $concern->current_risk_level,
            'restricted' => $restricted,
            'subject' => $restricted ? null : [
                'name' => $this->subjectDisplayName($concern),
                'site' => $concern->site?->name,
            ],
            'assigned_to' => $concern->assignedTo ? ['name' => $concern->assignedTo->name] : null,
            'flags' => [
                'has_alert' => ($concern->active_alerts_count ?? 0) > 0,
                'requires_referral' => (bool) $concern->requires_external_referral,
                'referral_overdue' => $concern->requires_external_referral
                    && ($concern->external_reports_count ?? 0) === 0
                    && ! in_array($concern->status, SafeguardingConcern::TERMINAL_STATUSES, true),
                'review_due' => (bool) $reviewDue,
                'action_overdue' => ($concern->overdue_actions_count ?? 0) > 0,
            ],
            'related_incident_id' => $concern->related_incident_id,
            'control_room_alert_id' => null, // wired in Step 8 (Control Room cross-module)
        ];
    }

    /** Non-terminal concern ids — the universe for "needs attention" worklists. */
    private function openConcernIds()
    {
        return SafeguardingConcern::query()
            ->whereNotIn('status', SafeguardingConcern::TERMINAL_STATUSES)
            ->pluck('id');
    }

    /** Concerns (within $ids) whose risk review is due/overdue. */
    private function riskReviewQuery($concernIds)
    {
        return SafeguardingConcern::query()
            ->whereIn('id', $concernIds)
            ->whereHas('riskAssessments', fn ($q) => $q->whereNotNull('next_review_date')->where('next_review_date', '<=', now()));
    }

    /** External reports (for concerns within $ids) still awaiting acknowledgement. */
    private function acksAwaitedQuery($concernIds)
    {
        return SafeguardingExternalReport::query()
            ->where('acknowledgement_received', false)
            ->whereIn('safeguarding_concern_id', $concernIds);
    }

    private function overdueActionsCount(): int
    {
        return \App\Models\SafeguardingActionPlan::query()
            ->where('due_date', '<', now())
            ->whereNotIn('status', ['completed', 'cancelled'])
            ->whereIn('safeguarding_concern_id', $this->openConcernIds())
            ->count();
    }

    /**
     * The Reviews-due worklist: risk reviews due + external-report acknowledgements
     * awaited, each redaction-aware, shaped like a (single-page) paginator.
     */
    private function reviewWorklist($concernIds, User $user, bool $canSensitive): array
    {
        $riskItems = $this->riskReviewQuery($concernIds)
            ->with(['subject', 'riskAssessments' => fn ($q) => $q->whereNotNull('next_review_date')->orderBy('next_review_date')])
            ->get()
            ->map(function (SafeguardingConcern $c) use ($user, $canSensitive) {
                $review = $c->riskAssessments->first();
                $restricted = $this->isConcernRestricted($c, $user, $canSensitive);

                return [
                    'id' => $c->id,
                    'reference_number' => $c->reference_number,
                    'restricted' => $restricted,
                    'subject' => $restricted ? null : $this->subjectDisplayName($c),
                    'kind' => 'risk',
                    'detail' => 'Risk review',
                    'due_at' => $review?->next_review_date?->toISOString(),
                    'overdue' => (bool) ($review && $review->next_review_date && $review->next_review_date->isPast()),
                ];
            });

        $ackItems = $this->acksAwaitedQuery($concernIds)
            ->with(['concern.subject'])
            ->get()
            ->map(function (SafeguardingExternalReport $r) use ($user, $canSensitive) {
                $c = $r->concern;
                if (! $c) {
                    return null;
                }
                $restricted = $this->isConcernRestricted($c, $user, $canSensitive);

                return [
                    'id' => $c->id,
                    'reference_number' => $c->reference_number,
                    'restricted' => $restricted,
                    'subject' => $restricted ? null : $this->subjectDisplayName($c),
                    'kind' => 'ack',
                    'detail' => 'Acknowledgement awaited · ' . $r->authority_name,
                    'due_at' => $r->reported_at?->toISOString(),
                    'overdue' => (bool) ($r->reported_at && $r->reported_at->lt(now()->subDays(7))),
                ];
            })
            ->filter()
            ->values();

        return [
            'data' => $riskItems->concat($ackItems)->values()->all(),
            'links' => [],
            'last_page' => 1,
        ];
    }

    /**
     * X3 state-sync: when a concern reaches a terminal state (closed /
     * no_action_required) keep linked records coherent — close the linked HsEvent
     * and resolve the Control Room alert. Best-effort; never blocks the action.
     * (NotifiableIncident has its own regulator lifecycle and is left as-is.)
     */
    private function syncTerminalState(SafeguardingConcern $concern): void
    {
        try {
            $key = HsEvent::buildIdempotencyKey(SafeguardingConcern::class, $concern->getKey(), HsEvent::CATEGORY_SAFEGUARDING);
            $hsEvent = HsEvent::query()->where('idempotency_key', $key)->first();

            if (! $hsEvent) {
                return;
            }

            if ($hsEvent->status !== HsEvent::STATUS_CLOSED) {
                $hsEvent->update(['status' => HsEvent::STATUS_CLOSED]);
            }

            $alertId = $hsEvent->control_room_alert_id;
            if ($alertId) {
                $alert = ControlRoomAlert::find($alertId);
                if ($alert && ! in_array($alert->status, [ControlRoomAlert::STATUS_RESOLVED, ControlRoomAlert::STATUS_CLOSED], true)) {
                    $alert->update(['status' => ControlRoomAlert::STATUS_RESOLVED, 'resolved_at' => now()]);
                }
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Safeguarding terminal state sync failed', [
                'concern_id' => $concern->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /** Stage tracker index per status (referred_external parallels investigating; no_action parallels triaged). */
    private const STAGE_INDEX = [
        'reported' => 0,
        'triaged' => 1,
        'investigating' => 2,
        'action_plan' => 3,
        'monitoring' => 4,
        'closed' => 5,
        'referred_external' => 2,
        'no_action_required' => 1,
    ];

    /**
     * Serialize a concern for the SafeguardingConcernDialog (read-only sections +
     * lifecycle tracker). Need-to-know: a restricted concern returns only a
     * redacted shell. Used by both the list (detail-over-list) and the
     * /safeguarding/{id} thin deep-link shell.
     */
    private function buildConcernDetail(SafeguardingConcern $concern, User $user, bool $canSensitive): array
    {
        $lifecycle = app(SafeguardingLifecycle::class);
        $restricted = $this->isConcernRestricted($concern, $user, $canSensitive);

        $base = [
            'id' => $concern->id,
            'reference_number' => $concern->reference_number,
            'restricted' => $restricted,
            'severity' => $concern->severity,
            'status' => $concern->status,
            'status_label' => $lifecycle->label($concern->status),
            'stage_index' => self::STAGE_INDEX[$concern->status] ?? 0,
            'occurred_at' => $concern->occurred_at?->toISOString(),
            'reported_at' => $concern->reported_at?->toISOString(),
        ];

        if ($restricted) {
            return $base; // redacted shell — the dialog renders the locked state
        }

        $concern->load([
            'subject', 'allegedPerpetrator', 'reportedBy', 'assignedTo', 'closedBy', 'triagedBy',
            'site', 'investigations.leadInvestigator', 'externalReports.reportedBy',
            'riskAssessments.assessor', 'actionPlans.assignedTo', 'alerts', 'attachments.uploader',
        ]);

        // Linked H&S event (read-only surface), resolved via the observer's idempotency key.
        $hsEvent = null;
        try {
            $key = HsEvent::buildIdempotencyKey(SafeguardingConcern::class, $concern->getKey(), HsEvent::CATEGORY_SAFEGUARDING);
            $ev = HsEvent::query()->where('idempotency_key', $key)->first();
            if ($ev) {
                $hsEvent = ['id' => $ev->id, 'reference_number' => $ev->reference_number, 'status' => $ev->status];
            }
        } catch (\Throwable $e) {
            // H&S link is best-effort; never block the detail on it.
        }

        return [
            ...$base,
            'concern_type' => $concern->concern_type,
            'abuse_category' => $concern->abuse_category,
            'location' => $concern->location,
            'description' => $concern->description,
            'immediate_actions' => $concern->immediate_actions,
            'subject_informed' => (bool) $concern->subject_informed,
            'subject_informed_at' => $concern->subject_informed_at?->toISOString(),
            'requires_external_referral' => (bool) $concern->requires_external_referral,
            'current_risk_level' => $concern->current_risk_level,
            'triage' => $concern->triaged_at ? [
                'at' => $concern->triaged_at?->toISOString(),
                'by' => $concern->triagedBy?->name,
                'substantiation' => $concern->triage_substantiation,
                'decision' => $concern->triage_decision,
                'notes' => $concern->triage_notes,
            ] : null,
            'closure' => $concern->closed_at ? [
                'at' => $concern->closed_at?->toISOString(),
                'by' => $concern->closedBy?->name,
                'summary' => $concern->closure_summary,
                'lessons' => $concern->lessons_learned,
            ] : null,
            'people' => [
                'subject' => $this->subjectPerson($concern),
                'reported_by' => $concern->reportedBy?->name ?? $concern->reported_by_name,
                'assigned_to' => $concern->assignedTo?->name,
                'alleged_perpetrator' => $this->perpetratorName($concern),
            ],
            'risk_assessments' => $concern->riskAssessments->map(fn ($r) => [
                'id' => $r->id,
                'assessed_at' => $r->assessed_at?->toISOString(),
                'assessor' => $r->assessor?->name,
                'risk_to_self' => $r->risk_to_self,
                'risk_to_others' => $r->risk_to_others,
                'risk_from_others' => $r->risk_from_others,
                'overall_risk_level' => $r->overall_risk_level,
                'mental_capacity' => $r->mental_capacity,
                'protective_measures' => $this->serializeList($r->protective_measures),
                'next_review_date' => $r->next_review_date?->toISOString(),
                'notes' => $r->assessment_notes,
            ])->values()->all(),
            'investigations' => $concern->investigations->map(fn ($i) => [
                'id' => $i->id,
                'type' => $i->investigation_type,
                'status' => $i->status,
                'lead' => $i->leadInvestigator?->name,
                'started_at' => $i->started_at?->toISOString(),
                'completed_at' => $i->completed_at?->toISOString(),
                'outcome' => $i->outcome,
                'findings' => $i->findings,
                'recommendations' => $i->recommendations,
            ])->values()->all(),
            'external_reports' => $concern->externalReports->map(fn ($r) => [
                'id' => $r->id,
                'authority_type' => $r->authority_type,
                'authority_name' => $r->authority_name,
                'reported_at' => $r->reported_at?->toISOString(),
                'method' => $r->report_method,
                'summary' => $r->report_summary,
                'ack_received' => (bool) $r->acknowledgement_received,
                'acknowledged_at' => $r->acknowledged_at?->toISOString(),
                'ack_reference' => $r->acknowledgement_reference,
                'authority_action' => $r->authority_action,
            ])->values()->all(),
            'action_plans' => $concern->actionPlans->map(fn ($a) => [
                'id' => $a->id,
                'description' => $a->action_description,
                'type' => $a->action_type,
                'assigned_to' => $a->assignedTo?->name,
                'due_date' => $a->due_date?->toISOString(),
                'status' => $a->status,
                'completed_at' => $a->completed_at?->toISOString(),
                'overdue' => (bool) ($a->due_date && $a->due_date->isPast() && ! in_array($a->status, ['completed', 'cancelled'], true)),
            ])->values()->all(),
            'alerts' => $concern->alerts->map(fn ($al) => [
                'id' => $al->id,
                'alert_type' => $al->alert_type,
                'summary' => $al->alert_summary,
                'severity' => $al->severity,
                'active' => (bool) $al->active,
            ])->values()->all(),
            'attachments' => $concern->attachments->map(function (\App\Models\SafeguardingAttachment $a) use ($concern, $canSensitive) {
                // Need-to-know: sensitive evidence is locked for viewers without viewSensitive.
                if ($a->is_sensitive && ! $canSensitive) {
                    return ['id' => $a->id, 'locked' => true, 'is_sensitive' => true];
                }

                return [
                    'id' => $a->id,
                    'locked' => false,
                    'name' => $a->original_name,
                    'mime' => $a->mime,
                    'is_image' => $a->isImage(),
                    'size' => $a->size,
                    'notes' => $a->notes,
                    'is_sensitive' => (bool) $a->is_sensitive,
                    'uploaded_by' => $a->uploader?->name,
                    'created_at' => $a->created_at?->toISOString(),
                    'download_url' => "/safeguarding/{$concern->id}/attachments/{$a->id}/download",
                ];
            })->values()->all(),
            'related_incident_id' => $concern->related_incident_id,
            'hs_event' => $hsEvent,
            'control_room_alert_id' => null, // wired in Step 8
            'can' => [
                'update' => $user->can('update', $concern),
                'investigate' => $user->can('investigate', $concern),
                'report_external' => $user->can('reportExternal', $concern),
            ],
            'assignable_staff' => User::staff()->select('id', 'name')->orderBy('name')->get()
                ->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name])->values()->all(),
        ];
    }

    private function subjectPerson(SafeguardingConcern $concern): ?array
    {
        $name = $this->subjectDisplayName($concern);
        if (! $name) {
            return null;
        }

        $subject = $concern->subject;
        $href = $subject instanceof Client ? "/operations/clients/{$subject->id}/care" : null;
        $type = $subject instanceof Client ? 'client' : ($subject instanceof User ? 'staff' : 'other');

        return ['name' => $name, 'href' => $href, 'type' => $type];
    }

    private function perpetratorName(SafeguardingConcern $concern): ?string
    {
        $p = $concern->allegedPerpetrator;
        if ($p instanceof Client) {
            return trim($p->first_name . ' ' . $p->last_name) ?: null;
        }
        if ($p instanceof User) {
            return $p->name;
        }

        return $concern->alleged_perpetrator_name;
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
