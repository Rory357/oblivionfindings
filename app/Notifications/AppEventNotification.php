<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class AppEventNotification extends Notification
{
    use Queueable;

    /**
     * @param array $payload Stored in notifications.data
     */
    public function __construct(public array $payload)
    {
    }

    public function via($notifiable): array
    {
        return ['database'];
    }

    public function toArray($notifiable): array
    {
        // Keep payload small and UI-friendly.
        return $this->payload + [
            'emitted_at' => now()->toISOString(),
        ];
    }
}
