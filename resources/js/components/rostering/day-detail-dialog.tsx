import * as VisuallyHidden from '@radix-ui/react-visually-hidden';
import {
    AlertTriangle,
    CalendarClock,
    Check,
    ChevronLeft,
    ChevronRight,
    Clock,
    Edit3,
    List,
    Moon,
    MoreVertical,
    Play,
    Plus,
    Repeat,
    Users,
    X,
} from 'lucide-react';
import type { ComponentType } from 'react';
import { useEffect, useRef, useState } from 'react';

import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import { cn } from '@/lib/utils';

import { avatarHueStyle } from './avatar-hue';
import {
    CAL_MONTHS,
    type CalendarGap,
    type CalendarShift,
    calendarStatusMeta,
    calHashHue,
    calInitials,
    fmtHours,
    parseDateKey,
} from './calendar-shared';

/**
 * Day Detail Dialog — review and manage every shift on one day without
 * leaving the Rostering calendar. Uses the same wizard chrome as the shared
 * CreateShiftDialog (rail + thin header + progress strip + muted footer); the
 * rail's "steps" are status filters. Edits open the shared CreateShiftDialog
 * layered above this dialog; row peeks / context menus portal above it too.
 */

type FilterKey = 'all' | 'open' | 'live' | 'scheduled' | 'completed' | 'cancelled';

const FILTERS: Array<{
    key: FilterKey;
    label: string;
    blurb: string;
    icon: ComponentType<{ className?: string }>;
    fg: string;
    bg: string;
    match: (s: CalendarShift) => boolean;
}> = [
    {
        key: 'all',
        label: 'All shifts',
        blurb: 'Everything on this day',
        icon: List,
        fg: 'var(--muted-foreground)',
        bg: 'var(--muted)',
        match: () => true,
    },
    {
        key: 'open',
        label: 'Open',
        blurb: 'Unassigned · needs cover',
        icon: AlertTriangle,
        fg: 'var(--status-critical)',
        bg: 'var(--status-critical-bg)',
        match: (s) => s.status === 'open',
    },
    {
        key: 'live',
        label: 'In progress',
        blurb: 'Running right now',
        icon: Play,
        fg: 'var(--live)',
        bg: 'var(--live-bg)',
        match: (s) => s.status === 'in_progress',
    },
    {
        key: 'scheduled',
        label: 'Scheduled',
        blurb: 'Assigned & drafts',
        icon: Clock,
        fg: 'var(--primary)',
        bg: 'var(--accent)',
        match: (s) => s.status === 'scheduled' || s.status === 'draft',
    },
    {
        key: 'completed',
        label: 'Completed',
        blurb: 'Finished shifts',
        icon: Check,
        fg: 'var(--status-success)',
        bg: 'var(--status-success-bg)',
        match: (s) => s.status === 'completed',
    },
    {
        key: 'cancelled',
        label: 'Cancelled',
        blurb: 'Cancelled occurrences',
        icon: X,
        fg: 'var(--status-neutral)',
        bg: 'var(--muted)',
        match: (s) => s.status === 'cancelled',
    },
];

const PERIODS: Array<{
    key: string;
    label: string;
    sub: string;
    icon: ComponentType<{ className?: string }>;
    test: (hour: number) => boolean;
}> = [
    {
        key: 'morning',
        label: 'Morning',
        sub: '05:00 – 12:00',
        icon: Clock,
        test: (h) => h >= 5 && h < 12,
    },
    {
        key: 'afternoon',
        label: 'Afternoon',
        sub: '12:00 – 17:00',
        icon: Clock,
        test: (h) => h >= 12 && h < 17,
    },
    {
        key: 'evening',
        label: 'Evening',
        sub: '17:00 – 22:00',
        icon: Clock,
        test: (h) => h >= 17 && h < 22,
    },
    {
        key: 'overnight',
        label: 'Overnight',
        sub: '22:00 – 05:00',
        icon: Moon,
        test: (h) => h >= 22 || h < 5,
    },
];

