import AppLayout from '@/layouts/app-layout';
import PageShell from '@/components/page-shell';
import PageHeader from '@/components/page-header';
import { Head, router } from '@inertiajs/react';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { type BreadcrumbItem } from '@/types';
import { Clock, Play, Square, Timer } from 'lucide-react';

interface TimeEntry {
    id: number;
    entry_date: string;
    clock_in: string;
    clock_out: string | null;
    break_minutes: number;
    total_hours: number | null;
    entry_type: string;
    status: string;
    notes: string | null;
}

interface WeeklySummary {
    week_start: string;
    week_end: string;
    daily_hours: Record<string, number>;
    total_hours: number;
    total_entries: number;
}

interface Props {
    activeClock: { id: number; clock_in: string; notes: string | null } | null;
    todayEntries: TimeEntry[];
    weeklySummary: WeeklySummary;
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'HR', href: '/hr/my' },
    { title: 'My HR', href: '/hr/my' },
    { title: 'My Time', href: '/hr/my/time' },
];

const statusConfig: Record<string, { className: string; label: string }> = {
    active: { className: 'border-blue-500/30 text-blue-400 bg-blue-500/10', label: 'Active' },
    submitted: { className: 'border-yellow-500/30 text-yellow-400 bg-yellow-500/10', label: 'Submitted' },
    approved: { className: 'border-emerald-500/30 text-emerald-400 bg-emerald-500/10', label: 'Approved' },
    rejected: { className: 'border-red-500/30 text-red-400 bg-red-500/10', label: 'Rejected' },
};

const dayLabels = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

export default function MyTime({ activeClock, todayEntries, weeklySummary }: Props) {
    function handleClockIn() {
        router.post('/hr/my/time/clock-in', {}, { preserveScroll: true });
    }

    function handleClockOut() {
        router.post('/hr/my/time/clock-out', {}, { preserveScroll: true });
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="My Time" />

            <PageShell>
                <PageHeader title="My Time" backHref="/hr/my" backLabel="Back to My HR" />

                <div className="grid gap-4 md:grid-cols-2">
                    {/* Clock In/Out */}
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
                                        <span className="text-sm font-medium">
                                            Clocked in since {activeClock.clock_in}
                                        </span>
                                    </div>
                                    {activeClock.notes && (
                                        <p className="text-sm text-muted-foreground">{activeClock.notes}</p>
                                    )}
                                    <Button onClick={handleClockOut} variant="destructive" className="w-full">
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

                    {/* Weekly Total */}
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
                                            style={{ height: `${Math.max(4, (Number(hours) / 10) * 60)}px` }}
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

                {/* Today's Entries */}
                <Card>
                    <CardHeader>
                        <CardTitle>Today's Entries</CardTitle>
                    </CardHeader>
                    <CardContent className="p-0">
                        {todayEntries.length === 0 ? (
                            <div className="py-12 text-center text-muted-foreground">
                                <Clock className="mx-auto mb-3 h-12 w-12 opacity-50" />
                                <p>No entries today.</p>
                            </div>
                        ) : (
                            <table className="w-full text-sm">
                                <thead className="border-b bg-muted/50">
                                    <tr>
                                        <th className="px-4 py-3 text-left font-medium">In</th>
                                        <th className="px-4 py-3 text-left font-medium">Out</th>
                                        <th className="px-4 py-3 text-right font-medium">Break</th>
                                        <th className="px-4 py-3 text-right font-medium">Hours</th>
                                        <th className="px-4 py-3 text-left font-medium">Status</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y">
                                    {todayEntries.map((entry) => {
                                        const config = statusConfig[entry.status] || statusConfig.active;
                                        return (
                                            <tr key={entry.id} className="hover:bg-muted/30">
                                                <td className="px-4 py-3">{entry.clock_in}</td>
                                                <td className="px-4 py-3">{entry.clock_out ?? '-'}</td>
                                                <td className="px-4 py-3 text-right text-muted-foreground">
                                                    {entry.break_minutes > 0 ? `${entry.break_minutes}m` : '-'}
                                                </td>
                                                <td className="px-4 py-3 text-right font-medium">
                                                    {entry.total_hours != null ? `${entry.total_hours}h` : '-'}
                                                </td>
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
                        )}
                    </CardContent>
                </Card>
            </PageShell>
        </AppLayout>
    );
}
