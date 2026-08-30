<?php

namespace App\Domain\Governance\Http\Controllers;

use App\Domain\Governance\Http\Requests\StoreMeetingRequest;
use App\Domain\Governance\Http\Requests\UpdateMeetingRequest;
use App\Domain\Governance\Models\BoardCommittee;
use App\Domain\Governance\Models\BoardMember;
use App\Domain\Governance\Models\GovernanceMeeting;
use App\Domain\Governance\Models\MeetingAgendaItem;
use App\Domain\Governance\Models\MeetingAttendance;
use App\Domain\Governance\Models\MeetingMinute;
use App\Domain\Governance\Models\MeetingRsvp;
use App\Domain\Governance\Services\BoardPackAccessService;
use App\Domain\Governance\Services\GovernanceNestedMutationService;
use App\Domain\Governance\Services\GovernanceWorkflowService;
use App\Domain\Governance\Support\GovernancePresenter;
use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Inertia\Inertia;

class GovernanceMeetingController extends Controller
{
    public function __construct(
        protected GovernanceWorkflowService $workflowService,
        protected GovernancePresenter $presenter,
        protected GovernanceNestedMutationService $nestedMutations,
        protected BoardPackAccessService $boardPackAccess,
    ) {}

    public function create()
    {
        $boardMembers = BoardMember::with('user')->get();
        $committees = BoardCommittee::all();

        return Inertia::render('Governance/Meetings/Create', [
            'boardMembers' => $boardMembers,
            'committees' => $committees,
        ]);
    }

