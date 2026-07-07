<?php

namespace App\Domain\Hr\Notifications;

use App\Domain\Hr\Models\HrDevelopmentGoal;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent to the manager when a development goal they set is completed — the
 * counterpart to DevelopmentGoalAssignedNotification (which tells the employee
 * on assignment).
 */
class DevelopmentGoalCompletedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private HrDevelopmentGoal $goal,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $employeeName = $this->goal->employee?->name ?? 'Your team member';

        return (new MailMessage)
            ->subject("Development goal completed — {$employeeName}")
            ->greeting('Hello,')
            ->line("{$employeeName} has completed the development goal \"{$this->goal->title}\".")
            ->action('View development goals', url('/hr/goals?tab=development'));
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'hr_development_goal_completed',
            'title' => "Development goal completed: {$this->goal->title}",
            'message' => ($this->goal->employee?->name ?? 'A team member').' completed a development goal.',
            'goal_id' => $this->goal->id,
            'url' => '/hr/goals?tab=development',
        ];
    }
}
