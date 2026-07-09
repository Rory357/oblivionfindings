<?php

namespace App\Http\Controllers\It;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\It\ItStaffDirectory;
use App\Http\Controllers\Concerns\ServesPrivateAttachments;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Hr\Concerns\ResolvesHrTenant;
use App\Http\Controllers\It\Concerns\BuildsItOptions;
use App\Http\Controllers\It\Concerns\StoresItAttachments;
use App\Http\Requests\It\BulkTicketActionRequest;
use App\Http\Requests\It\DecideApprovalRequest;
use App\Http\Requests\It\MergeTicketRequest;
use App\Http\Requests\It\RequestApprovalRequest;
use App\Http\Requests\It\StoreTicketCommentRequest;
use App\Http\Requests\It\SubmitCsatRequest;
use App\Models\ItAttachment;
use App\Models\ItTicket;
use App\Models\ItTicketApproval;
use App\Models\ItTicketComment;
use App\Models\ItTicketEvent;
use App\Models\User;
use App\Notifications\It\TicketApprovalNotification;
use App\Notifications\It\TicketAssignedNotification;
use App\Notifications\It\TicketRepliedNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Inertia\Inertia;

/**
 * The ticket workspace (/it/tickets/{ticket}): the conversation thread,
 * activity timeline and properties rail where tickets actually get worked.
 * Requesters reach their OWN tickets only, with internal notes stripped
 * server-side — the payload is the privacy boundary, never the UI.
 */
class ItTicketController extends Controller
{
    use BuildsItOptions, ResolvesHrTenant, ServesPrivateAttachments, StoresItAttachments;

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

