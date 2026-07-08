<?php

namespace App\Http\Controllers\Hr;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Hr\Concerns\ResolvesHrTenant;
use App\Domain\Hr\Models\HrCase;
use App\Domain\Hr\Models\HrCaseEvent;
use App\Domain\Hr\Models\HrDisciplinaryAction;
use App\Domain\Hr\Notifications\HrCaseUpdateNotification;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;

class HrCaseController extends Controller
{
    use ResolvesHrTenant;

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

        $tenantId = $this->resolveHrTenantIdForUser($user);
        $search = trim((string) $request->query('q', ''));
        $slaWindow = trim((string) $request->query('sla_window', ''));
        $now = now();
        $next24Hours = now()->addDay();

        $cases = HrCase::forTenant($tenantId)
            ->with(['subject:id,name', 'assignedTo:id,name'])
            ->tap(fn ($q) => $this->applyCaseVisibilityScope($q, $user))
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

        $openCasesQuery = HrCase::query()
            ->forTenant($tenantId)
            ->tap(fn ($q) => $this->applyCaseVisibilityScope($q, $user))
            ->whereNotIn('status', ['closed', 'resolved']);

        $activeDisciplinaryQuery = \App\Domain\Hr\Models\HrDisciplinaryAction::query()
            ->where('tenant_id', $tenantId)
            ->whereNotIn('stage', ['closed'])
            ->whereHas('hrCase', fn ($query) => $query->whereNotIn('status', ['closed', 'resolved']));

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
                ? User::staff()
                    ->whereIn('id', $this->hrStaffUserIdsForTenant($tenantId))
                    ->orderBy('name')
                    ->get(['id', 'name', 'email'])
                : [],
            'caseTypes' => self::CASE_TYPE_OPTIONS,
            'severities' => self::SEVERITY_OPTIONS,
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
        $tenantId = $this->resolveHrTenantIdForUser($user);
        $this->assertHrTenantAccess($tenantId, $case->tenant_id);

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
        $tenantId = $this->resolveHrTenantIdForUser($user);
        $this->assertHrTenantAccess($tenantId, $case->tenant_id);
        abort_unless($this->canViewCase($user, $case), 403);

        $case->load([
            'subject:id,name,email',
            'reportedBy:id,name',
            'assignedTo:id,name',
            'events' => fn ($q) => $q->with('creator:id,name')->orderBy('occurred_at'),
            'disciplinaryActions' => fn ($q) => $q->with([
                'employee:id,name',
                'investigator:id,name',
            ])->orderByDesc('created_at'),
        ]);

        $canManageCases = $user->canDo('hr.cases.manage');
        $canManageDisciplinary = $user->canDo('hr.disciplinary.manage');

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
        $case->setRelation(
            'disciplinaryActions',
            $case->disciplinaryActions->map(fn (HrDisciplinaryAction $action) => [
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
            ]),
        );

        $canRunWizards = $canManageCases || $canManageDisciplinary;

