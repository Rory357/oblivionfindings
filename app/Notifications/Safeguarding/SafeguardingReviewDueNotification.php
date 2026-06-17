<?php

namespace App\Notifications\Safeguarding;

use App\Console\Commands\SafeguardingReviewReminders;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Safeguarding W9 — the daily review-reminder digest delivered to a concern's
 * assigned lead (in-app, via the database channel / notification bell).
 *
 * Sent by {@see SafeguardingReviewReminders}. Database-only
 * so the scheduled run never depends on mail configuration; add 'mail' to via()
 * once SMTP is configured if an emailed digest is also wanted.
 */
class SafeguardingReviewDueNotification extends Notification
{
    use Queueable;

    public function __construct(
        public int $reviewsDue,
        public int $acksAwaited,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $parts = [];
        if ($this->reviewsDue > 0) {
            $parts[] = "{$this->reviewsDue} risk review".($this->reviewsDue === 1 ? '' : 's').' due';
        }
        if ($this->acksAwaited > 0) {
            $parts[] = "{$this->acksAwaited} authority acknowledgement".($this->acksAwaited === 1 ? '' : 's').' awaited';
        }

        return [
            'kind' => 'safeguarding_review_due',
            'title' => 'Safeguarding reviews need your attention',
            'body' => 'You have '.implode(' and ', $parts).' on safeguarding concerns assigned to you.',
            'reviews_due' => $this->reviewsDue,
            'acks_awaited' => $this->acksAwaited,
            'url' => '/safeguarding?tab=reviews',
        ];
    }
}
