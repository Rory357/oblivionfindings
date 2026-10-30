import { addDaysWP } from '@/components/rostering';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import { CalendarRange, ChevronRight, NotebookPen, Plus } from 'lucide-react';
import { useMemo } from 'react';
import {
    type Catalogue,
    type CatalogueShift,
    type ShiftNote,
    NOTE_TYPES,
    TYPE_META,
    clientName,
    fmtClock,
    noteCalendarDate,
    noteDate,
    ymd,
} from './shared';

export type CoverageGap = { shift: CatalogueShift; date: Date };
/** Suggestions from the writable shift catalogue, never proof of missing care. */
export function computeCoverageGaps(
    shifts: CatalogueShift[],
    weekNotes: ShiftNote[],
    weekStart: Date,
    notedShiftIds?: number[],
    timeZone?: string,
): CoverageGap[] {
    const weekEnd = addDaysWP(weekStart, 7);
    const noted = new Set(
        notedShiftIds ??
            weekNotes.flatMap((note) => (note.shift ? [note.shift.id] : [])),
    );
    const now = Date.now();
    return shifts
        .filter((shift) => {
            if (!shift.starts_at) return false;
            const date = noteCalendarDate(shift.starts_at, timeZone);
            const ended =
                new Date(shift.ends_at ?? shift.starts_at).getTime() < now;
            return (
                date >= weekStart &&
                date < weekEnd &&
                ended &&
                !noted.has(shift.id)
            );
        })
        .sort(
            (a, b) =>
                new Date(a.starts_at!).getTime() -
                new Date(b.starts_at!).getTime(),
        )
        .map((shift) => ({
            shift,
            date: noteCalendarDate(shift.starts_at, timeZone),
        }));
}
export function NoteRail({
    weekNotes,
    gaps,
    weekStart,
    selectedDay,
    onSelectDay,
    onOpen,
    onAddNoteForShift,
    timeZone,
    awaitingTotal,
    onAwaiting,
    shiftResults,
}: {
    weekNotes: ShiftNote[];
    gaps: CoverageGap[];
    weekStart: Date;
    selectedDay: string | null;
    onSelectDay: (day: string | null) => void;
    onOpen: (note: ShiftNote) => void;
    onAddNoteForShift?: (shift: CatalogueShift) => void;
    timeZone?: string;
    awaitingTotal: number;
    onAwaiting: () => void;
    shiftResults?: Catalogue['shift_results'];
}) {
    const awaiting = useMemo(
        () =>
            weekNotes
                .filter((note) => !note.reviewed_at)
                .sort((a, b) => Number(b.is_flagged) - Number(a.is_flagged))
                .slice(0, 5),
        [weekNotes],
    );
    const counts = useMemo(() => {
        const byDay = new Map<string, number>();
        for (const note of weekNotes) {
            const key = ymd(noteDate(note, timeZone));
            byDay.set(key, (byDay.get(key) ?? 0) + 1);
        }
        return byDay;
    }, [weekNotes, timeZone]);
    const today = ymd(noteCalendarDate(new Date().toISOString(), timeZone));
    return (
        <aside className="flex min-w-0 flex-col gap-4">
            <section className="rounded-xl border bg-card p-4">
                <h2 className="text-section-title">Awaiting review</h2>
                <p className="text-caption mt-1 text-muted-foreground">
                    {awaitingTotal} matching notes across all pages.
                </p>
                {awaiting.length === 0 ? (
                    <p className="text-caption mt-3">
                        No unreviewed notes on this page.
                    </p>
                ) : (
                    <div className="mt-3 space-y-1">
                        {awaiting.map((note) => (
                            <Button
                                key={note.id}
                                variant="ghost"
                                className="frontline-hit h-auto w-full justify-between text-left whitespace-normal"
                                onClick={() => onOpen(note)}
                            >
                                <span className="min-w-0">
                                    {clientName(note.client)}
                                    <span className="text-caption block text-muted-foreground">
                                        {note.user?.name ??
                                            'Author unavailable'}
                                        {note.is_flagged ? ' · Flagged' : ''}
                                    </span>
                                </span>
                                <ChevronRight className="h-4 w-4 shrink-0" />
                            </Button>
                        ))}
                    </div>
                )}
                {awaitingTotal > 0 && (
                    <Button
                        variant="outline"
                        className="frontline-hit mt-3"
                        onClick={onAwaiting}
                    >
                        View all awaiting review
                    </Button>
                )}
            </section>
            <section className="rounded-xl border bg-card p-4">
                <h2 className="text-section-title flex items-center gap-2">
                    <CalendarRange className="h-4 w-4" />
                    Viewed week
                </h2>
                <p className="text-caption mt-1 text-muted-foreground">
                    Loaded notes per day. Choose a day to search all matching
                    notes.
                </p>
                <div className="mt-3 grid grid-cols-7 gap-1">
                    {Array.from({ length: 7 }, (_, i) => {
                        const date = addDaysWP(weekStart, i);
                        const key = ymd(date);
                        const count = counts.get(key) ?? 0;
                        return (
                            <Button
                                key={key}
                                variant="ghost"
                                aria-pressed={selectedDay === key}
                                aria-label={`${date.toLocaleDateString('en-NZ', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' })}, ${count} loaded notes`}
                                onClick={() =>
                                    onSelectDay(
                                        selectedDay === key ? null : key,
                                    )
                                }
                                className={cn(
                                    'frontline-hit h-auto min-w-0 flex-col gap-1 px-1 py-2',
                                    selectedDay === key && 'bg-accent',
                                    today === key &&
                                        'ring-1 ring-border ring-inset',
                                )}
                            >
                                <span className="text-caption">
                                    {date
                                        .toLocaleDateString('en-NZ', {
                                            weekday: 'short',
                                        })
                                        .slice(0, 1)}
                                </span>
                                <span>{date.getDate()}</span>
                                <span className="text-caption text-muted-foreground">
                                    {count}
                                </span>
                            </Button>
                        );
                    })}
                </div>
            </section>
            <section className="rounded-xl border bg-card p-4">
                <h2 className="text-section-title">Types on this page</h2>
                <dl className="mt-3 space-y-2">
                    {NOTE_TYPES.map((type) => (
                        <div
                            key={type}
                            className="text-caption flex justify-between gap-2"
                        >
                            <dt>{TYPE_META[type].label}</dt>
                            <dd className="tabular-nums">
                                {
                                    weekNotes.filter(
                                        (note) => note.type === type,
                                    ).length
                                }
                            </dd>
                        </div>
                    ))}
                </dl>
            </section>
            <section className="rounded-xl border bg-card p-4">
                <h2 className="text-section-title flex items-center gap-2">
                    <NotebookPen className="h-4 w-4" />
                    Earlier shifts to document
                </h2>
                <p className="text-caption mt-1 text-muted-foreground">
                    These listed shifts have no note visible to you. Other notes
                    may exist; this is not a care-completion check.
                </p>
                {shiftResults?.truncated && (
                    <p className="text-caption mt-2 text-muted-foreground">
                        The shift picker shows {shiftResults.shown} of{' '}
                        {shiftResults.total} permitted shifts this week.
                    </p>
                )}
                {gaps.length === 0 ? (
                    <p className="text-caption mt-3">
                        No suggestions among the loaded shifts.
                    </p>
                ) : (
                    <>
                        <p className="text-caption mt-3">
                            {gaps.length} suggestions among the loaded shifts.
                        </p>
                        <div className="mt-2 space-y-2">
                            {gaps.slice(0, 6).map(({ shift, date }) => (
                                <div
                                    key={shift.id}
                                    className="flex items-center gap-2 rounded-lg border p-3"
                                >
                                    <div className="min-w-0 flex-1">
                                        <p className="text-caption font-medium">
                                            {date.toLocaleDateString('en-NZ', {
                                                weekday: 'long',
                                                day: 'numeric',
                                                month: 'short',
                                            })}{' '}
                                            ·{' '}
                                            {fmtClock(
                                                shift.starts_at,
                                                timeZone,
                                            )}
                                        </p>
                                        <p className="text-caption break-words text-muted-foreground">
                                            {shift.label}
                                        </p>
                                    </div>
                                    {onAddNoteForShift && (
                                        <Button
                                            variant="outline"
                                            className="frontline-hit"
                                            onClick={() =>
                                                onAddNoteForShift(shift)
                                            }
                                        >
                                            <Plus className="h-4 w-4" />
                                            Note
                                        </Button>
                                    )}
                                </div>
                            ))}
                        </div>
                        {gaps.length > 6 && (
                            <p className="text-caption mt-2 text-muted-foreground">
                                Showing the first 6 suggestions. Use Add note
                                for another listed shift.
                            </p>
                        )}
                    </>
                )}
            </section>
        </aside>
    );
}
