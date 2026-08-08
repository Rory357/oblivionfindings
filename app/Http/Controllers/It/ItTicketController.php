<?php

namespace App\Http\Controllers\It;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\It\Data\ItTransitionInput;
use App\Domain\It\Enums\ItWorkflowState;
use App\Domain\It\ItStaffDirectory;
use App\Domain\It\Presenters\ItTicketActivityPresenter;
use App\Domain\It\Presenters\ItTicketContextPresenter;
use App\Domain\It\Presenters\ItTicketRoutingPresenter;
use App\Domain\It\Services\ItEmailDeliveryService;
use App\Domain\It\Services\ItLinkedContextOptions;
use App\Domain\It\Services\ItTicketApprovalService;
use App\Domain\It\Services\ItTicketDeviceContextService;
use App\Domain\It\Services\ItTicketInteractionService;
use App\Domain\It\Services\ItTicketMergeService;
use App\Domain\It\Services\ItTicketTriageService;
use App\Domain\It\Services\ItWorkAccessService;
use App\Domain\It\Services\ItWorkTransitionService;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Http\Controllers\Concerns\ServesPrivateAttachments;
use App\Http\Controllers\Controller;
use App\Http\Controllers\It\Concerns\BuildsItOptions;
use App\Http\Requests\It\BulkTicketActionRequest;
use App\Http\Requests\It\CloseTicketRequest;
use App\Http\Requests\It\DecideApprovalRequest;
use App\Http\Requests\It\LinkTicketDeviceRequest;
use App\Http\Requests\It\MergeTicketRequest;
use App\Http\Requests\It\ReopenTicketRequest;
use App\Http\Requests\It\RequestApprovalRequest;
use App\Http\Requests\It\StoreTicketCommentRequest;
use App\Http\Requests\It\SubmitCsatRequest;
use App\Http\Requests\It\TransitionItWorkRequest;
use App\Models\ItAttachment;
use App\Models\ItTicket;
use App\Models\ItTicketApproval;
use App\Models\ItTicketComment;
use App\Models\User;
use App\Notifications\It\TicketApprovalNotification;
use App\Notifications\It\TicketReopenedNotification;
use App\Notifications\It\TicketRepliedNotification;
use App\Services\UserSiteAccessService;
use DomainException;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

/**
 * The ticket workspace (/it/tickets/{ticket}): the conversation thread,
 * activity timeline and properties rail where tickets actually get worked.
 * Requesters reach their OWN tickets only, with internal notes stripped
 * server-side — the payload is the privacy boundary, never the UI.
 */
class ItTicketController extends Controller
{
    use BuildsItOptions, ServesPrivateAttachments;

    public function __construct(
        private readonly ItTicketActivityPresenter $activityPresenter,
        private readonly ItTicketContextPresenter $contextPresenter,
        private readonly ItTicketRoutingPresenter $routingPresenter,
        private readonly ItWorkTransitionService $transitionService,
        private readonly ItTicketApprovalService $approvalService,
        private readonly ItTicketInteractionService $interactionService,
        private readonly ItTicketMergeService $mergeService,
        private readonly ItTicketTriageService $triageService,
        private readonly ItTicketDeviceContextService $deviceContext,
        private readonly ItEmailDeliveryService $emailDeliveries,
        private readonly ItWorkAccessService $workAccess,
        private readonly ItLinkedContextOptions $linkedContextOptions,
    ) {}

    public function show(Request $request, ItTicket $ticket)
    {
        $payload = $this->showPayload($request, $ticket);

        // One route, two callers: the detail page (Inertia) and the
        // quick-peek drawer (axios). Policy + internal-note stripping run
        // identically for both — the payload IS the privacy boundary.
        if (! $request->header('X-Inertia') && $request->wantsJson()) {
            return response()->json($payload);
        }

        return Inertia::render('it/tickets/show', $payload);
    }

    public function linkDevice(LinkTicketDeviceRequest $request, ItTicket $ticket)
    {
        try {
            $changed = $this->deviceContext->add(
                $ticket,
                (int) $request->validated('device_id'),
                $request->user(),
            );
        } catch (DomainException $exception) {
            return redirect()->back()->with('error', $exception->getMessage());
        }

        return redirect()->back()->with(
            'success',
            $changed ? 'Device linked to ticket.' : 'Device is already linked to this ticket.',
        );
    }

