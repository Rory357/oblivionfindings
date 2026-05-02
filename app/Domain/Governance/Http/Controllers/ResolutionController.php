<?php

namespace App\Domain\Governance\Http\Controllers;

use App\Domain\Governance\Http\Requests\StoreResolutionRequest;
use App\Domain\Governance\Http\Requests\UpdateResolutionRequest;
use App\Domain\Governance\Models\BoardMember;
use App\Domain\Governance\Models\GovernanceMeeting;
use App\Domain\Governance\Models\Resolution;
use App\Domain\Governance\Services\VotingService;
use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ResolutionController extends Controller
{
    public function __construct(
        protected VotingService $votingService
    ) {}

    public function create(Request $request)
    {
        $meetings = GovernanceMeeting::query()
            ->orderByDesc('scheduled_at')
            ->get(['id', 'title', 'scheduled_at']);

        return Inertia::render('Governance/Resolutions/Create', [
            'meetings' => $meetings,
            'selectedMeetingId' => $request->get('meeting_id'),
        ]);
    }

    public function index(Request $request)
    {
        $query = Resolution::with(['meeting', 'proposedBy']);

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        $resolutions = $query->orderByDesc('created_at')->paginate(20);

        return Inertia::render('Governance/Resolutions/Index', [
            'resolutions' => $resolutions,
            'my_pending_votes' => $this->getMyPendingVotes(),
        ]);
    }

    public function show(Resolution $resolution)
    {
        $resolution->load(['meeting', 'proposedBy', 'votes.boardMember.user', 'conflictDeclarations.boardMember.user']);

        $results = in_array($resolution->status, ['closed', 'implemented', 'archived'], true)
            ? $this->votingService->getVotingResults($resolution)
            : null;

        $boardMember = BoardMember::active()
            ->where('user_id', auth()->id())
            ->first();

        $myVote = $boardMember
            ? $resolution->getBoardMemberVote($boardMember->id)
            : null;

        return Inertia::render('Governance/Resolutions/Show', [
            'resolution' => $resolution,
            'results' => $results,
            'my_vote' => $myVote,
            'can_vote' => auth()->user()->can('vote', $resolution),
            'quorum' => $resolution->governance_meeting_id
                ? $this->votingService->calculateQuorum($resolution->governance_meeting_id)
                : null,
        ]);
    }

    public function store(StoreResolutionRequest $request)
    {
        $validated = $request->validated();

        $votingThreshold = match ($validated['type'] ?? 'ordinary') {
            'special' => 'two_thirds',
            'unanimous' => 'unanimous',
            default => 'simple_majority',
        };

        $resolution = Resolution::create([
            'title' => $validated['title'],
            'context' => $validated['description'] ?? '',
            'options' => [],
            'voting_threshold' => $votingThreshold,
            'deadline' => $validated['voting_deadline'] ?? null,
            'governance_meeting_id' => $validated['meeting_id'] ?? null,
            'proposed_by' => auth()->id(),
            'proposed_at' => now(),
            'status' => 'draft',
        ]);

        return redirect()->route('governance.resolutions.show', $resolution)
            ->with('success', 'Resolution created.');
    }

    public function update(UpdateResolutionRequest $request, Resolution $resolution)
    {
        $validated = $request->validated();

        $resolution->update($validated);

        return redirect()->back()->with('success', 'Resolution updated.');
    }

    public function vote(Request $request, Resolution $resolution)
    {
        $this->authorize('vote', $resolution);

        $validated = $request->validate([
            'vote' => 'required|in:for,against,abstain',
            'conflict_note' => 'nullable|string',
        ]);

        $boardMember = BoardMember::active()
            ->where('user_id', auth()->id())
            ->first();

        if (! $boardMember) {
            return redirect()->back()->with('error', 'You must be an active board member to vote.');
        }

        $this->votingService->castVote(
            $resolution,
            $boardMember,
            $validated['vote'],
            'electronic',
            $validated['conflict_note'] ?? null
        );

        return redirect()->back()->with('success', 'Vote recorded.');
    }

    public function declareConflict(Request $request, Resolution $resolution)
    {
        $validated = $request->validate([
            'type' => 'required|in:material,related,prejudicial,other',
            'description' => 'required|string|min:20',
            'withdraw_from_voting' => 'boolean',
            'withdraw_from_discussion' => 'boolean',
        ]);

        $boardMember = BoardMember::active()
            ->where('user_id', auth()->id())
            ->first();

        if (! $boardMember) {
            return redirect()->back()->with('error', 'You must be an active board member to declare a conflict.');
        }

        $this->votingService->declareConflict(
            $resolution,
            $boardMember,
            $validated['type'],
            $validated['description'],
            auth()->user(),
            $validated['withdraw_from_voting'] ?? true,
            $validated['withdraw_from_discussion'] ?? false,
        );

        return redirect()->back()->with('success', 'Conflict declared.');
    }

    public function openVoting(Request $request, Resolution $resolution)
    {
        $this->authorize('openVoting', $resolution);

        $validated = $request->validate([
            'deadline' => 'nullable|date|after:now',
        ]);

        $deadline = isset($validated['deadline'])
            ? Carbon::parse($validated['deadline'])
            : null;

        $this->votingService->openVoting($resolution, $deadline);

        return redirect()->back()->with('success', 'Voting opened.');
    }

    public function closeVoting(Request $request, Resolution $resolution)
    {
        $this->authorize('closeVoting', $resolution);

        $validated = $request->validate([
            'notes' => 'nullable|string',
        ]);

        $this->votingService->closeVoting($resolution, $validated['notes'] ?? null);

        return redirect()->back()->with('success', 'Voting closed. Outcome: '.$resolution->outcome);
    }

    public function finalize(Request $request, Resolution $resolution)
    {
        $this->authorize('closeVoting', $resolution);

        $validated = $request->validate([
            'status' => 'required|in:implemented,archived',
            'notes' => 'nullable|string',
        ]);

        if ($resolution->status !== 'closed') {
            return redirect()->back()->with('error', 'Resolution must be closed before finalizing.');
        }

        if ($validated['status'] === 'implemented') {
            $resolution->markImplemented($validated['notes'] ?? null);
        } else {
            $resolution->markArchived($validated['notes'] ?? null);
        }

        return redirect()->back()->with('success', 'Resolution finalized.');
    }

    protected function getMyPendingVotes(): array
    {
        $boardMember = BoardMember::active()
            ->where('user_id', auth()->id())
            ->first();

        if (! $boardMember) {
            return [];
        }

        return $this->votingService
            ->getPendingVotes($boardMember->id)
            ->toArray();
    }
}
