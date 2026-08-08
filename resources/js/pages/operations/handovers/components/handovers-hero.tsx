/* Governance PageHero for Shift Handovers: greeting + week range, status badges,
 * four stats, and a footer strip with the week navigator (left) and search +
 * searchable filters (right). */
import { PageHero } from '@/components/page';
import {
    EntityFilter,
    WeekPicker,
    addDaysWP,
    formatWeekRange,
    weekNumberISO,
} from '@/components/rostering';
import { Button } from '@/components/ui/button';
import {
    ArrowLeftRight,
    CalendarRange,
    ChevronDown,
    ChevronLeft,
    ChevronRight,
    Clock,
    LayoutGrid,
    Plus,
    Search,
    ShieldAlert,
    Users,
} from 'lucide-react';
import { type ComponentProps, useRef, useState } from 'react';

import type { Catalogue, Filters } from './shared';
import { clientName } from './shared';

export type HeroCounts = {
    total: number;
    draft: number;
    submitted: number;
    acknowledged: number;
    openIncoming: number;
    incidents: number;
};

function WeekNavButton({ children, ...rest }: ComponentProps<'button'>) {
    return (
        <button
            type="button"
            {...rest}
            className="inline-flex items-center gap-1 rounded-lg border border-primary-foreground/20 bg-primary-foreground/10 px-2.5 py-1.5 text-xs font-medium text-primary-foreground/90 transition-colors hover:bg-primary-foreground/20"
        >
            {children}
        </button>
    );
}

