<?php

namespace App\Domain\Obli;

use App\Domain\It\Enums\ItTicketCommandChannel;
use App\Domain\SecurityDevices\Models\Device;
use App\Models\ItTicket;
use App\Models\ItTicketCommandReceipt;
use App\Models\User;
use Closure;
use Illuminate\Support\Facades\DB;

/** Active only inside the verified context transaction; carries no caller-selected Device. */
final class ObliComputerIntakeContext
{
    private bool $active = true;

    private ?int $ticketId = null;

    private function __construct(private readonly Device $device, private readonly int $actorId, private readonly string $intentHash, private readonly int $siteId, public readonly int $contextId, private readonly array $intent) {}

    public static function within(User $actor, array $intent, int $id, Closure $operation): mixed
    {
        abort_unless(DB::transactionLevel() > 0, 403);
        $device = app(ObliComputerContexts::class)->proofDevice($actor, $intent, $id);
        $proof = new self($device, (int) $actor->id, ObliComputerContexts::intentHash($intent), (int) $intent['site_id'], $id, $intent);
        try {
            return $operation($proof);
        } finally {
            $proof->active = false;
        }
    }

    public function device(User $actor, array $data, ItTicketCommandChannel $channel): Device
    {
        abort_unless($this->active && DB::transactionLevel() > 0 && $channel === ItTicketCommandChannel::Obli && (int) $actor->id === $this->actorId && hash_equals($this->intentHash, ObliComputerContexts::intentHash($data)), 403);

        return $this->device;
    }

    /** Mutation provenance: only the pending canonical CREATE receipt may authorize this link. */
    public function assertPendingReceipt(User $actor, ItTicket $ticket): void
    {
        abort_unless($this->active && DB::transactionLevel() > 0 && (int) $actor->id === $this->actorId, 403);
        $receipt = ItTicketCommandReceipt::query()->where('actor_user_id', $actor->id)
            ->where('channel', ItTicketCommandChannel::Obli->value)->where('operation', ItTicketCommandReceipt::CREATE_OPERATION)
            ->where('request_uuid', strtolower($this->intent['request_uuid']))->where('it_ticket_id', $ticket->id)
            ->whereNull('committed_at')->lockForUpdate()->first();
        abort_unless($receipt, 403);
    }

    public function deviceForTicket(User $actor, ItTicket $ticket): Device
    {
        abort_unless(($this->ticketId === null ? $ticket->wasRecentlyCreated : (int) $ticket->id === $this->ticketId)
            && $ticket->title === $this->intent['title'] && $ticket->description === $this->intent['description'] && $ticket->category === $this->intent['category']
            && $this->active && DB::transactionLevel() > 0 && (int) $actor->id === $this->actorId && (int) $ticket->requester_user_id === $this->actorId && (int) $ticket->site_id === $this->siteId && ! $ticket->is_organisation_wide && $ticket->source === 'portal' && $ticket->work_type === 'incident' && ! $ticket->isMerged(), 403);
        $this->ticketId ??= (int) $ticket->id;

        return $this->device;
    }
}