    public function unlinkDevice(Request $request, ItTicket $ticket, Device $device)
    {
        abort_unless($this->workAccess->canWork($request->user(), $ticket), 404);
        $this->authorize('update', $ticket);

        try {
            $changed = $this->deviceContext->remove($ticket, $device, $request->user());
        } catch (DomainException $exception) {
            return redirect()->back()->with('error', $exception->getMessage());
        }

        return redirect()->back()->with(
            'success',
            $changed ? 'Device link removed.' : 'Device was already unlinked.',
        );
    }

    /** @return array<string, mixed> */
    private function showPayload(Request $request, ItTicket $ticket): array
    {
        $user = $request->user();
        abort_unless($this->workAccess->canView($user, $ticket), 404);
        $this->authorize('view', $ticket);

        $isAgent = $user->canDo('it.view') || $user->canDo('it.manage');
        $canManage = $this->workAccess->canWork($user, $ticket);
        $canLinkDevices = $canManage && $user->canDo('securityDevices.devices.view');
        $isRequester = (int) $ticket->requester_user_id === (int) $user->id;
        $canComment = ! $ticket->isMerged() && in_array($ticket->status, ItTicket::OPEN_STATUSES, true);
        $replyUnavailableReason = match (true) {
            $canComment => null,
            $ticket->isMerged() => $ticket->mergedInto?->reference
                ? "Continue the conversation on {$ticket->mergedInto->reference}."
                : 'Continue the conversation on the surviving ticket.',
            default => 'Reopen this ticket before adding another reply.',
        };

        $ticket->load([
            'requester:id,name',
            'assignee:id,name',
            'owner:id,name',
            'team:id,name',
            'queue:id,name',
            'watchers:id,name',
            'asset:id,name,asset_tag',
            'site:id,name,type,is_active,archived,archived_at',
            'service:id,name,is_active',
            'provisioningRequest:id,item,status',
            'attachments',
        ]);

        $mapAttachment = fn (ItAttachment $a) => [
            'id' => $a->id,
            'name' => $a->original_name,
            'size' => $a->size,
            'url' => "/it/attachments/{$a->id}",
        ];

        $requesterProfile = HrEmployeeProfile::query()
            ->where('user_id', $ticket->requester_user_id)
            ->first();
        $staffProfileHrefs = $this->staffProfileHrefs($user, collect([
            $ticket->requester_user_id,
            $ticket->assigned_to_user_id,
            ...$ticket->watchers->pluck('id')->all(),
        ])->all());
        $assetHref = $ticket->asset
            && app(SecurityDevicesAccessService::class)->assignableAsset($user, (int) $ticket->asset->id)
                ? "/fleet-assets/assets/{$ticket->asset->id}"
                : null;
        $siteHref = $ticket->site && Gate::forUser($user)->allows('view', $ticket->site)
            ? "/sites/{$ticket->site->id}"
            : null;

        $comments = $ticket->comments()
            ->with(['author:id,name', 'attachments'])
            ->when(! $isAgent, fn ($q) => $q->publicOnly())
            ->orderBy('created_at')
            ->get()
            ->map(fn (ItTicketComment $c) => [
                'id' => $c->id,
                'body' => $c->body,
                'is_internal' => $c->is_internal,
                'author' => [
                    'id' => $c->author?->id,
                    'name' => $c->author?->name ?? 'Unknown',
                    'is_requester' => $c->author_user_id === $ticket->requester_user_id,
                ],
                'attachments' => $c->attachments->map($mapAttachment)->values()->all(),
                'at' => $c->created_at?->toIso8601String(),
                'at_human' => $c->created_at?->diffForHumans(short: true),
            ])
            ->values()
            ->all();

        // §P-S3 approval state for the rail — the latest request + who can act.
        $latestApproval = $ticket->requires_approval
            ? $ticket->approvals()->with('requester:id,name', 'approver:id,name')->first()
            : null;
        $pendingApproval = $latestApproval?->status === 'pending' ? $latestApproval : null;

        return [
            'ticket' => [
                'id' => $ticket->id,
                'reference' => $ticket->reference,
                'title' => $ticket->title,
                'description' => $ticket->description,
                'work_type' => $ticket->work_type,
                'service' => $ticket->service
                    ? ['id' => $ticket->service->id, 'name' => $ticket->service->name]
                    : null,
                'category' => $ticket->category,
                'subcategory' => $ticket->subcategory,
                'priority' => $ticket->priority,
                'status' => $ticket->status,
                'workflow_state' => $ticket->workflow_state,
                'waiting' => $this->waitingPayload($ticket, $canManage),
                'source' => $ticket->source,
                'sla_state' => $ticket->sla_state,
                'first_response_due_at' => $ticket->first_response_due_at?->toIso8601String(),
                'resolution_due_at' => $ticket->resolution_due_at?->toIso8601String(),
                'first_responded_at' => $ticket->first_responded_at?->toIso8601String(),
                'requester' => [
                    'id' => $ticket->requester?->id,
                    'name' => $ticket->requester?->name ?? 'Unknown',
                    'role' => $requesterProfile?->position_title ?? $requesterProfile?->position_role,
                    'href' => $staffProfileHrefs[(int) $ticket->requester_user_id] ?? null,
                ],
                'assignee' => $ticket->assignee
                    ? [
                        'id' => $ticket->assignee->id,
                        'name' => $ticket->assignee->name,
                        'href' => $staffProfileHrefs[(int) $ticket->assignee->id] ?? null,
                    ]
                    : null,
                ...($isAgent ? ['routing' => $this->routingPresenter->present($ticket)] : []),
                'watchers' => $ticket->watchers
                    ->map(fn ($w) => [
                        'id' => $w->id,
                        'name' => $w->name,
                        'href' => $staffProfileHrefs[(int) $w->id] ?? null,
                    ])
                    ->values()
                    ->all(),
                'asset' => $ticket->asset
                    ? [
                        'id' => $ticket->asset->id,
                        'name' => $ticket->asset->name,
                        'tag' => $ticket->asset->asset_tag,
                        'href' => $assetHref,
                    ]
                    : null,
                'site' => $ticket->site
                    ? ['id' => $ticket->site->id, 'name' => $ticket->site->name, 'href' => $siteHref]
                    : null,
                'is_organisation_wide' => (bool) $ticket->is_organisation_wide,
                'provisioning_request' => $ticket->provisioningRequest
                    ? [
                        'id' => $ticket->provisioningRequest->id,
                        'item' => $ticket->provisioningRequest->item,
                        'status' => $ticket->provisioningRequest->status,
                    ]
                    : null,
                'attachments' => $ticket->attachments->map($mapAttachment)->values()->all(),
                // CSAT result — only once submitted (§K); shown in the rail.
                'csat' => $ticket->csat_submitted_at
                    ? [
                        'score' => (int) $ticket->csat_score,
                        'comment' => $ticket->csat_comment,
                        'submitted_at' => $ticket->csat_submitted_at->toIso8601String(),
                    ]
                    : null,
                'created_at' => $ticket->created_at?->toIso8601String(),
                'created_human' => $ticket->created_at?->diffForHumans(short: true),
                'updated_at' => $ticket->updated_at?->toIso8601String(),
                'resolved_at' => $ticket->resolved_at?->toIso8601String(),
                'monitoring_recovered_at' => $ticket->monitoring_recovered_at?->toIso8601String(),
                'closed_at' => $ticket->closed_at?->toIso8601String(),
                // §P-S2: the survivor this ticket was folded into, for the banner.
                'merged_into' => $ticket->mergedInto
                    ? [
                        'id' => $ticket->mergedInto->id,
                        'reference' => $ticket->mergedInto->reference,
                        'title' => $ticket->mergedInto->title,
                    ]
                    : null,
                // §P-S3 approval — flag + the latest request, for the rail.
                'requires_approval' => (bool) $ticket->requires_approval,
                'approval' => $latestApproval ? [
                    'id' => $latestApproval->id,
                    'status' => $latestApproval->status,
                    'requested_by_name' => $latestApproval->requester?->name,
                    'approver_name' => $latestApproval->approver?->name,
                    'reason' => $latestApproval->reason,
                    'requested_at' => $latestApproval->created_at?->toIso8601String(),
                    'decided_at' => $latestApproval->decided_at?->toIso8601String(),
                ] : null,
            ],
            'comments' => $comments,
            'events' => $this->activityPresenter->present($ticket, $user),
            'linked_context' => $this->contextPresenter->present($ticket, $user),
            'assignees' => $canManage ? $this->staffUserOptions($user, $ticket) : [],
            // Rail picker over the canonical (fleet-)assets register — never
            // a parallel IT register. Agents only.
            'assetOptions' => $canManage ? $this->assetOptions($user, $ticket) : [],
            'deviceOptions' => $canLinkDevices
                ? $this->linkedContextOptions->devices($user, $ticket)
                : [],
            'siteOptions' => $canManage
                ? collect($this->linkedContextOptions->sites($user))
                    ->when(
                        $ticket->site,
                        fn ($options) => $options->contains('id', $ticket->site->id)
                            ? $options
                            : $options->push(['id' => $ticket->site->id, 'name' => $ticket->site->name]),
                    )
                    ->sortBy('name')
                    ->values()
                    ->all()
                : [],
            'serviceOptions' => $canManage ? $this->linkedContextOptions->services() : [],
            'teamOptions' => $canManage ? $this->linkedContextOptions->teams() : [],
            // §I composer deflection: published articles an agent can reference
            // as they type a reply. Agents (it.view) only — requesters already
            // met the KB at raise time and their payload stays lean.
            'kbSuggestions' => $isAgent ? $this->kbSuggestions() : [],
            // §P-S2 merge picker: recent live tickets an agent can fold this one
            // into. Agents only; excludes self and already-merged tickets.
            'mergeTargets' => $canManage
                ? $this->workAccess->applyViewScope(ItTicket::query(), $user)
                    ->whereIn('status', ItTicket::OPEN_STATUSES)
                    ->whereNull('merged_into_ticket_id')
                    ->where('id', '!=', $ticket->id)
                    ->where('requester_user_id', $ticket->requester_user_id)
                    ->latest('id')
                    ->limit(100)
                    ->get([
                        'id',
                        'reference',
                        'title',
                        'priority',
                        'status',
                        'requester_user_id',
                        'requested_for_user_id',
                    ])
                    ->filter(fn (ItTicket $candidate) => $this->workAccess->canWork($user, $candidate)
                        && $this->mergeService->sharesConversationAudience($ticket, $candidate))
                    ->take(50)
                    ->map(fn (ItTicket $t) => [
                        'id' => $t->id,
                        'reference' => $t->reference,
                        'title' => $t->title,
                        'priority' => $t->priority,
                        'status' => $t->status,
                    ])
                    ->all()
                : [],
            'can' => [
                'manage' => $canManage,
                'linkDevices' => $canLinkDevices,
                'assignApplicationWide' => $canManage
                    && $this->workAccess->canAssignScope($user, null, true),
                'view' => $isAgent,
                'internal' => $canManage,
                'comment' => $canComment,
                'reopen' => $user->can('reopen', $ticket),
                'watching' => $ticket->watchers->contains('id', $user->id),
                // The requester may rate their own resolved ticket (§K).
                'rate' => $isRequester && $ticket->status === 'resolved',
                // Fold a duplicate into another live ticket (§P-S2). Agents only.
                'merge' => $canManage && ! $ticket->isMerged() && $ticket->status !== 'closed',
                // Approval affordances (§P-S3).
                'requestApproval' => (bool) $user->can('requestApproval', $ticket),
                'decideApproval' => $pendingApproval !== null && (bool) $user->can('decide', $pendingApproval),
            ],
            'replyUnavailableReason' => $replyUnavailableReason,
        ];
    }

