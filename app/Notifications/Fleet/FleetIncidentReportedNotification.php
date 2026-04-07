<?php

namespace App\Notifications\Fleet;

use App\Models\FleetIncident;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class FleetIncidentReportedNotification extends Notification
{
    use Queueable;

    public function __construct(
        public FleetIncident $incident,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $vehicleName = $this->incident->asset?->name ?? 'Vehicle';
        $severityLabel = strtoupper($this->incident->severity);

        return (new MailMessage)
            ->subject("[{$severityLabel}] Fleet Incident: {$this->incident->incident_type}")
            ->greeting('Kia ora ' . ($notifiable->name ?? 'there') . ',')
            ->line("A **{$severityLabel}** fleet incident has been reported on **{$vehicleName}**.")
            ->line("**Type:** {$this->incident->incident_type}")
            ->line("**Location:** " . ($this->incident->location ?? 'Not specified'))
            ->action('View Incident', url('/fleet-assets/incidents/' . $this->incident->id))
            ->line('Please review and take appropriate action.');
    }

    public function toArray(object $notifiable): array
    {
        $vehicleName = $this->incident->asset?->name ?? 'Vehicle';

        return [
            'title' => 'Fleet Incident Reported',
            'message' => "{$this->incident->severity} incident on {$vehicleName}: {$this->incident->incident_type}",
            'module' => 'fleet',
            'incident_id' => $this->incident->id,
            'asset_id' => $this->incident->asset_id,
            'severity' => $this->incident->severity,
            'incident_type' => $this->incident->incident_type,
        ];
    }
}
