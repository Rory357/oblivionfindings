<?php

namespace App\Domain\Governance\Http\Controllers;

use App\Domain\Governance\Exceptions\MinutesChangedByOthers;
use App\Domain\Governance\Http\Requests\StoreMeetingRequest;
use App\Domain\Governance\Http\Requests\UpdateMeetingRequest;
use App\Domain\Governance\Models\ActionItem;
use App\Domain\Governance\Models\BoardCommittee;
use App\Domain\Governance\Models\BoardMember;
use App\Domain\Governance\Models\GovernanceMeeting;
use App\Domain\Governance\Models\MeetingAgendaItem;
use App\Domain\Governance\Models\MeetingAttendance;
use App\Domain\Governance\Models\MeetingMinute;
use App\Domain\Governance\Models\MeetingRsvp;
use App\Domain\Governance\Models\Resolution;
use App\Domain\Governance\Services\BoardPackAccessService;
use App\Domain\Governance\Services\ExecutiveMeetingAccessService;
use App\Domain\Governance\Services\GovernanceCalendarQuery;
use App\Domain\Governance\Services\GovernanceNestedMutationService;
use App\Domain\Governance\Services\GovernanceRecordAccessService;
use App\Domain\Governance\Services\GovernanceVotingProfileService;
use App\Domain\Governance\Services\GovernanceWorkflowService;
use App\Domain\Governance\Services\MeetingMinuteService;
use App\Domain\Governance\Services\VotingService;
use App\Domain\Governance\Support\GovernanceLabels;
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

    /**
     * Meeting statuses the register can filter by — every stage a meeting
     * can reach, including a board pack being prepared and cancellation.
     */
    private const MEETING_STATUSES = [
        'scheduled', 'agenda_draft', 'agenda_final', 'pack_draft', 'in_progress', 'minutes_draft',
        'minutes_review', 'minutes_approved', 'minutes_signed', 'archived', 'cancelled',
    ];

    /** Plain agenda item validation messages (vocabulary.md). */
    private const AGENDA_MESSAGES = [
        'title.required' => 'Give the agenda item a title.',
        'title.max' => 'Keep the title under 255 characters.',
        'presenter_id.exists' => 'Choose the presenter from the list.',
        'duration_minutes.required' => 'Say how long this item will take, between 5 and 120 minutes.',
        'duration_minutes.integer' => 'Enter the time for this item in whole minutes.',
        'duration_minutes.min' => 'An agenda item needs at least 5 minutes.',
        'duration_minutes.max' => 'An agenda item can take at most 120 minutes.',
        'item_type.required' => 'Choose what kind of agenda item this is.',
        'item_type.in' => 'Choose what kind of agenda item this is.',
        'order.min' => 'Choose a position on the agenda.',
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
            'meeting_type' => 'nullable|in:'.implode(',', StoreMeetingRequest::MEETING_TYPES),
            'from' => 'nullable|date_format:Y-m-d',
            'to' => 'nullable|date_format:Y-m-d',
            'search' => 'nullable|string|max:120',
            'date' => 'nullable|date_format:Y-m-d',
            'hour' => 'nullable|integer|min:0|max:23',
        ], [
            'status.in' => 'Choose a status from the list.',
            'meeting_type.in' => 'Choose a type of meeting from the list.',
            'from.date_format' => 'Enter the start of the date range as a date.',
            'to.date_format' => 'Enter the end of the date range as a date.',
            'search.max' => 'Keep the search under 120 characters.',
        ]);

        $viewer = $request->user();
        $zone = config('app.worker_timezone', 'Pacific/Auckland');
        $visible = fn () => $this->executiveAccess->applyMeetingVisibilityScope(GovernanceMeeting::query(), $viewer);

        $query = $this->executiveAccess->applyMeetingVisibilityScope(
            GovernanceMeeting::with(['chair.user', 'secretary.user', 'committee:id,name'])->withCount('attendances'),
            $viewer
        );

        $status = $validated['status'] ?? null;
        if ($status === 'upcoming') {
            $query->where('scheduled_at', '>=', now())->whereNotIn('status', ['archived', 'cancelled']);
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
        $meetings = $query->paginate(15)
            ->appends(collect($request->query())->except(['create', 'date', 'hour', 'page'])->all())
            ->through(function (GovernanceMeeting $meeting) use ($viewer) {
                // Row menus only offer the meeting checklist to people who run the meeting.
                $meeting->setAttribute('can_run', $this->canRunMeeting($viewer, $meeting));
                $meeting->setAttribute('quorum_state', $this->quorumState($meeting));

                return $meeting;
            });

        $nextMeeting = $visible()
            ->where('scheduled_at', '>=', now())
            ->whereNotIn('status', ['archived', 'cancelled'])
            ->orderBy('scheduled_at')
            ->first(['id', 'title', 'scheduled_at']);

        $heldQuery = $visible()->where('scheduled_at', '<', now())->where('status', '!=', 'cancelled');
        $summary = [
            'total' => $visible()->count(),
            'upcoming' => $visible()->where('scheduled_at', '>=', now())->whereNotIn('status', ['archived', 'cancelled'])->count(),
            'minutes_pending' => $visible()->whereIn('status', ['minutes_draft', 'minutes_review'])->count(),
            'held' => (clone $heldQuery)->count(),
            // Held meetings whose quorum is known: attendance was recorded (or quorum was confirmed).
            'held_recorded' => (clone $heldQuery)->where(fn ($q) => $q->where('quorum_met', true)->orWhereHas('attendances'))->count(),
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
            'meetingTypes' => $this->meetingTypeLabels(),
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
     * Whether the viewer runs this meeting — edits it, records attendance or
     * handles its minutes — through the same gates as those routes.
     */
    protected function canRunMeeting(User $viewer, GovernanceMeeting $meeting): bool
    {
        if (! $viewer->canDo('governance.meetings.manage')) {
            return false;
        }

        return ($meeting->isEditable() && $viewer->can('update', $meeting))
            || $viewer->can('manageMinutes', $meeting)
            || $viewer->can('approveMinutes', $meeting)
            || $viewer->can('signMinutes', $meeting);
    }

    /**
     * What the register's Quorum column can truthfully say: nothing before a
     * meeting (or for a cancelled one), "Not recorded" when nobody recorded
     * attendance, otherwise whether enough members were present.
     */
    protected function quorumState(GovernanceMeeting $meeting): string
    {
        return match (true) {
            $meeting->status === 'cancelled' => 'cancelled',
            $meeting->scheduled_at === null || $meeting->scheduled_at->isFuture() => 'upcoming',
            (bool) $meeting->quorum_met => 'met',
            (int) ($meeting->attendances_count ?? $meeting->attendances()->count()) === 0 => 'not_recorded',
            default => 'not_met',
        };
    }

    /** @return array<string, string> meeting type → plain label, in wizard order */
    protected function meetingTypeLabels(): array
    {
        return collect(StoreMeetingRequest::MEETING_TYPES)
            ->mapWithKeys(fn (string $type) => [$type => GovernanceLabels::label('meeting_type', $type)])
            ->all();
    }

    /**
     * Select options for the meeting wizard (the retired Create/Edit pages'
     * props). Executive sessions are only offered to viewers the store/update
     * guard would accept. Quorum data lets the wizard say how many members a
     * percentage means today, exactly as GovernanceMeeting::calculateQuorum
     * counts them.
     *
     * @return array{board_members: array<int, array{id: int, name: string, is_active: bool, counts_for_quorum: bool}>, committees: array<int, array{id: int, name: string, committee_type: ?string, member_ids: array<int, int>}>, can_schedule_executive: bool}
     */
    protected function meetingFormOptions(User $viewer): array
    {
        $quorumMemberIds = BoardMember::query()->active()->pluck('id')->map(fn ($id) => (int) $id)->all();

        return [
            'board_members' => BoardMember::with('user:id,name')->get()
                ->filter(fn (BoardMember $member) => $member->user !== null)
                ->map(fn (BoardMember $member) => [
                    'id' => $member->id,
                    'name' => $member->user->name,
                    'is_active' => (bool) $member->is_active,
                    'counts_for_quorum' => in_array((int) $member->id, $quorumMemberIds, true),
                ])
                ->sortBy('name')
                ->values()
                ->all(),
            'committees' => BoardCommittee::query()
                ->with(['memberships' => fn ($query) => $query->where('is_active', true)])
                ->orderBy('name')
                ->get(['id', 'name', 'committee_type'])
                ->map(fn (BoardCommittee $committee) => [
                    'id' => $committee->id,
                    'name' => $committee->name,
                    'committee_type' => $committee->committee_type,
                    'member_ids' => $committee->memberships
                        ->pluck('board_member_id')
                        ->map(fn ($id) => (int) $id)
                        ->unique()
                        ->values()
                        ->all(),
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
            'meeting_type' => 'nullable|in:all,'.implode(',', StoreMeetingRequest::MEETING_TYPES),
        ], [
            'month.date_format' => 'Choose a month from the calendar.',
            'date.date_format' => 'Choose a day from the calendar.',
            'meeting_type.in' => 'Choose a type of meeting from the list.',
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
                ['value' => 'all', 'label' => 'All types'],
                ...collect($this->meetingTypeLabels())
                    ->map(fn (string $label, string $type) => ['value' => $type, 'label' => $label])
                    ->values()
                    ->all(),
            ],
            'meetings' => $meetings,
            // Only the calendar sources whose registers this viewer can open.
            'calendarSources' => app(GovernanceCalendarQuery::class)->sourcesFor($request->user()),
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

        // Page controls pass the same gates as the routes they call, so the
        // workspace never offers a button the server would refuse.
        $canManageRoutes = $viewer->canDo('governance.meetings.manage');
        $canEdit = $canManageRoutes && $meeting->isEditable() && $viewer->can('update', $meeting);
        $canManageMinutes = $canManageRoutes && $viewer->can('manageMinutes', $meeting);
        $canApproveMinutes = $canManageRoutes && $viewer->can('approveMinutes', $meeting);
        $canSignMinutes = $canManageRoutes && $viewer->can('signMinutes', $meeting);
        $canRunMeeting = $canEdit || $canManageMinutes || $canApproveMinutes || $canSignMinutes;

        // The preparation checklist and readiness summary are the chair and
        // secretary's work. Members get meters about their own preparation
        // instead, so the admin detail never reaches their payload.
        $workflowChecklist = $canRunMeeting
            ? $this->workflowService->meetingChecklist($meeting, $viewer)
            : ['counts' => ['done' => 0, 'remaining' => 0, 'blocked' => 0], 'next_step' => null, 'items' => []];
        $meetingCockpit = $canRunMeeting
            ? $this->presenter->meetingCockpit($meeting, $quorum, $workflowChecklist, $viewer)
            : ['cards' => [], 'next_step' => null];
        // The CEO report's status lives in the readiness summary; the page never reads the raw record.
        $meeting->unsetRelation('ceoReport');

        // The member's own reading receipt for the pack they were sent.
        $packRecipientId = $visiblePack ? $this->boardPackAccess->recipientBoardMemberId($viewer, $visiblePack) : null;
        $packReceipt = $packRecipientId !== null ? $visiblePack->getMemberReceipt($packRecipientId) : null;
        $packReading = $visiblePack ? [
            'sent' => $visiblePack->distributed_at !== null,
            'is_recipient' => $packRecipientId !== null,
            'read' => $packReceipt !== null,
            'read_at' => $packReceipt['read_at'] ?? null,
            'version' => (int) ($visiblePack->revision_number ?? 1),
        ] : null;

        // The meeting payload needs only a linkable pack summary, never the raw model fields.
        $visiblePack?->setVisible(['id', 'distributed_at']);

        $this->presentPeople($meeting, $canRunMeeting, $viewerBoardMember?->id);

        $canViewRecordDetails = $viewer->canDo('governance.audit.view');
        if ($meeting->minutes instanceof MeetingMinute) {
            $this->presentMinutes($meeting->minutes, $canViewRecordDetails, $canApproveMinutes || $canSignMinutes);
        }

        // The attendance dialog and agenda presenter picker are the only readers.
        $boardMembers = $canEdit
            ? BoardMember::with('user:id,name')->active()->get()
                ->filter(fn (BoardMember $member) => $member->user !== null)
                ->map(fn (BoardMember $member) => [
                    'id' => $member->id,
                    'user' => ['id' => $member->user->id, 'name' => $member->user->name],
                ])
                ->values()
            : [];

        $viewerCanRsvp = $viewerBoardMember !== null && $meeting->isInvited($viewerBoardMember);
        $viewerRsvp = $viewerCanRsvp ? $meeting->rsvps->firstWhere('board_member_id', $viewerBoardMember->id) : null;

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
                'committees' => BoardCommittee::query()
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
            'formOptions' => $canEdit ? $this->meetingFormOptions($viewer) : null,
            'canManageMinutes' => $canManageMinutes,
            'canApproveMinutes' => $canApproveMinutes,
            'canSignMinutes' => $canSignMinutes,
            'canViewRecordDetails' => $canViewRecordDetails,
            'workflowChecklist' => $workflowChecklist,
            'meetingCockpit' => $meetingCockpit,
            'packReading' => $packReading,
            'viewerCanRsvp' => $viewerCanRsvp,
            'viewerRsvp' => $viewerRsvp ? $this->presentRsvp($viewerRsvp) : null,
            'committeeOversight' => $this->committeeOversight($meeting, $viewer),
        ]);
    }

    /**
     * A committee meeting links to that committee's risk view and report —
     * only for committees that oversee risks and viewers who can open them
     * (both pages check governance.risks.view on the server).
     *
     * @return array{name: string, risks_href: string, report_href: string}|null
     */
    protected function committeeOversight(GovernanceMeeting $meeting, User $viewer): ?array
    {
        if (! $meeting->board_committee_id || ! $viewer->canDo('governance.risks.view')) {
            return null;
        }

        $committee = collect(\App\Domain\Governance\Support\RiskCommitteeScope::committeeOptions())
            ->firstWhere('id', (int) $meeting->board_committee_id);

        if ($committee === null) {
            return null;
        }

        return [
            'name' => $committee['name'],
            'risks_href' => "/governance/risks/committee/{$committee['id']}",
            'report_href' => "/governance/reports/committee/{$committee['id']}",
        ];
    }

    /**
     * Names, not account records, for the people on the meeting. Apology
     * reasons and dietary or access needs are personal: the people running
     * the meeting see everyone's, members see only their own.
     */
    protected function presentPeople(GovernanceMeeting $meeting, bool $canRunMeeting, ?int $viewerBoardMemberId): void
    {
        $nameOnly = function (?BoardMember $member): void {
            $member?->setVisible(['id', 'user']);
            $member?->user?->setVisible(['id', 'name']);
        };

        $nameOnly($meeting->chair);
        $nameOnly($meeting->secretary);

        $meeting->rsvps->each(function (MeetingRsvp $rsvp) use ($nameOnly, $canRunMeeting, $viewerBoardMemberId) {
            $nameOnly($rsvp->boardMember);
            if (! $canRunMeeting && (int) $rsvp->board_member_id !== (int) $viewerBoardMemberId) {
                $rsvp->makeHidden(['decline_reason', 'dietary_requirements', 'dietary_notes']);
            }
        });

        $meeting->attendances->each(function (MeetingAttendance $attendance) use ($nameOnly, $canRunMeeting, $viewerBoardMemberId) {
            $nameOnly($attendance->boardMember);
            $attendance->makeHidden(['marked_by']);
            if (! $canRunMeeting && (int) $attendance->board_member_id !== (int) $viewerBoardMemberId) {
                $attendance->makeHidden(['apology_reason']);
            }
        });
    }

    /**
     * Minutes as the workspace reads them: names instead of account records,
     * a plain version history, and integrity codes only for people who check
     * the record (audit access) or who need them to approve or sign the exact
     * version they read.
     */
    protected function presentMinutes(MeetingMinute $minutes, bool $showRecordDetails, bool $needsVersionCheck): void
    {
        $history = collect($minutes->version_history ?? [])
            ->filter(fn ($entry) => is_array($entry))
            ->map(fn (array $entry) => array_filter([
                'version' => isset($entry['version']) ? (int) $entry['version'] : null,
                'event' => $entry['event'] ?? null,
                'status' => $entry['status'] ?? null,
                'at' => $entry['timestamp'] ?? $entry['superseded_at'] ?? $entry['archived_at']
                    ?? $entry['updated_at'] ?? $entry['created_at'] ?? null,
                'actor_name' => $entry['user_name'] ?? $entry['approver_user_name'] ?? $entry['signer_user_name']
                    ?? $entry['archived_by_user_name'] ?? $entry['correction_initiated_by_name']
                    ?? $entry['updated_by_name'] ?? $entry['created_by_name'] ?? null,
                'note' => $entry['note'] ?? null,
                'reason_for_correction' => $entry['reason_for_correction'] ?? null,
                'content_blocks' => $entry['content_blocks'] ?? null,
                'content_hash' => $showRecordDetails ? ($entry['content_hash'] ?? null) : null,
            ], fn ($value) => $value !== null))
            ->values()
            ->all();

        $minutes->setAttribute('version_history', $history);
        // Ids and their account records (relations are hidden by relation name).
        $minutes->makeHidden(['drafted_by', 'reviewed_by', 'signed_by', 'draftedBy', 'reviewedBy', 'signedBy']);

        if (! $showRecordDetails && ! $needsVersionCheck) {
            $minutes->makeHidden('content_hash');
        }
    }

    /**
     * The viewer's reply, with the reference the reply confirmation gives —
     * derived from the stored reply, never made up in the browser.
     *
     * @return array<string, mixed>
     */
    protected function presentRsvp(MeetingRsvp $rsvp): array
    {
        return [
            'id' => $rsvp->id,
            'board_member_id' => $rsvp->board_member_id,
            'response' => $rsvp->response,
            'decline_reason' => $rsvp->decline_reason,
            'dietary_requirements' => (bool) $rsvp->dietary_requirements,
            'dietary_notes' => $rsvp->dietary_notes,
            'responded_at' => $rsvp->responded_at?->toIso8601String(),
            'receipt_id' => $this->rsvpReceiptId($rsvp),
        ];
    }

    protected function rsvpReceiptId(MeetingRsvp $rsvp): ?string
    {
        return $rsvp->responded_at
            ? sprintf(
                'RSVP-%d-%d-%s',
                $rsvp->governance_meeting_id,
                $rsvp->board_member_id,
                $rsvp->responded_at->copy()->utc()->format('YmdHis'),
            )
            : null;
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
            ->with('success', 'Meeting scheduled.');
    }

    public function update(UpdateMeetingRequest $request, GovernanceMeeting $meeting)
    {
        $this->authorize('update', $meeting);

        $validated = $request->validated();
        if (($validated['meeting_type'] ?? null) === 'executive_session' && ! $meeting->isExecutiveSession()) {
            abort_unless($this->executiveAccess->hasExecutiveAuthority($request->user()), 403);
        }

        $cancelling = ($validated['status'] ?? null) === 'cancelled' && $meeting->status !== 'cancelled';

        $meeting->update($validated);

        return redirect()->route('governance.meetings.show', $meeting)
            ->with('success', $cancelling ? 'Meeting cancelled.' : 'Meeting details saved.');
    }

    public function destroy(GovernanceMeeting $meeting)
    {
        $this->authorize('delete', $meeting);

        $meeting->delete();

        return redirect()->route('governance.meetings.index')
            ->with('success', 'Meeting removed.');
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
        ], self::AGENDA_MESSAGES);

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
        ], self::AGENDA_MESSAGES);

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
        ], [
            'content_blocks.array' => 'Write the minutes under the headings provided.',
        ]);

        try {
            $this->minuteService->storeMinutes($meeting, $validated['content_blocks'] ?? null, $request->user());

            return redirect()->back()->with('success', 'Draft minutes saved.');
        } catch (DomainException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }
    }

    public function updateMinutes(Request $request, GovernanceMeeting $meeting)
    {
        $this->authorize('manageMinutes', $meeting);

        if (! $meeting->minutes) {
            return redirect()->back()->with('error', "The minutes for this meeting haven't been started yet.");
        }

        if (! $meeting->minutes->canEdit()) {
            $message = "Approved minutes can't be edited. The secretary can start a correction if something is wrong.";

            if ($request->wantsJson()) {
                return response()->json(['error' => $message], 422);
            }

            return redirect()->back()->with('error', $message);
        }

        $validated = $request->validate([
            'content_blocks' => 'required|array',
            'expected_version' => 'nullable|integer',
        ], [
            'content_blocks.required' => 'Write at least one section of the minutes.',
            'content_blocks.array' => 'Write the minutes under the headings provided.',
            'expected_version.integer' => 'Refresh the page and try again.',
        ]);

        try {
            $this->minuteService->updateMinutes(
                $meeting,
                $validated['content_blocks'],
                $request->user(),
                $validated['expected_version'] ?? null
            );

            return redirect()->back()->with('success', 'Minutes saved.');
        } catch (DomainException $e) {
            if ($request->wantsJson()) {
                return response()->json(['error' => $e->getMessage()], $e instanceof MinutesChangedByOthers ? 409 : 422);
            }

            return redirect()->back()->with('error', $e->getMessage());
        }
    }

    public function submitMinutesForReview(Request $request, GovernanceMeeting $meeting)
    {
        $this->authorize('manageMinutes', $meeting);

        try {
            $this->minuteService->submitForReview($meeting, $request->user());

            return redirect()->back()->with('success', 'Minutes sent to the chair for approval.');
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
        ], [
            'expected_version.required' => 'Refresh the page and try again.',
            'expected_version.integer' => 'Refresh the page and try again.',
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
            if ($request->wantsJson()) {
                return response()->json(['error' => $e->getMessage()], $e instanceof MinutesChangedByOthers ? 409 : 422);
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
        ], [
            'attendance.required' => 'Choose who was at the meeting before saving.',
            'attendance.array' => 'Choose who was at the meeting before saving.',
            'attendance.*.board_member_id.required' => 'Refresh the page and try again.',
            'attendance.*.board_member_id.exists' => "Someone in the list isn't a board member any more. Refresh the page and try again.",
            'attendance.*.status.required' => 'Choose whether each member was present.',
            'attendance.*.status.in' => 'Choose present, arrived late, sent apologies, absent without apologies or not recorded for each member.',
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

        // Keep the meeting's stored quorum in step with the attendance just
        // recorded, so the register and reports don't report a stale result.
        $meeting->updateQuorumStatus();

        return redirect()->back()->with('success', 'Attendance saved.');
    }

    public function lockMeeting(Request $request, GovernanceMeeting $meeting)
    {
        $this->authorize('lock', $meeting);

        $meeting->update([
            'status' => 'locked',
            'locked_at' => now(),
            'locked_by' => $request->user()->id,
        ]);

        return redirect()->back()->with('success', 'Meeting locked. Its details can no longer be changed.');
    }

    public function signMinutes(Request $request, GovernanceMeeting $meeting)
    {
        $this->authorize('signMinutes', $meeting);

        $validated = $request->validate([
            'expected_version' => 'required|integer',
            'expected_hash' => 'nullable|string',
        ], [
            'expected_version.required' => 'Refresh the page and try again.',
            'expected_version.integer' => 'Refresh the page and try again.',
        ]);

        try {
            $this->minuteService->signMinutes(
                $meeting,
                $request->user(),
                (int) $validated['expected_version'],
                $validated['expected_hash'] ?? null
            );

            return redirect()->back()->with('success', 'Minutes signed.');
        } catch (DomainException $e) {
            if ($request->wantsJson()) {
                return response()->json(['error' => $e->getMessage()], $e instanceof MinutesChangedByOthers ? 409 : 422);
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
        ], [
            'reason.required' => 'Say what needs correcting.',
            'reason.min' => 'Say a little more about what needs correcting.',
            'reason.max' => 'Keep the reason to 1,000 characters.',
        ]);

        try {
            $minutes = $this->minuteService->createCorrection($meeting, $request->user(), $validated['reason']);

            return redirect()->back()->with(
                'success',
                "Correction started as version {$minutes->version_number}. The approved version is kept in the version history."
            );
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
            return redirect()->back()->with('error', "The meeting can't move to its next stage yet.");
        }

        return redirect()->back()->with(
            'success',
            'Meeting moved to: '.mb_strtolower(GovernanceLabels::label('meeting_status', $meeting->fresh()->status)).'.'
        );
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
        ], [
            'response.in' => "Choose whether you're attending, sending apologies or not sure yet.",
            'status.in' => "Choose whether you're attending, sending apologies or not sure yet.",
            'decline_reason.max' => 'Keep your reason to 500 characters.',
            'notes.max' => 'Keep your note to 500 characters.',
            'dietary_notes.max' => 'Keep your dietary or access needs to 255 characters.',
        ]);

        $viewer = $request->user();
        $boardMember = $viewer->boardMember;
        if (! $boardMember) {
            abort(403, 'Only board members can reply to meeting invitations.');
        }

        if (! $meeting->isInvited($boardMember)) {
            abort(403, 'Only members of this committee are invited to this meeting.');
        }

        $rawResponse = $validated['response'] ?? $validated['status'] ?? 'accepted';
        $normalizedResponse = match ($rawResponse) {
            'attending', 'accepted' => 'accepted',
            'apology', 'declined' => 'declined',
            'unsure', 'tentative' => 'tentative',
            default => 'accepted',
        };

        // An apology keeps its reason; attending (or not sure yet) keeps any
        // dietary or access needs. Switching replies clears the other note.
        $isApology = $normalizedResponse === 'declined';
        $declineReason = $isApology ? ($validated['decline_reason'] ?? $validated['notes'] ?? null) : null;
        $dietaryNotes = $isApology ? null : ($validated['dietary_notes'] ?? $validated['notes'] ?? null);
        $hasDietary = ! $isApology && (! empty($validated['dietary_requirements']) || filled($dietaryNotes));

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

        $receiptId = $this->rsvpReceiptId($rsvp->fresh());

        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'message' => 'Your reply has been recorded.',
                'receipt_id' => $receiptId,
                'rsvp' => $this->presentRsvp($rsvp->fresh()),
            ]);
        }

        return redirect()->back()->with('success', 'Your reply has been recorded.')->with('receipt_id', $receiptId);
    }
}
