import { medicationReturnTo } from '@/lib/medication-navigation';
import { SUPPORT, type SupportMode } from '@/pages/emar/support/types';
import {
    Activity,
    BellRing,
    CalendarDays,
    Camera,
    ClipboardList,
    History,
    Pill,
    ShieldAlert,
    Stethoscope,
    Syringe,
    Users,
    type LucideIcon,
} from 'lucide-react';

/**
 * The record's sections (the header rail) and their views (the tier-2
 * strip), from the approved P02 v1. A section joins the rail when its build
 * chunk lands — never as an empty or placeholder tab (hide unbuilt).
 */
export type RecordTab =
    | 'chart'
    | 'medicines'
    | 'support'
    | 'allergies'
    | 'clinical'
    | 'history';

export interface RecordSection {
    key: RecordTab;
    label: string;
    icon: LucideIcon;
    views: { key: string; label: string; icon: LucideIcon }[];
}

export const RECORD_SECTIONS: RecordSection[] = [
    {
        key: 'chart',
        label: 'Chart',
        icon: ClipboardList,
        views: [
            { key: 'scheduled', label: 'Scheduled doses', icon: CalendarDays },
            { key: 'asneeded', label: 'As needed', icon: Pill },
        ],
    },
    {
        key: 'medicines',
        label: 'Medicines',
        icon: Pill,
        views: [
            { key: 'current', label: 'Current', icon: Pill },
            { key: 'stopped', label: 'Stopped', icon: History },
            // C11: staff photos of the packs supplied (P06 capture at receipt).
            { key: 'photos', label: 'Photos', icon: Camera },
        ],
    },
    {
        key: 'support',
        label: 'Support plan',
        icon: Users,
        views: [
            { key: 'bymedicine', label: 'By medicine', icon: Users },
            { key: 'assessment', label: 'Assessment', icon: ClipboardList },
            { key: 'agreement', label: 'Agreement', icon: Users },
            { key: 'changes', label: 'Support changes', icon: History },
        ],
    },
    {
        key: 'allergies',
        label: 'Allergies & alerts',
        icon: ShieldAlert,
        views: [
            { key: 'allergies', label: 'Allergies', icon: ShieldAlert },
            { key: 'alerts', label: 'Chart alerts', icon: BellRing },
            { key: 'interactions', label: 'Interactions', icon: Pill },
        ],
    },
    {
        key: 'clinical',
        label: 'Clinical',
        icon: Stethoscope,
        views: [
            { key: 'inr', label: 'INR', icon: Activity },
            { key: 'driver', label: 'Syringe driver', icon: Syringe },
            { key: 'observations', label: 'Dose readings', icon: Stethoscope },
        ],
    },
    {
        key: 'history',
        label: 'History',
        icon: History,
        views: [
            { key: 'doses', label: 'Doses', icon: History },
            { key: 'corrections', label: 'Corrections', icon: ClipboardList },
            { key: 'changes', label: 'All changes', icon: History },
        ],
    },
];

export interface RecordLocation {
    tab: RecordTab;
    view: string;
}

/** Read ?tab=&view= into a section and view this page has, else the first. */
export function readLocation(search: string): RecordLocation {
    const params = new URLSearchParams(search);
    const section =
        RECORD_SECTIONS.find((s) => s.key === params.get('tab')) ??
        RECORD_SECTIONS[0];
    const view =
        section.views.find((v) => v.key === params.get('view'))?.key ??
        section.views[0].key;
    return { tab: section.key, view };
}

/** P02 Support filter (Lane C9f), worded as the support badges are. */
export const SUPPORT_FILTERS = [
    { value: 'all', label: 'All support' },
    { value: 'staff_given', label: SUPPORT.staff_given.label },
    { value: 'assisted', label: SUPPORT.assisted.label },
    { value: 'prompted', label: SUPPORT.prompted.label },
    { value: 'self_managed', label: SUPPORT.self_managed.label },
] as const satisfies readonly { value: 'all' | SupportMode; label: string }[];
export type SupportFilter = (typeof SUPPORT_FILTERS)[number]['value'];

/** The URL for a location, keeping the person and dropping first-view defaults. */
export function locationSearch(
    clientId: number,
    location: RecordLocation,
    extra: Record<string, string | null | undefined> = {},
    currentSearch = '',
): string {
    const section = RECORD_SECTIONS.find((s) => s.key === location.tab)!;
    const params = new URLSearchParams({ client_id: String(clientId) });
    const current = new URLSearchParams(currentSearch);
    // Person/day/house are record context, not section-specific filters.
    // Never carry a previous section's page, dose, or medicine selection.
    for (const key of ['date', 'site_id', 'mode']) {
        const value = current.get(key);
        const valid =
            value &&
            (key === 'date'
                ? /^\d{4}-\d{2}-\d{2}$/.test(value)
                : key === 'site_id'
                  ? /^[1-9]\d*$/.test(value)
                  : value === 'week');
        if (valid) params.set(key, value);
    }
    const returnTo = medicationReturnTo(current.get('return_to'));
    if (returnTo) params.set('return_to', returnTo);
    if (location.tab !== RECORD_SECTIONS[0].key)
        params.set('tab', location.tab);
    if (location.view !== section.views[0].key)
        params.set('view', location.view);
    for (const [key, value] of Object.entries(extra)) {
        if (value) params.set(key, value);
        else params.delete(key);
    }
    return `?${params.toString()}`;
}
