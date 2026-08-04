<?php

namespace App\Notifications\SecurityDevices;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

final class DeviceCommandBreakGlassDeclaredNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly int $commandId,
        private readonly int $deviceId,
        private readonly string $deviceName,
        private readonly string $siteName,
        private readonly string $capabilityLabel,
        private readonly string $requesterName,
        private readonly string $reviewDueAt,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, int|string> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'security_devices_command_break_glass_declared',
            'title' => 'Emergency command review required',
            'message' => "{$this->requesterName} declared break glass for {$this->capabilityLabel} on {$this->deviceName} at {$this->siteName}.",
            'command_id' => $this->commandId,
            'device_id' => $this->deviceId,
            'review_due_at' => $this->reviewDueAt,
            'action_url' => "/security-devices/devices/{$this->deviceId}?section=management",
        ];
    }
}
