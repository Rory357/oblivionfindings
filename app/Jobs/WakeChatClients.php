<?php

namespace App\Jobs;

use App\Services\Chat\ChatRealtime;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class WakeChatClients implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @param  array<int, string>  $kinds */
    public function __construct(
        public readonly int $conversationId,
        public readonly ?int $onlyUserId = null,
        public readonly array $kinds = ['message'],
        public readonly ?string $callId = null,
        public readonly ?string $inbox = null,
    ) {
        $this->afterCommit();
    }

    public function backoff(): array
    {
        return [2, 10, 30];
    }

    public function handle(ChatRealtime $realtime): void
    {
        // Jobs queued before kinds existed unserialise without them; treat those as a message change.
        $realtime->publish($this->conversationId, $this->onlyUserId ?? null, $this->kinds ?? ['message'], $this->callId ?? null, $this->inbox ?? null);
    }
}
