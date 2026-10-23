import { Link, usePage } from '@inertiajs/react';
import {
    Calendar,
    CalendarPlus,
    Clock,
    Coffee,
    Eye,
    LogIn,
    LogOut,
} from 'lucide-react';
import { MyHrShell, hueFromId, type MyHrShellData } from '@/components/hr';
import { readMyHrAttendance } from '@/components/hr/my-hr-attendance';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { formatDateOnly, formatDateTimeInZone, WORKER_TIMEZONE } from '@/lib/datetime';
import { cn } from '@/lib/utils';
import type { SharedData } from '@/types';

interface TimeEntry {
    id: number;
    clock_in: string;
    clock_out: string | null;
    clock_in_at?: string | null;
    clock_out_at?: string | null;
    break_minutes: number;
    total_hours: number | string | null;
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
    continues_from_previous_week?: boolean;
}

interface RosterDay {
    day: string;
    date: string;
    date_key?: string;
    today: boolean;
    shifts: RosterShift[];
}

interface Props {
    myHr: MyHrShellData;
    weekRoster: RosterDay[];
    activeClock: { id: number; clock_in: string } | null;
    todayEntries: TimeEntry[];
    weeklySummary: WeeklySummary;
    todayDate?: string;
    weekStartDate?: string;
    weekEndDate?: string;
}

const DAY_LABELS = ['M', 'T', 'W', 'T', 'F', 'S', 'S'];

type TimelineRow = {
    label: string;
    time: string;
    icon: typeof LogIn;
    tone: 'success' | 'warning' | 'neutral';
};

const TIMELINE_TONE: Record<TimelineRow['tone'], string> = {
    success: 'bg-status-success-bg text-status-success',
    warning: 'bg-status-warning-bg text-status-warning',
    neutral: 'bg-muted text-muted-foreground',
};

function instant(value: string | null | undefined): number | null {
    if (!value || !/(?:Z|[+-]\d{2}:\d{2})$/.test(value)) return null;
    const parsed = Date.parse(value);
    return Number.isFinite(parsed) ? parsed : null;
}

function recordedTime(value: string | null | undefined, legacy: string | null, timezone: string): string {
    if (instant(value) !== null) return formatDateTimeInZone(value, timezone);
    if (value === undefined && legacy) return `${legacy} (time zone not supplied)`;
    return 'Time unavailable';
}

function hasValidWindow(shift: RosterShift): boolean {
    const start = instant(shift.starts_at);
    const end = instant(shift.ends_at);
    return start !== null && end !== null && end > start;
}

function buildTimeline(entries: TimeEntry[], timezone: string): TimelineRow[] {
    const rows: TimelineRow[] = [];
    for (const e of entries) {
        rows.push({
            label: 'Clocked in',
            time: recordedTime(e.clock_in_at, e.clock_in, timezone),
            icon: LogIn,
            tone: 'success',
        });
        if (e.break_minutes > 0) {
            rows.push({
                label: `Recorded breaks · ${e.break_minutes} min`,
                time: '',
                icon: Coffee,
                tone: 'warning',
            });
        }
        if (e.clock_out) {
            rows.push({
                label: 'Clocked out',
                time: recordedTime(e.clock_out_at, e.clock_out, timezone),
                icon: LogOut,
                tone: 'neutral',
            });
        }
    }
    return rows;
}

