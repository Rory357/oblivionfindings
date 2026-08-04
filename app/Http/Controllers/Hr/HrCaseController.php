<?php

namespace App\Http\Controllers\Hr;

use App\Domain\Hr\Models\HrCase;
use App\Domain\Hr\Models\HrCaseEvent;
use App\Domain\Hr\Models\HrDisciplinaryAction;
use App\Domain\Hr\Notifications\HrCaseUpdateNotification;
use App\Domain\Hr\Services\HrCaseAccessService;
use App\Domain\Hr\Services\PeopleMutationLockService;
use App\Http\Controllers\Controller;
use App\Models\ClientIncident;
use App\Models\User;
use App\Services\References\ReferenceNumberGenerator;
use App\Services\UserSiteAccessService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class HrCaseController extends Controller
{
    public function __construct(
        private readonly UserSiteAccessService $siteAccess,
        private readonly HrCaseAccessService $caseAccess,
        private readonly PeopleMutationLockService $mutationLocks,
    ) {}

    /**
     * Case type options shared by the index New-case wizard.
     */
    private const CASE_TYPE_OPTIONS = [
        ['value' => 'grievance', 'label' => 'Grievance'],
        ['value' => 'disciplinary', 'label' => 'Disciplinary'],
        ['value' => 'investigation', 'label' => 'Investigation'],
        ['value' => 'welfare', 'label' => 'Welfare'],
        ['value' => 'complaint', 'label' => 'Complaint'],
        ['value' => 'other', 'label' => 'Other'],
    ];

    /**
     * Severity options shared by the index New-case wizard.
     */
    private const SEVERITY_OPTIONS = [
        ['value' => 'low', 'label' => 'Low'],
        ['value' => 'medium', 'label' => 'Medium'],
        ['value' => 'high', 'label' => 'High'],
        ['value' => 'critical', 'label' => 'Critical'],
    ];

    /**
     * Timeline event type options shared by the show-page Add-event wizard.
     */
    private const EVENT_TYPE_OPTIONS = [
        ['value' => 'note', 'label' => 'Note'],
        ['value' => 'meeting', 'label' => 'Meeting'],
        ['value' => 'phone_call', 'label' => 'Phone Call'],
        ['value' => 'letter', 'label' => 'Letter'],
        ['value' => 'email', 'label' => 'Email'],
        ['value' => 'document', 'label' => 'Document'],
        ['value' => 'investigation_update', 'label' => 'Investigation Update'],
        ['value' => 'other', 'label' => 'Other'],
    ];

    /**
     * List all HR cases.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.cases.view'), 403);

        $search = trim((string) $request->query('q', ''));
        $slaWindow = trim((string) $request->query('sla_window', ''));
        $now = now();
        $next24Hours = now()->addDay();

        $cases = $this->visibleCasesQuery($user)
            ->with(['subject:id,name', 'assignedTo:id,name'])
            ->when($request->query('status'), fn ($q, $status) => $q->where('status', $status))
            ->when($request->query('case_type'), fn ($q, $type) => $q->where('case_type', $type))
            ->when($request->query('severity'), fn ($q, $sev) => $q->where('severity', $sev))
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($inner) use ($search) {
                    $inner->where('case_number', 'like', "%{$search}%")
                        ->orWhere('title', 'like', "%{$search}%")
                        ->orWhereHas('subject', fn ($subjects) => $subjects->where('name', 'like', "%{$search}%"));
                });
            })
            ->when($slaWindow !== '', function ($query) use ($slaWindow, $now, $next24Hours) {
                if ($slaWindow === 'overdue') {
                    $query->whereHas('disciplinaryActions', function ($actions) use ($now) {
                        $actions
                            ->whereNotIn('stage', ['closed'])
                            ->whereNotNull('response_deadline')
                            ->where('response_deadline', '<', $now);
                    });
                }

                if ($slaWindow === 'due_24h') {
                    $query->whereHas('disciplinaryActions', function ($actions) use ($now, $next24Hours) {
                        $actions
                            ->whereNotIn('stage', ['closed'])
                            ->whereNotNull('response_deadline')
                            ->whereBetween('response_deadline', [$now, $next24Hours]);
                    });
                }

                if ($slaWindow === 'missing_deadline') {
                    $query->whereHas('disciplinaryActions', function ($actions) {
                        $actions
                            ->whereNotIn('stage', ['closed'])
                            ->whereNull('response_deadline');
                    });
                }

                if ($slaWindow === 'escalation') {
                    $query->whereHas('disciplinaryActions', function ($actions) use ($now) {
                        $actions
                            ->whereNotIn('stage', ['closed'])
                            ->where(function ($inner) use ($now) {
                                $inner->where(function ($branch) use ($now) {
                                    $branch->whereNotNull('response_deadline')
                                        ->where('response_deadline', '<', $now);
                                })->orWhereNull('investigator_user_id')
                                    ->orWhere(function ($branch) {
                                        $branch->where('stage', 'response_period')
                                            ->whereNull('response_deadline');
                                    })
                                    ->orWhereHas('hrCase', function ($caseQuery) {
                                        $caseQuery->whereIn('severity', ['high', 'critical'])
                                            ->whereNotIn('status', ['closed', 'resolved']);
                                    });
                            });
                    });
                }
            })
            ->orderByDesc('opened_at')
            ->paginate(20)
            ->withQueryString();
        $openCasesQuery = $this->visibleCasesQuery($user)
            ->whereNotIn('status', ['closed', 'resolved']);

        $activeDisciplinaryQuery = HrDisciplinaryAction::query()
            ->whereNotIn('stage', ['closed'])
            ->whereHas('hrCase', fn (Builder $query) => $this->applyVisibleCaseScope($query, $user)
                ->whereNotIn('status', ['closed', 'resolved']));

        $summary = [
            'open_cases' => (clone $openCasesQuery)->count(),
            'unassigned_open_cases' => (clone $openCasesQuery)->whereNull('assigned_to')->count(),
            'high_severity_open_cases' => (clone $openCasesQuery)->whereIn('severity', ['high', 'critical'])->count(),
            'disciplinary_active' => (clone $activeDisciplinaryQuery)->count(),
            'disciplinary_sla_overdue' => (clone $activeDisciplinaryQuery)
                ->whereNotNull('response_deadline')
                ->where('response_deadline', '<', $now)
                ->count(),
            'disciplinary_sla_due_24h' => (clone $activeDisciplinaryQuery)
                ->whereNotNull('response_deadline')
                ->whereBetween('response_deadline', [$now, $next24Hours])
                ->count(),
            'disciplinary_missing_deadline' => (clone $activeDisciplinaryQuery)
                ->whereNull('response_deadline')
                ->count(),
            'escalation_candidates' => (clone $activeDisciplinaryQuery)
                ->where(function ($query) use ($now) {
                    $query->where(function ($branch) use ($now) {
                        $branch->whereNotNull('response_deadline')
                            ->where('response_deadline', '<', $now);
                    })->orWhereNull('investigator_user_id')
                        ->orWhere(function ($branch) {
                            $branch->where('stage', 'response_period')
                                ->whereNull('response_deadline');
                        })
                        ->orWhereHas('hrCase', function ($caseQuery) {
                            $caseQuery->whereIn('severity', ['high', 'critical'])
                                ->whereNotIn('status', ['closed', 'resolved']);
                        });
                })
                ->count(),
        ];

        $canManage = $user->canDo('hr.cases.manage');

        return Inertia::render('hr/cases/index', [
            'cases' => $cases,
            'summary' => $summary,
            'filters' => [
                'status' => $request->query('status'),
                'case_type' => $request->query('case_type'),
                'severity' => $request->query('severity'),
                'q' => $search !== '' ? $search : null,
                'sla_window' => $slaWindow !== '' ? $slaWindow : null,
            ],
            'can' => [
                'manage' => $canManage,
                'disciplinary' => $user->canDo('hr.disciplinary.manage'),
            ],
            // New-case wizard data (managers only — the CTA is hidden otherwise).
            'staff' => $canManage
                ? $this->visibleStaffQuery($user)
                    ->orderBy('name')
                    ->get(['id', 'name', 'email'])
                : [],
            'caseTypes' => self::CASE_TYPE_OPTIONS,
            'severities' => self::SEVERITY_OPTIONS,
            'incidents' => $canManage
                ? $this->incidentSummariesForUser($user)
                : [],
        ]);
    }

    /**
     * The full-page create form was replaced by the New-case wizard on the
     * index; keep the GET route working by deep-linking into it.
     */
    public function create(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.cases.manage'), 403);

        return redirect()->route('hr.cases.index', ['new' => 1]);
    }

    /**
     * The full-page event form was replaced by the Add-event wizard on the
     * case show page; keep the GET route working by deep-linking into it.
     */
    public function createEvent(Request $request, HrCase $case)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.cases.manage'), 403);
        $this->assertCanAccessCase($user, $case);

        return redirect()->route('hr.cases.show', ['case' => $case->id, 'new' => 'event']);
    }

    /**
     * Show a single HR case with timeline.
     *
     * Loads events and disciplinary actions, sorted by occurred_at.
     */
    public function show(Request $request, HrCase $case)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.cases.view'), 403);
        $this->assertCanAccessCase($user, $case);

        $canManageCases = $user->canDo('hr.cases.manage');
        $canManageDisciplinary = $user->canDo('hr.disciplinary.manage');
        $relations = [
            'subject:id,name,email',
            'reportedBy:id,name',
            'assignedTo:id,name',
            'events' => fn ($q) => $q->with('creator:id,name')->orderBy('occurred_at'),
        ];
        if ($canManageDisciplinary) {
            $relations['disciplinaryActions'] = fn ($q) => $q->with([
                'employee:id,name',
                'investigator:id,name',
            ])->orderByDesc('created_at');
        }
        $case->load($relations);

        // Build a combined timeline from events and disciplinary milestones
        $timeline = $case->events
            ->filter(fn (HrCaseEvent $event) => $this->canViewCaseEvent($user, $case, $event, $canManageCases, $canManageDisciplinary))
            ->map(fn ($event) => [
                'type' => 'event',
                'id' => $event->id,
                'title' => $event->title,
                'description' => $event->description,
                'event_type' => $event->event_type,
                'occurred_at' => $event->occurred_at,
                'created_by' => $event->creator?->name,
                'visibility' => $this->normalizeCaseEventVisibility($event->visibility),
            ])
            ->sortBy('occurred_at')
            ->values();

        // Serialise disciplinary actions form-ready (input-formatted dates,
        // string ids, normalised checklist) so the Edit-disciplinary wizard on
        // this page can hydrate directly from the row.
        $disciplinaryActions = $canManageDisciplinary
            ? $case->disciplinaryActions->map(fn (HrDisciplinaryAction $action) => [
                'id' => $action->id,
                'employee_user_id' => (string) $action->employee_user_id,
                'stage' => $action->stage,
                'action_type' => $action->action_type,
                'allegation_summary' => $action->allegation_summary,
                'investigation_notes' => $action->investigation_notes,
                'investigator_user_id' => $action->investigator_user_id ? (string) $action->investigator_user_id : '',
                'notice_issued_at' => optional($action->notice_issued_at)->format('Y-m-d\TH:i'),
                'notice_document_path' => $action->notice_document_path,
                'meeting_scheduled_at' => optional($action->meeting_scheduled_at)->format('Y-m-d\TH:i'),
                'meeting_location' => $action->meeting_location,
                'support_person_advised' => (bool) $action->support_person_advised,
                'meeting_held_at' => optional($action->meeting_held_at)->format('Y-m-d\TH:i'),
                'meeting_notes' => $action->meeting_notes,
                'meeting_attendees' => $action->meeting_attendees ?? [],
                'employee_response' => $action->employee_response,
                'response_deadline' => optional($action->response_deadline)->toDateString(),
                'outcome' => $action->outcome,
                'outcome_rationale' => $action->outcome_rationale,
                'outcome_document_path' => $action->outcome_document_path,
                'good_faith_checklist' => DisciplinaryController::normalizeGoodFaithChecklist((array) ($action->good_faith_checklist ?? [])),
                'appeal_received' => (bool) $action->appeal_received,
                'appeal_notes' => $action->appeal_notes,
                'appeal_outcome' => $action->appeal_outcome,
                'employee' => $action->employee ? ['id' => $action->employee->id, 'name' => $action->employee->name] : null,
                'investigator' => $action->investigator ? ['id' => $action->investigator->id, 'name' => $action->investigator->name] : null,
                'created_at' => optional($action->created_at)->toIso8601String(),
            ])
            : collect();
        $case->setRelation('disciplinaryActions', $disciplinaryActions);

        $canRunWizards = $canManageCases || $canManageDisciplinary;

        return Inertia::render('hr/cases/show', [
            'case' => $case,
            'timeline' => $timeline,
            'linkedIncidents' => $this->incidentSummariesForUser(
                $user,
                (array) ($case->linked_incident_ids ?? []),
            ),
            'can' => [
                'manage' => $canManageCases,
                'disciplinary' => $canManageDisciplinary,
                // Assigned-only access is client-specific and cannot guarantee
                // every linked incident will open; only emit a safe deep link
                // for viewers with application-wide incident access.
                'view_incidents' => $user->canDo('incidents.viewAny'),
            ],
            // Wizard data (Add event / Add disciplinary / Edit disciplinary).
            'staff' => $canRunWizards
                ? $this->visibleStaffQuery($user)
                    ->orderBy('name')
                    ->get(['id', 'name', 'email'])
                : [],
            'eventTypes' => self::EVENT_TYPE_OPTIONS,
            'actionTypes' => DisciplinaryController::ACTION_TYPE_OPTIONS,
            'stageOptions' => collect(DisciplinaryController::STAGES)
                ->map(fn (string $stage) => [
                    'value' => $stage,
                    'label' => str_replace('_', ' ', $stage),
                ])
                ->values(),
            'goodFaithRequiredChecks' => DisciplinaryController::GOOD_FAITH_CHECK_OPTIONS,
        ]);
    }

    /**
     * Store a new HR case.
     */
    public function store(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.cases.manage'), 403);

        $data = $request->validate([
            'user_id' => ['required', 'bail', 'integer', $this->visibleStaffRule($user)],
            'case_type' => ['required', 'string', 'in:grievance,disciplinary,investigation,welfare,complaint,other'],
            'severity' => ['required', 'string', 'in:low,medium,high,critical'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:10000'],
            'assigned_to' => ['nullable', 'bail', 'integer', $this->visibleStaffRule($user)],
            'is_confidential' => ['boolean'],
            'access_list' => ['nullable', 'array', 'max:100'],
            'access_list.*' => ['bail', 'integer', 'distinct', $this->visibleStaffRule($user)],
            'linked_incident_ids' => ['nullable', 'array', 'max:100'],
            'linked_incident_ids.*' => ['integer', 'distinct', $this->availableIncidentRule($user)],
        ]);

        // hr_cases.description is NOT NULL with no default; a description-less case
        // (description is nullable in validation, and an empty field arrives as null
        // via ConvertEmptyStringsToNull) would otherwise 500 on insert. Coerce to ''.
        $data['description'] = $data['description'] ?? '';

        $this->normalizeCaseSelectionIds($data);

        $case = DB::transaction(function () use ($user, $data): HrCase {
            $locks = $this->mutationLocks->lock([
                $user->id,
                $data['user_id'],
                $data['assigned_to'] ?? null,
                ...($data['access_list'] ?? []),
            ]);
            $actor = $locks['users']->get($user->id);
            abort_unless($actor instanceof User && $actor->canDo('hr.cases.manage'), 403);

            $freshSiteAccess = new UserSiteAccessService;
            $this->assertAvailableCaseParticipants($actor, $data, $freshSiteAccess, true);
            $this->assertAvailableIncidentIds(
                $actor,
                (array) ($data['linked_incident_ids'] ?? []),
                $freshSiteAccess,
            );

            return HrCase::query()->create([
                'case_number' => app(ReferenceNumberGenerator::class)->nextGlobal('HR', 5),
                'status' => 'open',
                'reported_by' => $actor->id,
                'opened_at' => now(),
                'created_by' => $actor->id,
                ...$data,
            ]);
        });

        // Tell the owner they've picked up a case (skip self-assignment).
        $this->notifyCaseAssignee($case, $case->assigned_to !== null ? (int) $case->assigned_to : null, $user->id, 'assigned');

        return redirect()->back()->with('success', 'HR case opened.');
    }

    /**
     * Update an existing HR case.
     */
    public function update(Request $request, HrCase $case)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.cases.manage'), 403);
        $this->assertCanAccessCase($user, $case);

        $data = $request->validate([
            'case_type' => ['sometimes', 'string', 'in:grievance,disciplinary,investigation,welfare,complaint,other'],
            'severity' => ['sometimes', 'string', 'in:low,medium,high,critical'],
            'title' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:10000'],
            'status' => ['sometimes', 'string', 'in:open,under_investigation,awaiting_response,resolved'],
            'assigned_to' => ['nullable', 'bail', 'integer', $this->visibleStaffRule($user)],
            'is_confidential' => ['boolean'],
            'access_list' => ['nullable', 'array', 'max:100'],
            'access_list.*' => ['bail', 'integer', 'distinct', $this->visibleStaffRule($user)],
            'linked_incident_ids' => ['nullable', 'array', 'max:100'],
            'linked_incident_ids.*' => ['integer', 'distinct', $this->availableIncidentRule($user)],
        ]);

        // Coerce a null description (empty field → null via ConvertEmptyStringsToNull)
        // back to '' — hr_cases.description is NOT NULL.
        if (array_key_exists('description', $data) && $data['description'] === null) {
            $data['description'] = '';
        }

        $this->normalizeCaseSelectionIds($data);

        [$case, $previousAssignee, $newAssignee] = DB::transaction(function () use ($user, $case, $data): array {
            $locks = $this->mutationLocks->lock([
                $user->id,
                ...$this->casePeopleIds($case),
                $data['assigned_to'] ?? null,
                ...($data['access_list'] ?? []),
            ]);
            $actor = $locks['users']->get($user->id);
            abort_unless($actor instanceof User && $actor->canDo('hr.cases.manage'), 403);

            $lockedCase = HrCase::query()->whereKey($case->getKey())->lockForUpdate()->firstOrFail();
            $freshSiteAccess = new UserSiteAccessService;
            $freshCaseAccess = new HrCaseAccessService($freshSiteAccess);
            $this->assertCanAccessCase($actor, $lockedCase, $freshCaseAccess);
            if ($lockedCase->status === 'closed') {
                throw ValidationException::withMessages([
                    'case' => 'A closed HR case cannot be changed.',
                ]);
            }

            $this->assertAvailableCaseParticipants($actor, $data, $freshSiteAccess, false);
            if (array_key_exists('linked_incident_ids', $data)) {
                $this->assertAvailableIncidentIds(
                    $actor,
                    (array) ($data['linked_incident_ids'] ?? []),
                    $freshSiteAccess,
                );
            }

            $previousAssignee = $lockedCase->assigned_to !== null ? (int) $lockedCase->assigned_to : null;
            $lockedCase->update([
                ...$data,
                'updated_by' => $actor->id,
            ]);

            return [
                $lockedCase->fresh(),
                $previousAssignee,
                $lockedCase->assigned_to !== null ? (int) $lockedCase->assigned_to : null,
            ];
        });

        // Notify only when ownership actually moves to a new person.
        if ($newAssignee !== null && $newAssignee !== $previousAssignee) {
            $this->notifyCaseAssignee($case, $newAssignee, $user->id, 'reassigned');
        }

        return redirect()->back()->with('success', 'HR case updated.');
    }

    /**
     * Add a timeline event to an HR case.
     */
    public function storeEvent(Request $request, HrCase $case)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.cases.manage'), 403);
        $this->assertCanAccessCase($user, $case);

        $data = $request->validate([
            'event_type' => ['required', 'string', 'in:note,meeting,phone_call,letter,email,document,investigation_update,other'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:10000'],
            'occurred_at' => ['required', 'date', 'before_or_equal:now'],
            'document_path' => ['nullable', 'string', 'max:500'],
            'visibility' => ['nullable', 'string', 'in:internal,restricted,full'],
        ]);

        DB::transaction(function () use ($user, $case, $data): void {
            $locks = $this->mutationLocks->lock([
                $user->id,
                ...$this->casePeopleIds($case),
            ]);
            $actor = $locks['users']->get($user->id);
            abort_unless($actor instanceof User && $actor->canDo('hr.cases.manage'), 403);

            $lockedCase = HrCase::query()->whereKey($case->getKey())->lockForUpdate()->firstOrFail();
            $this->assertCanAccessCase(
                $actor,
                $lockedCase,
                new HrCaseAccessService(new UserSiteAccessService),
            );

            HrCaseEvent::query()->create([
                'case_id' => $lockedCase->id,
                'created_by' => $actor->id,
                ...$data,
            ]);
        });

        return redirect()->back()->with('success', 'Event added to case timeline.');
    }

    /**
     * Close an HR case with outcome.
     */
    public function close(Request $request, HrCase $case)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.cases.manage'), 403);
        $this->assertCanAccessCase($user, $case);

        $data = $request->validate([
            'outcome' => ['required', 'string', 'max:5000'],
            'outcome_type' => ['required', 'string', 'in:resolved,no_action,disciplinary,referred,withdrawn'],
        ]);

        DB::transaction(function () use ($user, $case, $data): void {
            $locks = $this->mutationLocks->lock([
                $user->id,
                ...$this->casePeopleIds($case),
            ]);
            $actor = $locks['users']->get($user->id);
            abort_unless($actor instanceof User && $actor->canDo('hr.cases.manage'), 403);

            $lockedCase = HrCase::query()->whereKey($case->getKey())->lockForUpdate()->firstOrFail();
            $this->assertCanAccessCase(
                $actor,
                $lockedCase,
                new HrCaseAccessService(new UserSiteAccessService),
            );
            if ($lockedCase->status === 'closed') {
                throw ValidationException::withMessages([
                    'case' => 'This HR case is already closed.',
                ]);
            }

            $disciplinaryActions = HrDisciplinaryAction::query()
                ->where('case_id', $lockedCase->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get(['id', 'stage']);
            if ($disciplinaryActions->contains(fn (HrDisciplinaryAction $action) => $action->stage !== 'closed')) {
                throw ValidationException::withMessages([
                    'case' => 'Close every disciplinary action before closing the HR case.',
                ]);
            }

            $lockedCase->update([
                'status' => 'closed',
                'outcome' => $data['outcome'],
                'outcome_type' => $data['outcome_type'],
                'closed_at' => now(),
                'updated_by' => $actor->id,
            ]);
        });

        return redirect()->back()->with('success', 'HR case closed.');
    }

    /**
     * Minimal, read-only incident summaries for the HR case federation.
     * ClientIncident remains H&S-owned; HR stores ids and never writes it.
     *
     * @param  array<int, int|string>|null  $incidentIds
     * @return Collection<int, array<string, mixed>>
     */
    private function incidentSummariesForUser(User $user, ?array $incidentIds = null): Collection
    {
        if (Gate::forUser($user)->denies('viewAny', ClientIncident::class)) {
            return collect();
        }

        $ids = $incidentIds === null
            ? null
            : collect($incidentIds)
                ->filter(fn ($id) => is_numeric($id))
                ->map(fn ($id) => (int) $id)
                ->unique()
                ->values();

        if ($ids !== null && $ids->isEmpty()) {
            return collect();
        }

        $incidents = ClientIncident::query();
        if (! $user->canDo('incidents.viewAny')) {
            $incidents->whereHas(
                'client.supportWorkers',
                fn ($workers) => $workers->whereKey($user->id),
            );
        }
        $this->siteAccess->applyClientIncidentScope($incidents, $user);
        $incidents = $incidents
            ->with('client:id,first_name,last_name')
            ->select([
                'id',
                'client_id',
                'reference_number',
                'title',
                'type',
                'severity',
                'status',
                'occurred_at',
            ])
            ->when(
                $ids !== null,
                fn ($query) => $query->whereIn('id', $ids->all()),
                fn ($query) => $query->orderByDesc('occurred_at')->limit(100),
            )
            ->get()
            ->filter(fn (ClientIncident $incident) => Gate::forUser($user)->allows('view', $incident))
            ->values();

        if ($ids !== null) {
            $positions = $ids->flip();
            $incidents = $incidents
                ->sortBy(fn (ClientIncident $incident) => $positions->get($incident->id, PHP_INT_MAX))
                ->values();
        }

        return $incidents->map(fn (ClientIncident $incident) => [
            'id' => $incident->id,
            'reference' => $incident->reference_number ?: 'Incident #'.$incident->id,
            'title' => $incident->title ?: ucfirst(str_replace('_', ' ', (string) $incident->type)),
            'type' => $incident->type,
            'severity' => $incident->severity,
            'status' => $incident->status,
            'occurred_at' => $incident->occurred_at?->toIso8601String(),
            'client' => $incident->client?->full_name,
        ])->values();
    }

    /**
     * Reject missing incidents and records hidden by the canonical incident policy.
     */
    private function availableIncidentRule(User $user): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail) use ($user): void {
            $incident = is_numeric($value)
                ? ClientIncident::query()->find((int) $value)
                : null;

            $visibleAtApprovedSite = $incident !== null
                && ClientIncident::query()
                    ->whereKey($incident->id)
                    ->tap(fn ($query) => $this->siteAccess->applyClientIncidentScope($query, $user))
                    ->exists();

            if (! $incident
                || Gate::forUser($user)->denies('view', $incident)
                || ! $visibleAtApprovedSite) {
                $fail('The selected incident is not available.');
            }
        };
    }

    /** @param array<int, int|string> $incidentIds */
    private function assertAvailableIncidentIds(
        User $viewer,
        array $incidentIds,
        UserSiteAccessService $siteAccess,
    ): void {
        foreach ($incidentIds as $incidentId) {
            $incident = is_numeric($incidentId)
                ? ClientIncident::query()->find((int) $incidentId)
                : null;
            $visibleAtApprovedSite = $incident !== null
                && ClientIncident::query()
                    ->whereKey($incident->id)
                    ->tap(fn ($query) => $siteAccess->applyClientIncidentScope($query, $viewer))
                    ->exists();

            if (! $incident
                || Gate::forUser($viewer)->denies('view', $incident)
                || ! $visibleAtApprovedSite) {
                throw ValidationException::withMessages([
                    'linked_incident_ids' => 'The selected incident is not available.',
                ]);
            }
        }
    }

    private function visibleCasesQuery(User $viewer): Builder
    {
        return $this->applyVisibleCaseScope(HrCase::query(), $viewer);
    }

    private function applyVisibleCaseScope(Builder $query, User $viewer): Builder
    {
        return $this->caseAccess->applyVisibleCaseScope($query, $viewer);
    }

    private function visibleStaffQuery(
        User $viewer,
        ?UserSiteAccessService $siteAccess = null,
    ): Builder {
        return ($siteAccess ?? $this->siteAccess)->applyStaffScope(User::query(), $viewer);
    }

    private function visibleStaffRule(User $viewer): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail) use ($viewer): void {
            if (! is_numeric($value)
                || ! $this->visibleStaffQuery($viewer)->whereKey((int) $value)->exists()) {
                $fail('The selected staff member is not available.');
            }
        };
    }

    private function assertCanAccessCase(
        User $viewer,
        HrCase $case,
        ?HrCaseAccessService $caseAccess = null,
    ): void {
        abort_unless(
            ($caseAccess ?? $this->caseAccess)
                ->applyVisibleCaseScope(HrCase::query(), $viewer)
                ->whereKey($case->getKey())
                ->exists(),
            404,
        );
    }

    /** @param array<string, mixed> $data */
    private function normalizeCaseSelectionIds(array &$data): void
    {
        foreach (['access_list', 'linked_incident_ids'] as $field) {
            if (! array_key_exists($field, $data) || $data[$field] === null) {
                continue;
            }

            $data[$field] = collect((array) $data[$field])
                ->map(fn ($id) => (int) $id)
                ->unique()
                ->values()
                ->all();
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function assertAvailableCaseParticipants(
        User $viewer,
        array $data,
        UserSiteAccessService $siteAccess,
        bool $requireSubject,
    ): void {
        $errors = [];
        if ($requireSubject || array_key_exists('user_id', $data)) {
            $subjectId = $data['user_id'] ?? null;
            if (! is_numeric($subjectId)
                || ! $this->visibleStaffQuery($viewer, $siteAccess)->whereKey((int) $subjectId)->exists()) {
                $errors['user_id'] = 'The selected staff member is not available.';
            }
        }

        if (array_key_exists('assigned_to', $data) && $data['assigned_to'] !== null) {
            $assigneeId = $data['assigned_to'];
            if (! is_numeric($assigneeId)
                || ! $this->visibleStaffQuery($viewer, $siteAccess)->whereKey((int) $assigneeId)->exists()) {
                $errors['assigned_to'] = 'The selected staff member is not available.';
            }
        }

        foreach ((array) ($data['access_list'] ?? []) as $accessId) {
            if (! is_numeric($accessId)
                || ! $this->visibleStaffQuery($viewer, $siteAccess)->whereKey((int) $accessId)->exists()) {
                $errors['access_list'] = 'The selected staff member is not available.';
                break;
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /** @return array<int, int> */
    private function casePeopleIds(HrCase $case): array
    {
        return collect([
            $case->user_id,
            $case->reported_by,
            $case->assigned_to,
            $case->created_by,
            $case->updated_by,
            ...((array) ($case->access_list ?? [])),
        ])
            ->filter(fn ($id) => is_numeric($id) && (int) $id > 0)
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Notify a case's assigned owner that it has landed with them. Fires the
     * previously-dead HrCaseUpdateNotification (database-only — sensitive HR
     * data). Skips self-assignment and is best-effort so a notification failure
     * never rolls back the case write. Assignees pass the current-staff/Site
     * rule and are included in HrCaseAccessService's confidentiality predicate,
     * so this cannot leak a confidential case.
     */
    protected function notifyCaseAssignee(HrCase $case, ?int $assigneeId, int $actorId, string $eventType): void
    {
        if (! $assigneeId || $assigneeId === $actorId) {
            return;
        }

        $assignee = User::find($assigneeId);
        if (! $assignee) {
            return;
        }

        try {
            $assignee->notify(new HrCaseUpdateNotification($case, $eventType));
        } catch (\Throwable $e) {
            Log::warning('Failed to send HR case assignment notification', [
                'case_id' => $case->id,
                'assignee_id' => $assigneeId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    protected function normalizeCaseEventVisibility(?string $visibility): string
    {
        return match ($visibility) {
            'full', 'restricted', 'internal' => $visibility,
            default => 'internal',
        };
    }

    protected function canViewCaseEvent(
        User $viewer,
        HrCase $case,
        HrCaseEvent $event,
        bool $canManageCases,
        bool $canManageDisciplinary
    ): bool {
        $visibility = $this->normalizeCaseEventVisibility($event->visibility);

        if ($canManageCases || $canManageDisciplinary) {
            return true;
        }

        if ($visibility === 'full') {
            return true;
        }

        if ($visibility === 'restricted') {
            $allowedIds = collect([$case->assigned_to, $case->reported_by, $case->user_id])
                ->filter(fn ($id) => is_numeric($id))
                ->map(fn ($id) => (int) $id)
                ->values();

            return $allowedIds->contains((int) $viewer->id);
        }

        return false;
    }
}
