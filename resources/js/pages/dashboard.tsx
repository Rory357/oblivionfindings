import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Tabs } from '@/components/ui/tabs';

import AppLayout from '@/layouts/app-layout';
import { dashboard } from '@/routes';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/react';

import {
    ActivityTimeline,
    type ActivityEventLite,
} from '@/components/dashboard/activity-timeline';
import { DashboardAnalytics } from '@/components/dashboard/analytics';
import { DonutChart } from '@/components/dashboard/donut-chart';
import { KpiCard } from '@/components/dashboard/kpi-card';
import { ShiftTimeline, type ShiftLite } from '@/components/dashboard/timeline';
import { MyDayList, type MyDayItem } from '@/components/workstream/my-day-list';

import {
    BarChart,
    Bar,
    XAxis,
    YAxis,
    CartesianGrid,
    Tooltip as RechartsTooltip,
    ResponsiveContainer,
    LineChart,
    Line,
} from 'recharts';

import {
    Users,
    ClipboardList,
    Clock,
    ShieldAlert,
    CalendarDays,
    Timer,
    CheckCircle2,
    ListTodo,
    Building2,
    Briefcase,
    FileWarning,
    Activity,
    Pill,
} from 'lucide-react';

/* -------------------------------------------------------------------------- */
/*  Breadcrumbs                                                                */
/* -------------------------------------------------------------------------- */

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: dashboard().url },
];

/* -------------------------------------------------------------------------- */
/*  Types                                                                      */
/* -------------------------------------------------------------------------- */

type ClientLite = {
    id: number;
    first_name: string;
    last_name: string;
    status?: string | null;
};

type TimesheetLite = {
    id: number;
    status: string;
    work_date: string;
    client?: ClientLite | null;
    created_at?: string;
};

type HrFeedPostLite = {
    id: number;
    post_type: string;
    content: string;
    created_at: string;
    user?: { id: number; name: string } | null;
};

type ExpiringComplianceItem = {
    user_id: number;
    user_name: string;
    requirement_name: string;
    expires_at: string;
};

type DepartmentBreakdown = {
    department: string;
    count: number;
};

type Props = {
    mode: 'staff' | 'manager' | 'client' | 'hr_admin';
    filters?: {
        range?: 'today' | 'week';
        status?: string;
        client_id?: number | null;
    };
    client?: {
        id: number;
        first_name: string;
        last_name: string;
        status?: string | null;
    } | null;
    assignedStaff?: { id: number; name: string; email?: string }[];
    assignedClients?: ClientLite[];
    myDayItems?: MyDayItem[];
    todayShifts: ShiftLite[];
    upcomingShifts?: ShiftLite[];
    upcomingEvents?: ActivityEventLite[];
    todayTimesheets?: TimesheetLite[];
    managerSummary?: {
        shiftsTodayCount: number;
        staffWorkingTodayCount: number;
        timesheetsPendingCount: number;
        staffSparkline?: number[];
    } | null;
    incidentKpis?: {
        incidentsLast30: number;
        incidentsHighLast30: number;
        followupsOpen: number;
        followupsOverdue: number;
        reviewedLast30: number;
        unreviewedLast30: number;
    } | null;
    analytics?: {
        shiftSeries?: Array<{
            date: string;
            count: number;
            hours: number;
            status?: Record<string, number>;
        }>;
        shiftSeries30?: Array<{
            date: string;
            count: number;
            hours: number;
            status?: Record<string, number>;
        }>;
        incidentSeries?: Array<{ date: string; count: number }>;
        incidentSeries30?: Array<{ date: string; count: number }>;
        incidentBySeverity30?: Array<{ severity: string; count: number }>;
        timesheetByStatus?: Array<{ status: string; count: number }>;
        timesheetSeries30?: Array<{
            date: string;
            count: number;
            hours: number;
        }>;
    } | null;
    hrWidgets?: {
        pending_leave: number;
        expiring_compliance: number;
        pending_signatures: number;
        due_attestations: number;
    } | null;
    /* HR Admin mode props */
    hrAdmin?: {
        headcount: number;
        headcountTrend?: number[];
        vacancies: number;
        pendingLeave: number;
        complianceScore: number;
        headcountSeries?: Array<{ month: string; count: number }>;
        departmentBreakdown?: DepartmentBreakdown[];
        recentFeedPosts?: HrFeedPostLite[];
        expiringCompliance?: ExpiringComplianceItem[];
    } | null;
    /* Staff-specific */
    staffKpis?: {
        myShiftsToday: number;
        leaveBalance: number;
        compliancePercent: number;
        pendingTasks: number;
    } | null;
    /* eMAR */
    emarWidgets?: {
        adminRate: number;
        pending: number;
        activeAlerts: number;
        overdueReviews: number;
        lowStock: number;
    } | null;
};