        return Inertia::render('hr/cases/show', [
            'case' => $case,
            'timeline' => $timeline,
            'can' => [
                'manage' => $canManageCases,
                'disciplinary' => $canManageDisciplinary,
            ],
            // Wizard data (Add event / Add disciplinary / Edit disciplinary).
            'staff' => $canRunWizards
                ? User::staff()
                    ->whereIn('id', $this->hrStaffUserIdsForTenant($tenantId))
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
        $tenantId = $this->resolveHrTenantIdForUser($user);

        $data = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'case_type' => ['required', 'string', 'in:grievance,disciplinary,investigation,welfare,complaint,other'],
            'severity' => ['required', 'string', 'in:low,medium,high,critical'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:10000'],
            'assigned_to' => ['nullable', 'integer', 'exists:users,id'],
            'is_confidential' => ['boolean'],
            'access_list' => ['nullable', 'array'],
            'access_list.*' => ['integer', 'exists:users,id'],
            'linked_incident_ids' => ['nullable', 'array'],
            'linked_incident_ids.*' => ['integer'],
        ]);

        $case = HrCase::create([
            'tenant_id' => $tenantId,
            'case_number' => app(\App\Services\References\ReferenceNumberGenerator::class)->nextGlobal('HR', 5),
            'status' => 'open',
            'reported_by' => $user->id,
            'opened_at' => now(),
            'created_by' => $user->id,
            ...$data,
        ]);

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
        $tenantId = $this->resolveHrTenantIdForUser($user);
        $this->assertHrTenantAccess($tenantId, $case->tenant_id);

        $data = $request->validate([
            'case_type' => ['sometimes', 'string', 'in:grievance,disciplinary,investigation,welfare,complaint,other'],
            'severity' => ['sometimes', 'string', 'in:low,medium,high,critical'],
            'title' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:10000'],
            'status' => ['sometimes', 'string', 'in:open,under_investigation,awaiting_response,resolved,closed'],
            'assigned_to' => ['nullable', 'integer', 'exists:users,id'],
            'is_confidential' => ['boolean'],
            'access_list' => ['nullable', 'array'],
            'access_list.*' => ['integer', 'exists:users,id'],
            'linked_incident_ids' => ['nullable', 'array'],
            'linked_incident_ids.*' => ['integer'],
        ]);

        $data['updated_by'] = $user->id;

        $previousAssignee = $case->assigned_to !== null ? (int) $case->assigned_to : null;

        $case->update($data);

        // Notify only when ownership actually moves to a new person.
        $newAssignee = $case->assigned_to !== null ? (int) $case->assigned_to : null;
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
        $tenantId = $this->resolveHrTenantIdForUser($user);
        $this->assertHrTenantAccess($tenantId, $case->tenant_id);

        $data = $request->validate([
            'event_type' => ['required', 'string', 'in:note,meeting,phone_call,letter,email,document,investigation_update,other'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:10000'],
            'occurred_at' => ['required', 'date'],
            'document_path' => ['nullable', 'string', 'max:500'],
            'visibility' => ['nullable', 'string', 'in:internal,restricted,full'],
        ]);

        HrCaseEvent::create([
            'case_id' => $case->id,
            'created_by' => $user->id,
            ...$data,
        ]);

        return redirect()->back()->with('success', 'Event added to case timeline.');
    }

    /**
     * Close an HR case with outcome.
     */
    public function close(Request $request, HrCase $case)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.cases.manage'), 403);
        $tenantId = $this->resolveHrTenantIdForUser($user);
        $this->assertHrTenantAccess($tenantId, $case->tenant_id);

        $data = $request->validate([
            'outcome' => ['required', 'string', 'max:5000'],
            'outcome_type' => ['required', 'string', 'in:resolved,no_action,disciplinary,referred,withdrawn'],
        ]);

        $case->update([
            'status' => 'closed',
            'outcome' => $data['outcome'],
            'outcome_type' => $data['outcome_type'],
            'closed_at' => now(),
            'updated_by' => $user->id,
        ]);

        return redirect()->back()->with('success', 'HR case closed.');
    }

    /**
     * Notify a case's assigned owner that it has landed with them. Fires the
     * previously-dead HrCaseUpdateNotification (database-only — sensitive HR
     * data). Skips self-assignment and is best-effort so a notification failure
     * never rolls back the case write. The assignee is always an authorised
     * viewer (see canViewCase), so this can't leak a confidential case.
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
            \Illuminate\Support\Facades\Log::warning('Failed to send HR case assignment notification', [
                'case_id' => $case->id,
                'assignee_id' => $assigneeId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Confidential-case visibility: when a case is flagged is_confidential only
     * the creator, the reporter, the assigned owner, users on the access_list,
     * or a case manager (hr.cases.manage — the strongest existing HR-cases
     * permission) may see it. Non-confidential cases are unrestricted.
     */
    protected function canViewCase(User $viewer, HrCase $case): bool
    {
        if (! $case->is_confidential) {
            return true;
        }

        if ($viewer->canDo('hr.cases.manage')) {
            return true;
        }

        $allowedIds = collect([$case->created_by, $case->reported_by, $case->assigned_to])
            ->merge($case->access_list ?? [])
            ->filter(fn ($id) => is_numeric($id))
            ->map(fn ($id) => (int) $id);

        return $allowedIds->contains((int) $viewer->id);
    }

    /**
     * Query-side twin of {@see canViewCase} for lists/search: hides confidential
     * cases the viewer is not the creator/reporter/owner of, not on the
     * access_list of, and cannot manage.
     */
    protected function applyCaseVisibilityScope($query, User $viewer)
    {
        if ($viewer->canDo('hr.cases.manage')) {
            return $query;
        }

        return $query->where(function ($inner) use ($viewer) {
            $inner->where('is_confidential', false)
                ->orWhereNull('is_confidential')
                ->orWhere('created_by', $viewer->id)
                ->orWhere('reported_by', $viewer->id)
                ->orWhere('assigned_to', $viewer->id)
                // access_list entries may be stored as ints or strings.
                ->orWhereJsonContains('access_list', $viewer->id)
                ->orWhereJsonContains('access_list', (string) $viewer->id);
        });
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
