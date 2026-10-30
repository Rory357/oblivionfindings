<?php

namespace App\Jobs;

use App\Services\Chat\ChatFamilyWorkDelivery;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class DeliverChatFamilyWorkNotifications implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly int $eventId)
    {
        $this->afterCommit();
    }

    public function handle(ChatFamilyWorkDelivery $delivery): void
    {
        $delivery->recover($this->eventId);
    }
}