    /** @return array<string, mixed> */
    private function showPayload(Request $request, ItTicket $ticket): array
    {
        $user = $request->user();
        $this->authorize('view', $ticket);
        $tenantId = $this->resolveHrTenantIdForUser($user);
        $this->assertHrTenantAccess($tenantId, $ticket->tenant_id);

        $isAgent = $user->canDo('it.view');
        $canManage = $user->canDo('it.manage');
        $isRequester = (int) $ticket->requester_user_id === (int) $user->id;

        $ticket->load([
            'requester:id,name',
            'assignee:id,name',
            'watchers:id,name',
            'asset:id,name,asset_tag',
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

        $events = $ticket->events()
            ->with('actor:id,name')
            ->orderBy('created_at')
            ->get()
            ->map(fn (ItTicketEvent $e) => [
                'id' => $e->id,
                'type' => $e->type,
                'payload' => $e->payload,
                'actor' => $e->actor?->name,
                'at' => $e->created_at?->toIso8601String(),
                'at_human' => $e->created_at?->diffForHumans(short: true),
            ])
            ->values()
            ->all();

        return [
            'ticket' => [
                'id' => $ticket->id,
                'reference' => $ticket->reference,
                'title' => $ticket->title,
                'description' => $ticket->description,
                'category' => $ticket->category,
                'subcategory' => $ticket->subcategory,
                'priority' => $ticket->priority,
                'status' => $ticket->status,
                'source' => $ticket->source,
                'sla_state' => $ticket->sla_state,
                'first_response_due_at' => $ticket->first_response_due_at?->toIso8601String(),
                'resolution_due_at' => $ticket->resolution_due_at?->toIso8601String(),
                'first_responded_at' => $ticket->first_responded_at?->toIso8601String(),
                'requester' => [
                    'id' => $ticket->requester?->id,
                    'name' => $ticket->requester?->name ?? 'Unknown',
                    'role' => $requesterProfile?->position_title ?? $requesterProfile?->position_role,
                ],
                'assignee' => $ticket->assignee
                    ? ['id' => $ticket->assignee->id, 'name' => $ticket->assignee->name]
                    : null,
                'watchers' => $ticket->watchers
                    ->map(fn ($w) => ['id' => $w->id, 'name' => $w->name])
                    ->values()
                    ->all(),
                'asset' => $ticket->asset
                    ? ['id' => $ticket->asset->id, 'name' => $ticket->asset->name, 'tag' => $ticket->asset->asset_tag]
                    : null,
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
                'closed_at' => $ticket->closed_at?->toIso8601String(),
                // §P-S2: the survivor this ticket was folded into, for the banner.
                'merged_into' => $ticket->mergedInto
                    ? [
                        'id' => $ticket->mergedInto->id,
                        'reference' => $ticket->mergedInto->reference,
                        'title' => $ticket->mergedInto->title,
                    ]
                    : null,
            ],
            'comments' => $comments,
            'events' => $events,
            'assignees' => $canManage ? $this->tenantUserOptions($tenantId) : [],
            // Rail picker over the canonical (fleet-)assets register — never
            // a parallel IT register. Agents only.
            'assetOptions' => $canManage ? $this->assetOptions() : [],
            // §I composer deflection: published articles an agent can reference
            // as they type a reply. Agents (it.view) only — requesters already
            // met the KB at raise time and their payload stays lean.
            'kbSuggestions' => $isAgent ? $this->kbSuggestions($tenantId) : [],
            // §P-S2 merge picker: recent live tickets an agent can fold this one
            // into. Agents only; excludes self and already-merged tickets.
            'mergeTargets' => $canManage
                ? ItTicket::query()
                    ->forTenant($tenantId)
                    ->whereIn('status', ItTicket::OPEN_STATUSES)
                    ->whereNull('merged_into_ticket_id')
                    ->where('id', '!=', $ticket->id)
                    ->latest('id')
                    ->limit(50)
                    ->get(['id', 'reference', 'title', 'priority', 'status'])
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
                'view' => $isAgent,
                'internal' => $canManage,
                'reopen' => $user->can('reopen', $ticket),
                'watching' => $ticket->watchers->contains('id', $user->id),
                // The requester may rate their own resolved ticket (§K).
                'rate' => $isRequester && $ticket->status === 'resolved',
                // Fold a duplicate into another live ticket (§P-S2). Agents only.
                'merge' => $canManage && ! $ticket->isMerged() && $ticket->status !== 'closed',
            ],
        ];
    }

    public function storeComment(StoreTicketCommentRequest $request, ItTicket $ticket)
    {
        $user = $request->user();
        $tenantId = $this->resolveHrTenantIdForUser($user);
        $this->assertHrTenantAccess($tenantId, $ticket->tenant_id);

        $isInternal = $request->boolean('is_internal');
        $isAgentSide = $user->canDo('it.manage');
        $isRequester = (int) $ticket->requester_user_id === (int) $user->id;

        $comment = $ticket->comments()->create([
            'tenant_id' => $ticket->tenant_id,
            'author_user_id' => $user->id,
            'body' => $request->validated('body'),
            'is_internal' => $isInternal,
        ]);

        $this->storeItAttachments($comment, $request->file('attachments'), $user);

        // First PUBLIC agent reply stops the response clock.
        if ($isAgentSide && ! $isInternal && ! $ticket->first_responded_at) {
            $ticket->first_responded_at = now();
        }

        // The ball comes back from the requester: resume the resolution
        // clock and put the ticket back in the working pile.
        if ($isRequester && $ticket->status === 'waiting') {
            $from = $ticket->status;
            $ticket->stopWaiting();
            ItTicketEvent::record($ticket, 'status_changed', $user->id, [
                'from' => $from,
                'to' => $ticket->status,
                'via' => 'requester_reply',
            ]);
        }

        $ticket->save();

        // Public replies notify the other side of the conversation; internal
        // notes notify nobody (they do not exist for requesters).
        if (! $isInternal) {
            if ($isRequester) {
                $recipients = $ticket->watchers
                    ->when($ticket->assignee, fn ($c) => $c->push($ticket->assignee))
                    ->unique('id')
                    ->reject(fn ($u) => $u->id === $user->id);
                NotificationFacade::send($recipients, new TicketRepliedNotification($ticket, 'agent_side'));
            } elseif ($ticket->requester && $ticket->requester_user_id !== $user->id) {
                $ticket->requester->notify(new TicketRepliedNotification($ticket, 'requester'));
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
        $tenantId = $this->resolveHrTenantIdForUser($user);
        $this->assertHrTenantAccess($tenantId, (int) $attachment->tenant_id);

        $parent = $attachment->attachable;

        if ($parent instanceof ItTicketComment) {
            $this->authorize('view', $parent->ticket);
            if ($parent->is_internal) {
                abort_unless($user->canDo('it.manage'), 403);
            }
        } elseif ($parent instanceof ItTicket) {
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

    /** Close a settled (or abandoned) ticket — terminal until reopened. */
    public function close(Request $request, ItTicket $ticket)
    {
        $user = $request->user();
        abort_unless($user && $user->can('close', $ticket), 403);
        $tenantId = $this->resolveHrTenantIdForUser($user);
        $this->assertHrTenantAccess($tenantId, $ticket->tenant_id);

        if ($ticket->status === 'closed') {
            return redirect()->back()->with('error', 'This ticket is already closed.');
        }

        if ($ticket->status === 'waiting') {
            $ticket->stopWaiting('closed');
        }
        $ticket->status = 'closed';
        $ticket->closed_at = now();
        $ticket->save();

        ItTicketEvent::record($ticket, 'closed', $user->id);

        return redirect()->back()->with('success', "Closed {$ticket->reference}.");
    }

    /**
     * Bring a settled ticket back: agents anytime, the requester within
     * 7 days of resolution (ItTicketPolicy::reopen).
     */
    public function reopen(Request $request, ItTicket $ticket)
    {
        $user = $request->user();
        $this->authorize('reopen', $ticket);
        $tenantId = $this->resolveHrTenantIdForUser($user);
        $this->assertHrTenantAccess($tenantId, $ticket->tenant_id);

        if ($ticket->isMerged()) {
            return redirect()->back()->with('error', 'This ticket was merged into another — reopen the survivor instead.');
        }

        if (! in_array($ticket->status, ['resolved', 'closed'], true)) {
            return redirect()->back()->with('error', 'Only resolved or closed tickets can be reopened.');
        }

        $ticket->status = 'open';
        $ticket->resolved_at = null;
        $ticket->closed_at = null;
        $ticket->reopened_count = (int) $ticket->reopened_count + 1;
        // The clock runs again — a previously met/breached verdict no longer
        // describes an open ticket; the SLA engine re-evaluates from here.
        $ticket->sla_state = 'ok';
        $ticket->save();

        ItTicketEvent::record($ticket, 'reopened', $user->id);

        if ($ticket->assignee && $ticket->assigned_to_user_id !== $user->id) {
            $ticket->assignee->notify(new \App\Notifications\It\TicketReopenedNotification($ticket));
        }

        return redirect()->back()->with('success', "Reopened {$ticket->reference}.");
    }

    /**
     * Fold a duplicate SOURCE ticket into a TARGET survivor: the conversation
     * and watchers move across, the source closes as merged, and both
     * timelines get a `merged` marker. Audit events stay put — merging never
     * rewrites a ticket's own history. Guarded by ItTicketPolicy@merge.
     */
    public function merge(MergeTicketRequest $request, ItTicket $ticket)
    {
        $user = $request->user();
        $tenantId = $this->resolveHrTenantIdForUser($user);
        $this->assertHrTenantAccess($tenantId, $ticket->tenant_id);

        $target = $request->targetTicket();
        abort_unless($target instanceof ItTicket, 404);

        DB::transaction(function () use ($ticket, $target, $user) {
            // The conversation continues on the survivor.
            $ticket->comments()->update(['ticket_id' => $target->id]);

            // Watchers follow, de-duplicated against the survivor's list.
            $watcherIds = $ticket->watchers()->pluck('users.id')->all();
            if ($watcherIds !== []) {
                $target->watchers()->syncWithoutDetaching($watcherIds);
                $ticket->watchers()->detach();
            }

            // Close the source as merged — kept, never deleted.
            $ticket->forceFill([
                'status' => 'closed',
                'closed_at' => now(),
                'merged_into_ticket_id' => $target->id,
                'merged_at' => now(),
            ])->save();

            // A marker on each timeline; each ticket's own audit stays intact.
            ItTicketEvent::record($ticket, 'merged', $user->id, [
                'direction' => 'into',
                'target_id' => $target->id,
                'target_reference' => $target->reference,
            ]);
            ItTicketEvent::record($target, 'merged', $user->id, [
                'direction' => 'from',
                'source_id' => $ticket->id,
                'source_reference' => $ticket->reference,
            ]);
        });

        return redirect()
            ->route('it.tickets.show', $target)
            ->with('success', "Merged {$ticket->reference} into {$target->reference}.");
    }

    /**
     * Raise a sign-off request on a ticket whose category needs approval
     * (§P-S3). Notifies the other agents (never the requester) and logs it.
     * Authorised by ItTicketPolicy@requestApproval (RequestApprovalRequest).
     */
    public function requestApproval(RequestApprovalRequest $request, ItTicket $ticket)
    {
        $user = $request->user();
        $tenantId = $this->resolveHrTenantIdForUser($user);
        $this->assertHrTenantAccess($tenantId, $ticket->tenant_id);

        $approval = $ticket->approvals()->create([
            'tenant_id' => $ticket->tenant_id,
            'requested_by' => $user->id,
            'status' => 'pending',
            'reason' => $request->validated('reason'),
        ]);

        ItTicketEvent::record($ticket, 'approval_requested', $user->id, ['approval_id' => $approval->id]);

        // Every agent who could sign off, except the one who asked.
        $approvers = ItStaffDirectory::agents($tenantId)->reject(fn (User $u) => $u->id === $user->id);
        if ($approvers->isNotEmpty()) {
            NotificationFacade::send($approvers, new TicketApprovalNotification($ticket, 'requested'));
        }

        return redirect()->back()->with('success', "Approval requested for {$ticket->reference}.");
    }

    /**
     * Record a manager's verdict on a pending request (§P-S3) and tell the
     * agent who asked. Authorised by ItTicketApprovalPolicy@decide
     * (DecideApprovalRequest) — a different agent, pending only.
     */
    public function decideApproval(DecideApprovalRequest $request, ItTicketApproval $approval)
    {
        $user = $request->user();
        $tenantId = $this->resolveHrTenantIdForUser($user);
        $this->assertHrTenantAccess($tenantId, $approval->tenant_id);

        $status = $request->validated('decision') === 'approve' ? 'approved' : 'rejected';

        $approval->forceFill([
            'status' => $status,
            'approver_id' => $user->id,
            'reason' => $request->validated('reason') ?? $approval->reason,
            'decided_at' => now(),
        ])->save();

        $ticket = $approval->ticket;
        ItTicketEvent::record($ticket, 'approval_'.$status, $user->id, ['approval_id' => $approval->id]);

        $requester = User::find($approval->requested_by);
        if ($requester) {
            $requester->notify(new TicketApprovalNotification($ticket, $status));
        }

        return redirect()->back()->with('success', "Approval {$status} for {$ticket->reference}.");
    }

    /**
     * CSAT (§K): the requester rates the resolution 1–5 (+ optional comment).
     * One-shot in spirit — the `csat_submitted` event and stamp land on the
     * FIRST submission — but editable while the ticket is still resolved, so a
     * re-rate silently updates the score. Authorisation is the FormRequest's.
     */
    public function csat(SubmitCsatRequest $request, ItTicket $ticket)
    {
        $user = $request->user();
        $tenantId = $this->resolveHrTenantIdForUser($user);
        $this->assertHrTenantAccess($tenantId, $ticket->tenant_id);

        $firstTime = $ticket->csat_submitted_at === null;
        $ticket->csat_score = (int) $request->validated('score');
        $ticket->csat_comment = $request->validated('comment') ?: null;
        if ($firstTime) {
            $ticket->csat_submitted_at = now();
        }
        $ticket->save();

        // One trail entry — the first rating. Edits change the score quietly.
        if ($firstTime) {
            ItTicketEvent::record($ticket, 'csat_submitted', $user->id, ['score' => $ticket->csat_score]);
        }

        return redirect()->back()->with('success', 'Thanks — your feedback helps IT improve.');
    }

    public function watch(Request $request, ItTicket $ticket)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('it.manage'), 403);
        $tenantId = $this->resolveHrTenantIdForUser($user);
        $this->assertHrTenantAccess($tenantId, $ticket->tenant_id);

        $attached = $ticket->watchers()->syncWithoutDetaching([$user->id]);
        if (! empty($attached['attached'])) {
            ItTicketEvent::record($ticket, 'watcher_added', $user->id, ['user_id' => $user->id]);
        }

        return redirect()->back()->with('success', "Watching {$ticket->reference}.");
    }

    public function unwatch(Request $request, ItTicket $ticket)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('it.manage'), 403);
        $tenantId = $this->resolveHrTenantIdForUser($user);
        $this->assertHrTenantAccess($tenantId, $ticket->tenant_id);

        if ($ticket->watchers()->detach($user->id)) {
            ItTicketEvent::record($ticket, 'watcher_removed', $user->id, ['user_id' => $user->id]);
        }

        return redirect()->back()->with('success', "Stopped watching {$ticket->reference}.");
    }

    /* ================================================================== */
    /*  Bulk actions (§F2) */
    /* ================================================================== */

    /**
     * One action over many tickets: assign, set priority (restamps the SLA
     * clock), set a working status (waiting transitions bank the pause), or
     * close. Foreign-tenant ids silently drop out of the tenant-scoped
     * fetch; settled tickets are skipped rather than mutated — the flash
     * reports both as "unchanged". One event row per actual change, same
     * payload shape as the single-ticket routes.
     */
    public function bulk(BulkTicketActionRequest $request)
    {
        $user = $request->user();
        $tenantId = $this->resolveHrTenantIdForUser($user);
        $validated = $request->validated();
        $action = (string) $validated['action'];

        $assignee = null;
        if ($action === 'assign' && ! empty($validated['assigned_to_user_id'])) {
            $assignee = User::query()->find((int) $validated['assigned_to_user_id']);
            // Same foreign-tenant guard as every other assignment here: reject a
            // recipient whose HR profile sits in a different organisation.
            $inOtherTenant = $assignee && HrEmployeeProfile::query()
                ->where('user_id', $assignee->id)
                ->whereNotNull('tenant_id')
                ->where('tenant_id', '!=', $tenantId)
                ->exists();
            if ($inOtherTenant) {
                return redirect()->back()->with('error', 'That colleague is in a different organisation.');
            }
        }

        $tickets = ItTicket::query()
            ->forTenant($tenantId)
            ->whereIn('id', $validated['ids'])
            ->get();

        $updated = 0;
        $skipped = count($validated['ids']) - $tickets->count();

        foreach ($tickets as $ticket) {
            $changed = match ($action) {
                'assign' => $this->bulkAssign($ticket, $assignee, $user),
                'priority' => $this->bulkPriority($ticket, (string) $validated['priority'], $user),
                'status' => $this->bulkStatus($ticket, (string) $validated['status'], $user),
                'close' => $this->bulkClose($ticket, $user),
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

    private function bulkAssign(ItTicket $ticket, ?User $assignee, User $actor): bool
    {
        if (! in_array($ticket->status, ItTicket::OPEN_STATUSES, true)) {
            return false; // settled tickets keep their history
        }
        $newId = $assignee?->id;
        if ((int) $ticket->assigned_to_user_id === (int) $newId) {
            return false;
        }

        $from = $ticket->assigned_to_user_id;
        $ticket->assigned_to_user_id = $newId;
        $ticket->save();

        ItTicketEvent::record($ticket, 'assigned', $actor->id, [
            'from' => $from,
            'to' => $newId,
            'via' => 'bulk',
        ]);
        if ($assignee && $assignee->id !== $actor->id) {
            $assignee->notify(new TicketAssignedNotification($ticket));
        }

        return true;
    }

    private function bulkPriority(ItTicket $ticket, string $priority, User $actor): bool
    {
        if (! in_array($ticket->status, ItTicket::OPEN_STATUSES, true) || $ticket->priority === $priority) {
            return false;
        }

        $from = $ticket->priority;
        $ticket->priority = $priority;
        // Re-target the SLA clock for the new priority (same creation anchor).
        $ticket->stampSlaDueDates();
        $ticket->save();

        ItTicketEvent::record($ticket, 'priority_changed', $actor->id, [
            'from' => $from,
            'to' => $priority,
            'via' => 'bulk',
        ]);

        return true;
    }

    private function bulkStatus(ItTicket $ticket, string $status, User $actor): bool
    {
        // Working states only (the FormRequest enforces the target; this
        // guards the source) — bulk never un-resolves or un-closes.
        if (! in_array($ticket->status, ItTicket::OPEN_STATUSES, true) || $ticket->status === $status) {
            return false;
        }

        $from = $ticket->status;
        if ($status === 'waiting') {
            $ticket->startWaiting();
        } elseif ($ticket->status === 'waiting') {
            $ticket->stopWaiting($status);
        } else {
            $ticket->status = $status;
        }
        $ticket->save();

        ItTicketEvent::record($ticket, 'status_changed', $actor->id, [
            'from' => $from,
            'to' => $ticket->status,
            'via' => 'bulk',
        ]);

        return true;
    }

    private function bulkClose(ItTicket $ticket, User $actor): bool
    {
        if ($ticket->status === 'closed') {
            return false;
        }

        if ($ticket->status === 'waiting') {
            $ticket->stopWaiting('closed');
        } else {
            $ticket->status = 'closed';
        }
        $ticket->closed_at = now();
        $ticket->save();

        ItTicketEvent::record($ticket, 'closed', $actor->id, ['via' => 'bulk']);

        return true;
    }
}
