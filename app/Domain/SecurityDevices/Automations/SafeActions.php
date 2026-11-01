<?php

namespace App\Domain\SecurityDevices\Automations;

use InvalidArgumentException;

/**
 * S&D Phase F: every action an automation or a house mode may take (D12). They tell people, open
 * alerts, make cameras more private, and check that doors are locked and alarms armed (reminding
 * staff when not). None sends a governed command: commands need a person (step-up, approval), so
 * nothing automatic can disarm, unlock, bypass, reset, open a stream or loosen privacy, ever.
 *
 * Modes and automations must build their actions only from here, and run them only through here.
 */
final class SafeActions
{
    /** @var array<string, array{label: string, description: string}> */
    public const array ACTIONS = [
        'notify' => [
            'label' => 'Tell people',
            'description' => 'The staff on shift at the house, a role, or named people, in the app (and by push or email if chosen).',
        ],
        'open_alert' => [
            'label' => 'Open an alert',
            'description' => 'An alert in Security & devices › Alerts, for the staff on shift.',
        ],
        'camera_privacy_on' => [
            'label' => 'Turn camera privacy on',
            'description' => 'No picture from the chosen cameras until a time, the same as their privacy hours.',
        ],
        'door_locked_check' => [
            'label' => 'Check doors are locked',
            'description' => 'Reads each chosen door’s lock; any that isn’t locked is reported to the staff on shift to lock by hand.',
        ],
        'alarm_armed_check' => [
            'label' => 'Check the alarm is armed',
            'description' => 'Reads the alarm’s areas; if one isn’t armed, the staff on shift are reminded to arm it.',
        ],
    ];

    /**
     * Governed capabilities no automation or mode may ever ask for (D12). Nothing here sends any
     * governed command at all; this list is what the tests prove stays out.
     */
    public const array NEVER = [
        'alarm.disarm', 'alarm.bypass', 'alarm.reset', 'alarm.emergency_mode.set',
        'access.door.unlock_timed', 'access.door.keep_unlocked', 'access.credential.grant', 'access.schedule.update',
        'camera.privacy.disable', 'camera.recording.stop', 'camera.stream.share', 'camera.evidence.export',
        'device.wipe', 'device.remote_shell', 'device.remote_session',
        'network.firewall_policy.apply', 'monitoring.suppression.create', 'monitoring.suppression.site.create',
    ];

    public static function allowed(string $action): bool
    {
        return array_key_exists($action, self::ACTIONS);
    }

    /** Refuse anything that isn't one of the safe actions (a disarm or unlock never is). */
    public static function assertAllowed(string $action): void
    {
        if (! self::allowed($action)) {
            throw new InvalidArgumentException('That action can’t be automated: automations and house modes never disarm or unlock.');
        }
    }

    /** @return list<array{key: string, label: string, description: string}> for pickers */
    public static function choices(): array
    {
        $out = [];
        foreach (self::ACTIONS as $key => $action) {
            $out[] = ['key' => $key, ...$action];
        }

        return $out;
    }
}
