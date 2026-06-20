<?php

namespace App\Notifications\HealthSafety;

use App\Console\Commands\InjuryReviewReminders;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Injuries & RTW — daily review-reminder digest delivered to the responsible
 * manager (in-app, via the database channel / notification bell). Sent by
 * {@see InjuryReviewReminders}. Database-only so the scheduled run never depends
 * on mail configuration; add 'mail' to via() once SMTP is configured.
 */
class InjuryReviewDueNotification extends Notification
{
    use Queueable;

    public function __construct(
        public int $rtwReviewsDue,
        public int $capacityFollowupsDue,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $parts = [];
        if ($this->rtwReviewsDue > 0) {
            $parts[] = "{$this->rtwReviewsDue} return-to-work review".($this->rtwReviewsDue === 1 ? '' : 's').' due';
        }
        if ($this->capacityFollowupsDue > 0) {
            $parts[] = "{$this->capacityFollowupsDue} capacity reassessment".($this->capacityFollowupsDue === 1 ? '' : 's').' due';
        }

        return [
            'kind' => 'injury_review_due',
            'title' => 'Injury reviews need your attention',
            'body' => 'You have '.implode(' and ', $parts).' on workplace injuries you manage.',
            'rtw_reviews_due' => $this->rtwReviewsDue,
            'capacity_followups_due' => $this->capacityFollowupsDue,
            'url' => '/health-safety/injuries?tab=return_to_work',
        ];
    }
}
