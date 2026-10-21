<?php

namespace App\Jobs;

use App\Services\Chat\ChatRealtime;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class WakeChatClients implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public readonly int $conversationId, public readonly ?int $onlyUserId = null)
    {
        $this->afterCommit();
    }

    public function backoff(): array
    {
        return [2, 10, 30];
    }

    public function handle(ChatRealtime $realtime): void
    {
        $realtime->publish($this->conversationId, $this->onlyUserId);
    }
}
