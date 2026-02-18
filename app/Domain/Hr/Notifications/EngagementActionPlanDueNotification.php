<?php

namespace App\Domain\Hr\Notifications;

use App\Domain\Hr\Models\HrEngagementActionPlan;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class EngagementActionPlanDueNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly HrEngagementActionPlan $plan,
        private readonly int $daysUntilDue,
        private readonly string $reminderKind,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $subject = $this->reminderKind === 'overdue'
            ? 'Action plan overdue'
            : 'Action plan due soon';

        $line = $this->reminderKind === 'overdue'
            ? "This engagement action plan is overdue by " . abs($this->daysUntilDue) . " day(s)."
            : "This engagement action plan is due in {$this->daysUntilDue} day(s).";

        return (new MailMessage)
            ->subject($subject)
            ->line($line)
            ->line('Plan: ' . $this->plan->title)
            ->line('Due date: ' . (optional($this->plan->due_date)->toDateString() ?? 'Not set'))
            ->action('Open Wellbeing Dashboard', url('/hr/wellbeing'));
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'hr_engagement_action_plan_due',
            'action_plan_id' => $this->plan->id,
            'survey_id' => $this->plan->survey_id,
            'title' => $this->plan->title,
            'priority' => $this->plan->priority,
            'status' => $this->plan->status,
            'progress_percent' => (int) $this->plan->progress_percent,
            'due_date' => optional($this->plan->due_date)->toDateString(),
            'days_until_due' => $this->daysUntilDue,
            'reminder_kind' => $this->reminderKind,
            'url' => '/hr/wellbeing',
        ];
    }
}

