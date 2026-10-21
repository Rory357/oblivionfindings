<?php

namespace App\Jobs;

use App\Services\Chat\ChatBrowserPushDelivery;
use App\Services\Chat\ChatMessageDelivery;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class DeliverChatMessageNotifications implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly int $messageId)
    {
        $this->afterCommit();
    }

    public function handle(ChatMessageDelivery $delivery, ChatBrowserPushDelivery $push): void
    {
        $delivery->recover($this->messageId);
        $push->recover($this->messageId);
    }
}
