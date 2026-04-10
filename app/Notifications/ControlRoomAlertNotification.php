<?php

namespace App\Notifications;

use App\Models\ControlRoomAlert;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class ControlRoomAlertNotification extends Notification
{
    use Queueable;

    public function __construct(
        protected ControlRoomAlert $alert,
        protected array $extraContext = [],
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return array_merge([
            'type' => 'control_room_alert',
            'alert_id' => $this->alert->id,
            'severity' => $this->alert->severity,
            'status' => $this->alert->status,
            'alert_type' => $this->alert->alert_type,
            'source' => $this->alert->source,
            'triggered_at' => $this->alert->triggered_at?->toISOString(),
            'escalation_level' => $this->alert->escalation_level,
        ], $this->extraContext);
    }
}
