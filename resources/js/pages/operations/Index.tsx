import { BarChart, DonutChart, OPS_COLORS, OpsStatCard } from '@/components/ops-stat-card';
import PageShell from '@/components/page-shell';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { PageHero } from '@/components/page';
import AppLayout from '@/layouts/app-layout';
import { Head, Link } from '@inertiajs/react';
import {
    Activity,
    AlertTriangle,
    ArrowRight,
    CalendarDays,
    CalendarPlus,
    Clock,
    ClipboardCheck,
    FileText,
    Plus,
    TrendingDown,
    TrendingUp,
    UserPlus,
    Users,
} from 'lucide-react';

type Props = {
    stats: {
        active_clients: number;
        total_clients: number;
        new_clients_this_month: number;
        shifts_today_total: number;
        shifts_today: Record<string, number>;
        hours_this_week: number;
        hours_last_week: number;
        timesheets_pending: number;
        timesheets_overdue: number;
        unassigned_shifts: number;
        urgent_unassigned: number;
    };
    client_status_breakdown: Record<string, number>;
    shift_status_breakdown: Record<string, number>;
    timesheet_status_breakdown: Record<string, number>;
    weekly_hours_trend: number[];
    shifts_per_day: Array<{ date: string; count: number }>;
    recent_activity: Array<{
        id: string | number;
        type: string;
        status: string;
        client?: string;
        staff?: string;
        action?: string;
        title?: string;
        starts_at?: string;
        work_date?: string;
        updated_at?: string;
    }>;
};

const STATUS_COLORS: Record<string, string> = {
    active: OPS_COLORS.success,
    inactive: OPS_COLORS.neutral,
    on_hold: OPS_COLORS.warning,
    discharged: OPS_COLORS.muted,
    scheduled: OPS_COLORS.primary,
    in_progress: OPS_COLORS.accent,
    completed: OPS_COLORS.success,
    cancelled: OPS_COLORS.danger,
    draft: OPS_COLORS.muted,
    submitted: OPS_COLORS.warning,
    approved: OPS_COLORS.success,
    rejected: OPS_COLORS.danger,
    returned: OPS_COLORS.neutral,
};

function statusBadgeVariant(status: string): 'default' | 'secondary' | 'destructive' | 'outline' {
    switch (status) {
        case 'completed':
        case 'approved':
        case 'active':
            return 'default';
        case 'cancelled':
        case 'rejected':
            return 'destructive';
        case 'submitted':
        case 'in_progress':
            return 'secondary';
        default:
            return 'outline';
    }
}

function formatRelativeTime(iso: string): string {
    const d = new Date(iso);
    const now = new Date();
    const diff = Math.floor((now.getTime() - d.getTime()) / 1000);
    if (diff < 60) return 'just now';
    if (diff < 3600) return `${Math.floor(diff / 60)}m ago`;
    if (diff < 86400) return `${Math.floor(diff / 3600)}h ago`;
    return `${Math.floor(diff / 86400)}d ago`;
}

function breakdownToSegments(breakdown: Record<string, number>) {
    return Object.entries(breakdown).map(([label, value]) => ({
        label: label.replace(/_/g, ' '),
        value,
        color: STATUS_COLORS[label] ?? OPS_COLORS.muted,
    }));
}

