<?php

namespace App\Notifications\Fleet;

use App\Models\Asset;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class FleetGeofenceBreachNotification extends Notification
{
    use Queueable;

    public function __construct(
        public int $assetId,
        public string $vehicleName,
        public string $geofenceName,
        public ?int $signalId = null,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Geofence Breach: {$this->vehicleName}")
            ->greeting('Kia ora ' . ($notifiable->name ?? 'there') . ',')
            ->line("**{$this->vehicleName}** has left the designated geofence: **{$this->geofenceName}**.")
            ->action('View Live Map', url('/fleet-assets/live-map'))
            ->line('Please investigate immediately.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'title' => 'Geofence Breach Detected',
            'message' => "{$this->vehicleName} left geofence: {$this->geofenceName}",
            'module' => 'fleet',
            'asset_id' => $this->assetId,
            'geofence_name' => $this->geofenceName,
            'signal_id' => $this->signalId,
        ];
    }
}
