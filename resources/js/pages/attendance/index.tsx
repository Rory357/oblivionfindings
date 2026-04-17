import FleetHero from '@/components/fleet-hero';
import PageShell from '@/components/page-shell';
import { TimesheetStatusBadge } from '@/components/timesheet-status-badge';
import { OpsStatCard } from '@/components/ops-stat-card';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { useState, useMemo } from 'react';
import { Clock, Timer, CalendarDays, Activity, Info } from 'lucide-react';
import { Tooltip, TooltipTrigger, TooltipContent } from '@/components/ui/tooltip';

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
    canManageAny: boolean;
    canClock: boolean;
};

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Attendance', href: '/attendance' },
];

const ANY = '__any__';

function toLocal(dateString: string | null): string {
    if (!dateString) return '—';
    return new Date(dateString).toLocaleString();
}

function toTime(dateString: string | null): string {
    if (!dateString) return '—';
    return new Date(dateString).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
}

export default function AttendanceIndex({
    sessions,
    openSession,
    activeShift,
    eligibleShifts,
    staff,
    filters,
    todayHours,
    canManageAny,
    canClock,
}: Props) {
    const [breakMinutes, setBreakMinutes] = useState('0');
    const [selectedShiftId, setSelectedShiftId] = useState<string>(
        activeShift
            ? String(activeShift.id)
            : eligibleShifts[0]
              ? String(eligibleShifts[0].id)
              : '',
    );

    const selectedUserId = filters.user_id ?? null;

    const sessionCount = sessions.data.length;
    const syncedCount = useMemo(() => sessions.data.filter((s) => s.timesheet_id).length, [sessions.data]);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Attendance" />

            <PageShell>
                <FleetHero
                    title="Attendance"
                    description="Clock in and out of shifts, and track attendance sessions."
                    icon={<Timer className="h-7 w-7 text-white" />}
                    actions={
                        canManageAny ? (
                            <Select
                                value={selectedUserId ? String(selectedUserId) : ANY}
                                onValueChange={(v) => {
                                    router.get('/attendance', v === ANY ? {} : { user_id: Number(v) }, { preserveState: true, replace: true });
                                }}
                            >
                                <SelectTrigger className="w-56 border-white/30 bg-white/10 text-white shadow-none hover:bg-white/20 [&_[data-slot=select-value]]:text-white">
                                    <SelectValue placeholder="My sessions" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value={ANY}>My sessions</SelectItem>
                                    {staff.map((member) => (
                                        <SelectItem key={member.id} value={String(member.id)}>{member.name}</SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        ) : null
                    }
                />

                {/* Stat cards */}
                <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <OpsStatCard label="Today's Hours" value={`${todayHours.toFixed(1)}h`} icon={Clock} color="indigo" />
                    <OpsStatCard
                        label="Status"
                        value={openSession ? 'Clocked In' : 'Clocked Out'}
                        icon={Activity}
                        color={openSession ? 'emerald' : 'slate'}
                    />
                    <OpsStatCard label="Sessions" value={sessionCount} icon={Timer} color="blue" />
                    <Tooltip>
                        <TooltipTrigger asChild>
                            <div>
                                <OpsStatCard label="Eligible Shifts" value={eligibleShifts.length} icon={CalendarDays} color="violet" />
                            </div>
                        </TooltipTrigger>
                        <TooltipContent>Assigned shifts within the clock-in window</TooltipContent>
                    </Tooltip>
                </div>

                {/* Clock action card */}
                {canClock ? (
                    <Card className={openSession ? 'border-emerald-500/20' : 'border-primary/20'}>
                        <CardContent className="p-5">
                            <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                                <div>
                                    <div className="flex items-center gap-3">
                                        {openSession ? (
                                            <span className="relative flex h-3 w-3">
                                                <span className="absolute inline-flex h-full w-full animate-ping rounded-full bg-emerald-400 opacity-75" />
                                                <span className="relative inline-flex h-3 w-3 rounded-full bg-emerald-500" />
                                            </span>
                                        ) : null}
                                        <span className="text-lg font-semibold">
                                            {openSession ? 'Currently clocked in' : 'Ready to clock in'}
                                        </span>
                                    </div>
                                    {openSession ? (
                                        <p className="mt-1 text-sm text-muted-foreground">
                                            Since {toTime(openSession.clock_in_at)}
                                            {openSession.shift_id ? ` · Shift #${openSession.shift_id}` : ''}
                                        </p>
                                    ) : eligibleShifts.length > 0 ? (
                                        <p className="mt-1 text-sm text-muted-foreground">
                                            {eligibleShifts.length} eligible shift{eligibleShifts.length !== 1 ? 's' : ''} available
                                        </p>
                                    ) : (
                                        <p className="mt-1 text-sm text-muted-foreground">No eligible shifts near the current time</p>
                                    )}
                                </div>

                                <div className="flex flex-col gap-3 sm:flex-row sm:items-end">
                                    {openSession ? (
                                        <>
                                            <div className="w-full sm:w-36">
                                                <Label className="text-xs text-muted-foreground">Break minutes</Label>
                                                <Input type="number" min={0} max={240} className="mt-1" value={breakMinutes} onChange={(e) => setBreakMinutes(e.target.value)} />
                                            </div>
                                            <Button
                                                variant="destructive"
                                                onClick={() =>
                                                    router.post('/attendance/clock-out', {
                                                        session_id: openSession.id,
                                                        break_minutes: Number(breakMinutes || 0),
                                                    }, { preserveScroll: true })
                                                }
                                            >
                                                Clock out
                                            </Button>
                                        </>
                                    ) : (
                                        <>
                                            {eligibleShifts.length > 0 ? (
                                                <Select value={selectedShiftId || ANY} onValueChange={(v) => setSelectedShiftId(v === ANY ? '' : v)}>
                                                    <SelectTrigger className="w-56 text-sm">
                                                        <SelectValue placeholder="Select shift" />
                                                    </SelectTrigger>
                                                    <SelectContent>
                                                        {eligibleShifts.map((shift) => (
                                                            <SelectItem key={shift.id} value={String(shift.id)}>
                                                                {shift.client_name ? `${shift.client_name} — ` : ''}{toTime(shift.starts_at)}–{toTime(shift.ends_at)}
                                                            </SelectItem>
                                                        ))}
                                                    </SelectContent>
                                                </Select>
                                            ) : null}
                                            <Button
                                                onClick={() =>
                                                    router.post('/attendance/clock-in', {
                                                        shift_id: selectedShiftId ? Number(selectedShiftId) : null,
                                                    }, { preserveScroll: true })
                                                }
                                                disabled={eligibleShifts.length > 1 && !selectedShiftId}
                                            >
                                                {selectedShiftId ? 'Clock in to shift' : 'Clock in'}
                                            </Button>
                                        </>
                                    )}
                                </div>
                            </div>
                        </CardContent>
                    </Card>
                ) : null}

                {/* Sessions list — mobile cards, desktop table */}
                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Recent Sessions</CardTitle>
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
                                    <li key={session.id} className="space-y-2 px-4 py-3 text-sm">
                                        <div className="flex items-start justify-between gap-3">
                                            <div className="min-w-0">
                                                <div className="font-medium">
                                                    {toLocal(session.clock_in_at)}
                                                </div>
                                                <div className="mt-0.5 text-xs text-muted-foreground">
                                                    {session.clock_out_at
                                                        ? `Out ${toLocal(session.clock_out_at)}`
                                                        : 'Still clocked in'}
                                                </div>
                                            </div>
                                            <span className="shrink-0 rounded-full border bg-muted/60 px-2 py-0.5 text-xs font-medium tabular-nums">
                                                {session.worked_hours.toFixed(2)}h
                                            </span>
                                        </div>
                                        <div className="flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-muted-foreground">
                                            <span>Break {session.break_minutes}m</span>
                                            {session.timesheet_id ? (
                                                <Link
                                                    href={`/timesheets/${session.timesheet_id}/edit`}
                                                    className="inline-flex items-center gap-1.5"
                                                >
                                                    <span className="underline">Timesheet #{session.timesheet_id}</span>
                                                    {session.timesheet_status ? (
                                                        <TimesheetStatusBadge status={session.timesheet_status} className="text-[10px]" />
                                                    ) : null}
                                                </Link>
                                            ) : (
                                                <span>Not synced to timesheet</span>
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
                                        <th className="px-4 py-3 text-left font-medium">Clock In</th>
                                        <th className="px-4 py-3 text-left font-medium">Clock Out</th>
                                        <th className="px-4 py-3 text-right font-medium">Break</th>
                                        <th className="px-4 py-3 text-right font-medium">Hours</th>
                                        <th className="px-4 py-3 text-left font-medium">
                                            <Tooltip>
                                                <TooltipTrigger asChild>
                                                    <span className="inline-flex cursor-default items-center gap-1">
                                                        Timesheet
                                                        <Info className="h-3 w-3 text-muted-foreground" />
                                                    </span>
                                                </TooltipTrigger>
                                                <TooltipContent>Linked to a timesheet for payroll processing</TooltipContent>
                                            </Tooltip>
                                        </th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y">
                                    {sessions.data.map((session) => (
                                        <tr key={session.id} className="transition-colors hover:bg-muted/20">
                                            <td className="px-4 py-3">{toLocal(session.clock_in_at)}</td>
                                            <td className="px-4 py-3">{toLocal(session.clock_out_at)}</td>
                                            <td className="px-4 py-3 text-right tabular-nums">{session.break_minutes}m</td>
                                            <td className="px-4 py-3 text-right tabular-nums">{session.worked_hours.toFixed(2)}h</td>
                                            <td className="px-4 py-3">
                                                {session.timesheet_id ? (
                                                    <Link className="inline-flex items-center gap-1.5" href={`/timesheets/${session.timesheet_id}/edit`}>
                                                        <span className="underline">#{session.timesheet_id}</span>
                                                        {session.timesheet_status ? (
                                                            <TimesheetStatusBadge status={session.timesheet_status} className="text-[10px]" />
                                                        ) : null}
                                                    </Link>
                                                ) : (
                                                    <span className="text-muted-foreground">Not synced</span>
                                                )}
                                            </td>
                                        </tr>
                                    ))}
                                    {sessions.data.length === 0 ? (
                                        <tr>
                                            <td colSpan={5} className="px-4 py-8 text-center text-muted-foreground">
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