    public function index(Request $request)
    {
        $meetings = GovernanceMeeting::with(['chair.user', 'secretary.user'])
            ->orderByDesc('scheduled_at')
            ->paginate(15);

        return Inertia::render('Governance/Meetings/Index', [
            'meetings' => $meetings,
        ]);
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
        ]);
    }

    public function show(Request $request, GovernanceMeeting $meeting)
    {
        $meeting->load([
            'chair.user',
            'secretary.user',
            'agendaItems.presenter',
            'attendances.boardMember.user',
            'ceoReport.submittedBy',
            'minutes',
            'boardPack',
            'resolutions',
        ]);

        $viewer = $request->user();
        $visiblePack = $this->boardPackAccess->visiblePack($viewer, $meeting->boardPack);
        $meeting->setRelation('boardPack', $visiblePack);

        $quorum = $meeting->calculateQuorum();
        $boardMembers = BoardMember::with('user')->active()->get();
        $workflowChecklist = $this->workflowService->meetingChecklist($meeting, $viewer);
        $meetingCockpit = $this->presenter->meetingCockpit($meeting, $quorum, $workflowChecklist, $viewer);

        // The meeting payload needs only a linkable pack summary, never the raw model fields.
        $visiblePack?->setVisible(['id', 'distributed_at']);

        return Inertia::render('Governance/Meetings/Show', [
            'meeting' => $meeting,
            'quorum' => $quorum,
            'boardMembers' => $boardMembers,
            'canEdit' => $meeting->isEditable() && $viewer->can('update', $meeting),
            'canManageMinutes' => $viewer->can('manageMinutes', $meeting),
            'canApproveMinutes' => $viewer->can('approveMinutes', $meeting),
            'workflowChecklist' => $workflowChecklist,
            'meetingCockpit' => $meetingCockpit,
        ]);
    }

    public function edit(GovernanceMeeting $meeting)
    {
        $this->authorize('update', $meeting);

        $boardMembers = BoardMember::with('user')->get();
        $committees = BoardCommittee::all();

        return Inertia::render('Governance/Meetings/Edit', [
            'meeting' => $meeting,
            'boardMembers' => $boardMembers,
            'committees' => $committees,
        ]);
    }

    public function store(StoreMeetingRequest $request)
    {
        $meeting = GovernanceMeeting::create([
            ...$request->validated(),
            'created_by' => auth()->id(),
        ]);

        return redirect()->route('governance.meetings.show', $meeting)
            ->with('success', 'Meeting scheduled successfully.');
    }

    public function update(UpdateMeetingRequest $request, GovernanceMeeting $meeting)
    {
        $meeting->update($request->validated());

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

        $this->nestedMutations->addAgendaItem($request->user(), $meeting, $validated);

        return redirect()->back()->with('success', 'Agenda item added.');
    }

    public function updateAgendaItem(Request $request, GovernanceMeeting $meeting, MeetingAgendaItem $item)
    {
        $this->nestedMutations->assertAgendaItemBound($request->user(), $meeting, $item);

        $validated = $request->validate([
            'title' => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'presenter_id' => 'nullable|exists:users,id',
            'duration_minutes' => 'sometimes|integer|min:5|max:120',
            'order' => 'sometimes|integer|min:1',
        ]);

        $this->nestedMutations->updateAgendaItem($request->user(), $meeting, $item, $validated);

        return redirect()->back()->with('success', 'Agenda item updated.');
    }

    public function removeAgendaItem(Request $request, GovernanceMeeting $meeting, MeetingAgendaItem $item)
    {
        $this->nestedMutations->removeAgendaItem($request->user(), $meeting, $item);

        return redirect()->back()->with('success', 'Agenda item removed.');
    }

    public function storeMinutes(Request $request, GovernanceMeeting $meeting)
    {
        $this->authorize('manageMinutes', $meeting);

        $validated = $request->validate([
            'content_blocks' => 'nullable|array',
        ]);

        $contentBlocks = $validated['content_blocks'] ?? null;
        if (empty($contentBlocks)) {
            $contentBlocks = $meeting->generateMinutesSkeleton();
        }

        $minutes = MeetingMinute::create([
            'governance_meeting_id' => $meeting->id,
            'content_blocks' => $contentBlocks,
            'status' => 'draft',
            'drafted_by' => auth()->id(),
            'drafted_at' => now(),
        ]);

        $meeting->update(['status' => 'minutes_draft']);

        return redirect()->back()->with('success', 'Minutes drafted.');
    }

    public function updateMinutes(Request $request, GovernanceMeeting $meeting)
    {
        $this->authorize('manageMinutes', $meeting);

        if (! $meeting->minutes) {
            return redirect()->back()->with('error', 'Minutes have not been created for this meeting yet.');
        }

        $validated = $request->validate([
            'content_blocks' => 'required|array',
        ]);

        $meeting->minutes->update([
            'content_blocks' => $validated['content_blocks'],
        ]);
        $meeting->minutes->incrementVersion();

        return redirect()->back()->with('success', 'Minutes updated.');
    }

    public function approveMinutes(Request $request, GovernanceMeeting $meeting)
    {
        $this->authorize('approveMinutes', $meeting);

        if (! $meeting->minutes) {
            return redirect()->back()->with('error', 'Minutes have not been created for this meeting yet.');
        }

        $meeting->minutes->update([
            'status' => 'approved',
            'reviewed_by' => auth()->user()->boardMember?->id,
            'reviewed_at' => now(),
        ]);

        $meeting->update(['status' => 'minutes_approved']);

        return redirect()->back()->with('success', 'Minutes approved.');
    }

    public function recordAttendance(Request $request, GovernanceMeeting $meeting)
    {
        $this->authorize('update', $meeting);

        $validated = $request->validate([
            'attendance' => 'required|array',
            'attendance.*.board_member_id' => 'required|exists:board_members,id',
            'attendance.*.status' => 'required|in:present,apology,no_show,late',
            'attendance.*.apology_reason' => 'nullable|string',
        ]);

        foreach ($validated['attendance'] as $record) {
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

        $meeting->updateQuorumStatus();

        return redirect()->back()->with('success', 'Attendance recorded.');
    }

    public function lockMeeting(GovernanceMeeting $meeting)
    {
        $this->authorize('update', $meeting);

        if ($meeting->isLocked()) {
            return redirect()->back()->with('error', 'Meeting is already locked.');
        }

        $meeting->lock(auth()->id());

        return redirect()->back()->with('success', 'Meeting locked. No further edits allowed.');
    }

    public function signMinutes(GovernanceMeeting $meeting)
    {
        $this->authorize('approveMinutes', $meeting);

        $minutes = $meeting->minutes;
        if (! $minutes) {
            return redirect()->back()->with('error', 'No minutes found for this meeting.');
        }

        if (! $minutes->isApproved()) {
            return redirect()->back()->with('error', 'Minutes must be approved before signing.');
        }

        $minutes->sign(auth()->id());

        return redirect()->back()->with('success', 'Minutes signed successfully.');
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
        $validated = $request->validate([
            'status' => 'required|in:attending,apology,tentative',
            'dietary_requirements' => 'nullable|string|max:255',
            'notes' => 'nullable|string|max:500',
        ]);

        $boardMember = auth()->user()->boardMember;
        if (! $boardMember) {
            return redirect()->back()->with('error', 'You are not a board member.');
        }

        MeetingRsvp::updateOrCreate(
            [
                'governance_meeting_id' => $meeting->id,
                'board_member_id' => $boardMember->id,
            ],
            [
                ...$validated,
                'responded_at' => now(),
            ]
        );

        return redirect()->back()->with('success', 'RSVP recorded.');
    }
}