const EMPTY_COPY: Record<FilterKey, [string, string]> = {
    all: [
        'No shifts on this day',
        'This day has nothing rostered yet. Create a shift to start covering it.',
    ],
    open: [
        'No open shifts',
        'Every shift on this day has someone assigned. Nice work.',
    ],
    live: [
        'Nothing in progress',
        'No shifts are currently running on this day.',
    ],
    scheduled: [
        'Nothing scheduled',
        'No upcoming assigned or draft shifts on this day.',
    ],
    completed: [
        'Nothing completed yet',
        'Completed shifts will appear here once they end.',
    ],
    cancelled: ['No cancellations', 'No shifts were cancelled on this day.'],
};

export type DayDetailDialogProps = {
    open: boolean;
    dayKey: string;
    todayKey: string;
    shifts: CalendarShift[];
    gaps: CalendarGap[];
    /** A peek popover or context menu is open above the dialog — suspend
     *  Esc/outside-close and the ←/→ day navigation while it is. */
    popoverOpen: boolean;
    canManage: boolean;
    canCreate: boolean;
    onClose: () => void;
    onNav: (delta: number) => void;
    onJumpToday: () => void;
    onPeek: (
        s: CalendarShift,
        rect: { top: number; bottom: number; left: number; right: number },
    ) => void;
    onCtx: (s: CalendarShift, x: number, y: number) => void;
    onAssign?: (s: CalendarShift) => void;
    onEdit?: (s: CalendarShift) => void;
    onNewShift: () => void;
    onFillGap: (gap: CalendarGap) => void;
};

