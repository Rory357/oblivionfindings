<?php

namespace App\Domain\Governance\Http\Controllers;

use App\Domain\Governance\Http\Requests\StoreMeetingRequest;
use App\Domain\Governance\Http\Requests\UpdateMeetingRequest;
use App\Domain\Governance\Models\ActionItem;
use App\Domain\Governance\Models\BoardCommittee;
use App\Domain\Governance\Models\BoardMember;
use App\Domain\Governance\Models\GovernanceMeeting;
use App\Domain\Governance\Models\MeetingAgendaItem;
use App\Domain\Governance\Models\MeetingAttendance;
use App\Domain\Governance\Models\MeetingRsvp;
use App\Domain\Governance\Models\Resolution;
use App\Domain\Governance\Services\BoardPackAccessService;
use App\Domain\Governance\Services\ExecutiveMeetingAccessService;
use App\Domain\Governance\Services\GovernanceNestedMutationService;
use App\Domain\Governance\Services\GovernanceRecordAccessService;
use App\Domain\Governance\Services\GovernanceVotingProfileService;
use App\Domain\Governance\Services\GovernanceWorkflowService;
use App\Domain\Governance\Services\MeetingMinuteService;
use App\Domain\Governance\Services\VotingService;
use App\Domain\Governance\Support\GovernancePresenter;
use App\Http\Controllers\Controller;
use App\Models\User;
use Carbon\Carbon;
use DomainException;
use Illuminate\Http\Request;
use Inertia\Inertia;

class GovernanceMeetingController extends Controller
{
    public function __construct(
        protected GovernanceWorkflowService $workflowService,
        protected GovernancePresenter $presenter,
        protected GovernanceNestedMutationService $nestedMutations,
        protected BoardPackAccessService $boardPackAccess,
        protected ExecutiveMeetingAccessService $executiveAccess,
        protected MeetingMinuteService $minuteService,
        protected VotingService $votingService,
        protected GovernanceRecordAccessService $recordAccess,
    ) {}

    /** Meeting types accepted by Store/UpdateMeetingRequest, in display order. */
    private const MEETING_TYPES = [
        'full_board' => 'Full Board',
        'audit_risk' => 'Audit & Risk',
        'people' => 'People Committee',
        'finance' => 'Finance Committee',
        'special_general' => 'Special General',
        'executive_session' => 'Executive Session',
    ];

    /** Meeting lifecycle statuses accepted by UpdateMeetingRequest. */
    private const MEETING_STATUSES = [
        'scheduled', 'agenda_draft', 'agenda_final', 'in_progress', 'minutes_draft',
        'minutes_review', 'minutes_approved', 'minutes_signed', 'archived',
    ];

    /**
     * Legacy deep link: scheduling is a wizard dialog on the register. The
     * retired page carried no extra authorisation beyond the route group, so
     * this only forwards the calendar's date/hour seed to the dialog.
     */
    public function create(Request $request)
    {
        $query = ['create' => 1];

        $date = $request->input('date');
        if (is_string($date) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1) {
            $query['date'] = $date;
            $hour = $request->input('hour');
            if (is_numeric($hour) && (int) $hour >= 0 && (int) $hour <= 23) {
                $query['hour'] = (int) $hour;
            }
        }

        return redirect()->route('governance.meetings.index', $query);
    }

