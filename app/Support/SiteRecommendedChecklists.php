<?php

namespace App\Support;

class SiteRecommendedChecklists
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
            self::item('site_induction', 'Site induction', 'One-off induction for workers starting at this site.', 'once', ['site induction', 'induction']),
            self::item('fire_drill', 'Fire drill', 'Quarterly drill and evacuation readiness check.', 'quarterly', ['fire drill', 'evacuation']),
            self::item('medication_storage_audit', 'Medication storage audit', 'Monthly check for locked storage and access controls.', 'monthly', ['medication storage', 'medication']),
            self::item('cleaning_food_safety', 'Cleaning & food safety', 'Weekly household cleaning and food safety routine.', 'weekly', ['cleaning', 'food safety']),
            self::item('emergency_readiness', 'Emergency readiness check', 'Monthly emergency contacts, supplies, and plan review.', 'monthly', ['emergency readiness', 'emergency']),
            self::item('quality_home', 'Quality Home Checklist', 'Monthly quality and home environment review.', 'monthly', ['quality home']),
        ];
    }

    private static function headOffice(): array
    {
        return [
            self::item('visitor_induction', 'Visitor induction', 'One-off reception, visitor, and contractor induction check.', 'once', ['visitor induction', 'induction']),
            self::item('fire_drill', 'Fire drill', 'Quarterly drill and evacuation readiness check.', 'quarterly', ['fire drill', 'evacuation']),
            self::item('emergency_readiness', 'Emergency readiness check', 'Monthly emergency contacts, supplies, and plan review.', 'monthly', ['emergency readiness', 'emergency']),
            self::item('office_health_safety', 'Office health & safety', 'Monthly office environment and workstation safety review.', 'monthly', ['health', 'safety', 'office']),
        ];
    }

    private static function facility(): array
    {
        return [
            self::item('site_induction', 'Site induction', 'One-off induction for workers starting at this site.', 'once', ['site induction', 'induction']),
            self::item('equipment_maintenance', 'Equipment maintenance', 'Monthly equipment safety and maintenance checks.', 'monthly', ['equipment', 'maintenance']),
            self::item('ppe_register', 'PPE register', 'Weekly PPE stock, issue, and suitability check.', 'weekly', ['ppe']),
            self::item('emergency_readiness', 'Emergency readiness check', 'Monthly emergency contacts, supplies, and plan review.', 'monthly', ['emergency readiness', 'emergency']),
        ];
    }

    private static function item(string $key, string $label, string $hint, string $frequency, array $matches): array
    {
        return compact('key', 'label', 'hint', 'frequency', 'matches');
    }
}
