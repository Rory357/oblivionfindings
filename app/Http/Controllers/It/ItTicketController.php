<?php

namespace App\Http\Controllers\It;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\It\Data\ItBulkActionResult;
use App\Domain\It\Data\ItTicketCommentCancellationResult;
use App\Domain\It\Data\ItTransitionInput;
use App\Domain\It\Enums\ItWorkflowState;
use App\Domain\It\Exceptions\ItSettlementBlocked;
use App\Domain\It\Exceptions\ItTicketCommandConflict;
use App\Domain\It\Exceptions\ItTicketDraftException;
use App\Domain\It\ItStaffDirectory;
use App\Domain\It\Presenters\ItTicketActivityPresenter;
use App\Domain\It\Presenters\ItTicketApprovalPresenter;
use App\Domain\It\Presenters\ItTicketCommentDeliveryPresenter;
use App\Domain\It\Presenters\ItTicketContextPresenter;
use App\Domain\It\Presenters\ItTicketConversationPresenter;
use App\Domain\It\Presenters\ItTicketRoutingPresenter;
use App\Domain\It\Services\ItEmailDeliveryService;
use App\Domain\It\Services\ItLinkedContextOptions;
use App\Domain\It\Services\ItSlaReadService;
use App\Domain\It\Services\ItTicketDeviceContextService;
use App\Domain\It\Services\ItTicketDraftAttachmentService;
use App\Domain\It\Services\ItTicketInteractionService;
use App\Domain\It\Services\ItTicketMergeService;
use App\Domain\It\Services\ItTicketRoutingService;
use App\Domain\It\Services\ItTicketTriageService;
use App\Domain\It\Services\ItWorkAccessService;
use App\Domain\It\Services\ItWorkTaskReadinessService;
use App\Domain\It\Services\ItWorkTransitionService;
use App\Domain\Monitoring\Services\MonitoringTechnicalSummary;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Http\Controllers\Concerns\ServesPrivateAttachments;
use App\Http\Controllers\Controller;
use App\Http\Controllers\It\Concerns\BuildsItOptions;
use App\Http\Requests\It\BulkTicketActionRequest;
use App\Http\Requests\It\CancelItTicketCommentCommandRequest;
use App\Http\Requests\It\CloseTicketRequest;
use App\Http\Requests\It\ConfirmTicketResolutionRequest;
use App\Http\Requests\It\LinkTicketDeviceRequest;
use App\Http\Requests\It\MergeTicketRequest;
use App\Http\Requests\It\PreviewTicketMergeRequest;
use App\Http\Requests\It\ReadTicketMergeCommandRequest;
use App\Http\Requests\It\RecoverItTicketCommentCommandRequest;
use App\Http\Requests\It\ReopenTicketRequest;
use App\Http\Requests\It\StoreTicketCommentRequest;
use App\Http\Requests\It\SubmitCsatRequest;
use App\Http\Requests\It\TransitionItWorkRequest;
use App\Http\Requests\It\UpdateTicketWatcherRequest;
use App\Http\Requests\It\ValidateItMergeCandidateRequest;
use App\Jobs\DispatchItTicketNotifications;
use App\Models\ItAttachment;
use App\Models\ItTicket;
use App\Models\ItTicketCommandReceipt;
use App\Models\ItTicketComment;
use App\Models\ItTicketDraft;
use App\Models\User;
use App\Notifications\It\TicketReopenedNotification;
use App\Notifications\It\TicketRepliedNotification;
use App\Services\UserSiteAccessService;
use DomainException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
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
        private readonly ItTicketInteractionService $interactionService,
        private readonly ItTicketMergeService $mergeService,
        private readonly ItTicketTriageService $triageService,
        private readonly ItTicketDeviceContextService $deviceContext,
        private readonly ItEmailDeliveryService $emailDeliveries,
        private readonly ItWorkAccessService $workAccess,
        private readonly ItLinkedContextOptions $linkedContextOptions,
        private readonly ItSlaReadService $slaRead,
    ) {}

    public function show(Request $request, ItTicket $ticket)
    {
        // Preserve the drawer's actor/record-bound JSON contract. Browser links
        // follow the authorized survivor; historical commands never redirect.
        if ($ticket->isMerged() && ($request->header('X-Inertia') || ! $request->wantsJson())) {
            $destination = $this->mergeService->destinationForViewer($ticket, $request->user());
            if ($destination) {
                $tab = $request->query('tab');

                return redirect()->route('it.tickets.show', [
                    'ticket' => $destination->id,
                    'merged_from' => $ticket->id,
                    ...(in_array($tab, ['messages', 'files', 'tasks', 'approvals', 'properties', 'links', 'sla', 'history'], true)
                        ? ['tab' => $tab] : []),
                ])->header('Cache-Control', 'no-store, private');
            }
        }

        return $this->renderWorkspace($request, $ticket);
    }

    public function original(Request $request, ItTicket $ticket)
    {
        abort_unless($this->workAccess->canView($request->user(), $ticket), 404);
        if (! $ticket->isMerged()) {
            return redirect()->route('it.tickets.show', $ticket)->header('Cache-Control', 'no-store, private');
        }

        return $this->renderWorkspace($request, $ticket);
    }

    private function renderWorkspace(Request $request, ItTicket $ticket)
    {
        $payload = $this->showPayload($request, $ticket);

        // One route, two callers: the detail page (Inertia) and the
        // quick-peek drawer (axios). Policy + internal-note stripping run
        // identically for both — the payload IS the privacy boundary.
        if (! $request->header('X-Inertia') && $request->wantsJson()) {
            return response()->json($payload)->header('Cache-Control', 'no-store, private');
        }

        return Inertia::render('it/tickets/show', $payload)->toResponse($request)
            ->header('Cache-Control', 'no-store, private');
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

        $canManage = $this->workAccess->canWork($user, $ticket);
        $taskStorageReady = $canManage && app(ItWorkTaskReadinessService::class)->storageReady();
        $canChangeTasks = $taskStorageReady && ! $ticket->isMerged() && in_array($ticket->status, ItTicket::OPEN_STATUSES, true);
        // Participation may grant a technician access to their own sensitive
        // or otherwise out-of-scope request. Internal data and controls still
        // require the same per-record work boundary as protected downloads.
        $isAgent = $canManage;
        $canLinkDevices = $canManage && ! $ticket->isMerged() && $user->canDo('securityDevices.devices.view');
        $isRequester = (int) $ticket->requester_user_id === (int) $user->id;
        $canComment = ! $ticket->isMerged() && in_array($ticket->status, ItTicket::OPEN_STATUSES, true);
        $mergeDestination = $ticket->isMerged() ? $this->mergeService->destinationForViewer($ticket, $user) : null;
        $mergeOrigin = null;
        $originId = filter_var($request->query('merged_from'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($originId && ! $ticket->isMerged()) {
            $origin = ItTicket::query()->find($originId);
            if ($origin && (int) $this->mergeService->destinationForViewer($origin, $user)?->id === (int) $ticket->id) {
                $mergeOrigin = ['id' => $origin->id, 'reference' => $origin->reference,
                    'href' => route('it.tickets.original', $origin, false)];
            }
        }
        $replyUnavailableReason = match (true) {
            $canComment => null,
            $ticket->isMerged() => $mergeDestination
                ? "Continue the conversation on {$mergeDestination->reference}."
                : 'This ticket was merged. The surviving ticket is not available to your current account.',
            default => 'Reopen this ticket before adding another reply.',
        };

        $ticket->load([
            'requester:id,name',
            'assignee:id,name',
            'owner:id,name',
            'team:id,name',
            'queue:id,name',
            'watchers:id,name,role,approved_at',
            'watchers.roles.permissions',
            'watchers.permissionOverrides',
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
            ->orderBy('id')
            ->get();
        $commentDeliveries = app(ItTicketCommentDeliveryPresenter::class)
            ->presentMany($ticket, $user, $comments);
        $comments = $comments
            ->map(fn (ItTicketComment $c) => [
                'id' => $c->id,
                'body' => $c->body,
                'is_internal' => $c->is_internal,
                'speaker_side' => $c->speaker_side,
                'source_channel' => $c->source_channel,
                'delivery' => $commentDeliveries[$c->id] ?? null,
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
            'viewer_user_id' => (int) $user->id,
            'conversation_ready' => ItTicket::hasConversationEvidence(),
            'ticket' => [
                'id' => $ticket->id,
                'reference' => $ticket->reference,
                'lock_version' => (int) $ticket->lock_version,
                'title' => $ticket->title,
                'description' => MonitoringTechnicalSummary::ticketDescription($ticket),
                'work_type' => $ticket->work_type,
                'service' => $ticket->service
                    ? ['id' => $ticket->service->id, 'name' => $ticket->service->name]
                    : null,
                'category' => $ticket->category,
                'subcategory' => $ticket->subcategory,
                'priority' => $ticket->priority,
                ...($canManage ? [
                    'impact' => $ticket->impact,
                    'urgency' => $ticket->urgency,
                    'priority_decision' => $ticket->priority_decision,
                ] : []),
                'status' => $ticket->status,
                'workflow_state' => $ticket->workflow_state,
                'waiting' => $this->waitingPayload($ticket, $canManage),
                'source' => $ticket->source,
                ...$this->slaRead->present($ticket),
                'first_response_due_at' => $ticket->first_response_due_at?->toIso8601String(),
                'resolution_due_at' => $ticket->resolution_due_at?->toIso8601String(),
                'first_responded_at' => $ticket->first_responded_at?->toIso8601String(),
                'conversation' => app(ItTicketConversationPresenter::class)->present($ticket),
                ...($canManage ? ['resolution' => $ticket->resolution_code !== null ? [
                    'code' => $ticket->resolution_code,
                    'summary' => $ticket->resolution_summary,
                    'verification' => $ticket->resolution_verification,
                ] : null] : []),
                'requester' => [
                    'id' => $ticket->requester?->id,
                    'name' => $ticket->requester?->name ?? ($ticket->source === 'system' && $ticket->requester_user_id === null ? 'System' : 'Unknown'),
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
                        ...($canManage ? ['receives_updates' => $this->workAccess->canReceiveTicketUpdates($w, $ticket)] : []),
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
                'is_merged' => $ticket->isMerged(),
                'merge_origin' => $mergeOrigin,
                'merged_originals' => $this->mergeService->originalRecords($ticket, $user,
                    max(1, (int) $request->query('originals_page', 1))),
                'merged_into' => $mergeDestination
                    ? [
                        'id' => $mergeDestination->id,
                        'reference' => $mergeDestination->reference,
                        'title' => $mergeDestination->title,
                    ]
                    : null,
                // §P-S3 approval — flag + the latest request, for the rail.
                'requires_approval' => (bool) $ticket->requires_approval,
                'approval' => $latestApproval ? [
                    'id' => $latestApproval->id,
                    'status' => $latestApproval->effectiveStatus(),
                    'requested_by_name' => $latestApproval->requester?->name,
                    'approver_name' => $latestApproval->approver?->name,
                    'reason' => $canManage ? $latestApproval->reason : null,
                    'requested_at' => $latestApproval->created_at?->toIso8601String(),
                    'decided_at' => $latestApproval->decided_at?->toIso8601String(),
                ] : null,
            ],
            'comments' => $comments,
            'draftRecovery' => $this->draftRecoveryOptions(),
            'events' => $this->activityPresenter->present($ticket, $user),
            'linked_context' => $this->contextPresenter->present($ticket, $user),
            'assignees' => $canManage ? $this->staffUserOptions($user, $ticket) : [],
            'approvals' => $canManage ? $ticket->approvals()->orderByDesc('id')->get(['id', 'status'])
                ->map(fn ($approval): array => ['id' => (int) $approval->id, 'status' => $approval->status])->all() : [],
            'task_work' => $canManage ? ['storage_ready' => $taskStorageReady,
                'can_create' => $canChangeTasks, 'can_reorder' => $canChangeTasks] : null,
            'approval_work' => app(ItTicketApprovalPresenter::class)->work($ticket, $user),
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
            'queueOptions' => $canManage
                ? app(ItTicketRoutingService::class)->queueOptions($ticket)
                : [],
            // §I composer deflection: published articles an agent can reference
            // as they type a reply. Only technicians who can work this ticket
            // receive the internal composer context.
            'kbSuggestions' => $isAgent ? $this->kbSuggestions($user) : [],
            // Suggestions remain scoped and explainable; they never authorize a merge.
            'mergeTargets' => $canManage
                ? $this->mergeService->candidates($ticket, $user)
                : [],
            'watcherOptions' => $canManage && ! $ticket->isMerged()
                ? ItStaffDirectory::watchersForTicket($ticket)->map(fn (User $watcher): array => [
                    'id' => (int) $watcher->id, 'name' => $watcher->name,
                ])->all()
                : [],
            'can' => [
                'manage' => $canManage && ! $ticket->isMerged(),
                'manageWatchers' => $canManage && ! $ticket->isMerged(),
                'linkDevices' => $canLinkDevices,
                'assignApplicationWide' => $canManage
                    && $this->workAccess->canAssignScope($user, null, true),
                'view' => $isAgent,
                'internal' => $canManage,
                'comment' => $canComment,
                'reopen' => $user->can('reopen', $ticket),
                'confirmResolution' => $user->can('confirmResolution', $ticket),
                'watching' => $ticket->watchers->contains('id', $user->id),
                // The requester may rate their own resolved ticket (§K).
                'rate' => $isRequester && ! $ticket->isMerged() && $ticket->status === 'resolved',
                // Fold a duplicate into another live ticket (§P-S2). Agents only.
                'merge' => $canManage && ! $ticket->isMerged() && $ticket->status !== 'closed',
                // Approval affordances (§P-S3).
                'requestApproval' => (bool) $user->can('requestApproval', $ticket),
                'decideApproval' => ! $ticket->isMerged() && $pendingApproval !== null && (bool) $user->can('decide', $pendingApproval),
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
        if ($request->has('request_uuid')) {
            try {
                $result = $this->interactionService->addCommentCommand($ticket, $user, $request->validated(), $request->file('attachments', []));
            } catch (ItTicketCommandConflict $exception) {
                return response()->json(['code' => 'idempotency_conflict', 'message' => $exception->getMessage()], 409);
            } catch (DomainException $exception) {
                return response()->json(['code' => 'comment_rejected', 'message' => $exception->getMessage(), 'errors' => ['body' => [$exception->getMessage()]]], 422);
            }
            if ($result instanceof ItTicketCommentCancellationResult) {
                return response()->json(['status' => 'cancelled', 'data' => $result->toArray((int) $user->id)], 200, ['Cache-Control' => 'no-store, private']);
            }
            try {
                DispatchItTicketNotifications::dispatchAfterResponse((int) $result->ticket->id);
            } catch (\Throwable) {
                // The committed outbox remains available to the scheduled
                // drain. Scheduling failure cannot undo the saved reply ACK.
            }

            return response()->json(['status' => 'committed', 'data' => $result->toArray((int) $user->id)], $result->replayed ? 200 : 201, ['Cache-Control' => 'no-store, private']);
        }
        try {
            $result = $this->interactionService->addComment(
                $ticket,
                $user,
                (string) $request->validated('body'),
                $isInternal,
                array_values(array_filter((array) $request->file('attachments'))),
            );
        } catch (DomainException $exception) {
            return redirect()->back()->withErrors(['body' => $exception->getMessage()]);
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

        return redirect()->back()->with('success', $isInternal ? 'Internal note added.' : 'Reply added.');
    }

    public function recoverCommentCommand(RecoverItTicketCommentCommandRequest $request, ItTicket $ticket, string $requestUuid)
    {
        $result = $this->interactionService->recoverCommentCommand($ticket, $request->user(), $requestUuid);

        return response()->json([
            'status' => $result instanceof ItTicketCommentCancellationResult ? 'cancelled' : 'committed',
            'data' => $result->toArray((int) $request->user()->id),
        ], 200, ['Cache-Control' => 'no-store, private']);
    }

    public function cancelCommentCommand(CancelItTicketCommentCommandRequest $request, ItTicket $ticket, string $requestUuid)
    {
        try {
            $result = $this->interactionService->cancelCommentCommand($ticket, $request->user(), $requestUuid, $request->boolean('is_internal'));
        } catch (ItTicketCommandConflict $exception) {
            return response()->json(['code' => 'idempotency_conflict', 'message' => $exception->getMessage()], 409);
        }

        return response()->json([
            'status' => $result instanceof ItTicketCommentCancellationResult ? 'cancelled' : 'committed',
            'data' => $result->toArray((int) $request->user()->id),
        ], 200, ['Cache-Control' => 'no-store, private']);
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

        if ($parent instanceof ItTicketDraft) {
            try {
                app(ItTicketDraftAttachmentService::class)->authorizeDownload($user, $attachment);
            } catch (ItTicketDraftException) {
                abort(404);
            }
        } elseif ($parent instanceof ItTicketComment) {
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
            $ticket = $this->transitionService->transition(
                $ticket,
                new ItTransitionInput(
                    actor: $user,
                    to: ItWorkflowState::from((string) $validated['workflow_state']),
                    reason: $validated['reason'] ?? null,
                    waitingParty: $validated['waiting_party'] ?? null,
                    nextAction: $validated['next_action'] ?? null,
                    resolutionCode: $validated['resolution_code'] ?? null,
                    resolutionSummary: $validated['resolution_summary'] ?? null,
                    resolutionVerification: $validated['resolution_verification'] ?? null,
                    source: 'workspace',
                    expectedVersion: (int) $validated['expected_version'],
                ),
            );
        } catch (DomainException $exception) {
            return redirect()->back()->with('error', $exception->getMessage());
        }

        if ($request->expectsJson()) {
            return response()->json(['data' => [
                'id' => (int) $ticket->id,
                'lock_version' => (int) $ticket->lock_version,
            ]]);
        }

        return redirect()->back()->with('success', "Updated {$ticket->reference}.");
    }

    /** The requester confirms the reviewed resolution without private work access. */
    public function confirmResolution(ConfirmTicketResolutionRequest $request, ItTicket $ticket)
    {
        try {
            $saved = $this->transitionService->transition($ticket, new ItTransitionInput(
                actor: $request->user(), to: ItWorkflowState::Closed,
                reason: 'Requester confirmed the fix', source: 'requester_confirmation',
                expectedVersion: (int) $request->validated('expected_version'),
            ));
        } catch (ItSettlementBlocked) {
            // The requester must not receive private task titles or direct IDs.
            return response()->json(['code' => 'confirmation_blocked',
                'message' => 'IT still needs to complete required work before this ticket can close. Your confirmation was not recorded.'],
                422, ['Cache-Control' => 'no-store, private']);
        } catch (DomainException) {
            return response()->json(['code' => 'confirmation_unavailable',
                'message' => 'This ticket can no longer be confirmed in its current state. Review the current ticket.'],
                422, ['Cache-Control' => 'no-store, private']);
        }

        return response()->json(['status' => 'committed', 'data' => [
            'id' => (int) $saved->id, 'viewer_user_id' => (int) $request->user()->id,
            'lock_version' => (int) $saved->lock_version, 'operation' => 'resolution.confirm', 'status' => $saved->status,
        ]], 200, ['Cache-Control' => 'no-store, private']);
    }

    /** Close a settled (or abandoned) ticket — terminal until reopened. */
    public function close(CloseTicketRequest $request, ItTicket $ticket)
    {
        $user = $request->user();

        try {
            $saved = $this->triageService->closeWithReason(
                $ticket,
                $user,
                (string) $request->validated('reason'),
                expectedVersion: (int) $request->validated('expected_version'),
            );
        } catch (DomainException $exception) {
            if ($request->expectsJson()) {
                return response()->json(['code' => 'close_unavailable', 'message' => $exception->getMessage()],
                    422, ['Cache-Control' => 'no-store, private']);
            }

            return redirect()->back()->with('error', $exception->getMessage());
        }

        if ($request->expectsJson()) {
            return response()->json(['status' => 'committed', 'data' => [
                'id' => (int) $saved->id,
                'viewer_user_id' => (int) $user->id,
                'operation' => 'ticket.close',
                'status' => $saved->status,
                'lock_version' => (int) $saved->lock_version,
                'reason' => (string) $request->validated('reason'),
            ]], 200, ['Cache-Control' => 'no-store, private']);
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
                (int) $request->validated('expected_version'),
            );
        } catch (DomainException $exception) {
            if ($request->expectsJson()) {
                return response()->json(['code' => 'reopen_unavailable', 'message' => $exception->getMessage()],
                    422, ['Cache-Control' => 'no-store, private']);
            }

            return redirect()->back()->with('error', $exception->getMessage());
        }
        $ticket = $result['ticket'];

        $recipients = $ticket->watchers
            ->when($ticket->assignee, fn ($collection) => $collection->push($ticket->assignee))
            ->unique('id')
            ->reject(fn ($recipient) => (int) $recipient->id === (int) $user->id);
        $this->emailDeliveries->send($recipients, new TicketReopenedNotification($ticket));

        if ($request->expectsJson()) {
            return response()->json(['status' => 'committed', 'data' => [
                'id' => (int) $ticket->id,
                'viewer_user_id' => (int) $user->id,
                'operation' => 'ticket.reopen',
                'status' => $ticket->status,
                'lock_version' => (int) $ticket->lock_version,
                'reason' => $result['comment']->body,
                'comment_id' => (int) $result['comment']->id,
                'visibility' => $result['is_requester'] ? 'public' : 'internal',
            ]], 200, ['Cache-Control' => 'no-store, private']);
        }

        return redirect()->back()->with('success', "Reopened {$ticket->reference}.");
    }

    public function mergePreview(PreviewTicketMergeRequest $request, ItTicket $ticket)
    {
        $target = ItTicket::query()->findOrFail($request->integer('target_ticket_id'));
        $preview = $this->mergeService->preview($ticket, $target, $request->user(),
            $request->integer('source_version'), $request->integer('target_version'));

        return response()->json(['status' => 'reviewed', 'data' => [
            'viewer_user_id' => (int) $request->user()->id,
            'review_nonce' => $request->validated('review_nonce'),
            ...$preview,
        ]], 200, ['Cache-Control' => 'no-store, private']);
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
            $result = $this->mergeService->execute(
                $ticket,
                $target,
                $user,
                $request->validated(),
            );
        } catch (ItTicketCommandConflict $exception) {
            return response()->json(['code' => 'command_conflict', 'message' => $exception->getMessage()],
                409, ['Cache-Control' => 'no-store, private']);
        } catch (DomainException $exception) {
            if ($request->expectsJson()) {
                return response()->json(['code' => 'merge_blocked', 'message' => $exception->getMessage(),
                    'errors' => ['form' => [$exception->getMessage()]]], 422, ['Cache-Control' => 'no-store, private']);
            }

            return redirect()->back()->with('error', $exception->getMessage());
        }

        if ($request->expectsJson()) {
            return response()->json($result, 200, ['Cache-Control' => 'no-store, private']);
        }
        if ($result['status'] !== 'committed') {
            return redirect()->back()->with('error', 'This merge command was cancelled. Review the tickets before starting another merge.');
        }

        return redirect()
            ->route('it.tickets.show', $target)
            ->with('success', "Merged {$ticket->reference} into {$target->reference}.");
    }

    public function mergeCommand(ReadTicketMergeCommandRequest $request, ItTicket $ticket, string $requestUuid)
    {
        $target = ItTicket::query()->findOrFail($request->integer('target_ticket_id'));
        try {
            $result = $this->mergeService->recover($ticket, $target, $request->user(), $requestUuid, $request->isMethod('post'));
        } catch (ModelNotFoundException $exception) {
            if ($exception->getModel() !== ItTicketCommandReceipt::class) {
                throw $exception;
            }

            // Both parents and the actor were reauthorized inside recover(). A
            // missing receipt is distinct from lost access and proves no outcome.
            return response()->json(['code' => 'merge_receipt_unconfirmed', 'viewer_user_id' => (int) $request->user()->id,
                'source_id' => (int) $ticket->id, 'target_id' => (int) $target->id, 'request_uuid' => $requestUuid],
                404, ['Cache-Control' => 'no-store, private']);
        }

        return response()->json($result, 200, ['Cache-Control' => 'no-store, private']);
    }

    public function validateMergeCandidate(ValidateItMergeCandidateRequest $request, ItTicket $ticket)
    {
        return response()->json($this->mergeService->validateCandidate($ticket, $request->user(), $request->validated()),
            200, ['Cache-Control' => 'no-store, private']);
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
        $saved = $this->interactionService->submitCsat(
            $ticket,
            $user,
            (int) $request->validated('score'),
            $request->validated('comment') ?: null,
            (int) $request->validated('expected_version'),
        );

        if ($request->expectsJson()) {
            return response()->json(['status' => 'committed', 'data' => [
                'id' => (int) $saved->id,
                'viewer_user_id' => (int) $user->id,
                'operation' => 'csat.save',
                'lock_version' => (int) $saved->lock_version,
                'score' => (int) $saved->csat_score,
                'comment' => $saved->csat_comment,
                'submitted_at' => $saved->csat_submitted_at?->toIso8601String(),
            ]], 200, ['Cache-Control' => 'no-store, private']);
        }

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

    public function updateWatcher(UpdateTicketWatcherRequest $request, ItTicket $ticket, int $watcherUserId)
    {
        $result = $this->interactionService->setWatcher($ticket, $request->user(), $watcherUserId,
            $request->boolean('watching'), (int) $request->validated('expected_version'));

        return response()->json(['status' => 'committed', 'data' => $result->toArray((int) $request->user()->id)],
            200, ['Cache-Control' => 'no-store, private']);
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
            ->filter(fn (ItTicket $ticket) => $this->workAccess->canWork($user, $ticket))
            ->keyBy('id');

        $items = [];

        foreach ($validated['ids'] as $selectedId) {
            $ticket = $tickets->get((int) $selectedId);
            $outcome = $ticket === null ? ItBulkActionResult::outcome('unavailable') : match ($action) {
                'assign' => $this->triageService->bulkOutcome($ticket, $user, [
                    'assigned_to_user_id' => $assignee?->id,
                    'routing_reason' => $validated['routing_reason'],
                    'expected_version' => (int) $validated['expected_versions'][$ticket->id],
                ], 'bulk'),
                'priority' => $this->triageService->bulkOutcome($ticket, $user, [
                    'priority' => (string) $validated['priority'],
                    'priority_reason' => $validated['priority_reason'],
                    'expected_version' => (int) $validated['expected_versions'][$ticket->id],
                ], 'bulk'),
                'status' => $this->triageService->bulkOutcome($ticket, $user, [
                    'status' => (string) $validated['status'],
                    'expected_version' => (int) $validated['expected_versions'][$ticket->id],
                    ...Arr::only($validated, ['waiting_party', 'waiting_reason', 'next_action']),
                ], 'bulk'),
                'close' => ItBulkActionResult::capture(fn (): bool => $this->triageService->close(
                    $ticket,
                    $user,
                    (string) $validated['reason'],
                    source: 'bulk_close',
                    expectedVersion: (int) $validated['expected_versions'][$ticket->id],
                )),
            };
            $items[] = ['id' => (int) $selectedId, ...$outcome];
        }

        $result = ItBulkActionResult::payload('tickets', $action, $items);
        $label = ['assign' => 'assigned', 'priority' => 'reprioritised', 'status' => 'updated', 'close' => 'closed'][$action];
        $unchanged = $result['selected'] - $result['updated'];
        $message = "{$result['updated']} ticket(s) {$label}".($unchanged > 0 ? " · {$unchanged} unchanged" : '').'.';
        if ($request->expectsJson()) {
            return response()->json(['status' => 'completed', 'viewer_user_id' => (int) $user->id, 'message' => $message, 'result' => $result]);
        }

        return redirect()->back()
            ->with('it_bulk_result', ['actor_user_id' => (int) $user->id, ...$result])
            ->with($result['rejected'] > 0 ? 'warning' : ($result['updated'] > 0 ? 'success' : 'info'), $message);
    }
}
