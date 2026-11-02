<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Clock-ins from doors (S&D Phase H, D15): the notice staff get before it starts at their Site,
 * a suggested clock-in after a badge, a reminder, a gap for the house manager, or a manager's
 * question about a clock-in. In the app, and by email only where Setup says so.
 */
class DoorClockinNotification extends Notification
{
    use Queueable;

    /** @param  list<string>  $channels  database and/or mail */
    public function __construct(
        public readonly string $kind,
        public readonly string $title,
        public readonly string $body,
        public readonly string $url,
        public readonly array $channels,
        public readonly ?int $clockinId = null,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        $resolved = [];
        if (in_array('database', $this->channels, true)) {
            $resolved[] = 'database';
        }
        if (in_array('mail', $this->channels, true) && ! empty($notifiable->email)) {
            $resolved[] = 'mail';
        }

        return $resolved;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject($this->title)
            ->greeting('Kia ora '.($notifiable->name ?? 'team').',');
        foreach (preg_split('/\R{2,}/', $this->body) ?: [$this->body] as $paragraph) {
            $mail->line(trim($paragraph));
        }

        return $mail->action($this->kind === 'notice' ? 'Open My Day' : 'Open it in Oblivion', url($this->url));
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'door_clockin_'.$this->kind,
            'title' => $this->title,
            'body' => $this->body,
            'message' => $this->body,
            'url' => $this->url,
            'action_url' => $this->url,
            'context' => array_filter(['door_clockin_id' => $this->clockinId]),
        ];
    }
}
