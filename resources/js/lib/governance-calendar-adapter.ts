import { router } from '@inertiajs/react';
import type { CalendarItem } from '@/lib/calendar/recur';
import type {
    CalendarDataAdapter,
    CalView,
    CreateSeed,
} from '@/pages/sites/calendar/SiteCalendar';
import type { Decorated, SourceDef } from '@/pages/sites/calendar/_parts';

export const GOVERNANCE_CALENDAR_SOURCES: SourceDef[] = [
    {
        key: 'meetings',
        label: 'Meetings',
        short: 'Meetings',
        group: 'manual',
        icon: 'CalendarDays',
        origin: 'Governance meetings',
    },
    {
        key: 'decisions',
        label: 'Decision deadlines',
        short: 'Decisions',
        group: 'auto',
        icon: 'Vote',
        origin: 'Board resolutions',
    },
    {
        key: 'obligations',
        label: 'Obligations',
        short: 'Obligations',
        group: 'auto',
        icon: 'ShieldCheck',
        origin: 'Compliance register',
    },
    {
        key: 'policies',
        label: 'Policy reviews',
        short: 'Policies',
        group: 'auto',
        icon: 'BookOpen',
        origin: 'Policy register',
    },
];

export interface GovernanceCalendarAdapterOptions {
    title?: string;
    subline?: string;
    initialSources?: string[];
    initialView?: CalView;
    sourceFilters?: SourceDef[];
    committeeOptions?: { value: string; label: string }[];
    onOpenItem?: (item: Decorated) => void;
    onCreate?: (seed: CreateSeed) => void;
}

export function createGovernanceCalendarAdapter(
    options?: GovernanceCalendarAdapterOptions,
): CalendarDataAdapter {
    return {
        title: options?.title ?? 'Governance calendar',
        subline:
            options?.subline ??
            'Board meetings, decisions, obligations, and policy reviews',
        allowSubscriptions: false,
        initialSources: options?.initialSources ?? [
            'meetings',
            'decisions',
            'obligations',
            'policies',
        ],
        initialView: options?.initialView ?? 'month',
        sourceFilters: options?.sourceFilters ?? GOVERNANCE_CALENDAR_SOURCES,
        committeeOptions: options?.committeeOptions,
        loadItems: async ({ start, end, signal, committeeId }) => {
            const params = new URLSearchParams({
                start: start.toISOString(),
                end: end.toISOString(),
            });
            if (committeeId != null) {
                params.set('committee_id', String(committeeId));
            }
            const res = await fetch(`/governance/calendar/items?${params}`, {
                signal,
                headers: { Accept: 'application/json' },
            });
            if (!res.ok) {
                const error = new Error('Failed to load governance calendar entries');
                (error as any).status = res.status;
                throw error;
            }
            const data = (await res.json()) as {
                events?: CalendarItem[];
                availability?: Record<string, string>;
                totals?: Record<string, number>;
            };
            return {
                events: data.events ?? [],
                availability: data.availability,
                totals: data.totals,
            };
        },
        onOpenItem: (item: Decorated) => {
            if (options?.onOpenItem) {
                options.onOpenItem(item);
                return;
            }
            if (item.link) {
                router.visit(item.link);
            }
        },
        onCreate: (seed: CreateSeed) => {
            if (options?.onCreate) {
                options.onCreate(seed);
                return;
            }
            const params = new URLSearchParams();
            if (seed?.date) {
                const d = seed.date instanceof Date ? seed.date : new Date(seed.date);
                params.set('date', d.toISOString().slice(0, 10));
            }
            if (seed?.hour !== undefined && seed?.hour !== null) params.set('hour', String(seed.hour));
            const query = params.toString();
            router.visit(query ? `/governance/meetings/create?${query}` : '/governance/meetings/create');
        },
    };
}
