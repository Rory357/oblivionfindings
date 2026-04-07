<?php

namespace App\Notifications\Fleet;

use App\Models\FleetVehicleBooking;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class FleetVehicleOverdueNotification extends Notification
{
    use Queueable;

    public function __construct(
        public FleetVehicleBooking $booking,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $vehicleName = $this->booking->asset?->name ?? 'Vehicle';
        $endsAt = optional($this->booking->ends_at)->format('d M Y H:i') ?? 'unknown';

        return (new MailMessage)
            ->subject("Vehicle Return Overdue: {$vehicleName}")
            ->greeting('Kia ora ' . ($notifiable->name ?? 'there') . ',')
            ->line("**{$vehicleName}** was due back at **{$endsAt}** and has not been returned.")
            ->action('View Booking', url('/fleet-assets/bookings/' . $this->booking->id))
            ->line('Please return the vehicle or contact the fleet manager.');
    }

    public function toArray(object $notifiable): array
    {
        $vehicleName = $this->booking->asset?->name ?? 'Vehicle';
        $endsAt = optional($this->booking->ends_at)->format('d M Y H:i') ?? 'unknown';

        return [
            'title' => 'Vehicle Return Overdue',
            'message' => "{$vehicleName} was due back at {$endsAt}",
            'module' => 'fleet',
            'booking_id' => $this->booking->id,
            'asset_id' => $this->booking->asset_id,
            'ends_at' => $this->booking->ends_at?->toISOString(),
        ];
    }
}
