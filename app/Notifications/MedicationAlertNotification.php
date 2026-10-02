<?php

namespace App\Notifications;

use App\Models\MedicationAlert;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * A medication alert as one person receives it (eMAR P11 B2). Every alert in
 * Medication Settings › Alerts & access is sent through this notification,
 * built from the alert log's record, so what people see and what the log
 * keeps never disagree.
 *
 * In the bell (database) today; email and push follow the alert's channels
 * with P11 B2 chunk 2.
 */
class MedicationAlertNotification extends Notification
{
    use Queueable;

    /** @param list<string> $channels The alert's channels when it was sent. */
    public function __construct(
        public readonly MedicationAlert $alert,
        public readonly array $channels = ['inapp'],
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return in_array('inapp', $this->channels, true) ? ['database'] : [];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'medication_alert',
            'alert_key' => $this->alert->type,
            'medication_alert_id' => $this->alert->id,
            'module' => 'medication',
            'title' => $this->alert->title,
            'message' => $this->alert->message,
            'severity' => $this->alert->severity,
            'action_url' => $this->alert->action_url,
            'controlled' => $this->alert->controlled,
            ...($this->alert->subject ?? []),
        ];
    }
}
