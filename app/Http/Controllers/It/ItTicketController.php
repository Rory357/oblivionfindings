<?php

namespace App\Http\Controllers\It;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Http\Controllers\Concerns\ServesPrivateAttachments;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Hr\Concerns\ResolvesHrTenant;
use App\Http\Controllers\It\Concerns\BuildsItOptions;
use App\Http\Controllers\It\Concerns\StoresItAttachments;
use App\Http\Requests\It\StoreTicketCommentRequest;
use App\Models\ItAttachment;
use App\Models\ItTicket;
use App\Models\ItTicketComment;
use App\Models\ItTicketEvent;
use App\Notifications\It\TicketRepliedNotification;
use Illuminate\Http\Request;
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
                'created_at' => $ticket->created_at?->toIso8601String(),
                'created_human' => $ticket->created_at?->diffForHumans(short: true),
                'updated_at' => $ticket->updated_at?->toIso8601String(),
                'resolved_at' => $ticket->resolved_at?->toIso8601String(),
                'closed_at' => $ticket->closed_at?->toIso8601String(),
            ],
            'comments' => $comments,
            'events' => $events,
            'assignees' => $canManage ? $this->tenantUserOptions($tenantId) : [],
            // Rail picker over the canonical (fleet-)assets register — never
            // a parallel IT register. Agents only.
            'assetOptions' => $canManage
                ? \App\Models\Asset::query()
                    ->where('status', 'active')
                    ->orderBy('name')
                    ->limit(200)
                    ->get(['id', 'name', 'asset_tag'])
                    ->map(fn ($a) => ['id' => $a->id, 'name' => $a->name, 'tag' => $a->asset_tag])
                    ->values()
                    ->all()
                : [],
            'can' => [
                'manage' => $canManage,
                'view' => $isAgent,
                'internal' => $canManage,
                'reopen' => $user->can('reopen', $ticket),
                'watching' => $ticket->watchers->contains('id', $user->id),
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
}
