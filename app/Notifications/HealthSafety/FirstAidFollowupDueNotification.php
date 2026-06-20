<?php

namespace App\Notifications\HealthSafety;

use App\Console\Commands\FirstAidFollowupReminders;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * First Aid Register — daily follow-up reminder digest delivered to the assigned
 * responder (in-app, via the database channel / notification bell). Sent by
 * {@see FirstAidFollowupReminders}. Database-only so the scheduled run never
 * depends on mail configuration; add 'mail' to via() once SMTP is configured.
 */
class FirstAidFollowupDueNotification extends Notification
{
    use Queueable;

    public function __construct(
        public int $followupsDue,
        public int $overdue,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'first_aid_followup_due',
            'title' => 'First aid follow-ups need your attention',
            'body' => "You have {$this->followupsDue} first-aid follow-up(s) due ({$this->overdue} overdue).",
            'followups_due' => $this->followupsDue,
            'overdue' => $this->overdue,
            'url' => '/health-safety/first-aid?tab=followup',
        ];
    }
}
