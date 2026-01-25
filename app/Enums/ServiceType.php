<?php

namespace App\Enums;

/**
 * NZ supported-living service delivery context.
 *
 * Stable codes are important for auditability (e.g. tying medication
 * administrations and shift activity back to the service setting).
 */
enum ServiceType: string
{
    case Residential = 'residential';
    case HomeSupport = 'home_support';
    case Respite = 'respite';

    public function label(): string
    {
        return match ($this) {
            self::Residential => 'Community residential',
            self::HomeSupport => 'Home & community support',
            self::Respite => 'Respite',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Residential => 'Service delivered in a community residential setting (e.g. supported living residence).',
            self::HomeSupport => 'Service delivered in the client’s home and/or community (in-home support, community access).',
            self::Respite => 'Short-term respite support (planned or emergency respite).',
        };
    }
}
