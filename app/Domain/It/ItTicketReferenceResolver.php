<?php

namespace App\Domain\It;

use App\Domain\It\Services\ItEmailMessageIdentifiers;
use App\Domain\It\Services\ItTicketMergeService;
use App\Domain\It\Services\ItWorkAccessService;
use App\Models\ItEmailDelivery;
use App\Models\ItInboundEmail;
use App\Models\ItTicket;
use App\Models\User;

final class ItTicketReferenceResolver
{
    /**
     * Reconcile subject and header evidence against existing canonical records.
     * Unknown ancestry may precede a known parent; conflicting known destinations fail closed.
     *
     * @param  list<string>  $subjectReferences
     * @param  list<string>  $messageIds
     * @return array{ticket: ItTicket|null, failure: string|null}
     */
    public function resolveInbound(array $subjectReferences, array $messageIds, User $sender): array
    {
        if (count($subjectReferences) > 1) {
            return ['ticket' => null, 'failure' => 'reference_ambiguous'];
        }
        $tickets = [];
        foreach ($subjectReferences as $reference) {
            $resolution = $this->resolve($reference);
            if ($resolution['failure'] !== null) {
                return $resolution;
            }
            $tickets[] = $resolution['ticket'];
        }
        if ($messageIds !== []) {
            $parser = new ItEmailMessageIdentifiers;
            $hashes = array_unique(array_map($parser->hash(...), $messageIds));
            if (ItInboundEmail::query()->whereIn('message_identity_hash', $hashes)
                ->where('quarantine_reason', 'message_id_collision')->exists()) {
                return ['ticket' => null, 'failure' => 'reference_ambiguous'];
            }
            $rows = ItInboundEmail::query()->whereIn('message_identity_hash', $hashes)
                ->whereIn('status', ['processed', 'duplicate'])->whereNotNull('it_ticket_id')
                ->select('it_ticket_id')->distinct()->limit(201)->get();
            if ($rows->count() > 200) {
                return ['ticket' => null, 'failure' => 'reference_ambiguous'];
            }
            $outgoing = ItEmailDelivery::query()->whereIn('rfc_message_id_hash', $hashes)
                ->whereNotNull('rfc_message_id_recorded_at')->whereNotNull('it_ticket_id')
                ->select('it_ticket_id')->distinct()->limit(201)->get();
            $ids = $rows->pluck('it_ticket_id')->merge($outgoing->pluck('it_ticket_id'))->unique();
            if ($ids->count() > 200) {
                return ['ticket' => null, 'failure' => 'reference_ambiguous'];
            }
            $linked = ItTicket::query()->whereIn('id', $ids)->get();
            if ($linked->count() !== $ids->count()) {
                return ['ticket' => null, 'failure' => 'reference_not_found'];
            }
            array_push($tickets, ...$linked->all());
        }
        if ($tickets === []) {
            return ['ticket' => null, 'failure' => $messageIds !== [] ? 'reference_not_found' : null];
        }
        $destinations = [];
        foreach ($tickets as $ticket) {
            if (! app(ItWorkAccessService::class)->canView($sender, $ticket)) {
                return ['ticket' => null, 'failure' => 'sender_unauthorized'];
            }
            $destination = $ticket->isMerged()
                ? app(ItTicketMergeService::class)->destinationForViewer($ticket, $sender) : $ticket;
            if ($destination === null) {
                return ['ticket' => null, 'failure' => 'reference_unavailable'];
            }
            $destinations[$destination->id] = $destination;
        }

        return count($destinations) === 1
            ? ['ticket' => array_values($destinations)[0], 'failure' => null]
            : ['ticket' => null, 'failure' => 'reference_ambiguous'];
    }

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
