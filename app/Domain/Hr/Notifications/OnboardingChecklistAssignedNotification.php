<?php

namespace App\Domain\Hr\Notifications;

use App\Domain\Hr\Models\HrOnboardingChecklist;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class OnboardingChecklistAssignedNotification extends Notification implements ShouldQueue
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
        return (new MailMessage)
            ->subject('Your onboarding checklist is ready')
            ->greeting("Hello {$notifiable->name},")
            ->line('Your onboarding checklist has been created.')
            ->line("Tasks assigned: {$this->checklist->tasks()->count()}")
            ->action('View Onboarding', url('/hr/my'))
            ->line('Please review your onboarding tasks and complete any items assigned to you.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'onboarding_checklist_assigned',
            'checklist_id' => $this->checklist->id,
            'task_count' => $this->checklist->tasks()->count(),
            'due_date' => $this->checklist->due_date?->toDateString(),
            'action_url' => '/hr/my',
        ];
    }
}
