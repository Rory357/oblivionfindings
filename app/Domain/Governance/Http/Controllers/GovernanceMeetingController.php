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
use App\Domain\Governance\Services\ExecutiveMeetingAccessService;
use App\Domain\Governance\Services\GovernanceNestedMutationService;
use App\Domain\Governance\Services\GovernanceWorkflowService;
use App\Domain\Governance\Services\MeetingMinuteService;
use App\Domain\Governance\Support\GovernancePresenter;
use App\Http\Controllers\Controller;
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
    ) {}

    public function create(Request $request)
    {
        $boardMembers = BoardMember::with('user')->get();
        $committees = BoardCommittee::all();

        $initialScheduledAt = null;
        if ($request->filled('date')) {
            $date = $request->input('date');
            $hour = $request->input('hour');
            $hourNum = is_numeric($hour) ? (int) $hour : 9;
            $padHour = str_pad((string) $hourNum, 2, '0', STR_PAD_LEFT);
            $initialScheduledAt = "{$date}T{$padHour}:00";
        }

        return Inertia::render('Governance/Meetings/Create', [
            'boardMembers' => $boardMembers,
            'committees' => $committees,
            'initialScheduledAt' => $initialScheduledAt,
        ]);
    }

    public function index(Request $request)
    {
        $meetings = $this->executiveAccess->applyMeetingVisibilityScope(
            GovernanceMeeting::with(['chair.user', 'secretary.user'])->orderByDesc('scheduled_at'),
            $request->user()
        )->paginate(15);

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
            'resolutions',
        ]);

        $viewer = $request->user();

        // Scope confidential agenda items for viewers without executive access
        $visibleAgendaItems = $meeting->agendaItems->filter(
            fn (MeetingAgendaItem $item) => $this->executiveAccess->canViewAgendaItem($viewer, $meeting, $item)
        )->values();
        $meeting->setRelation('agendaItems', $visibleAgendaItems);

        $visiblePack = $this->boardPackAccess->visiblePack($viewer, $meeting->boardPack);
        $meeting->setRelation('boardPack', $visiblePack);

        $quorum = $meeting->calculateQuorum();
        $boardMembers = BoardMember::with('user')->active()->get();
        $workflowChecklist = $this->workflowService->meetingChecklist($meeting, $viewer);
        $meetingCockpit = $this->presenter->meetingCockpit($meeting, $quorum, $workflowChecklist, $viewer);

        // The meeting payload needs only a linkable pack summary, never the raw model fields.
        $visiblePack?->setVisible(['id', 'distributed_at']);

        $viewerBoardMember = $viewer->boardMember;
        $viewerCanRsvp = $viewerBoardMember !== null && $meeting->isInvited($viewerBoardMember);
        $viewerRsvp = $viewerCanRsvp ? $meeting->rsvps->firstWhere('board_member_id', $viewerBoardMember->id) : null;

        return Inertia::render('Governance/Meetings/Show', [
            'meeting' => $meeting,
            'quorum' => $quorum,
            'boardMembers' => $boardMembers,
            'canEdit' => $meeting->isEditable() && $viewer->can('update', $meeting),
            'canManageMinutes' => $viewer->can('manageMinutes', $meeting),
            'canApproveMinutes' => $viewer->can('approveMinutes', $meeting),
            'canSignMinutes' => $viewer->can('signMinutes', $meeting),
            'workflowChecklist' => $workflowChecklist,
            'meetingCockpit' => $meetingCockpit,
            'viewerCanRsvp' => $viewerCanRsvp,
            'viewerRsvp' => $viewerRsvp,
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
