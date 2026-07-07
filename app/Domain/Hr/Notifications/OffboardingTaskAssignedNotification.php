<?php

namespace App\Domain\Hr\Notifications;

use App\Domain\Hr\Models\HrOffboardingTask;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class OffboardingTaskAssignedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private HrOffboardingTask $task
    ) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $leaverName = $this->task->checklist?->employeeProfile?->user?->name ?? 'a departing staff member';
        $dueDate = $this->task->due_date
            ? \Illuminate\Support\Carbon::parse($this->task->due_date)->format('l, F j, Y')
            : 'No due date set';

        return (new MailMessage)
            ->subject("Offboarding Task — {$this->task->title}")
            ->greeting("Hello {$notifiable->name},")
            ->line("You have an offboarding task for {$leaverName}'s departure:")
            ->line("**Task:** {$this->task->title}")
            ->line("**Due:** {$dueDate}")
            ->line('Departure tasks gate system access and equipment recovery — please complete it before the leaver\'s last day.')
            ->action('Open Offboarding', url("/hr/offboarding/{$this->task->offboarding_checklist_id}"));
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'offboarding_task_assigned',
            'task_id' => $this->task->id,
            'task_title' => $this->task->title,
            'due_date' => $this->task->due_date,
            'leaver_name' => $this->task->checklist?->employeeProfile?->user?->name,
            'action_url' => "/hr/offboarding/{$this->task->offboarding_checklist_id}",
        ];
    }
}