    /**
     * Resolve only current staff profiles that the viewer can actually open.
     * The ticket remains the source of names; this map grants no extra HR data.
     *
     * @param  array<int, mixed>  $userIds
     * @return array<int, string>
     */
    private function staffProfileHrefs(User $viewer, array $userIds): array
    {
        if (! $viewer->canDo('hr.employees.viewAny')) {
            return [];
        }

        $userIds = collect($userIds)
            ->filter(fn (mixed $id): bool => is_numeric($id) && (int) $id > 0)
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
        if ($userIds === []) {
            return [];
        }

        $visibleUsers = User::query()->whereKey($userIds)->select('users.id');
        app(UserSiteAccessService::class)->applyStaffScope($visibleUsers, $viewer);

        return HrEmployeeProfile::query()
            ->whereIn('user_id', $visibleUsers)
            ->pluck('id', 'user_id')
            ->mapWithKeys(fn (mixed $profileId, mixed $userId): array => [
                (int) $userId => "/hr/people/{$profileId}",
            ])
            ->all();
    }

    /** @return array<string, mixed>|null */
    private function waitingPayload(ItTicket $ticket, bool $canManage): ?array
    {
        if ($ticket->status !== 'waiting') {
            return null;
        }

        $payload = [
            'party' => $canManage
                ? ($ticket->waiting_party ?: 'other')
                : ($ticket->waiting_party === 'requester' ? 'requester' : 'other'),
            'since' => $ticket->waiting_since?->toIso8601String(),
            'since_human' => $ticket->waiting_since?->diffForHumans(short: true),
        ];

        if ($canManage) {
            $payload['reason'] = $ticket->waiting_reason;
            $payload['next_action'] = $ticket->next_action;
        }

        return $payload;
    }

