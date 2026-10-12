<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

/**
 * EA-141: an approved backup recipient without a backup passphrase can't
 * open a backup while the app is down. Names no house, person or medicine.
 */
class BackupPassphraseReminderNotification extends Notification
{
    public function __construct(public string $reminderKey) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'backup_passphrase_reminder',
            'reminder_key' => $this->reminderKey,
            'title' => 'Set a backup passphrase',
            'message' => 'You receive protected chart backups. Set your own backup passphrase so they open during an outage, without the app.',
            'severity' => 'warning',
            'action_url' => '/emar/backups',
        ];
    }
}
