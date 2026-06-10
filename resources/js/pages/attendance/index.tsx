import { PageHero, type PageHeroBadge } from '@/components/page';
import PageShell from '@/components/page-shell';
import { ReasonDialog } from '@/components/reason-dialog';
import { TimesheetStatusBadge } from '@/components/timesheet-status-badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import AppLayout from '@/layouts/app-layout';
import { formatDate, formatDateTime, formatTime } from '@/lib/datetime';
import { cn } from '@/lib/utils';
import { edit as editTimesheet } from '@/routes/operations/timesheets';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/react';
import {
    AlertTriangle,
    CalendarDays,
    CheckCircle2,
    Info,
    Link2,
    Timer,
    UserCheck,
} from 'lucide-react';
import { useMemo, useState } from 'react';

type Session = {
    id: number;
    clock_in_at: string;
    clock_out_at: string | null;
    break_minutes: number;
    status: string;
    source: string;
    location: string | null;
    worked_hours: number;
    timesheet_id: number | null;
    timesheet_status: string | null;
};

type OpenSession = {
    id: number;
    clock_in_at: string;
    shift_id: number | null;
    shift_starts_at: string | null;
    shift_ends_at: string | null;
    timesheet_id: number | null;
} | null;

type Props = {
    sessions: {
        data: Session[];
        links: Array<{ url: string | null; label: string; active: boolean }>;
        current_page: number;
        last_page: number;
        per_page: number;
        total: number;
    };
    openSession: OpenSession;
    activeShift: {
        id: number;
        starts_at: string;
        ends_at: string;
        status: string;
        location: string | null;
    } | null;
    eligibleShifts: Array<{
        id: number;
        starts_at: string;
        ends_at: string;
        status: string;
        location: string | null;
        client_name: string;
    }>;
    staff: Array<{ id: number; name: string; email: string }>;
    filters: { user_id?: number | null };
    todayHours: number;
    weekHours: number;
    onClockNow: Array<{
        id: number;
        user_id: number;
        user_name: string | null;
        clock_in_at: string;
        shift_id: number | null;
        shift_location: string | null;
        shift_ends_at: string | null;
        is_stale: boolean;
    }>;
    canManageAny: boolean;
    canClock: boolean;
};

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Attendance', href: '/attendance' },
];

const ANY = '__any__';

// Worker-facing timestamps — one locale (en-NZ), one timezone
// (Pacific/Auckland) via the shared datetime helpers, so session rows read
// the same as shift times everywhere else in the app.
function toLocal(dateString: string | null): string {
    return formatDateTime(dateString);
}

function toTime(dateString: string | null): string {
    return formatTime(dateString);
}