export function HandoversHero({
    firstName,
    weekStart,
    counts,
    search,
    onSearch,
    filters,
    onFilters,
    catalogue,
    onNewHandover,
    onWeekChange,
    canCreate,
}: {
    firstName: string;
    weekStart: Date;
    counts: HeroCounts;
    search: string;
    onSearch: (value: string) => void;
    filters: Filters;
    onFilters: (next: Filters) => void;
    catalogue: Catalogue;
    onNewHandover: () => void;
    onWeekChange: (week: Date) => void;
    canCreate: boolean;
}) {
    const [pickerOpen, setPickerOpen] = useState(false);
    const weekBtnRef = useRef<HTMLButtonElement>(null);

    const range = formatWeekRange(weekStart);
    const prevWeek = addDaysWP(weekStart, -7);
    const nextWeek = addDaysWP(weekStart, 7);
    const fmtDay = (d: Date) =>
        d.toLocaleDateString('en-NZ', { day: 'numeric', month: 'short' });
    const rangeShort = `${fmtDay(weekStart)} – ${fmtDay(range.end)}`;
    const pending = counts.submitted;

    const title = (
        <span>
            <span className="text-primary-foreground/80">
                Kia ora {firstName}, this week's handovers —{' '}
            </span>
            <span className="underline decoration-primary-foreground/40 underline-offset-4">
                {fmtDay(weekStart)} → {fmtDay(range.end)}
            </span>
        </span>
    );

    const description = `${counts.total} handover${counts.total === 1 ? '' : 's'} across ${catalogue.sites.length} houses. ${
        pending > 0 ? `${pending} awaiting acknowledgement` : 'All caught up'
    }${counts.openIncoming > 0 ? `, ${counts.openIncoming} with an open incoming shift` : ''}.`;

    const meta = [
        {
            icon: CalendarRange,
            label: `Wk ${weekNumberISO(weekStart)} · Mon–Sun`,
        },
        { icon: LayoutGrid, label: `${catalogue.sites.length} houses` },
        { icon: Users, label: `${catalogue.staff.length} staff on roster` },
    ];

    const badges: {
        icon: typeof Clock;
        tone: 'warning' | 'critical' | 'default';
        label: string;
    }[] = [];
    if (pending > 0)
        badges.push({
            icon: Clock,
            tone: 'warning',
            label: `${pending} awaiting sign-off`,
        });
    if (counts.openIncoming > 0)
        badges.push({
            icon: ShieldAlert,
            tone: 'critical',
            label: `${counts.openIncoming} open incoming`,
        });
    if (counts.incidents > 0)
        badges.push({
            icon: ShieldAlert,
            tone: 'default',
            label: `${counts.incidents} incident${counts.incidents === 1 ? '' : 's'} noted`,
        });

    const stats = [
        { label: 'Total', value: counts.total },
        { label: 'Submitted', value: counts.submitted },
        { label: "Ack'd", value: counts.acknowledged },
        {
            label: 'Pending',
            value: pending,
            tone: (pending > 0 ? 'warning' : 'neutral') as
                | 'warning'
                | 'neutral',
        },
    ];

    const footer = (
        <div className="flex flex-col gap-3 py-3 lg:flex-row lg:items-center lg:justify-between">
            {/* Week navigator */}
            <div className="flex items-center gap-1.5">
                <WeekNavButton
                    onClick={() => onWeekChange(prevWeek)}
                    aria-label={`Previous week, week ${weekNumberISO(prevWeek)}`}
                >
                    <ChevronLeft className="h-3.5 w-3.5" />
                    Wk {weekNumberISO(prevWeek)}
                </WeekNavButton>
                {/* eslint-disable-next-line no-restricted-syntax -- segmented week-stepper on dark hero; not a shadcn Button. */}
                <button
                    ref={weekBtnRef}
                    type="button"
                    onClick={() => setPickerOpen((v) => !v)}
                    aria-haspopup="dialog"
                    aria-expanded={pickerOpen}
                    className="inline-flex items-center gap-1.5 rounded-lg border border-primary-foreground/25 bg-primary-foreground/15 px-3 py-1.5 text-xs font-semibold text-primary-foreground transition-colors hover:bg-primary-foreground/25"
                >
                    <CalendarRange className="h-3.5 w-3.5" />
                    Wk {weekNumberISO(weekStart)} · {rangeShort} · pick week
                    <ChevronDown className="h-3 w-3" />
                </button>
                <WeekNavButton
                    onClick={() => onWeekChange(nextWeek)}
                    aria-label={`Next week, week ${weekNumberISO(nextWeek)}`}
                >
                    Wk {weekNumberISO(nextWeek)}
                    <ChevronRight className="h-3.5 w-3.5" />
                </WeekNavButton>
                {pickerOpen ? (
                    <WeekPicker
                        selectedWeekStart={weekStart}
                        anchorRef={weekBtnRef}
                        onSelect={(d) => onWeekChange(d)}
                        onClose={() => setPickerOpen(false)}
                        showContextMenu={false}
                    />
                ) : null}
            </div>

            {/* Search + filters */}
            <div className="flex flex-wrap items-center gap-2">
                <div className="relative">
                    <Search className="pointer-events-none absolute top-1/2 left-2.5 h-3.5 w-3.5 -translate-y-1/2 text-primary-foreground/60" />
                    <input
                        value={search}
                        onChange={(e) => onSearch(e.target.value)}
                        placeholder="Search handovers, clients, staff…"
                        aria-label="Search handovers"
                        className="h-9 w-[210px] rounded-lg border border-primary-foreground/25 bg-primary-foreground/15 pr-3 pl-8 text-xs text-primary-foreground transition-all placeholder:text-primary-foreground/55 focus:w-[260px] focus:bg-primary-foreground/25 focus:outline-none"
                    />
                </div>
                <EntityFilter
                    onDark
                    label="Staff"
                    allLabel="All staff"
                    pluralLabel="staff"
                    items={catalogue.staff.map((s) => ({
                        id: s.id,
                        name: s.name,
                        description: s.email,
                    }))}
                    value={filters.staff}
                    onChange={(v) => onFilters({ ...filters, staff: v })}
                />
                <EntityFilter
                    onDark
                    label="Client"
                    allLabel="All clients"
                    items={catalogue.clients.map((c) => ({
                        id: c.id,
                        name: clientName(c),
                    }))}
                    value={filters.client}
                    onChange={(v) => onFilters({ ...filters, client: v })}
                />
                <EntityFilter
                    onDark
                    label="House"
                    allLabel="All houses"
                    items={catalogue.sites.map((s) => ({
                        id: s.id,
                        name: s.name,
                    }))}
                    value={filters.site}
                    onChange={(v) => onFilters({ ...filters, site: v })}
                />
            </div>
        </div>
    );

    return (
        <PageHero
            category="ops"
            icon={ArrowLeftRight}
            title={title}
            description={description}
            meta={meta}
            badges={badges}
            stats={stats}
            actions={
                canCreate ? (
                    <Button onClick={onNewHandover}>
                        <Plus className="mr-1.5 h-4 w-4" />
                        New handover
                    </Button>
                ) : undefined
            }
            footer={footer}
        >
            <div className="inline-flex items-center gap-1.5 text-[11px] font-semibold tracking-wide text-primary-foreground/70 uppercase">
                <span className="relative flex h-2 w-2">
                    <span className="absolute inline-flex h-full w-full animate-ping rounded-full bg-status-success opacity-70 motion-reduce:animate-none" />
                    <span className="relative inline-flex h-2 w-2 rounded-full bg-status-success" />
                </span>
                Live · synced just now
            </div>
        </PageHero>
    );
}