    public function index(Request $request)
    {
        $validated = $request->validate([
            'status' => 'nullable|in:upcoming,minutes_pending,'.implode(',', self::MEETING_STATUSES),
            'meeting_type' => 'nullable|in:'.implode(',', array_keys(self::MEETING_TYPES)),
            'from' => 'nullable|date_format:Y-m-d',
            'to' => 'nullable|date_format:Y-m-d',
            'search' => 'nullable|string|max:120',
            'date' => 'nullable|date_format:Y-m-d',
            'hour' => 'nullable|integer|min:0|max:23',
        ]);

        $viewer = $request->user();
        $zone = config('app.worker_timezone', 'Pacific/Auckland');
        $visible = fn () => $this->executiveAccess->applyMeetingVisibilityScope(GovernanceMeeting::query(), $viewer);

        $query = $this->executiveAccess->applyMeetingVisibilityScope(
            GovernanceMeeting::with(['chair.user', 'secretary.user', 'committee:id,name']),
            $viewer
        );

        $status = $validated['status'] ?? null;
        if ($status === 'upcoming') {
            $query->where('scheduled_at', '>=', now())->whereNotIn('status', ['archived']);
        } elseif ($status === 'minutes_pending') {
            $query->whereIn('status', ['minutes_draft', 'minutes_review']);
        } elseif ($status !== null) {
            $query->where('status', $status);
        }

        if (! empty($validated['meeting_type'])) {
            $query->where('meeting_type', $validated['meeting_type']);
        }

        // Date filters are NZ calendar days; scheduled_at is stored in UTC.
        if (! empty($validated['from'])) {
            $query->where('scheduled_at', '>=', Carbon::createFromFormat('Y-m-d', $validated['from'], $zone)->startOfDay()->utc());
        }
        if (! empty($validated['to'])) {
            $query->where('scheduled_at', '<=', Carbon::createFromFormat('Y-m-d', $validated['to'], $zone)->endOfDay()->utc());
        }

        if (! empty($validated['search'])) {
            $term = '%'.addcslashes($validated['search'], '%_\\').'%';
            $query->where(fn ($q) => $q->where('title', 'like', $term)->orWhere('location', 'like', $term));
        }

        // Upcoming meetings read soonest-first; everything else newest-first.
        $status === 'upcoming'
            ? $query->orderBy('scheduled_at')
            : $query->orderByDesc('scheduled_at');

        // Page links keep the filters but never the one-shot wizard deep link.
        $meetings = $query->paginate(15)->appends(
            collect($request->query())->except(['create', 'date', 'hour', 'page'])->all()
        );

        $nextMeeting = $visible()
            ->where('scheduled_at', '>=', now())
            ->whereNotIn('status', ['archived'])
            ->orderBy('scheduled_at')
            ->first(['id', 'title', 'scheduled_at']);

        $heldQuery = $visible()->where('scheduled_at', '<', now());
        $summary = [
            'total' => $visible()->count(),
            'upcoming' => $visible()->where('scheduled_at', '>=', now())->whereNotIn('status', ['archived'])->count(),
            'minutes_pending' => $visible()->whereIn('status', ['minutes_draft', 'minutes_review'])->count(),
            'held' => (clone $heldQuery)->count(),
            'held_quorum_met' => (clone $heldQuery)->where('quorum_met', true)->count(),
            'next_meeting' => $nextMeeting ? [
                'id' => $nextMeeting->id,
                'title' => $nextMeeting->title,
                'scheduled_at' => $nextMeeting->scheduled_at?->toIso8601String(),
            ] : null,
            'today' => now($zone)->toDateString(),
        ];

        $canCreate = $this->canScheduleMeetings($viewer);

        $initialScheduledAt = null;
        if ($canCreate && ! empty($validated['date'])) {
            $hour = str_pad((string) ($validated['hour'] ?? 9), 2, '0', STR_PAD_LEFT);
            $initialScheduledAt = "{$validated['date']}T{$hour}:00";
        }

        return Inertia::render('Governance/Meetings/Index', [
            'meetings' => $meetings,
            'filters' => [
                'status' => $status,
                'meeting_type' => $validated['meeting_type'] ?? null,
                'from' => $validated['from'] ?? null,
                'to' => $validated['to'] ?? null,
                'search' => $validated['search'] ?? null,
            ],
            'summary' => $summary,
            'meetingTypes' => self::MEETING_TYPES,
            'canCreate' => $canCreate,
            // Scheduling wizard options — only for viewers who can schedule.
            'formOptions' => $canCreate ? $this->meetingFormOptions($viewer) : null,
            'initialScheduledAt' => $initialScheduledAt,
        ]);
    }

    /** The store route's permission gate plus the policy's create ability. */
    protected function canScheduleMeetings(User $user): bool
    {
        return $user->canDo('governance.meetings.manage')
            && $user->can('create', GovernanceMeeting::class);
    }

