<?php

namespace App\Domain\Hr\Notifications;

use App\Domain\Hr\Models\HrOnboardingChecklist;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Notify the checklist owner that an onboarding checklist has been completed.
 */
class OnboardingChecklistCompletedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private HrOnboardingChecklist $checklist,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $employee = $this->checklist->employeeProfile?->user?->name ?? 'A new employee';

        return (new MailMessage)
            ->subject("Onboarding complete: {$employee}")
            ->greeting("Hello {$notifiable->name},")
            ->line("{$employee}'s onboarding checklist is now complete — every required task is done.")
            ->action('View checklist', url('/hr/onboarding/'.$this->checklist->id));
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'onboarding_checklist_completed',
            'employee_name' => $this->checklist->employeeProfile?->user?->name,
            'checklist_id' => $this->checklist->id,
            'action_url' => '/hr/onboarding/'.$this->checklist->id,
        ];
    }
}
