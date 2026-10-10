<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

/**
 * B10 (EA-188): 14 days before an outside prescriber's access, their
 * verified identity or a picture-catalogue source review ends, the person
 * who granted, verified or owns it is told — once — so it can be renewed or
 * deliberately left to end. The bell names no medicine.
 */
class ConnectedCareExpiryNotification extends Notification
{
    public function __construct(
        public string $reminderKey,
        public string $title,
        public string $message,
        public string $actionUrl,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'connected_care_expiry',
            'reminder_key' => $this->reminderKey,
            'title' => $this->title,
            'message' => $this->message,
            'severity' => 'warning',
            'action_url' => $this->actionUrl,
        ];
    }
}
