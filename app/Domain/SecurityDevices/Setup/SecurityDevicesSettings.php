<?php

namespace App\Domain\SecurityDevices\Setup;

use App\Domain\Monitoring\Discovery\Models\DiscoveryScope;
use App\Domain\SecurityDevices\Setup\Models\SecurityDeviceSetting;
use App\Models\Role;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The organisation-wide Security & devices settings shown in Setup (Fleet's settings pattern:
 * titled groups of rows, saved together after "Review changes").
 *
 * Each setting knows its type (an On/Off switch, or the role that is told), its default, the
 * permission that may change it, and which value loosens a safety check: the review flags
 * those and the save is the destructive kind.
 */
final class SecurityDevicesSettings
{
    /** Hubs scan their networks every day at their set time (6:00 am unless changed). */
    public const string DAILY_SCANS = 'scans.daily';

    /** The Oblivion server may scan networks itself, not only through a hub. */
    public const string SERVER_SCANS = 'scans.server_direct';

    /** Who is told when a device or hub stops answering and no rule says: a role, or IT for that Site. */
    public const string DEVICES_ROLE = 'alerts.devices.role';

    public const string DEVICES_APP = 'alerts.devices.app';

    public const string DEVICES_EMAIL = 'alerts.devices.email';

    public const string DEVICES_PUSH = 'alerts.devices.push';

    /** A scan found something new: the notice (with Add or Ignore on Found devices). */
    public const string NEW_DEVICES_APP = 'notify.new_devices.app';

    public const string NEW_DEVICES_EMAIL = 'notify.new_devices.email';

    public const string NEW_DEVICES_PUSH = 'notify.new_devices.push';

    /** Safety alerts (panic, alarm, leak, forced door, tamper) by email and push; always in the app. */
    public const string SAFETY_EMAIL = 'alerts.safety.email';

    public const string SAFETY_PUSH = 'alerts.safety.push';

    /** Safety alerts go to the staff on shift at that house, from the roster and clock-ins. */
    public const string SAFETY_ROSTER = 'alerts.safety.roster';

    /** D22: sensor gateways may send unencrypted MQTT to their Site's hub on the house network. */
    public const string MQTT_PLAIN = 'mqtt.allow_unencrypted';

    /** Found devices someone adds start being watched for going offline. */
    public const string WATCH_ON_ADD = 'devices.watch_on_add';

    /** Phones and tablets found by a scan are watched too (off: found, never watched or alerted on). */
    public const string WATCH_PHONES = 'devices.watch_phones';

    /** S&D Phase H (D20): a matched leaver is turned off in UniFi Access after their last day. */
    public const string ACCESS_LEAVERS = 'access.leavers_off';

    /** S&D Phase H: people, cards and visitor passes may be changed in UniFi Access from Oblivion. */
    public const string ACCESS_CHANGES = 'access.changes_from_oblivion';

    private const string MANAGE_SCANS = 'securityDevices.integrations.manage';

    private const string MANAGE_ALERTS = 'securityDevices.automations.manage';

