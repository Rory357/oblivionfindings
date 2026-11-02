<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * A house mode tells the staff on shift (S&D Phase F): the house switched mode, or a mode's check
 * found a door not locked or the alarm not armed, to lock or arm by hand. In the app only.
 */
class HouseModeNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly int $siteId,
        public readonly string $title,
        public readonly string $line,
        public readonly string $severity = 'info',
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function url(): string
    {
        return '/security-devices/sites/'.$this->siteId.'?tab=modes';
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'security_device_house_mode',
            'severity' => $this->severity,
            'title' => $this->title,
            'body' => $this->line,
            'message' => $this->line,
            'url' => $this->url(),
            'action_url' => $this->url(),
            'context' => ['site_id' => $this->siteId],
        ];
    }
}