    public function storeComment(StoreTicketCommentRequest $request, ItTicket $ticket)
    {
        $user = $request->user();
        abort_unless($this->workAccess->canView($user, $ticket), 404);
        abort_unless($user->can('comment', $ticket), 403);
        if ($request->boolean('is_internal')) {
            abort_unless($this->workAccess->canWork($user, $ticket), 403);
        }
        $isInternal = $request->boolean('is_internal');
        try {
            $result = $this->interactionService->addComment(
                $ticket,
                $user,
                (string) $request->validated('body'),
                $isInternal,
                array_values(array_filter((array) $request->file('attachments'))),
            );
        } catch (DomainException $exception) {
            return redirect()->back()->with('error', $exception->getMessage());
        }
        $ticket = $result['ticket'];
        $comment = $result['comment'];
        $isRequester = $result['is_requester'];

        // Public replies notify the other side of the conversation; internal
        // notes notify nobody (they do not exist for requesters).
        if (! $isInternal) {
            if ($isRequester) {
                $recipients = $ticket->watchers
                    ->when($ticket->assignee, fn ($c) => $c->push($ticket->assignee))
                    ->unique('id')
                    ->reject(fn ($u) => $u->id === $user->id);
                $this->emailDeliveries->send(
                    $recipients,
                    new TicketRepliedNotification($ticket, 'agent_side', $comment->id),
                );
            } elseif ($ticket->requester && $ticket->requester_user_id !== $user->id) {
                $this->emailDeliveries->send(
                    $ticket->requester,
                    new TicketRepliedNotification($ticket, 'requester', $comment->id),
                );
            }
        }

        return redirect()->back()->with('success', $isInternal ? 'Internal note added.' : 'Reply sent.');
    }

