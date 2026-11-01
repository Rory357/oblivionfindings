<?php

namespace App\Domain\SecurityDevices\Setup;

use App\Domain\Monitoring\Discovery\Models\DiscoveryScope;
use App\Domain\SecurityDevices\Setup\Models\SecurityDeviceSetting;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The organisation-wide Security & devices switches shown in Setup (Fleet's settings
 * pattern: titled groups of On/Off rows, saved together after "Review changes").
 *
 * Each switch knows its default, the permission that may change it, and whether turning
 * it on loosens a safety check (the review flags those and the save is the destructive kind).
 */
final class SecurityDevicesSettings
{
    /** Hubs scan their networks every day at their set time (6:00 am unless changed). */
    public const string DAILY_SCANS = 'scans.daily';

    /** The Oblivion server may scan networks itself, not only through a hub. */
    public const string SERVER_SCANS = 'scans.server_direct';

    /**
     * @var array<string, array{permission: string, loosens_when_on: bool}>
     */
    private const array SWITCHES = [
        self::DAILY_SCANS => ['permission' => 'securityDevices.integrations.manage', 'loosens_when_on' => false],
        self::SERVER_SCANS => ['permission' => 'securityDevices.integrations.manage', 'loosens_when_on' => true],
    ];

    /** @var array<string, bool>|null */
    private ?array $stored = null;

    public function dailyScans(): bool
    {
        return $this->bool(self::DAILY_SCANS);
    }

    public function serverMayScan(): bool
    {
        return $this->bool(self::SERVER_SCANS);
    }

    public function bool(string $key): bool
    {
        $this->assertKnown($key);
        $stored = $this->stored();

        return array_key_exists($key, $stored) ? $stored[$key] : $this->default($key);
    }

    /**
     * Every switch with its value, whether it was changed from the default, and whether
     * this person may change it.
     *
     * @return array<string, array{on: bool, default: bool, changed: bool, loosens_when_on: bool, can_change: bool}>
     */
    public function forViewer(User $viewer): array
    {
        $out = [];
        foreach (self::SWITCHES as $key => $definition) {
            $on = $this->bool($key);
            $default = $this->default($key);
            $out[$key] = [
                'on' => $on,
                'default' => $default,
                'changed' => array_key_exists($key, $this->stored()) && $on !== $default,
                'loosens_when_on' => $definition['loosens_when_on'],
                'can_change' => $viewer->canDo($definition['permission']),
            ];
        }

        return $out;
    }

    /**
     * Save several switches at once. Unchanged values are ignored; each real change is
     * audited with its old and new value.
     *
     * @param  array<string, mixed>  $changes
     * @return list<string> the keys that changed
     */
    public function update(User $actor, array $changes): array
    {
        $errors = [];
        foreach ($changes as $key => $value) {
            if (! is_string($key) || ! array_key_exists($key, self::SWITCHES)) {
                $errors[(string) $key] = 'This setting doesn’t exist.';

                continue;
            }
            if (! is_bool($value)) {
                $errors[$key] = 'Choose On or Off.';
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
        foreach (array_keys($changes) as $key) {
            if (! $actor->canDo(self::SWITCHES[$key]['permission'])) {
                throw new AuthorizationException('You can’t change this setting.');
            }
        }

        $changed = DB::transaction(function () use ($actor, $changes): array {
            $changed = [];
            foreach ($changes as $key => $value) {
                $row = SecurityDeviceSetting::query()->where('key', $key)->lockForUpdate()->first();
                $before = $row !== null ? (bool) $row->value : $this->default($key);
                if ($before === $value && $row !== null) {
                    continue;
                }
                $row ??= new SecurityDeviceSetting(['key' => $key]);
                $row->value = $value;
                $row->updated_by_user_id = $actor->id;
                $row->save();
                if ($before !== $value) {
                    AuditLogger::logOrFail('security_devices.settings.changed', $row, [
                        'actor_id' => (int) $actor->id,
                        'key' => $key,
                        'from' => $before,
                        'to' => $value,
                        'loosens_safety' => self::SWITCHES[$key]['loosens_when_on'] && $value,
                    ]);
                    $changed[] = $key;
                }
            }

            return $changed;
        }, 3);

        $this->stored = null;

        return $changed;
    }

    private function default(string $key): bool
    {
        return match ($key) {
            self::DAILY_SCANS => true,
            // Off for new organisations. Where the server already scanned networks itself
            // before this switch existed, that keeps working until someone turns it off.
            self::SERVER_SCANS => DiscoveryScope::query()
                ->where('status', 'active')
                ->whereNull('collector_id')
                ->exists(),
        };
    }

    /** @return array<string, bool> */
    private function stored(): array
    {
        return $this->stored ??= SecurityDeviceSetting::query()
            ->whereIn('key', array_keys(self::SWITCHES))
            ->get(['key', 'value'])
            ->mapWithKeys(fn (SecurityDeviceSetting $row): array => [(string) $row->key => (bool) $row->value])
            ->all();
    }

    private function assertKnown(string $key): void
    {
        if (! array_key_exists($key, self::SWITCHES)) {
            throw new \InvalidArgumentException("Unknown Security & devices setting {$key}.");
        }
    }
}
