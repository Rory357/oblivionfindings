<?php

namespace App\Services\Sites\Calendar;

/**
 * The event-source taxonomy that drives the calendar legend and colour-by-source
 * palette. `manual` = created by hand on the calendar; `auto` = derived from
 * another Sites module. Mirrors the prototype's SOURCES list (cal-data.js).
 *
 * Colours live as fixed `--src-{key}` design tokens in the frontend (app.css);
 * this list only carries identity/labels/icons for the UI.
 */
class CalendarSources
{
    /**
     * @return array<int, array{key:string,label:string,short:string,group:string,icon:string,origin:string}>
     */
    public static function all(): array
    {
        return [
            ['key' => 'event',      'label' => 'Event',             'short' => 'Event',      'group' => 'manual', 'icon' => 'CalendarDays',  'origin' => 'Calendar'],
            ['key' => 'inspection', 'label' => 'Inspection',        'short' => 'Inspection', 'group' => 'auto',   'icon' => 'ClipboardList', 'origin' => 'Inspections'],
            ['key' => 'compliance', 'label' => 'Compliance & certs','short' => 'Compliance', 'group' => 'auto',   'icon' => 'ShieldCheck',   'origin' => 'Compliance'],
            ['key' => 'credential', 'label' => 'Credential expiry', 'short' => 'Credential', 'group' => 'auto',   'icon' => 'KeyRound',      'origin' => 'Credentials vault'],
            ['key' => 'checklist',  'label' => 'Checklist run',     'short' => 'Checklist',  'group' => 'auto',   'icon' => 'CheckSquare',   'origin' => 'Checklists'],
            ['key' => 'hazard',     'label' => 'Hazard review',     'short' => 'Hazard',     'group' => 'auto',   'icon' => 'AlertTriangle', 'origin' => 'Hazard register'],
            ['key' => 'vendor',     'label' => 'Vendor / insurance','short' => 'Vendor',     'group' => 'auto',   'icon' => 'Wrench',        'origin' => 'Vendors'],
            ['key' => 'asset',      'label' => 'Fleet / asset',     'short' => 'Asset',      'group' => 'auto',   'icon' => 'Truck',         'origin' => 'Assets register'],
            ['key' => 'meal',       'label' => 'Meal plan',         'short' => 'Meal',       'group' => 'auto',   'icon' => 'Utensils',      'origin' => 'Meal planner'],
            ['key' => 'damage',     'label' => 'Damage follow-up',  'short' => 'Damage',     'group' => 'auto',   'icon' => 'Hammer',        'origin' => 'Damages'],
            ['key' => 'emergency',  'label' => 'Emergency plan',    'short' => 'Emergency',  'group' => 'auto',   'icon' => 'Siren',         'origin' => 'Emergency plans'],
            ['key' => 'drill',      'label' => 'Emergency drill',   'short' => 'Drill',      'group' => 'auto',   'icon' => 'Flame',         'origin' => 'Emergency drills'],
            ['key' => 'respite',    'label' => 'Respite booking',   'short' => 'Respite',    'group' => 'auto',   'icon' => 'BedDouble',     'origin' => 'Respite'],
            ['key' => 'participation', 'label' => 'Worker participation', 'short' => 'Participation', 'group' => 'auto', 'icon' => 'Users',     'origin' => 'Worker participation'],
            ['key' => 'external',   'label' => 'External busy',     'short' => 'External',   'group' => 'external', 'icon' => 'Lock',        'origin' => 'External calendar'],
        ];
    }

    /**
     * Sources that can be chosen as a mapping's *push* filter (everything except the
     * pull-only "external" busy layer).
     *
     * @return array<int, array{key:string,label:string,short:string,group:string,icon:string,origin:string}>
     */
    public static function pushable(): array
    {
        return array_values(array_filter(self::all(), fn (array $s) => $s['group'] !== 'external'));
    }

    /**
     * Source keys only.
     *
     * @return string[]
     */
    public static function keys(): array
    {
        return array_column(self::all(), 'key');
    }
}
