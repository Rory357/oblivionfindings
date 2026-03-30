import AppLayout from '@/layouts/app-layout';
import PageShell from '@/components/page-shell';
import PageHeader from '@/components/page-header';
import { Head, Link, router } from '@inertiajs/react';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Clock, Play, Square, Timer } from 'lucide-react';
import { LaravelPagination } from '@/components/ui/laravel-pagination';

interface TimeEntry {
    id: number;
    user_name: string;
    user_id: number;
    entry_date: string;
    clock_in: string;
    clock_out: string | null;
    break_minutes: number;
    total_hours: number | null;
    entry_type: string;
    status: string;
    notes: string | null;
    project_code: string | null;
    approved_by: string | null;
}

interface WeeklySummary {
    week_start: string;
    week_end: string;
    daily_hours: Record<string, number>;
    total_hours: number;
    total_entries: number;
}

interface Props {
    entries: {
        data: TimeEntry[];
        current_page: number;
        last_page: number;
        per_page: number;
        total: number;
        links: Array<{ url: string | null; label: string; active: boolean }>;
    };
    activeClock: { id: number; clock_in: string; notes: string | null } | null;
    weeklySummary: WeeklySummary;
    filters: {
        status?: string;
        q?: string;
    };
    can: {
        manage?: boolean;
    };
}

const breadcrumbs = [
    { title: 'HR', href: '/hr' },
    { title: 'Time Tracking', href: '/hr/time' },
];

const statusConfig: Record<string, { className: string; label: string }> = {
    active: {
        className: 'border-blue-500/30 text-blue-400 bg-blue-500/10',
        label: 'Active',
    },
    submitted: {
        className: 'border-yellow-500/30 text-yellow-400 bg-yellow-500/10',
        label: 'Submitted',
    },
    approved: {
        className: 'border-emerald-500/30 text-emerald-400 bg-emerald-500/10',
        label: 'Approved',
    },
    rejected: {
        className: 'border-red-500/30 text-red-400 bg-red-500/10',
        label: 'Rejected',
    },
};

const dayLabels = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

