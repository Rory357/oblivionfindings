import {
    EntityContextMenu,
    EntityKebab,
    type MenuItem,
} from '@/components/lists/entity-menu';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { StatusBadge } from '@/components/ui/status-badge';
import {
    formatDate,
    formatDateLong,
    formatTime,
    toDateInput,
    toDatetimeLocal,
} from '@/lib/datetime';
import { cn } from '@/lib/utils';
import {
    CalendarDays,
    Clock3,
    List,
    Plus,
    type LucideIcon,
} from 'lucide-react';
import { useEffect, useRef, useState, type ReactNode } from 'react';

/** Embedded work adapter for the shared Site Calendar. Owning modules retain every action. */
export interface CalendarWorkEntry {
    key: string;
    at: number | null;
    title: string;
    person: string;
    icon: LucideIcon;
    detail?: ReactNode;
    meta?: string;
    status?: ReactNode;
    source: 'checklist' | 'medication';
    onOpen: () => void;
    openLabel: string;
    actions?: MenuItem[];
}

export function CalendarDayHeading({
    date,
    caption,
    trailing,
}: {
    date: number;
    caption?: ReactNode;
    trailing?: ReactNode;
}) {
    return (
        <div className="flex flex-wrap items-center gap-3 border-b px-4 py-3">
            <span className="flex size-11 shrink-0 flex-col items-center justify-center rounded-xl bg-primary text-primary-foreground">
                <span className="text-caption font-semibold uppercase">
                    {formatDate(date).split(' ')[0]}
                </span>
                <span className="text-page-title leading-none tabular-nums">
                    {Number(toDateInput(date).slice(-2))}
                </span>
            </span>
            <div className="min-w-0 flex-1">
                <h3 className="text-section-title">{formatDateLong(date)}</h3>
                <div className="text-subtle">{caption}</div>
            </div>
            {trailing}
        </div>
    );
}

export function CalendarWorkRows({
    entries,
    now,
    nextKey,
}: {
    entries: CalendarWorkEntry[];
    now: number;
    nextKey?: string;
}) {
    const [context, setContext] = useState<{
        entry: CalendarWorkEntry;
        x: number;
        y: number;
    } | null>(null);
    return (
        <>
            <ul className="divide-y" aria-label="Work items">
                {entries.map((entry) => {
                    const Icon = entry.icon;
                    const when =
                        entry.at === null ? 'Any time' : formatTime(entry.at);
                    return (
                        <li
                            key={entry.key}
                            className={cn(
                                'px-4 py-4',
                                nextKey === entry.key &&
                                    'border-l-2 border-l-primary bg-primary/5',
                            )}
                            data-work-key={entry.key}
                            onContextMenu={(event) => {
                                if (!entry.actions?.length) return;
                                event.preventDefault();
                                setContext({
                                    entry,
                                    x: event.clientX,
                                    y: event.clientY,
                                });
                            }}
                        >
                            <div className="flex flex-wrap items-start gap-3">
                                <div className="w-24 shrink-0 pt-1 text-sm font-semibold tabular-nums">
                                    {entry.at !== null &&
                                        toDateInput(entry.at) !==
                                            toDateInput(now) && (
                                            <span className="text-caption block text-muted-foreground">
                                                {formatDate(entry.at)}
                                            </span>
                                        )}
                                    {when}
                                </div>
                                <span
                                    className="flex size-8 shrink-0 items-center justify-center rounded-lg"
                                    style={{
                                        color: `var(--src-${entry.source})`,
                                        background: `var(--src-${entry.source}-bg)`,
                                    }}
                                >
                                    <Icon className="size-4" />
                                </span>
                                <div className="min-w-40 flex-1">
                                    {nextKey === entry.key && (
                                        <p className="text-caption mb-1 font-semibold text-primary">
                                            Up next · {when}
                                        </p>
                                    )}
                                    <p className="font-semibold">
                                        {entry.title}
                                    </p>
                                    <p className="text-subtle mt-1">
                                        {entry.person}
                                        {entry.meta ? ` · ${entry.meta}` : ''}
                                    </p>
                                    {entry.status && (
                                        <div className="mt-2">
                                            {entry.status}
                                        </div>
                                    )}
                                </div>
                                <div className="flex items-center gap-2">
                                    <Button
                                        variant="outline"
                                        className="frontline-tap"
                                        onClick={entry.onOpen}
                                    >
                                        {entry.openLabel}
                                    </Button>
                                    {entry.actions && (
                                        <EntityKebab
                                            actions={entry.actions}
                                            className="frontline-tap"
                                            label={`More actions for ${entry.title}`}
                                        />
                                    )}
                                </div>
                            </div>
                            {entry.detail && (
                                <details className="mt-2 ml-0 sm:ml-27">
                                    <summary className="frontline-focus min-h-11 cursor-pointer rounded-md py-3 text-sm font-medium">
                                        View steps
                                    </summary>
                                    {entry.detail}
                                </details>
                            )}
                        </li>
                    );
                })}
            </ul>
            {context && (
                <EntityContextMenu
                    x={context.x}
                    y={context.y}
                    title={context.entry.title}
                    items={context.entry.actions ?? []}
                    onClose={() => setContext(null)}
                />
            )}
        </>
    );
}

