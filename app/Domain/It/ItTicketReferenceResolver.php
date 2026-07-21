<?php

namespace App\Domain\It;

use App\Models\ItTicket;

final class ItTicketReferenceResolver
{
    /**
     * Resolve one application-global ticket identity while remaining safe
     * during a rolling deployment that has not yet applied the unique index.
     *
     * @return array{ticket: ItTicket|null, failure: 'reference_not_found'|'reference_ambiguous'|null}
     */
    public function resolve(string $reference): array
    {
        $tickets = ItTicket::query()
            ->where('reference', $reference)
            ->limit(2)
            ->get();

        if ($tickets->isEmpty()) {
            return ['ticket' => null, 'failure' => 'reference_not_found'];
        }
        if ($tickets->count() !== 1) {
            return ['ticket' => null, 'failure' => 'reference_ambiguous'];
        }

        return ['ticket' => $tickets->firstOrFail(), 'failure' => null];
    }
}