    /**
     * Authorised download for thread evidence. The parent write's audience
     * is the read audience: ticket files follow the ticket policy; comment
     * files additionally hide with their internal note.
     */
    public function downloadAttachment(Request $request, ItAttachment $attachment)
    {
        $user = $request->user();
        $parent = $attachment->attachable;

        if ($parent instanceof ItTicketComment) {
            abort_unless($this->workAccess->canView($user, $parent->ticket), 404);
            $this->authorize('view', $parent->ticket);
            if ($parent->is_internal) {
                abort_unless($this->workAccess->canWork($user, $parent->ticket), 404);
            }
        } elseif ($parent instanceof ItTicket) {
            abort_unless($this->workAccess->canView($user, $parent), 404);
            $this->authorize('view', $parent);
        } else {
            // KB attachments arrive with the Knowledge tab; orphans 404.
            abort(404);
        }

        return $this->streamPrivateAttachment(
            null,
            $attachment->path,
            $attachment->original_name,
            $attachment->mime,
        );
    }

    public function transition(TransitionItWorkRequest $request, ItTicket $ticket)
    {
        $user = $request->user();
        abort_unless($this->workAccess->canWork($user, $ticket), 404);
        abort_unless($user->can('update', $ticket), 403);
        $validated = $request->validated();

        try {
            $this->transitionService->transition(
                $ticket,
                new ItTransitionInput(
                    actor: $user,
                    to: ItWorkflowState::from((string) $validated['workflow_state']),
                    reason: $validated['reason'] ?? null,
                    waitingParty: $validated['waiting_party'] ?? null,
                    nextAction: $validated['next_action'] ?? null,
                    resolutionCode: $validated['resolution_code'] ?? null,
                    resolutionSummary: $validated['resolution_summary'] ?? null,
                    source: 'workspace',
                ),
            );
        } catch (DomainException $exception) {
            return redirect()->back()->with('error', $exception->getMessage());
        }

        return redirect()->back()->with('success', "Updated {$ticket->reference}.");
    }

