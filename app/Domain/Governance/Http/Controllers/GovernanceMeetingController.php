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
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;

class GovernanceMeetingController extends Controller
{
    public function create()
    {
        $boardMembers = \App\Domain\Governance\Models\BoardMember::with('user')->get();
        $committees = \App\Domain\Governance\Models\BoardCommittee::all();
        
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

    public function show(GovernanceMeeting $meeting)
    {
        $meeting->load([
            'chair.user',
            'secretary.user',
            'agendaItems.presenter',
            'attendances.boardMember.user',
            'minutes',
            'boardPack',
            'resolutions',
        ]);

        $quorum = $meeting->calculateQuorum();
        $boardMembers = BoardMember::with('user')->active()->get();

        return Inertia::render('Governance/Meetings/Show', [
            'meeting' => $meeting,
            'quorum' => $quorum,
            'boardMembers' => $boardMembers,
            'canEdit' => $meeting->isEditable() && auth()->user()->can('update', $meeting),
            'canManageMinutes' => auth()->user()->can('manageMinutes', $meeting),
            'canApproveMinutes' => auth()->user()->can('approveMinutes', $meeting),
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

        $maxOrder = $meeting->agendaItems()->max('order') ?? 0;

        $meeting->agendaItems()->create([
            ...$validated,
            'order' => $maxOrder + 1,
        ]);

        return redirect()->back()->with('success', 'Agenda item added.');
    }

    public function updateAgendaItem(Request $request, GovernanceMeeting $meeting, MeetingAgendaItem $item)
    {
        $this->authorize('update', $meeting);

        $validated = $request->validate([
            'title' => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'presenter_id' => 'nullable|exists:users,id',
            'duration_minutes' => 'sometimes|integer|min:5|max:120',
            'order' => 'sometimes|integer|min:1',
        ]);

        $item->update($validated);

        // Reorder if necessary
        if (isset($validated['order'])) {
            $this->reorderAgendaItems($meeting);
        }

        return redirect()->back()->with('success', 'Agenda item updated.');
    }

    public function removeAgendaItem(GovernanceMeeting $meeting, MeetingAgendaItem $item)
    {
        $this->authorize('update', $meeting);

        $item->delete();
        $this->reorderAgendaItems($meeting);

        return redirect()->back()->with('success', 'Agenda item removed.');
    }

    public function storeMinutes(Request $request, GovernanceMeeting $meeting)
    {
        $this->authorize('manageMinutes', $meeting);

        $validated = $request->validate([
            'content_blocks' => 'required|array',
        ]);

        $minutes = MeetingMinute::create([
            'governance_meeting_id' => $meeting->id,
            'content_blocks' => $validated['content_blocks'],
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

        if (!$meeting->minutes) {
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

        if (!$meeting->minutes) {
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
        if ($meeting->isLocked()) {
            return redirect()->back()->with('error', 'Meeting is already locked.');
        }

        $meeting->lock(auth()->id());

        return redirect()->back()->with('success', 'Meeting locked. No further edits allowed.');
    }

    public function signMinutes(GovernanceMeeting $meeting)
    {
        $minutes = $meeting->minutes;
        if (!$minutes) {
            return redirect()->back()->with('error', 'No minutes found for this meeting.');
        }

        if (!$minutes->isApproved()) {
            return redirect()->back()->with('error', 'Minutes must be approved before signing.');
        }

        $minutes->sign(auth()->id());

        return redirect()->back()->with('success', 'Minutes signed successfully.');
    }

    protected function reorderAgendaItems(GovernanceMeeting $meeting): void
    {
        $items = $meeting->agendaItems()->orderBy('order')->get();

        foreach ($items as $index => $item) {
            $item->update(['order' => $index + 1]);
        }
    }
}
