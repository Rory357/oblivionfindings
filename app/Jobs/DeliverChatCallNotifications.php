<?php

namespace App\Jobs;

use App\Services\Chat\ChatCallNoticeDelivery;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class DeliverChatCallNotifications implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly string $callId)
    {
        $this->afterCommit();
    }

    public function handle(ChatCallNoticeDelivery $delivery): void
    {
        $delivery->recover($this->callId);
    }
}
