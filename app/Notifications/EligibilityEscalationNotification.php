<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class EligibilityEscalationNotification extends Notification
{
    use Queueable;

    public function __construct(
        public int $shiftId,
        public string $staffName,
        public string $shiftDate,
        public string $siteName,
        public string $blockingReason,
        public string $unresolvedSince,
        public int $hoursUnresolved,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("ESCALATION: Unresolved shift eligibility — {$this->staffName} on {$this->shiftDate}")
            ->greeting('Hello ' . ($notifiable->name ?? 'there') . ',')
            ->line("A future shift at **{$this->siteName}** on **{$this->shiftDate}** assigned to **{$this->staffName}** has been flagged as ineligible for **{$this->hoursUnresolved} hours** and remains unresolved.")
            ->line("**Reason:** {$this->blockingReason}")
            ->line("**First detected:** {$this->unresolvedSince}")
            ->action('Review Shift', url("/shifts/{$this->shiftId}"))
            ->line('Please reassign this shift or resolve the eligibility issue urgently.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'title' => 'Eligibility Escalation',
            'message' => "{$this->staffName} has been ineligible for their shift at {$this->siteName} on {$this->shiftDate} for {$this->hoursUnresolved}h — unresolved.",
            'shift_id' => $this->shiftId,
            'staff_name' => $this->staffName,
            'site_name' => $this->siteName,
            'shift_date' => $this->shiftDate,
            'blocking_reason' => $this->blockingReason,
            'unresolved_since' => $this->unresolvedSince,
            'hours_unresolved' => $this->hoursUnresolved,
            'url' => url("/shifts/{$this->shiftId}"),
        ];
    }
}