function fullName(c: ClientLite) {
    return `${c.first_name} ${c.last_name}`;
}

function formatShortDate(iso: string) {
    const d = new Date(iso);
    return d.toLocaleDateString(undefined, { month: 'short', day: 'numeric' });
}

/* -------------------------------------------------------------------------- */
/*  Severity color map for donut                                               */
/* -------------------------------------------------------------------------- */

const SEVERITY_COLORS: Record<string, string> = {
    low: '#a78bfa',
    medium: '#8b5cf6',
    high: '#7c3aed',
    critical: '#5b21b6',
    unspecified: '#c4b5fd',
};

const DEPT_COLORS = [
    '#8b5cf6', '#a78bfa', '#7c3aed', '#c084fc',
    '#6d28d9', '#ddd6fe', '#5b21b6', '#ede9fe',
];

/* -------------------------------------------------------------------------- */
/*  Manager Dashboard                                                          */
/* -------------------------------------------------------------------------- */

function ManagerDashboard({ props }: { props: Props }) {
    const { labels } = usePage().props as any;
    const clientLabelPlural = labels?.['client.plural'] ?? 'Clients';

    const shiftSeries = props.analytics?.shiftSeries ?? [];
    const shiftSeries30 = props.analytics?.shiftSeries30 ?? [];
    const incidentSeries30 = props.analytics?.incidentSeries30 ?? [];
    const incidentBySeverity30 = props.analytics?.incidentBySeverity30 ?? [];
    const timesheetByStatus = props.analytics?.timesheetByStatus ?? [];
    const timesheetSeries30 = props.analytics?.timesheetSeries30 ?? [];

    const summary = props.managerSummary;
    const incidents = props.incidentKpis;

    // Build bar chart data from shift series (use 7-day by default)
    const barData = (shiftSeries.length ? shiftSeries : shiftSeries30).map((d) => ({
        name: formatShortDate(d.date),
        shifts: d.count,
        hours: Math.round((d.hours ?? 0) * 10) / 10,
    }));

    // Build severity donut data
    const severityDonut = incidentBySeverity30.map((d) => ({
        label: d.severity || 'unspecified',
        value: d.count,
        color: SEVERITY_COLORS[d.severity] ?? '#c4b5fd',
    }));

    const severityTotal = severityDonut.reduce((s, d) => s + d.value, 0);

    const filters = props.filters ?? { range: 'week', status: 'all', client_id: null };

    function updateFilters(next: Partial<typeof filters>) {
        router.get(
            '/dashboard',
            {
                range: next.range ?? filters.range,
                status: next.status ?? filters.status,
                client_id: Object.prototype.hasOwnProperty.call(next, 'client_id')
                    ? (next as any).client_id
                    : filters.client_id,
            },
            { preserveScroll: true, preserveState: true, replace: true },
        );
    }

    const shiftsForWorkTab = [
        ...(props.todayShifts ?? []),
        ...(props.upcomingShifts ?? []),
    ];

    return (
        <div className="space-y-6">
            {/* Row 1: KPI cards */}
            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <KpiCard
                    label="Staff Active Today"
                    value={summary?.staffWorkingTodayCount ?? 0}
                    icon={Users}
                    sparklineData={summary?.staffSparkline}
                    href="/shifts"
                />
                <KpiCard
                    label="Pending Timesheets"
                    value={summary?.timesheetsPendingCount ?? 0}
                    icon={ClipboardList}
                    href="/timesheets"
                />
                <KpiCard
                    label="Shifts Today"
                    value={summary?.shiftsTodayCount ?? 0}
                    icon={Clock}
                    href="/shifts"
                />
                <KpiCard
                    label="Incidents (30d)"
                    value={incidents?.incidentsLast30 ?? 0}
                    icon={ShieldAlert}
                    href="/incidents"
                    trend={
                        incidents && incidents.incidentsHighLast30 > 0
                            ? {
                                  value: incidents.incidentsHighLast30,
                                  label: 'high severity',
                                  direction: 'up',
                              }
                            : undefined
                    }
                />
            </div>

            {/* HR widgets if available */}
            {props.hrWidgets && (
                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <KpiCard
                        label="Pending Leave"
                        value={props.hrWidgets.pending_leave}
                        icon={CalendarDays}
                        href="/hr/leave"
                    />
                    <KpiCard
                        label="Expiring Compliance"
                        value={props.hrWidgets.expiring_compliance}
                        icon={FileWarning}
                        href="/hr/compliance"
                    />
                    <KpiCard
                        label="Pending Signatures"
                        value={props.hrWidgets.pending_signatures}
                        icon={ClipboardList}
                        href="/hr/signatures/pending"
                    />
                    <KpiCard
                        label="Due Attestations"
                        value={props.hrWidgets.due_attestations}
                        icon={CheckCircle2}
                        href="/hr/my/policies"
                    />
                </div>
            )}

            {/* eMAR widget */}
            {props.emarWidgets && (
                <Link href="/emar" className="block">
                    <Card className="transition-all hover:border-primary/30 hover:shadow-md hover:-translate-y-0.5">
                        <CardHeader className="pb-2">
                            <CardTitle className="flex items-center gap-2 text-sm">
                                <div className="flex h-7 w-7 items-center justify-center rounded-lg bg-indigo-100 dark:bg-indigo-900/40">
                                    <Pill className="h-3.5 w-3.5 text-indigo-600 dark:text-indigo-400" />
                                </div>
                                Medications (eMAR)
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="grid grid-cols-1 sm:grid-cols-3 gap-4 text-center">
                                <div>
                                    <p className="text-2xl font-bold text-emerald-600">{props.emarWidgets.adminRate}%</p>
                                    <p className="text-[10px] text-muted-foreground">Admin Rate</p>
                                </div>
                                <div>
                                    <p className="text-2xl font-bold">{props.emarWidgets.pending}</p>
                                    <p className="text-[10px] text-muted-foreground">Pending</p>
                                </div>
                                <div>
                                    <p className={`text-2xl font-bold ${props.emarWidgets.activeAlerts > 0 ? 'text-amber-600' : ''}`}>{props.emarWidgets.activeAlerts}</p>
                                    <p className="text-[10px] text-muted-foreground">Alerts</p>
                                </div>
                            </div>
                            {(props.emarWidgets.overdueReviews > 0 || props.emarWidgets.lowStock > 0) && (
                                <div className="mt-3 flex flex-wrap gap-1.5">
                                    {props.emarWidgets.overdueReviews > 0 && <Badge variant="destructive" className="text-[10px]">{props.emarWidgets.overdueReviews} overdue reviews</Badge>}
                                    {props.emarWidgets.lowStock > 0 && <Badge variant="outline" className="text-[10px]">{props.emarWidgets.lowStock} low stock</Badge>}
                                </div>
                            )}
                        </CardContent>
                    </Card>
                </Link>
            )}

            {/* Row 2: Charts */}
            <div className="grid gap-4 lg:grid-cols-5">
                {/* Weekly shifts bar chart */}
                <Card className="lg:col-span-3">
                    <CardHeader>
                        <CardTitle className="text-sm">Weekly Shifts</CardTitle>
                    </CardHeader>
                    <CardContent>
                        {barData.length > 0 ? (
                            <ResponsiveContainer width="100%" height={260}>
                                <BarChart data={barData}>
                                    <CartesianGrid strokeDasharray="3 3" className="stroke-muted" />
                                    <XAxis
                                        dataKey="name"
                                        tick={{ fontSize: 11 }}
                                        className="fill-muted-foreground"
                                    />
                                    <YAxis
                                        tick={{ fontSize: 11 }}
                                        className="fill-muted-foreground"
                                    />
                                    <RechartsTooltip
                                        contentStyle={{
                                            backgroundColor: 'hsl(var(--card))',
                                            border: '1px solid hsl(var(--border))',
                                            borderRadius: '0.75rem',
                                            fontSize: 12,
                                        }}
                                    />
                                    <Bar
                                        dataKey="shifts"
                                        fill="var(--primary)"
                                        radius={[4, 4, 0, 0]}
                                    />
                                </BarChart>
                            </ResponsiveContainer>
                        ) : (
                            <div className="flex h-[260px] items-center justify-center text-sm text-muted-foreground">
                                No shift data available.
                            </div>
                        )}
                    </CardContent>
                </Card>

                {/* Incident severity donut */}
                <Card className="lg:col-span-2">
                    <CardHeader>
                        <CardTitle className="text-sm">Incident Severity (30d)</CardTitle>
                    </CardHeader>
                    <CardContent className="flex items-center justify-center">
                        {severityTotal > 0 ? (
                            <DonutChart
                                data={severityDonut}
                                size={160}
                                thickness={24}
                                centerValue={severityTotal}
                                centerLabel="incidents"
                            />
                        ) : (
                            <div className="text-sm text-muted-foreground">
                                No incidents in the last 30 days.
                            </div>
                        )}
                    </CardContent>
                </Card>
            </div>

            {/* Row 3: Upcoming shifts + My Day */}
            <div className="grid gap-4 lg:grid-cols-3">
                <div className="lg:col-span-2 space-y-4">
                    {/* Filters */}
                    <div className="flex flex-wrap items-end gap-3 rounded-xl border p-4">
                        <div>
                            <div className="text-xs text-muted-foreground">Range</div>
                            <Select
                                value={filters.range ?? 'week'}
                                onValueChange={(v) => updateFilters({ range: v as any })}
                            >
                                <SelectTrigger className="mt-1 w-[160px]">
                                    <SelectValue placeholder="Range" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="today">Today</SelectItem>
                                    <SelectItem value="week">Next 7 days</SelectItem>
                                </SelectContent>
                            </Select>
                        </div>
                        <div>
                            <div className="text-xs text-muted-foreground">Status</div>
                            <Select
                                value={filters.status ?? 'all'}
                                onValueChange={(v) => updateFilters({ status: v })}
                            >
                                <SelectTrigger className="mt-1 w-[180px]">
                                    <SelectValue placeholder="Status" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all">All</SelectItem>
                                    <SelectItem value="scheduled">Scheduled</SelectItem>
                                    <SelectItem value="in_progress">In progress</SelectItem>
                                    <SelectItem value="completed">Completed</SelectItem>
                                    <SelectItem value="cancelled">Cancelled</SelectItem>
                                </SelectContent>
                            </Select>
                        </div>
                        <div className="ml-auto text-xs text-muted-foreground">
                            Showing {shiftsForWorkTab.length} shift
                            {shiftsForWorkTab.length === 1 ? '' : 's'}
                        </div>
                    </div>

                    {/* Upcoming shifts */}
                    <ShiftTimeline
                        title="Upcoming Shifts"
                        shifts={shiftsForWorkTab}
                        mode="manager"
                        emptyText="No shifts scheduled."
                    />
                </div>

                <div className="lg:col-span-1 space-y-4">
                    <MyDayList
                        title="My Day"
                        items={props.myDayItems ?? []}
                        emptyLabel="No tasks or follow-ups due."
                    />

                    <ActivityTimeline
                        title="Activity"
                        events={props.upcomingEvents ?? []}
                        emptyText="No upcoming activity."
                    />
                </div>
            </div>

            {/* Analytics tab */}
            <Tabs
                tabs={[
                    {
                        key: 'analytics',
                        label: 'Analytics',
                        content: (
                            <DashboardAnalytics
                                shiftSeries7={shiftSeries as any}
                                shiftSeries30={shiftSeries30 as any}
                                timesheetByStatus={timesheetByStatus}
                                timesheetSeries30={timesheetSeries30 as any}
                                incidentSeries30={incidentSeries30 as any}
                                incidentBySeverity30={incidentBySeverity30 as any}
                            />
                        ),
                    },
                ]}
            />
        </div>
    );
}

