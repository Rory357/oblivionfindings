<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class MedicationCompetencyExpiringNotification extends Notification
{
    use Queueable;

    public function __construct(
        public string $staffName,
        public string $expiryDate,
        public ?int $assessmentId = null,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'medication_competency_expiring',
            'title' => 'Competency Expiring',
            'message' => "{$this->staffName}'s medication competency expires on {$this->expiryDate}",
            'severity' => 'info',
            'action_url' => '/emar/competency',
            'staff_name' => $this->staffName,
            'expiry_date' => $this->expiryDate,
            'assessment_id' => $this->assessmentId,
        ];
    }
}