    /**
     * Select options for the meeting wizard (the retired Create/Edit pages'
     * props). Executive sessions are only offered to viewers the store/update
     * guard would accept.
     *
     * @return array{board_members: array<int, array{id: int, name: string, is_active: bool}>, committees: array<int, array{id: int, name: string, committee_type: ?string}>, can_schedule_executive: bool}
     */
    protected function meetingFormOptions(User $viewer): array
    {
        return [
            'board_members' => BoardMember::with('user:id,name')->get()
                ->filter(fn (BoardMember $member) => $member->user !== null)
                ->map(fn (BoardMember $member) => [
                    'id' => $member->id,
                    'name' => $member->user->name,
                    'is_active' => (bool) $member->is_active,
                ])
                ->sortBy('name')
                ->values()
                ->all(),
            'committees' => BoardCommittee::query()->orderBy('name')->get(['id', 'name', 'committee_type'])
                ->map(fn (BoardCommittee $committee) => [
                    'id' => $committee->id,
                    'name' => $committee->name,
                    'committee_type' => $committee->committee_type,
                ])
                ->all(),
            'can_schedule_executive' => $this->executiveAccess->hasExecutiveAuthority($viewer),
        ];
    }

    public function calendar(Request $request)
    {
        $validated = $request->validate([
            'month' => 'nullable|date_format:Y-m',
            'date' => 'nullable|date_format:Y-m-d',
            'meeting_type' => 'nullable|in:all,full_board,audit_risk,people,finance,special_general,executive_session',
        ]);

        $month = isset($validated['month'])
            ? Carbon::createFromFormat('Y-m', $validated['month'])->startOfMonth()
            : now()->startOfMonth();

        $viewStart = $month->copy()->startOfMonth()->startOfWeek(Carbon::MONDAY);
        $viewEnd = $month->copy()->endOfMonth()->endOfWeek(Carbon::SUNDAY);

        $meetingType = $validated['meeting_type'] ?? 'all';

        $query = GovernanceMeeting::query()
            ->with(['chair.user', 'secretary.user'])
            ->whereBetween('scheduled_at', [$viewStart, $viewEnd]);

        $query = $this->executiveAccess->applyMeetingVisibilityScope($query, $request->user());

        if ($meetingType !== 'all') {
            $query->where('meeting_type', $meetingType);
        }

        $meetings = $query
            ->orderBy('scheduled_at')
            ->get()
            ->map(fn (GovernanceMeeting $meeting) => [
                'id' => $meeting->id,
                'title' => $meeting->title,
                'meeting_type' => $meeting->meeting_type,
                'scheduled_at' => $meeting->scheduled_at?->toIso8601String(),
                'duration_minutes' => $meeting->duration_minutes,
                'location' => $meeting->location,
                'status' => $meeting->status,
                'quorum_met' => $meeting->quorum_met,
                'chair' => $meeting->chair?->user ? [
                    'name' => $meeting->chair->user->name,
                ] : null,
                'secretary' => $meeting->secretary?->user ? [
                    'name' => $meeting->secretary->user->name,
                ] : null,
            ])
            ->values();

        $canCreate = $this->canScheduleMeetings($request->user());

        $selectedDate = $validated['date'] ?? null;
        if ($selectedDate === null) {
            $today = now()->toDateString();
            $selectedDate = ($today >= $viewStart->toDateString() && $today <= $viewEnd->toDateString())
                ? $today
                : $month->toDateString();
        }

        return Inertia::render('Governance/Meetings/Calendar', [
            'month' => $month->format('Y-m'),
            'monthLabel' => $month->format('F Y'),
            'previousMonth' => $month->copy()->subMonth()->format('Y-m'),
            'nextMonth' => $month->copy()->addMonth()->format('Y-m'),
            'selectedDate' => $selectedDate,
            'selectedMeetingType' => $meetingType,
            'meetingTypes' => [
                ['value' => 'all', 'label' => 'All Types'],
                ['value' => 'full_board', 'label' => 'Full Board'],
                ['value' => 'audit_risk', 'label' => 'Audit & Risk'],
                ['value' => 'people', 'label' => 'People Committee'],
                ['value' => 'finance', 'label' => 'Finance Committee'],
                ['value' => 'special_general', 'label' => 'Special General'],
                ['value' => 'executive_session', 'label' => 'Executive Session'],
            ],
            'meetings' => $meetings,
            // The calendar's create seed opens the same scheduling wizard in place.
            'canCreate' => $canCreate,
            'formOptions' => $canCreate ? $this->meetingFormOptions($request->user()) : null,
        ]);
    }

