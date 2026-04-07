<?php

namespace App\Notifications\Fleet;

use App\Models\FleetMedicationTransitLog;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class FleetControlledDrugTransitNotification extends Notification
{
    use Queueable;

    public function __construct(
        public FleetMedicationTransitLog $log,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $medicationName = $this->log->medication_name;
        $residentName = trim(($this->log->client?->first_name ?? '') . ' ' . ($this->log->client?->last_name ?? ''));
        $packedBy = $this->log->packedBy?->name ?? 'Unknown';

        return (new MailMessage)
            ->subject("Controlled Drug in Transit: {$medicationName}")
            ->greeting('Kia ora ' . ($notifiable->name ?? 'there') . ',')
            ->line("A controlled drug has been packed for transport.")
            ->line("**Medication:** {$medicationName}")
            ->line("**Resident:** {$residentName}")
            ->line("**Packed by:** {$packedBy}")
            ->action('View Medication Transit', url('/fleet-assets/transports/medications'))
            ->line('Please ensure proper chain-of-custody procedures are followed.');
    }

    public function toArray(object $notifiable): array
    {
        $residentName = trim(($this->log->client?->first_name ?? '') . ' ' . ($this->log->client?->last_name ?? ''));

        return [
            'title' => 'Controlled Drug in Transit',
            'message' => "{$this->log->medication_name} packed for {$residentName}",
            'module' => 'fleet',
            'transport_id' => $this->log->transport_id,
            'medication_name' => $this->log->medication_name,
            'packed_by' => $this->log->packedBy?->name,
            'resident_name' => $residentName,
        ];
    }
}
