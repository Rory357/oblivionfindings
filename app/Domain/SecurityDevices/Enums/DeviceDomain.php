<?php

namespace App\Domain\SecurityDevices\Enums;

/**
 * Top-level hardware domains.
 *
 * This is a backed enum because the set of domains is finite and stable.
 * Adding a new domain is an architectural decision, not a data entry.
 * Categories and subcategories within each domain are NOT enums — they
 * are config-driven strings for extensibility (see DeviceTaxonomy).
 */
enum DeviceDomain: string
{
    case Security = 'security';
    case Tracking = 'tracking';
    case IotHealthcare = 'iot_healthcare';
    case ItInfrastructure = 'it_infrastructure';
    case Facilities = 'facilities';

    public function label(): string
    {
        return match ($this) {
            self::Security => 'Security',
            self::Tracking => 'Tracking Devices',
            self::IotHealthcare => 'Smart IoT & Healthcare',
            self::ItInfrastructure => 'IT Infrastructure',
            self::Facilities => 'Facilities',
        };
    }
}
