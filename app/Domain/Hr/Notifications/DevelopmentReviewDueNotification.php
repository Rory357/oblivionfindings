<?php

namespace App\Domain\Hr\Notifications;

use App\Domain\Hr\Models\HrDevelopmentGoal;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** A development plan's scheduled review is due. */
class DevelopmentReviewDueNotification extends Notification
{
    use Queueable;

    public function __construct(private readonly HrDevelopmentGoal $plan) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Development review due: '.$this->plan->title)
            ->line('A development plan review is due.')
            ->line('Plan: '.$this->plan->title)
            ->line('Cadence: '.ucfirst((string) $this->plan->review_frequency))
            ->action('Open development plans', url('/hr/goals?tab=development'));
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'hr_development_review_due',
            'title' => 'Development review due: '.$this->plan->title,
            'message' => 'A development plan review is due.',
            'url' => '/hr/goals?tab=development',
            'development_goal_id' => $this->plan->id,
        ];
    }
}
