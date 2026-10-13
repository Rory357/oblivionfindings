import {
    PageHeaderFilterSelect,
    PageHeaderRail,
    type PageHeaderRailItem,
} from '@/components/page';
import {
    AlertTriangle,
    BarChart3,
    CalendarCheck,
    CalendarDays,
    Repeat,
} from 'lucide-react';

export type RosterView =
    | 'shifts'
    | 'calendar'
    | 'open'
    | 'coverage'
    | 'timeoff'
    | 'availability'
    | 'capacity'
    | 'analytics'
    | 'templates'
    | 'recurring';

type Section = 'roster' | 'cover' | 'availability' | 'patterns' | 'reports';

const sections = [
    {
        key: 'roster',
        label: 'Roster',
        icon: CalendarDays,
        views: ['shifts', 'calendar'],
    },
    {
        key: 'cover',
        label: 'Cover & conflicts',
        icon: AlertTriangle,
        views: ['open', 'coverage'],
    },
    {
        key: 'availability',
        label: 'Availability & leave',
        icon: CalendarCheck,
        views: ['availability', 'timeoff'],
    },
    {
        key: 'patterns',
        label: 'Patterns',
        icon: Repeat,
        views: ['templates', 'recurring'],
    },
    {
        key: 'reports',
        label: 'Reports',
        icon: BarChart3,
        views: ['capacity', 'analytics'],
    },
] satisfies {
    key: Section;
    label: string;
    icon: typeof CalendarDays;
    views: RosterView[];
}[];

const sectionByView: Record<RosterView, Section> = {
    shifts: 'roster',
    calendar: 'roster',
    open: 'cover',
    coverage: 'cover',
    timeoff: 'availability',
    availability: 'availability',
    templates: 'patterns',
    recurring: 'patterns',
    capacity: 'reports',
    analytics: 'reports',
};

type Props = {
    value: RosterView;
    views: PageHeaderRailItem<RosterView>[];
    onSelect: (view: RosterView) => void;
};

/** Group presentation only: the owner keeps its existing view IDs and read handlers. */
export function RosterWorkspaceRail({ value, views, onSelect }: Props) {
    const available = sections.filter((section) =>
        section.views.some((view) => views.some((item) => item.key === view)),
    );
    return (
        <PageHeaderRail
            items={available}
            value={sectionByView[value]}
            ariaLabel="Rostering sections"
            onSelect={(key) => {
                if (key === sectionByView[value]) return;
                const next = available
                    .find((section) => section.key === key)
                    ?.views.find((view) => views.some((item) => item.key === view));
                if (next) onSelect(next);
            }}
        />
    );
}

/** A normal view chooser, with no misleading clear action or new URL vocabulary. */
export function RosterWorkspaceViewSelect({
    value,
    views,
    onSelect,
    onOpenConflicts,
    onOpenShiftList,
    onOpenQualifications,
}: Props & {
    onOpenConflicts?: () => void;
    onOpenShiftList?: () => void;
    onOpenQualifications?: () => void;
}) {
    const current = views.find((item) => item.key === value);
    const options = views.filter(
        (item) => sectionByView[item.key] === sectionByView[value],
    );
    const label = current?.label ?? 'Current view';
    return (
        <PageHeaderFilterSelect
            label={`View: ${label}`}
            value={value}
            allValue={value}
            options={[
                ...options.map((item) => ({
                    value: item.key,
                    label: `${item.label}${typeof item.count === 'number' ? ` · ${item.count}` : ''}`,
                })),
                ...(sectionByView[value] === 'cover' && onOpenConflicts
                    ? [{ value: 'conflicts', label: 'Conflict queue' }]
                    : []),
                ...(sectionByView[value] === 'cover' && onOpenQualifications
                    ? [{ value: 'qualifications', label: 'Qualifications' }]
                    : []),
                ...(sectionByView[value] === 'roster' && onOpenShiftList
                    ? [{ value: 'shift-list', label: 'Full shift list' }]
                    : []),
            ]}
            onChange={(next) => {
                if (next === 'conflicts' && sectionByView[value] === 'cover') {
                    onOpenConflicts?.();
                    return;
                }
                if (next === 'qualifications' && sectionByView[value] === 'cover') {
                    onOpenQualifications?.();
                    return;
                }
                if (next === 'shift-list' && sectionByView[value] === 'roster') {
                    onOpenShiftList?.();
                    return;
                }
                const view = options.find((item) => item.key === next);
                if (view && view.key !== value) onSelect(view.key);
            }}
        />
    );
}
