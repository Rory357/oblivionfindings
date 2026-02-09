<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\AppSetting;
use App\Models\SiteChecklistTemplate;

class SitesModuleSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedEventTypes();
        $this->seedHazardTypes();
        $this->seedChecklistTemplates();
    }

    private function seedEventTypes(): void
    {
        $eventTypes = [
            ['key' => 'general', 'label' => 'General Event', 'color' => '#6366f1', 'icon' => 'calendar'],
            ['key' => 'maintenance', 'label' => 'Maintenance Schedule', 'color' => '#f59e0b', 'icon' => 'wrench', 'requires_approval' => true],
            ['key' => 'site_visit', 'label' => 'Site Visit', 'color' => '#10b981', 'icon' => 'map-pin'],
            ['key' => 'inspection', 'label' => 'Inspection', 'color' => '#8b5cf6', 'icon' => 'clipboard-check', 'requires_approval' => true],
            ['key' => 'contractor_visit', 'label' => 'Contractor Visit', 'color' => '#06b6d4', 'icon' => 'hard-hat'],
            ['key' => 'cleaning_grounds', 'label' => 'Cleaning / Lawn / Grounds', 'color' => '#84cc16', 'icon' => 'leaf'],
            ['key' => 'utilities_outage', 'label' => 'Utilities Outage', 'color' => '#ef4444', 'icon' => 'zap-off'],
            ['key' => 'training_meeting', 'label' => 'Training / Meeting', 'color' => '#ec4899', 'icon' => 'users', 'site_types' => ['head_office']],
            ['key' => 'room_booking', 'label' => 'Room Booking', 'color' => '#14b8a6', 'icon' => 'door-open', 'site_types' => ['head_office']],
            ['key' => 'vehicle_booking', 'label' => 'Vehicle Booking', 'color' => '#f97316', 'icon' => 'truck'],
            ['key' => 'other', 'label' => 'Other', 'color' => '#64748b', 'icon' => 'file-text', 'allow_custom' => true],
        ];

        AppSetting::firstOrCreate(
            ['key' => 'sites.default_event_types'],
            ['value' => $eventTypes]
        );
    }

    private function seedHazardTypes(): void
    {
        $hazardTypes = [
            ['key' => 'slip_trip', 'label' => 'Slip / Trip / Fall', 'default_severity' => 'medium'],
            ['key' => 'fire', 'label' => 'Fire / Electrical', 'default_severity' => 'high'],
            ['key' => 'chemical', 'label' => 'Chemical / Hazardous Substance', 'default_severity' => 'high'],
            ['key' => 'biological', 'label' => 'Biological / Infection Control', 'default_severity' => 'medium'],
            ['key' => 'manual_handling', 'label' => 'Manual Handling', 'default_severity' => 'medium'],
            ['key' => 'equipment', 'label' => 'Equipment / Machinery', 'default_severity' => 'medium'],
            ['key' => 'environmental', 'label' => 'Environmental (temp, noise, light)', 'default_severity' => 'low'],
            ['key' => 'security', 'label' => 'Security / Behavioural', 'default_severity' => 'high'],
            ['key' => 'structural', 'label' => 'Structural / Building', 'default_severity' => 'high'],
            ['key' => 'custom', 'label' => 'Custom...', 'allow_create' => true],
        ];

        AppSetting::firstOrCreate(
            ['key' => 'sites.hazard_types'],
            ['value' => $hazardTypes]
        );
    }

    private function seedChecklistTemplates(): void
    {
        // House: Quality Home Checklist
        $houseTemplate = SiteChecklistTemplate::firstOrCreate(
            ['key' => 'quality_home_checklist'],
            [
                'name' => 'Quality Home Checklist',
                'description' => 'Monthly quality assurance check for residential houses',
                'applicable_to_type' => 'house',
                'frequency' => 'monthly',
            ]
        );

        $houseItems = [
            ['question' => 'Entry locks and security working correctly', 'response_type' => 'yes_no'],
            ['question' => 'Smoke alarms tested and functional', 'response_type' => 'yes_no'],
            ['question' => 'First aid kit stocked and in date', 'response_type' => 'yes_no'],
            ['question' => 'Medication storage secure and compliant', 'response_type' => 'yes_no'],
            ['question' => 'Fire extinguishers inspected and tagged', 'response_type' => 'yes_no'],
            ['question' => 'Emergency evacuation plan visible', 'response_type' => 'yes_no'],
            ['question' => 'Client bedrooms clean and tidy', 'response_type' => 'yes_no_na'],
            ['question' => 'Kitchen hygiene standards met', 'response_type' => 'yes_no'],
            ['question' => 'Refrigerator temperature', 'response_type' => 'numeric', 'response_config' => ['min' => 0, 'max' => 5]],
            ['question' => 'Outdoor areas safe and maintained', 'response_type' => 'yes_no'],
            ['question' => 'Vehicle (if applicable) maintenance up to date', 'response_type' => 'yes_no_na'],
            ['question' => 'Staff communication log completed', 'response_type' => 'yes_no'],
        ];

        foreach ($houseItems as $i => $item) {
            $houseTemplate->items()->firstOrCreate(
                ['sort_order' => $i],
                array_merge($item, ['is_required' => true])
            );
        }

        // House: New Home Walkthrough
        SiteChecklistTemplate::firstOrCreate(
            ['key' => 'new_home_walkthrough'],
            [
                'name' => 'New Home Walkthrough',
                'description' => 'Initial walkthrough checklist for new house onboarding',
                'applicable_to_type' => 'house',
                'frequency' => 'once',
            ]
        );

        // Facility: Safety Walkthrough
        $facilityTemplate = SiteChecklistTemplate::firstOrCreate(
            ['key' => 'facility_safety_walkthrough'],
            [
                'name' => 'Facility Safety Walkthrough',
                'description' => 'Safety inspection for workshop/café facilities',
                'applicable_to_type' => 'facility',
                'frequency' => 'weekly',
            ]
        );

        $facilityItems = [
            ['question' => 'All equipment guards in place', 'response_type' => 'yes_no'],
            ['question' => 'Emergency stops functional', 'response_type' => 'yes_no'],
            ['question' => 'Adequate lighting in all zones', 'response_type' => 'yes_no'],
            ['question' => 'Clear walkways and exits', 'response_type' => 'yes_no'],
            ['question' => 'PPE available and in good condition', 'response_type' => 'yes_no'],
            ['question' => 'First aid kit accessible and stocked', 'response_type' => 'yes_no'],
        ];

        foreach ($facilityItems as $i => $item) {
            $facilityTemplate->items()->firstOrCreate(
                ['sort_order' => $i],
                array_merge($item, ['is_required' => true])
            );
        }

        // Head Office: Safety & Facilities
        SiteChecklistTemplate::firstOrCreate(
            ['key' => 'ho_safety_facilities'],
            [
                'name' => 'Head Office Safety & Facilities Check',
                'description' => 'Safety and facilities check for head office',
                'applicable_to_type' => 'head_office',
                'frequency' => 'monthly',
            ]
        );
    }
}
