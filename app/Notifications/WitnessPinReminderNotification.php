<?php

namespace App\Notifications;

use App\Models\RoleNotificationPreference;
use App\Models\User;
use App\Models\UserNotificationPreference;
use App\Notifications\Channels\PushChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * "Set your witness PIN" (eMAR P11 PIN status, Q-G): always in the app, plus
 * push when the person has push on for it — their own notification setting,
 * or their role's default when they haven't chosen. It opens their account ›
 * Witness PIN.
 */
class WitnessPinReminderNotification extends Notification
{
    use Queueable;

    /** The key in config/notification_events.php and the person's notification settings. */
    public const PREFERENCE_KEY = 'medications.witness_pin_reminder';

    public function __construct(
        public string $remindedBy,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return $this->pushOn($notifiable) ? ['database', PushChannel::class] : ['database'];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'witness_pin_reminder',
            'title' => 'Set your witness PIN',
            'message' => $this->body(),
            'severity' => 'info',
            'action_url' => '/settings/witness-pin',
            'reminded_by' => $this->remindedBy,
        ];
    }

    /** @return array{title: string, body: string, data: array<string, string>} */
    public function toPush(object $notifiable): array
    {
        return [
            'title' => 'Set your witness PIN',
            'body' => $this->body(),
            'data' => ['url' => '/settings/witness-pin'],
        ];
    }

    private function body(): string
    {
        return $this->remindedBy.' asked you to set a witness PIN. Until you do, you can’t co-sign or witness medication.';
    }

    /** Push is off unless the person (or, if they haven't chosen, their role) turned it on. */
    private function pushOn(object $notifiable): bool
    {
        if (! $notifiable instanceof User) {
            return false;
        }
        $own = UserNotificationPreference::query()
            ->where('user_id', $notifiable->id)
            ->where('key', self::PREFERENCE_KEY)
            ->first();
        if ($own !== null) {
            return (bool) $own->enabled && (bool) $own->channel_push;
        }
        $roleIds = $notifiable->roles()->pluck('roles.id');

        return $roleIds->isNotEmpty() && RoleNotificationPreference::query()
            ->whereIn('role_id', $roleIds)
            ->where('key', self::PREFERENCE_KEY)
            ->where('enabled', true)
            ->where('channel_push', true)
            ->exists();
    }
}