function DayShiftRow({
    s,
    canManage,
    onPeek,
    onCtx,
    onAssign,
    onEdit,
}: {
    s: CalendarShift;
    canManage: boolean;
    onPeek: DayDetailDialogProps['onPeek'];
    onCtx: DayDetailDialogProps['onCtx'];
    onAssign?: (s: CalendarShift) => void;
    onEdit?: (s: CalendarShift) => void;
}) {
    const ref = useRef<HTMLDivElement | null>(null);
    const m = calendarStatusMeta(s);
    const rect = () => ref.current!.getBoundingClientRect();
    const unstaffedLabel = s.status === 'draft' ? 'Draft — no staff' : 'Unassigned';
    const unstaffedSub = s.status === 'draft' ? 'Not yet published' : 'Needs cover';
    const subLine = [s.siteName, s.context].filter(Boolean).join(' · ');

    return (
        <div
            ref={ref}
            role="button"
            tabIndex={0}
            aria-label={`${s.client ?? 'Shift'} ${s.start} to ${s.end}, ${m.label}`}
            className={cn(
                'grid cursor-pointer grid-cols-[78px_minmax(0,1.3fr)_minmax(0,1fr)_auto] items-center gap-3 rounded-xl border border-border bg-card px-3.5 py-3 shadow-xs transition-all hover:z-[1] hover:-translate-y-px hover:shadow-md min-[880px]:grid-cols-[78px_minmax(0,1.3fr)_minmax(0,1fr)_auto_auto]',
                m.muted && 'opacity-65',
            )}
            style={{
                borderLeftWidth: 3,
                borderLeftStyle: m.dashed ? 'dashed' : 'solid',
                borderLeftColor: m.accent,
            }}
            onClick={() => onPeek(s, rect())}
            onKeyDown={(e) => {
                if (e.key === 'Enter') onPeek(s, rect());
            }}
            onContextMenu={(e) => {
                e.preventDefault();
                e.stopPropagation();
                onCtx(s, e.clientX, e.clientY);
            }}
        >
            <div className="flex flex-col gap-px">
                <span className="text-[14.5px] font-bold tracking-tight tabular-nums">
                    {s.start}
                </span>
                <span className="text-[11px] text-muted-foreground tabular-nums">
                    – {s.end}
                </span>
                <span className="text-[10.5px] font-semibold text-muted-foreground/80">
                    {fmtHours(s.durationH)}
                </span>
            </div>

            <div className="flex min-w-0 items-center gap-2.5">
                <span
                    className="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-[11px] text-xs font-extrabold"
                    style={avatarHueStyle(calHashHue(s.client ?? 'Shift'))}
                >
                    {calInitials(s.client ?? 'S')}
                </span>
                <span className="flex min-w-0 flex-col gap-px">
                    <span
                        className={cn(
                            'inline-flex min-w-0 items-center gap-1.5 truncate text-[13.5px] font-bold tracking-tight',
                            m.muted && 'line-through',
                        )}
                    >
                        <span className="truncate">{s.client ?? 'Shift'}</span>
                        {s.recurring ? (
                            <Repeat className="h-[11px] w-[11px] shrink-0 text-muted-foreground" />
                        ) : null}
                    </span>
                    <span className="truncate text-[11.5px] text-muted-foreground">
                        {subLine ? `${subLine} · ` : ''}
                        <span className="capitalize">{s.shiftType}</span>
                    </span>
                </span>
            </div>

            <div className="flex min-w-0 items-center gap-2">
                {s.staff ? (
                    <>
                        <span
                            className="inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-full text-[10px] font-extrabold"
                            style={avatarHueStyle(calHashHue(s.staff))}
                        >
                            {calInitials(s.staff)}
                        </span>
                        <span className="min-w-0 truncate text-[12.5px] font-semibold">
                            {s.staff}
                        </span>
                    </>
                ) : (
                    <>
                        <span
                            className={cn(
                                'inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-full border-[1.5px] border-dashed',
                                s.status === 'draft'
                                    ? 'border-border text-muted-foreground'
                                    : 'border-status-critical/55 text-status-critical',
                            )}
                        >
                            <Users className="h-[13px] w-[13px]" />
                        </span>
                        <span className="flex min-w-0 flex-col">
                            <span
                                className={cn(
                                    'truncate text-[12.5px] font-semibold',
                                    s.status !== 'draft' && 'text-status-critical',
                                )}
                            >
                                {unstaffedLabel}
                            </span>
                            <span className="truncate text-[10.5px] text-muted-foreground">
                                {unstaffedSub}
                            </span>
                        </span>
                    </>
                )}
            </div>

            <div className="hidden items-center justify-end gap-1.5 min-[880px]:flex">
                <span
                    className="inline-flex items-center gap-1.5 rounded-full px-2 py-[3px] text-[10.5px] font-bold whitespace-nowrap"
                    style={{ background: m.tint, color: m.accent }}
                >
                    {m.live ? (
                        <span
                            className="h-1.5 w-1.5 animate-pulse rounded-full"
                            style={{ background: m.accent }}
                        />
                    ) : null}
                    {m.label}
                </span>
                {s.conflict ? (
                    <span className="rounded bg-status-critical-bg px-1 py-px text-[8.5px] font-extrabold tracking-wider text-status-critical">
                        CLASH
                    </span>
                ) : null}
                {s.incidents > 0 ? (
                    <span className="rounded bg-status-critical-bg px-1 py-px text-[8.5px] font-extrabold tracking-wider text-status-critical">
                        {s.incidents} INC
                    </span>
                ) : null}
                {s.replacement ? (
                    <span className="rounded bg-status-warning-bg px-1 py-px text-[8.5px] font-extrabold tracking-wider text-status-warning">
                        REPL
                    </span>
                ) : null}
            </div>

            {/* Stops row-click bubbling for the action cluster only. */}
            <div
                className="flex items-center gap-1"
                onClick={(e) => e.stopPropagation()}
            >
                {s.status === 'open' && canManage && onAssign ? (
                    <Button
                        size="sm"
                        className="h-[30px] rounded-[9px] px-2.5 text-xs"
                        onClick={() => onAssign(s)}
                    >
                        <Plus className="mr-1 h-[13px] w-[13px]" /> Assign
                    </Button>
                ) : canManage && onEdit && s.status !== 'cancelled' ? (
                    <Button
                        size="sm"
                        variant="outline"
                        className="h-[30px] rounded-[9px] px-2.5 text-xs"
                        onClick={() => onEdit(s)}
                    >
                        <Edit3 className="mr-1 h-[13px] w-[13px]" /> Edit
                    </Button>
                ) : (
                    <Button
                        size="sm"
                        variant="outline"
                        className="h-[30px] rounded-[9px] px-2.5 text-xs"
                        onClick={() => {
                            window.location.href = `/operations/shifts/${s.id}`;
                        }}
                    >
                        Open
                    </Button>
                )}
                {/* eslint-disable-next-line no-restricted-syntax -- 30px kebab matching the row action sizing. */}
                <button
                    type="button"
                    aria-label="More actions"
                    onClick={(e) => {
                        const r = e.currentTarget.getBoundingClientRect();
                        onCtx(s, r.right - 280, r.bottom + 6);
                    }}
                    className="inline-flex h-[30px] w-[30px] items-center justify-center rounded-[9px] text-muted-foreground transition-colors hover:bg-accent hover:text-foreground"
                >
                    <MoreVertical className="h-4 w-4" />
                </button>
            </div>
        </div>
    );
}

