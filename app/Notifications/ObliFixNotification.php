<?php

namespace App\Notifications;

use App\Notifications\Channels\PushChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * S&D Phase I: tells IT that Obli needs them (a one-off fix to approve, pop-up 95) or that a staff
 * computer had no internet (an offline report). Sent through the same channels as device alerts
 * (Setup › Alerts & checks). Never carries what the person typed: only where, and a link.
 */
class ObliFixNotification extends Notification
{
    use Queueable;

    /** @param  list<string>  $channels  database, mail and/or push */
    public function __construct(
        public readonly string $kind,
        public readonly string $title,
        public readonly string $where,
        public readonly string $path,
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

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->title)
            ->greeting('Kia ora '.($notifiable->name ?? 'team').',')
            ->line($this->title.' · '.$this->where.'.')
            ->action($this->kind === 'request' ? 'Review the request' : 'Open the computer', url($this->path));
    }

    /** @return array{title: string, body: string, data: array<string, string>} */
    public function toPush(object $notifiable): array
    {
        return ['title' => $this->title, 'body' => $this->where, 'data' => ['url' => $this->path]];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'obli_fix_'.$this->kind,
            'title' => $this->title,
            'body' => $this->where,
            'message' => $this->title.' · '.$this->where,
            'url' => $this->path,
            'action_url' => $this->path,
        ];
    }
}
