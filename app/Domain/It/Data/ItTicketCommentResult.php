<?php

namespace App\Domain\It\Data;

use App\Models\ItEmailDelivery;
use App\Models\ItTicket;
use App\Models\ItTicketComment;

/** Committed conversation identity; message text and recipient identities stay out of receipts. */
final readonly class ItTicketCommentResult
{
    public function __construct(
        public ItTicket $ticket,
        public ItTicketComment $comment,
        public string $requestUuid,
        public int $committedVersion,
        public bool $replayed = false,
        public ?array $consumedDraft = null,
    ) {}

    public function toArray(int $viewerId): array
    {
        // Count current leaf attempts, so a retried delivery is not counted
        // twice. These are delivery outcomes, never an assertion mail arrived.
        $statuses = ItEmailDelivery::query()
            ->where('it_ticket_id', $this->ticket->id)
            ->where('it_ticket_comment_id', $this->comment->id)
            ->whereDoesntHave('retryAttempt')
            ->selectRaw('status, COUNT(*) AS aggregate')->groupBy('status')
            ->pluck('aggregate', 'status')->map(fn ($count) => (int) $count)->all();

        return [
            'id' => (int) $this->ticket->id,
            'comment_id' => (int) $this->comment->id,
            'canonical_ticket_id' => (int) $this->comment->ticket_id,
            'viewer_user_id' => $viewerId,
            'request_uuid' => $this->requestUuid,
            'is_internal' => (bool) $this->comment->is_internal,
            'lock_version' => $this->committedVersion,
            'replayed' => $this->replayed,
            'delivery' => ['requested' => $statuses !== [], 'attempt_statuses' => (object) $statuses],
            ...($this->consumedDraft !== null ? ['draft' => $this->consumedDraft] : []),
        ];
    }
}
