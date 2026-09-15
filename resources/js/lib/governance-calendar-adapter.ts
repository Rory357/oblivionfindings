import { router } from '@inertiajs/react';
import type { CalendarItem } from '@/lib/calendar/recur';
import { toDateInput } from '@/lib/datetime';
import type {
    CalendarDataAdapter,
    CalView,
    CreateSeed,
} from '@/pages/sites/calendar/SiteCalendar';
import type { Decorated, SourceDef } from '@/pages/sites/calendar/_parts';

/**
 * The Governance calendar sources in plain words. Mirrors SOURCE_LABELS in
 * GovernanceCalendarController — the Governance calendar page receives these
 * from the server, already filtered to the registers the viewer can open.
 * Colours come from the `--src-meetings` / `--src-decisions` /
 * `--src-obligations` / `--src-policies` token triples in app.css.
 */
export const GOVERNANCE_CALENDAR_SOURCES: SourceDef[] = [
    {
        key: 'meetings',
        label: 'Meetings',
        short: 'Meetings',
        group: 'auto',
        icon: 'CalendarDays',
        origin: 'Meetings',
        note: 'Board or committee meeting',
    },
    {
        key: 'decisions',
        label: 'Voting deadlines',
        short: 'Voting',
        group: 'auto',
        icon: 'Vote',
        origin: 'Resolutions',
        note: 'When voting on a resolution closes',
    },
    {
        key: 'obligations',
        label: 'Requirements',
        short: 'Requirements',
        group: 'auto',
        icon: 'ShieldCheck',
        origin: 'Compliance',
        note: 'When a compliance requirement is due',
    },
    {
        key: 'policies',
        label: 'Policy reviews',
        short: 'Policies',
        group: 'auto',
        icon: 'BookOpen',
        origin: 'Policies',
        note: 'When a policy is due for review',
    },
];

export interface GovernanceCalendarAdapterOptions {
    title?: string;
    subline?: string;
    initialSources?: string[];
    initialView?: CalView;
    sourceFilters?: SourceDef[];
    committeeOptions?: { value: string; label: string }[];
    /** Optional glass back link in the calendar header. */
    backLink?: { href: string; label: string };
    onOpenItem?: (item: Decorated) => void;
    onCreate?: (seed: CreateSeed) => void;
}

/** Where "add" on a calendar day goes: the meeting wizard, seeded with that NZ date and hour. */
export function meetingCreateHref(seed: CreateSeed | null | undefined): string {
    const params = new URLSearchParams();
    if (seed?.date) {
        const date = toDateInput(seed.date);
        if (date) params.set('date', date);
    }
    if (seed?.hour !== undefined && seed?.hour !== null) {
        params.set('hour', String(seed.hour));
    }
    const query = params.toString();
    return query
        ? `/governance/meetings/create?${query}`
        : '/governance/meetings/create';
}

export function createGovernanceCalendarAdapter(
    options?: GovernanceCalendarAdapterOptions,
): CalendarDataAdapter {
    const sourceFilters = options?.sourceFilters ?? GOVERNANCE_CALENDAR_SOURCES;

    return {
        title: options?.title ?? 'Calendar',
        subline:
            options?.subline ??
            'Board meetings, voting deadlines, requirements and policy reviews',
        allowSubscriptions: false,
        // Governance entries never wait for calendar sign-off, so a
        // "To approve" meter would always read 0 — leave it out.
        showApprovalMeter: false,
        mineLink: { href: '/governance/my-work', label: 'Open My work' },
        backLink: options?.backLink,
        searchPlaceholder: 'Search meetings, resolutions, requirements…',
        exportFilename: 'governance-calendar.ics',
        initialSources:
            options?.initialSources ?? sourceFilters.map((source) => source.key),
        initialView: options?.initialView ?? 'month',
        sourceFilters,
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
                throw Object.assign(
                    new Error("The calendar couldn't be loaded."),
                    { status: res.status },
                );
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
            router.visit(meetingCreateHref(seed));
        },
    };
}
