import {
    Calendar,
    CalendarPlus,
    Clock,
    Coffee,
    Eye,
    LogIn,
    LogOut,
} from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';

import { MyHrShell, hueFromId, type MyHrShellData } from '@/components/hr';
import {
    ShiftContextMenu,
    type ShiftCtxState,
} from '@/components/rostering/shift-context-menu';
import { Card } from '@/components/ui/card';
import { cn } from '@/lib/utils';

interface TimeEntry {
    id: number;
    clock_in: string;
    clock_out: string | null;
    break_minutes: number;
    total_hours: number | null;
    status: string;
    client_name: string | null;
    shift: { client_name: string } | null;
}

interface WeeklySummary {
    week_start: string;
    week_end: string;
    daily_hours: Record<string, number>;
    total_hours: number;
}

interface RosterShift {
    id: number;
    service_context_id: number | null;
    site: string;
    client_name: string | null;
    shift_type: string;
    time: string;
    starts_at: string;
    ends_at: string | null;
}

interface RosterDay {
    day: string;
    date: string;
    today: boolean;
    shifts: RosterShift[];
}

interface Props {
    myHr: MyHrShellData;
    weekRoster: RosterDay[];
    activeClock: { id: number; clock_in: string } | null;
    todayEntries: TimeEntry[];
    weeklySummary: WeeklySummary;
}

const DAY_LABELS = ['M', 'T', 'W', 'T', 'F', 'S', 'S'];

type TimelineRow = {
    label: string;
    time: string;
    icon: typeof LogIn;
    tone: 'success' | 'warning' | 'neutral' | 'live';
};

const TIMELINE_TONE: Record<TimelineRow['tone'], string> = {
    success: 'bg-status-success-bg text-status-success',
    warning: 'bg-status-warning-bg text-status-warning',
    neutral: 'bg-muted text-muted-foreground',
    live: 'bg-live-bg text-live',
};

function buildTimeline(entries: TimeEntry[], clockedIn: boolean): TimelineRow[] {
    const rows: TimelineRow[] = [];
    for (const e of entries) {
        rows.push({
            label: 'Clocked in',
            time: e.clock_in,
            icon: LogIn,
            tone: 'success',
        });
        if (e.break_minutes > 0) {
            rows.push({
                label: `Break · ${e.break_minutes}m`,
                time: '',
                icon: Coffee,
                tone: 'warning',
            });
        }
        if (e.clock_out) {
            rows.push({
                label: 'Clocked out',
                time: e.clock_out,
                icon: LogOut,
                tone: 'neutral',
            });
        }
    }
    if (clockedIn) {
        rows.push({
            label: 'Now — on shift',
            time: 'live',
            icon: Clock,
            tone: 'live',
        });
    }
    return rows;
}

function downloadShiftIcs(shiftId: number) {
    const a = document.createElement('a');
    a.href = `/hr/my/time/shifts/${shiftId}/calendar`;
    a.download = `shift-${shiftId}.ics`;
    document.body.appendChild(a);
    a.click();
    a.remove();
    toast.success('Added to calendar 📅', {
        description: 'Shift exported as an .ics event.',
    });
}