/* -------------------------------------------------------------------------- */
/*  Staff Dashboard                                                            */
/* -------------------------------------------------------------------------- */

function StaffDashboard({ props }: { props: Props }) {
    const kpis = props.staffKpis;
    const shiftsForWorkTab = [
        ...(props.todayShifts ?? []),
        ...(props.upcomingShifts ?? []),
    ];

    return (
        <div className="space-y-6">
            {/* Row 1: KPI cards */}
            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <KpiCard
                    label="My Shifts Today"
                    value={kpis?.myShiftsToday ?? props.todayShifts?.length ?? 0}
                    icon={Clock}
                    href="/shifts"
                />
                <KpiCard
                    label="Leave Balance"
                    value={kpis?.leaveBalance != null ? `${kpis.leaveBalance}h` : '--'}
                    icon={CalendarDays}
                    href="/hr/leave"
                />
                <KpiCard
                    label="Compliance"
                    value={kpis?.compliancePercent != null ? `${kpis.compliancePercent}%` : '--'}
                    icon={CheckCircle2}
                    trend={
                        kpis?.compliancePercent != null
                            ? {
                                  value: kpis.compliancePercent,
                                  label: 'complete',
                                  direction:
                                      kpis.compliancePercent >= 90
                                          ? 'up'
                                          : kpis.compliancePercent >= 70
                                            ? 'neutral'
                                            : 'down',
                              }
                            : undefined
                    }
                />
                <KpiCard
                    label="Pending Tasks"
                    value={kpis?.pendingTasks ?? props.myDayItems?.length ?? 0}
                    icon={ListTodo}
                />
            </div>

            {/* HR widgets if available */}
            {props.hrWidgets && (
                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <KpiCard
                        label="Pending Leave"
                        value={props.hrWidgets.pending_leave}
                        icon={CalendarDays}
                        href="/hr/leave"
                    />
                    <KpiCard
                        label="Expiring Compliance"
                        value={props.hrWidgets.expiring_compliance}
                        icon={FileWarning}
                        href="/hr/compliance"
                    />
                    <KpiCard
                        label="Pending Signatures"
                        value={props.hrWidgets.pending_signatures}
                        icon={ClipboardList}
                        href="/hr/signatures/pending"
                    />
                    <KpiCard
                        label="Due Attestations"
                        value={props.hrWidgets.due_attestations}
                        icon={CheckCircle2}
                        href="/hr/my/policies"
                    />
                </div>
            )}

            {/* Row 2: Schedule + Check-in */}
            <div className="grid gap-4 lg:grid-cols-3">
                <div className="lg:col-span-2">
                    <ShiftTimeline
                        title="Today's Schedule"
                        shifts={shiftsForWorkTab}
                        mode="staff"
                        emptyText="No shifts scheduled for today."
                    />
                </div>

                <div className="lg:col-span-1">
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-sm">Daily Check-in</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <div className="text-sm text-muted-foreground">
                                How are you feeling today?
                            </div>
                            <div className="flex gap-2">
                                {['Great', 'Good', 'Okay', 'Tired'].map((mood) => (
                                    <Button
                                        key={mood}
                                        size="sm"
                                        variant="outline"
                                        className="flex-1"
                                    >
                                        {mood}
                                    </Button>
                                ))}
                            </div>

                            <div className="border-t pt-4">
                                <div className="text-xs font-medium text-muted-foreground">
                                    Quick Actions
                                </div>
                                <div className="mt-2 flex flex-wrap gap-2">
                                    <Button asChild size="sm" variant="outline">
                                        <Link href="/hr/leave/create">Submit Leave</Link>
                                    </Button>
                                    <Button asChild size="sm" variant="outline">
                                        <Link href="/timesheets">View Timesheets</Link>
                                    </Button>
                                </div>
                            </div>
                        </CardContent>
                    </Card>
                </div>
            </div>

            {/* Row 3: Tasks + Quick Actions */}
            <div className="grid gap-4 lg:grid-cols-3">
                <div className="lg:col-span-2">
                    <MyDayList
                        title="My Tasks / Follow-ups"
                        items={props.myDayItems ?? []}
                        emptyLabel="No tasks or follow-ups due."
                    />
                </div>

                <div className="lg:col-span-1">
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-sm">Quick Actions</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="grid grid-cols-2 gap-2">
                                <Button asChild size="sm" variant="outline" className="justify-start">
                                    <Link href="/hr/leave/create">
                                        <CalendarDays className="mr-2 h-4 w-4" />
                                        Submit Leave
                                    </Link>
                                </Button>
                                <Button asChild size="sm" variant="outline" className="justify-start">
                                    <Link href="/timesheets">
                                        <Timer className="mr-2 h-4 w-4" />
                                        View Timesheets
                                    </Link>
                                </Button>
                                <Button asChild size="sm" variant="outline" className="justify-start">
                                    <Link href="/hr/my/training">
                                        <CheckCircle2 className="mr-2 h-4 w-4" />
                                        My Training
                                    </Link>
                                </Button>
                                <Button asChild size="sm" variant="outline" className="justify-start">
                                    <Link href="/hr/my/policies">
                                        <ClipboardList className="mr-2 h-4 w-4" />
                                        My Policies
                                    </Link>
                                </Button>
                            </div>
                        </CardContent>
                    </Card>

                    <div className="mt-4">
                        <ActivityTimeline
                            title="Activity"
                            events={props.upcomingEvents ?? []}
                            emptyText="No upcoming activity."
                        />
                    </div>
                </div>
            </div>
        </div>
    );
}

