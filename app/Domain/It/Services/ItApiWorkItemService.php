<?php

namespace App\Domain\It\Services;

use App\Domain\It\Data\ItTransitionInput;
use App\Models\ItServiceIdentity;
use App\Models\ItTicket;
use App\Models\ItTicketComment;
use App\Models\ItTicketEvent;
use App\Services\AuditLogger;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

final class ItApiWorkItemService
{
    public function __construct(
        private readonly ItTicketRoutingService $routingService,
        private readonly ItWorkTransitionService $transitionService,
    ) {}

    /** @param array<string, mixed> $data */
    public function create(ItServiceIdentity $identity, array $data): ItTicket
    {
        return DB::transaction(function () use ($identity, $data): ItTicket {
            $actor = $identity->actor;
            $ticket = ItTicket::createWithReference([
                'tenant_id' => $identity->tenant_id,
                'requester_user_id' => $actor->id,
                'requested_for_user_id' => $actor->id,
                'source' => 'system',
                'status' => 'open',
                'workflow_state' => 'submitted',
                'requires_approval' => ItTicket::categoryNeedsApproval((string) $data['category']),
                'impact' => 'individual',
                'urgency' => 'normal',
                ...Arr::only($data, ItServiceIdentity::CREATE_FIELDS),
            ]);
            $ticket->stampSlaDueDates();
            $ticket->save();

            ItTicketEvent::record($ticket, 'created', $actor->id, [
                'source' => 'service_api',
                'service_identity_id' => $identity->id,
            ]);
            $ticket = $this->routingService->route($ticket, $actor->id);

            AuditLogger::logOrFail('it.api.work_item.created', $ticket, [
                'organization_id' => $identity->tenant_id,
                'actor_id' => $actor->id,
                'service_identity_id' => $identity->id,
            ]);

            return $ticket->load(['site:id,name', 'service:id,name', 'asset:id,name,asset_tag', 'queue:id,name', 'team:id,name', 'owner:id,name', 'assignee:id,name']);
        });
    }

    public function addPublicComment(ItServiceIdentity $identity, ItTicket $ticket, string $body): ItTicketComment
    {
        return DB::transaction(function () use ($identity, $ticket, $body): ItTicketComment {
            $comment = ItTicketComment::query()->create([
                'tenant_id' => $identity->tenant_id,
                'ticket_id' => $ticket->id,
                'author_user_id' => $identity->actor_user_id,
                'body' => $body,
                'is_internal' => false,
            ]);
            ItTicketEvent::record($ticket, 'api_public_comment', $identity->actor_user_id, [
                'comment_id' => $comment->id,
                'service_identity_id' => $identity->id,
            ]);
            AuditLogger::logOrFail('it.api.comment.created', $ticket, [
                'organization_id' => $identity->tenant_id,
                'actor_id' => $identity->actor_user_id,
                'service_identity_id' => $identity->id,
                'comment_id' => $comment->id,
            ]);

            return $comment;
        });
    }

    public function transition(ItServiceIdentity $identity, ItTicket $ticket, ItTransitionInput $input): ItTicket
    {
        $transitioned = $this->transitionService->transition($ticket, $input);
        AuditLogger::logOrFail('it.api.transition.completed', $transitioned, [
            'organization_id' => $identity->tenant_id,
            'actor_id' => $identity->actor_user_id,
            'service_identity_id' => $identity->id,
            'to_workflow_state' => $input->to->value,
        ]);

        return $transitioned->load(['site:id,name', 'service:id,name', 'asset:id,name,asset_tag', 'queue:id,name', 'team:id,name', 'owner:id,name', 'assignee:id,name']);
    }
}
