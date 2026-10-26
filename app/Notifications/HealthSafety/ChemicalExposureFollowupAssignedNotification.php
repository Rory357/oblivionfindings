<?php

namespace App\Notifications\HealthSafety;

use Illuminate\Notifications\Notification;

/** Database only. Source body, worker, Site, files and associations never enter the inbox payload. */
final class ChemicalExposureFollowupAssignedNotification extends Notification
{
    public function __construct(public readonly int $deliveryId) {}

    public function via($notifiable): array { return ['database']; }

    public function toArray($notifiable): array
    {
        return ['kind' => 'private_followup', 'title' => 'Assigned follow-up', 'body' => null,
            'url' => null, 'delivery_id' => $this->deliveryId];
    }
}
