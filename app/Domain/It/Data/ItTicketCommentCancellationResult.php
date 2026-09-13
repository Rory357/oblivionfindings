<?php

namespace App\Domain\It\Data;

use App\Models\ItTicket;

/** Permanent acknowledgement that this exact uncommitted command cannot write later. */
final readonly class ItTicketCommentCancellationResult
{
    public function __construct(
        public ItTicket $ticket,
        public string $requestUuid,
        public bool $isInternal,
        public string $cancelledAt,
        public bool $replayed = false,
    ) {}

    public function toArray(int $viewerId): array
    {
        return ['id' => (int) $this->ticket->id, 'viewer_user_id' => $viewerId, 'request_uuid' => $this->requestUuid,
            'is_internal' => $this->isInternal, 'cancelled_at' => $this->cancelledAt, 'replayed' => $this->replayed];
    }
}
