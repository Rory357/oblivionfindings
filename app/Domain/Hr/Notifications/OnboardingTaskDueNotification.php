<?php

namespace App\Domain\Hr\Notifications;

use App\Domain\Hr\Models\HrOnboardingTask;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Reminder to an onboarding task assignee that their task is overdue or due
 * soon. Dispatched by the hr:onboarding-reminders command.
 */
class OnboardingTaskDueNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /** @param  'overdue'|'due_soon'  $reason */
    public function __construct(
        private HrOnboardingTask $task,
        private string $reason = 'overdue',
    ) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $title = $this->task->title;
        $employee = $this->task->checklist?->employeeProfile?->user?->name ?? 'a new employee';
        $verb = $this->reason === 'overdue' ? 'is overdue' : 'is due soon';

        return (new MailMessage)
            ->subject("Onboarding task {$verb}: {$title}")
            ->greeting("Hello {$notifiable->name},")
            ->line("An onboarding task assigned to you {$verb}:")
            ->line("**Task:** {$title}")
            ->line("**Employee:** {$employee}")
            ->when($this->task->due_date, fn ($mail) => $mail->line('**Due:** '.$this->task->due_date->format('d/m/Y')))
            ->action('Open checklist', url('/hr/onboarding/'.$this->task->checklist_id))
            ->line('Please action this as soon as you can.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'onboarding_task_due',
            'reason' => $this->reason,
            'task_title' => $this->task->title,
            'employee_name' => $this->task->checklist?->employeeProfile?->user?->name,
            'task_id' => $this->task->id,
            'checklist_id' => $this->task->checklist_id,
            'action_url' => '/hr/onboarding/'.$this->task->checklist_id,
        ];
    }
}
