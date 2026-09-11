<?php

namespace App\Domain\It\Data;

use App\Models\ItTicket;

final readonly class ItTicketCreationResult
{
    public function __construct(
        public ItTicket $ticket,
        public ?string $requestUuid,
        public bool $replayed = false,
    ) {}

    /** @return array{id: int, reference: string, url: string, request_uuid: string|null, replayed: bool} */
    public function toArray(): array
    {
        return [
            'id' => (int) $this->ticket->id,
            'reference' => $this->ticket->reference,
            'url' => route('it.tickets.show', $this->ticket, false),
            'request_uuid' => $this->requestUuid,
            'replayed' => $this->replayed,
        ];
    }
}
