/* Right rail (cards & list views): awaiting sign-off, mini week strip, legend. */
import { addDaysWP } from '@/components/rostering';
import { Activity, Bell, CalendarRange, ChevronRight } from 'lucide-react';
import { useMemo } from 'react';

import { toDateInput } from '@/lib/datetime';
import { cn } from '@/lib/utils';

import { Button as GuardrailButton } from '@/components/ui/button';
import {
    type HandoverCtxHandlers,
    useHandoverContextMenu,
} from './handover-context-menu';
import {
    type Handover,
    HueAvatar,
    clientName,
    handoverCalendarDate,
    relTime,
    ymd,
} from './shared';

type RailCounts = {
    draft: number;
    submitted: number;
    acknowledged: number;
};

export function HandoverRail({
    handovers,
    counts,
    weekStart,
    timeZone,
    onAwaiting,
    onOpen,
    onSubmit,
    onAcknowledge,
    onEdit,
}: {
    handovers: Handover[];
    counts: RailCounts;
    weekStart: Date;
    timeZone?: string;
    onAwaiting?: () => void;
} & HandoverCtxHandlers) {
    const { openCtx, menu } = useHandoverContextMenu({
        onOpen,
        onSubmit,
        onAcknowledge,
        onEdit,
    });
    const awaiting = useMemo(
        () => handovers.filter((h) => h.status === 'submitted').slice(0, 5),
        [handovers],
    );

    const days = useMemo(() => {
        const todayKey = timeZone
            ? toDateInput(new Date(), timeZone)
            : ymd(new Date());
        return Array.from({ length: 7 }, (_, i) => {
            const d = addDaysWP(weekStart, i);
            const key = ymd(d);
            const n = handovers.filter(
                (h) => ymd(handoverCalendarDate(h, timeZone)) === key,
            ).length;
            return { d, n, isToday: key === todayKey };
        });
    }, [handovers, weekStart, timeZone]);

    const legend = [
        { label: 'Draft', color: 'bg-muted-foreground', n: counts.draft },
        {
            label: 'Awaiting acknowledgement',
            color: 'bg-status-warning',
            n: counts.submitted,
        },
        {
            label: 'Acknowledged',
            color: 'bg-status-success',
            n: counts.acknowledged,
        },
    ];

    return (
        <aside className="flex w-full flex-col gap-4">
            <div className="rounded-2xl border border-border bg-card p-4">
                <h3 className="flex items-center gap-2 text-[13px] font-bold">
                    <Bell className="h-4 w-4 shrink-0 text-status-warning" />
                    Awaiting acknowledgement
                </h3>
                <p className="mt-0.5 text-[11.5px] text-muted-foreground">
                    {counts.submitted === 0
                        ? 'No handovers awaiting acknowledgement in this selection.'
                        : awaiting.length === 0
                          ? `${counts.submitted} match these filters on other pages or statuses.`
                          : `Showing ${awaiting.length} from this page · ${counts.submitted} match these filters in total.`}
                </p>
                {counts.submitted > 0 && onAwaiting ? (
                    <GuardrailButton
                        variant="link"
                        className="frontline-hit mt-2 px-0"
                        onClick={onAwaiting}
                    >
                        View all awaiting acknowledgement
                    </GuardrailButton>
                ) : null}
                <div className="mt-3 space-y-1">
                    {awaiting.map((h) => (
                        <GuardrailButton
                            unstyled
                            key={h.id}
                            type="button"
                            onClick={() => onOpen(h)}
                            onContextMenu={(e) => openCtx(e, h)}
                            className="flex w-full items-center gap-2.5 rounded-lg px-2 py-2 text-left transition-colors hover:bg-accent"
                        >
                            {h.outgoing_staff ? (
                                <HueAvatar
                                    name={h.outgoing_staff.name}
                                    size={28}
                                />
                            ) : null}
                            <span className="min-w-0 flex-1">
                                <span className="block truncate text-[12.5px] font-semibold">
                                    {clientName(h.client)}
                                </span>
                                <span className="block truncate text-[11px] text-muted-foreground">
                                    {h.outgoing_staff?.name.split(' ')[0] ??
                                        '—'}{' '}
                                    →{' '}
                                    {h.incoming_staff?.name.split(' ')[0] ??
                                        'Open'}{' '}
                                    · {relTime(h.submitted_at)}
                                </span>
                            </span>
                            <ChevronRight className="h-4 w-4 shrink-0 text-muted-foreground" />
                        </GuardrailButton>
                    ))}
                </div>
            </div>

            <div className="rounded-2xl border border-border bg-card p-4">
                <h3 className="flex items-center gap-2 text-[13px] font-bold">
                    <CalendarRange className="h-4 w-4 shrink-0 text-primary" />
                    This week
                </h3>
                <p className="mt-0.5 text-[11.5px] text-muted-foreground">
                    Loaded handovers per day
                </p>
                <div className="mt-3 grid grid-cols-7 gap-1.5">
                    {days.map((c, i) => (
                        <div
                            key={i}
                            className={cn(
                                'flex flex-col items-center gap-1 rounded-lg border border-transparent py-2',
                                c.isToday && 'border-primary/30 bg-accent',
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
                                    'flex h-4 min-w-4 items-center justify-center rounded-full px-1 text-[10px] font-semibold tabular-nums',
                                    c.n > 0
                                        ? 'bg-primary/15 text-primary'
                                        : 'text-muted-foreground/50',
                                )}
                            >
                                {c.n > 0 ? c.n : '·'}
                            </span>
                        </div>
                    ))}
                </div>
            </div>

            <div className="rounded-2xl border border-border bg-card p-4">
                <h3 className="flex items-center gap-2 text-[13px] font-bold">
                    <Activity className="h-4 w-4 shrink-0 text-primary" />
                    Status breakdown
                </h3>
                <p className="mt-0.5 text-[11.5px] text-muted-foreground">
                    {onAwaiting
                        ? 'All matching records, across every page'
                        : 'Loaded handovers in the selected week'}
                </p>
                <div className="mt-3 space-y-2">
                    {legend.map((s) => (
                        <div
                            key={s.label}
                            className="flex items-center gap-2 text-[12.5px]"
                        >
                            <span
                                className={cn(
                                    'h-2.5 w-2.5 rounded-full',
                                    s.color,
                                )}
                            />
                            {s.label}
                            <span className="ml-auto font-semibold tabular-nums">
                                {s.n}
                            </span>
                        </div>
                    ))}
                </div>
            </div>
            {menu}
        </aside>
    );
}