    /**
     * type: bool | role. loosens: the value that loosens a safety check (true, false) or null.
     *
     * @var array<string, array{type: string, permission: string, loosens: bool|null}>
     */
    private const array DEFINITIONS = [
        self::DAILY_SCANS => ['type' => 'bool', 'permission' => self::MANAGE_SCANS, 'loosens' => null],
        self::SERVER_SCANS => ['type' => 'bool', 'permission' => self::MANAGE_SCANS, 'loosens' => true],
        self::DEVICES_ROLE => ['type' => 'role', 'permission' => self::MANAGE_ALERTS, 'loosens' => null],
        self::DEVICES_APP => ['type' => 'bool', 'permission' => self::MANAGE_ALERTS, 'loosens' => false],
        self::DEVICES_EMAIL => ['type' => 'bool', 'permission' => self::MANAGE_ALERTS, 'loosens' => null],
        self::DEVICES_PUSH => ['type' => 'bool', 'permission' => self::MANAGE_ALERTS, 'loosens' => null],
        self::NEW_DEVICES_APP => ['type' => 'bool', 'permission' => self::MANAGE_SCANS, 'loosens' => null],
        self::NEW_DEVICES_EMAIL => ['type' => 'bool', 'permission' => self::MANAGE_SCANS, 'loosens' => null],
        self::NEW_DEVICES_PUSH => ['type' => 'bool', 'permission' => self::MANAGE_SCANS, 'loosens' => null],
        self::SAFETY_EMAIL => ['type' => 'bool', 'permission' => self::MANAGE_ALERTS, 'loosens' => false],
        self::SAFETY_PUSH => ['type' => 'bool', 'permission' => self::MANAGE_ALERTS, 'loosens' => false],
        self::SAFETY_ROSTER => ['type' => 'bool', 'permission' => self::MANAGE_ALERTS, 'loosens' => null],
        self::MQTT_PLAIN => ['type' => 'bool', 'permission' => self::MANAGE_SCANS, 'loosens' => true],
        self::WATCH_ON_ADD => ['type' => 'bool', 'permission' => 'securityDevices.monitoring.manage', 'loosens' => false],
        self::WATCH_PHONES => ['type' => 'bool', 'permission' => 'securityDevices.monitoring.manage', 'loosens' => null],
        // Turning leavers off automatically is a safety check: switching it off loosens it.
        self::ACCESS_LEAVERS => ['type' => 'bool', 'permission' => 'securityDevices.accessControl.manage', 'loosens' => false],
        self::ACCESS_CHANGES => ['type' => 'bool', 'permission' => 'securityDevices.accessControl.manage', 'loosens' => null],
    ];

    /** @var array<string, mixed>|null */
    private ?array $stored = null;

    public function dailyScans(): bool
    {
        return $this->bool(self::DAILY_SCANS);
    }

    public function serverMayScan(): bool
    {
        return $this->bool(self::SERVER_SCANS);
    }

    /** @return list<string> notification channels (database, mail, push) for a group of settings */
    public function channels(string $app, string $email, string $push): array
    {
        return array_values(array_filter([
            $this->bool($app) ? 'database' : null,
            $this->bool($email) ? 'mail' : null,
            $this->bool($push) ? 'push' : null,
        ]));
    }

    public function bool(string $key): bool
    {
        $this->assertKnown($key, 'bool');

        return (bool) $this->value($key);
    }

