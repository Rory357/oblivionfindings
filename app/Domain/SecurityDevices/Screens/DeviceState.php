<?php

namespace App\Domain\SecurityDevices\Screens;

/**
 * A device's state in plain words (plan v10). "Can't check" is grey, never red: it means the
 * device sits behind something that is down (its router or its hub), so one root-cause alert
 * covers it instead of a red alert per device.
 */
enum DeviceState: string
{
    case Online = 'online';
    case Problem = 'problem';
    case Offline = 'offline';
    case CantCheck = 'cant_check';
    case NotWatched = 'not_watched';
    case Retired = 'retired';

    public function label(): string
    {
        return match ($this) {
            self::Online => 'Online',
            self::Problem => 'Needs attention',
            self::Offline => 'Offline',
            self::CantCheck => 'Can’t check',
            self::NotWatched => 'Not watched',
            self::Retired => 'Retired',
        };
    }

    /** StatusBadge variant. */
    public function tone(): string
    {
        return match ($this) {
            self::Online => 'success',
            self::Problem => 'warning',
            self::Offline => 'critical',
            self::CantCheck, self::NotWatched, self::Retired => 'neutral',
        };
    }

    /** Problems first: offline, then needs attention, then can't check, then the rest. */
    public function sortOrder(): int
    {
        return match ($this) {
            self::Offline => 0,
            self::Problem => 1,
            self::CantCheck => 2,
            self::Online => 3,
            self::NotWatched => 4,
            self::Retired => 5,
        };
    }

    /**
     * @param  string|null  $status  devices.status (active, offline, degraded, maintenance, decommissioned, …)
     * @param  bool  $watched  any enabled check or a connected service reports on it
     * @param  bool  $behindDownPath  its checks are suppressed because the hub or router in front is down
     */
    public static function resolve(?string $status, ?string $health, bool $watched, bool $behindDownPath): self
    {
        return match (true) {
            in_array($status, ['decommissioned', 'lost'], true) => self::Retired,
            $behindDownPath => self::CantCheck,
            ! $watched => self::NotWatched,
            $status === 'offline' || $health === 'critical' => self::Offline,
            $status === 'degraded' || $health === 'warning' => self::Problem,
            default => self::Online,
        };
    }
}
