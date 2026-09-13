<?php

namespace App\Domain\It\Presenters;

use App\Models\ItTicket;

/** Public conversation evidence, separate from private waiting and SLA facts. */
final class ItTicketConversationPresenter
{
    public function present(ItTicket $ticket): array
    {
        $party = in_array($ticket->next_response_party, ['it', 'requester'], true) ? $ticket->next_response_party : null;
        $active = in_array($ticket->status, ItTicket::OPEN_STATUSES, true) && ! $ticket->isMerged();

        return [
            'last_public' => $ticket->last_public_comment_id !== null ? [
                'comment_id' => (int) $ticket->last_public_comment_id,
                'at' => $ticket->last_public_commented_at?->toIso8601String(),
                'speaker_side' => in_array($ticket->last_public_speaker_side, ['it', 'requester', 'observer'], true)
                    ? $ticket->last_public_speaker_side : null,
            ] : null,
            'next_response_party' => $active ? $party : null,
            'state' => ! $active ? 'settled' : ($party === null ? 'unknown' : 'awaiting_'.$party),
        ];
    }
}