/* -------------------------------------------------------------------------- */
/*  HR Admin Dashboard                                                         */
/* -------------------------------------------------------------------------- */

function HrAdminDashboard({ props }: { props: Props }) {
    const hr = props.hrAdmin;

    const headcountSeries = (hr?.headcountSeries ?? []).map((d) => ({
        name: d.month,
        headcount: d.count,
    }));

    const deptDonut = (hr?.departmentBreakdown ?? []).map((d, i) => ({
        label: d.department || 'Unassigned',
        value: d.count,
        color: DEPT_COLORS[i % DEPT_COLORS.length],
    }));

    const deptTotal = deptDonut.reduce((s, d) => s + d.value, 0);

    return (
        <div className="space-y-6">
            {/* Row 1: KPI cards */}
            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <KpiCard
                    label="Total Headcount"
                    value={hr?.headcount ?? 0}
                    icon={Users}
                    sparklineData={hr?.headcountTrend}
                    href="/hr/employees"
                />
                <KpiCard
                    label="Open Vacancies"
                    value={hr?.vacancies ?? 0}
                    icon={Briefcase}
                    href="/hr/positions"
                />
                <KpiCard
                    label="Pending Leave"
                    value={hr?.pendingLeave ?? 0}
                    icon={CalendarDays}
                    href="/hr/leave"
                />
                <KpiCard
                    label="Compliance Score"
                    value={hr?.complianceScore != null ? `${hr.complianceScore}%` : '--'}
                    icon={CheckCircle2}
                    trend={
                        hr?.complianceScore != null
                            ? {
                                  value: hr.complianceScore,
                                  label: 'compliant',
                                  direction:
                                      hr.complianceScore >= 90
                                          ? 'up'
                                          : hr.complianceScore >= 70
                                            ? 'neutral'
                                            : 'down',
                              }
                            : undefined
                    }
                    href="/hr/compliance"
                />
            </div>

            {/* Row 2: Charts */}
            <div className="grid gap-4 lg:grid-cols-5">
                {/* Headcount trend line chart */}
                <Card className="lg:col-span-3">
                    <CardHeader>
                        <CardTitle className="text-sm">Headcount Trend (12 months)</CardTitle>
                    </CardHeader>
                    <CardContent>
                        {headcountSeries.length > 0 ? (
                            <ResponsiveContainer width="100%" height={260}>
                                <LineChart data={headcountSeries}>
                                    <CartesianGrid strokeDasharray="3 3" className="stroke-muted" />
                                    <XAxis
                                        dataKey="name"
                                        tick={{ fontSize: 11 }}
                                        className="fill-muted-foreground"
                                    />
                                    <YAxis
                                        tick={{ fontSize: 11 }}
                                        className="fill-muted-foreground"
                                    />
                                    <RechartsTooltip
                                        contentStyle={{
                                            backgroundColor: 'hsl(var(--card))',
                                            border: '1px solid hsl(var(--border))',
                                            borderRadius: '0.75rem',
                                            fontSize: 12,
                                        }}
                                    />
                                    <Line
                                        type="monotone"
                                        dataKey="headcount"
                                        stroke="var(--primary)"
                                        strokeWidth={2}
                                        dot={{ r: 3, fill: 'var(--primary)' }}
                                    />
                                </LineChart>
                            </ResponsiveContainer>
                        ) : (
                            <div className="flex h-[260px] items-center justify-center text-sm text-muted-foreground">
                                No headcount data available.
                            </div>
                        )}
                    </CardContent>
                </Card>

                {/* Department breakdown donut */}
                <Card className="lg:col-span-2">
                    <CardHeader>
                        <CardTitle className="text-sm">Department Breakdown</CardTitle>
                    </CardHeader>
                    <CardContent className="flex items-center justify-center">
                        {deptTotal > 0 ? (
                            <DonutChart
                                data={deptDonut}
                                size={160}
                                thickness={24}
                                centerValue={deptTotal}
                                centerLabel="employees"
                            />
                        ) : (
                            <div className="text-sm text-muted-foreground">
                                No department data available.
                            </div>
                        )}
                    </CardContent>
                </Card>
            </div>

            {/* Row 3: Recent activity + Expiring compliance */}
            <div className="grid gap-4 lg:grid-cols-2">
                {/* Recent activity feed */}
                <Card>
                    <CardHeader>
                        <CardTitle className="text-sm">Recent Activity</CardTitle>
                    </CardHeader>
                    <CardContent>
                        {hr?.recentFeedPosts?.length ? (
                            <div className="space-y-3">
                                {hr.recentFeedPosts.map((post) => (
                                    <div
                                        key={post.id}
                                        className="flex items-start gap-3 rounded-lg border p-3"
                                    >
                                        <Activity className="mt-0.5 h-4 w-4 shrink-0 text-muted-foreground" />
                                        <div className="min-w-0 flex-1">
                                            <div className="flex items-center gap-2">
                                                <span className="text-sm font-medium">
                                                    {post.user?.name ?? 'System'}
                                                </span>
                                                <Badge variant="secondary" className="text-[10px]">
                                                    {post.post_type}
                                                </Badge>
                                            </div>
                                            <div className="mt-1 line-clamp-2 text-sm text-muted-foreground">
                                                {post.content}
                                            </div>
                                            <div className="mt-1 text-xs text-muted-foreground">
                                                {formatShortDate(post.created_at)}
                                            </div>
                                        </div>
                                    </div>
                                ))}
                            </div>
                        ) : (
                            <div className="text-sm text-muted-foreground">
                                No recent activity.
                            </div>
                        )}
                    </CardContent>
                </Card>

                {/* Expiring compliance */}
                <Card>
                    <CardHeader className="flex flex-row items-center justify-between">
                        <CardTitle className="text-sm">Expiring Compliance</CardTitle>
                        <Button asChild size="sm" variant="outline">
                            <Link href="/hr/compliance">View all</Link>
                        </Button>
                    </CardHeader>
                    <CardContent>
                        {hr?.expiringCompliance?.length ? (
                            <div className="space-y-2">
                                {hr.expiringCompliance.map((item, i) => (
                                    <div
                                        key={`${item.user_id}-${i}`}
                                        className="flex items-center justify-between gap-3 rounded-lg border p-3"
                                    >
                                        <div className="min-w-0">
                                            <div className="text-sm font-medium">
                                                {item.user_name}
                                            </div>
                                            <div className="text-xs text-muted-foreground">
                                                {item.requirement_name}
                                            </div>
                                        </div>
                                        <Badge variant="destructive" className="shrink-0 text-[10px]">
                                            Expires {formatShortDate(item.expires_at)}
                                        </Badge>
                                    </div>
                                ))}
                            </div>
                        ) : (
                            <div className="text-sm text-muted-foreground">
                                No items expiring soon.
                            </div>
                        )}
                    </CardContent>
                </Card>
            </div>
        </div>
    );
}

