<?php

namespace App\Jobs;

use App\Services\Chat\ChatPresence;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

/** The trailing presence update for someone whose state changed inside their one-minute window. */
final class PublishChatPresence implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public readonly int $userId) {}

    public function handle(ChatPresence $presence): void
    {
        Cache::forget('chat:presence:pending:'.$this->userId);
        Cache::put('chat:presence:published:'.$this->userId, 1, 60);
        $presence->publish($this->userId);
    }
}
