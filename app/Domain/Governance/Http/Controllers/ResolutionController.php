<?php

namespace App\Domain\Governance\Http\Controllers;

use App\Domain\Governance\Http\Requests\StoreResolutionRequest;
use App\Domain\Governance\Http\Requests\UpdateResolutionRequest;
use App\Domain\Governance\Models\BoardMember;
use App\Domain\Governance\Models\GovernanceMeeting;
use App\Domain\Governance\Models\Resolution;
use App\Domain\Governance\Services\GovernanceAuditService;
use App\Domain\Governance\Services\VotingService;
use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
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

        $meetings = GovernanceMeeting::query()
            ->orderByDesc('scheduled_at')
            ->get(['id', 'title', 'scheduled_at']);

        return Inertia::render('Governance/Resolutions/Index', [
            'resolutions' => $resolutions,
            'my_pending_votes' => $this->getMyPendingVotes(),
            'meetings' => $meetings,
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
            'attachments' => $this->presentAttachments($resolution),
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

        DB::transaction(function () use ($resolution, $boardMember, $validated) {
            $this->votingService->castVote(
                $resolution,
                $boardMember,
                $validated['vote'],
                'electronic',
                $validated['conflict_note'] ?? null
            );
            GovernanceAuditService::log('resolution.voted', 'Resolution', $resolution->id, [
                'vote' => $validated['vote'],
                'board_member_id' => $boardMember->id,
                'conflict_note' => !empty($validated['conflict_note']),
            ]);
        });

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
        GovernanceAuditService::log('resolution.voting_opened', 'Resolution', $resolution->id, [
            'deadline' => $deadline?->toIso8601String(),
        ]);

        return redirect()->back()->with('success', 'Voting opened.');
    }

    public function closeVoting(Request $request, Resolution $resolution)
    {
        $this->authorize('closeVoting', $resolution);

        $validated = $request->validate([
            'notes' => 'nullable|string',
        ]);

        DB::transaction(function () use ($resolution, $validated) {
            $this->votingService->closeVoting($resolution, $validated['notes'] ?? null);
            GovernanceAuditService::log('resolution.voting_closed', 'Resolution', $resolution->id, [
                'outcome' => $resolution->outcome,
            ]);
        });

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

        DB::transaction(function () use ($resolution, $validated) {
            if ($validated['status'] === 'implemented') {
                $resolution->markImplemented($validated['notes'] ?? null);
            } else {
                $resolution->markArchived($validated['notes'] ?? null);
            }
            GovernanceAuditService::log('resolution.finalized', 'Resolution', $resolution->id, [
                'status' => $validated['status'],
            ]);
        });

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

    /**
     * Upload supporting documents (analyses, briefings, draft contracts) for
     * a resolution. These attach to the JSON column and travel with the
     * resolution when the board reviews it.
     */
    public function attachFiles(Request $request, Resolution $resolution)
    {
        $this->authorize('update', $resolution);

        $request->validate([
            'files' => 'required|array|min:1|max:10',
            'files.*' => [
                'required',
                'file',
                'max:20480', // 20 MB per file
                'mimes:pdf,doc,docx,xls,xlsx,ppt,pptx,jpg,jpeg,png,gif,webp,csv,txt,md',
            ],
        ]);

        $existing = is_array($resolution->attachments) ? $resolution->attachments : [];

        foreach ($request->file('files') as $file) {
            $directory = "governance/resolutions/{$resolution->id}";
            $extension = $file->getClientOriginalExtension() ?: $file->extension();
            $storedName = Str::uuid()->toString() . ($extension ? ".{$extension}" : '');
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

        $resolution->update(['attachments' => $existing]);

        GovernanceAuditService::log(
            'resolution.attachment_added',
            'Resolution',
            $resolution->id,
            ['count' => count($request->file('files'))],
        );

        return $request->wantsJson()
            ? response()->json(['attachments' => $this->presentAttachments($resolution->fresh())])
            : redirect()->back()->with('success', 'Attachment(s) added.');
    }

    public function deleteAttachment(Request $request, Resolution $resolution, string $attachment)
    {
        $this->authorize('update', $resolution);

        $existing = is_array($resolution->attachments) ? $resolution->attachments : [];
        $target = collect($existing)->firstWhere('id', $attachment);

        if (! $target) {
            abort(404, 'Attachment not found.');
        }

        if (isset($target['path']) && Storage::disk('local')->exists($target['path'])) {
            Storage::disk('local')->delete($target['path']);
        }

        $remaining = array_values(
            array_filter($existing, fn (array $row) => ($row['id'] ?? null) !== $attachment),
        );

        $resolution->update(['attachments' => $remaining]);

        GovernanceAuditService::log(
            'resolution.attachment_removed',
            'Resolution',
            $resolution->id,
            ['attachment_id' => $attachment, 'original_name' => $target['original_name'] ?? null],
        );

        return $request->wantsJson()
            ? response()->json(['attachments' => $this->presentAttachments($resolution->fresh())])
            : redirect()->back()->with('success', 'Attachment removed.');
    }

    public function downloadAttachment(Resolution $resolution, string $attachment)
    {
        $this->authorize('view', $resolution);

        $existing = is_array($resolution->attachments) ? $resolution->attachments : [];
        $target = collect($existing)->firstWhere('id', $attachment);

        if (! $target || empty($target['path']) || ! Storage::disk('local')->exists($target['path'])) {
            abort(404, 'Attachment not found.');
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
    protected function presentAttachments(Resolution $resolution): array
    {
        $existing = is_array($resolution->attachments) ? $resolution->attachments : [];

        return collect($existing)->map(fn (array $row) => [
            'id' => $row['id'] ?? null,
            'original_name' => $row['original_name'] ?? 'attachment',
            'mime_type' => $row['mime_type'] ?? null,
            'size_bytes' => $row['size_bytes'] ?? null,
            'uploaded_at' => $row['uploaded_at'] ?? null,
            'uploaded_by_name' => $row['uploaded_by_name'] ?? null,
            'download_url' => isset($row['id'])
                ? "/governance/resolutions/{$resolution->id}/attachments/{$row['id']}/download"
                : null,
        ])->all();
    }
}