    /** The role told by default, or null for "IT for that Site". */
    public function role(string $key): ?string
    {
        $this->assertKnown($key, 'role');
        $value = $this->value($key);

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * Every setting with its value, whether it was changed from the default, whether this person
     * may change it, and for a role its choices.
     *
     * @return array<string, array<string, mixed>>
     */
    public function forViewer(User $viewer): array
    {
        $roles = null;
        $out = [];
        foreach (self::DEFINITIONS as $key => $definition) {
            $value = $this->value($key);
            $default = $this->default($key);
            $row = [
                'type' => $definition['type'],
                'changed' => array_key_exists($key, $this->stored()) && $value !== $default,
                'can_change' => $viewer->canDo($definition['permission']),
            ];
            if ($definition['type'] === 'bool') {
                $row += ['on' => (bool) $value, 'default' => (bool) $default, 'loosens_when_on' => $definition['loosens'] === true, 'loosens_when_off' => $definition['loosens'] === false];
            } else {
                $roles ??= Role::query()->orderBy('level')->orderBy('label')->get(['name', 'label'])
                    ->map(fn (Role $role): array => ['value' => (string) $role->name, 'label' => (string) ($role->label ?: ucfirst(str_replace('_', ' ', (string) $role->name)))])
                    ->values()->all();
                $row += ['value' => $value, 'default' => $default, 'options' => [['value' => null, 'label' => 'IT for that Site'], ...$roles]];
            }
            $out[$key] = $row;
        }

        return $out;
    }

    /**
     * Save several settings at once. Unchanged values are ignored; each real change is audited
     * with its old and new value.
     *
     * @param  array<string, mixed>  $changes
     * @return list<string> the keys that changed
     */
    public function update(User $actor, array $changes, ?string $reason = null): array
    {
        $reason = $reason !== null && trim($reason) !== '' ? mb_substr(trim($reason), 0, 500) : null;
        $errors = [];
        foreach ($changes as $key => $value) {
            $definition = is_string($key) ? (self::DEFINITIONS[$key] ?? null) : null;
            if ($definition === null) {
                $errors[(string) $key] = 'This setting doesn’t exist.';

                continue;
            }
            if ($definition['type'] === 'bool' && ! is_bool($value)) {
                $errors[$key] = 'Choose On or Off.';
            }
            if ($definition['type'] === 'role' && $value !== null && ! (is_string($value) && Role::query()->where('name', $value)->exists())) {
                $errors[$key] = 'Choose a role that exists.';
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
        // Permission first: someone who can't change a setting is refused before being asked for a reason.
        foreach (array_keys($changes) as $key) {
            if (! $actor->canDo(self::DEFINITIONS[$key]['permission'])) {
                throw new AuthorizationException('You can’t change this setting.');
            }
        }
        if ($reason === null) {
            foreach ($changes as $key => $value) {
                $loosens = self::DEFINITIONS[$key]['loosens'];
                if ($loosens !== null && $value === $loosens && $this->value($key) !== $value) {
                    throw ValidationException::withMessages(['reason' => 'Say why you’re loosening this safety check.']);
                }
            }
        }

        $changed = DB::transaction(function () use ($actor, $changes, $reason): array {
            $changed = [];
            foreach ($changes as $key => $value) {
                $row = SecurityDeviceSetting::query()->where('key', $key)->lockForUpdate()->first();
                $before = $row !== null ? $row->value : $this->default($key);
                if ($before === $value && $row !== null) {
                    continue;
                }
                $row ??= new SecurityDeviceSetting(['key' => $key]);
                $row->value = $value;
                $row->updated_by_user_id = $actor->id;
                $row->save();
                if ($before !== $value) {
                    $loosens = self::DEFINITIONS[$key]['loosens'];
                    AuditLogger::logOrFail('security_devices.settings.changed', $row, [
                        'actor_id' => (int) $actor->id,
                        'key' => $key,
                        'from' => $before,
                        'to' => $value,
                        'loosens_safety' => $loosens !== null && $value === $loosens,
                        'reason' => $reason,
                    ]);
                    $changed[] = $key;
                }
            }

            return $changed;
        }, 3);

        $this->stored = null;

        return $changed;
    }

    private function value(string $key): mixed
    {
        $stored = $this->stored();

        return array_key_exists($key, $stored) ? $stored[$key] : $this->default($key);
    }

    private function default(string $key): mixed
    {
        return match ($key) {
            self::DAILY_SCANS, self::DEVICES_APP, self::DEVICES_EMAIL, self::NEW_DEVICES_APP, self::WATCH_ON_ADD, self::SAFETY_EMAIL, self::SAFETY_PUSH, self::SAFETY_ROSTER,
            self::ACCESS_LEAVERS, self::ACCESS_CHANGES => true,
            self::DEVICES_PUSH, self::NEW_DEVICES_EMAIL, self::NEW_DEVICES_PUSH, self::WATCH_PHONES, self::MQTT_PLAIN => false,
            self::DEVICES_ROLE => null,
            // Off for new organisations. Where the server already scanned networks itself
            // before this switch existed, that keeps working until someone turns it off.
            self::SERVER_SCANS => DiscoveryScope::query()
                ->where('status', 'active')
                ->whereNull('collector_id')
                ->exists(),
        };
    }

    /** @return array<string, mixed> */
    private function stored(): array
    {
        return $this->stored ??= SecurityDeviceSetting::query()
            ->whereIn('key', array_keys(self::DEFINITIONS))
            ->get(['key', 'value'])
            ->mapWithKeys(fn (SecurityDeviceSetting $row): array => [(string) $row->key => $row->value])
            ->all();
    }

    private function assertKnown(string $key, string $type): void
    {
        if ((self::DEFINITIONS[$key]['type'] ?? null) !== $type) {
            throw new \InvalidArgumentException("Unknown Security & devices setting {$key}.");
        }
    }
}