export function DayDetailDialog({
    open,
    dayKey,
    todayKey,
    shifts,
    gaps,
    popoverOpen,
    canManage,
    canCreate,
    onClose,
    onNav,
    onJumpToday,
    onPeek,
    onCtx,
    onAssign,
    onEdit,
    onNewShift,
    onFillGap,
}: DayDetailDialogProps) {
    const [filter, setFilter] = useState<FilterKey>('all');
    const contentRef = useRef<HTMLDivElement | null>(null);

    // Fresh filter each time the dialog opens (day-to-day nav keeps it).
    useEffect(() => {
        if (open) setFilter('all');
    }, [open]);

    // ←/→ day navigation. Document-level because focus regularly lands on
    // <body> (e.g. after the layered CreateShiftDialog closes), where a
    // keydown handler on the dialog content would never hear it. Suspended
    // while a peek/context-menu is up, while focus sits in a dialog layered
    // above this one, or while a form control has focus.
    useEffect(() => {
        if (!open) return;
        const onKey = (e: KeyboardEvent) => {
            if (popoverOpen) return;
            if (e.key !== 'ArrowLeft' && e.key !== 'ArrowRight') return;
            const el = contentRef.current;
            if (!el) return;
            const active = document.activeElement;
            if (
                active &&
                active !== document.body &&
                !el.contains(active)
            ) {
                return;
            }
            if (
                active &&
                ['INPUT', 'SELECT', 'TEXTAREA'].includes(active.tagName)
            ) {
                return;
            }
            e.preventDefault();
            onNav(e.key === 'ArrowLeft' ? -1 : 1);
        };
        document.addEventListener('keydown', onKey);
        return () => document.removeEventListener('keydown', onKey);
    }, [open, popoverOpen, onNav]);

    const d = parseDateKey(dayKey);
    const isToday = dayKey === todayKey;

    const active = shifts.filter((s) => s.status !== 'cancelled');
    const hours = active.reduce((a, s) => a + s.durationH, 0);
    const openCount = shifts.filter((s) => s.status === 'open').length;
    const filled = active.filter((s) => s.staff).length;
    const coverage =
        filled + openCount > 0
            ? Math.round((filled / (filled + openCount)) * 100)
            : 100;
    const meterColor =
        openCount > 0 ? 'var(--status-critical)' : 'var(--primary)';

    const activeFilter = FILTERS.find((f) => f.key === filter) ?? FILTERS[0];
    const visible = shifts.filter(activeFilter.match);
    const groups = PERIODS.map((p) => ({
        ...p,
        items: visible.filter((s) => p.test(parseInt(s.start, 10))),
    })).filter((g) => g.items.length > 0);

    const weekday = d.toLocaleDateString('en-NZ', { weekday: 'long' });
    const dateLabel = `${weekday} ${d.getDate()} ${CAL_MONTHS[d.getMonth()]} ${d.getFullYear()}`;
    const empty = EMPTY_COPY[filter];

    return (
        <Dialog open={open} onOpenChange={(o) => (!o ? onClose() : null)}>
            <DialogContent
                ref={contentRef}
                className="flex h-[min(820px,92vh)] !w-full !max-w-[min(96vw,1080px)] flex-col gap-0 overflow-hidden !rounded-2xl !p-0 md:flex-row [&>button]:hidden"
                onEscapeKeyDown={(e) => {
                    if (popoverOpen) e.preventDefault();
                }}
                onInteractOutside={(e) => {
                    if (popoverOpen) e.preventDefault();
                }}
            >
                <VisuallyHidden.Root>
                    <DialogTitle>Shifts on {dateLabel}</DialogTitle>
                    <DialogDescription>
                        Review, filter and manage every shift rostered on this
                        day. Use the left and right arrow keys to move between
                        days.
                    </DialogDescription>
                </VisuallyHidden.Root>

                {/* ── rail: date nav + status filters ── */}
                <aside className="hidden w-[248px] shrink-0 flex-col gap-1 overflow-y-auto border-r border-sidebar-border bg-sidebar p-4 md:flex">
                    <div className="mb-2 flex items-center gap-2.5">
                        <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-primary/15 text-primary">
                            <CalendarClock className="h-4.5 w-4.5" />
                        </span>
                        <div className="min-w-0">
                            <h2 className="text-sm font-bold">Day roster</h2>
                            <div className="truncate text-[11.5px] text-muted-foreground">
                                {d.getDate()}{' '}
                                {CAL_MONTHS[d.getMonth()].slice(0, 3)}{' '}
                                {d.getFullYear()}
                                {isToday ? ' · Today' : ''}
                            </div>
                        </div>
                    </div>

                    <div className="mb-2 grid grid-cols-[32px_1fr_32px] gap-1">
                        {/* eslint-disable-next-line no-restricted-syntax -- compact rail day-stepper per design. */}
                        <button
                            type="button"
                            aria-label="Previous day"
                            onClick={() => onNav(-1)}
                            className="inline-flex h-[30px] items-center justify-center rounded-lg border border-border bg-card text-muted-foreground transition-colors hover:text-foreground"
                        >
                            <ChevronLeft className="h-[15px] w-[15px]" />
                        </button>
                        {/* eslint-disable-next-line no-restricted-syntax -- compact rail day-stepper per design. */}
                        <button
                            type="button"
                            onClick={onJumpToday}
                            className="h-[30px] rounded-lg border border-border bg-card text-xs font-semibold transition-colors hover:bg-secondary"
                        >
                            Today
                        </button>
                        {/* eslint-disable-next-line no-restricted-syntax -- compact rail day-stepper per design. */}
                        <button
                            type="button"
                            aria-label="Next day"
                            onClick={() => onNav(1)}
                            className="inline-flex h-[30px] items-center justify-center rounded-lg border border-border bg-card text-muted-foreground transition-colors hover:text-foreground"
                        >
                            <ChevronRight className="h-[15px] w-[15px]" />
                        </button>
                    </div>

                    {FILTERS.map((f) => {
                        const n = shifts.filter(f.match).length;
                        if (f.key !== 'all' && n === 0) return null;
                        const isActive = filter === f.key;
                        const Icon = f.icon;
                        return (
                            // eslint-disable-next-line no-restricted-syntax -- wizard-rail step row repurposed as a status filter.
                            <button
                                key={f.key}
                                type="button"
                                aria-pressed={isActive}
                                onClick={() => setFilter(f.key)}
                                className={cn(
                                    'flex w-full items-start gap-2.5 rounded-[10px] px-2.5 py-2 text-left transition-colors',
                                    isActive
                                        ? 'bg-primary/10'
                                        : 'hover:bg-sidebar-accent',
                                )}
                            >
                                <span
                                    className={cn(
                                        'flex h-6 w-6 shrink-0 items-center justify-center rounded-full',
                                        isActive &&
                                            'bg-primary text-primary-foreground',
                                    )}
                                    style={
                                        isActive
                                            ? undefined
                                            : { background: f.bg, color: f.fg }
                                    }
                                >
                                    <Icon className="h-3 w-3" />
                                </span>
                                <span className="min-w-0 flex-1">
                                    <span
                                        className={cn(
                                            'block text-[13px] leading-tight font-semibold',
                                            isActive && 'font-bold',
                                        )}
                                    >
                                        {f.label}
                                    </span>
                                    <span className="block truncate text-[11px] text-muted-foreground">
                                        {f.blurb}
                                    </span>
                                </span>
                                <span
                                    className={cn(
                                        'shrink-0 self-center rounded-full px-1.5 py-px text-[10.5px] font-bold tabular-nums',
                                        isActive
                                            ? 'bg-primary text-primary-foreground'
                                            : 'bg-muted text-muted-foreground',
                                    )}
                                >
                                    {n}
                                </span>
                            </button>
                        );
                    })}

                    {/* eslint-disable-next-line no-restricted-syntax -- wizard-rail coverage meter card matching CreateShiftDialog's readiness card. */}
                    <div className="mt-auto rounded-lg border border-border bg-card p-3">
                        <div className="flex items-center justify-between text-[11.5px] font-semibold">
                            <span>Coverage</span>
                            <b
                                className="tabular-nums"
                                style={{ color: meterColor }}
                            >
                                {coverage}%
                            </b>
                        </div>
                        <div className="mt-1.5 h-1.5 overflow-hidden rounded-full bg-muted">
                            <div
                                className="h-full rounded-full transition-all"
                                style={{
                                    width: `${coverage}%`,
                                    background: meterColor,
                                }}
                            />
                        </div>
                        <div className="mt-1.5 text-[10.5px] text-muted-foreground">
                            {openCount > 0
                                ? `${openCount} open shift${openCount > 1 ? 's' : ''} need${openCount === 1 ? 's' : ''} cover`
                                : active.length > 0
                                  ? 'Fully covered'
                                  : 'Nothing rostered'}
                        </div>
                    </div>
                </aside>

                {/* ── main column ── */}
                <div className="flex min-w-0 flex-1 flex-col">
                    <header className="flex items-center justify-between gap-3 border-b border-border px-5 py-3">
                        <div className="min-w-0 truncate text-[12.5px] text-muted-foreground">
                            <b className="text-foreground">{dateLabel}</b> ·{' '}
                            {active.length} shift{active.length === 1 ? '' : 's'}{' '}
                            · {fmtHours(hours)} planned
                        </div>
                        <div className="flex shrink-0 items-center gap-2">
                            <span className="hidden items-center gap-1 rounded-md border border-border px-1.5 py-1 text-[10.5px] text-muted-foreground sm:inline-flex">
                                <kbd className="font-sans font-semibold">←</kbd>
                                <kbd className="font-sans font-semibold">→</kbd>
                                <span>day</span>
                            </span>
                            {/* eslint-disable-next-line no-restricted-syntax -- thin-header close affordance matching CreateShiftDialog. */}
                            <button
                                type="button"
                                aria-label="Close dialog"
                                onClick={onClose}
                                className="rounded-md p-1 text-muted-foreground hover:bg-accent hover:text-foreground"
                            >
                                <X className="h-4.5 w-4.5" />
                            </button>
                        </div>
                    </header>
                    <div className="h-[3px] shrink-0 bg-muted">
                        <div
                            className="h-full transition-all"
                            style={{
                                width: `${coverage}%`,
                                background: meterColor,
                            }}
                        />
                    </div>

                    <div className="min-h-0 flex-1 overflow-y-auto bg-background px-6 py-4">
                        {gaps.length > 0 ? (
                            // eslint-disable-next-line no-restricted-syntax -- inline coverage-gap callout panel, not a Card.
                            <div className="mb-4 rounded-xl border border-status-critical/25 bg-status-critical-bg/50 p-3">
                                <div className="flex items-center gap-2 text-[12.5px] font-bold text-status-critical">
                                    <AlertTriangle className="h-3.5 w-3.5 shrink-0" />
                                    Site coverage {gaps.length === 1 ? 'gap' : 'gaps'} on this day
                                </div>
                                <div className="mt-2 flex flex-col gap-1.5">
                                    {gaps.map((g) => (
                                        // eslint-disable-next-line no-restricted-syntax -- compact gap-window row inside the callout, not a Card.
                                        <div
                                            key={g.key}
                                            className="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-border bg-card px-2.5 py-2"
                                        >
                                            <span className="min-w-0 text-xs">
                                                <span className="font-semibold">
                                                    {g.siteName ?? 'Site'}
                                                </span>{' '}
                                                <span className="text-muted-foreground">
                                                    {g.windowLabel
                                                        ? `· ${g.windowLabel} `
                                                        : ''}
                                                    · missing {g.missingStaff}{' '}
                                                    staff
                                                </span>
                                            </span>
                                            {canCreate ? (
                                                <Button
                                                    size="sm"
                                                    variant="outline"
                                                    className="h-7 px-2 text-[11px]"
                                                    onClick={() => onFillGap(g)}
                                                >
                                                    <Plus className="mr-1 h-3 w-3" />
                                                    Create cover
                                                </Button>
                                            ) : null}
                                        </div>
                                    ))}
                                </div>
                            </div>
                        ) : null}

                        {visible.length === 0 ? (
                            <div className="flex flex-col items-center gap-2 rounded-[13px] border border-dashed border-border bg-card px-5 py-11 text-center text-muted-foreground">
                                <Clock className="h-6 w-6" />
                                <strong className="text-sm text-foreground">
                                    {empty[0]}
                                </strong>
                                <span className="max-w-[360px] text-[12.5px]">
                                    {empty[1]}
                                </span>
                                {filter === 'all' && canCreate ? (
                                    <Button
                                        size="sm"
                                        className="mt-1.5"
                                        onClick={onNewShift}
                                    >
                                        <Plus className="mr-1 h-[13px] w-[13px]" />{' '}
                                        New shift
                                    </Button>
                                ) : null}
                            </div>
                        ) : (
                            groups.map((g, gi) => {
                                const Icon = g.icon;
                                return (
                                    <section
                                        key={g.key}
                                        className={cn(
                                            gi > 0 &&
                                                'mt-4 border-t border-border pt-4',
                                        )}
                                    >
                                        <div className="mb-3 flex items-baseline justify-between gap-3">
                                            <div className="flex min-w-0 items-center gap-2">
                                                <Icon className="h-[15px] w-[15px] shrink-0 self-center text-primary" />
                                                <span className="text-sm font-semibold">
                                                    {g.label}
                                                </span>
                                                <span className="truncate text-xs text-muted-foreground">
                                                    · {g.sub}
                                                </span>
                                            </div>
                                            <span className="shrink-0 rounded-full bg-muted px-1.5 py-px text-[10.5px] font-bold text-muted-foreground tabular-nums">
                                                {g.items.length}
                                            </span>
                                        </div>
                                        <div className="flex flex-col gap-2">
                                            {g.items.map((s) => (
                                                <DayShiftRow
                                                    key={s.id}
                                                    s={s}
                                                    canManage={canManage}
                                                    onPeek={onPeek}
                                                    onCtx={onCtx}
                                                    onAssign={onAssign}
                                                    onEdit={onEdit}
                                                />
                                            ))}
                                        </div>
                                    </section>
                                );
                            })
                        )}
                    </div>

                    <footer className="flex items-center justify-between gap-2.5 border-t border-border bg-muted/30 px-5 py-3.5">
                        <span className="min-w-0 text-xs text-muted-foreground">
                            Showing{' '}
                            <strong className="font-bold text-foreground">
                                {visible.length}
                            </strong>{' '}
                            of{' '}
                            <strong className="font-bold text-foreground">
                                {shifts.length}
                            </strong>{' '}
                            shift{shifts.length === 1 ? '' : 's'}
                        </span>
                        <div className="flex shrink-0 items-center gap-2">
                            <Button
                                size="sm"
                                variant="outline"
                                onClick={onClose}
                            >
                                Close
                            </Button>
                            {canCreate ? (
                                <Button size="sm" onClick={onNewShift}>
                                    <Plus className="mr-1 h-[13px] w-[13px]" />{' '}
                                    New shift on this day
                                </Button>
                            ) : null}
                        </div>
                    </footer>
                </div>
            </DialogContent>
        </Dialog>
    );
}
