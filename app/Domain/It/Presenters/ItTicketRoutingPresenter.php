<?php

namespace App\Domain\It\Presenters;

use App\Models\ItTicket;

final class ItTicketRoutingPresenter
{
    /**
     * @return array{
     *     queue: array{id: int, name: string}|null,
     *     team: array{id: int, name: string}|null,
     *     owner: array{id: int, name: string}|null
     * }
     */
    public function present(ItTicket $ticket): array
    {
        $ticket->loadMissing([
            'queue:id,name',
            'team:id,name',
            'owner:id,name',
        ]);

        return [
            'queue' => $ticket->queue
                ? ['id' => $ticket->queue->id, 'name' => $ticket->queue->name]
                : null,
            'team' => $ticket->team
                ? ['id' => $ticket->team->id, 'name' => $ticket->team->name]
                : null,
            'owner' => $ticket->owner
                ? ['id' => $ticket->owner->id, 'name' => $ticket->owner->name]
                : null,
        ];
    }
}
