<?php

namespace App\Domain\Governance\Http\Controllers;

use App\Domain\Governance\Http\Requests\StoreResolutionRequest;
use App\Domain\Governance\Http\Requests\UpdateResolutionRequest;
use App\Domain\Governance\Models\BoardMember;
use App\Domain\Governance\Models\GovernanceMeeting;
use App\Domain\Governance\Models\GovernanceResolutionBinding;
use App\Domain\Governance\Models\Resolution;
use App\Domain\Governance\Services\GovernanceAuditService;
use App\Domain\Governance\Services\GovernanceResolutionAuthorityService;
use App\Domain\Governance\Services\VotingService;
use App\Http\Controllers\Controller;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class ResolutionController extends Controller
{
    public function __construct(
        protected VotingService $votingService
    ) {}

    /**
     * The full-page authoring form was replaced by the register's
     * WizardShell dialog: old links land on the index with `?create=1`
     * (and the meeting preselected when one was supplied).
     */
    public function create(Request $request)
    {
        $this->authorize('create', Resolution::class);

        $query = ['create' => 1];
        $meetingId = filter_var($request->query('meeting_id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($meetingId !== false) {
            $query['meeting_id'] = $meetingId;
        }

        return redirect()->route('governance.resolutions.index', $query);
    }

    public function index(Request $request)
    {
        $this->authorize('viewAny', Resolution::class);

        $recordAccess = app(\App\Domain\Governance\Services\GovernanceRecordAccessService::class);
        $user = $request->user();

        $base = Resolution::query();
        $recordAccess->scopeResolutions($base, $user);

        $query = (clone $base)->with(['meeting', 'committee', 'proposedBy']);

        if ($request->filled('status')) {
            $query->where('status', (string) $request->input('status'));
        }

        if ($request->filled('outcome')) {
            $query->where('outcome', (string) $request->input('outcome'));
        }

        $meetingFilter = filter_var($request->input('meeting'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($meetingFilter !== false) {
            $query->where('governance_meeting_id', $meetingFilter);
        }

        if ($request->filled('search')) {
            $search = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], trim((string) $request->input('search'))).'%';
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', $search)
                    ->orWhere('resolution_reference', 'like', $search)
                    ->orWhere('exact_motion', 'like', $search);
            });
        }

        $resolutions = $query->orderByDesc('created_at')->paginate(20)->withQueryString();

        $meetingsQuery = GovernanceMeeting::query()->orderByDesc('scheduled_at');
        $recordAccess->scopeMeetings($meetingsQuery, $user);
        $meetings = $meetingsQuery->get(['id', 'title', 'scheduled_at']);

        $canCreate = $this->canAuthorPapers($user);

        return Inertia::render('Governance/Resolutions/Index', [
            'resolutions' => $resolutions,
            'my_pending_votes' => $this->getMyPendingVotes(),
            'meetings' => $meetings,
            'summary' => [
                'total' => (clone $base)->count(),
                'draft' => (clone $base)->where('status', 'draft')->count(),
                'open' => (clone $base)->where('status', 'open')->count(),
                'carried' => (clone $base)->where('outcome', 'carried')->count(),
            ],
            'filters' => [
                'status' => $request->filled('status') ? (string) $request->input('status') : null,
                'outcome' => $request->filled('outcome') ? (string) $request->input('outcome') : null,
                'meeting' => $meetingFilter !== false ? (string) $meetingFilter : null,
                'search' => $request->filled('search') ? (string) $request->input('search') : null,
            ],
            'can_create' => $canCreate,
            // Publishing from the wizard opens voting — the same ability.
            'can_publish' => $canCreate && $user->can('openVoting', new Resolution),
            // Wizard options are only sent to people who can author papers.
            ...($canCreate ? $this->authoringFormProps($user) : [
                'committees' => [],
                'users' => [],
                'authoritySubjects' => null,
                'authoritySubjectGroups' => [],
            ]),
        ]);
    }

    public function show(Resolution $resolution)
    {
        $this->authorize('view', $resolution);

        $resolution->load(['meeting', 'committee', 'proposedBy', 'votes.boardMember.user', 'conflictDeclarations.boardMember.user']);

        // Stored attachment rows (and the frozen snapshot's copy of them)
        // carry storage paths; the page receives presented payloads only.
        $resolution->makeHidden(['attachments', 'paper_snapshot']);

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

        $user = auth()->user();
        // `update` is draft-only, so edit options exist only while editable.
        $canManage = $user->can('update', $resolution);
        $canCommand = $user->canDo('governance.resolutions.manage');
        $formProps = $canManage ? $this->authoringFormProps($user) : [
            'meetings' => [],
            'committees' => [],
            'users' => [],
            'authoritySubjects' => null,
            'authoritySubjectGroups' => [],
        ];

        return Inertia::render('Governance/Resolutions/Show', [
            'resolution' => $resolution,
            'results' => $results,
            'my_vote' => $myVote,
            'my_conflict' => $myConflict,
            'can_vote' => $user->can('vote', $resolution) && (!$myConflict || !$myConflict->withdrew_from_voting),
            'can_manage' => $canManage,
            // Mirrors the route permission AND the policy ability of each command.
            'can_open_voting' => $resolution->isDraft() && $canCommand && $user->can('openVoting', $resolution),
            'can_close_voting' => $resolution->status === 'open' && $canCommand && $user->can('closeVoting', $resolution),
            'can_finalize' => $resolution->status === 'closed' && $canCommand && $user->can('closeVoting', $resolution),
            'quorum' => $resolution->governance_meeting_id
                ? $this->votingService->calculateQuorum($resolution->governance_meeting_id, $resolution)
                : $this->votingService->calculateQuorum(null, $resolution),
            'attachments' => $this->presentAttachments($resolution),
            'paper_snapshot' => $this->presentPaperSnapshot($resolution),
            'authority_bindings' => $this->presentAuthorityBindings($resolution, $formProps['authoritySubjects']),
            'validation_errors' => $resolution->isDraft() ? $resolution->validateForPublication() : [],
            ...$formProps,
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

        $authority = app(GovernanceResolutionAuthorityService::class);
        $resolution = DB::transaction(function () use ($validated, $votingThreshold, $meetingId, $context, $authority, $request): Resolution {
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

            // Explicit decision authority is bound while the paper is a draft.
            $authority->applyAuthoringInput($resolution, $validated, $request->user());

            return $resolution;
        });

        GovernanceAuditService::log('resolution.created', 'Resolution', $resolution->id, [
            'title' => $resolution->title,
            'purpose' => $resolution->purpose,
        ]);

        // The register/meeting wizard dialog stays where it was opened and shows
        // its success pane; other callers land on the new paper.
        $respond = fn (string $level, string $message) => $request->boolean('_modal')
            ? redirect()->back()->with($level, $message)
            : redirect()->route('governance.resolutions.show', $resolution)->with($level, $message);

        if ($request->boolean('publish_now')) {
            $publishError = $this->publishError($request, $resolution);
            if ($publishError !== null) {
                return $respond('error', "Decision paper {$resolution->resolution_reference} was saved as a draft but not published: {$publishError}");
            }
        }

        return $respond('success', $request->boolean('publish_now')
            ? "Decision paper {$resolution->resolution_reference} created and opened for voting."
            : "Decision paper {$resolution->resolution_reference} created.");
    }

    /**
     * Open voting for a freshly saved paper when asked to publish it. Returns
     * the reason it could not be published, or null once voting is open.
     */
    protected function publishError(Request $request, Resolution $resolution): ?string
    {
        // Publishing is the open-voting command — the same ability applies.
        if (! $request->user()->can('openVoting', $resolution)) {
            return 'you are not authorised to open voting.';
        }

        $errors = $resolution->validateForPublication();
        if (! empty($errors)) {
            return implode(' ', $errors);
        }

        try {
            $deadline = $resolution->deadline ? Carbon::parse($resolution->deadline) : null;
            $this->votingService->openVoting($resolution, $deadline);
        } catch (\InvalidArgumentException|\DomainException $e) {
            return $e->getMessage();
        }

        return null;
    }

    public function update(UpdateResolutionRequest $request, Resolution $resolution)
    {
        $this->authorize('update', $resolution);

        if (!$resolution->isEditable()) {
            abort(422, 'Cannot edit paper: resolution is no longer a draft.');
        }

        $validated = $request->validated();

        if (isset($validated['expected_version']) && (int)$resolution->version_number !== (int)$validated['expected_version']) {
            $message = 'This decision paper has been updated by another user. Please reload and review the latest changes.';

            // The authoring wizard (an Inertia visit) shows the conflict inline;
            // other clients keep the explicit 409.
            if ($request->header('X-Inertia')) {
                throw ValidationException::withMessages(['expected_version' => $message]);
            }

            abort(409, $message);
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

        DB::transaction(function () use ($resolution, $updateData, $validated, $request): void {
            $resolution->update($updateData);

            // Explicit decision authority may only change while the paper is a draft.
            app(GovernanceResolutionAuthorityService::class)->applyAuthoringInput($resolution, $validated, $request->user());
        });

        GovernanceAuditService::log('resolution.updated', 'Resolution', $resolution->id, [
            'version' => $resolution->version_number,
        ]);

        if ($request->boolean('publish_now')) {
            $publishError = $this->publishError($request, $resolution);
            if ($publishError !== null) {
                return redirect()->route('governance.resolutions.show', $resolution)
                    ->with('error', 'Cannot publish paper: '.$publishError);
            }
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
        // A declaration is only possible on a paper inside the member's audience.
        $this->authorize('view', $resolution);

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

        $recordAccess = app(\App\Domain\Governance\Services\GovernanceRecordAccessService::class);
        $user = auth()->user();

        // Only papers inside the viewer's record audience, and only the
        // fields the ballot prompt needs.
        return $this->votingService
            ->getPendingVotes($boardMember->id)
            ->filter(fn (Resolution $resolution) => $recordAccess->canViewResolution($user, $resolution))
            ->map(fn (Resolution $resolution) => [
                'id' => $resolution->id,
                'resolution_reference' => $resolution->resolution_reference,
                'title' => $resolution->title,
                'deadline' => $resolution->deadline?->toIso8601String(),
            ])
            ->values()
            ->all();
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
        return $resolution->presentAttachments();
    }

    /**
     * Route permission + policy: who may author decision papers.
     */
    protected function canAuthorPapers(User $user): bool
    {
        return $user->canDo('governance.resolutions.manage') && $user->can('create', Resolution::class);
    }

    /**
     * Options for the decision-paper wizard (create and edit share one form).
     *
     * @return array{meetings: mixed, committees: mixed, users: mixed, authoritySubjects: array<string, mixed>, authoritySubjectGroups: list<array{key: string, subject_type: string, label: string}>}
     */
    protected function authoringFormProps(User $user): array
    {
        $meetingsQuery = GovernanceMeeting::query()->orderByDesc('scheduled_at');
        app(\App\Domain\Governance\Services\GovernanceRecordAccessService::class)->scopeMeetings($meetingsQuery, $user);

        return [
            'meetings' => $meetingsQuery->get(['id', 'title', 'scheduled_at']),
            'committees' => \App\Domain\Governance\Models\BoardCommittee::query()
                ->orderBy('name')
                ->get(['id', 'name']),
            'users' => User::query()
                ->whereNotNull('approved_at')
                ->orderBy('name')
                ->get(['id', 'name']),
            ...app(GovernanceResolutionAuthorityService::class)->authoringChoices($user),
        ];
    }

    /**
     * The paper's explicit approval bindings with a readable subject label.
     * The label comes from the author's own selectable options when present,
     * otherwise from the binding's recorded identity (never restricted content).
     *
     * @param  array<string, mixed>|null  $options
     * @return array<int, array<string, mixed>>
     */
    protected function presentAuthorityBindings(Resolution $resolution, ?array $options): array
    {
        return $resolution->authorityBindings()
            ->orderBy('id')
            ->get(['id', 'resolution_id', 'subject_type', 'subject_id', 'subject_revision', 'governing_body', 'board_committee_id', 'document_reference', 'document_version', 'budget_id', 'budget_line_item_id', 'amount', 'direction', 'bound_at', 'consumed_at'])
            ->map(function (GovernanceResolutionBinding $binding) use ($options): array {
                $typeLabel = Str::ucfirst(str_replace('_', ' ', (string) $binding->subject_type));
                $optionLabel = collect($options[Str::plural((string) $binding->subject_type)] ?? [])
                    ->first(fn ($option) => is_array($option) && (int) ($option['id'] ?? 0) === (int) $binding->subject_id)['label'] ?? null;

                $fallback = collect([
                    "{$typeLabel} #{$binding->subject_id}",
                    $binding->document_reference,
                    $binding->direction && $binding->amount !== null ? Str::ucfirst((string) $binding->direction).' $'.number_format((float) $binding->amount, 2) : null,
                    $binding->subject_revision !== null ? "revision {$binding->subject_revision}" : null,
                ])->filter()->implode(' · ');

                return [
                    ...$binding->toArray(),
                    'subject_type_label' => $typeLabel,
                    'subject_label' => $optionLabel ?? $fallback,
                ];
            })
            ->all();
    }

    /**
     * The frozen paper snapshot without stored attachment paths.
     */
    protected function presentPaperSnapshot(Resolution $resolution): ?array
    {
        $snapshot = $resolution->paper_snapshot;
        if (! is_array($snapshot)) {
            return null;
        }

        if (is_array($snapshot['attachments'] ?? null)) {
            $snapshot['attachments'] = collect($snapshot['attachments'])
                ->filter(fn ($row) => is_array($row))
                ->map(fn (array $row) => Arr::except($row, ['path']))
                ->values()
                ->all();
        }

        return $snapshot;
    }
}
