<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class MedicationRefusalClusterNotification extends Notification
{
    use Queueable;

    public function __construct(
        public string $clientName,
        public string $medication,
        public int $count,
        public ?int $clientId = null,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'medication_refusal_cluster',
            'title' => 'Repeated Medication Refusals',
            'message' => "{$this->clientName} has refused {$this->medication} {$this->count} times in the last 7 days",
            'severity' => 'warning',
            'action_url' => $this->clientId ? "/emar/mar?client_id={$this->clientId}" : '/emar/mar',
            'client_name' => $this->clientName,
            'medication' => $this->medication,
            'count' => $this->count,
            'client_id' => $this->clientId,
        ];
    }
}
