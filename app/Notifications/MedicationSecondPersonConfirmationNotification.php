<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

/** No clinical details are copied into the bell's enduring payload. */
class MedicationSecondPersonConfirmationNotification extends Notification
{
    public function __construct(public int $followupId) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'medication_second_person_confirmation',
            'title' => 'Were you there?',
            'message' => 'A dose names you as the second person. Answer in your own login within 30 minutes. Reset your witness PIN if you have forgotten it.',
            'severity' => 'warning',
            'action_url' => '/medication-followups?open='.$this->followupId,
        ];
    }
}
