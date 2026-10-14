<?php

namespace App\Domain\Obli;

use App\Domain\It\Data\ItTicketCommentResult;
use App\Domain\It\Enums\ItTicketCommandChannel;
use App\Domain\It\Services\ItTicketInteractionService;
use App\Domain\It\Services\ItWorkAccessService;
use App\Models\ItTicket;
use App\Models\ItTicketComment;
use App\Models\User;

/** Personal native adapter; canonical IT commands remain the sole reply writer. */
final readonly class ObliTickets
{
    public function __construct(private ItWorkAccessService $access, private ItTicketInteractionService $interactions) {}

    public function ownTicket(User $actor, int $id): ItTicket
    {
        $ticket = ItTicket::query()->whereKey($id)->lockForUpdate()->firstOrFail();
        abort_unless((int) $ticket->requester_user_id === (int) $actor->id
            && $ticket->merged_into_ticket_id === null
            && in_array((int) $ticket->site_id, $this->access->approvedSiteIds($actor, true), true)
            && $this->access->canView($actor, $ticket, true), 404);

        return $ticket;
    }

    public function detail(User $actor, int $id, ?int $before): array
    {
        $ticket = $this->ownTicket($actor, $id);
        $rows = $ticket->comments()->publicOnly()->when($before, fn ($q) => $q->where('id', '<', $before))
            ->orderByDesc('id')->limit(11)->lockForUpdate()->get();
        $selected = $rows->take(10);
        do {
            $payload = [
                'ticket' => $this->ticket($ticket),
                'comments' => $selected->reverse()->map(fn ($comment) => $this->comment($comment))->values()->all(),
                'next_before' => $rows->count() > $selected->count() ? (int) $selected->last()->id : null,
            ];
            if (strlen(json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) <= 120000) {
                return $payload;
            }
            $selected = $selected->take($selected->count() - 1);
        } while ($selected->isNotEmpty());

        // Each individual field is bounded; a one-comment page fits this limit.
        abort(500);
    }

    public function reply(User $actor, int $id, array $input): array
    {
        $ticket = $this->ownTicket($actor, $id);
        $result = $this->interactions->addCommentCommand($ticket, $actor, [
            ...$input, 'actor_user_id' => $actor->id, 'is_internal' => false,
        ], channel: ItTicketCommandChannel::Obli);

        $receipt = $this->receipt($actor, $id, $result);
        ObliTicketNotificationDispatch::schedule($id);

        return $receipt;
    }

    public function recover(User $actor, int $id, string $uuid): array
    {
        $ticket = $this->ownTicket($actor, $id);
        $result = $this->interactions->recoverCommentCommand($ticket, $actor, $uuid, ItTicketCommandChannel::Obli);

        return $this->receipt($actor, $id, $result);
    }

    private function receipt(User $actor, int $id, mixed $result): array
    {
        abort_unless($result instanceof ItTicketCommentResult, 404);
        $ticket = $this->ownTicket($actor, $id);
        $comment = ItTicketComment::query()->whereKey($result->comment->id)->where('ticket_id', $ticket->id)
            ->publicOnly()->lockForUpdate()->firstOrFail();

        return ['ticket' => $this->ticket($ticket), 'comment' => $this->comment($comment),
            'request_uuid' => $result->requestUuid, 'committed_version' => $result->committedVersion,
            'replayed' => $result->replayed];
    }

    private function ticket(ItTicket $ticket): array
    {
        $description = (string) $ticket->description;

        return [
            'id' => (int) $ticket->id, 'reference' => mb_substr((string) $ticket->reference, 0, 200),
            'title' => mb_substr((string) $ticket->title, 0, 200), 'status' => $ticket->status,
            'updated_at' => $ticket->updated_at?->toIso8601String(), 'path' => '/it/tickets/'.$ticket->id,
            'description' => mb_substr($description, 0, 12000), 'description_truncated' => mb_strlen($description) > 12000,
            'lock_version' => (int) $ticket->lock_version,
            'can_reply' => in_array($ticket->status, ItTicket::OPEN_STATUSES, true),
        ];
    }

    private function comment(ItTicketComment $comment): array
    {
        $body = (string) $comment->body;

        return ['id' => (int) $comment->id, 'body' => mb_substr($body, 0, 5000),
            'body_truncated' => mb_strlen($body) > 5000,
            'author' => mb_substr((string) ($comment->author?->name ?? 'Staff'), 0, 200),
            'created_at' => $comment->created_at?->toIso8601String()];
    }
}
