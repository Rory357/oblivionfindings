<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * An automation tells people (S&D Phase F): its "Tell people" action, or what one of its checks
 * found (a door not locked, the alarm not armed) for the staff on shift to sort out by hand. A test
 * run (pop-up 41) sends this only to the person testing, with "TEST" in front.
 */
class SecurityDeviceAutomationNotification extends Notification
{
    use Queueable;

    /** @param  list<string>  $channels  database, mail and/or push */
    public function __construct(
        public readonly int $automationId,
        public readonly int $siteId,
        public readonly string $title,
        public readonly string $line,
        public readonly array $channels = ['database'],
        public readonly string $severity = 'info',
        public readonly bool $test = false,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        $resolved = ['database'];
        if (in_array('mail', $this->channels, true) && ! empty($notifiable->email)) {
            $resolved[] = 'mail';
        }
        if (in_array('push', $this->channels, true)) {
            $resolved[] = Channels\PushChannel::class;
        }

        return $resolved;
    }

    public function url(): string
    {
        return '/security-devices/alerts?tab=rules&site='.$this->siteId;
    }

    /** @return array{title: string, body: string, data: array<string, string>} */
    public function toPush(object $notifiable): array
    {
        return ['title' => $this->title, 'body' => $this->line, 'data' => ['url' => $this->url()]];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->title)
            ->greeting('Kia ora '.($notifiable->name ?? 'team').',')
            ->line($this->line)
            ->action('Open Security & devices', url($this->url()));
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'security_device_automation',
            'severity' => $this->severity,
            'title' => $this->title,
            'body' => $this->line,
            'message' => $this->line,
            'url' => $this->url(),
            'action_url' => $this->url(),
            'context' => ['automation_id' => $this->automationId, 'site_id' => $this->siteId, 'test' => $this->test],
        ];
    }
}
