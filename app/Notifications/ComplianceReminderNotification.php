<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Fired when a manager nudges a staff member about an outstanding or expiring
 * compliance requirement from the Compliance hub (row menu, renewals sheet or the
 * bulk "Send reminders" action). Real database + mail delivery — no stubbed toast.
 */
class ComplianceReminderNotification extends Notification
{
    use Queueable;

    public function __construct(
        public string $requirementName,
        public ?string $expiryDate = null,
        public ?string $senderName = null,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject("Compliance reminder: {$this->requirementName}")
            ->greeting('Hello ' . ($notifiable->name ?? 'there') . ',')
            ->line("This is a reminder about your **{$this->requirementName}** compliance requirement.");

        if ($this->expiryDate) {
            $mail->line("It is recorded as due / expiring on **{$this->expiryDate}**.");
        }

        return $mail
            ->line('Please arrange renewal as soon as possible to remain eligible for shifts.')
            ->action('View my compliance', url('/hr/my'));
    }

    public function toArray(object $notifiable): array
    {
        return [
            'title' => 'Compliance reminder',
            'message' => "Reminder: {$this->requirementName}"
                . ($this->expiryDate ? " — due {$this->expiryDate}" : '')
                . '.',
            'requirement_name' => $this->requirementName,
            'expiry_date' => $this->expiryDate,
            'sender_name' => $this->senderName,
            'category' => 'compliance',
        ];
    }
}
