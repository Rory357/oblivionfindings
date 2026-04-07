<?php

namespace App\Notifications\Fleet;

use App\Models\FleetVehicleBooking;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class FleetBookingApprovedNotification extends Notification
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
        $dateRange = optional($this->booking->starts_at)->format('d M Y H:i')
            . ' - '
            . optional($this->booking->ends_at)->format('d M Y H:i');

        return (new MailMessage)
            ->subject("Vehicle Booking Approved: {$vehicleName}")
            ->greeting('Kia ora ' . ($notifiable->name ?? 'there') . ',')
            ->line("Your booking for **{$vehicleName}** has been approved.")
            ->line("Date: {$dateRange}")
            ->action('View Booking', url('/fleet-assets/bookings/' . $this->booking->id))
            ->line('Please ensure you complete the pre-trip inspection before departure.');
    }

    public function toArray(object $notifiable): array
    {
        $vehicleName = $this->booking->asset?->name ?? 'Vehicle';
        $dateRange = optional($this->booking->starts_at)->format('d M Y H:i')
            . ' - '
            . optional($this->booking->ends_at)->format('d M Y H:i');

        return [
            'title' => 'Vehicle Booking Approved',
            'message' => "{$vehicleName} booked for {$dateRange}",
            'module' => 'fleet',
            'booking_id' => $this->booking->id,
            'asset_id' => $this->booking->asset_id,
            'starts_at' => $this->booking->starts_at?->toISOString(),
            'ends_at' => $this->booking->ends_at?->toISOString(),
        ];
    }
}
