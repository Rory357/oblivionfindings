<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\Chat\OblivionLive;
use App\Services\Operations\OpsMessageVisibilityService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Sends each person their own unread count (unread.changed) after their badge cache was cleared. */
final class PublishChatUnread implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    /** @param  array<int, int>  $userIds */
    public function __construct(public readonly array $userIds)
    {
        $this->afterCommit();
    }

    public function handle(OpsMessageVisibilityService $visibility, OblivionLive $live): void
    {
        $events = [];
        foreach (User::whereIn('id', $this->userIds)->get() as $user) {
            $events[] = ['to' => [(int) $user->id], 'type' => 'unread.changed', 'data' => ['total' => $visibility->cachedUnreadCount($user)]];
        }
        $live->publishMany($events);
    }
}
