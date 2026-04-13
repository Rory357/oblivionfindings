<?php

namespace App\Domain\SecurityDevices\Enums;

enum RelationshipType: string
{
    case RecordsTo = 'records_to';
    case PoweredBy = 'powered_by';
    case ConnectedTo = 'connected_to';
    case MountedIn = 'mounted_in';
    case Controls = 'controls';
    case UplinksTo = 'uplinks_to';
    case BacksUpTo = 'backs_up_to';

    public function label(): string
    {
        return match ($this) {
            self::RecordsTo => 'Records to',
            self::PoweredBy => 'Powered by',
            self::ConnectedTo => 'Connected to',
            self::MountedIn => 'Mounted in',
            self::Controls => 'Controls',
            self::UplinksTo => 'Uplinks to',
            self::BacksUpTo => 'Backs up to',
        };
    }
}
