<?php

namespace App\Jobs;

use App\Services\Chat\OblivionRelay;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * One retry of a relay revocation for one node that couldn't be reached (spec 3.7). Re-signed at
 * send time with the current control key, so the queue never holds a signed request.
 */
final class SendChatRelayRevocation implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    /** @param  list<array{id: string, expires_up_to: int}>  $entries */
    public function __construct(
        public readonly string $revocationId,
        public readonly string $node,
        public readonly array $entries,
        public readonly string $reason,
        public readonly int $attempt,
        public readonly int $giveUpAt,
    ) {}

    public function handle(OblivionRelay $relay): void
    {
        $relay->retry($this->revocationId, $this->node, $this->entries, $this->reason, $this->attempt, $this->giveUpAt);
    }
}