    public function show(Request $request, GovernanceMeeting $meeting)
    {
        $this->authorize('view', $meeting);

        $meeting->load([
            'chair.user',
            'secretary.user',
            'agendaItems.presenter',
            'attendances.boardMember.user',
            'rsvps.boardMember.user',
            'ceoReport.submittedBy',
            'minutes.draftedBy',
            'minutes.reviewedBy.user',
            'minutes.signedBy.user',
            'boardPack',
            'resolutions.proposedBy',
            'resolutions.votes.boardMember.user',
            'resolutions.conflictDeclarations.boardMember.user',
            'resolutions.committee',
            'resolutions.actionItems.assignedTo:id,name',
        ]);

        $viewer = $request->user();

        // Scope confidential agenda items for viewers without executive access.
        // The readiness checklist derives its counts from the same contract.
        $meeting->setRelation('agendaItems', $this->executiveAccess->visibleAgendaItems($viewer, $meeting));

        $visiblePack = $this->boardPackAccess->visiblePack($viewer, $meeting->boardPack);
        $meeting->setRelation('boardPack', $visiblePack);

        // Scope resolutions for viewer
        $visibleResolutions = $meeting->resolutions->filter(
            fn (Resolution $r) => $this->recordAccess->canViewResolution($viewer, $r)
        )->values();
        $meeting->setRelation('resolutions', $visibleResolutions);

        $viewerBoardMember = $viewer->boardMember;
        // Declaring a conflict needs an active board seat (the declare route
        // refuses anyone else) plus the vote permission.
        $viewerCanDeclare = $viewer->canDo('governance.resolutions.vote')
            && BoardMember::active()->where('user_id', $viewer->id)->exists();
        $votingProfiles = app(GovernanceVotingProfileService::class);
        $votingSwitchedOn = [];

        $enrichedResolutions = $visibleResolutions->map(function (Resolution $r) use ($viewer, $viewerBoardMember, $meeting, $viewerCanDeclare, $votingProfiles, &$votingSwitchedOn) {
            $myVote = $viewerBoardMember ? $r->getBoardMemberVote($viewerBoardMember->id) : null;
            // Integrity hashes and the legacy conflict note are internal.
            $myVote?->makeHidden(['vote_hash', 'conflict_note']);
            $r->votes->each(fn ($vote) => $vote->makeHidden(['vote_hash', 'conflict_note']));
            $myConflict = $viewerBoardMember
                ? $r->conflictDeclarations->firstWhere('board_member_id', $viewerBoardMember->id)
                : null;
            $results = in_array($r->status, ['closed', 'implemented', 'archived'], true)
                ? $this->votingService->getVotingResults($r)
                : null;
            $canVote = $viewer->can('vote', $r) && (! $myConflict || ! $myConflict->withdrew_from_voting);

            $committeeId = $r->board_committee_id ?? $meeting->board_committee_id;
            $rulesKey = $committeeId ? "committee:{$committeeId}" : 'board';
            $votingSwitchedOn[$rulesKey] ??= $votingProfiles->votingIsSwitchedOn(
                $committeeId ? 'committee' : 'board',
                $committeeId ? (int) $committeeId : null,
            );

            // The stored attachment rows and the frozen snapshot's copy of them
            // carry storage paths; hide both from every serialisation of this
            // model (including `meeting.resolutions`) and send presented copies.
            $r->makeHidden(['attachments', 'paper_snapshot']);

            $arr = $r->toArray();
            $arr['paper_snapshot'] = $r->presentPaperSnapshot();
            $arr['my_vote'] = $myVote;
            $arr['my_conflict'] = $myConflict;
            $arr['can_vote'] = $canVote;
            $arr['can_declare_conflict'] = $viewerCanDeclare
                && in_array($r->status, ['draft', 'proposed', 'open'], true);
            $arr['ineligible_reason'] = $r->isOpen() ? $this->votingService->ineligibleReason($r, $viewer) : null;
            $arr['applied_threshold'] = $r->appliedThreshold();
            $arr['voting_rules_switched_on'] = $votingSwitchedOn[$rulesKey];
            $arr['can_manage'] = $viewer->can('update', $r);
            $arr['results'] = $results;
            $arr['quorum'] = $this->votingService->calculateQuorum($meeting->id, $r);

            // Explicit, audience-filtered follow-up and document payloads — never
            // the raw relation/JSON columns (hidden action titles, storage paths).
            $visibleActions = $r->actionItems
                ->filter(fn (ActionItem $action) => $viewer->can('view', $action))
                ->sortBy(fn (ActionItem $action) => $action->due_date?->timestamp ?? PHP_INT_MAX)
                ->values();
            $arr['action_items'] = $visibleActions->map(fn (ActionItem $action) => $this->presentPaperAction($viewer, $action))->all();
            $arr['restricted_action_items_count'] = $r->actionItems->count() - $visibleActions->count();
            $arr['attachments'] = $r->presentAttachments(
                $viewer->canDo('governance.resolutions.view') && $viewer->can('view', $r)
            );

            return $arr;
        })->values();

        // The enriched list above is the only resolution payload the page reads;
        // strip the raw follow-up relation and attachment JSON from the nested
        // meeting copy so neither can leak through `meeting.resolutions`.
        $visibleResolutions->each(function (Resolution $r) {
            $r->unsetRelation('actionItems');
            $r->makeHidden('attachments');
        });

        $quorum = $meeting->calculateQuorum();
        $boardMembers = BoardMember::with('user')->active()->get();
        $workflowChecklist = $this->workflowService->meetingChecklist($meeting, $viewer);
        $meetingCockpit = $this->presenter->meetingCockpit($meeting, $quorum, $workflowChecklist, $viewer);

        // The meeting payload needs only a linkable pack summary, never the raw model fields.
        $visiblePack?->setVisible(['id', 'distributed_at']);

        $viewerCanRsvp = $viewerBoardMember !== null && $meeting->isInvited($viewerBoardMember);
        $viewerRsvp = $viewerCanRsvp ? $meeting->rsvps->firstWhere('board_member_id', $viewerBoardMember->id) : null;

        $canEdit = $meeting->isEditable() && $viewer->can('update', $meeting);

        // The in-meeting decision-paper wizard's options go only to paper
        // authors (the same gate as the Resolutions register) — never the
        // whole user directory to every attendee.
        $canAuthorPapers = $viewer->canDo('governance.resolutions.manage')
            && $viewer->can('create', Resolution::class);
        $paperAuthoring = $canAuthorPapers
            ? [
                'users' => User::query()
                    ->whereNotNull('approved_at')
                    ->orderBy('name')
                    ->get(['id', 'name']),
                'committees' => \App\Domain\Governance\Models\BoardCommittee::query()
                    ->orderBy('name')
                    ->get(['id', 'name']),
                'canPublishPapers' => $viewer->can('openVoting', new Resolution),
                ...app(\App\Domain\Governance\Services\GovernanceResolutionAuthorityService::class)->authoringChoices($viewer),
            ]
            : [
                'users' => [],
                'committees' => [],
                'canPublishPapers' => false,
                'authoritySubjects' => null,
                'authoritySubjectGroups' => [],
            ];

        return Inertia::render('Governance/Meetings/Show', [
            'meeting' => $meeting,
            'resolutions' => $enrichedResolutions,
            ...$paperAuthoring,
            'quorum' => $quorum,
            'boardMembers' => $boardMembers,
            'canEdit' => $canEdit,
            // Edit wizard options — only for viewers the update route accepts.
            'formOptions' => $canEdit && $viewer->canDo('governance.meetings.manage')
                ? $this->meetingFormOptions($viewer)
                : null,
            'canManageMinutes' => $viewer->can('manageMinutes', $meeting),
            'canApproveMinutes' => $viewer->can('approveMinutes', $meeting),
            'canSignMinutes' => $viewer->can('signMinutes', $meeting),
            'workflowChecklist' => $workflowChecklist,
            'meetingCockpit' => $meetingCockpit,
            'viewerCanRsvp' => $viewerCanRsvp,
            'viewerRsvp' => $viewerRsvp,
        ]);
    }

