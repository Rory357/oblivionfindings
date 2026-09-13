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
        $this->authorize('create', Resolution::class);

        $recordAccess = app(\App\Domain\Governance\Services\GovernanceRecordAccessService::class);
        $user = $request->user();

        $meetingsQuery = GovernanceMeeting::query()->orderByDesc('scheduled_at');
        $recordAccess->scopeMeetings($meetingsQuery, $user);
        $meetings = $meetingsQuery->get(['id', 'title', 'scheduled_at']);

        $committees = \App\Domain\Governance\Models\BoardCommittee::query()
            ->orderBy('name')
            ->get(['id', 'name']);

        return Inertia::render('Governance/Resolutions/Create', [
            'meetings' => $meetings,
            'committees' => $committees,
            'selectedMeetingId' => $request->get('meeting_id'),
        ]);
    }

    public function index(Request $request)
    {
        $this->authorize('viewAny', Resolution::class);

        $recordAccess = app(\App\Domain\Governance\Services\GovernanceRecordAccessService::class);
        $user = $request->user();

        $query = Resolution::with(['meeting', 'committee', 'proposedBy']);
        $recordAccess->scopeResolutions($query, $user);

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        $resolutions = $query->orderByDesc('created_at')->paginate(20);

        $meetingsQuery = GovernanceMeeting::query()->orderByDesc('scheduled_at');
        $recordAccess->scopeMeetings($meetingsQuery, $user);
        $meetings = $meetingsQuery->get(['id', 'title', 'scheduled_at']);

        return Inertia::render('Governance/Resolutions/Index', [
            'resolutions' => $resolutions,
            'my_pending_votes' => $this->getMyPendingVotes(),
            'meetings' => $meetings,
        ]);
    }

    public function show(Resolution $resolution)
    {
        $this->authorize('view', $resolution);

        $resolution->load(['meeting', 'committee', 'proposedBy', 'votes.boardMember.user', 'conflictDeclarations.boardMember.user']);

        $results = in_array($resolution->status, ['closed', 'implemented', 'archived'], true)
            ? $this->votingService->getVotingResults($resolution)
            : null;

        $boardMember = BoardMember::active()
            ->where('user_id', auth()->id())
            ->first();

        $myVote = $boardMember
            ? $resolution->getBoardMemberVote($boardMember->id)
            : null;

        $myConflict = $boardMember
            ? $resolution->conflictDeclarations()
                ->where('board_member_id', $boardMember->id)
                ->first()
            : null;

        $recordAccess = app(\App\Domain\Governance\Services\GovernanceRecordAccessService::class);
        $user = auth()->user();

        $meetingsQuery = GovernanceMeeting::query()->orderByDesc('scheduled_at');
        $recordAccess->scopeMeetings($meetingsQuery, $user);
        $meetings = $meetingsQuery->get(['id', 'title', 'scheduled_at']);

        $committees = \App\Domain\Governance\Models\BoardCommittee::query()
            ->orderBy('name')
            ->get(['id', 'name']);

        return Inertia::render('Governance/Resolutions/Show', [
            'resolution' => $resolution,
            'results' => $results,
            'my_vote' => $myVote,
            'my_conflict' => $myConflict,
            'can_vote' => auth()->user()->can('vote', $resolution) && (!$myConflict || !$myConflict->withdrew_from_voting),
            'can_manage' => auth()->user()->can('update', $resolution),
            'quorum' => $resolution->governance_meeting_id
                ? $this->votingService->calculateQuorum($resolution->governance_meeting_id, $resolution)
                : $this->votingService->calculateQuorum(null, $resolution),
            'attachments' => $this->presentAttachments($resolution),
            'paper_snapshot' => $resolution->paper_snapshot,
            'validation_errors' => $resolution->isDraft() ? $resolution->validateForPublication() : [],
            'meetings' => $meetings,
            'committees' => $committees,
        ]);
    }

    public function store(StoreResolutionRequest $request)
    {
        $validated = $request->validated();

        $votingThreshold = match ($validated['type'] ?? 'ordinary') {
            'special' => 'two_thirds',
            'unanimous' => 'unanimous',
            default => $validated['voting_threshold'] ?? 'simple_majority',
        };

        $meetingId = $validated['meeting_id'] ?? $validated['governance_meeting_id'] ?? null;
        $context = $validated['context'] ?? $validated['description'] ?? '';

        $resolution = Resolution::create([
            'title' => $validated['title'],
            'exact_motion' => $validated['exact_motion'] ?? null,
            'purpose' => $validated['purpose'] ?? 'decision',
            'decision_type' => $validated['decision_type'] ?? 'resolution',
            'context' => $context,
            'options' => $validated['options'] ?? [],
            'single_option_reason' => $validated['single_option_reason'] ?? null,
            'recommendation' => $validated['recommendation'] ?? null,
            'cost_impact' => $validated['cost_impact'] ?? null,
            'risk_impact' => $validated['risk_impact'] ?? null,
            'service_user_implications' => $validated['service_user_implications'] ?? null,
            'risk_equity_implications' => $validated['risk_equity_implications'] ?? null,
            'attachments' => $validated['attachments'] ?? [],
            'follow_up_actions' => $validated['follow_up_actions'] ?? [],
            'voting_threshold' => $votingThreshold,
            'quorum_required' => $validated['quorum_required'] ?? true,
            'deadline' => $validated['voting_deadline'] ?? null,
            'governance_meeting_id' => $meetingId,
            'board_committee_id' => $validated['board_committee_id'] ?? null,
            'proposed_by' => auth()->id(),
            'proposed_at' => now(),
            'status' => 'draft',
            'version_number' => 1,
        ]);

        GovernanceAuditService::log('resolution.created', 'Resolution', $resolution->id, [
            'title' => $resolution->title,
            'purpose' => $resolution->purpose,
        ]);

        if ($request->boolean('publish_now')) {
            $errors = $resolution->validateForPublication();
            if (!empty($errors)) {
                return redirect()->route('governance.resolutions.show', $resolution)
                    ->with('error', 'Cannot publish paper: ' . implode(' ', $errors));
            }
            $deadline = $resolution->deadline ? Carbon::parse($resolution->deadline) : null;
            $this->votingService->openVoting($resolution, $deadline);
        }

        return redirect()->route('governance.resolutions.show', $resolution)
            ->with('success', 'Decision paper created.');
    }

    public function update(UpdateResolutionRequest $request, Resolution $resolution)
    {
        $this->authorize('update', $resolution);

        if (!$resolution->isEditable()) {
            abort(422, 'Cannot edit paper: resolution is no longer a draft.');
        }

        $validated = $request->validated();

        if (isset($validated['expected_version']) && (int)$resolution->version_number !== (int)$validated['expected_version']) {
            abort(409, 'This decision paper has been updated by another user. Please reload and review the latest changes.');
        }

        $votingThreshold = isset($validated['type']) ? match ($validated['type']) {
            'special' => 'two_thirds',
            'unanimous' => 'unanimous',
            default => 'simple_majority',
        } : ($validated['voting_threshold'] ?? $resolution->voting_threshold);

        $meetingId = array_key_exists('meeting_id', $validated)
            ? $validated['meeting_id']
            : (array_key_exists('governance_meeting_id', $validated) ? $validated['governance_meeting_id'] : $resolution->governance_meeting_id);

        $context = array_key_exists('context', $validated)
            ? $validated['context']
            : (array_key_exists('description', $validated) ? $validated['description'] : $resolution->context);

        $updateData = [
            'title' => $validated['title'] ?? $resolution->title,
            'exact_motion' => array_key_exists('exact_motion', $validated) ? $validated['exact_motion'] : $resolution->exact_motion,
            'purpose' => $validated['purpose'] ?? $resolution->purpose,
            'decision_type' => array_key_exists('decision_type', $validated) ? ($validated['decision_type'] ?? 'resolution') : ($resolution->decision_type ?? 'resolution'),
            'context' => $context,
            'options' => array_key_exists('options', $validated) ? $validated['options'] : $resolution->options,
            'single_option_reason' => array_key_exists('single_option_reason', $validated) ? $validated['single_option_reason'] : $resolution->single_option_reason,
            'recommendation' => array_key_exists('recommendation', $validated) ? $validated['recommendation'] : $resolution->recommendation,
            'cost_impact' => array_key_exists('cost_impact', $validated) ? $validated['cost_impact'] : $resolution->cost_impact,
            'risk_impact' => array_key_exists('risk_impact', $validated) ? $validated['risk_impact'] : $resolution->risk_impact,
            'service_user_implications' => array_key_exists('service_user_implications', $validated) ? $validated['service_user_implications'] : $resolution->service_user_implications,
            'risk_equity_implications' => array_key_exists('risk_equity_implications', $validated) ? $validated['risk_equity_implications'] : $resolution->risk_equity_implications,
            'voting_threshold' => $votingThreshold,
            'deadline' => array_key_exists('voting_deadline', $validated) ? $validated['voting_deadline'] : $resolution->deadline,
            'governance_meeting_id' => $meetingId,
            'board_committee_id' => array_key_exists('board_committee_id', $validated) ? $validated['board_committee_id'] : $resolution->board_committee_id,
            'follow_up_actions' => array_key_exists('follow_up_actions', $validated) ? $validated['follow_up_actions'] : $resolution->follow_up_actions,
            'version_number' => ($resolution->version_number ?? 1) + 1,
        ];

        $resolution->update($updateData);

        GovernanceAuditService::log('resolution.updated', 'Resolution', $resolution->id, [
            'version' => $resolution->version_number,
        ]);

        if ($request->boolean('publish_now')) {
            $errors = $resolution->validateForPublication();
            if (!empty($errors)) {
                return redirect()->route('governance.resolutions.show', $resolution)
                    ->with('error', 'Cannot publish paper: ' . implode(' ', $errors));
            }
            $deadline = $resolution->deadline ? Carbon::parse($resolution->deadline) : null;
            $this->votingService->openVoting($resolution, $deadline);
        }

        return redirect()->route('governance.resolutions.show', $resolution)
            ->with('success', 'Decision paper updated.');
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

        try {
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

            return redirect()->back()->with('success', 'Vote recorded.');
        } catch (\InvalidArgumentException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }
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

        try {
            $this->votingService->declareConflict(
                $resolution,
                $boardMember,
                $validated['type'],
                $validated['description'],
                auth()->user(),
                $validated['withdraw_from_voting'] ?? true,
                $validated['withdraw_from_discussion'] ?? false,
            );

            GovernanceAuditService::log('resolution.conflict_declared', 'Resolution', $resolution->id, [
                'board_member_id' => $boardMember->id,
                'type' => $validated['type'],
                'withdrew_from_voting' => $validated['withdraw_from_voting'] ?? true,
            ]);

            return redirect()->back()->with('success', 'Conflict declared.');
        } catch (\InvalidArgumentException|\DomainException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }
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

        try {
            $this->votingService->openVoting($resolution, $deadline);
            GovernanceAuditService::log('resolution.voting_opened', 'Resolution', $resolution->id, [
                'deadline' => $deadline?->toIso8601String(),
            ]);

            return redirect()->back()->with('success', 'Voting opened.');
        } catch (\DomainException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }
    }

    public function closeVoting(Request $request, Resolution $resolution)
    {
        $this->authorize('closeVoting', $resolution);

        $validated = $request->validate([
            'notes' => 'nullable|string',
        ]);

        try {
            $this->votingService->closeVoting($resolution, $validated['notes'] ?? null);
            GovernanceAuditService::log('resolution.voting_closed', 'Resolution', $resolution->id, [
                'outcome' => $resolution->outcome,
            ]);

            return redirect()->back()->with('success', 'Voting closed. Outcome: '.$resolution->outcome);
        } catch (\InvalidArgumentException|\DomainException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }
    }

    public function finalize(Request $request, Resolution $resolution)
    {
        $this->authorize('closeVoting', $resolution);

        $validated = $request->validate([
            'status' => 'required|in:implemented,archived',
            'notes' => 'nullable|string',
            'no_action_reason' => 'nullable|string|max:1000',
        ]);

        if ($resolution->status !== 'closed') {
            return redirect()->back()->with('error', 'Resolution must be closed before finalizing.');
        }

        if ($validated['status'] === 'implemented' && $resolution->outcome !== 'carried') {
            return redirect()->back()->with('error', "Cannot mark resolution as implemented unless outcome is carried (outcome is '{$resolution->outcome}').");
        }

        try {
            DB::transaction(function () use ($resolution, $validated) {
                if ($validated['status'] === 'implemented') {
                    $resolution->markImplemented($validated['notes'] ?? null, $validated['no_action_reason'] ?? null);
                } else {
                    $resolution->markArchived($validated['notes'] ?? null);
                }
                GovernanceAuditService::log('resolution.finalized', 'Resolution', $resolution->id, [
                    'status' => $validated['status'],
                ]);
            });

            return redirect()->back()->with('success', 'Resolution finalized.');
        } catch (\DomainException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }
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

        if (!$resolution->isEditable()) {
            abort(422, 'Cannot modify attachments on an active or closed resolution paper.');
        }

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

        if (!$resolution->isEditable()) {
            abort(422, 'Cannot modify attachments on an active or closed resolution paper.');
        }

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
