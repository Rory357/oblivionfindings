<?php

/*
|--------------------------------------------------------------------------
| Site Plan Builder Taxonomy
|--------------------------------------------------------------------------
|
| Defines the human labels, default colours, and permitted `subkind` values
| for every pin `kind`. Shared with the React frontend via the
| `SiteTypePlanService::taxonomy()` method so both halves of the app stay in
| sync.
|
| Each group entry has:
|  - id:    machine identifier used by the tool palette grouping
|  - label: human label (NZ English)
|  - kinds: ordered list of kinds shown under the group
|
| Each kind entry has:
|  - label:    short label shown on the tool tile and the pin
|  - icon:     lucide-react icon name (consumed by the frontend)
|  - color:    HEX colour for the pin marker
|  - subkinds: optional [{value, label}] list shown after placement
|  - measure:  optional "line" | "polyline" | "area" — interaction hint
|
*/

return [
    'groups' => [
        [
            'id' => 'structure',
            'label' => 'Structure',
            'kinds' => ['__room', '__wall', '__door', '__window', '__label'],
        ],
        [
            'id' => 'fire',
            'label' => 'Fire safety',
            'kinds' => [
                'fire_extinguisher',
                'fire_blanket',
                'fire_hose_reel',
                'fire_panel',
                'fire_door',
                'sprinkler_head',
                'smoke_alarm',
                'manual_call_point',
                'hydrant',
            ],
        ],
        [
            'id' => 'emergency',
            'label' => 'Emergency',
            'kinds' => [
                'emergency_exit',
                'evacuation_route',
                'assembly_point',
                'you_are_here',
                'evacuation_diagram',
            ],
        ],
        [
            'id' => 'life_safety',
            'label' => 'Life safety',
            'kinds' => ['first_aid_kit', 'defibrillator', 'medication_storage'],
        ],
        [
            'id' => 'utilities',
            'label' => 'Utilities',
            'kinds' => ['gas_shutoff', 'water_shutoff', 'electrical_panel'],
        ],
        [
            'id' => 'devices',
            'label' => 'Devices',
            'kinds' => ['device'],
        ],
        [
            'id' => 'annotation',
            'label' => 'Annotation',
            'kinds' => ['custom_marker'],
        ],
    ],

    'shapes' => [
        '__room' => ['label' => 'Room', 'icon' => 'Square', 'tool' => 'room'],
        '__wall' => ['label' => 'Wall', 'icon' => 'Pencil', 'tool' => 'wall', 'measure' => 'line'],
        '__door' => ['label' => 'Door', 'icon' => 'DoorOpen', 'tool' => 'door'],
        '__window' => ['label' => 'Window', 'icon' => 'PanelTop', 'tool' => 'window'],
        '__label' => ['label' => 'Label', 'icon' => 'Type', 'tool' => 'label'],
    ],

    'kinds' => [

        // Fire safety -----------------------------------------------------
        'fire_extinguisher' => [
            'label' => 'Fire extinguisher',
            'icon' => 'FlameKindling',
            'color' => '#ea580c',
            'subkinds' => [
                ['value' => 'dry_powder', 'label' => 'Dry powder (A/B/E)'],
                ['value' => 'co2', 'label' => 'CO₂ (B/E)'],
                ['value' => 'foam', 'label' => 'Foam (A/B)'],
                ['value' => 'water', 'label' => 'Water (A)'],
                ['value' => 'wet_chemical', 'label' => 'Wet chemical (F)'],
                ['value' => 'class_d', 'label' => 'Class D (metal fires)'],
            ],
        ],
        'fire_blanket' => [
            'label' => 'Fire blanket',
            'icon' => 'Shield',
            'color' => '#dc2626',
        ],
        'fire_hose_reel' => [
            'label' => 'Fire hose reel',
            'icon' => 'CircleDashed',
            'color' => '#dc2626',
        ],
        'fire_panel' => [
            'label' => 'Fire panel',
            'icon' => 'Cpu',
            'color' => '#7c2d12',
            'subkinds' => [
                ['value' => 'conventional', 'label' => 'Conventional'],
                ['value' => 'addressable', 'label' => 'Addressable'],
            ],
        ],
        'fire_door' => [
            'label' => 'Fire door',
            'icon' => 'DoorClosed',
            'color' => '#b45309',
            'subkinds' => [
                ['value' => 'fd30', 'label' => 'FD30 (30 min)'],
                ['value' => 'fd60', 'label' => 'FD60 (60 min)'],
                ['value' => 'fd90', 'label' => 'FD90 (90 min)'],
                ['value' => 'fd120', 'label' => 'FD120 (120 min)'],
            ],
        ],
        'sprinkler_head' => [
            'label' => 'Sprinkler',
            'icon' => 'Droplets',
            'color' => '#0284c7',
            'subkinds' => [
                ['value' => 'pendant', 'label' => 'Pendant'],
                ['value' => 'upright', 'label' => 'Upright'],
                ['value' => 'sidewall', 'label' => 'Sidewall'],
                ['value' => 'concealed', 'label' => 'Concealed'],
            ],
        ],
        'smoke_alarm' => [
            'label' => 'Smoke alarm',
            'icon' => 'BellRing',
            'color' => '#64748b',
            'subkinds' => [
                ['value' => 'photoelectric', 'label' => 'Photoelectric'],
                ['value' => 'ionisation', 'label' => 'Ionisation'],
                ['value' => 'dual_sensor', 'label' => 'Dual sensor'],
                ['value' => 'heat_detector', 'label' => 'Heat detector'],
            ],
        ],
        'manual_call_point' => [
            'label' => 'Manual call point',
            'icon' => 'BellElectric',
            'color' => '#dc2626',
        ],
        'hydrant' => [
            'label' => 'Hydrant',
            'icon' => 'Pipette',
            'color' => '#1d4ed8',
            'subkinds' => [
                ['value' => 'wall', 'label' => 'Wall'],
                ['value' => 'pillar', 'label' => 'Pillar'],
                ['value' => 'underground', 'label' => 'Underground'],
            ],
        ],

        // Emergency -------------------------------------------------------
        'emergency_exit' => [
            'label' => 'Emergency exit',
            'icon' => 'DoorOpen',
            'color' => '#dc2626',
        ],
        'evacuation_route' => [
            'label' => 'Evacuation route',
            'icon' => 'Route',
            'color' => '#d97706',
            'measure' => 'polyline',
        ],
        'assembly_point' => [
            'label' => 'Assembly point',
            'icon' => 'MapPin',
            'color' => '#059669',
        ],
        'you_are_here' => [
            'label' => 'You are here',
            'icon' => 'Crosshair',
            'color' => '#0f172a',
        ],
        'evacuation_diagram' => [
            'label' => 'Evacuation diagram',
            'icon' => 'BookMarked',
            'color' => '#0f766e',
        ],

        // Life safety -----------------------------------------------------
        'first_aid_kit' => [
            'label' => 'First-aid kit',
            'icon' => 'Cross',
            'color' => '#16a34a',
            'subkinds' => [
                ['value' => 'workplace', 'label' => 'Workplace'],
                ['value' => 'vehicle', 'label' => 'Vehicle'],
                ['value' => 'outdoor', 'label' => 'Outdoor'],
            ],
        ],
        'defibrillator' => [
            'label' => 'Defibrillator (AED)',
            'icon' => 'HeartPulse',
            'color' => '#be123c',
            'subkinds' => [
                ['value' => 'aed_semi_auto', 'label' => 'AED — semi-auto'],
                ['value' => 'aed_fully_auto', 'label' => 'AED — fully auto'],
            ],
        ],
        'medication_storage' => [
            'label' => 'Medication storage',
            'icon' => 'Pill',
            'color' => '#7c3aed',
        ],

        // Utilities -------------------------------------------------------
        'gas_shutoff' => [
            'label' => 'Gas shut-off',
            'icon' => 'Flame',
            'color' => '#b45309',
        ],
        'water_shutoff' => [
            'label' => 'Water shut-off',
            'icon' => 'Droplet',
            'color' => '#0369a1',
        ],
        'electrical_panel' => [
            'label' => 'Electrical panel',
            'icon' => 'Zap',
            'color' => '#ca8a04',
        ],

        // Devices ---------------------------------------------------------
        'device' => [
            'label' => 'Device',
            'icon' => 'Video',
            'color' => '#2563eb',
        ],

        // Annotation ------------------------------------------------------
        'custom_marker' => [
            'label' => 'Custom marker',
            'icon' => 'Pin',
            'color' => '#475569',
        ],
    ],
];
