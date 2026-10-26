<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class MonitoringCheckAlertNotification extends Notification
{
    use Queueable;

    /**
     * @param  list<string>  $channels
     * @param  array<string, scalar|null>  $context
     */
    public function __construct(
        public readonly string $severity,
        public readonly string $title,
        public readonly string $summary,
        public readonly string $actionUrl,
        public readonly array $channels,
        public readonly array $context = [],
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
        $prefix = $this->severity === 'critical' ? 'Critical network check' : 'Network check warning';

        return (new MailMessage)
            ->subject($prefix.': '.$this->title)
            ->greeting('Kia ora '.($notifiable->name ?? 'team').',')
            ->line($this->summary)
            ->action('Open device monitoring', url($this->actionUrl))
            ->line('Review the latest observation and linked operational work before closing.');
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'monitoring_check_alert',
            'severity' => $this->severity,
            'title' => $this->title,
            'body' => $this->summary,
            'message' => $this->summary,
            'url' => $this->actionUrl,
            'action_url' => $this->actionUrl,
            'context' => $this->context,
        ];
    }
}