export default function OperationsDashboard({ stats, client_status_breakdown, shift_status_breakdown, timesheet_status_breakdown, weekly_hours_trend, shifts_per_day, recent_activity }: Props) {
    const hoursTrend = stats.hours_this_week - stats.hours_last_week;
    const hoursTrendPct = stats.hours_last_week > 0 ? Math.round((hoursTrend / stats.hours_last_week) * 100) : 0;

    return (
        <AppLayout>
            <Head title="Operations" />
            <PageHero variant="compact"
                title="Operations Dashboard"
                description="Overview of clients, shifts, timesheets, and operational activity."
            />
            <PageShell>
                {/* ── Quick Actions ─────────────────────────────────────── */}
                <div className="mb-6 flex flex-wrap gap-2">
                    <Button asChild size="sm">
                        <Link href="/operations/shifts/create">
                            <CalendarPlus className="mr-1.5 h-3.5 w-3.5" />
                            Create Shift
                        </Link>
                    </Button>
                    <Button asChild size="sm" variant="outline">
                        <Link href="/operations/clients/create">
                            <UserPlus className="mr-1.5 h-3.5 w-3.5" />
                            Add Client
                        </Link>
                    </Button>
                    <Button asChild size="sm" variant="outline">
                        <Link href="/operations/timesheets/create">
                            <Plus className="mr-1.5 h-3.5 w-3.5" />
                            Submit Timesheet
                        </Link>
                    </Button>
                    <Button asChild size="sm" variant="outline">
                        <Link href="/operations/rostering">
                            <CalendarDays className="mr-1.5 h-3.5 w-3.5" />
                            View Roster
                        </Link>
                    </Button>
                </div>

                {/* ── KPI Cards ─────────────────────────────────────────── */}
                <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
                    <OpsStatCard
                        label="Active Clients"
                        value={stats.active_clients}
                        icon={Users}
                        color="indigo"
                        subtitle={`${stats.new_clients_this_month} new this month`}
                        href="/operations/clients"
                    />
                    <OpsStatCard
                        label="Shifts Today"
                        value={stats.shifts_today_total}
                        icon={CalendarDays}
                        color="blue"
                        subtitle={`${stats.shifts_today?.in_progress ?? 0} in progress`}
                        href="/operations/shifts"
                    />
                    <OpsStatCard
                        label="Hours This Week"
                        value={stats.hours_this_week}
                        icon={Clock}
                        color="cyan"
                        subtitle={
                            hoursTrend >= 0
                                ? `+${hoursTrendPct}% vs last week`
                                : `${hoursTrendPct}% vs last week`
                        }
                        trend={weekly_hours_trend}
                    />
                    <OpsStatCard
                        label="Timesheets Pending"
                        value={stats.timesheets_pending}
                        icon={ClipboardCheck}
                        color={stats.timesheets_overdue > 0 ? 'amber' : 'emerald'}
                        subtitle={stats.timesheets_overdue > 0 ? `${stats.timesheets_overdue} overdue` : 'All on track'}
                        href="/operations/timesheets/approvals"
                    />
                    <OpsStatCard
                        label="Unassigned Shifts"
                        value={stats.unassigned_shifts}
                        icon={AlertTriangle}
                        color={stats.urgent_unassigned > 0 ? 'red' : 'slate'}
                        subtitle={stats.urgent_unassigned > 0 ? `${stats.urgent_unassigned} within 24h` : 'None urgent'}
                        href="/operations/rostering"
                    />
                    <OpsStatCard
                        label="Weekly Trend"
                        value={hoursTrend >= 0 ? `+${hoursTrendPct}%` : `${hoursTrendPct}%`}
                        icon={hoursTrend >= 0 ? TrendingUp : TrendingDown}
                        color={hoursTrend >= 0 ? 'emerald' : 'red'}
                        subtitle="Hours vs last week"
                        trend={weekly_hours_trend}
                    />
                </div>

                {/* ── Charts Row ────────────────────────────────────────── */}
                <div className="mt-6 grid gap-4 md:grid-cols-2 lg:grid-cols-3">
                    {/* Client Status */}
                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-sm font-medium">Client Status</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="flex items-center justify-center gap-6">
                                <DonutChart
                                    segments={breakdownToSegments(client_status_breakdown)}
                                    centerValue={stats.total_clients}
                                    centerLabel="Total"
                                    size={130}
                                    strokeWidth={16}
                                />
                                <div className="space-y-1.5">
                                    {Object.entries(client_status_breakdown).map(([status, count]) => (
                                        <div key={status} className="flex items-center gap-2">
                                            <div
                                                className="h-2.5 w-2.5 rounded-full"
                                                style={{ backgroundColor: STATUS_COLORS[status] ?? OPS_COLORS.muted }}
                                            />
                                            <span className="text-xs capitalize text-muted-foreground">
                                                {status.replace(/_/g, ' ')}
                                            </span>
                                            <span className="ml-auto text-xs font-medium tabular-nums">{count}</span>
                                        </div>
                                    ))}
                                </div>
                            </div>
                        </CardContent>
                    </Card>

                    {/* Shift Status */}
                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-sm font-medium">Shifts This Week</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="flex items-center justify-center gap-6">
                                <DonutChart
                                    segments={breakdownToSegments(shift_status_breakdown)}
                                    centerValue={Object.values(shift_status_breakdown).reduce((a, b) => a + b, 0)}
                                    centerLabel="Total"
                                    size={130}
                                    strokeWidth={16}
                                />
                                <div className="space-y-1.5">
                                    {Object.entries(shift_status_breakdown).map(([status, count]) => (
                                        <div key={status} className="flex items-center gap-2">
                                            <div
                                                className="h-2.5 w-2.5 rounded-full"
                                                style={{ backgroundColor: STATUS_COLORS[status] ?? OPS_COLORS.muted }}
                                            />
                                            <span className="text-xs capitalize text-muted-foreground">
                                                {status.replace(/_/g, ' ')}
                                            </span>
                                            <span className="ml-auto text-xs font-medium tabular-nums">{count}</span>
                                        </div>
                                    ))}
                                </div>
                            </div>
                        </CardContent>
                    </Card>

                    {/* Timesheet Pipeline */}
                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-sm font-medium">Timesheet Pipeline</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="flex items-center justify-center gap-6">
                                <DonutChart
                                    segments={breakdownToSegments(timesheet_status_breakdown)}
                                    centerValue={Object.values(timesheet_status_breakdown).reduce((a, b) => a + b, 0)}
                                    centerLabel="30 days"
                                    size={130}
                                    strokeWidth={16}
                                />
                                <div className="space-y-1.5">
                                    {Object.entries(timesheet_status_breakdown).map(([status, count]) => (
                                        <div key={status} className="flex items-center gap-2">
                                            <div
                                                className="h-2.5 w-2.5 rounded-full"
                                                style={{ backgroundColor: STATUS_COLORS[status] ?? OPS_COLORS.muted }}
                                            />
                                            <span className="text-xs capitalize text-muted-foreground">
                                                {status.replace(/_/g, ' ')}
                                            </span>
                                            <span className="ml-auto text-xs font-medium tabular-nums">{count}</span>
                                        </div>
                                    ))}
                                </div>
                            </div>
                        </CardContent>
                    </Card>
                </div>

                {/* ── Bottom Row: Shifts Bar Chart + Activity Feed ─────── */}
                <div className="mt-6 grid gap-4 lg:grid-cols-5">
                    {/* Shifts Per Day */}
                    <Card className="lg:col-span-2">
                        <CardHeader className="pb-2">
                            <CardTitle className="text-sm font-medium">Shifts — Next 7 Days</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <BarChart
                                data={shifts_per_day.map((d) => ({ label: d.date, value: d.count }))}
                                height={140}
                                barColor={OPS_COLORS.primary}
                            />
                        </CardContent>
                    </Card>

                    {/* Recent Activity */}
                    <Card className="lg:col-span-3">
                        <CardHeader className="flex flex-row items-center justify-between pb-2">
                            <CardTitle className="text-sm font-medium">Recent Activity</CardTitle>
                            <Button asChild variant="ghost" size="sm" className="h-7 text-xs">
                                <Link href="/operations/activity">
                                    View all <ArrowRight className="ml-1 h-3 w-3" />
                                </Link>
                            </Button>
                        </CardHeader>
                        <CardContent>
                            <div className="space-y-2">
                                {recent_activity.length === 0 && (
                                    <p className="py-4 text-center text-xs text-muted-foreground">No recent activity</p>
                                )}
                                {recent_activity.slice(0, 8).map((item) => (
                                    <div
                                        key={item.id}
                                        className="flex items-center gap-3 rounded-lg border border-transparent px-2 py-1.5 transition-colors hover:border-border hover:bg-muted/30"
                                    >
                                        <div className="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-muted/50">
                                            {item.type === 'shift' && <CalendarDays className="h-3.5 w-3.5 text-muted-foreground" />}
                                            {item.type === 'timesheet' && <Clock className="h-3.5 w-3.5 text-muted-foreground" />}
                                            {item.type === 'client' && <Users className="h-3.5 w-3.5 text-muted-foreground" />}
                                        </div>
                                        <div className="min-w-0 flex-1">
                                            <div className="flex items-center gap-2">
                                                <span className="truncate text-xs font-medium">
                                                    {item.type === 'shift' && `Shift ${item.status}`}
                                                    {item.type === 'timesheet' && `Timesheet ${item.status}`}
                                                    {item.type === 'client' && 'New client'}
                                                </span>
                                                <Badge variant={statusBadgeVariant(item.status)} className="h-4 px-1.5 text-[9px]">
                                                    {item.status}
                                                </Badge>
                                            </div>
                                            <p className="truncate text-[10px] text-muted-foreground">
                                                {item.staff && item.client
                                                    ? `${item.staff} — ${item.client}`
                                                    : item.client ?? item.staff ?? ''}
                                            </p>
                                        </div>
                                        <span className="shrink-0 text-[10px] text-muted-foreground">
                                            {item.updated_at ? formatRelativeTime(item.updated_at) : ''}
                                        </span>
                                    </div>
                                ))}
                            </div>
                        </CardContent>
                    </Card>
                </div>
            </PageShell>
        </AppLayout>
    );
}
