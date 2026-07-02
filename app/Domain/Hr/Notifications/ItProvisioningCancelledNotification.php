<?php

namespace App\Domain\Hr\Notifications;

use App\Domain\Hr\Models\HrOnboardingTask;
use App\Models\ItProvisioningRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Warns the onboarding checklist owner that an IT provisioning request raised
 * from one of their checklist tasks was cancelled in the /it queue — the task
 * stays open and needs to be resolved (or removed) manually.
 */
class ItProvisioningCancelledNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private ItProvisioningRequest $provisioning,
        private HrOnboardingTask $task,
        private ?string $reason = null,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $employeeName = $this->task->checklist?->employeeProfile?->user?->name ?? 'a new hire';

        return (new MailMessage)
            ->subject("IT request cancelled — “{$this->provisioning->item}”")
            ->greeting("Hello {$notifiable->name},")
            ->line("An IT provisioning request raised from {$employeeName}'s onboarding checklist has been cancelled:")
            ->line("**Item:** {$this->provisioning->item}")
            ->when($this->reason, fn ($mail) => $mail->line("**Reason:** {$this->reason}"))
            ->line("The onboarding task **{$this->task->title}** is still open — please resolve it manually (complete it another way, or remove it from the checklist).")
            ->action('Open onboarding', url('/hr/onboarding'))
            ->line('This was sent because you created the checklist.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'it_provisioning_cancelled',
            'provisioning_request_id' => $this->provisioning->id,
            'item' => $this->provisioning->item,
            'reason' => $this->reason,
            'task_id' => $this->task->id,
            'task_title' => $this->task->title,
            'checklist_id' => $this->task->checklist_id,
            'employee_name' => $this->task->checklist?->employeeProfile?->user?->name,
            'action_url' => '/hr/onboarding',
        ];
    }
}
