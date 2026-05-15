<?php

namespace App\Support;

class SiteRecommendedHazards
{
    public static function forType(?string $type): array
    {
        return match ($type) {
            'head_office' => self::headOffice(),
            'facility' => self::facility(),
            default => self::house(),
        };
    }

    private static function house(): array
    {
        return [
            self::item('slip_trip_fall', 'Slip / trip hazards', 'Loose mats, wet floors, cluttered walkways.'),
            self::item('hot_water_temperature', 'Hot water temperature', 'Scald risk above 50C in bathrooms or kitchens.'),
            self::item('medication_storage_access', 'Medication storage access', 'Unlocked cabinet, key control, or access concern.'),
            self::item('fire_electrical', 'Fire / electrical', 'Overloaded sockets, damaged leads, expired alarms.'),
            self::item('manual_handling', 'Manual handling', 'Transfers, lifting, equipment, or room layout risk.'),
            self::item('security_behaviour', 'Behavioural / security', 'Entry, privacy, aggression, or lone-worker concerns.'),
            self::item('outdoor_garden', 'Outdoor / gardening hazards', 'Uneven paths, tools, weeds, or poor lighting.'),
            self::item('cleaning_chemicals', 'Cleaning chemicals storage', 'COSHH-style storage, labels, and locked access.'),
            self::item('bathroom_safety', 'Bathroom safety', 'Grab rails, non-slip surfaces, shower access.'),
        ];
    }

    private static function headOffice(): array
    {
        return [
            self::item('slip_trip_fall', 'Slip / trip hazards', 'Loose mats, wet floors, cluttered walkways.'),
            self::item('fire_electrical', 'Fire / electrical', 'Overloaded sockets, damaged leads, expired alarms.'),
            self::item('security_access', 'Security / visitor access', 'Reception, contractor access, privacy, or lone-worker concern.'),
            self::item('office_ergonomics', 'Office ergonomics', 'Workstation setup, lighting, or repetitive strain risk.'),
            self::item('emergency_exits', 'Emergency exits', 'Blocked exits, signage, or assembly point issues.'),
        ];
    }

    private static function facility(): array
    {
        return [
            self::item('slip_trip_fall', 'Slip / trip hazards', 'Loose mats, wet floors, cluttered walkways.'),
            self::item('fire_electrical', 'Fire / electrical', 'Overloaded sockets, damaged leads, expired alarms.'),
            self::item('manual_handling', 'Manual handling', 'Transfers, lifting, equipment, or room layout risk.'),
            self::item('equipment_guarding', 'Equipment guarding', 'Missing guards, lockout gaps, or damaged safety controls.'),
            self::item('cleaning_chemicals', 'Cleaning chemicals storage', 'COSHH-style storage, labels, and locked access.'),
            self::item('ppe_availability', 'PPE availability', 'Missing, expired, or unsuitable PPE for facility tasks.'),
        ];
    }

    private static function item(string $key, string $label, string $hint): array
    {
        return compact('key', 'label', 'hint');
    }
}
