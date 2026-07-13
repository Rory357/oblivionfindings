/* Right rail (cards & list views): awaiting review, mini week day-picker,
 * type breakdown, and coverage gaps (past shifts with no note). */
import { addDaysWP } from '@/components/rostering';
import {
    Activity,
    AlertTriangle,
    Bell,
    CalendarRange,
    CheckCircle2,
    ChevronRight,
    Plus,
} from 'lucide-react';
import { useMemo } from 'react';

import { cn } from '@/lib/utils';

import {
    type CatalogueShift,
    HueAvatar,
    NOTE_TYPES,
    type ShiftNote,
    TYPE_META,
    clientName,
    fmtClock,
    noteDate,
    relTime,
    ymd,
} from './shared';
import { Button as GuardrailButton } from '@/components/ui/button';
import { Card as GuardrailCard } from '@/components/ui/card';

export type CoverageGap = {
    shift: CatalogueShift;
    date: Date;
};

/** Past shifts in the week that have no associated note — surfaced as coverage
 *  gaps in the rail and counted in the hero. Shared so both agree. */
export function computeCoverageGaps(
    shifts: CatalogueShift[],
    weekNotes: ShiftNote[],
    weekStart: Date,
): CoverageGap[] {
    const weekEnd = addDaysWP(weekStart, 7);
    const now = Date.now();
    const noted = new Set(
        weekNotes
            .map((n) => n.shift?.id)
            .filter((id): id is number => id != null),
    );
    return shifts
        .filter((s) => {
            if (!s.starts_at) return false;
            const start = new Date(s.starts_at);
            return (
                start >= weekStart &&
                start < weekEnd &&
                start.getTime() < now &&
                !noted.has(s.id)
            );
        })
        .sort(
            (a, b) =>
                new Date(a.starts_at!).getTime() -
                new Date(b.starts_at!).getTime(),
        )
        .map((s) => ({ shift: s, date: new Date(s.starts_at!) }));
}

