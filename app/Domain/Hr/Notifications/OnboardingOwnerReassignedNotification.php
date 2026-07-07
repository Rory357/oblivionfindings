<?php

namespace App\Domain\Hr\Notifications;

use App\Domain\Hr\Models\HrOnboardingChecklist;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class OnboardingOwnerReassignedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly HrOnboardingChecklist $checklist,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $subjectName = $this->checklist->employeeProfile?->user?->name ?? 'a new starter';

        return (new MailMessage)
            ->subject("Onboarding handed to you — {$subjectName}")
            ->greeting("Hello {$notifiable->name},")
            ->line("You are now the owner of {$subjectName}'s onboarding checklist.")
            ->line('You will be notified as tasks complete, and the checklist rolls up to you until everything required is done.')
            ->action('Open Checklist', url("/hr/onboarding/{$this->checklist->id}"));
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'onboarding_owner_reassigned',
            'checklist_id' => $this->checklist->id,
            'subject_name' => $this->checklist->employeeProfile?->user?->name,
            'action_url' => "/hr/onboarding/{$this->checklist->id}",
        ];
    }
}
