<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class MedicationOverdueNotification extends Notification
{
    use Queueable;

    public function __construct(
        public string $medication,
        public string $clientName,
        public string $scheduledTime,
        public ?int $clientId = null,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'medication_overdue',
            'title' => 'Overdue Medication',
            'message' => "{$this->medication} for {$this->clientName} was due at {$this->scheduledTime} and has not been administered",
            'severity' => 'warning',
            'action_url' => $this->clientId ? "/emar/mar?client_id={$this->clientId}" : '/emar/mar',
            'medication' => $this->medication,
            'client_name' => $this->clientName,
            'scheduled_time' => $this->scheduledTime,
            'client_id' => $this->clientId,
        ];
    }
}