    /** Close a settled (or abandoned) ticket — terminal until reopened. */
    public function close(CloseTicketRequest $request, ItTicket $ticket)
    {
        $user = $request->user();

        try {
            $this->triageService->close(
                $ticket,
                $user,
                (string) $request->validated('reason'),
            );
        } catch (DomainException $exception) {
            return redirect()->back()->with('error', $exception->getMessage());
        }

        return redirect()->back()->with('success', "Closed {$ticket->reference}.");
    }

    /**
     * Bring a settled ticket back: agents anytime, the requester within
     * 7 days of resolution (ItTicketPolicy::reopen).
     */
    public function reopen(ReopenTicketRequest $request, ItTicket $ticket)
    {
        $user = $request->user();

        try {
            $result = $this->interactionService->reopenWithReason(
                $ticket,
                $user,
                (string) $request->validated('reason'),
            );
        } catch (DomainException $exception) {
            return redirect()->back()->with('error', $exception->getMessage());
        }
        $ticket = $result['ticket'];

        $recipients = $ticket->watchers
            ->when($ticket->assignee, fn ($collection) => $collection->push($ticket->assignee))
            ->unique('id')
            ->reject(fn ($recipient) => (int) $recipient->id === (int) $user->id);
        $this->emailDeliveries->send($recipients, new TicketReopenedNotification($ticket));

        return redirect()->back()->with('success', "Reopened {$ticket->reference}.");
    }

    /**
     * Fold a duplicate SOURCE ticket into a TARGET survivor: the conversation
     * and watchers move across, the source closes as merged, and both
     * timelines get a `merged` marker. The locked lifecycle keeps the same
     * requester audience private and preserves each ticket's own history.
     */
    public function merge(MergeTicketRequest $request, ItTicket $ticket)
    {
        $user = $request->user();
        $target = $request->targetTicket();
        abort_unless($target instanceof ItTicket, 404);
        abort_unless(
            $this->workAccess->canWork($user, $ticket)
                && $this->workAccess->canWork($user, $target),
            404,
        );
        try {
            $target = $this->mergeService->merge(
                $ticket,
                $target,
                $user,
                $request->validated('reason'),
            );
        } catch (DomainException $exception) {
            return redirect()->back()->with('error', $exception->getMessage());
        }

        return redirect()
            ->route('it.tickets.show', $target)
            ->with('success', "Merged {$ticket->reference} into {$target->reference}.");
    }

    /**
     * Raise a sign-off request on a ticket whose category needs approval
     * (§P-S3). Notifies the other agents (never the requester) and logs it.
     * Canonical access is concealed before the locked lifecycle revalidates it.
     */
    public function requestApproval(RequestApprovalRequest $request, ItTicket $ticket)
    {
        $user = $request->user();
        abort_unless($this->workAccess->canWork($user, $ticket), 404);
        try {
            $approval = $this->approvalService->request(
                $ticket,
                $user,
                $request->validated('reason'),
            );
        } catch (DomainException $exception) {
            return redirect()->back()->with('error', $exception->getMessage());
        }

        $ticket = $approval->ticket;

        // Every agent who could sign off, except the one who asked.
        $approvers = ItStaffDirectory::agentsForTicket($ticket)
            ->reject(fn (User $u) => $u->id === $user->id);
        if ($approvers->isNotEmpty()) {
            $this->emailDeliveries->send($approvers, new TicketApprovalNotification($ticket, 'requested'));
        }

        return redirect()->back()->with('success', "Approval requested for {$ticket->reference}.");
    }

