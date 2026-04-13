<?php

namespace App\Domain\SecurityDevices\Enums;

enum LinkType: string
{
    case Primary = 'primary';
    case InstalledIn = 'installed_in';
    case Accessory = 'accessory';

    public function label(): string
    {
        return match ($this) {
            self::Primary => 'Primary (device is this asset)',
            self::InstalledIn => 'Installed in',
            self::Accessory => 'Accessory',
        };
    }
}
