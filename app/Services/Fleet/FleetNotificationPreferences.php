<?php

namespace App\Services\Fleet;

use App\Models\RoleNotificationPreference;
use App\Models\User;
use App\Models\UserNotificationPreference;

/** Optional copies only. Source workflows continue to own recipients and response. */
class FleetNotificationPreferences
{
    public const EVENTS = [
        'fleet.booking_decisions' => ['title' => 'Booking decisions', 'description' => 'Approval and decline decisions on your bookings.', 'owner' => 'Fleet booking', 'href' => '/fleet-assets/bookings', 'inapp' => true, 'email' => true],
        'fleet.maintenance_reminders' => ['title' => 'Maintenance reminders', 'description' => 'Scheduled maintenance reminders for eligible Fleet managers.', 'owner' => 'Maintenance', 'href' => '/fleet-assets/maintenance/work-orders', 'inapp' => true, 'email' => true],
        'fleet.handover_updates' => ['title' => 'Handover updates', 'description' => 'A handover sent to you, or a response to your handover.', 'owner' => 'Transport', 'href' => '/fleet-assets/handovers', 'inapp' => true, 'email' => false],
        'fleet.import_results' => ['title' => 'Import results', 'description' => 'Results of your asset import, including rows needing review.', 'owner' => 'Assets', 'href' => '/fleet-assets/assets?view=imports', 'inapp' => true, 'email' => true],
    ];

    public function snapshot(User $user): array
    {
        $keys = array_keys(self::EVENTS);
        $personal = UserNotificationPreference::where('user_id', $user->id)->whereIn('key', $keys)->get()->keyBy('key');
        $roles = RoleNotificationPreference::whereIn('role_id', $user->roles()->pluck('roles.id'))->whereIn('key', $keys)->orderBy('role_id')->orderBy('key')->get();
        $overrides = [];
        $events = [];
        foreach (self::EVENTS as $key => $event) {
            $defaults = [];
            $roleRows = $roles->where('key', $key);
            foreach (['inapp', 'email'] as $channel) {
                $defaults[$channel] = $roleRows->isEmpty() ? $event[$channel] : $roleRows->contains(fn ($row) => $row->enabled && $row->{'channel_'.$channel});
            }
            if ($pref = $personal->get($key)) {
                // Rows saved by the older settings page are explicit full-row choices.
                $overrides[$key] = $pref->channel_overrides ?? [
                    'inapp' => $pref->enabled && $pref->channel_inapp,
                    'email' => $pref->enabled && $pref->channel_email,
                ];
            }
            $events[] = ['key' => $key, ...$event, 'defaults' => $defaults, 'defaultSource' => $roleRows->isEmpty() ? 'Application default' : 'Role default', 'effective' => array_replace($defaults, $overrides[$key] ?? [])];
        }

        return [
            'events' => $events,
            'overrides' => (object) $overrides,
            // Include effective defaults and membership: stale forms must review role changes too.
            'revision' => hash('sha256', json_encode([$user->id, $events, $overrides], JSON_THROW_ON_ERROR)),
            'channels' => [
                'inapp' => ['available' => true, 'label' => 'In-app notices', 'detail' => 'Stored in your notification inbox. Delivery is not acknowledgement.'],
                'email' => ['available' => $this->externalEmailConfigured(), 'label' => 'Email configuration', 'detail' => $this->externalEmailConfigured() ? 'An email transport is configured. Receipt and provider health are not verified here.' : 'External email is not configured. Your choice can be saved for when email is available.'],
            ],
        ];
    }

    public function channels(object $recipient, string $key): array
    {
        if (! $recipient instanceof User || ! isset(self::EVENTS[$key])) {
            return [];
        }
        $event = collect($this->snapshot($recipient)['events'])->firstWhere('key', $key);

        return array_values(array_filter([
            $event['effective']['inapp'] ? 'database' : null,
            $event['effective']['email'] && filled($recipient->email) ? 'mail' : null,
        ]));
    }

    private function externalEmailConfigured(): bool
    {
        // Configuration presence is not a transport health check (including failover mailers).
        $mailer = config('mail.default');

        return filled($mailer) && ! in_array(config("mail.mailers.{$mailer}.transport"), [null, 'array', 'log'], true);
    }
}