    /**
     * Record a manager's verdict on a pending request (§P-S3) and tell the
     * agent who asked. The locked lifecycle revalidates pending state and
     * separation of duties before writing the decision.
     */
    public function decideApproval(DecideApprovalRequest $request, ItTicketApproval $approval)
    {
        $user = $request->user();
        $ticket = $approval->ticket;
        abort_unless($ticket && $this->workAccess->canWork($user, $ticket), 404);
        abort_if((int) $approval->requested_by === (int) $user->id, 403);

        try {
            $approval = $this->approvalService->decide(
                $approval,
                $user,
                $request->validated('decision'),
                $request->validated('reason'),
            );
        } catch (DomainException $exception) {
            return redirect()->back()->with('error', $exception->getMessage());
        }

        $ticket = $approval->ticket;
        $status = $approval->status;

        $requester = User::find($approval->requested_by);
        if ($requester) {
            $this->emailDeliveries->send($requester, new TicketApprovalNotification($ticket, $status));
        }

        return redirect()->back()->with('success', "Approval {$status} for {$ticket->reference}.");
    }

    /**
     * CSAT (§K): the requester rates the resolution 1–5 (+ optional comment).
     * The first submission receives the immutable stamp; later edits retain it
     * and receive their own explicit change trail.
     */
    public function csat(SubmitCsatRequest $request, ItTicket $ticket)
    {
        $user = $request->user();
        abort_unless($this->workAccess->canView($user, $ticket), 404);
        abort_unless($user->can('csat', $ticket), 403);
        $this->interactionService->submitCsat(
            $ticket,
            $user,
            (int) $request->validated('score'),
            $request->validated('comment') ?: null,
        );

        return redirect()->back()->with('success', 'Thanks — your feedback helps IT improve.');
    }

    public function watch(Request $request, ItTicket $ticket)
    {
        $user = $request->user();
        abort_unless($user, 403);
        $this->interactionService->watch($ticket, $user);

        return redirect()->back()->with('success', "Watching {$ticket->reference}.");
    }

    public function unwatch(Request $request, ItTicket $ticket)
    {
        $user = $request->user();
        abort_unless($user, 403);
        $this->interactionService->unwatch($ticket, $user);

        return redirect()->back()->with('success', "Stopped watching {$ticket->reference}.");
    }

    /* ================================================================== */
    /*  Bulk actions (§F2) */
    /* ================================================================== */

    /**
     * One action over many tickets: assign, set priority (restamps the SLA
     * clock), set a working status (waiting transitions bank the pause), or
     * close. Inaccessible IDs silently drop out of the canonical work-access
     * query; settled tickets are skipped rather than mutated — the flash
     * reports both as "unchanged". One event row per actual change, same
     * payload shape as the single-ticket routes.
     */
    public function bulk(BulkTicketActionRequest $request)
    {
        $user = $request->user();
        $validated = $request->validated();
        $action = (string) $validated['action'];

        $assignee = null;
        if ($action === 'assign' && ! empty($validated['assigned_to_user_id'])) {
            $assignee = User::query()->find((int) $validated['assigned_to_user_id']);
            if (! $assignee || ! ItStaffDirectory::agents()->contains('id', $assignee->id)) {
                return redirect()->back()->with('error', 'Choose a current IT technician.');
            }
        }

        $tickets = $this->workAccess->applyViewScope(ItTicket::query(), $user)
            ->whereIn('id', $validated['ids'])
            ->get()
            ->filter(fn (ItTicket $ticket) => $this->workAccess->canWork($user, $ticket));

        $updated = 0;
        $skipped = count($validated['ids']) - $tickets->count();

        foreach ($tickets as $ticket) {
            $changed = match ($action) {
                'assign' => $this->triageService->bulkUpdate($ticket, $user, [
                    'assigned_to_user_id' => $assignee?->id,
                ], 'bulk'),
                'priority' => $this->triageService->bulkUpdate($ticket, $user, [
                    'priority' => (string) $validated['priority'],
                ], 'bulk'),
                'status' => $this->triageService->bulkUpdate($ticket, $user, [
                    'status' => (string) $validated['status'],
                    ...Arr::only($validated, ['waiting_party', 'waiting_reason', 'next_action']),
                ], 'bulk'),
                'close' => $this->triageService->close(
                    $ticket,
                    $user,
                    (string) $validated['reason'],
                    source: 'bulk_close',
                    staleIsUnchanged: true,
                ),
                default => false,
            };
            $changed ? $updated++ : $skipped++;
        }

        $label = ['assign' => 'assigned', 'priority' => 'reprioritised', 'status' => 'updated', 'close' => 'closed'][$action];

        return redirect()->back()->with(
            'success',
            "{$updated} ticket(s) {$label}".($skipped > 0 ? " · {$skipped} unchanged" : '').'.',
        );
    }
}
