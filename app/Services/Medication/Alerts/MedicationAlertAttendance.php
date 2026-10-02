<?php

namespace App\Services\Medication\Alerts;

use App\Models\MedicationAlert;
use App\Models\MedicationAlertEvent;
use App\Models\User;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\DB;

/**
 * Who attended a medication alert (eMAR P11 B2 chunk 3): one record per
 * alert, shared by every recipient.
 *
 * A person attends by opening their alert in the bell, by acknowledging it
 * there, or — strongest — when its source is dealt with (MedicationAlerts
 * closes it). Settings › Delivery decides what counts: dealt with >
 * acknowledged > opened. The first attendance strong enough stops re-alerts
 * and escalation for everyone. Every open and acknowledgement is kept in the
 * alert's history either way.
 *
 * Race: the record is written under the alert's row lock, and the follow-up
 * tick takes the same lock and re-checks before it re-alerts. An attend that
 * lands first stops the tick; nothing re-alerts after it.
 */
class MedicationAlertAttendance
{
    public const OPENED = 'opened';

    public const ACKNOWLEDGED = 'acknowledged';

    public const DEALT_WITH = 'dealt_with';

    /** How strongly each counts. */
    private const STRENGTH = [self::OPENED => 1, self::ACKNOWLEDGED => 2, self::DEALT_WITH => 3];

    /** Settings › Delivery "An alert counts as attended when" → the strength it needs. */
    private const REQUIRED = ['open' => 1, 'ack' => 2, 'done' => 3];

    public function __construct(
        private readonly MedicationAlerts $alerts,
        private readonly MedicationAlertSettings $settings,
    ) {}

    /** From the bell: someone opened or acknowledged their alert's notification. */
    public function fromNotification(DatabaseNotification $notification, User $user, string $how): bool
    {
        $alertId = data_get($notification->data, 'medication_alert_id');
        if (data_get($notification->data, 'type') !== 'medication_alert' || ! is_numeric($alertId)) {
            return false;
        }

        return $this->record((int) $alertId, $user, $how);
    }

    /** Record that this person opened or acknowledged the alert. True when it now counts as attended. */
    public function record(int $alertId, User $user, string $how): bool
    {
        if (! isset(self::STRENGTH[$how]) || $how === self::DEALT_WITH) {
            return false;
        }

        return DB::transaction(function () use ($alertId, $user, $how): bool {
            $alert = MedicationAlert::query()->whereKey($alertId)->lockForUpdate()->first();
            // Only someone it was sent to, at any step.
            if (! $alert instanceof MedicationAlert || ! $alert->recipients()->where('user_id', $user->id)->exists()) {
                return false;
            }
            $this->alerts->event($alert, $how, [], (int) $user->id);
            if ($alert->open_key === null || $alert->attended_at !== null) {
                return false;
            }
            $required = self::REQUIRED[$this->settings->followUp()['attended']] ?? self::REQUIRED['ack'];
            if (self::STRENGTH[$how] < $required) {
                return false;
            }
            $alert->forceFill([
                'attended_at' => now(),
                'attended_by' => $user->id,
                'attended_how' => $how,
                'status' => MedicationAlert::STATUS_ATTENDED,
                'next_follow_up_at' => null,
            ])->save();
            $this->alerts->event($alert, MedicationAlertEvent::ATTENDED, ['how' => $how], (int) $user->id);

            return true;
        });
    }
}
