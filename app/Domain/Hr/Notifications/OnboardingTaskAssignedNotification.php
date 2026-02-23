<?php

namespace App\Domain\Hr\Notifications;

use App\Domain\Hr\Models\HrOnboardingTask;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class OnboardingTaskAssignedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private HrOnboardingTask $task
    ) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $title = $this->task->title;
        $employeeName = $this->task->checklist?->employeeProfile?->user?->name
            ?? 'a new employee';

        return (new MailMessage)
            ->subject("Onboarding Task: {$title}")
            ->greeting("Hello {$notifiable->name},")
            ->line("You have been assigned an onboarding task:")
            ->line("**Task:** {$title}")
            ->line("**Employee:** {$employeeName}")
            ->line("**Category:** " . ucfirst($this->task->category ?? 'General'))
            ->when($this->task->description, fn ($mail) => $mail->line("**Details:** {$this->task->description}"))
            ->action('View Task', url('/hr/onboarding'))
            ->line('Please complete this task as soon as possible.');
    }

    public function toArray(object $notifiable): array
    {
        $employeeName = $this->task->checklist?->employeeProfile?->user?->name ?? 'a new employee';

        return [
            'type'          => 'onboarding_task_assigned',
            'title'         => "Onboarding Task: {$this->task->title}",
            'message'       => "You have been assigned an onboarding task for {$employeeName}.",
            'task_title'    => $this->task->title,
            'employee_name' => $employeeName,
            'task_id'       => $this->task->id,
            'checklist_id'  => $this->task->checklist_id,
            'url'           => '/hr/onboarding',
            'context'       => [
                'Task' => $this->task->title,
                'Employee' => $employeeName,
                'Category' => ucfirst($this->task->category ?? 'General'),
            ],
        ];
    }
}
