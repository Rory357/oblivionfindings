<?php

namespace App\Domain\Hr\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Weekly summary of an owner's OKR portfolio — counts by confidence, overdue
 * and check-ins due. Built from a plain stats array so the same notification
 * serves owners and managers.
 */
class GoalWeeklyDigestNotification extends Notification
{
    use Queueable;

    /**
     * @param  array{on_track:int,at_risk:int,off_track:int,overdue:int,checkins_due:int,avg_progress:int}  $stats
     */
    public function __construct(private readonly array $stats) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $s = $this->stats;

        return (new MailMessage)
            ->subject('Your weekly OKR digest')
            ->line('Here is where your objectives stand this week.')
            ->line("On track: {$s['on_track']} · At risk: {$s['at_risk']} · Off track: {$s['off_track']}")
            ->line("Overdue: {$s['overdue']} · Check-ins due: {$s['checkins_due']} · Avg progress: {$s['avg_progress']}%")
            ->action('Open Goals & OKRs', url('/hr/goals'));
    }

    public function toArray(object $notifiable): array
    {
        $s = $this->stats;

        return [
            'type' => 'hr_goal_weekly_digest',
            'title' => 'Your weekly OKR digest',
            'message' => "On track {$s['on_track']} · At risk {$s['at_risk']} · Off track {$s['off_track']} · {$s['checkins_due']} check-ins due",
            'url' => '/hr/goals',
            'context' => [
                'On track' => $s['on_track'],
                'At risk' => $s['at_risk'],
                'Off track' => $s['off_track'],
                'Overdue' => $s['overdue'],
                'Check-ins due' => $s['checkins_due'],
                'Avg progress' => $s['avg_progress'].'%',
            ],
        ];
    }
}