export default function AttendanceIndex({
    sessions,
    openSession,
    activeShift,
    eligibleShifts,
    staff,
    filters,
    todayHours,
    weekHours,
    onClockNow,
    canManageAny,
    canClock,
}: Props) {
    const [breakMinutes, setBreakMinutes] = useState('0');
    const [endTarget, setEndTarget] = useState<Props['onClockNow'][number] | null>(null);
    const [selectedShiftId, setSelectedShiftId] = useState<string>(
        activeShift
            ? String(activeShift.id)
            : eligibleShifts[0]
              ? String(eligibleShifts[0].id)
              : '',
    );

    const { auth } = usePage<SharedData>().props;
    const firstName = (auth?.user?.name ?? '').split(' ')[0] || 'there';

    const selectedUserId = filters.user_id ?? null;
    // "Viewing X" treatment only when a manager filters to someone ELSE —
    // your own sessions read as the personal greeting, even though the
    // controller echoes your id back in filters.user_id.
    const viewedStaff = useMemo(
        () =>
            canManageAny && selectedUserId && selectedUserId !== auth?.user?.id
                ? (staff.find((member) => member.id === selectedUserId) ?? null)
                : null,
        [auth?.user?.id, canManageAny, selectedUserId, staff],
    );

    const totalSessions = sessions.total;
    const eligibleCount = eligibleShifts.length;

    const heroBadges: PageHeroBadge[] = [
        openSession
            ? {
                  icon: CheckCircle2,
                  label: `On the clock since ${toTime(openSession.clock_in_at)}`,
                  tone: 'success' as const,
              }
            : {
                  label: 'Not clocked in',
                  tone: 'default' as const,
                  dot: true,
              },
        ...(eligibleCount > 0
            ? [
                  {
                      icon: CalendarDays,
                      label: `${eligibleCount} eligible shift${eligibleCount === 1 ? '' : 's'} in the clock-in window`,
                      tone: 'info' as const,
                  },
              ]
            : []),
        ...(openSession?.timesheet_id
            ? [
                  {
                      icon: Link2,
                      label: `Synced to timesheet #${openSession.timesheet_id}`,
                      tone: 'default' as const,
                  },
              ]
            : []),
        ...(viewedStaff
            ? [
                  {
                      icon: UserCheck,
                      label: `Viewing ${viewedStaff.name}`,
                      tone: 'warning' as const,
                  },
              ]
            : []),
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Attendance" />

            <PageShell>
                <PageHero
                    category="ops"
                    icon={Timer}
                    title={
                        <span>
                            <span className="mb-2 flex items-center gap-2 text-[10.5px] font-semibold tracking-wider text-primary-foreground/80 uppercase">
                                <span
                                    aria-hidden="true"
                                    className="relative inline-flex h-2 w-2"
                                >
                                    <span className="absolute inset-0 inline-flex h-full w-full animate-ping rounded-full bg-status-success/70" />
                                    <span className="relative inline-flex h-2 w-2 rounded-full bg-status-success ring-2 ring-status-success/30" />
                                </span>
                                Live attendance · synced to timesheets
                            </span>
                            <span className="block">
                                <span className="font-normal text-primary-foreground/80">
                                    {viewedStaff
                                        ? `${viewedStaff.name}'s attendance —`
                                        : `Kia ora ${firstName}, your attendance —`}
                                </span>{' '}
                                <span className="border-b-2 border-primary-foreground/40 pb-0.5">
                                    {formatDate(new Date())}
                                </span>
                            </span>
                        </span>
                    }
                    description={
                        openSession ? (
                            <span>
                                Clocked in since {toTime(openSession.clock_in_at)}
                                {openSession.shift_id
                                    ? ` on shift #${openSession.shift_id}`
                                    : ''}
                                . {todayHours.toFixed(1)}h logged today across{' '}
                                {totalSessions} session
                                {totalSessions === 1 ? '' : 's'} on record.
                            </span>
                        ) : (
                            <span>
                                Not on the clock. {todayHours.toFixed(1)}h logged
                                today, {eligibleCount} eligible shift
                                {eligibleCount === 1 ? '' : 's'} in the clock-in
                                window, and {totalSessions} session
                                {totalSessions === 1 ? '' : 's'} on record.
                            </span>
                        )
                    }
                    meta={[
                        { icon: CalendarDays, label: formatDate(new Date()) },
                        {
                            icon: Timer,
                            label: `${totalSessions} session${totalSessions === 1 ? '' : 's'} on record`,
                        },
                        {
                            icon: Link2,
                            label: 'Sessions sync to timesheets',
                        },
                    ]}
                    badges={heroBadges}
                    stats={[
                        { label: 'Today', value: `${todayHours.toFixed(1)}h` },
                        { label: 'This week', value: `${weekHours.toFixed(1)}h` },
                        {
                            label: 'On the clock',
                            value: openSession ? 'Yes' : 'No',
                            tone: openSession ? 'success' : undefined,
                        },
                        {
                            label: 'Eligible',
                            value: eligibleCount,
                            tone: eligibleCount > 0 ? 'info' : undefined,
                        },
                    ]}
                    actions={
                        canManageAny ? (
                            <Select
                                value={
                                    selectedUserId
                                        ? String(selectedUserId)
                                        : ANY
                                }
                                onValueChange={(v) => {
                                    router.get(
                                        '/attendance',
                                        v === ANY ? {} : { user_id: Number(v) },
                                        { preserveState: true, replace: true },
                                    );
                                }}
                            >
                                <SelectTrigger className="w-56 border-primary-foreground/30 bg-primary-foreground/10 text-primary-foreground shadow-none hover:bg-primary-foreground/20 [&_[data-slot=select-value]]:text-primary-foreground">
                                    <SelectValue placeholder="My sessions" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value={ANY}>
                                        My sessions
                                    </SelectItem>
                                    {staff.map((member) => (
                                        <SelectItem
                                            key={member.id}
                                            value={String(member.id)}
                                        >
                                            {member.name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        ) : null
                    }
                />

                {/* Clock action card */}
                {canClock ? (
                    <Card
                        className={
                            openSession
                                ? 'border-status-success/20'
                                : 'border-primary/20'
                        }
                    >
                        <CardContent className="p-5">
                            <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                                <div>
                                    <div className="flex items-center gap-3">
                                        {openSession ? (
                                            <span className="relative flex h-3 w-3">
                                                <span className="absolute inline-flex h-full w-full animate-ping rounded-full bg-status-success opacity-75" />
                                                <span className="relative inline-flex h-3 w-3 rounded-full bg-status-success" />
                                            </span>
                                        ) : null}
                                        <span className="text-lg font-semibold">
                                            {openSession
                                                ? 'Currently clocked in'
                                                : 'Ready to clock in'}
                                        </span>
                                    </div>
                                    {openSession ? (
                                        <>
                                            <p className="mt-1 text-sm text-muted-foreground">
                                                Since{' '}
                                                {toTime(openSession.clock_in_at)}
                                                {openSession.shift_id
                                                    ? ` · Shift #${openSession.shift_id}`
                                                    : ''}
                                            </p>
                                            {Date.now() -
                                                new Date(
                                                    openSession.clock_in_at,
                                                ).getTime() >
                                            16 * 60 * 60 * 1000 ? (
                                                <p className="mt-1.5 inline-flex items-center gap-1.5 rounded-md bg-status-warning-bg px-2 py-1 text-xs text-status-warning">
                                                    <AlertTriangle className="h-3.5 w-3.5 shrink-0" />
                                                    Open for more than 16 hours
                                                    — likely a missed
                                                    clock-out. Clock out and
                                                    correct the times, or ask a
                                                    coordinator.
                                                </p>
                                            ) : null}
                                        </>
                                    ) : eligibleShifts.length > 0 ? (
                                        <p className="mt-1 text-sm text-muted-foreground">
                                            {eligibleShifts.length} eligible
                                            shift
                                            {eligibleShifts.length !== 1
                                                ? 's'
                                                : ''}{' '}
                                            available
                                        </p>
                                    ) : (
                                        <p className="mt-1 text-sm text-muted-foreground">
                                            No eligible shifts near the current
                                            time
                                        </p>
                                    )}
                                </div>

                                <div className="flex flex-col gap-3 sm:flex-row sm:items-end">
                                    {openSession ? (
                                        <>
                                            <div className="w-full sm:w-36">
                                                <Label className="text-xs text-muted-foreground">
                                                    Break minutes
                                                </Label>
                                                <Input
                                                    type="number"
                                                    min={0}
                                                    max={240}
                                                    className="mt-1"
                                                    value={breakMinutes}
                                                    onChange={(e) =>
                                                        setBreakMinutes(
                                                            e.target.value,
                                                        )
                                                    }
                                                />
                                            </div>
                                            <Button
                                                variant="destructive"
                                                onClick={() =>
                                                    router.post(
                                                        '/attendance/clock-out',
                                                        {
                                                            session_id:
                                                                openSession.id,
                                                            break_minutes:
                                                                Number(
                                                                    breakMinutes ||
                                                                        0,
                                                                ),
                                                        },
                                                        {
                                                            preserveScroll: true,
                                                        },
                                                    )
                                                }
                                            >
                                                Clock out
                                            </Button>
                                        </>
                                    ) : (
                                        <>
                                            {eligibleShifts.length > 0 ? (
                                                <Select
                                                    value={
                                                        selectedShiftId || ANY
                                                    }
                                                    onValueChange={(v) =>
                                                        setSelectedShiftId(
                                                            v === ANY ? '' : v,
                                                        )
                                                    }
                                                >
                                                    <SelectTrigger className="w-56 text-sm">
                                                        <SelectValue placeholder="Select shift" />
                                                    </SelectTrigger>
                                                    <SelectContent>
                                                        {eligibleShifts.map(
                                                            (shift) => (
                                                                <SelectItem
                                                                    key={
                                                                        shift.id
                                                                    }
                                                                    value={String(
                                                                        shift.id,
                                                                    )}
                                                                >
                                                                    {shift.client_name
                                                                        ? `${shift.client_name} — `
                                                                        : ''}
                                                                    {toTime(
                                                                        shift.starts_at,
                                                                    )}
                                                                    –
                                                                    {toTime(
                                                                        shift.ends_at,
                                                                    )}
                                                                </SelectItem>
                                                            ),
                                                        )}
                                                    </SelectContent>
                                                </Select>
                                            ) : null}
                                            <Button
                                                onClick={() =>
                                                    router.post(
                                                        '/attendance/clock-in',
                                                        {
                                                            shift_id:
                                                                selectedShiftId
                                                                    ? Number(
                                                                          selectedShiftId,
                                                                      )
                                                                    : null,
                                                        },
                                                        {
                                                            preserveScroll: true,
                                                        },
                                                    )
                                                }
                                                disabled={
                                                    eligibleShifts.length > 1 &&
                                                    !selectedShiftId
                                                }
                                            >
                                                {selectedShiftId
                                                    ? 'Clock in to shift'
                                                    : 'Clock in'}
                                            </Button>
                                        </>
                                    )}
                                </div>
                            </div>
                        </CardContent>
                    </Card>
                ) : null}

                {/* Manager live board — who is on the clock right now. */}
                {canManageAny && onClockNow.length > 0 ? (
                    <Card>
                        <CardHeader className="flex-row items-center justify-between space-y-0">
                            <CardTitle className="text-base">
                                On the clock now
                                <span className="ml-2 text-sm font-normal text-muted-foreground">
                                    {onClockNow.length} open session
                                    {onClockNow.length === 1 ? '' : 's'}
                                </span>
                            </CardTitle>
                            {onClockNow.some((s) => s.is_stale) ? (
                                <span className="inline-flex items-center gap-1.5 rounded-full border border-status-warning/30 bg-status-warning-bg px-2.5 py-1 text-xs font-medium text-status-warning">
                                    <AlertTriangle className="h-3.5 w-3.5" />
                                    {
                                        onClockNow.filter((s) => s.is_stale)
                                            .length
                                    }{' '}
                                    likely missed clock-out
                                    {onClockNow.filter((s) => s.is_stale)
                                        .length === 1
                                        ? ''
                                        : 's'}
                                </span>
                            ) : null}
                        </CardHeader>
                        <CardContent className="p-0">
                            <ul className="divide-y">
                                {onClockNow.map((s) => (
                                    <li
                                        key={s.id}
                                        className="flex flex-wrap items-center gap-x-4 gap-y-1 px-4 py-2.5 text-sm"
                                    >
                                        <span className="relative flex h-2 w-2 shrink-0">
                                            <span
                                                className={cn(
                                                    'absolute inline-flex h-full w-full rounded-full opacity-75',
                                                    s.is_stale
                                                        ? 'bg-status-warning'
                                                        : 'animate-ping bg-status-success',
                                                )}
                                            />
                                            <span
                                                className={cn(
                                                    'relative inline-flex h-2 w-2 rounded-full',
                                                    s.is_stale
                                                        ? 'bg-status-warning'
                                                        : 'bg-status-success',
                                                )}
                                            />
                                        </span>
                                        {/* eslint-disable-next-line no-restricted-syntax -- inline text link inside a list row; a shadcn Button would add box chrome. */}
                                        <button
                                            type="button"
                                            onClick={() =>
                                                router.get(
                                                    '/attendance',
                                                    { user_id: s.user_id },
                                                    {
                                                        preserveState: true,
                                                        replace: true,
                                                    },
                                                )
                                            }
                                            className="min-w-0 truncate font-medium hover:text-primary hover:underline"
                                        >
                                            {s.user_name ?? `User #${s.user_id}`}
                                        </button>
                                        <span className="text-muted-foreground">
                                            since {toLocal(s.clock_in_at)}
                                        </span>
                                        {s.shift_id ? (
                                            <span className="text-muted-foreground">
                                                Shift #{s.shift_id}
                                                {s.shift_location
                                                    ? ` · ${s.shift_location}`
                                                    : ''}
                                                {s.shift_ends_at
                                                    ? ` · ends ${toTime(s.shift_ends_at)}`
                                                    : ''}
                                            </span>
                                        ) : (
                                            <span className="text-muted-foreground">
                                                No shift linked
                                            </span>
                                        )}
                                        <span className="ml-auto flex items-center gap-2">
                                            {s.is_stale ? (
                                                <span className="inline-flex items-center gap-1 rounded-full bg-status-warning-bg px-2 py-0.5 text-xs font-medium text-status-warning">
                                                    <AlertTriangle className="h-3 w-3" />
                                                    16h+ open
                                                </span>
                                            ) : null}
                                            <Button
                                                variant="outline"
                                                size="sm"
                                                className={cn(
                                                    'h-7 px-2.5 text-xs',
                                                    s.is_stale &&
                                                        'border-status-critical/30 text-status-critical hover:bg-status-critical-bg',
                                                )}
                                                onClick={() => setEndTarget(s)}
                                            >
                                                End session
                                            </Button>
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        </CardContent>
                    </Card>
                ) : null}

                <ReasonDialog
                    open={endTarget !== null}
                    onClose={() => setEndTarget(null)}
                    title={endTarget?.user_name ? `End ${endTarget.user_name}'s session?` : 'End this session?'}
                    description="This closes the open attendance session, ends any running break, and syncs a draft timesheet. The reason is recorded in the audit log."
                    label="Reason"
                    placeholder="e.g. Missed clock-out on Monday"
                    confirmLabel="End session"
                    onConfirm={(reason, done) => {
                        if (!endTarget) return;
                        router.post(
                            `/attendance/sessions/${endTarget.id}/end`,
                            { reason },
                            {
                                preserveScroll: true,
                                onSuccess: () => setEndTarget(null),
                                onFinish: done,
                            },
                        );
                    }}
                />

                {/* Sessions list — mobile cards, desktop table */}
                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">
                            Recent Sessions
                        </CardTitle>
                    </CardHeader>
                    <CardContent className="p-0">
                        {/* Mobile: stacked cards (no horizontal scroll) */}
                        <ul className="divide-y md:hidden">
                            {sessions.data.length === 0 ? (
                                <li className="px-4 py-8 text-center text-sm text-muted-foreground">
                                    No attendance sessions found.
                                </li>
                            ) : (
                                sessions.data.map((session) => (
                                    <li
                                        key={session.id}
                                        className="space-y-2 px-4 py-3 text-sm"
                                    >
                                        <div className="flex items-start justify-between gap-3">
                                            <div className="min-w-0">
                                                <div className="font-medium">
                                                    {toLocal(
                                                        session.clock_in_at,
                                                    )}
                                                </div>
                                                <div className="mt-0.5 text-xs text-muted-foreground">
                                                    {session.clock_out_at
                                                        ? `Out ${toLocal(session.clock_out_at)}`
                                                        : 'Still clocked in'}
                                                </div>
                                            </div>
                                            <span className="shrink-0 rounded-full border bg-muted/60 px-2 py-0.5 text-xs font-medium tabular-nums">
                                                {session.worked_hours.toFixed(
                                                    2,
                                                )}
                                                h
                                            </span>
                                        </div>
                                        <div className="flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-muted-foreground">
                                            <span>
                                                Break {session.break_minutes}m
                                            </span>
                                            {session.timesheet_id ? (
                                                <Link
                                                    href={editTimesheet.url(
                                                        session.timesheet_id,
                                                    )}
                                                    className="inline-flex items-center gap-1.5"
                                                >
                                                    <span className="underline">
                                                        Timesheet #
                                                        {session.timesheet_id}
                                                    </span>
                                                    {session.timesheet_status ? (
                                                        <TimesheetStatusBadge
                                                            status={
                                                                session.timesheet_status
                                                            }
                                                            className="text-[10px]"
                                                        />
                                                    ) : null}
                                                </Link>
                                            ) : (
                                                <span>
                                                    Not synced to timesheet
                                                </span>
                                            )}
                                        </div>
                                    </li>
                                ))
                            )}
                        </ul>

                        {/* Desktop: keep the dense table */}
                        <div className="hidden md:block">
                            <table className="w-full text-sm">
                                <thead className="border-b bg-muted/40">
                                    <tr>
                                        <th className="px-4 py-3 text-left font-medium">
                                            Clock In
                                        </th>
                                        <th className="px-4 py-3 text-left font-medium">
                                            Clock Out
                                        </th>
                                        <th className="px-4 py-3 text-right font-medium">
                                            Break
                                        </th>
                                        <th className="px-4 py-3 text-right font-medium">
                                            Hours
                                        </th>
                                        <th className="px-4 py-3 text-left font-medium">
                                            <Tooltip>
                                                <TooltipTrigger asChild>
                                                    <span className="inline-flex cursor-default items-center gap-1">
                                                        Timesheet
                                                        <Info className="h-3 w-3 text-muted-foreground" />
                                                    </span>
                                                </TooltipTrigger>
                                                <TooltipContent>
                                                    Linked to a timesheet for
                                                    payroll processing
                                                </TooltipContent>
                                            </Tooltip>
                                        </th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y">
                                    {sessions.data.map((session) => (
                                        <tr
                                            key={session.id}
                                            className="transition-colors hover:bg-muted/20"
                                        >
                                            <td className="px-4 py-3">
                                                {toLocal(session.clock_in_at)}
                                            </td>
                                            <td className="px-4 py-3">
                                                {toLocal(session.clock_out_at)}
                                            </td>
                                            <td className="px-4 py-3 text-right tabular-nums">
                                                {session.break_minutes}m
                                            </td>
                                            <td className="px-4 py-3 text-right tabular-nums">
                                                {session.worked_hours.toFixed(
                                                    2,
                                                )}
                                                h
                                            </td>
                                            <td className="px-4 py-3">
                                                {session.timesheet_id ? (
                                                    <Link
                                                        className="inline-flex items-center gap-1.5"
                                                        href={editTimesheet.url(
                                                            session.timesheet_id,
                                                        )}
                                                    >
                                                        <span className="underline">
                                                            #
                                                            {
                                                                session.timesheet_id
                                                            }
                                                        </span>
                                                        {session.timesheet_status ? (
                                                            <TimesheetStatusBadge
                                                                status={
                                                                    session.timesheet_status
                                                                }
                                                                className="text-[10px]"
                                                            />
                                                        ) : null}
                                                    </Link>
                                                ) : (
                                                    <span className="text-muted-foreground">
                                                        Not synced
                                                    </span>
                                                )}
                                            </td>
                                        </tr>
                                    ))}
                                    {sessions.data.length === 0 ? (
                                        <tr>
                                            <td
                                                colSpan={5}
                                                className="px-4 py-8 text-center text-muted-foreground"
                                            >
                                                No attendance sessions found.
                                            </td>
                                        </tr>
                                    ) : null}
                                </tbody>
                            </table>
                        </div>
                    </CardContent>
                </Card>
            </PageShell>
        </AppLayout>
    );
}
