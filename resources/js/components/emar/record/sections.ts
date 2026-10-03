import {
    Activity,
    BellRing,
    CalendarDays,
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
 * Pack photos (Medicines › Photos) wait for P06's capture at stock receipt.
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

/** The URL for a location, keeping the person and dropping first-view defaults. */
export function locationSearch(
    clientId: number,
    location: RecordLocation,
    extra: Record<string, string | null | undefined> = {},
): string {
    const section = RECORD_SECTIONS.find((s) => s.key === location.tab)!;
    const params = new URLSearchParams({ client_id: String(clientId) });
    if (location.tab !== RECORD_SECTIONS[0].key)
        params.set('tab', location.tab);
    if (location.view !== section.views[0].key)
        params.set('view', location.view);
    for (const [key, value] of Object.entries(extra)) {
        if (value) params.set(key, value);
    }
    return `?${params.toString()}`;
}
