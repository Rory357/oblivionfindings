<?php

/*
|--------------------------------------------------------------------------
| Checklists taxonomy
|--------------------------------------------------------------------------
|
| Single source of truth for the supported-living checklist library. The
| `category` column on site_checklist_templates stores one of the keys below;
| the dashboard (ChecklistsDashboardData) exposes this list to the frontend so
| labels, icons and accent tones stay consistent across the Library tab, the
| template builder and every category chip.
|
| `tone` maps onto existing design tokens (see resources/css/app.css):
|   - category tokens: ops | fleet | governance | sites | compliance
|   - status tokens:   critical | warning | success | info
| Icons are lucide-react names (PascalCase), resolved on the frontend.
|
*/

return [

    // Ordered — this drives display order in the Library tab and chips.
    'categories' => [
        [
            'key' => 'health_safety',
            'label' => 'Health & Safety',
            'short' => 'H&S',
            'icon' => 'ShieldAlert',
            'tone' => 'critical',
            'blurb' => 'Fire, alarms, anti-scald and hazard sweeps',
        ],
        [
            'key' => 'medication',
            'label' => 'Medication',
            'short' => 'Meds',
            'icon' => 'Pill',
            'tone' => 'info',
            'blurb' => 'Storage, controlled drugs and administration audits',
        ],
        [
            'key' => 'infection_cleaning',
            'label' => 'Infection Control & Cleaning',
            'short' => 'IPC',
            'icon' => 'SprayCan',
            'tone' => 'success',
            'blurb' => 'IPC audits, daily cleaning and outbreak readiness',
        ],
        [
            'key' => 'food_kitchen',
            'label' => 'Food Safety & Kitchen',
            'short' => 'Food',
            'icon' => 'UtensilsCrossed',
            'tone' => 'warning',
            'blurb' => 'Temperatures, food control plan and kitchen hygiene',
        ],
        [
            'key' => 'resident_wellbeing',
            'label' => 'Resident Wellbeing',
            'short' => 'Wellbeing',
            'icon' => 'HeartHandshake',
            'tone' => 'sites',
            'blurb' => 'Welfare, personal care, nutrition and activities',
        ],
        [
            'key' => 'property_facilities',
            'label' => 'Property & Facilities',
            'short' => 'Property',
            'icon' => 'House',
            'tone' => 'ops',
            'blurb' => 'Opening/closing, inspections and maintenance',
        ],
        [
            'key' => 'vehicle_transport',
            'label' => 'Vehicle & Transport',
            'short' => 'Vehicle',
            'icon' => 'Car',
            'tone' => 'fleet',
            'blurb' => 'Pre-use safety checks and vehicle logs',
        ],
        [
            'key' => 'governance_audit',
            'label' => 'Governance & Audit',
            'short' => 'Governance',
            'icon' => 'Gavel',
            'tone' => 'governance',
            'blurb' => 'Records, restraint register and certification prep',
        ],
        [
            'key' => 'movein_moveout',
            'label' => 'Move-in / Move-out',
            'short' => 'Transitions',
            'icon' => 'PackageOpen',
            'tone' => 'compliance',
            'blurb' => 'New home setup and resident transitions',
        ],
    ],

    'frequency_labels' => [
        'once' => 'One-time',
        'daily' => 'Daily',
        'weekly' => 'Weekly',
        'fortnightly' => 'Fortnightly',
        'monthly' => 'Monthly',
        'quarterly' => 'Quarterly',
        'annual' => 'Annual',
    ],

    'type_labels' => [
        'house' => 'House',
        'head_office' => 'Head Office',
        'facility' => 'Facility',
        'all' => 'All site types',
    ],
];