    /**
     * Typed follow-up action row for the inline paper workspace. The caller
     * has already confirmed the viewer may see the action; the open link is
     * only issued when the viewer can also pass the action route's permission
     * gate, so the page never renders a control that would 403.
     *
     * @return array{id: int, reference: string, title: string, status: string, priority: ?string, due_date: ?string, due_label: ?string, assignee_name: ?string, is_mine: bool, can_open: bool, open_url: ?string}
     */
    protected function presentPaperAction(User $viewer, ActionItem $action): array
    {
        $canOpen = $viewer->canDo('governance.actions.view');

        return [
            'id' => $action->id,
            'reference' => $action->action_reference ?? "ACT-{$action->id}",
            'title' => $action->title,
            'status' => $action->status,
            'priority' => $action->priority,
            'due_date' => $action->due_date?->toDateString(),
            'due_label' => $action->due_date?->format('j M Y'),
            'assignee_name' => $action->assignedTo?->name,
            'is_mine' => (int) $action->assigned_to === (int) $viewer->id,
            'can_open' => $canOpen,
            'open_url' => $canOpen ? route('governance.actions.show', $action, false) : null,
        ];
    }

    /** Legacy deep link: the edit wizard is a dialog on the meeting workspace. */
    public function edit(GovernanceMeeting $meeting)
    {
        $this->authorize('update', $meeting);

        return redirect()->route('governance.meetings.show', ['meeting' => $meeting->id, 'edit' => 1]);
    }

