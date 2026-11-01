<?php

namespace App\Notifications;

use App\Notifications\Channels\PushChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** A network scan found devices Oblivion doesn't know yet (S&D Phase D, one notice per scan). */
class SecurityDevicesFoundNotification extends Notification
{
    use Queueable;

    /** @param  list<string>  $channels  database, mail and/or push */
    public function __construct(
        public readonly int $runId,
        public readonly int $found,
        public readonly string $where,
        public readonly array $channels,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return array_values(array_filter([
            in_array('database', $this->channels, true) ? 'database' : null,
            in_array('mail', $this->channels, true) && ! empty($notifiable->email) ? 'mail' : null,
            in_array('push', $this->channels, true) ? PushChannel::class : null,
        ]));
    }

    public function title(): string
    {
        return $this->found === 1 ? 'A scan found 1 new device' : "A scan found {$this->found} new devices";
    }

    public function url(): string
    {
        return '/security-devices/scans/'.$this->runId;
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->title())
            ->greeting('Kia ora '.($notifiable->name ?? 'team').',')
            ->line($this->title().' at '.$this->where.'.')
            ->line('Add the ones you want watched, or ignore them.')
            ->action('Open Found devices', url($this->url()));
    }

    /** @return array{title: string, body: string, data: array<string, string>} */
    public function toPush(object $notifiable): array
    {
        return ['title' => $this->title(), 'body' => $this->where.' · add or ignore them', 'data' => ['url' => $this->url()]];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'security_devices_found',
            'title' => $this->title(),
            'body' => $this->where.' · add or ignore them',
            'message' => $this->title().' at '.$this->where,
            'url' => $this->url(),
            'action_url' => $this->url(),
            'context' => ['run_id' => $this->runId],
        ];
    }
}