/* -------------------------------------------------------------------------- */
/*  Client Dashboard (preserved from original)                                 */
/* -------------------------------------------------------------------------- */

function ClientDashboard({ props }: { props: Props }) {
    const { labels } = usePage().props as any;
    const clientLabelSingular = labels?.['client.singular'] ?? 'Client';
    const staffLabelPlural = labels?.['staff.plural'] ?? 'Staff';

    const shiftsForWorkTab = [
        ...(props.todayShifts ?? []),
        ...(props.upcomingShifts ?? []),
    ];

    return (
        <div className="grid gap-4 lg:grid-cols-3">
            <div className="rounded-xl border p-4 lg:col-span-1">
                <div className="text-sm font-semibold">
                    {clientLabelSingular}: {props.client?.first_name} {props.client?.last_name}
                </div>
                <div className="mt-1 text-xs text-muted-foreground">
                    Status: {props.client?.status ?? '--'}
                </div>

                <div className="mt-4">
                    <div className="text-xs font-medium text-muted-foreground">
                        Assigned {staffLabelPlural}
                    </div>
                    <div className="mt-2 space-y-2">
                        {props.assignedStaff?.length ? (
                            props.assignedStaff.map((s) => (
                                <div key={s.id} className="rounded-md border p-2 text-sm">
                                    <div className="font-medium">{s.name}</div>
                                    {s.email && (
                                        <div className="text-xs text-muted-foreground">{s.email}</div>
                                    )}
                                </div>
                            ))
                        ) : (
                            <div className="text-sm text-muted-foreground">
                                No assigned {staffLabelPlural.toLowerCase()}.
                            </div>
                        )}
                    </div>
                </div>
            </div>

            <div className="lg:col-span-2">
                <ShiftTimeline
                    title="Upcoming shifts"
                    shifts={shiftsForWorkTab}
                    mode="client"
                    emptyText="No shifts scheduled."
                />
            </div>
        </div>
    );
}

/* -------------------------------------------------------------------------- */
/*  Main Dashboard                                                             */
/* -------------------------------------------------------------------------- */

export default function Dashboard(props: Props) {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Dashboard" />

            {props.mode === 'hr_admin' && <HrAdminDashboard props={props} />}
            {props.mode === 'manager' && <ManagerDashboard props={props} />}
            {props.mode === 'staff' && <StaffDashboard props={props} />}
            {props.mode === 'client' && <ClientDashboard props={props} />}
        </AppLayout>
    );
}
