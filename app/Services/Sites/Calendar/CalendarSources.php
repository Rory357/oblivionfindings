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
            ['key' => 'meal',       'label' => 'Meal plan',         'short' => 'Meal',       'group' => 'auto',   'icon' => 'Utensils',      'origin' => 'Meal planner'],
            ['key' => 'damage',     'label' => 'Damage follow-up',  'short' => 'Damage',     'group' => 'auto',   'icon' => 'Hammer',        'origin' => 'Damages'],
        ];
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
