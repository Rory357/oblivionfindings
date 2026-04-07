<?php

namespace App\Notifications\Fleet;

use App\Models\FleetVehicleBooking;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class FleetBookingRejectedNotification extends Notification
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
        $reason = $this->booking->rejection_reason ?? 'No reason provided';

        return (new MailMessage)
            ->subject("Vehicle Booking Rejected: {$vehicleName}")
            ->greeting('Kia ora ' . ($notifiable->name ?? 'there') . ',')
            ->line("Your booking for **{$vehicleName}** has been rejected.")
            ->line("**Reason:** {$reason}")
            ->action('View Booking', url('/fleet-assets/bookings/' . $this->booking->id))
            ->line('Please contact the fleet manager if you have questions.');
    }

    public function toArray(object $notifiable): array
    {
        $vehicleName = $this->booking->asset?->name ?? 'Vehicle';
        $reason = $this->booking->rejection_reason ?? 'No reason provided';

        return [
            'title' => 'Vehicle Booking Rejected',
            'message' => "Your booking for {$vehicleName} was rejected: {$reason}",
            'module' => 'fleet',
            'booking_id' => $this->booking->id,
            'rejection_reason' => $reason,
        ];
    }
}
