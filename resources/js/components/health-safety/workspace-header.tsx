import {
    PageHeaderFilterButton,
    PageHeaderRail,
    PageHeaderSearch,
} from '@/components/page';
import { Button } from '@/components/ui/button';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { formatDateOnly, workerDayRange } from '@/lib/datetime';
import {
    SAFETY_HUBS,
    visibleSafetyViews,
    type SafetyHubKey,
    type SafetyNavigationPermissions,
} from '@/lib/health-safety/workspace-navigation';
import { type SharedData } from '@/types';
import { router, usePage } from '@inertiajs/react';
import axios from 'axios';
import { CalendarDays } from 'lucide-react';
import { useEffect, useState } from 'react';

/**
 * G2 (B5.2/K9): the Incidents and safety reviews rail shows each tab's attention count (incidents needing an owner,
 * reviews needing an owner, overdue actions, WorkSafe to decide or tell), read on the server like the Overview's boxes.
 * A count the person can't read comes back null and the tab shows none. Pages that pass their own counts keep them.
 */
function useConcernsRailCounts(enabled: boolean): Partial<Record<string, number>> | undefined {
    const [counts, setCounts] = useState<Partial<Record<string, number>>>();
    useEffect(() => {
        if (!enabled) return;
        let live = true;
        axios
            .get<Record<string, number | null>>('/health-safety/rail-counts')
            .then(({ data }) => {
                if (!live) return;
                setCounts(Object.fromEntries(Object.entries(data).filter((entry): entry is [string, number] => typeof entry[1] === 'number')));
            })
            .catch(() => undefined); // No counts is a quiet state: the tabs still work.
        return () => {
            live = false;
        };
    }, [enabled]);
    return counts;
}

/** B5.4: Site inspections stay a Sites page; Risks and prevention links there (↗, folded under More) for people who can open them. */
const INSPECTIONS_LINK = 'inspections-link';

/** Sibling destinations live on the header rail, status lenses in its filters. */
export function SafetyWorkspaceRail({
    hub,
    current,
    counts,
    onSelect,
}: {
    hub: SafetyHubKey;
    current: string;
    /** Optional counts per view key (shown on the tab). */
    counts?: Partial<Record<string, number>>;
    /**
     * Optional: a page whose views share one address (Worker participation) switches itself, keeping its filters.
     * Return false to fall back to the view's own address.
     */
    onSelect?: (key: string) => boolean;
}) {
    const can = usePage<SharedData>().props.auth.can;
    const fetched = useConcernsRailCounts(hub === 'concerns' && counts === undefined);
    // Fetched counts are attention counts (work waiting), so they take the alert pair; a page's own counts stay neutral.
    const attention = counts === undefined && fetched !== undefined;
    counts = counts ?? fetched;
    const group = SAFETY_HUBS.find((item) => item.key === hub)!;
    const views = visibleSafetyViews(group, can).filter(
        (view) => !view.shortcut,
    );
    const inspections =
        hub === 'prevention' &&
        !!(can as SafetyNavigationPermissions | undefined)?.checklists?.view &&
        !!(can as SafetyNavigationPermissions | undefined)?.sites?.viewAny;
    return (
        <PageHeaderRail
            items={[
                ...views.map((view) => ({
                    ...view,
                    count: counts?.[view.key] || undefined,
                    alert: attention && !!counts?.[view.key],
                })),
                ...(inspections
                    ? [
                          {
                              key: INSPECTIONS_LINK,
                              label: 'Inspections ↗',
                              overflow: {
                                  description:
                                      'Opens Sites: each site keeps its own inspections',
                              },
                          },
                      ]
                    : []),
            ]}
            value={current}
            ariaLabel={`${group.label} views`}
            showFind={views.length > 2}
            maxVisible={inspections ? views.length : undefined}
            onSelect={(key) => {
                if (key === INSPECTIONS_LINK) {
                    router.visit('/sites');
                    return;
                }
                if (onSelect?.(key)) return;
                const view = views.find((view) => view.key === key);
                if (view) router.visit(view.href);
            }}
        />
    );
}

export function SafetyRegisterSearch({
    query,
    onSearch,
    placeholder,
}: {
    query: string;
    onSearch: (query: string) => void;
    placeholder: string;
}) {
    const [value, setValue] = useState(query);
    return (
        <PageHeaderSearch
            value={value}
            onChange={setValue}
            placeholder={placeholder}
            onKeyDown={(event) => {
                if (event.key === 'Enter') onSearch(value.trim());
            }}
        />
    );
}

export function SafetyDateFilter({
    from,
    to,
    onChange,
    label = 'Period',
}: {
    from: string | null;
    to: string | null;
    label?: string;
    onChange: (range: { from: string | null; to: string | null }) => void;
}) {
    const [open, setOpen] = useState(false);
    const [start, setStart] = useState(from ?? '');
    const [end, setEnd] = useState(to ?? '');
    const invalid = !!(start && end && start > end);
    const apply = (range: { from: string | null; to: string | null }) => {
        setOpen(false);
        onChange(range);
    };
    return (
        <Popover
            open={open}
            onOpenChange={(next) => {
                if (next) {
                    setStart(from ?? '');
                    setEnd(to ?? '');
                }
                setOpen(next);
            }}
        >
            <PopoverTrigger asChild>
                <PageHeaderFilterButton
                    icon={CalendarDays}
                    active={!!(from || to)}
                >
                    {from || to
                        ? `${label}: ${from ? formatDateOnly(from) : 'Any start'} – ${to ? formatDateOnly(to) : 'Any end'}`
                        : `${label}: all dates`}
                </PageHeaderFilterButton>
            </PopoverTrigger>
            <PopoverContent
                align="start"
                className="w-80 max-w-[calc(100vw-2rem)] space-y-4"
            >
                <div className="flex flex-wrap gap-2">
                    <Button
                        className="frontline-tap"
                        variant="outline"
                        size="sm"
                        onClick={() => apply({ from: null, to: null })}
                    >
                        All dates
                    </Button>
                    {[7, 30, 90].map((days) => (
                        <Button
                            key={days}
                            className="frontline-tap"
                            variant="outline"
                            size="sm"
                            onClick={() => apply(workerDayRange(days))}
                        >
                            {days} days
                        </Button>
                    ))}
                </div>
                <label className="block text-sm font-medium">
                    From (NZ date)
                    <input
                        type="date"
                        value={start}
                        max={end || undefined}
                        onChange={(event) => setStart(event.target.value)}
                        className="mt-1 block h-11 w-full rounded-md border border-input bg-background px-3 text-sm focus-visible:ring-2 focus-visible:ring-ring"
                    />
                </label>
                <label className="block text-sm font-medium">
                    To (NZ date)
                    <input
                        type="date"
                        value={end}
                        min={start || undefined}
                        onChange={(event) => setEnd(event.target.value)}
                        className="mt-1 block h-11 w-full rounded-md border border-input bg-background px-3 text-sm focus-visible:ring-2 focus-visible:ring-ring"
                    />
                </label>
                {invalid && (
                    <p role="alert" className="text-sm text-destructive">
                        The end date must be on or after the start date.
                    </p>
                )}
                <Button
                    className="frontline-tap"
                    disabled={invalid}
                    onClick={() =>
                        apply({ from: start || null, to: end || null })
                    }
                >
                    Apply range
                </Button>
            </PopoverContent>
        </Popover>
    );
}
