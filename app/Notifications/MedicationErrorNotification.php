<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

// Dispatch immediately from MedicationErrorController::store() when an error is created:
// User::whereHas('roles', fn($q) => $q->whereHas('permissions', fn($p) => $p->where('key', 'medications.view')))
//     ->get()
//     ->each
//     ->notify(new MedicationErrorNotification($severity, $clientName, $errorType, $errorId));

class MedicationErrorNotification extends Notification
{
    use Queueable;

    public function __construct(
        public string $severity,
        public string $clientName,
        public string $errorType,
        public ?int $errorId = null,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'medication_error',
            'title' => 'Medication Error Reported',
            'message' => "{$this->severity} medication error reported for {$this->clientName}: {$this->errorType}",
            'severity' => 'critical',
            'action_url' => '/emar/errors',
            'error_severity' => $this->severity,
            'client_name' => $this->clientName,
            'error_type' => $this->errorType,
            'error_id' => $this->errorId,
        ];
    }
}