export default function MyTime({
    myHr,
    weekRoster,
    todayEntries,
    weeklySummary,
    todayDate,
    weekStartDate,
    weekEndDate,
}: Props) {
    const { auth } = usePage<SharedData>().props;
    const canOpenShift = auth?.can?.shifts?.viewAny === true || auth?.can?.shifts?.viewAssigned === true;
    const workTimezone = readMyHrAttendance(myHr.attendance, auth?.user?.id)?.timezone ?? WORKER_TIMEZONE;

    const timeline = buildTimeline(todayEntries, workTimezone);
    const dailyEntries = Object.entries(weeklySummary.daily_hours ?? {});
    const maxHours = Math.max(
        8,
        ...dailyEntries.map(([, h]) => Number(h) || 0),
    );

    return (
        <MyHrShell active="time" myHr={myHr} title="Time & Shifts · My HR">
            <div className="flex flex-col gap-5">
                <div className="grid gap-5 lg:grid-cols-[1fr_1.4fr]">
                    {/* Today punch timeline */}
                    <Card className="p-[18px]">
                        <div className="mb-3.5 flex flex-wrap items-center justify-between gap-2">
                            <h3 className="text-sm font-bold">Today</h3>
                            <span className="text-xs font-bold text-foreground">
                                {todayHoursLabel(todayEntries)}
                            </span>
                        </div>
                        <p className="mb-3 text-caption text-muted-foreground">
                            {todayDate ? `${formatDateOnly(todayDate)} · ` : ''}{workTimezone}
                        </p>
                        {timeline.length === 0 ? (
                            <div className="flex flex-col items-center gap-2 py-8 text-center">
                                <Clock className="h-8 w-8 text-muted-foreground/40" />
                                <p className="text-[13px] text-muted-foreground">
                                    No time entries shown for today.
                                </p>
                            </div>
                        ) : (
                            <div className="flex flex-col">
                                {timeline.map((row, i) => {
                                    const Icon = row.icon;
                                    return (
                                        <div
                                            key={i}
                                            className="grid grid-cols-[30px_minmax(0,1fr)] items-center gap-x-3 gap-y-1 py-2 sm:flex sm:flex-wrap"
                                        >
                                            <span
                                                className={cn(
                                                    'grid h-[30px] w-[30px] shrink-0 place-items-center rounded-lg',
                                                    TIMELINE_TONE[row.tone],
                                                )}
                                            >
                                                <Icon className="h-3.5 w-3.5" />
                                            </span>
                                            <span className="min-w-0 flex-1 text-[13px] font-semibold break-words">
                                                {row.label}
                                            </span>
                                            <span className="col-start-2 min-w-0 text-[13px] font-semibold break-words tabular-nums">
                                                {row.time}
                                            </span>
                                        </div>
                                    );
                                })}
                            </div>
                        )}
                    </Card>

                    {/* Hours this week */}
                    <Card className="p-[18px]">
                        <div className="mb-2 flex flex-wrap items-center justify-between gap-2">
                            <h3 className="text-sm font-bold">
                                Recorded hours this week
                            </h3>
                            <span className="text-[13px] font-bold">
                                {weeklySummary.total_hours.toFixed(1)} hr
                            </span>
                        </div>
                        <p className="text-caption text-muted-foreground">
                            Time entries with a recorded finish. Ongoing sessions are excluded.
                        </p>
                        <p className="mt-1 text-caption text-muted-foreground">
                            {formatDateOnly(weeklySummary.week_start)} – {formatDateOnly(weeklySummary.week_end)}
                        </p>
                        <div className="flex h-[120px] items-end gap-2.5 pt-3.5">
                            {dailyEntries.map(([date, hours], i) => {
                                const h = Number(hours) || 0;
                                const barH = Math.max(
                                    4,
                                    Math.round((h / maxHours) * 88),
                                );
                                const weekend = i >= 5;
                                return (
                                    <div
                                        key={date}
                                        className="flex h-full flex-1 flex-col items-center justify-end gap-1.5"
                                    >
                                        <span className="text-[10px] text-muted-foreground">
                                            {h}h
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
                    <div className="mb-3.5 flex flex-wrap items-center gap-2">
                        <Calendar className="h-4 w-4 text-primary" />
                        <h3 className="text-sm font-bold">
                            This week’s roster
                        </h3>
                        <span className="ml-auto text-[11px] text-muted-foreground">
                            From Workforce
                        </span>
                    </div>
                    {weekStartDate && weekEndDate ? (
                        <p className="mb-2 text-caption text-muted-foreground">
                            {formatDateOnly(weekStartDate)} – {formatDateOnly(weekEndDate)} · {workTimezone}
                        </p>
                    ) : null}
                    <p className="mb-3 text-caption text-muted-foreground">
                        Open a downloaded calendar file in your calendar app to add the shift.
                    </p>
                    {weekRoster.every((day) => day.shifts.length === 0) ? (
                        <p className="mb-3 text-subtle text-muted-foreground">
                            No shifts are shown for this week.
                        </p>
                    ) : null}
                    <div className="grid grid-cols-1 gap-2.5 sm:grid-cols-4 lg:grid-cols-7">
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
                                        className="m-2 min-w-0 rounded-lg bg-muted p-2 break-words"
                                        style={{
                                            borderLeft: `3px solid oklch(0.62 0.17 ${hueFromId(s.service_context_id ?? s.id)})`,
                                        }}
                                    >
                                        <div className="text-[11.5px] leading-tight font-bold">
                                            {s.site}
                                        </div>
                                        <div className="mt-0.5 text-[10.5px] text-muted-foreground">
                                            {s.client_name ?? s.shift_type}
                                        </div>
                                        {s.continues_from_previous_week ? (
                                            <p className="mt-1 text-caption text-muted-foreground">Continues into this week</p>
                                        ) : null}
                                        {hasValidWindow(s) ? (
                                            <dl className="mt-2 space-y-1 text-caption">
                                                <div>
                                                    <dt className="text-muted-foreground">Starts</dt>
                                                    <dd>{formatDateTimeInZone(s.starts_at, workTimezone)}</dd>
                                                </div>
                                                <div>
                                                    <dt className="text-muted-foreground">Finishes</dt>
                                                    <dd>{formatDateTimeInZone(s.ends_at, workTimezone)}</dd>
                                                </div>
                                            </dl>
                                        ) : (
                                            <p className="mt-2 text-caption text-muted-foreground">Schedule times unavailable</p>
                                        )}
                                        {Number.isSafeInteger(s.id) && s.id > 0 ? (
                                            <div className="mt-2 flex flex-col gap-2">
                                                {canOpenShift ? (
                                                    <Button asChild variant="outline" size="sm" className="frontline-tap h-auto w-full whitespace-normal py-2">
                                                        <Link href={`/operations/shifts/${s.id}`}
                                                            aria-label={`View shift at ${s.site} on ${d.day} ${d.date}`}>
                                                            <Eye className="h-4 w-4 shrink-0" aria-hidden="true" />
                                                            View shift
                                                        </Link>
                                                    </Button>
                                                ) : null}
                                                {hasValidWindow(s) ? <Button asChild variant="outline" size="sm" className="frontline-tap h-auto w-full whitespace-normal py-2">
                                                    <a href={`/hr/my/time/shifts/${s.id}/calendar`} download={`shift-${s.id}.ics`}
                                                        aria-label={`Download calendar file for ${s.site} on ${d.day} ${d.date}`}>
                                                        <CalendarPlus className="h-4 w-4 shrink-0" aria-hidden="true" />
                                                        Calendar file
                                                    </a>
                                                </Button> : null}
                                            </div>
                                        ) : null}
                                    </div>
                                ))}
                            </div>
                        ))}
                    </div>
                </Card>
            </div>

        </MyHrShell>
    );
}

/** Sum of completed-entry hours today, formatted for the timeline header. */
function todayHoursLabel(entries: TimeEntry[]): string {
    let total = 0;
    for (const entry of entries) {
        if (!entry.clock_out) continue;
        const value = entry.total_hours;
        const hours = typeof value === 'number'
            ? value
            : typeof value === 'string' && /^\d+(?:\.\d+)?$/.test(value.trim())
              ? Number(value)
              : Number.NaN;
        if (!Number.isFinite(hours) || hours < 0) return 'Recorded hours unavailable';
        total += hours;
        if (!Number.isFinite(total)) return 'Recorded hours unavailable';
    }
    return `${total.toFixed(1)} hr recorded`;
}