export function NoteRail({
    weekNotes,
    gaps,
    weekStart,
    selectedDay,
    onSelectDay,
    onOpen,
    onAddNoteForShift,
}: {
    weekNotes: ShiftNote[];
    gaps: CoverageGap[];
    weekStart: Date;
    selectedDay: string | null;
    onSelectDay: (day: string | null) => void;
    onOpen: (note: ShiftNote) => void;
    onAddNoteForShift: (shift: CatalogueShift) => void;
}) {
    const awaiting = useMemo(
        () =>
            weekNotes
                .filter((n) => !n.reviewed_at)
                .sort((a, b) => (b.is_flagged ? 1 : 0) - (a.is_flagged ? 1 : 0))
                .slice(0, 5),
        [weekNotes],
    );

    const notesByDay = useMemo(() => {
        const m = new Map<string, number>();
        for (const n of weekNotes) {
            const k = ymd(noteDate(n));
            m.set(k, (m.get(k) ?? 0) + 1);
        }
        return m;
    }, [weekNotes]);

    const days = useMemo(() => {
        const todayKey = ymd(new Date());
        return Array.from({ length: 7 }, (_, i) => {
            const d = addDaysWP(weekStart, i);
            const key = ymd(d);
            return {
                d,
                key,
                n: notesByDay.get(key) ?? 0,
                isToday: key === todayKey,
            };
        });
    }, [weekStart, notesByDay]);

    const breakdown = useMemo(() => {
        const counts = new Map<string, number>();
        for (const n of weekNotes)
            counts.set(n.type, (counts.get(n.type) ?? 0) + 1);
        return NOTE_TYPES.map((t) => ({
            key: t,
            label: TYPE_META[t].label,
            color: TYPE_META[t].color,
            value: counts.get(t) ?? 0,
        }));
    }, [weekNotes]);

    const visibleGaps = gaps.slice(0, 6);

    return (
        <aside className="flex w-full flex-col gap-4">
            {/* Awaiting review */}
            <div className="rounded-2xl border border-border bg-card p-4">
                <h3 className="flex items-center gap-2 text-[13px] font-bold">
                    <Bell className="h-4 w-4 shrink-0 text-status-warning" />
                    Awaiting review
                </h3>
                <p className="mt-0.5 text-[11.5px] text-muted-foreground">
                    {awaiting.length === 0
                        ? 'Everything is reviewed — nice work.'
                        : `${awaiting.length} note${awaiting.length === 1 ? '' : 's'} need${awaiting.length === 1 ? 's' : ''} reviewing.`}
                </p>
                {awaiting.length === 0 ? (
                    <div className="mt-3 inline-flex items-center gap-1.5 text-[12.5px] text-status-success">
                        <CheckCircle2 className="h-4 w-4" />
                        All caught up.
                    </div>
                ) : (
                    <div className="mt-3 space-y-1">
                        {awaiting.map((n) => (
                            <GuardrailButton unstyled
                                key={n.id}
                                type="button"
                                onClick={() => onOpen(n)}
                                className="flex w-full items-center gap-2.5 rounded-lg px-2 py-2 text-left transition-colors hover:bg-accent"
                            >
                                {n.user ? (
                                    <HueAvatar name={n.user.name} size={28} />
                                ) : null}
                                <span className="min-w-0 flex-1">
                                    <span className="flex items-center gap-1 truncate text-[12.5px] font-semibold">
                                        {clientName(n.client)}
                                        {n.is_flagged ? (
                                            <span className="text-status-critical">
                                                ⚑
                                            </span>
                                        ) : null}
                                    </span>
                                    <span className="block truncate text-[11px] text-muted-foreground">
                                        {n.user?.name ?? '—'} ·{' '}
                                        {relTime(n.created_at)}
                                    </span>
                                </span>
                                <ChevronRight className="h-4 w-4 shrink-0 text-muted-foreground" />
                            </GuardrailButton>
                        ))}
                    </div>
                )}
            </div>

            {/* This week */}
            <div className="rounded-2xl border border-border bg-card p-4">
                <h3 className="flex items-center gap-2 text-[13px] font-bold">
                    <CalendarRange className="h-4 w-4 shrink-0 text-primary" />
                    This week
                </h3>
                <p className="mt-0.5 text-[11.5px] text-muted-foreground">
                    Notes logged per day
                </p>
                <div className="mt-3 grid grid-cols-7 gap-1.5">
                    {days.map((c) => {
                        const active = selectedDay === c.key;
                        return (
                            <GuardrailButton unstyled
                                key={c.key}
                                type="button"
                                onClick={() =>
                                    onSelectDay(active ? null : c.key)
                                }
                                aria-pressed={active}
                                title={`${c.n} note${c.n === 1 ? '' : 's'}`}
                                className={cn(
                                    'flex flex-col items-center gap-1 rounded-lg border py-2 transition-colors',
                                    active
                                        ? 'border-primary bg-primary/10'
                                        : c.isToday
                                          ? 'border-primary/30 bg-accent'
                                          : 'border-transparent hover:bg-accent',
                                )}
                            >
                                <span className="text-[10px] font-semibold text-muted-foreground uppercase">
                                    {c.d
                                        .toLocaleDateString('en-NZ', {
                                            weekday: 'short',
                                        })
                                        .slice(0, 1)}
                                </span>
                                <span className="text-[13px] font-bold tabular-nums">
                                    {c.d.getDate()}
                                </span>
                                <span
                                    className={cn(
                                        'h-1.5 w-1.5 rounded-full',
                                        c.n > 0 ? 'bg-primary' : 'bg-border',
                                    )}
                                />
                            </GuardrailButton>
                        );
                    })}
                </div>
            </div>

            {/* Type breakdown */}
            <div className="rounded-2xl border border-border bg-card p-4">
                <h3 className="flex items-center gap-2 text-[13px] font-bold">
                    <Activity className="h-4 w-4 shrink-0 text-primary" />
                    Type breakdown
                </h3>
                <p className="mt-0.5 text-[11.5px] text-muted-foreground">
                    Across the displayed week
                </p>
                <div className="mt-3 space-y-2">
                    {breakdown.map((b) => (
                        <div
                            key={b.key}
                            className="flex items-center gap-2 text-[12.5px]"
                        >
                            <span
                                className="h-2.5 w-2.5 rounded-full"
                                style={{ backgroundColor: b.color }}
                            />
                            {b.label}
                            <span className="ml-auto font-semibold tabular-nums">
                                {b.value}
                            </span>
                        </div>
                    ))}
                </div>
            </div>

            {/* Coverage gaps */}
            <div className="rounded-2xl border border-border bg-card p-4">
                <h3 className="flex items-center gap-2 text-[13px] font-bold">
                    <AlertTriangle className="h-4 w-4 shrink-0 text-status-warning" />
                    Coverage gaps
                    {gaps.length > 0 ? (
                        <span className="ml-auto rounded-full bg-status-warning-bg px-2 py-0.5 text-[11px] font-semibold text-status-warning tabular-nums">
                            {gaps.length}
                        </span>
                    ) : null}
                </h3>
                <p className="mt-0.5 text-[11.5px] text-muted-foreground">
                    Past shifts with no note
                </p>
                {gaps.length === 0 ? (
                    <div className="mt-3 inline-flex items-center gap-1.5 text-[12.5px] text-status-success">
                        <CheckCircle2 className="h-4 w-4" />
                        Every past shift is documented.
                    </div>
                ) : (
                    <div className="mt-3 space-y-1.5">
                        {visibleGaps.map((g) => (
                            <GuardrailCard unstyled
                                key={g.shift.id}
                                className="flex items-center gap-2.5 rounded-lg border border-border bg-background px-2.5 py-2"
                            >
                                <span className="flex h-7 w-7 shrink-0 items-center justify-center rounded-lg bg-status-warning-bg text-status-warning">
                                    <AlertTriangle className="h-3.5 w-3.5" />
                                </span>
                                <div className="min-w-0 flex-1 leading-tight">
                                    <div className="truncate text-[12.5px] font-semibold">
                                        {g.date.toLocaleDateString('en-NZ', {
                                            weekday: 'long',
                                            day: 'numeric',
                                            month: 'short',
                                        })}
                                    </div>
                                    <div className="truncate text-[11px] text-muted-foreground">
                                        {g.shift.label} ·{' '}
                                        {fmtClock(g.shift.starts_at)} · not yet
                                        written up
                                    </div>
                                </div>
                                <GuardrailButton unstyled
                                    type="button"
                                    onClick={() => onAddNoteForShift(g.shift)}
                                    className="inline-flex shrink-0 items-center gap-1 rounded-lg border border-border bg-card px-2 py-1 text-[11px] font-semibold transition-colors hover:bg-accent"
                                >
                                    <Plus className="h-3 w-3" />
                                    Note
                                </GuardrailButton>
                            </GuardrailCard>
                        ))}
                    </div>
                )}
            </div>
        </aside>
    );
}
