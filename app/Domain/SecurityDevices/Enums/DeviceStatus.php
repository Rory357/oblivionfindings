<?php

namespace App\Domain\SecurityDevices\Enums;

enum DeviceStatus: string
{
    case Active = 'active';
    case Offline = 'offline';
    case Degraded = 'degraded';
    case Maintenance = 'maintenance';
    case Decommissioned = 'decommissioned';
    case InStock = 'in_stock';
    case Quarantined = 'quarantined';
    case Lost = 'lost';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Offline => 'Offline',
            self::Degraded => 'Degraded',
            self::Maintenance => 'Maintenance',
            self::Decommissioned => 'Decommissioned',
            self::InStock => 'In Stock',
            self::Quarantined => 'Quarantined',
            self::Lost => 'Lost',
        };
    }

    public function isOperational(): bool
    {
        return in_array($this, [self::Active, self::Degraded], true);
    }

    public function isRetired(): bool
    {
        return in_array($this, [self::Decommissioned, self::Quarantined, self::Lost], true);
    }
}
