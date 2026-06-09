/* Governance PageHero for Shift Notes: greeting + week range, status badges,
 * four stats, and a footer strip with the week navigator (left) and search +
 * searchable filters (right). Mirrors the Shift Handovers hero. */
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
    AlertTriangle,
    CalendarRange,
    CheckCircle2,
    ChevronDown,
    ChevronLeft,
    ChevronRight,
    Clock,
    Download,
    Home,
    NotebookPen,
    Plus,
    Search,
    ShieldAlert,
    Users,
} from 'lucide-react';
import { type ComponentProps, useRef, useState } from 'react';

import {
    type Catalogue,
    type Filters,
    NOTE_TYPES,
    type NoteType,
    TYPE_META,
    clientName,
} from './shared';

export type HeroCounts = {
    total: number;
    reviewed: number;
    flagged: number;
    gaps: number;
    awaiting: number;
    incidents: number;
    people: number;
    houses: number;
    staffOnRoster: number;
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

export function ShiftNotesHero({
    firstName,
    weekStart,
    counts,
    search,
    onSearch,
    filters,
    onFilters,
    catalogue,
    onAddNote,
    onWeekChange,
    onExport,
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
    onAddNote: () => void;
    onWeekChange: (week: Date) => void;
    onExport: () => void;
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

    const title = (
        <span>
            <span className="text-primary-foreground/80">
                Kia ora {firstName}, this week's shift notes —{' '}
            </span>
            <span className="underline decoration-primary-foreground/40 underline-offset-4">
                {fmtDay(weekStart)} → {fmtDay(range.end)}
            </span>
        </span>
    );

    const description =
        counts.total === 0
            ? 'No notes logged for this week yet. Add the first one, or check a different week.'
            : `${counts.total} note${counts.total === 1 ? '' : 's'} across ${counts.people} ${
                  counts.people === 1 ? 'person' : 'people'
              } in ${counts.houses} ${counts.houses === 1 ? 'house' : 'houses'}. ${counts.awaiting} awaiting review, ${counts.gaps} ${
                  counts.gaps === 1 ? 'shift' : 'shifts'
              } still undocumented.`;

    const meta = [
        {
            icon: CalendarRange,
            label: `Wk ${weekNumberISO(weekStart)} · Mon–Sun`,
        },
        {
            icon: Home,
            label: `${counts.houses} ${counts.houses === 1 ? 'house' : 'houses'}`,
        },
        { icon: Users, label: `${counts.staffOnRoster} staff on roster` },
    ];

    const badges: {
        icon: typeof Clock;
        tone: 'warning' | 'critical' | 'default' | 'success';
        label: string;
    }[] = [];
    if (counts.awaiting > 0)
        badges.push({
            icon: Clock,
            tone: 'default',
            label: `${counts.awaiting} awaiting review`,
        });
    if (counts.gaps > 0)
        badges.push({
            icon: AlertTriangle,
            tone: 'warning',
            label: `${counts.gaps} cover ${counts.gaps === 1 ? 'gap' : 'gaps'}`,
        });
    if (counts.incidents > 0)
        badges.push({
            icon: ShieldAlert,
            tone: 'critical',
            label: `${counts.incidents} incident${counts.incidents === 1 ? '' : 's'} noted`,
        });
    if (badges.length === 0)
        badges.push({
            icon: CheckCircle2,
            tone: 'default',
            label: 'All caught up',
        });

    const stats = [
        { label: 'Notes', value: counts.total },
        { label: 'Reviewed', value: counts.reviewed },
        {
            label: 'Gaps',
            value: counts.gaps,
            tone: (counts.gaps > 0 ? 'warning' : 'neutral') as
                | 'warning'
                | 'neutral',
        },
        {
            label: 'Flagged',
            value: counts.flagged,
            tone: (counts.flagged > 0 ? 'critical' : 'neutral') as
                | 'critical'
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
                        placeholder="Search notes, people, staff…"
                        aria-label="Search shift notes"
                        className="h-9 w-[210px] rounded-lg border border-primary-foreground/25 bg-primary-foreground/15 pr-3 pl-8 text-xs text-primary-foreground transition-all placeholder:text-primary-foreground/55 focus:w-[260px] focus:bg-primary-foreground/25 focus:outline-none"
                    />
                </div>
                <EntityFilter
                    onDark
                    label="Person"
                    allLabel="All people"
                    items={catalogue.clients.map((c) => ({
                        id: c.id,
                        name: clientName(c),
                    }))}
                    value={filters.client}
                    onChange={(v) => onFilters({ ...filters, client: v })}
                />
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
                <select
                    aria-label="Filter by note type"
                    value={filters.type ?? ''}
                    onChange={(e) =>
                        onFilters({
                            ...filters,
                            type: (e.target.value || null) as NoteType | null,
                        })
                    }
                    className="h-9 rounded-lg border border-primary-foreground/25 bg-primary-foreground/15 px-2.5 text-xs font-medium text-primary-foreground transition-colors hover:bg-primary-foreground/25 focus:outline-none [&>option]:text-foreground"
                >
                    <option value="">All types</option>
                    {NOTE_TYPES.map((t) => (
                        <option key={t} value={t}>
                            {TYPE_META[t].label}
                        </option>
                    ))}
                </select>
            </div>
        </div>
    );

    return (
        <PageHero
            icon={NotebookPen}
            title={title}
            description={description}
            meta={meta}
            badges={badges}
            stats={stats}
            actions={
                canCreate ? (
                    <div className="flex items-center gap-2">
                        <Button onClick={onAddNote}>
                            <Plus className="mr-1.5 h-4 w-4" />
                            Add shift note
                        </Button>
                        <button
                            type="button"
                            onClick={onExport}
                            aria-label="Export this week to CSV"
                            title="Export this week to CSV"
                            className="inline-flex h-9 w-9 items-center justify-center rounded-lg border border-primary-foreground/25 bg-primary-foreground/10 text-primary-foreground transition-colors hover:bg-primary-foreground/20"
                        >
                            <Download className="h-4 w-4" />
                        </button>
                    </div>
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
