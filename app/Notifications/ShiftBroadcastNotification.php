<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ShiftBroadcastNotification extends Notification
{
    use Queueable;

    public function __construct(
        public int $shiftId,
        public string $shiftDate,
        public string $shiftTime,
        public ?string $clientName,
        public ?string $siteName,
        public ?string $message = null,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $clientLabel = $this->clientName ?: 'an unassigned shift';
        $siteLabel = $this->siteName ? " at {$this->siteName}" : '';

        $mail = (new MailMessage)
            ->subject("Open shift needs cover: {$clientLabel} on {$this->shiftDate}")
            ->greeting('Kia ora '.($notifiable->name ?? 'there').',')
            ->line("An open shift needs cover for {$clientLabel}{$siteLabel}.")
            ->line("Date: {$this->shiftDate}")
            ->line("Time: {$this->shiftTime}");

        if ($this->message) {
            $mail->line($this->message);
        }

        return $mail
            ->action('View open shifts', url('/operations/rostering'))
            ->line('If you can cover this shift, reach out to your scheduler.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'title' => 'Open shift needs cover',
            'message' => trim(sprintf(
                'Open shift on %s (%s) for %s%s.%s',
                $this->shiftDate,
                $this->shiftTime,
                $this->clientName ?: 'an unassigned shift',
                $this->siteName ? " at {$this->siteName}" : '',
                $this->message ? ' '.$this->message : '',
            )),
            'shift_id' => $this->shiftId,
            'client_name' => $this->clientName,
            'site_name' => $this->siteName,
            'shift_date' => $this->shiftDate,
            'shift_time' => $this->shiftTime,
            'broadcast_message' => $this->message,
        ];
    }
}