/** Hour bands expand for busy slots: due points never imply a task duration or overlap text. */
export function workHourBands(
    entries: CalendarWorkEntry[],
    start: number,
    end: number,
) {
    const finite = entries.flatMap((entry) =>
        entry.at === null ? [] : [entry.at],
    );
    const first = Math.min(start, ...finite);
    const last = Math.max(end, ...finite);
    // Align by NZ wall-clock minutes while retaining the instant across midnight/DST.
    const minute = Number(toDatetimeLocal(first).slice(14, 16));
    const from = first - minute * 60_000 - (first % 60_000);
    const count = Math.max(1, Math.ceil((last - from) / 3_600_000));
    return Array.from({ length: count }, (_, index) => {
        const at = from + index * 3_600_000;
        return {
            at,
            entries: entries.filter(
                (entry) =>
                    entry.at !== null &&
                    entry.at >= at &&
                    (entry.at < at + 3_600_000 ||
                        (index === count - 1 && entry.at === last)),
            ),
        };
    });
}

export function WorkSchedule({
    entries,
    anytime,
    now,
    startsAt,
    endsAt,
    caption,
    onAddAt,
    onAddAnytime,
    children,
}: {
    entries: CalendarWorkEntry[];
    anytime: CalendarWorkEntry[];
    now: number;
    startsAt: number;
    endsAt: number;
    caption?: ReactNode;
    onAddAt?: (at: number) => void;
    onAddAnytime?: () => void;
    children?: ReactNode;
}) {
    const [view, setView] = useState<'agenda' | 'day'>('agenda');
    const currentHour = useRef<HTMLDivElement>(null);
    const bands = workHourBands(entries, startsAt, endsAt);
    const nextKey = entries.find(
        (entry) => entry.at !== null && entry.at > now,
    )?.key;
    const nextSlot = Math.max(startsAt, Math.ceil(now / 1_800_000) * 1_800_000);
    useEffect(() => {
        if (view === 'day')
            currentHour.current?.scrollIntoView?.({ block: 'nearest' });
    }, [view]);
    return (
        <Card unstyled className="overflow-hidden rounded-xl border bg-card">
            <CalendarDayHeading
                date={startsAt}
                caption={caption}
                trailing={
                    <Card
                        unstyled
                        role="group"
                        aria-label="Schedule layout"
                        className="flex gap-1 rounded-lg border bg-background p-1"
                    >
                        <Button
                            variant={view === 'agenda' ? 'secondary' : 'ghost'}
                            aria-pressed={view === 'agenda'}
                            className="frontline-tap"
                            onClick={() => setView('agenda')}
                        >
                            <List className="size-4" />
                            Agenda
                        </Button>
                        <Button
                            variant={view === 'day' ? 'secondary' : 'ghost'}
                            aria-pressed={view === 'day'}
                            className="frontline-tap"
                            onClick={() => setView('day')}
                        >
                            <CalendarDays className="size-4" />
                            Day
                        </Button>
                    </Card>
                }
            />
            {view === 'agenda' ? (
                <>
                    {entries.length ? (
                        <CalendarWorkRows
                            entries={entries}
                            now={now}
                            nextKey={nextKey}
                        />
                    ) : (
                        <p className="p-5 text-sm text-muted-foreground">
                            No timed work to show. Check any-time tasks below.
                        </p>
                    )}
                    {onAddAt && nextSlot < endsAt && (
                        <div className="flex justify-center border-t p-2">
                            <Button
                                variant="ghost"
                                className="frontline-tap"
                                onClick={() => onAddAt(nextSlot)}
                            >
                                <Plus className="size-4" />
                                Add at {formatTime(nextSlot)}
                            </Button>
                        </div>
                    )}
                </>
            ) : (
                <div
                    className="max-h-[36rem] overflow-y-auto"
                    aria-label="Shift hours"
                >
                    {bands.map(({ at, entries: hourEntries }) => {
                        const current = now >= at && now < at + 3_600_000;
                        const allowedAt = Math.max(startsAt, at);
                        return (
                            <div
                                key={at}
                                ref={current ? currentHour : undefined}
                                className="min-h-24 border-b last:border-b-0"
                            >
                                <div className="flex flex-wrap items-center justify-between gap-2 border-b border-border/50 bg-muted/30 px-4 py-2">
                                    <p className="text-sm font-medium tabular-nums">
                                        {formatDate(at)} · {formatTime(at)}
                                    </p>
                                    {current && (
                                        <StatusBadge variant="info">
                                            <Clock3 className="size-3.5" />
                                            Now {formatTime(now)}
                                        </StatusBadge>
                                    )}
                                    {onAddAt && allowedAt < endsAt && (
                                        <Button
                                            variant="ghost"
                                            className="frontline-tap"
                                            onClick={() => onAddAt(allowedAt)}
                                        >
                                            <Plus className="size-4" />
                                            Add at {formatTime(allowedAt)}
                                        </Button>
                                    )}
                                </div>
                                <CalendarWorkRows
                                    entries={hourEntries}
                                    now={now}
                                    nextKey={nextKey}
                                />
                            </div>
                        );
                    })}
                </div>
            )}
            <div className="flex flex-wrap items-center justify-between gap-3 border-y bg-muted/30 px-4 py-3">
                <h3 className="text-section-title">
                    Any time today ({anytime.length})
                </h3>
                {onAddAnytime && (
                    <Button
                        variant="outline"
                        className="frontline-tap"
                        onClick={onAddAnytime}
                    >
                        <Plus className="size-4" />
                        Add anytime task
                    </Button>
                )}
            </div>
            {anytime.length ? (
                <CalendarWorkRows entries={anytime} now={now} />
            ) : (
                <p className="p-5 text-sm text-muted-foreground">
                    No any-time tasks to show.
                </p>
            )}
            {children}
        </Card>
    );
}
