<?php

namespace App\Support;

class SiteRecommendedDocuments
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
            self::item('evacuation_plan', 'Evacuation plan & assembly point map', 'Keep the evacuation route, assembly point, and support notes together.'),
            self::item('fire_safety_log', 'Fire safety / smoke alarm log', 'Current smoke alarm, drill, and fire equipment checks.'),
            self::item('medication_storage', 'Medication storage policy / locked cabinet audit', 'Shows medicines are stored securely and checked.'),
            self::item('resident_handbook', 'House rules / resident handbook', 'Plain-language living expectations for residents and staff.'),
            self::item('emergency_contacts', 'Emergency contacts sheet', 'Current after-hours, maintenance, and escalation numbers.'),
            self::item('hazard_register', 'Hazard register (current)', 'A current view of site hazards and controls.'),
            self::item('cleaning_food_safety', 'Cleaning & food safety schedule', 'Shows recurring household hygiene routines.'),
            self::item('site_induction', 'Site induction checklist', 'Required induction items for staff working at this site.'),
            self::item('inspection_report', 'Most recent inspection report', 'Latest site or property inspection record.'),
        ];
    }

    private static function headOffice(): array
    {
        return [
            self::item('hs_policy', 'Health & Safety policy', 'Office health and safety responsibilities and escalation.'),
            self::item('evacuation_plan', 'Office evacuation plan', 'Emergency exits, assembly area, and warden notes.'),
            self::item('building_wof', 'Building WOF', 'Current building warrant or landlord confirmation.'),
            self::item('insurance_certificate', 'Insurance certificate', 'Current office and contents cover evidence.'),
            self::item('visitor_induction', 'Visitor induction checklist', 'Reception and contractor induction record.'),
        ];
    }

    private static function facility(): array
    {
        return [
            self::item('equipment_maintenance', 'Equipment maintenance log', 'Current equipment checks and service records.'),
            self::item('ppe_register', 'PPE register', 'Required PPE stock and issue records.'),
            self::item('safe_work_methods', 'Safe Work Method Statements', 'Task-specific safe work instructions.'),
            self::item('emergency_stop', 'Emergency stop check log', 'Routine emergency stop and machinery safety checks.'),
            self::item('facility_evacuation', 'Facility evacuation plan', 'Evacuation route and assembly point for the facility.'),
        ];
    }

    private static function item(string $key, string $label, string $hint): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'hint' => $hint,
            'category' => self::categoryForKey($key),
        ];
    }

    private static function categoryForKey(string $key): string
    {
        if (str_contains($key, 'evacuation') || str_contains($key, 'fire') || str_contains($key, 'hazard') || str_contains($key, 'safety')) {
            return 'safety';
        }

        if (str_contains($key, 'policy') || str_contains($key, 'handbook') || str_contains($key, 'induction')) {
            return 'policy';
        }

        if (str_contains($key, 'insurance')) {
            return 'insurance';
        }

        if (str_contains($key, 'wof') || str_contains($key, 'inspection')) {
            return 'compliance_cert';
        }

        return 'other';
    }
}