export default function TimeIndex({ entries, activeClock, weeklySummary, filters, can }: Props) {
    function handleClockIn() {
        router.post('/hr/time/clock-in', {}, { preserveScroll: true });
    }

    function handleClockOut() {
        router.post('/hr/time/clock-out', {}, { preserveScroll: true });
    }

    function updateFilter(key: string, value: string | null) {
        const newFilters = { ...filters, [key]: value };
        if (value === null || value === 'all') {
            delete newFilters[key as keyof typeof newFilters];
        }
        router.get('/hr/time', newFilters, { preserveState: true, replace: true });
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Time Tracking" />

            <PageShell>
                <PageHeader
                    title="Time Tracking"
                    description="Track working hours, manage time entries, and review timesheets."
                    actions={
                        <div className="flex items-center gap-2">
                            <Button variant="outline" asChild>
                                <Link href="/hr/time/entries">All Entries</Link>
                            </Button>
                            <Button variant="outline" asChild>
                                <Link href="/hr/time/timesheets">Timesheets</Link>
                            </Button>
                        </div>
                    }
                />

                {/* Clock In/Out & Weekly Summary */}
                <div className="grid gap-4 md:grid-cols-2">
                    {/* Clock Status */}
                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2">
                                <Clock className="h-5 w-5" />
                                Clock Status
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            {activeClock ? (
                                <div className="space-y-4">
                                    <div className="flex items-center gap-2">
                                        <div className="h-3 w-3 rounded-full bg-emerald-400 animate-pulse" />
                                        <span className="text-sm font-medium">Clocked in since {activeClock.clock_in}</span>
                                    </div>
                                    {activeClock.notes && (
                                        <p className="text-sm text-muted-foreground">{activeClock.notes}</p>
                                    )}
                                    <Button
                                        onClick={handleClockOut}
                                        variant="destructive"
                                        className="w-full"
                                    >
                                        <Square className="mr-2 h-4 w-4" />
                                        Clock Out
                                    </Button>
                                </div>
                            ) : (
                                <div className="space-y-4">
                                    <div className="flex items-center gap-2">
                                        <div className="h-3 w-3 rounded-full bg-slate-400" />
                                        <span className="text-sm text-muted-foreground">Not clocked in</span>
                                    </div>
                                    <Button onClick={handleClockIn} className="w-full">
                                        <Play className="mr-2 h-4 w-4" />
                                        Clock In
                                    </Button>
                                </div>
                            )}
                        </CardContent>
                    </Card>

                    {/* Weekly Summary */}
                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2">
                                <Timer className="h-5 w-5" />
                                This Week
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="mb-4 text-center">
                                <p className="text-3xl font-bold">{weeklySummary.total_hours}h</p>
                                <p className="text-sm text-muted-foreground">
                                    {weeklySummary.week_start} to {weeklySummary.week_end}
                                </p>
                            </div>
                            <div className="flex items-end justify-between gap-1">
                                {Object.entries(weeklySummary.daily_hours).map(([date, hours], i) => (
                                    <div key={date} className="flex flex-1 flex-col items-center gap-1">
                                        <div
                                            className="w-full rounded bg-primary/20"
                                            style={{
                                                height: `${Math.max(4, (Number(hours) / 10) * 60)}px`,
                                                backgroundColor: Number(hours) > 0 ? undefined : undefined,
                                            }}
                                        >
                                            <div
                                                className="w-full rounded bg-primary"
                                                style={{ height: `${Math.max(0, (Number(hours) / 10) * 60)}px` }}
                                            />
                                        </div>
                                        <span className="text-[10px] text-muted-foreground">
                                            {dayLabels[i] ?? date.slice(5)}
                                        </span>
                                    </div>
                                ))}
                            </div>
                        </CardContent>
                    </Card>
                </div>

                {/* Filters */}
                <div className="flex flex-wrap items-center gap-2 rounded-lg border bg-card p-3">
                    <Select
                        value={filters.status ?? 'all'}
                        onValueChange={(v) => updateFilter('status', v === 'all' ? null : v)}
                    >
                        <SelectTrigger className="w-[140px]">
                            <SelectValue placeholder="All Statuses" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All Statuses</SelectItem>
                            <SelectItem value="active">Active</SelectItem>
                            <SelectItem value="submitted">Submitted</SelectItem>
                            <SelectItem value="approved">Approved</SelectItem>
                            <SelectItem value="rejected">Rejected</SelectItem>
                        </SelectContent>
                    </Select>

                    <Button
                        variant="ghost"
                        size="sm"
                        onClick={() => router.get('/hr/time', {}, { preserveState: true })}
                    >
                        Clear Filters
                    </Button>
                </div>

                {/* Today's Entries */}
                <Card>
                    <CardHeader>
                        <CardTitle>Recent Entries</CardTitle>
                    </CardHeader>
                    <CardContent className="p-0">
                        {entries.data.length === 0 ? (
                            <div className="py-12 text-center text-muted-foreground">
                                <Clock className="mx-auto mb-3 h-12 w-12 opacity-50" />
                                <p>No time entries found.</p>
                            </div>
                        ) : (
                            <div className="overflow-hidden rounded-xl border">
                                <table className="w-full text-sm">
                                    <thead className="border-b bg-slate-50/5">
                                        <tr>
                                            {can.manage && <th className="px-4 py-3 text-left font-medium">Staff</th>}
                                            <th className="px-4 py-3 text-left font-medium">Date</th>
                                            <th className="px-4 py-3 text-left font-medium">In</th>
                                            <th className="px-4 py-3 text-left font-medium">Out</th>
                                            <th className="px-4 py-3 text-right font-medium">Break</th>
                                            <th className="px-4 py-3 text-right font-medium">Hours</th>
                                            <th className="px-4 py-3 text-left font-medium">Type</th>
                                            <th className="px-4 py-3 text-left font-medium">Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {entries.data.map((entry) => {
                                            const config = statusConfig[entry.status] || statusConfig.active;
                                            return (
                                                <tr key={entry.id} className="border-b last:border-b-0 hover:bg-muted/50">
                                                    {can.manage && (
                                                        <td className="px-4 py-3 font-medium">{entry.user_name}</td>
                                                    )}
                                                    <td className="px-4 py-3 text-muted-foreground">{entry.entry_date}</td>
                                                    <td className="px-4 py-3">{entry.clock_in}</td>
                                                    <td className="px-4 py-3">{entry.clock_out ?? '-'}</td>
                                                    <td className="px-4 py-3 text-right text-muted-foreground">
                                                        {entry.break_minutes > 0 ? `${entry.break_minutes}m` : '-'}
                                                    </td>
                                                    <td className="px-4 py-3 text-right font-medium">
                                                        {entry.total_hours != null ? `${entry.total_hours}h` : '-'}
                                                    </td>
                                                    <td className="px-4 py-3 text-muted-foreground capitalize">{entry.entry_type}</td>
                                                    <td className="px-4 py-3">
                                                        <Badge variant="outline" className={config.className}>
                                                            {config.label}
                                                        </Badge>
                                                    </td>
                                                </tr>
                                            );
                                        })}
                                    </tbody>
                                </table>
                            </div>
                        )}
                    </CardContent>
                </Card>

                {/* Pagination */}
                {entries.last_page > 1 && (
                    <div className="flex items-center justify-between">
                        <p className="text-sm text-muted-foreground">
                            Showing {(entries.current_page - 1) * entries.per_page + 1} to{' '}
                            {Math.min(entries.current_page * entries.per_page, entries.total)} of{' '}
                            {entries.total} entries
                        </p>
                        <LaravelPagination links={entries.links} />
                    </div>
                )}
            </PageShell>
        </AppLayout>
    );
}
