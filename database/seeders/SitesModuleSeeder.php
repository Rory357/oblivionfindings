<?php

namespace Database\Seeders;

use App\Models\AppSetting;
use App\Models\Site;
use App\Models\SiteChecklistTemplate;
use App\Services\Sites\SitePhysicalRoomService;
use Illuminate\Database\Seeder;

class SitesModuleSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedEventTypes();
        $this->seedHazardTypes();
        $this->seedChecklistTemplates();
        $this->seedHouseRooms();
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
        // Supported-living checklist library — 9 categories (config/checklists.php).
        // Idempotent: firstOrCreate by key + sort_order. Category/settings are
        // backfilled onto templates created before this rebuild.
        //
        // NOTE (compliance lead): item wording and frequencies below are sensible
        // starting points reviewed against the NZ Health & Disability Services
        // Standards / Ngā Paerewa — confirm exact wording before go-live.
        foreach ($this->checklistLibrary() as $def) {
            $this->seedChecklistTemplate($def);
        }
    }

    private function seedChecklistTemplate(array $def): void
    {
        $template = SiteChecklistTemplate::firstOrCreate(
            ['key' => $def['key']],
            [
                'name' => $def['name'],
                'description' => $def['description'] ?? null,
                'category' => $def['category'],
                'applicable_to_type' => $def['applicable_to_type'] ?? 'house',
                'frequency' => $def['frequency'] ?? 'monthly',
                'settings' => $def['settings'] ?? null,
                'is_active' => true,
            ]
        );

        // Backfill the new taxonomy fields onto pre-existing templates without
        // clobbering any name/description/frequency edits a tenant has made.
        $sync = [];
        if ($template->category !== $def['category']) {
            $sync['category'] = $def['category'];
        }
        if (! empty($def['settings'] ?? null) && empty($template->settings)) {
            $sync['settings'] = $def['settings'];
        }
        if (! empty($sync)) {
            $template->update($sync);
        }

        foreach (($def['items'] ?? []) as $i => $item) {
            $template->items()->firstOrCreate(
                ['sort_order' => $i],
                array_merge(
                    ['is_required' => true, 'failure_creates_hazard' => false],
                    $item,
                )
            );
        }
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function checklistLibrary(): array
    {
        // Shorthand item builders keep the library readable.
        $yn = fn (string $q, bool $hazard = false, bool $required = true) => [
            'question' => $q, 'response_type' => 'yes_no',
            'is_required' => $required, 'failure_creates_hazard' => $hazard,
        ];
        $yna = fn (string $q, bool $hazard = false) => [
            'question' => $q, 'response_type' => 'yes_no_na',
            'is_required' => true, 'failure_creates_hazard' => $hazard,
        ];
        $num = fn (string $q, int $min, int $max, string $unit = '°C', bool $hazard = true) => [
            'question' => $q, 'response_type' => 'numeric',
            'response_config' => ['min' => $min, 'max' => $max, 'unit' => $unit],
            'is_required' => true, 'failure_creates_hazard' => $hazard,
        ];
        $text = fn (string $q) => ['question' => $q, 'response_type' => 'text', 'is_required' => false];
        $photo = fn (string $q) => ['question' => $q, 'response_type' => 'photo', 'is_required' => false];

        return [
            /* ---- Health & Safety ------------------------------------------ */
            [
                'key' => 'fire_evac_drill', 'category' => 'health_safety',
                'name' => 'Fire & Evacuation Drill', 'frequency' => 'quarterly',
                'settings' => ['requires_signature' => true],
                'description' => 'Timed evacuation drill with muster, headcount and resident mobility review.',
                'items' => [
                    $yn('Evacuation completed within the target time', true),
                    $yn('All residents and staff accounted for at the muster point', true),
                    $yn('Exit routes clear and unlocked throughout the drill', true),
                    $yn('Resident mobility / evacuation needs reviewed'),
                    $yn('Assembly point appropriate and signage visible'),
                    $num('Evacuation time', 0, 10, 'min', false),
                    $yn('Debrief held and learnings recorded'),
                    $text('Issues, delays or follow-up actions'),
                ],
            ],
            [
                'key' => 'smoke_alarm_test', 'category' => 'health_safety',
                'name' => 'Smoke Alarm & Emergency Lighting Test', 'frequency' => 'monthly',
                'description' => 'Test every alarm and exit light; log any unit that fails to sound or illuminate.',
                'items' => [
                    $yn('All smoke alarms sound when tested', true),
                    $yn('All emergency / exit lights illuminate', true),
                    $yn('No units showing a fault or low-battery warning', true),
                    $yn('Detector coverage unchanged (none removed or covered)'),
                    $yna('Faulty units logged for replacement'),
                    $text('Notes'),
                ],
            ],
            [
                'key' => 'fire_extinguisher', 'category' => 'health_safety',
                'name' => 'Fire Extinguisher & Blanket Inspection', 'frequency' => 'monthly',
                'description' => 'Pressure gauge, tag date, seal and accessibility for each extinguisher and blanket.',
                'items' => [
                    $yn('Pressure gauge in the green zone for each extinguisher', true),
                    $yn('Service tag in date', true),
                    $yn('Seals and pins intact and undamaged'),
                    $yn('Mounted, accessible and unobstructed'),
                    $yn('Fire blanket present and undamaged'),
                    $text('Notes'),
                ],
            ],
            [
                'key' => 'emergency_kit', 'category' => 'health_safety',
                'name' => 'Emergency / Civil Defence Kit Check', 'frequency' => 'monthly',
                'description' => 'Grab bag, water, torch, radio, first aid and resident emergency info up to date.',
                'items' => [
                    $yn('Grab bag present and complete'),
                    $yn('Drinking water stock within date'),
                    $yn('Torch and spare batteries working'),
                    $yn('Battery / wind-up radio working'),
                    $yn('First aid supplies in the kit'),
                    $yn('Resident emergency information current'),
                    $yna('Expired or used items restocked'),
                ],
            ],
            [
                'key' => 'first_aid_kit', 'category' => 'health_safety',
                'name' => 'First Aid Kit Stock Check', 'frequency' => 'monthly',
                'description' => 'Contents complete, nothing expired, restock list raised for missing items.',
                'items' => [
                    $yn('All listed contents present'),
                    $yn('Nothing past its expiry date'),
                    $yn('Kit clean, sealed and accessible'),
                    $yna('Restock list raised for missing items'),
                    $text('Notes'),
                ],
            ],
            [
                'key' => 'hot_water_temp', 'category' => 'health_safety',
                'name' => 'Hot Water Temperature (Anti-Scald)', 'frequency' => 'weekly',
                'description' => 'Delivered water ≤ 45°C at resident outlets; cylinder ≥ 60°C for legionella control.',
                'items' => [
                    $num('Delivered water temperature at resident outlet', 0, 45, '°C'),
                    $num('Cylinder / storage temperature', 60, 80, '°C'),
                    $yn('Tempering / mixing valve functioning', true),
                    $text('Outlet(s) checked'),
                ],
            ],
            [
                'key' => 'hs_walkthrough', 'category' => 'health_safety',
                'name' => 'Health & Safety Walkthrough', 'frequency' => 'weekly',
                'settings' => ['requires_photo' => true],
                'description' => 'General hazard sweep — trip hazards, cords, lighting, exits, secure storage.',
                'items' => [
                    $yn('No trip hazards in walkways or communal areas', true),
                    $yn('Cords and cables secured and clear of paths'),
                    $yn('Lighting working in all areas'),
                    $yn('Exits clear and unlocked', true),
                    $yn('Hazardous substances stored securely', true),
                    $yn('Grab rails and mobility aids secure'),
                    $photo('Photo of any hazard found'),
                    $text('Notes'),
                ],
            ],

            /* ---- Medication ----------------------------------------------- */
            [
                'key' => 'med_storage_fridge', 'category' => 'medication',
                'name' => 'Medication Storage & Fridge Temperature', 'frequency' => 'daily',
                'description' => 'Locked storage, fridge 2–8°C, min/max recorded, no expired stock on shelf.',
                'items' => [
                    $yn('Medication storage locked and secure', true),
                    $num('Medication fridge temperature', 2, 8, '°C'),
                    $yn('Min/max thermometer read and reset'),
                    $yn('No expired stock on the shelf', true),
                    $yn('Storage area clean and tidy'),
                ],
            ],
            [
                'key' => 'controlled_drugs', 'category' => 'medication',
                'name' => 'Controlled Drugs Register & Count', 'frequency' => 'daily',
                'settings' => ['requires_signature' => true],
                'description' => 'Two-person count against the register; discrepancies escalated immediately.',
                'items' => [
                    $yn('Physical count matches the register balance', true),
                    $yn('Two-person count completed', true),
                    $yn('Register entries signed and dated'),
                    $yn('CD cabinet locked and keys controlled', true),
                    $yna('Any discrepancy escalated immediately', true),
                ],
            ],
            [
                'key' => 'med_audit', 'category' => 'medication',
                'name' => 'Medication Administration Audit', 'frequency' => 'monthly',
                'description' => 'Sample eMAR entries for signatures, gaps, PRN rationale and disposal records.',
                'items' => [
                    $yn('eMAR signatures complete with no gaps'),
                    $yn('PRN administrations have a recorded rationale'),
                    $yn('Disposed / returned medications documented'),
                    $yn('Allergies recorded and clearly visible'),
                    $yn('Sample matches the current prescription'),
                    $text('Findings and notes'),
                ],
            ],
            [
                'key' => 'sharps_disposal', 'category' => 'medication',
                'name' => 'Sharps & Clinical Waste Check', 'frequency' => 'weekly',
                'description' => 'Sharps containers below fill line, secured, collection booked when due.',
                'items' => [
                    $yn('Sharps containers below the fill line', true),
                    $yn('Containers sealed and stored securely', true),
                    $yn('Clinical waste separated correctly'),
                    $yna('Collection booked when due'),
                ],
            ],

            /* ---- Infection Control & Cleaning ----------------------------- */
            [
                'key' => 'ipc_audit', 'category' => 'infection_cleaning',
                'name' => 'Infection Prevention & Control Audit', 'frequency' => 'monthly',
                'settings' => ['requires_photo' => true],
                'description' => 'Hand hygiene stations, PPE stock, cleaning logs, colour-coded equipment.',
                'items' => [
                    $yn('Hand hygiene stations stocked'),
                    $yn('PPE available and in date'),
                    $yn('Cleaning logs complete'),
                    $yn('Colour-coded equipment used correctly'),
                    $yn('Waste segregation correct'),
                    $photo('Photo of a hand hygiene station'),
                    $text('Notes'),
                ],
            ],
            [
                'key' => 'daily_cleaning', 'category' => 'infection_cleaning',
                'name' => 'Daily Cleaning Checklist', 'frequency' => 'daily',
                'description' => 'Bathrooms, kitchen, high-touch surfaces and communal areas signed off per shift.',
                'items' => [
                    $yn('Bathrooms cleaned and sanitised'),
                    $yn('Kitchen surfaces cleaned'),
                    $yn('High-touch surfaces wiped down'),
                    $yn('Communal areas tidy'),
                    $yn('Floors vacuumed / mopped'),
                    $yn('Rubbish emptied'),
                ],
            ],
            [
                'key' => 'laundry_linen', 'category' => 'infection_cleaning',
                'name' => 'Laundry & Linen', 'frequency' => 'daily',
                'description' => 'Soiled/clean separation, wash temperatures, machine hygiene cycle logged.',
                'items' => [
                    $yn('Soiled and clean linen separated'),
                    $yn('Wash run at the correct temperature'),
                    $yna('Machine hygiene cycle logged'),
                    $yn('Linen stored clean and dry'),
                ],
            ],
            [
                'key' => 'outbreak_readiness', 'category' => 'infection_cleaning',
                'name' => 'Outbreak Readiness', 'frequency' => 'quarterly',
                'description' => 'Isolation plan, PPE buffer stock, signage, staff trained on protocol.',
                'items' => [
                    $yn('Isolation plan documented and understood'),
                    $yn('PPE buffer stock available'),
                    $yn('Signage ready to display'),
                    $yn('Staff trained on the outbreak protocol'),
                    $yn('Cleaning escalation plan in place'),
                ],
            ],

            /* ---- Food Safety & Kitchen ------------------------------------ */
            [
                'key' => 'fridge_freezer_temp', 'category' => 'food_kitchen',
                'name' => 'Fridge / Freezer Temperature Log', 'frequency' => 'daily',
                'description' => 'Fridge ≤ 5°C, freezer ≤ -18°C, AM/PM readings recorded.',
                'items' => [
                    $num('Fridge temperature', 0, 5, '°C'),
                    $num('Freezer temperature', -30, -18, '°C'),
                    $yn('AM reading recorded'),
                    $yn('PM reading recorded'),
                    $yna('Action taken for any out-of-range reading', true),
                ],
            ],
            [
                'key' => 'food_safety_haccp', 'category' => 'food_kitchen',
                'name' => 'Food Safety (Food Control Plan)', 'frequency' => 'weekly',
                'settings' => ['requires_photo' => true],
                'description' => 'Cook/cool/reheat temps, labelling, allergen separation, use-by checks.',
                'items' => [
                    $yn('Cook / reheat temperatures recorded'),
                    $yn('Cooling done within safe timeframes'),
                    $yn('Use-by / best-before checked, expired discarded'),
                    $yn('Allergens labelled and separated'),
                    $yn('Food covered, labelled and dated'),
                    $photo('Photo of fridge / store contents'),
                ],
            ],
            [
                'key' => 'kitchen_hygiene', 'category' => 'food_kitchen',
                'name' => 'Kitchen Hygiene & Cleaning', 'frequency' => 'daily',
                'description' => 'Surfaces, appliances, bins, pest evidence, dishwasher sanitising temperature.',
                'items' => [
                    $yn('Benches and surfaces sanitised'),
                    $yn('Appliances clean'),
                    $yn('Bins emptied and clean'),
                    $yn('No signs of pests', true),
                    $yna('Dishwasher sanitising temperature reached'),
                ],
            ],

            /* ---- Resident Wellbeing --------------------------------------- */
            [
                'key' => 'daily_welfare', 'category' => 'resident_wellbeing',
                'name' => 'Daily Resident Welfare Check', 'frequency' => 'daily',
                'description' => 'Wellbeing, mood, sleep, appetite, skin integrity — changes noted in care notes.',
                'items' => [
                    $yn('Wellbeing and mood observed'),
                    $yn('Sleep and rest adequate'),
                    $yn('Appetite and intake normal'),
                    $yn('Skin integrity checked'),
                    $yn('Any changes recorded in care notes'),
                    $yna('Concerns escalated'),
                ],
            ],
            [
                'key' => 'personal_care', 'category' => 'resident_wellbeing',
                'name' => 'Personal Care Routine', 'frequency' => 'daily',
                'description' => 'Support delivered per care plan; dignity, choice and consent respected.',
                'items' => [
                    $yn('Support delivered per the care plan'),
                    $yn('Dignity and privacy respected'),
                    $yn('Choice and consent respected'),
                    $yna('Continence support provided as planned'),
                    $yn('Oral and personal hygiene supported'),
                ],
            ],
            [
                'key' => 'nutrition_hydration', 'category' => 'resident_wellbeing',
                'name' => 'Nutrition & Hydration', 'frequency' => 'daily',
                'description' => 'Meals offered, intake, fluids, texture-modified diets and dislikes honoured.',
                'items' => [
                    $yn('Meals offered per the plan'),
                    $yn('Fluids offered and intake adequate'),
                    $yna('Texture-modified diet followed'),
                    $yn('Dislikes and allergies honoured'),
                    $yna('Intake concerns recorded'),
                ],
            ],
            [
                'key' => 'activities_community', 'category' => 'resident_wellbeing',
                'name' => 'Activities & Community Participation', 'frequency' => 'weekly',
                'description' => 'Goals from My Plan supported; outings, interests and connections logged.',
                'items' => [
                    $yn('Goals from My Plan supported'),
                    $yn('Activity or outing offered'),
                    $yna('Community connection supported'),
                    $yn('Participation logged'),
                ],
            ],
            [
                'key' => 'bedroom_environment', 'category' => 'resident_wellbeing',
                'name' => 'Bedroom & Personal Space Check', 'frequency' => 'weekly',
                'settings' => ['requires_photo' => true],
                'description' => 'Clean, personalised, safe, call system reachable, belongings respected.',
                'items' => [
                    $yn('Room clean and tidy'),
                    $yn("Personalised to the resident's wishes"),
                    $yn('Call system reachable and working', true),
                    $yn('Belongings respected and secure'),
                    $photo('Photo of room condition'),
                ],
            ],

            /* ---- Property & Facilities ------------------------------------ */
            [
                'key' => 'opening_shift', 'category' => 'property_facilities',
                'name' => 'Shift Start / Opening Checklist', 'frequency' => 'daily',
                'description' => 'Handover read, headcount, keys, alarms, vehicle, communication log.',
                'items' => [
                    $yn('Handover read and understood'),
                    $yn('Resident headcount confirmed'),
                    $yn('Keys and access accounted for'),
                    $yn('Alarms and systems checked'),
                    $yna('Vehicle available and fuelled'),
                    $yn('Communication log started'),
                ],
            ],
            [
                'key' => 'closing_shift', 'category' => 'property_facilities',
                'name' => 'Shift End / Closing Checklist', 'frequency' => 'daily',
                'description' => 'Doors/windows secure, appliances off, notes written, next shift briefed.',
                'items' => [
                    $yn('Doors and windows secure', true),
                    $yn('Appliances and heaters off as required'),
                    $yn('Medication storage locked', true),
                    $yn('Notes written for the next shift'),
                    $yn('Next shift briefed'),
                ],
            ],
            [
                'key' => 'weekly_house_inspection', 'category' => 'property_facilities',
                'name' => 'Weekly House Inspection', 'frequency' => 'weekly',
                'settings' => ['requires_photo' => true],
                'description' => 'Room-by-room condition, repairs needed, damage logged against the house.',
                'items' => [
                    $yn('Living areas safe and undamaged'),
                    $yn('Bedrooms safe and undamaged'),
                    $yn('Bathrooms working and safe'),
                    $yna('Repairs needed identified'),
                    $yna('Damage logged against the house', true),
                    $photo('Photo of any damage found'),
                ],
            ],
            [
                // The "core" template — already seeded historically; keep its
                // 12 items, now with hazard flags + a numeric fridge reading.
                'key' => 'quality_home_checklist', 'category' => 'property_facilities',
                'name' => 'Monthly Quality Home Checklist', 'frequency' => 'monthly',
                'applicable_to_type' => 'house',
                'settings' => ['requires_photo' => true, 'requires_signature' => true],
                'description' => 'Full quality assurance sweep covering safety, comfort and compliance.',
                'items' => [
                    $yn('Entry locks and security working correctly', true),
                    $yn('Smoke alarms tested and functional', true),
                    $yn('First aid kit stocked and in date'),
                    $yn('Medication storage secure and compliant', true),
                    $yn('Fire extinguishers inspected and tagged', true),
                    $yn('Emergency evacuation plan visible'),
                    $yna('Client bedrooms clean and tidy'),
                    $yn('Kitchen hygiene standards met'),
                    $num('Refrigerator temperature', 0, 5, '°C'),
                    $yn('Outdoor areas safe and maintained'),
                    $yna('Vehicle (if applicable) maintenance up to date'),
                    $yn('Staff communication log completed'),
                ],
            ],
            [
                'key' => 'maintenance_request', 'category' => 'property_facilities',
                'name' => 'Planned Maintenance & Appliances', 'frequency' => 'monthly',
                'description' => 'Heat pumps, smoke detectors, appliances serviced; faults raised to vendors.',
                'items' => [
                    $yn('Heating / heat pumps serviced or checked'),
                    $yn('Smoke detectors functional', true),
                    $yn('Appliances working safely'),
                    $yna('Faults raised to vendors'),
                    $yn('Maintenance log updated'),
                ],
            ],
            [
                'key' => 'grounds_garden', 'category' => 'property_facilities',
                'name' => 'Grounds, Garden & Exterior', 'frequency' => 'fortnightly',
                'description' => 'Paths clear, fencing secure, lighting works, outdoor areas safe and tidy.',
                'items' => [
                    $yn('Paths clear and non-slip'),
                    $yn('Fencing and gates secure'),
                    $yn('Exterior lighting works'),
                    $yn('Outdoor areas tidy and safe'),
                    $yn('Rubbish and recycling managed'),
                ],
            ],
            [
                // Legacy facility template — retained and categorised.
                'key' => 'facility_safety_walkthrough', 'category' => 'property_facilities',
                'name' => 'Facility Safety Walkthrough', 'frequency' => 'weekly',
                'applicable_to_type' => 'facility',
                'description' => 'Safety inspection for workshop/café facilities.',
                'items' => [
                    $yn('All equipment guards in place', true),
                    $yn('Emergency stops functional', true),
                    $yn('Adequate lighting in all zones'),
                    $yn('Clear walkways and exits', true),
                    $yn('PPE available and in good condition'),
                    $yn('First aid kit accessible and stocked'),
                ],
            ],
            [
                // Legacy head-office template — retained and categorised.
                'key' => 'ho_safety_facilities', 'category' => 'property_facilities',
                'name' => 'Head Office Safety & Facilities Check', 'frequency' => 'monthly',
                'applicable_to_type' => 'head_office',
                'description' => 'Safety and facilities check for head office.',
                'items' => [
                    $yn('Walkways and exits clear', true),
                    $yn('Smoke alarms and extinguishers in date', true),
                    $yn('First aid kit stocked'),
                    $yn('Electrical leads tagged and tidy'),
                    $yn('Kitchen and amenities clean'),
                ],
            ],

            /* ---- Vehicle & Transport -------------------------------------- */
            [
                'key' => 'vehicle_preuse', 'category' => 'vehicle_transport',
                'name' => 'Vehicle Pre-Use Safety Check', 'frequency' => 'daily',
                'description' => 'Tyres, lights, fuel/charge, WOF, first aid, child/wheelchair restraints.',
                'items' => [
                    $yn('Tyres in good condition and inflated', true),
                    $yn('Lights and indicators working', true),
                    $yn('Fuel / charge sufficient'),
                    $yn('WOF and registration current', true),
                    $yn('First aid kit present'),
                    $yna('Child / wheelchair restraints functional', true),
                ],
            ],
            [
                'key' => 'vehicle_log', 'category' => 'vehicle_transport',
                'name' => 'Vehicle Cleaning & Log Review', 'frequency' => 'weekly',
                'description' => 'Mileage, fuel card, cleanliness, damage report, service due tracking.',
                'items' => [
                    $yn('Mileage recorded'),
                    $yna('Fuel card reconciled'),
                    $yn('Vehicle clean inside and out'),
                    $yna('Damage reported'),
                    $yn('Service due tracked'),
                ],
            ],

            /* ---- Governance & Audit --------------------------------------- */
            [
                'key' => 'records_audit', 'category' => 'governance_audit',
                'name' => 'Records & Documentation Audit', 'frequency' => 'monthly',
                'description' => 'Care plans current, consents signed, daily notes complete and contemporaneous.',
                'items' => [
                    $yn('Care plans current and reviewed'),
                    $yn('Consents signed and in date'),
                    $yn('Daily notes complete and contemporaneous'),
                    $yn('Incident reports followed up'),
                    $yn('Confidentiality maintained'),
                ],
            ],
            [
                'key' => 'restraint_register', 'category' => 'governance_audit',
                'name' => 'Restraint & Enabler Register Review', 'frequency' => 'monthly',
                'settings' => ['requires_signature' => true],
                'description' => 'Every restraint authorised, reviewed, least-restrictive and time-limited.',
                'items' => [
                    $yn('Every restraint authorised'),
                    $yn('Least-restrictive option used'),
                    $yn('Reviews completed and time-limited'),
                    $yn('Enablers documented and consented'),
                    $yn('Register up to date'),
                ],
            ],
            [
                'key' => 'complaints_feedback', 'category' => 'governance_audit',
                'name' => 'Complaints & Feedback Review', 'frequency' => 'monthly',
                'description' => 'Open items actioned, themes identified, residents/whānau responses closed out.',
                'items' => [
                    $yn('Open complaints actioned'),
                    $yn('Response timeframes met'),
                    $yn('Themes and trends identified'),
                    $yn('Residents / whānau responses closed out'),
                    $yn('Learnings shared with the team'),
                ],
            ],
            [
                'key' => 'cert_readiness', 'category' => 'governance_audit',
                'name' => 'Certification Readiness (Ngā Paerewa)', 'frequency' => 'quarterly',
                'applicable_to_type' => 'all',
                'settings' => ['requires_photo' => true, 'requires_signature' => true],
                'description' => 'Self-audit against the Health & Disability Services Standards ahead of HealthCERT.',
                'items' => [
                    $yn('Self-audit against the standards completed'),
                    $yn('Evidence files current and accessible'),
                    $yn('Corrective actions tracked to closure'),
                    $yn('Staff training records up to date'),
                    $photo('Photo of evidence / board'),
                    $text('Notes'),
                ],
            ],

            /* ---- Move-in / Move-out --------------------------------------- */
            [
                'key' => 'new_home_walkthrough', 'category' => 'movein_moveout',
                'name' => 'New Home Setup Walkthrough', 'frequency' => 'once',
                'settings' => ['requires_photo' => true, 'requires_signature' => true],
                'description' => 'Initial readiness sweep before a new house opens to residents.',
                'items' => [
                    $yn('All rooms clean and ready for occupancy'),
                    $yn('All windows and doors lock properly', true),
                    $yn('Hot water system tested and working'),
                    $yn('Heating / cooling systems operational'),
                    $yn('Smoke alarms installed and tested', true),
                    $yn('Fire extinguisher present and tagged', true),
                    $yn('Emergency exits clearly marked', true),
                    $yn('Kitchen appliances clean and working'),
                    $yn('Bathroom fixtures working (taps, toilet, shower)'),
                    $yn('Power points and light switches functional'),
                    $yna('Internet / phone connectivity available'),
                    $yn('Outdoor areas safe (fencing, paths, lighting)'),
                    $yn('Medication storage area secured', true),
                    $yn('Resident welcome pack prepared'),
                    $text('Additional notes or issues found'),
                ],
            ],
            [
                'key' => 'resident_movein', 'category' => 'movein_moveout',
                'name' => 'Resident Move-In Setup', 'frequency' => 'once',
                'description' => 'Room ready, belongings, consents, care plan, GP/meds transferred, welcome.',
                'items' => [
                    $yn('Room ready and cleaned'),
                    $yn('Belongings received and stored'),
                    $yn('Consents and agreements signed'),
                    $yn('Care plan in place'),
                    $yn('GP and medications transferred'),
                    $yn('Welcome and orientation completed'),
                ],
            ],
            [
                'key' => 'resident_moveout', 'category' => 'movein_moveout',
                'name' => 'Resident Move-Out / Exit', 'frequency' => 'once',
                'description' => 'Belongings, records archived, meds returned, room reset, transition support.',
                'items' => [
                    $yn('Belongings packed and returned'),
                    $yn('Records archived appropriately'),
                    $yn('Medications returned or disposed'),
                    $yn('Room reset for the next resident'),
                    $yn('Transition support provided'),
                ],
            ],
        ];
    }

    private function seedHouseRooms(): void
    {
        $defaultRooms = [
            'Bedroom 1', 'Bedroom 2', 'Bedroom 3',
            'Kitchen', 'Lounge', 'Bathroom',
            'Laundry', 'Hallway', 'Garage', 'Garden/Exterior',
        ];

        $houses = Site::whereIn('type', ['house', 'residential'])->get();

        foreach ($houses as $site) {
            if ($site->houseRooms()->count() > 0) {
                continue;
            }

            foreach ($defaultRooms as $i => $name) {
                app(SitePhysicalRoomService::class)->createResidentialRoom($site, [
                    'name' => $name,
                    'is_active' => true,
                    'sort_order' => $i,
                ]);
            }
        }
    }
}
