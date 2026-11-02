<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * A Security & devices alert needs someone (S&D Phase D): it's still open after an hour, the
 * person who acknowledged it asked to be reminded, or someone was asked to look after it.
 */
class SecurityDeviceAlertNotification extends Notification
{
    use Queueable;

    /** @param  list<string>  $channels  database and/or mail */
    public function __construct(
        public readonly int $alertId,
        public readonly string $severity,
        public readonly string $title,
        public readonly string $line,
        public readonly array $channels,
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
        if (in_array('push', $this->channels, true)) {
            $resolved[] = Channels\PushChannel::class;
        }

        return $resolved;
    }

    /** @return array{title: string, body: string, data: array<string, string>} */
    public function toPush(object $notifiable): array
    {
        return ['title' => $this->title, 'body' => $this->line, 'data' => ['url' => $this->url()]];
    }

    public function url(): string
    {
        return '/security-devices/alerts?alert='.$this->alertId;
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(($this->severity === 'critical' ? 'Needs someone: ' : 'Alert: ').$this->title)
            ->greeting('Kia ora '.($notifiable->name ?? 'team').',')
            ->line($this->title.'.')
            ->line($this->line.'.')
            ->action('Open the alert', url($this->url()));
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'security_device_alert',
            'severity' => $this->severity,
            'title' => $this->title,
            'body' => $this->line,
            'message' => $this->line,
            'url' => $this->url(),
            'action_url' => $this->url(),
            'context' => ['alert_id' => $this->alertId],
        ];
    }
}
