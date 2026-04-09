<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ShiftEligibilityWarningNotification extends Notification
{
    use Queueable;

    /**
     * @param  int         $shiftId
     * @param  string      $staffName
     * @param  string      $shiftDate
     * @param  string      $siteName
     * @param  string[]    $blockingReasons
     * @param  string|null $shiftUrl
     */
    public function __construct(
        public int $shiftId,
        public string $staffName,
        public string $shiftDate,
        public string $siteName,
        public array $blockingReasons,
        public ?string $shiftUrl = null,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $reasonList = implode("\n", array_map(fn ($r) => "• {$r}", $this->blockingReasons));

        return (new MailMessage)
            ->subject("Shift Eligibility Alert: {$this->staffName} on {$this->shiftDate}")
            ->greeting('Hello ' . ($notifiable->name ?? 'there') . ',')
            ->line("A future shift at **{$this->siteName}** on **{$this->shiftDate}** assigned to **{$this->staffName}** has become ineligible.")
            ->line('**Reasons:**')
            ->line($reasonList)
            ->action('Review Shift', $this->shiftUrl ?? url("/shifts/{$this->shiftId}"))
            ->line('Please reassign this shift or resolve the eligibility issue.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'title' => 'Shift Eligibility Alert',
            'message' => "{$this->staffName} is no longer eligible for their shift at {$this->siteName} on {$this->shiftDate}.",
            'shift_id' => $this->shiftId,
            'staff_name' => $this->staffName,
            'site_name' => $this->siteName,
            'shift_date' => $this->shiftDate,
            'blocking_reasons' => $this->blockingReasons,
            'url' => $this->shiftUrl ?? url("/shifts/{$this->shiftId}"),
        ];
    }
}