    public function store(StoreMeetingRequest $request)
    {
        $validated = $request->validated();
        if (($validated['meeting_type'] ?? null) === 'executive_session') {
            abort_unless($this->executiveAccess->hasExecutiveAuthority($request->user()), 403);
        }

        $meeting = GovernanceMeeting::create([
            ...$validated,
            'created_by' => auth()->id(),
        ]);

        return redirect()->route('governance.meetings.show', $meeting)
            ->with('success', 'Meeting scheduled successfully.');
    }

    public function update(UpdateMeetingRequest $request, GovernanceMeeting $meeting)
    {
        $this->authorize('update', $meeting);

        $validated = $request->validated();
        if (($validated['meeting_type'] ?? null) === 'executive_session' && ! $meeting->isExecutiveSession()) {
            abort_unless($this->executiveAccess->hasExecutiveAuthority($request->user()), 403);
        }

        $meeting->update($validated);

        return redirect()->route('governance.meetings.show', $meeting)
            ->with('success', 'Meeting updated successfully.');
    }

    public function destroy(GovernanceMeeting $meeting)
    {
        $this->authorize('delete', $meeting);

        $meeting->delete();

        return redirect()->route('governance.meetings.index')
            ->with('success', 'Meeting cancelled.');
    }

    public function addAgendaItem(Request $request, GovernanceMeeting $meeting)
    {
        $this->authorize('update', $meeting);

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'presenter_id' => 'nullable|exists:users,id',
            'duration_minutes' => 'required|integer|min:5|max:120',
            'item_type' => 'required|in:standard,decision,consent,for_info',
            'is_confidential' => 'boolean',
        ]);

        if (! empty($validated['is_confidential'])) {
            abort_unless($this->executiveAccess->canManageConfidentialAgenda($request->user(), $meeting), 403);
        }

        $this->nestedMutations->addAgendaItem($request->user(), $meeting, $validated);

        return redirect()->back()->with('success', 'Agenda item added.');
    }

    public function updateAgendaItem(Request $request, GovernanceMeeting $meeting, MeetingAgendaItem $item)
    {
        $this->authorize('update', $meeting);

        if ($item->is_confidential) {
            abort_unless($this->executiveAccess->canManageConfidentialAgenda($request->user(), $meeting), 403);
        }

        $this->nestedMutations->assertAgendaItemBound($request->user(), $meeting, $item);

        $validated = $request->validate([
            'title' => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'presenter_id' => 'nullable|exists:users,id',
            'duration_minutes' => 'sometimes|integer|min:5|max:120',
            'order' => 'sometimes|integer|min:1',
            'is_confidential' => 'sometimes|boolean',
        ]);

        if (! empty($validated['is_confidential'])) {
            abort_unless($this->executiveAccess->canManageConfidentialAgenda($request->user(), $meeting), 403);
        }

        $this->nestedMutations->updateAgendaItem($request->user(), $meeting, $item, $validated);

        return redirect()->back()->with('success', 'Agenda item updated.');
    }

    public function removeAgendaItem(Request $request, GovernanceMeeting $meeting, MeetingAgendaItem $item)
    {
        $this->authorize('update', $meeting);

        if ($item->is_confidential) {
            abort_unless($this->executiveAccess->canManageConfidentialAgenda($request->user(), $meeting), 403);
        }

        $this->nestedMutations->removeAgendaItem($request->user(), $meeting, $item);

        return redirect()->back()->with('success', 'Agenda item removed.');
    }

    public function storeMinutes(Request $request, GovernanceMeeting $meeting)
    {
        $this->authorize('manageMinutes', $meeting);

        $validated = $request->validate([
            'content_blocks' => 'nullable|array',
        ]);

        try {
            $this->minuteService->storeMinutes($meeting, $validated['content_blocks'] ?? null, $request->user());

            return redirect()->back()->with('success', 'Minutes drafted.');
        } catch (DomainException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }
    }

    public function updateMinutes(Request $request, GovernanceMeeting $meeting)
    {
        $this->authorize('manageMinutes', $meeting);

        if (! $meeting->minutes) {
            return redirect()->back()->with('error', 'Minutes have not been created for this meeting yet.');
        }

        if (! $meeting->minutes->canEdit()) {
            if ($request->wantsJson()) {
                return response()->json(['error' => "Minutes in status '{$meeting->minutes->status}' cannot be edited in place. Approved and signed minutes are immutable."], 422);
            }

            return redirect()->back()->with('error', "Minutes in status '{$meeting->minutes->status}' cannot be edited in place. Approved and signed minutes are immutable. Create a correction draft to propose revisions.");
        }

        $validated = $request->validate([
            'content_blocks' => 'required|array',
            'expected_version' => 'nullable|integer',
        ]);

        try {
            $this->minuteService->updateMinutes(
                $meeting,
                $validated['content_blocks'],
                $request->user(),
                $validated['expected_version'] ?? null
            );

            return redirect()->back()->with('success', 'Minutes updated.');
        } catch (DomainException $e) {
            if ($request->wantsJson()) {
                $status = str_contains($e->getMessage(), 'Stale edit') ? 409 : 422;

                return response()->json(['error' => $e->getMessage()], $status);
            }

            return redirect()->back()->with('error', $e->getMessage());
        }
    }

    public function submitMinutesForReview(Request $request, GovernanceMeeting $meeting)
    {
        $this->authorize('manageMinutes', $meeting);

        try {
            $this->minuteService->submitForReview($meeting, $request->user());

            return redirect()->back()->with('success', 'Minutes submitted for review.');
        } catch (DomainException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }
    }

    public function approveMinutes(Request $request, GovernanceMeeting $meeting)
    {
        $this->authorize('approveMinutes', $meeting);

        $validated = $request->validate([
            'notes' => 'nullable|string',
            'expected_version' => 'required|integer',
            'expected_hash' => 'nullable|string',
        ]);

        try {
            $this->minuteService->approveMinutes(
                $meeting,
                $request->user(),
                $validated['notes'] ?? null,
                (int) $validated['expected_version'],
                $validated['expected_hash'] ?? null
            );

            return redirect()->back()->with('success', 'Minutes approved.');
        } catch (DomainException $e) {
            $status = str_contains($e->getMessage(), 'conflict') ? 409 : 422;
            if ($request->wantsJson()) {
                return response()->json(['error' => $e->getMessage()], $status);
            }

            return redirect()->back()->with('error', $e->getMessage());
        }
    }

    public function recordAttendance(Request $request, GovernanceMeeting $meeting)
    {
        $this->authorize('update', $meeting);

        $validated = $request->validate([
            'attendance' => 'required|array',
            'attendance.*.board_member_id' => 'required|exists:board_members,id',
            'attendance.*.status' => 'required|in:present,apology,no_show,late,unrecorded',
            'attendance.*.apology_reason' => 'nullable|string',
        ]);

        foreach ($validated['attendance'] as $record) {
            if ($record['status'] === 'unrecorded') {
                MeetingAttendance::where('governance_meeting_id', $meeting->id)
                    ->where('board_member_id', $record['board_member_id'])
                    ->delete();

                continue;
            }

            MeetingAttendance::updateOrCreate(
                [
                    'governance_meeting_id' => $meeting->id,
                    'board_member_id' => $record['board_member_id'],
                ],
                [
                    'status' => $record['status'],
                    'apology_reason' => $record['apology_reason'] ?? null,
                    'marked_at' => now(),
                    'marked_by' => auth()->id(),
                ]
            );
        }

        return redirect()->back()->with('success', 'Attendance recorded.');
    }

    public function lockMeeting(Request $request, GovernanceMeeting $meeting)
    {
        $this->authorize('lock', $meeting);

        $meeting->update([
            'status' => 'locked',
            'locked_at' => now(),
            'locked_by' => $request->user()->id,
        ]);

        return redirect()->back()->with('success', 'Meeting locked. No further edits allowed.');
    }

    public function signMinutes(Request $request, GovernanceMeeting $meeting)
    {
        $this->authorize('signMinutes', $meeting);

        $validated = $request->validate([
            'expected_version' => 'required|integer',
            'expected_hash' => 'nullable|string',
        ]);

        try {
            $this->minuteService->signMinutes(
                $meeting,
                $request->user(),
                (int) $validated['expected_version'],
                $validated['expected_hash'] ?? null
            );

            return redirect()->back()->with('success', 'Minutes signed successfully.');
        } catch (DomainException $e) {
            $status = str_contains($e->getMessage(), 'conflict') ? 409 : 422;
            if ($request->wantsJson()) {
                return response()->json(['error' => $e->getMessage()], $status);
            }

            return redirect()->back()->with('error', $e->getMessage());
        }
    }

    public function archiveMinutes(Request $request, GovernanceMeeting $meeting)
    {
        $this->authorize('archiveMinutes', $meeting);

        try {
            $this->minuteService->archiveMinutes($meeting, $request->user());

            return redirect()->back()->with('success', 'Minutes archived.');
        } catch (DomainException $e) {
            if ($request->wantsJson()) {
                return response()->json(['error' => $e->getMessage()], 422);
            }

            return redirect()->back()->with('error', $e->getMessage());
        }
    }

    public function createMinutesCorrection(Request $request, GovernanceMeeting $meeting)
    {
        $this->authorize('manageMinutes', $meeting);

        $validated = $request->validate([
            'reason' => 'required|string|min:5|max:1000',
        ]);

        try {
            $this->minuteService->createCorrection($meeting, $request->user(), $validated['reason']);

            return redirect()->back()->with('success', 'Correction draft created. Prior approved version is preserved in version history.');
        } catch (DomainException $e) {
            if ($request->wantsJson()) {
                return response()->json(['error' => $e->getMessage()], 422);
            }

            return redirect()->back()->with('error', $e->getMessage());
        }
    }

    public function advanceStatus(GovernanceMeeting $meeting)
    {
        $this->authorize('update', $meeting);

        $advanced = $meeting->autoAdvanceStatus();

        if (! $advanced) {
            return redirect()->back()->with('error', 'Cannot advance meeting status. Check prerequisites.');
        }

        return redirect()->back()->with('success', 'Meeting status advanced to: '.str_replace('_', ' ', $meeting->fresh()->status));
    }

    public function submitRsvp(Request $request, GovernanceMeeting $meeting)
    {
        $this->authorize('view', $meeting);

        $validated = $request->validate([
            'response' => 'nullable|in:accepted,declined,tentative,attending,apology,unsure',
            'status' => 'nullable|in:accepted,declined,tentative,attending,apology,unsure',
            'decline_reason' => 'nullable|string|max:500',
            'notes' => 'nullable|string|max:500',
            'dietary_requirements' => 'nullable|boolean',
            'dietary_notes' => 'nullable|string|max:255',
        ]);

        $viewer = $request->user();
        $boardMember = $viewer->boardMember;
        if (! $boardMember) {
            abort(403, 'You are not a registered board member.');
        }

        if (! $meeting->isInvited($boardMember)) {
            abort(403, 'You are not an invited member of this committee meeting.');
        }

        $rawResponse = $validated['response'] ?? $validated['status'] ?? 'accepted';
        $normalizedResponse = match ($rawResponse) {
            'attending', 'accepted' => 'accepted',
            'apology', 'declined' => 'declined',
            'unsure', 'tentative' => 'tentative',
            default => 'accepted',
        };

        $declineReason = $validated['decline_reason'] ?? ($normalizedResponse === 'declined' ? ($validated['notes'] ?? null) : null);
        $dietaryNotes = $validated['dietary_notes'] ?? ($normalizedResponse !== 'declined' ? ($validated['notes'] ?? null) : null);
        $hasDietary = ! empty($validated['dietary_requirements']) || ! empty($dietaryNotes);

        $rsvp = MeetingRsvp::updateOrCreate(
            [
                'governance_meeting_id' => $meeting->id,
                'board_member_id' => $boardMember->id,
            ],
            [
                'response' => $normalizedResponse,
                'decline_reason' => $declineReason,
                'dietary_requirements' => $hasDietary,
                'dietary_notes' => $dietaryNotes,
                'responded_at' => now(),
            ]
        );

        $receiptId = sprintf('RSVP-%d-%d-%s', $meeting->id, $boardMember->id, now()->format('YmdHis'));

        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'message' => 'RSVP recorded.',
                'receipt_id' => $receiptId,
                'rsvp' => $rsvp->fresh()->load('boardMember.user'),
            ]);
        }

        return redirect()->back()->with('success', 'RSVP recorded.')->with('receipt_id', $receiptId);
    }
}
