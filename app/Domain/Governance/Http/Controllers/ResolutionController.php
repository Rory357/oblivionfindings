<?php

namespace App\Domain\Governance\Http\Controllers;

use App\Domain\Governance\Http\Requests\StoreResolutionRequest;
use App\Domain\Governance\Http\Requests\UpdateResolutionRequest;
use App\Domain\Governance\Models\ActionItem;
use App\Domain\Governance\Models\BoardMember;
use App\Domain\Governance\Models\GovernanceMeeting;
use App\Domain\Governance\Models\GovernanceResolutionBinding;
use App\Domain\Governance\Models\GovernanceVotingProfile;
use App\Domain\Governance\Models\Resolution;
use App\Domain\Governance\Services\GovernanceAuditService;
use App\Domain\Governance\Services\GovernanceRecordAccessService;
use App\Domain\Governance\Services\GovernanceResolutionAuthorityService;
use App\Domain\Governance\Services\GovernanceVotingProfileService;
use App\Domain\Governance\Services\VotingService;
use App\Domain\Governance\Support\GovernanceLabels;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\WorkerClock;
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

        $recordAccess = app(GovernanceRecordAccessService::class);
        $user = $request->user();

        $base = Resolution::query();
        $recordAccess->scopeResolutions($base, $user);

        $query = (clone $base)->with(['meeting:id,title,scheduled_at,board_committee_id', 'committee:id,name', 'proposedBy:id,name']);

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

        $profiles = $this->profileMemo();

        // Rows carry only what the register shows — never stored attachment
        // paths, frozen snapshots or electorate lists.
        $resolutions = $query->orderByDesc('created_at')->paginate(20)->withQueryString()
            ->through(fn (Resolution $resolution) => [
                'id' => $resolution->id,
                'resolution_reference' => $resolution->resolution_reference,
                'title' => $resolution->title,
                'status' => $resolution->status,
                'outcome' => $resolution->outcome,
                'purpose' => $resolution->purpose ?? 'decision',
                'voting_threshold' => $resolution->voting_threshold,
                'applied_threshold' => $resolution->appliedThreshold($profiles($resolution)),
                'deadline' => $resolution->deadline?->toIso8601String(),
                'governance_meeting_id' => $resolution->governance_meeting_id,
                'meeting' => $resolution->meeting
                    ? ['id' => $resolution->meeting->id, 'title' => $resolution->meeting->title, 'scheduled_at' => $resolution->meeting->scheduled_at?->toIso8601String()]
                    : null,
                'committee' => $resolution->committee
                    ? ['id' => $resolution->committee->id, 'name' => $resolution->committee->name]
                    : null,
                'proposed_by' => $resolution->proposedBy ? ['name' => $resolution->proposedBy->name] : null,
            ]);

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
                'decided' => (clone $base)->whereNotNull('outcome')->count(),
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
            'voting_rules' => $this->votingRulesState($user),
            // The wizard's success pane links to the resolution it just saved.
            'created_resolution_id' => session('created_resolution_id'),
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
        $myVote?->makeHidden(['vote_hash', 'conflict_note']);

        $myConflict = $boardMember
            ? $resolution->conflictDeclarations()
                ->where('board_member_id', $boardMember->id)
                ->first()
            : null;

        /** @var User $user */
        $user = auth()->user();
        // `update` is draft-only, so edit options exist only while editable.
        $canManage = $user->can('update', $resolution);
        $canCommand = $user->canDo('governance.resolutions.manage');
        $isDecision = $resolution->isDecisionPaper();
        $formProps = $canManage ? $this->authoringFormProps($user) : [
            'meetings' => [],
            'committees' => [],
            'users' => [],
            'authoritySubjects' => null,
            'authoritySubjectGroups' => [],
        ];

        $committeeId = $resolution->board_committee_id ?? $resolution->meeting?->board_committee_id;

        return Inertia::render('Governance/Resolutions/Show', [
            'resolution' => $resolution,
            'applied_threshold' => $resolution->appliedThreshold(),
            'results' => $results,
            'my_vote' => $myVote,
            'my_conflict' => $myConflict,
            'can_vote' => $user->can('vote', $resolution) && (! $myConflict || ! $myConflict->withdrew_from_voting),
            // Why the ballot isn't offered, in plain words (open papers only).
            'ineligible_reason' => $resolution->isOpen() ? $this->votingService->ineligibleReason($resolution, $user) : null,
            // Declaring needs the vote permission (route) and a board seat (controller).
            'can_declare_conflict' => $boardMember !== null
                && $user->canDo('governance.resolutions.vote')
                && in_array($resolution->status, ['draft', 'proposed', 'open'], true),
            'can_manage' => $canManage,
            // Mirrors the route permission AND the policy ability of each command.
            'can_open_voting' => $resolution->isDraft() && $isDecision && $canCommand && $user->can('openVoting', $resolution),
            'can_publish_to_members' => $resolution->isDraft() && ! $isDecision && $canCommand && $user->can('openVoting', $resolution),
            'can_close_voting' => $resolution->status === 'open' && $canCommand && $user->can('closeVoting', $resolution),
            'can_finalize' => ($resolution->status === 'closed' || ($resolution->status === 'proposed' && ! $isDecision))
                && $canCommand && $user->can('closeVoting', $resolution),
            'voting_rules' => $this->votingRulesState($user, $committeeId ? 'committee' : 'board', $committeeId ? (int) $committeeId : null),
            'quorum' => $resolution->governance_meeting_id
                ? $this->votingService->calculateQuorum($resolution->governance_meeting_id, $resolution)
                : $this->votingService->calculateQuorum(null, $resolution),
            'attachments' => $this->presentAttachments($resolution),
            'paper_snapshot' => $this->presentPaperSnapshot($resolution),
            'authority_bindings' => $this->presentAuthorityBindings($resolution, $formProps['authoritySubjects']),
            'validation_errors' => $resolution->isDraft() ? $resolution->validateForPublication() : [],
            ...$this->presentFollowUpActions($resolution, $user),
            'next_pending_vote' => $this->nextPendingVote($resolution),
            ...$formProps,
        ]);
    }

    public function store(StoreResolutionRequest $request)
    {
        $validated = $request->validated();

        $purpose = $validated['purpose'] ?? 'decision';
        $isDecision = $purpose === 'decision';
        $votingThreshold = $this->thresholdFromInput($validated, 'simple_majority');
        $followUps = $isDecision ? array_values($validated['follow_up_actions'] ?? []) : [];

        $meetingId = $validated['meeting_id'] ?? $validated['governance_meeting_id'] ?? null;
        $context = $validated['context'] ?? $validated['description'] ?? '';

        $authority = app(GovernanceResolutionAuthorityService::class);
        $resolution = DB::transaction(function () use ($validated, $votingThreshold, $meetingId, $context, $authority, $request, $purpose, $isDecision, $followUps): Resolution {
            $resolution = Resolution::create([
                'title' => $validated['title'],
                'exact_motion' => $validated['exact_motion'] ?? null,
                'purpose' => $purpose,
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
                'follow_up_actions' => $followUps,
                // The wizard promises these become actions if the resolution
                // passes — so they must actually be created then.
                'auto_generate_actions' => $followUps !== [],
                'voting_threshold' => $votingThreshold,
                'quorum_required' => $validated['quorum_required'] ?? true,
                // Papers that don't go to a vote have no voting deadline.
                'deadline' => $isDecision ? ($validated['voting_deadline'] ?? null) : null,
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
            ? redirect()->back()->with($level, $message)->with('created_resolution_id', $resolution->id)
            : redirect()->route('governance.resolutions.show', $resolution)->with($level, $message);

        if ($request->boolean('publish_now')) {
            $publishError = $this->publishError($request, $resolution);
            if ($publishError !== null) {
                return $respond('error', $isDecision
                    ? "The resolution was saved as a draft, but voting couldn't be opened: {$publishError}"
                    : "The paper was saved as a draft, but it couldn't be published to members: {$publishError}");
            }

            return $respond('success', $isDecision
                ? 'Resolution created, and voting is now open.'
                : 'Paper created and published to board members.');
        }

        return $respond('success', $isDecision ? 'Resolution created as a draft.' : 'Paper created as a draft.');
    }

    /**
     * Open voting (For decision) or publish to members (For discussion /
     * For information) for a freshly saved paper. Returns the plain reason it
     * could not be published, or null once it is.
     */
    protected function publishError(Request $request, Resolution $resolution): ?string
    {
        // Publishing is the open-voting command — the same ability applies.
        if (! $request->user()->can('openVoting', $resolution)) {
            return 'only the chair or board secretary can publish resolutions.';
        }

        $errors = $resolution->validateForPublication();
        if (! empty($errors)) {
            return implode(' ', $errors);
        }

        try {
            if ($resolution->isDecisionPaper()) {
                $deadline = $resolution->deadline ? Carbon::parse($resolution->deadline) : null;
                $this->votingService->openVoting($resolution, $deadline);
                GovernanceAuditService::log('resolution.voting_opened', 'Resolution', $resolution->id, [
                    'deadline' => $deadline?->toIso8601String(),
                ]);
            } else {
                $this->votingService->publishToMembers($resolution);
                GovernanceAuditService::log('resolution.published', 'Resolution', $resolution->id, [
                    'purpose' => $resolution->purpose,
                ]);
            }
        } catch (\InvalidArgumentException|\DomainException $e) {
            return $e->getMessage();
        }

        return null;
    }

    public function update(UpdateResolutionRequest $request, Resolution $resolution)
    {
        $this->authorize('update', $resolution);

        if (! $resolution->isEditable()) {
            abort(422, "This resolution can't be edited because it's no longer a draft.");
        }

        $validated = $request->validated();

        if (isset($validated['expected_version']) && (int) $resolution->version_number !== (int) $validated['expected_version']) {
            $message = 'Someone else has changed this resolution since you opened it. Reload the page to see their changes before saving yours.';

            // The authoring wizard (an Inertia visit) shows the conflict inline;
            // other clients keep the explicit 409.
            if ($request->header('X-Inertia')) {
                throw ValidationException::withMessages(['expected_version' => $message]);
            }

            abort(409, $message);
        }

        $votingThreshold = (isset($validated['type']) || isset($validated['voting_threshold']))
            ? $this->thresholdFromInput($validated, (string) ($resolution->voting_threshold ?: 'simple_majority'))
            : $resolution->voting_threshold;

        $meetingId = array_key_exists('meeting_id', $validated)
            ? $validated['meeting_id']
            : (array_key_exists('governance_meeting_id', $validated) ? $validated['governance_meeting_id'] : $resolution->governance_meeting_id);

        $context = array_key_exists('context', $validated)
            ? $validated['context']
            : (array_key_exists('description', $validated) ? $validated['description'] : $resolution->context);

        $purpose = $validated['purpose'] ?? $resolution->purpose;
        $isDecision = ($purpose ?? 'decision') === 'decision';

        $followUps = array_key_exists('follow_up_actions', $validated)
            ? array_values($validated['follow_up_actions'] ?? [])
            : ($resolution->follow_up_actions ?? []);
        if (! $isDecision) {
            $followUps = [];
        }

        $deadline = array_key_exists('voting_deadline', $validated) ? $validated['voting_deadline'] : $resolution->deadline;

        $updateData = [
            'title' => $validated['title'] ?? $resolution->title,
            'exact_motion' => array_key_exists('exact_motion', $validated) ? $validated['exact_motion'] : $resolution->exact_motion,
            'purpose' => $purpose,
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
            'deadline' => $isDecision ? $deadline : null,
            'governance_meeting_id' => $meetingId,
            'board_committee_id' => array_key_exists('board_committee_id', $validated) ? $validated['board_committee_id'] : $resolution->board_committee_id,
            'follow_up_actions' => $followUps,
            'auto_generate_actions' => ! empty($followUps),
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
                    ->with('error', "Your changes were saved, but the resolution couldn't be published: {$publishError}");
            }
        }

        return redirect()->route('governance.resolutions.show', $resolution)
            ->with('success', 'Resolution updated.');
    }

    public function vote(Request $request, Resolution $resolution)
    {
        $this->authorize('vote', $resolution);

        $validated = $request->validate([
            'vote' => 'required|in:for,against,abstain',
            'vote_note' => 'nullable|string|max:2000',
            // Older ballots sent the optional reason as `conflict_note`. It was
            // always a reason for the vote, never a conflict declaration.
            'conflict_note' => 'nullable|string|max:2000',
        ], [
            'vote.required' => 'Choose For, Against or Abstain before casting your vote.',
            'vote.in' => 'Choose For, Against or Abstain before casting your vote.',
            'vote_note.max' => 'Keep the reason for your vote under 2,000 characters.',
            'conflict_note.max' => 'Keep the reason for your vote under 2,000 characters.',
        ]);

        $boardMember = BoardMember::active()
            ->where('user_id', auth()->id())
            ->first();

        if (! $boardMember) {
            return redirect()->back()->with('error', 'Only current board members can vote on resolutions.');
        }

        $note = $validated['vote_note'] ?? $validated['conflict_note'] ?? null;

        try {
            $this->votingService->castVote(
                $resolution,
                $boardMember,
                $validated['vote'],
                'electronic',
                $note,
            );
            GovernanceAuditService::log('resolution.voted', 'Resolution', $resolution->id, [
                'vote' => $validated['vote'],
                'board_member_id' => $boardMember->id,
                'has_vote_note' => ! empty($note),
            ]);

            return redirect()->back()->with('success', 'Your vote is recorded.');
        } catch (\InvalidArgumentException|\DomainException $e) {
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
        ], [
            'type.required' => 'Choose what kind of conflict of interest it is.',
            'type.in' => 'Choose what kind of conflict of interest it is.',
            'description.required' => 'Describe the conflict of interest.',
            'description.min' => 'Add a little more detail about the conflict (at least 20 characters).',
            'withdraw_from_voting.boolean' => 'Choose whether you are stepping aside from the vote.',
            'withdraw_from_discussion.boolean' => 'Choose whether you are stepping aside from the discussion.',
        ]);

        $boardMember = BoardMember::active()
            ->where('user_id', auth()->id())
            ->first();

        if (! $boardMember) {
            return redirect()->back()->with('error', 'Only current board members can declare a conflict of interest on a resolution.');
        }

        $withdraw = $validated['withdraw_from_voting'] ?? true;

        try {
            $this->votingService->declareConflict(
                $resolution,
                $boardMember,
                $validated['type'],
                $validated['description'],
                auth()->user(),
                $withdraw,
                $validated['withdraw_from_discussion'] ?? false,
            );

            GovernanceAuditService::log('resolution.conflict_declared', 'Resolution', $resolution->id, [
                'board_member_id' => $boardMember->id,
                'type' => $validated['type'],
                'withdrew_from_voting' => $withdraw,
            ]);

            return redirect()->back()->with('success', $withdraw
                ? "Your conflict of interest is recorded, and you've stepped aside from the vote."
                : 'Your conflict of interest is recorded.');
        } catch (\InvalidArgumentException|\DomainException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }
    }

    public function openVoting(Request $request, Resolution $resolution)
    {
        $this->authorize('openVoting', $resolution);

        // An optional deadline arrives as NZ wall time.
        if (is_string($request->input('deadline')) && trim((string) $request->input('deadline')) !== '') {
            try {
                $request->merge(['deadline' => WorkerClock::toUtc(trim((string) $request->input('deadline')))?->toIso8601String()]);
            } catch (\Throwable) {
                // the date rule reports it
            }
        }

        $validated = $request->validate([
            'deadline' => 'nullable|date|after:now',
        ], [
            'deadline.date' => 'Enter the voting deadline as a date and time.',
            'deadline.after' => 'Pick a voting deadline in the future.',
        ]);

        $deadline = isset($validated['deadline'])
            ? Carbon::parse($validated['deadline'])
            : null;

        try {
            $this->votingService->openVoting($resolution, $deadline);
            GovernanceAuditService::log('resolution.voting_opened', 'Resolution', $resolution->id, [
                'deadline' => $deadline?->toIso8601String(),
            ]);

            return redirect()->back()->with('success', 'Voting is now open.');
        } catch (\InvalidArgumentException|\DomainException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }
    }

    /**
     * Share a For discussion / For information paper with board members.
     * Same ability as opening voting (chair, secretary, admin).
     */
    public function publish(Request $request, Resolution $resolution)
    {
        $this->authorize('openVoting', $resolution);

        try {
            $this->votingService->publishToMembers($resolution);
            GovernanceAuditService::log('resolution.published', 'Resolution', $resolution->id, [
                'purpose' => $resolution->purpose,
            ]);

            return redirect()->back()->with('success', 'The paper is now published to board members.');
        } catch (\InvalidArgumentException|\DomainException $e) {
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

            $message = 'Voting is closed. Result: '.GovernanceLabels::label('resolution_outcome', $resolution->outcome).'.';
            $created = $resolution->outcome === 'carried' ? $resolution->actionItems()->count() : 0;
            if ($created > 0) {
                $message .= $created === 1
                    ? ' 1 follow-up action was created.'
                    : " {$created} follow-up actions were created.";
            }

            return redirect()->back()->with('success', $message);
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
        ], [
            'status.required' => 'Choose whether to mark the resolution as done or archive it.',
            'status.in' => 'Choose whether to mark the resolution as done or archive it.',
            'no_action_reason.max' => 'Keep the reason under 1,000 characters.',
        ]);

        $sharedPaper = $resolution->status === 'proposed' && ! $resolution->isDecisionPaper();

        if ($resolution->status !== 'closed' && ! $sharedPaper) {
            return redirect()->back()->with('error', 'Voting on this resolution has to close before it can be marked as done or archived.');
        }

        if (! $sharedPaper && $validated['status'] === 'implemented' && $resolution->outcome !== 'carried') {
            return redirect()->back()->with('error', 'Only resolutions that passed can be marked as done. This one\'s result is: '.GovernanceLabels::label('resolution_outcome', $resolution->outcome).'.');
        }

        try {
            DB::transaction(function () use ($resolution, $validated, $sharedPaper) {
                if ($validated['status'] === 'implemented') {
                    $sharedPaper
                        ? $resolution->markDoneWithoutVote($validated['notes'] ?? null)
                        : $resolution->markImplemented($validated['notes'] ?? null, $validated['no_action_reason'] ?? null);
                } else {
                    $resolution->markArchived($validated['notes'] ?? null);
                }
                GovernanceAuditService::log('resolution.finalized', 'Resolution', $resolution->id, [
                    'status' => $validated['status'],
                ]);
            });

            return redirect()->back()->with('success', $validated['status'] === 'implemented'
                ? 'Resolution marked as done.'
                : 'Resolution archived.');
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

        $recordAccess = app(GovernanceRecordAccessService::class);
        $user = auth()->user();

        // Only papers inside the viewer's record audience the viewer can
        // actually vote on, and only the fields the ballot prompt needs.
        return $this->votingService
            ->getPendingVotes($boardMember->id)
            ->filter(fn (Resolution $resolution) => $recordAccess->canViewResolution($user, $resolution)
                && $user->can('vote', $resolution))
            ->map(fn (Resolution $resolution) => [
                'id' => $resolution->id,
                'resolution_reference' => $resolution->resolution_reference,
                'title' => $resolution->title,
                'deadline' => $resolution->deadline?->toIso8601String(),
                'meeting_id' => $resolution->governance_meeting_id,
                // Meeting resolutions are voted on inside the meeting workspace.
                'vote_href' => $this->decisionHref($resolution),
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

        if (! $resolution->isEditable()) {
            abort(422, 'Files can only be added or removed while the resolution is a draft.');
        }

        $request->validate([
            'files' => 'required|array|min:1|max:10',
            'files.*' => [
                'required',
                'file',
                'max:20480', // 20 MB per file
                'mimes:pdf,doc,docx,xls,xlsx,ppt,pptx,jpg,jpeg,png,gif,webp,csv,txt,md',
            ],
        ], [
            'files.required' => 'Choose at least one file.',
            'files.max' => 'Add up to 10 files at a time.',
            'files.*.max' => 'Each file must be 20 MB or smaller.',
            'files.*.mimes' => 'Files must be PDF, Word, Excel, PowerPoint, an image, CSV or text.',
        ]);

        $existing = is_array($resolution->attachments) ? $resolution->attachments : [];

        foreach ($request->file('files') as $file) {
            $directory = "governance/resolutions/{$resolution->id}";
            $extension = $file->getClientOriginalExtension() ?: $file->extension();
            $storedName = Str::uuid()->toString().($extension ? ".{$extension}" : '');
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
            : redirect()->back()->with('success', count($request->file('files')) === 1 ? 'File added.' : 'Files added.');
    }

    public function deleteAttachment(Request $request, Resolution $resolution, string $attachment)
    {
        $this->authorize('update', $resolution);

        if (! $resolution->isEditable()) {
            abort(422, 'Files can only be added or removed while the resolution is a draft.');
        }

        $existing = is_array($resolution->attachments) ? $resolution->attachments : [];
        $target = collect($existing)->firstWhere('id', $attachment);

        if (! $target) {
            abort(404, "That file couldn't be found.");
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
            : redirect()->back()->with('success', 'File removed.');
    }

    public function downloadAttachment(Resolution $resolution, string $attachment)
    {
        $this->authorize('view', $resolution);

        $existing = is_array($resolution->attachments) ? $resolution->attachments : [];
        $target = collect($existing)->firstWhere('id', $attachment);

        if (! $target || empty($target['path']) || ! Storage::disk('local')->exists($target['path'])) {
            abort(404, "That file couldn't be found.");
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
     * The stored voting rule from wizard input: the tile picker's `type`
     * (ordinary / special / unanimous) or an explicit engine threshold.
     *
     * @param  array<string, mixed>  $validated
     */
    protected function thresholdFromInput(array $validated, string $default): string
    {
        if (! empty($validated['type'])) {
            return match ($validated['type']) {
                'special' => 'two_thirds',
                'three_quarters' => 'three_quarters',
                'unanimous' => 'unanimous',
                default => 'simple_majority',
            };
        }

        return ! empty($validated['voting_threshold']) ? (string) $validated['voting_threshold'] : $default;
    }

    /**
     * Whether board voting is switched on, and whether this viewer can switch
     * it on (the chair or secretary — governance settings managers).
     *
     * @return array{switched_on: bool, can_switch_on: bool}
     */
    protected function votingRulesState(User $user, string $body = 'board', ?int $committeeId = null): array
    {
        return [
            'switched_on' => app(GovernanceVotingProfileService::class)->votingIsSwitchedOn($body, $committeeId),
            'can_switch_on' => $user->canDo('governance.settings.manage'),
        ];
    }

    /**
     * Options for the decision-paper wizard (create and edit share one form).
     *
     * @return array{meetings: mixed, committees: mixed, users: mixed, authoritySubjects: array<string, mixed>, authoritySubjectGroups: list<array{key: string, subject_type: string, label: string}>, votingRules: array<string, bool>}
     */
    protected function authoringFormProps(User $user): array
    {
        $meetingsQuery = GovernanceMeeting::query()->orderByDesc('scheduled_at');
        app(GovernanceRecordAccessService::class)->scopeMeetings($meetingsQuery, $user);
        $boardProfile = app(GovernanceVotingProfileService::class)->getActiveProfile('board');

        return [
            'meetings' => $meetingsQuery->get(['id', 'title', 'scheduled_at']),
            'committees' => \App\Domain\Governance\Models\BoardCommittee::query()
                ->orderBy('name')
                ->get(['id', 'name']),
            'users' => User::query()
                ->whereNotNull('approved_at')
                ->orderBy('name')
                ->get(['id', 'name']),
            // What the wizard tells authors about votes outside a meeting.
            'votingRules' => [
                'switched_on' => (bool) $boardProfile?->isConfirmed(),
                'written_voting_permitted' => (bool) $boardProfile?->written_voting_permitted,
                'written_unanimity_required' => (bool) $boardProfile?->written_unanimity_required,
            ],
            ...app(GovernanceResolutionAuthorityService::class)->authoringChoices($user),
        ];
    }

    /**
     * The paper's explicit approval links with a readable label.
     * The label comes from the author's own selectable options when present,
     * otherwise from the link's recorded identity (never restricted content).
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
                $typeLabel = GovernanceLabels::label('authority_subject', (string) $binding->subject_type);
                $optionLabel = collect($options[Str::plural((string) $binding->subject_type)] ?? [])
                    ->first(fn ($option) => is_array($option) && (int) ($option['id'] ?? 0) === (int) $binding->subject_id)['label'] ?? null;

                $revision = $binding->subject_revision !== null
                    ? preg_replace('/^v(?=\d)/i', '', (string) $binding->subject_revision)
                    : null;

                $fallback = collect([
                    $binding->document_reference ?: $typeLabel,
                    $binding->direction && $binding->amount !== null
                        ? GovernanceLabels::label('budget_change_type', (string) $binding->direction).' of '.GovernanceLabels::money($binding->amount)
                        : null,
                ])->filter()->implode(' · ');

                return [
                    ...$binding->toArray(),
                    'subject_type_label' => $typeLabel,
                    'subject_label' => $optionLabel ?? $fallback,
                    'subject_version' => $revision,
                ];
            })
            ->all();
    }

    /**
     * Follow-up actions created from this resolution that the viewer may see,
     * plus how many more exist that they can't.
     *
     * @return array{action_items: list<array<string, mixed>>, restricted_action_items_count: int}
     */
    protected function presentFollowUpActions(Resolution $resolution, User $viewer): array
    {
        $actions = $resolution->actionItems()->with('assignedTo:id,name')->get();
        $visible = $actions
            ->filter(fn (ActionItem $action) => $viewer->can('view', $action))
            ->sortBy(fn (ActionItem $action) => $action->due_date?->timestamp ?? PHP_INT_MAX)
            ->values();
        $canOpen = $viewer->canDo('governance.actions.view');

        return [
            'action_items' => $visible->map(fn (ActionItem $action) => [
                'id' => $action->id,
                'reference' => $action->action_reference ?? "ACT-{$action->id}",
                'title' => $action->title ?: $action->description,
                'status' => $action->status,
                'due_date' => $action->due_date?->toDateString(),
                'assignee_name' => $action->assignedTo?->name,
                'is_mine' => (int) $action->assigned_to === (int) $viewer->id,
                'can_open' => $canOpen,
                'open_url' => $canOpen ? route('governance.actions.show', $action, false) : null,
            ])->all(),
            'restricted_action_items_count' => $actions->count() - $visible->count(),
        ];
    }

    /** The next resolution still waiting for this member's vote. */
    protected function nextPendingVote(Resolution $current): ?array
    {
        $next = collect($this->getMyPendingVotes())
            ->first(fn (array $pending) => (int) $pending['id'] !== (int) $current->id);

        return $next ? ['id' => $next['id'], 'title' => $next['title'], 'href' => $next['vote_href']] : null;
    }

    /**
     * Canonical place to read and vote on a resolution: the meeting workspace
     * for meeting resolutions (GovernanceWorkQuery::decisionWorkspaceHref),
     * otherwise the resolution record.
     */
    protected function decisionHref(Resolution $resolution): string
    {
        return $resolution->governance_meeting_id
            ? "/governance/meetings/{$resolution->governance_meeting_id}?tab=resolutions&paper={$resolution->id}"
            : "/governance/resolutions/{$resolution->id}";
    }

    /**
     * Memoised fallback voting profile per governing body, so the register
     * can show each row's applied rule without a query per row.
     *
     * @return callable(Resolution): ?GovernanceVotingProfile
     */
    protected function profileMemo(): callable
    {
        $cache = [];
        $service = app(GovernanceVotingProfileService::class);

        return function (Resolution $resolution) use (&$cache, $service): ?GovernanceVotingProfile {
            $committeeId = $resolution->board_committee_id ?? $resolution->meeting?->board_committee_id;
            $key = $committeeId ? "committee:{$committeeId}" : 'board';
            if (! array_key_exists($key, $cache)) {
                $cache[$key] = $service->getActiveProfile($committeeId ? 'committee' : 'board', $committeeId ? (int) $committeeId : null);
            }

            return $cache[$key];
        };
    }

    /**
     * The frozen paper snapshot without stored attachment paths.
     */
    protected function presentPaperSnapshot(Resolution $resolution): ?array
    {
        return $resolution->presentPaperSnapshot();
    }
}