export default function MyTime({
    myHr,
    weekRoster,
    activeClock,
    todayEntries,
    weeklySummary,
}: Props) {
    const [ctx, setCtx] = useState<ShiftCtxState | null>(null);

    const timeline = buildTimeline(todayEntries, !!activeClock);
    const dailyEntries = Object.entries(weeklySummary.daily_hours ?? {});
    const maxHours = Math.max(8, ...dailyEntries.map(([, h]) => Number(h) || 0));
    const target = myHr.weekly.target_hours;

    function openShiftCtx(e: React.MouseEvent, s: RosterShift, day: RosterDay) {
        e.preventDefault();
        setCtx({
            x: e.clientX,
            y: e.clientY,
            tag: `${day.day} ${day.date}`,
            tagBg: 'var(--accent)',
            tagColor: 'var(--primary)',
            meta: `${s.site} · ${s.time}`,
            items: [
                {
                    icon: <Eye className="h-4 w-4" />,
                    label: 'View shift',
                    onClick: () =>
                        toast.info(s.site, {
                            description: `${s.client_name ? `${s.client_name} · ` : ''}${s.time}`,
                        }),
                },
                {
                    icon: <CalendarPlus className="h-4 w-4" />,
                    label: 'Add to calendar',
                    sub: 'Export .ics',
                    onClick: () => downloadShiftIcs(s.id),
                },
            ],
        });
    }

    return (
        <MyHrShell active="time" myHr={myHr} title="Time & Shifts · My HR">
            <div className="flex flex-col gap-5">
                <div className="grid gap-5 lg:grid-cols-[1fr_1.4fr]">
                    {/* Today punch timeline */}
                    <Card className="p-[18px]">
                        <div className="mb-3.5 flex items-center justify-between">
                            <h3 className="text-sm font-bold">Today</h3>
                            <span className="text-xs font-bold text-live">
                                {todayHoursLabel(todayEntries)}
                            </span>
                        </div>
                        {timeline.length === 0 ? (
                            <div className="flex flex-col items-center gap-2 py-8 text-center">
                                <Clock className="h-8 w-8 text-muted-foreground/40" />
                                <p className="text-[13px] text-muted-foreground">
                                    No punches today yet — clock in from the card above.
                                </p>
                            </div>
                        ) : (
                            <div className="flex flex-col">
                                {timeline.map((row, i) => {
                                    const Icon = row.icon;
                                    return (
                                        <div
                                            key={i}
                                            className="flex items-center gap-3 py-2"
                                        >
                                            <span
                                                className={cn(
                                                    'grid h-[30px] w-[30px] shrink-0 place-items-center rounded-lg',
                                                    TIMELINE_TONE[row.tone],
                                                )}
                                            >
                                                <Icon className="h-3.5 w-3.5" />
                                            </span>
                                            <span className="flex-1 text-[13px] font-semibold">
                                                {row.label}
                                            </span>
                                            <span className="text-[13px] font-semibold tabular-nums">
                                                {row.time === 'live' ? (
                                                    <span className="inline-flex items-center gap-1.5 text-live">
                                                        <span className="relative flex h-2 w-2">
                                                            <span className="absolute inline-flex h-full w-full animate-ping rounded-full bg-live opacity-75 motion-reduce:animate-none" />
                                                            <span className="relative inline-flex h-2 w-2 rounded-full bg-live" />
                                                        </span>
                                                        live
                                                    </span>
                                                ) : (
                                                    row.time
                                                )}
                                            </span>
                                        </div>
                                    );
                                })}
                            </div>
                        )}
                    </Card>

                    {/* Hours this week */}
                    <Card className="p-[18px]">
                        <div className="mb-2 flex items-center justify-between">
                            <h3 className="text-sm font-bold">Hours this week</h3>
                            <span className="text-[13px] font-bold">
                                {weeklySummary.total_hours.toFixed(1)}h{' '}
                                <span className="font-normal text-muted-foreground">
                                    / {target}h
                                </span>
                            </span>
                        </div>
                        <div className="flex h-[120px] items-end gap-2.5 pt-3.5">
                            {dailyEntries.map(([date, hours], i) => {
                                const h = Number(hours) || 0;
                                const barH = Math.max(4, Math.round((h / maxHours) * 88));
                                const weekend = i >= 5;
                                return (
                                    <div
                                        key={date}
                                        className="flex h-full flex-1 flex-col items-center justify-end gap-1.5"
                                    >
                                        <span className="text-[10px] text-muted-foreground">
                                            {h > 0 ? `${h}h` : '–'}
                                        </span>
                                        <div
                                            className={cn(
                                                'w-full rounded-t-md',
                                                weekend || h === 0
                                                    ? 'bg-muted'
                                                    : 'bg-primary',
                                            )}
                                            style={{ height: `${barH}px` }}
                                        />
                                        <span className="text-[11px] font-semibold text-muted-foreground">
                                            {DAY_LABELS[i] ?? date.slice(8)}
                                        </span>
                                    </div>
                                );
                            })}
                        </div>
                    </Card>
                </div>

                {/* This week's roster */}
                <Card className="p-[18px]">
                    <div className="mb-3.5 flex items-center gap-2">
                        <Calendar className="h-4 w-4 text-primary" />
                        <h3 className="text-sm font-bold">This week’s roster</h3>
                        <span className="ml-auto text-[11px] text-muted-foreground">
                            From Workforce · right-click a shift
                        </span>
                    </div>
                    <div className="grid grid-cols-2 gap-2.5 sm:grid-cols-4 lg:grid-cols-7">
                        {weekRoster.map((d) => (
                            <div
                                key={`${d.day}-${d.date}`}
                                className={cn(
                                    'min-h-[118px] overflow-hidden rounded-xl border border-border',
                                    d.today && 'bg-accent',
                                )}
                            >
                                <div className="border-b border-border px-2.5 py-2">
                                    <span className="text-[11px] font-bold text-muted-foreground">
                                        {d.day}
                                    </span>{' '}
                                    <span className="text-[13px] font-bold">
                                        {d.date}
                                    </span>
                                </div>
                                {d.shifts.map((s) => (
                                    <div
                                        key={s.id}
                                        onContextMenu={(e) => openShiftCtx(e, s, d)}
                                        className="m-2 cursor-default rounded-lg bg-muted p-2"
                                        style={{
                                            borderLeft: `3px solid oklch(0.62 0.17 ${hueFromId(s.service_context_id ?? s.id)})`,
                                        }}
                                    >
                                        <div className="text-[11.5px] font-bold leading-tight">
                                            {s.site}
                                        </div>
                                        <div className="mt-0.5 text-[10.5px] text-muted-foreground">
                                            {s.client_name ?? s.shift_type}
                                        </div>
                                        <div className="mt-1 text-[10.5px] font-semibold">
                                            {s.time}
                                        </div>
                                    </div>
                                ))}
                            </div>
                        ))}
                    </div>
                </Card>
            </div>

            {ctx ? <ShiftContextMenu ctx={ctx} onClose={() => setCtx(null)} /> : null}
        </MyHrShell>
    );
}

/** Sum of completed-entry hours today, formatted for the timeline header. */
function todayHoursLabel(entries: TimeEntry[]): string {
    const total = entries
        .filter((e) => e.total_hours != null)
        .reduce((sum, e) => sum + (e.total_hours ?? 0), 0);
    return total > 0 ? `${total.toFixed(1)}h` : 'On shift';
}
