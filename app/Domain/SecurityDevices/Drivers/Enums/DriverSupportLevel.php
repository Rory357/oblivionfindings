<?php

namespace App\Domain\SecurityDevices\Drivers\Enums;

/**
 * Honest verification levels shown on the Supported devices catalogue.
 *
 * Never claim "Tested on real hardware" unless an actual physical unit has
 * been verified end-to-end against Oblivion Findings.
 */
enum DriverSupportLevel: string
{
    case TestedOnHardware = 'tested_on_hardware';
    case BuiltFromDocs = 'built_from_docs';
    case Planned = 'planned';

    public function label(): string
    {
        return match ($this) {
            self::TestedOnHardware => 'Tested on real hardware',
            self::BuiltFromDocs => 'Built from vendor docs',
            self::Planned => 'Planned',
        };
    }

    public function badgeVariant(): string
    {
        return match ($this) {
            self::TestedOnHardware => 'success',
            self::BuiltFromDocs => 'info',
            self::Planned => 'neutral',
        };
    }

    public function sortOrder(): int
    {
        return match ($this) {
            self::TestedOnHardware => 1,
            self::BuiltFromDocs => 2,
            self::Planned => 3,
        };
    }
}
