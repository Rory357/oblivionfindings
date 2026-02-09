<?php

namespace App\Helpers;

class SiteTypeHelper
{
    /**
     * UI display name for site type
     */
    public static function displayName(string $type): string
    {
        return match ($type) {
            'head_office' => 'Head Office',
            'house' => 'House',
            'facility' => 'Facility',
            default => 'Site',
        };
    }

    /**
     * Plural display name
     */
    public static function displayNamePlural(string $type): string
    {
        return match ($type) {
            'head_office' => 'Head Office',
            'house' => 'Houses',
            'facility' => 'Facilities',
            default => 'Sites',
        };
    }

    /**
     * Legacy compatibility: "location" → "facility"
     */
    public static function normalizeLegacyType(?string $type): string
    {
        if ($type === 'location') {
            return 'facility';
        }
        return $type ?? 'house';
    }

    /**
     * Get icon for site type
     */
    public static function icon(string $type): string
    {
        return match ($type) {
            'head_office' => 'building-2',
            'house' => 'home',
            'facility' => 'warehouse',
            default => 'map-pin',
        };
    }

    /**
     * Get badge color for site type
     */
    public static function badgeColor(string $type): string
    {
        return match ($type) {
            'head_office' => 'blue',
            'house' => 'green',
            'facility' => 'orange',
            default => 'gray',
        };
    }

    /**
     * All available site types
     */
    public static function allTypes(): array
    {
        return ['head_office', 'house', 'facility'];
    }

    /**
     * Check if type is valid
     */
    public static function isValid(string $type): bool
    {
        return in_array($type, self::allTypes(), true);
    }
}
