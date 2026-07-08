<?php

namespace App\Domain\Hr\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/**
 * Sent to the author of a kudos or feed post when someone replies to it.
 * Database-only (the header bell) — a social wall shouldn't email on every
 * reply. Deep-links back to the community feed.
 */
class FeedReplyNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  string  $context  what was replied to, e.g. "your kudos" / "your post"
     */
    public function __construct(
        private string $replierName,
        private string $context,
        private string $snippet,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'feed_reply',
            'title' => "{$this->replierName} replied to {$this->context}",
            'message' => Str::limit($this->snippet, 140),
            'url' => '/hr/feed',
        ];
    }
}
