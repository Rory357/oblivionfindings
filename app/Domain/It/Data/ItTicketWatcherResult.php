<?php

namespace App\Domain\It\Data;

use App\Models\ItTicket;

final readonly class ItTicketWatcherResult
{
    public function __construct(
        public ItTicket $ticket,
        public int $watcherId,
        public bool $watching,
        public bool $changed,
    ) {}

    public function toArray(int $viewerId): array
    {
        return [
            'id' => (int) $this->ticket->id, 'viewer_user_id' => $viewerId,
            'watcher_user_id' => $this->watcherId, 'watching' => $this->watching,
            'changed' => $this->changed, 'lock_version' => (int) $this->ticket->lock_version,
        ];
    }
}
