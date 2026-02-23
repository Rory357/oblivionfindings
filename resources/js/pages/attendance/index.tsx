import AppLayout from '@/layouts/app-layout';
import { Head, Link, router } from '@inertiajs/react';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { type BreadcrumbItem } from '@/types';
import { useState } from 'react';

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
    staff: Array<{ id: number; name: string; email: string }>;
    filters: { user_id?: number | null };
    todayHours: number;
    canManageAny: boolean;
    canClock: boolean;
};

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Attendance', href: '/attendance' },
];

function toLocal(dateString: string | null): string {
    if (!dateString) {
        return '-';
    }

    return new Date(dateString).toLocaleString();
}

export default function AttendanceIndex({
    sessions,
    openSession,
    activeShift,
    staff,
    filters,
    todayHours,
    canManageAny,
    canClock,
}: Props) {
    const [breakMinutes, setBreakMinutes] = useState('0');

    const selectedUserId = filters.user_id ?? null;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Attendance" />
            <div className="flex flex-col gap-6 p-6">
                <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h1 className="text-2xl font-bold">Attendance</h1>
                        <p className="text-sm text-muted-foreground">
                            Clock in/out sessions and timesheet sync.
                        </p>
                    </div>
                    {canManageAny ? (
                        <select
                            className="w-full rounded-md border bg-background p-2 text-sm sm:w-72"
                            value={selectedUserId ?? ''}
                            onChange={(event) => {
                                const value = event.target.value;
                                router.get('/attendance', value ? { user_id: Number(value) } : {}, { preserveState: true, replace: true });
                            }}
                        >
                            <option value="">My sessions</option>
                            {staff.map((member) => (
                                <option key={member.id} value={member.id}>
                                    {member.name}
                                </option>
                            ))}
                        </select>
                    ) : null}
                </div>

                <div className="grid gap-4 md:grid-cols-3">
                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-sm text-muted-foreground">Today</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <p className="text-2xl font-semibold">{todayHours.toFixed(2)}h</p>
                            <p className="text-xs text-muted-foreground">Total tracked today</p>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-sm text-muted-foreground">Current Session</CardTitle>
                        </CardHeader>
                        <CardContent>
                            {openSession ? (
                                <div className="space-y-2">
                                    <p className="text-sm">Clocked in at {toLocal(openSession.clock_in_at)}</p>
                                    <p className="text-xs text-muted-foreground">
                                        Session is in progress
                                    </p>
                                    {openSession.shift_id ? (
                                        <p className="text-xs text-muted-foreground">
                                            Shift #{openSession.shift_id}
                                        </p>
                                    ) : null}
                                </div>
                            ) : (
                                <p className="text-sm text-muted-foreground">No open session.</p>
                            )}
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-sm text-muted-foreground">Suggested Shift</CardTitle>
                        </CardHeader>
                        <CardContent>
                            {activeShift ? (
                                <div className="space-y-1 text-sm">
                                    <p>Shift #{activeShift.id}</p>
                                    <p className="text-xs text-muted-foreground">
                                        {toLocal(activeShift.starts_at)} to {toLocal(activeShift.ends_at)}
                                    </p>
                                    {activeShift.location ? (
                                        <p className="text-xs text-muted-foreground">{activeShift.location}</p>
                                    ) : null}
                                </div>
                            ) : (
                                <p className="text-sm text-muted-foreground">No upcoming assigned shift.</p>
                            )}
                        </CardContent>
                    </Card>
                </div>

                {canClock ? (
                    <Card>
                        <CardHeader>
                            <CardTitle>Clock Actions</CardTitle>
                        </CardHeader>
                        <CardContent>
                            {openSession ? (
                                <div className="flex flex-col gap-3 sm:flex-row sm:items-end">
                                    <div className="w-full sm:w-48">
                                        <div className="mb-1 text-xs text-muted-foreground">Break minutes</div>
                                        <Input
                                            type="number"
                                            min={0}
                                            max={240}
                                            value={breakMinutes}
                                            onChange={(event) => setBreakMinutes(event.target.value)}
                                        />
                                    </div>
                                    <Button
                                        onClick={() =>
                                            router.post('/attendance/clock-out', {
                                                session_id: openSession.id,
                                                break_minutes: Number(breakMinutes || 0),
                                            })
                                        }
                                    >
                                        Clock Out
                                    </Button>
                                </div>
                            ) : (
                                <Button
                                    onClick={() =>
                                        router.post('/attendance/clock-in', {
                                            shift_id: activeShift?.id ?? null,
                                        })
                                    }
                                >
                                    Clock In
                                </Button>
                            )}
                        </CardContent>
                    </Card>
                ) : null}

                <Card>
                    <CardHeader>
                        <CardTitle>Recent Sessions</CardTitle>
                    </CardHeader>
                    <CardContent className="p-0">
                        <table className="w-full text-sm">
                            <thead className="border-b bg-muted/50">
                                <tr>
                                    <th className="px-4 py-3 text-left font-medium">Clock In</th>
                                    <th className="px-4 py-3 text-left font-medium">Clock Out</th>
                                    <th className="px-4 py-3 text-right font-medium">Break</th>
                                    <th className="px-4 py-3 text-right font-medium">Hours</th>
                                    <th className="px-4 py-3 text-left font-medium">Timesheet</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y">
                                {sessions.data.map((session) => (
                                    <tr key={session.id} className="hover:bg-muted/20">
                                        <td className="px-4 py-3">{toLocal(session.clock_in_at)}</td>
                                        <td className="px-4 py-3">{toLocal(session.clock_out_at)}</td>
                                        <td className="px-4 py-3 text-right">{session.break_minutes}m</td>
                                        <td className="px-4 py-3 text-right">{session.worked_hours.toFixed(2)}h</td>
                                        <td className="px-4 py-3">
                                            {session.timesheet_id ? (
                                                <Link className="underline" href={`/timesheets/${session.timesheet_id}/edit`}>
                                                    #{session.timesheet_id} ({session.timesheet_status})
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
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
