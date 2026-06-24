import PageShell from '@/components/page-shell';
import { KpiCard } from '@/components/recruitment/kpi-card';
import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { LaravelPagination } from '@/components/ui/laravel-pagination';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import {
    LeaveRequestDialog,
    type LeaveStaff,
    type LeaveTypeOption,
} from '@/components/hr/leave-request-dialog';
import { LeaveTabs } from '@/components/hr';
import { PageHero } from '@/components/page';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import { Head, Link, router } from '@inertiajs/react';
import {
    AlertTriangle,
    BarChart3,
    Calendar,
    CalendarDays,
    CalendarOff,
    CheckCircle2,
    Clock,
    Loader2,
    MapPin,
    Plus,
    Timer,
    TrendingDown,
    Users,
    XCircle,
} from 'lucide-react';
import { useMemo, useState } from 'react';
import {
    Area,
    AreaChart,
    Bar,
    BarChart,
    CartesianGrid,
    Cell,
    Legend,
    Pie,
    PieChart,
    ResponsiveContainer,
    Tooltip,
    XAxis,
    YAxis,
} from 'recharts';

/* ------------------------------------------------------------------ */
/*  Types                                                              */
/* ------------------------------------------------------------------ */

type RosterConflict = {
    has_conflict: boolean;
    count: number;
    shifts: Array<{
        site_id: number | null;
        site_name: string | null;
        date: string | null;
        am_pm: string;
    }>;
};

type BalanceImpact = {
    remaining_before: number;
    projected_after: number;
    insufficient: boolean;
} | null;

type LeaveRequest = {
    id: number;
    staff_name: string;
    staff_id: number;
    leave_type: string;
    period?: string;
    start_date: string;
    end_date: string;
    hours: number;
    status: 'pending' | 'approved' | 'declined' | 'cancelled';
    reason?: string | null;
    has_doc?: boolean;
    reviewed_by?: string | null;
    reviewed_at?: string | null;
    submitted_at?: string | null;
    hours_waiting?: number;
    approval_due_at?: string | null;
    is_overdue?: boolean;
    due_within_24h?: boolean;
    escalation_level?: number;
    escalated?: boolean;
    escalated_from?: string | null;
    roster_conflict?: RosterConflict;
    balance_impact?: BalanceImpact;
};

type InboxSegment = { count: number; items: LeaveRequest[] };
type Inbox = {
    awaiting_my_decision: InboxSegment;
    escalated_to_me: InboxSegment;
    all_pending: InboxSegment;
    recently_decided: InboxSegment;
};

type CalendarFeed = {
    month: string;
    month_label: string;
    start: string;
    end: string;
    entries: Array<{
        id: number;
        user_id: number;
        user_name: string;
        site: string | null;
        leave_type: string;
        period: string;
        status: string;
        start: string;
        end: string;
    }>;
    people: Array<{ user_id: number; name: string; site: string | null }>;
    public_holidays: Record<
        string,
        { name: string; is_national: boolean; region: string | null }
    >;
};

type PaginatedRequests = {
    data: LeaveRequest[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    links: Array<{ url: string | null; label: string; active: boolean }>;
};

type DashboardData = {
    monthlyTrend: Array<{
        month: string;
        approved: number;
        pending: number;
        declined: number;
        total_hours: number;
    }>;
    typeBreakdown: Array<{ type: string; value: number }>;
    topAbsentees: Array<{ name: string; hours: number; occurrences: number }>;
    onLeaveToday: Array<{
        id: number;
        name: string;
        leave_type: string;
        end_date: string;
    }>;
    upcomingLeaveThisWeek: Array<{
        id: number;
        name: string;
        leave_type: string;
        start_date: string;
    }>;
    absenceRate: number;
    totalActiveStaff: number;
    rosterImpact: number;
};

type Props = {
    requests: PaginatedRequests;
    inbox: Inbox;
    calendar?: CalendarFeed | null;
    filters: { status?: string; leave_type?: string; sla?: string | null };
    sla: {
        pending_total: number;
        overdue_count: number;
        due_within_24h_count: number;
        oldest_pending_hours: number;
        avg_decision_hours_30d: number;
        pending_by_type: Record<string, number>;
    };
    pendingAging: Array<{
        id: number;
        staff_name: string;
        leave_type: string;
        submitted_at: string | null;
        approval_due_at: string | null;
        hours_waiting: number;
    }>;
    dashboardData: DashboardData;
    staff: LeaveStaff[];
    leaveTypes: LeaveTypeOption[];
    can: { approve?: boolean; manage?: boolean; create?: boolean };
};

/* ------------------------------------------------------------------ */
/*  Config                                                             */
/* ------------------------------------------------------------------ */

const breadcrumbs = [
    { title: 'HR', href: '/hr' },
    { title: 'Leave', href: '/hr/leave' },
];

type StatusVariant = 'default' | 'secondary' | 'destructive' | 'outline';

const statusConfig: Record<
    string,
    { variant: StatusVariant; className: string; label: string }
> = {
    pending: {
        variant: 'outline',
        className:
            'border-status-warning/30 text-status-warning bg-status-warning-bg',
        label: 'Pending',
    },
    approved: {
        variant: 'outline',
        className:
            'border-status-success/30 text-status-success bg-status-success-bg',
        label: 'Approved',
    },
    declined: { variant: 'destructive', className: '', label: 'Declined' },
    cancelled: { variant: 'secondary', className: '', label: 'Cancelled' },
};

const leaveTypeColors: Record<string, string> = {
    Annual: '#3b82f6',
    Sick: '#ef4444',
    Bereavement: '#8b5cf6',
    Parental: '#f59e0b',
    'Public Holiday': '#10b981',
    Unpaid: '#94a3b8',
    Toil: '#06b6d4',
    Other: '#64748b',
};

const CHART_COLORS = [
    '#3b82f6',
    '#ef4444',
    '#8b5cf6',
    '#f59e0b',
    '#10b981',
    '#94a3b8',
    '#06b6d4',
    '#64748b',
];

function StatusBadge({ status }: { status: string }) {
    const config = statusConfig[status] || statusConfig.pending;
    return (
        <Badge
            variant={config.variant}
            className={config.className || undefined}
        >
            {config.label}
        </Badge>
    );
}

function SlaBadge({ request }: { request: LeaveRequest }) {
    if (request.is_overdue) {
        return (
            <Badge variant="destructive" className="ml-2 gap-1">
                <AlertTriangle className="h-3 w-3" /> Overdue
            </Badge>
        );
    }
    if (request.due_within_24h) {
        return (
            <Badge
                variant="outline"
                className="ml-2 gap-1 border-status-warning/30 bg-status-warning-bg text-status-warning"
            >
                <Clock className="h-3 w-3" /> Due in 24h
            </Badge>
        );
    }
    return null;
}

/* ------------------------------------------------------------------ */
/*  Component                                                          */
/* ------------------------------------------------------------------ */

const INBOX_SEGMENTS: Array<{ key: keyof Inbox; label: string }> = [
    { key: 'awaiting_my_decision', label: 'Awaiting my decision' },
    { key: 'escalated_to_me', label: 'Escalated to me' },
    { key: 'all_pending', label: 'All pending' },
    { key: 'recently_decided', label: 'Recently decided' },
];

export default function LeaveIndex({
    requests,
    inbox,
    filters,
    sla,
    pendingAging,
    dashboardData,
    staff,
    leaveTypes,
    can,
}: Props) {
    const [requestOpen, setRequestOpen] = useState(false);
    const [segment, setSegment] = useState<keyof Inbox>('all_pending');
    // Cross-page, SLA-ordered pending queue (handover §3.1) — bulk actions can now reach
    // every pending request, not just page 1.
    const pendingRequests = inbox.all_pending.items;
    const segmentItems = inbox[segment].items;
    const segmentIsPending = segment !== 'recently_decided';
    const allRequests = requests.data;
    const [selectedRequestIds, setSelectedRequestIds] = useState<number[]>([]);
    const [declineDialogOpen, setDeclineDialogOpen] = useState(false);
    const [declineTarget, setDeclineTarget] = useState<
        { type: 'single'; id: number } | { type: 'bulk' } | null
    >(null);
    const [declineNotes, setDeclineNotes] = useState('');
    const [processing, setProcessing] = useState(false);
    const [bulkApproveDialogOpen, setBulkApproveDialogOpen] = useState(false);
    const selectedPendingIds = useMemo(
        () =>
            selectedRequestIds.filter((id) =>
                pendingRequests.some((request) => request.id === id),
            ),
        [selectedRequestIds, pendingRequests],
    );

    const updateFilter = (key: string, value: string | null) => {
        const newFilters = { ...filters, [key]: value };
        if (value === null || value === 'all')
            delete newFilters[key as keyof typeof newFilters];
        router.get('/hr/leave', newFilters, {
            preserveState: true,
            replace: true,
        });
    };

    function handleApprove(requestId: number) {
        setProcessing(true);
        router.post(
            `/hr/leave/${requestId}/approve`,
            {},
            { preserveScroll: true, onFinish: () => setProcessing(false) },
        );
    }
    function handleDecline(requestId: number) {
        setDeclineTarget({ type: 'single', id: requestId });
        setDeclineNotes('');
        setDeclineDialogOpen(true);
    }
    function toggleRequestSelection(requestId: number, checked: boolean) {
        setSelectedRequestIds((current) =>
            checked
                ? current.includes(requestId)
                    ? current
                    : [...current, requestId]
                : current.filter((id) => id !== requestId),
        );
    }
    function toggleSelectAllPending(checked: boolean) {
        setSelectedRequestIds(checked ? segmentItems.map((r) => r.id) : []);
    }
    function handleBulkApprove() {
        if (selectedPendingIds.length > 0) setBulkApproveDialogOpen(true);
    }
    function confirmBulkApprove() {
        setProcessing(true);
        setBulkApproveDialogOpen(false);
        router.post(
            '/hr/leave/bulk-approve',
            { request_ids: selectedPendingIds },
            {
                preserveScroll: true,
                onFinish: () => setProcessing(false),
                onSuccess: () => setSelectedRequestIds([]),
            },
        );
    }
    function handleBulkDecline() {
        if (selectedPendingIds.length > 0) {
            setDeclineTarget({ type: 'bulk' });
            setDeclineNotes('');
            setDeclineDialogOpen(true);
        }
    }
    function submitDecline() {
        if (!declineNotes.trim() || !declineTarget) return;
        setProcessing(true);
        if (declineTarget.type === 'single') {
            router.post(
                `/hr/leave/${declineTarget.id}/decline`,
                { review_notes: declineNotes.trim() },
                {
                    preserveScroll: true,
                    onFinish: () => setProcessing(false),
                    onSuccess: () => setDeclineDialogOpen(false),
                },
            );
        } else {
            router.post(
                '/hr/leave/bulk-decline',
                {
                    request_ids: selectedPendingIds,
                    review_notes: declineNotes.trim(),
                },
                {
                    preserveScroll: true,
                    onFinish: () => setProcessing(false),
                    onSuccess: () => {
                        setSelectedRequestIds([]);
                        setDeclineDialogOpen(false);
                    },
                },
            );
        }
    }
    function extendSlaByHours(requestId: number, hours: number) {
        router.post(
            `/hr/leave/${requestId}/sla-due`,
            { hours },
            { preserveScroll: true },
        );
    }
    function escalateNow() {
        router.post('/hr/leave/escalate-now', {}, { preserveScroll: true });
    }

    const dd = dashboardData;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Leave Management" />

            <PageShell>
                <PageHero category="hr"
                    icon={CalendarOff}
                    title="Leave Management"
                    description="Manage leave requests, approvals, balances, and absence analytics."
                    stats={[
                        { label: 'Pending', value: sla.pending_total },
                        { label: 'On leave today', value: dd.onLeaveToday.length },
                        { label: 'Overdue', value: sla.overdue_count },
                        { label: 'Absence rate', value: `${dd.absenceRate}%` },
                    ]}
                    actions={
                        <div className="flex items-center gap-2">
                            {can.approve && (
                                <Button
                                    variant="outline"
                                    size="sm"
                                    className="border-primary-foreground/30 bg-primary-foreground/10 text-primary-foreground backdrop-blur-sm hover:bg-primary-foreground/20 hover:text-primary-foreground"
                                    onClick={escalateNow}
                                >
                                    Escalate Overdue
                                </Button>
                            )}
                            {can.create && (
                                <Button
                                    size="sm"
                                    onClick={() => setRequestOpen(true)}
                                >
                                    <Plus className="mr-1.5 h-4 w-4" /> New
                                    Request
                                </Button>
                            )}
                        </div>
                    }
                />

                {/* KPI Cards */}
                <div className="grid grid-cols-2 gap-3 md:grid-cols-3 lg:grid-cols-5">
                    <KpiCard
                        label="Pending Queue"
                        value={sla.pending_total}
                        icon={Clock}
                        color="bg-status-warning-bg text-status-warning"
                    />
                    <KpiCard
                        label="On Leave Today"
                        value={dd.onLeaveToday.length}
                        icon={Users}
                        description={`of ${dd.totalActiveStaff} staff`}
                        color="bg-status-info-bg text-status-info"
                    />
                    <KpiCard
                        label="Absence Rate"
                        value={dd.absenceRate}
                        icon={TrendingDown}
                        suffix="%"
                        decimals={1}
                        description="Sick leave (30d)"
                        color="bg-status-critical-bg text-status-critical"
                    />
                    <KpiCard
                        label="Avg Decision"
                        value={sla.avg_decision_hours_30d}
                        icon={Timer}
                        suffix="h"
                        decimals={1}
                        description="Last 30 days"
                        color="bg-primary/10 text-primary"
                    />
                    <KpiCard
                        label="Roster Impact"
                        value={dd.rosterImpact}
                        icon={AlertTriangle}
                        description="Shifts affected"
                        color="bg-status-warning-bg text-status-warning"
                    />
                </div>

                {/* Leave & Rosters hub tabs */}
                <LeaveTabs active="requests" />

                <div className="space-y-4">
                    {/* ===== Overview ===== */}
                        <div className="grid gap-6 lg:grid-cols-3">
                            <div className="space-y-4 lg:col-span-2">
                                {/* Monthly Leave Trend */}
                                <Card>
                                    <CardHeader className="pb-3">
                                        <CardTitle className="text-base">
                                            Leave Requests Trend (6 Months)
                                        </CardTitle>
                                    </CardHeader>
                                    <CardContent>
                                        {dd.monthlyTrend.length > 0 ? (
                                            <ResponsiveContainer
                                                width="100%"
                                                height={220}
                                            >
                                                <AreaChart
                                                    data={dd.monthlyTrend}
                                                >
                                                    <CartesianGrid
                                                        strokeDasharray="3 3"
                                                        className="stroke-muted"
                                                    />
                                                    <XAxis
                                                        dataKey="month"
                                                        className="text-xs"
                                                        tick={{ fontSize: 12 }}
                                                    />
                                                    <YAxis
                                                        className="text-xs"
                                                        tick={{ fontSize: 12 }}
                                                    />
                                                    <Tooltip />
                                                    <Area
                                                        type="monotone"
                                                        dataKey="approved"
                                                        stackId="1"
                                                        stroke="#10b981"
                                                        fill="#10b981"
                                                        fillOpacity={0.3}
                                                        name="Approved"
                                                    />
                                                    <Area
                                                        type="monotone"
                                                        dataKey="pending"
                                                        stackId="1"
                                                        stroke="#f59e0b"
                                                        fill="#f59e0b"
                                                        fillOpacity={0.3}
                                                        name="Pending"
                                                    />
                                                    <Area
                                                        type="monotone"
                                                        dataKey="declined"
                                                        stackId="1"
                                                        stroke="#ef4444"
                                                        fill="#ef4444"
                                                        fillOpacity={0.3}
                                                        name="Declined"
                                                    />
                                                    <Legend />
                                                </AreaChart>
                                            </ResponsiveContainer>
                                        ) : (
                                            <div className="flex h-[220px] items-center justify-center text-sm text-muted-foreground">
                                                No leave data available yet
                                            </div>
                                        )}
                                    </CardContent>
                                </Card>

                                {/* Type Breakdown + Top Absentees */}
                                <div className="grid gap-4 sm:grid-cols-2">
                                    <Card>
                                        <CardHeader className="pb-3">
                                            <CardTitle className="text-base">
                                                Leave by Type
                                            </CardTitle>
                                        </CardHeader>
                                        <CardContent>
                                            {dd.typeBreakdown.length > 0 ? (
                                                <ResponsiveContainer
                                                    width="100%"
                                                    height={200}
                                                >
                                                    <PieChart>
                                                        <Pie
                                                            data={
                                                                dd.typeBreakdown
                                                            }
                                                            dataKey="value"
                                                            nameKey="type"
                                                            cx="50%"
                                                            cy="50%"
                                                            outerRadius={70}
                                                            innerRadius={40}
                                                            paddingAngle={2}
                                                        >
                                                            {dd.typeBreakdown.map(
                                                                (entry, i) => (
                                                                    <Cell
                                                                        key={
                                                                            entry.type
                                                                        }
                                                                        fill={
                                                                            leaveTypeColors[
                                                                                entry
                                                                                    .type
                                                                            ] ||
                                                                            CHART_COLORS[
                                                                                i %
                                                                                    CHART_COLORS.length
                                                                            ]
                                                                        }
                                                                    />
                                                                ),
                                                            )}
                                                        </Pie>
                                                        <Tooltip />
                                                        <Legend />
                                                    </PieChart>
                                                </ResponsiveContainer>
                                            ) : (
                                                <div className="flex h-[200px] items-center justify-center text-sm text-muted-foreground">
                                                    No leave data
                                                </div>
                                            )}
                                        </CardContent>
                                    </Card>

                                    <Card>
                                        <CardHeader className="pb-3">
                                            <CardTitle className="text-base">
                                                Top Absentees (Sick Leave)
                                            </CardTitle>
                                        </CardHeader>
                                        <CardContent>
                                            {dd.topAbsentees.length > 0 ? (
                                                <ResponsiveContainer
                                                    width="100%"
                                                    height={200}
                                                >
                                                    <BarChart
                                                        data={dd.topAbsentees}
                                                        layout="vertical"
                                                        margin={{
                                                            left: 0,
                                                            right: 10,
                                                        }}
                                                    >
                                                        <CartesianGrid
                                                            strokeDasharray="3 3"
                                                            className="stroke-muted"
                                                        />
                                                        <XAxis
                                                            type="number"
                                                            tick={{
                                                                fontSize: 11,
                                                            }}
                                                        />
                                                        <YAxis
                                                            dataKey="name"
                                                            type="category"
                                                            width={90}
                                                            tick={{
                                                                fontSize: 11,
                                                            }}
                                                        />
                                                        <Tooltip
                                                            formatter={(
                                                                value?: number,
                                                            ) =>
                                                                `${value ?? 0}h`
                                                            }
                                                        />
                                                        <Bar
                                                            dataKey="hours"
                                                            fill="#ef4444"
                                                            radius={[
                                                                0, 4, 4, 0,
                                                            ]}
                                                            name="Sick Hours"
                                                        />
                                                    </BarChart>
                                                </ResponsiveContainer>
                                            ) : (
                                                <div className="flex h-[200px] items-center justify-center text-sm text-muted-foreground">
                                                    No sick leave recorded
                                                </div>
                                            )}
                                        </CardContent>
                                    </Card>
                                </div>
                            </div>

                            {/* Sidebar */}
                            <div className="space-y-4">
                                {/* Staff on Leave Today */}
                                <Card>
                                    <CardHeader className="pb-3">
                                        <CardTitle className="flex items-center gap-2 text-base">
                                            <CalendarDays className="h-4 w-4" />{' '}
                                            On Leave Today
                                        </CardTitle>
                                    </CardHeader>
                                    <CardContent className="space-y-2">
                                        {dd.onLeaveToday.length === 0 ? (
                                            <p className="text-sm text-muted-foreground">
                                                No staff on leave today.
                                            </p>
                                        ) : (
                                            dd.onLeaveToday.map((person) => (
                                                <div
                                                    key={person.id}
                                                    className="flex items-center justify-between rounded-md border p-2 text-sm"
                                                >
                                                    <div>
                                                        <p className="font-medium">
                                                            {person.name}
                                                        </p>
                                                        <p className="text-xs text-muted-foreground capitalize">
                                                            {person.leave_type.replace(
                                                                '_',
                                                                ' ',
                                                            )}
                                                        </p>
                                                    </div>
                                                    <p className="text-xs text-muted-foreground">
                                                        Until {person.end_date}
                                                    </p>
                                                </div>
                                            ))
                                        )}
                                    </CardContent>
                                </Card>

                                {/* Upcoming This Week */}
                                <Card>
                                    <CardHeader className="pb-3">
                                        <CardTitle className="flex items-center gap-2 text-base">
                                            <Calendar className="h-4 w-4" />{' '}
                                            Upcoming This Week
                                        </CardTitle>
                                    </CardHeader>
                                    <CardContent className="space-y-2">
                                        {dd.upcomingLeaveThisWeek.length ===
                                        0 ? (
                                            <p className="text-sm text-muted-foreground">
                                                No upcoming leave this week.
                                            </p>
                                        ) : (
                                            dd.upcomingLeaveThisWeek.map(
                                                (person) => (
                                                    <div
                                                        key={person.id}
                                                        className="flex items-center justify-between rounded-md border p-2 text-sm"
                                                    >
                                                        <div>
                                                            <p className="font-medium">
                                                                {person.name}
                                                            </p>
                                                            <p className="text-xs text-muted-foreground capitalize">
                                                                {person.leave_type.replace(
                                                                    '_',
                                                                    ' ',
                                                                )}
                                                            </p>
                                                        </div>
                                                        <p className="text-xs text-muted-foreground">
                                                            From{' '}
                                                            {person.start_date}
                                                        </p>
                                                    </div>
                                                ),
                                            )
                                        )}
                                    </CardContent>
                                </Card>

                                {/* Pending Aging */}
                                {pendingAging.length > 0 && (
                                    <Card>
                                        <CardHeader className="pb-3">
                                            <CardTitle className="flex items-center gap-2 text-base">
                                                <Clock className="h-4 w-4 text-status-warning" />{' '}
                                                Longest Waiting
                                            </CardTitle>
                                        </CardHeader>
                                        <CardContent className="space-y-2">
                                            {pendingAging
                                                .slice(0, 5)
                                                .map((row) => (
                                                    <div
                                                        key={row.id}
                                                        className="flex items-center justify-between rounded-md border p-2 text-sm"
                                                    >
                                                        <div>
                                                            <p className="font-medium">
                                                                {row.staff_name}
                                                            </p>
                                                            <p className="text-xs text-muted-foreground capitalize">
                                                                {row.leave_type.replace(
                                                                    '_',
                                                                    ' ',
                                                                )}
                                                            </p>
                                                        </div>
                                                        <Badge
                                                            variant="outline"
                                                            className={
                                                                row.hours_waiting >
                                                                48
                                                                    ? 'border-status-critical/30 bg-status-critical-bg text-status-critical'
                                                                    : 'border-status-warning/30 bg-status-warning-bg text-status-warning'
                                                            }
                                                        >
                                                            {row.hours_waiting.toFixed(
                                                                0,
                                                            )}
                                                            h
                                                        </Badge>
                                                    </div>
                                                ))}
                                        </CardContent>
                                    </Card>
                                )}

                                {/* Quick Links */}
                                <Card>
                                    <CardHeader className="pb-3">
                                        <CardTitle className="text-base">
                                            Quick Links
                                        </CardTitle>
                                    </CardHeader>
                                    <CardContent className="space-y-2">
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            className="w-full justify-start"
                                            asChild
                                        >
                                            <Link href="/hr/leave/balances">
                                                <BarChart3 className="mr-2 h-4 w-4" />{' '}
                                                View All Balances
                                            </Link>
                                        </Button>
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            className="w-full justify-start"
                                            asChild
                                        >
                                            <Link href="/hr/leave/reports">
                                                <TrendingDown className="mr-2 h-4 w-4" />{' '}
                                                Absence Reports
                                            </Link>
                                        </Button>
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            className="w-full justify-start"
                                            asChild
                                        >
                                            <Link href="/hr/calendar/time-off">
                                                <CalendarDays className="mr-2 h-4 w-4" />{' '}
                                                Time-Off Calendar
                                            </Link>
                                        </Button>
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            className="w-full justify-start"
                                            asChild
                                        >
                                            <Link href="/operations/rostering">
                                                <MapPin className="mr-2 h-4 w-4" />{' '}
                                                View Roster
                                            </Link>
                                        </Button>
                                    </CardContent>
                                </Card>
                            </div>
                        </div>

                    {/* ===== Requests ===== */}
                    {/* Pending Approval Section */}
                        {can.approve && (
                            <Card className="border-status-warning/20 bg-status-warning-bg">
                                <CardHeader className="space-y-3">
                                    <div className="flex flex-wrap items-center justify-between gap-2">
                                        <CardTitle className="flex items-center gap-2">
                                            <Clock className="h-5 w-5 text-status-warning" />{' '}
                                            Approvals
                                            <span className="text-xs font-normal text-muted-foreground">
                                                · sorted by SLA urgency
                                            </span>
                                        </CardTitle>
                                        {segmentIsPending && (
                                            <div className="flex items-center gap-2">
                                                <Button
                                                    variant="outline"
                                                    size="sm"
                                                    onClick={handleBulkApprove}
                                                    disabled={
                                                        selectedPendingIds.length ===
                                                            0 || processing
                                                    }
                                                >
                                                    {processing ? (
                                                        <Loader2 className="mr-1 h-3 w-3 animate-spin" />
                                                    ) : null}
                                                    Approve Selected (
                                                    {selectedPendingIds.length})
                                                </Button>
                                                <Button
                                                    variant="outline"
                                                    size="sm"
                                                    className="border-status-critical/30 text-status-critical hover:bg-status-critical-bg"
                                                    onClick={handleBulkDecline}
                                                    disabled={
                                                        selectedPendingIds.length ===
                                                            0 || processing
                                                    }
                                                >
                                                    Decline Selected
                                                </Button>
                                            </div>
                                        )}
                                    </div>
                                    <div className="flex flex-wrap gap-1">
                                        {INBOX_SEGMENTS.map((s) => (
                                            // eslint-disable-next-line no-restricted-syntax -- segment chips are custom-styled selector buttons, not standard form buttons
                                            <button
                                                key={s.key}
                                                type="button"
                                                onClick={() => setSegment(s.key)}
                                                className={cn(
                                                    'inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-xs font-semibold transition-colors',
                                                    segment === s.key
                                                        ? 'bg-primary text-primary-foreground'
                                                        : 'bg-card text-muted-foreground hover:bg-muted',
                                                )}
                                            >
                                                {s.label}
                                                <span
                                                    className={cn(
                                                        'inline-flex min-w-[18px] items-center justify-center rounded-full px-1.5 text-[10px] font-bold',
                                                        segment === s.key
                                                            ? 'bg-primary-foreground/20'
                                                            : 'bg-muted',
                                                    )}
                                                >
                                                    {inbox[s.key].count}
                                                </span>
                                            </button>
                                        ))}
                                    </div>
                                </CardHeader>
                                <CardContent>
                                    <div className="overflow-hidden rounded-xl border">
                                        <table className="w-full text-sm">
                                            <thead className="border-b bg-muted/50">
                                                <tr>
                                                    <th className="px-4 py-3 text-left font-medium">
                                                        {segmentIsPending ? (
                                                            <input
                                                                type="checkbox"
                                                                checked={
                                                                    segmentItems.length >
                                                                        0 &&
                                                                    selectedPendingIds.length ===
                                                                        segmentItems.length
                                                                }
                                                                onChange={(e) =>
                                                                    toggleSelectAllPending(
                                                                        e.target
                                                                            .checked,
                                                                    )
                                                                }
                                                                aria-label="Select all pending requests"
                                                                className="h-4 w-4 rounded"
                                                            />
                                                        ) : null}
                                                    </th>
                                                    <th className="px-4 py-3 text-left font-medium">
                                                        Staff
                                                    </th>
                                                    <th className="px-4 py-3 text-left font-medium">
                                                        Type
                                                    </th>
                                                    <th className="px-4 py-3 text-left font-medium">
                                                        Dates
                                                    </th>
                                                    <th className="px-4 py-3 text-left font-medium">
                                                        Hours
                                                    </th>
                                                    <th className="px-4 py-3 text-left font-medium">
                                                        SLA
                                                    </th>
                                                    <th className="px-4 py-3 text-right font-medium">
                                                        Actions
                                                    </th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                {segmentItems.length === 0 ? (
                                                    <tr>
                                                        <td
                                                            colSpan={7}
                                                            className="px-4 py-10 text-center text-sm text-muted-foreground"
                                                        >
                                                            Nothing waiting in this view.
                                                        </td>
                                                    </tr>
                                                ) : null}
                                                {segmentItems.map((r) => (
                                                    <tr
                                                        key={r.id}
                                                        className="border-b last:border-b-0 hover:bg-muted/50"
                                                    >
                                                        <td className="px-4 py-3">
                                                            {segmentIsPending ? (
                                                                <input
                                                                    type="checkbox"
                                                                    checked={selectedPendingIds.includes(
                                                                        r.id,
                                                                    )}
                                                                    onChange={(e) =>
                                                                        toggleRequestSelection(
                                                                            r.id,
                                                                            e.target
                                                                                .checked,
                                                                        )
                                                                    }
                                                                    aria-label={`Select leave request for ${r.staff_name}`}
                                                                    className="h-4 w-4 rounded"
                                                                />
                                                            ) : null}
                                                        </td>
                                                        <td className="px-4 py-3 font-medium">
                                                            <div>{r.staff_name}</div>
                                                            {r.roster_conflict
                                                                ?.has_conflict ? (
                                                                <div className="mt-1 inline-flex items-center gap-1 text-xs font-medium text-status-warning">
                                                                    <AlertTriangle className="h-3 w-3" />
                                                                    Roster conflict
                                                                    {r.roster_conflict
                                                                        .shifts[0]
                                                                        ? ` · ${r.roster_conflict.shifts[0].am_pm} ${r.roster_conflict.shifts[0].site_name ?? ''}`.trim()
                                                                        : ''}
                                                                </div>
                                                            ) : null}
                                                            {r.escalated ? (
                                                                <div className="mt-0.5 text-[11px] text-muted-foreground">
                                                                    Escalated
                                                                    {r.escalated_from
                                                                        ? ` from ${r.escalated_from}`
                                                                        : ''}
                                                                </div>
                                                            ) : null}
                                                        </td>
                                                        <td className="px-4 py-3 text-muted-foreground capitalize">
                                                            {r.leave_type.replace(
                                                                '_',
                                                                ' ',
                                                            )}
                                                            {r.has_doc ? (
                                                                <span className="ml-1 text-[11px]">
                                                                    📎
                                                                </span>
                                                            ) : null}
                                                        </td>
                                                        <td className="px-4 py-3 text-muted-foreground">
                                                            {r.start_date} -{' '}
                                                            {r.end_date}
                                                        </td>
                                                        <td className="px-4 py-3 text-muted-foreground">
                                                            <div>{r.hours}h</div>
                                                            {r.balance_impact ? (
                                                                <div
                                                                    className={cn(
                                                                        'text-[11px]',
                                                                        r.balance_impact
                                                                            .insufficient
                                                                            ? 'font-semibold text-status-critical'
                                                                            : 'text-muted-foreground',
                                                                    )}
                                                                >
                                                                    {
                                                                        r.balance_impact
                                                                            .remaining_before
                                                                    }
                                                                    h →{' '}
                                                                    {
                                                                        r.balance_impact
                                                                            .projected_after
                                                                    }
                                                                    h
                                                                    {r.balance_impact
                                                                        .insufficient
                                                                        ? ' ⚠'
                                                                        : ''}
                                                                </div>
                                                            ) : null}
                                                        </td>
                                                        <td className="px-4 py-3">
                                                            {segmentIsPending ? (
                                                                <SlaBadge request={r} />
                                                            ) : (
                                                                <StatusBadge
                                                                    status={r.status}
                                                                />
                                                            )}
                                                        </td>
                                                        <td className="px-4 py-3 text-right">
                                                            <div className="flex items-center justify-end gap-1">
                                                                {segmentIsPending ? (
                                                                    <>
                                                                        <Button
                                                                            variant="outline"
                                                                            size="sm"
                                                                            className="h-7 border-status-success/30 text-status-success hover:bg-status-success-bg"
                                                                            onClick={() =>
                                                                                handleApprove(
                                                                                    r.id,
                                                                                )
                                                                            }
                                                                            disabled={
                                                                                processing
                                                                            }
                                                                        >
                                                                            <CheckCircle2 className="mr-1 h-3 w-3" />{' '}
                                                                            Approve
                                                                        </Button>
                                                                        <Button
                                                                            variant="outline"
                                                                            size="sm"
                                                                            className="h-7 border-status-critical/30 text-status-critical hover:bg-status-critical-bg"
                                                                            onClick={() =>
                                                                                handleDecline(
                                                                                    r.id,
                                                                                )
                                                                            }
                                                                            disabled={
                                                                                processing
                                                                            }
                                                                        >
                                                                            <XCircle className="mr-1 h-3 w-3" />{' '}
                                                                            Decline
                                                                        </Button>
                                                                        <Button
                                                                            variant="ghost"
                                                                            size="sm"
                                                                            className="h-7"
                                                                            onClick={() =>
                                                                                extendSlaByHours(
                                                                                    r.id,
                                                                                    24,
                                                                                )
                                                                            }
                                                                            disabled={
                                                                                processing
                                                                            }
                                                                        >
                                                                            +24h
                                                                        </Button>
                                                                    </>
                                                                ) : (
                                                                    <span className="text-xs text-muted-foreground">
                                                                        {r.reviewed_by
                                                                            ? `by ${r.reviewed_by}`
                                                                            : '—'}
                                                                    </span>
                                                                )}
                                                            </div>
                                                        </td>
                                                    </tr>
                                                ))}
                                            </tbody>
                                        </table>
                                    </div>
                                </CardContent>
                            </Card>
                        )}

                        {/* Filters */}
                        <Card className="flex flex-wrap items-center gap-2 p-3">
                            <Select
                                value={filters.status ?? 'all'}
                                onValueChange={(v) =>
                                    updateFilter(
                                        'status',
                                        v === 'all' ? null : v,
                                    )
                                }
                            >
                                <SelectTrigger className="w-[140px]">
                                    <SelectValue placeholder="All Statuses" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all">
                                        All Statuses
                                    </SelectItem>
                                    <SelectItem value="pending">
                                        Pending
                                    </SelectItem>
                                    <SelectItem value="approved">
                                        Approved
                                    </SelectItem>
                                    <SelectItem value="declined">
                                        Declined
                                    </SelectItem>
                                    <SelectItem value="cancelled">
                                        Cancelled
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                            <Select
                                value={filters.leave_type ?? 'all'}
                                onValueChange={(v) =>
                                    updateFilter(
                                        'leave_type',
                                        v === 'all' ? null : v,
                                    )
                                }
                            >
                                <SelectTrigger className="w-[160px]">
                                    <SelectValue placeholder="All Leave Types" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all">
                                        All Leave Types
                                    </SelectItem>
                                    <SelectItem value="annual">
                                        Annual
                                    </SelectItem>
                                    <SelectItem value="sick">Sick</SelectItem>
                                    <SelectItem value="bereavement">
                                        Bereavement
                                    </SelectItem>
                                    <SelectItem value="family_violence">
                                        Family Violence
                                    </SelectItem>
                                    <SelectItem value="parental">
                                        Parental
                                    </SelectItem>
                                    <SelectItem value="alternative">
                                        Alternative Holiday
                                    </SelectItem>
                                    <SelectItem value="public_holiday">
                                        Public Holiday
                                    </SelectItem>
                                    <SelectItem value="toil">TOIL</SelectItem>
                                    <SelectItem value="unpaid">
                                        Unpaid
                                    </SelectItem>
                                    <SelectItem value="other">Other</SelectItem>
                                </SelectContent>
                            </Select>
                            <Select
                                value={filters.sla ?? 'all'}
                                onValueChange={(v) =>
                                    updateFilter('sla', v === 'all' ? null : v)
                                }
                            >
                                <SelectTrigger className="w-[170px]">
                                    <SelectValue placeholder="All SLA Windows" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all">
                                        All SLA Windows
                                    </SelectItem>
                                    <SelectItem value="overdue">
                                        Overdue only
                                    </SelectItem>
                                    <SelectItem value="due_24h">
                                        Due within 24h
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                            <Button
                                variant="ghost"
                                size="sm"
                                onClick={() =>
                                    router.get(
                                        '/hr/leave',
                                        {},
                                        { preserveState: true },
                                    )
                                }
                            >
                                Clear
                            </Button>
                        </Card>

                        {/* All Requests Table */}
                        <Card>
                            <CardHeader className="pb-3">
                                <CardTitle className="text-base">
                                    All Requests
                                </CardTitle>
                            </CardHeader>
                            <CardContent className="p-0">
                                {allRequests.length === 0 ? (
                                    <div className="py-12 text-center text-muted-foreground">
                                        <CalendarDays className="mx-auto mb-3 h-12 w-12 opacity-50" />
                                        <p>No leave requests found.</p>
                                    </div>
                                ) : (
                                    <div className="overflow-hidden rounded-b-xl">
                                        <table className="w-full text-sm">
                                            <thead className="border-b bg-muted/50">
                                                <tr>
                                                    <th className="px-4 py-3 text-left font-medium">
                                                        Staff
                                                    </th>
                                                    <th className="px-4 py-3 text-left font-medium">
                                                        Type
                                                    </th>
                                                    <th className="px-4 py-3 text-left font-medium">
                                                        Dates
                                                    </th>
                                                    <th className="px-4 py-3 text-left font-medium">
                                                        Hours
                                                    </th>
                                                    <th className="px-4 py-3 text-left font-medium">
                                                        Status
                                                    </th>
                                                    <th className="px-4 py-3 text-right font-medium">
                                                        Actions
                                                    </th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                {allRequests.map((r) => (
                                                    <tr
                                                        key={r.id}
                                                        className="border-b last:border-b-0 hover:bg-muted/50"
                                                    >
                                                        <td className="px-4 py-3 font-medium">
                                                            {r.staff_name}
                                                        </td>
                                                        <td className="px-4 py-3 text-muted-foreground capitalize">
                                                            {r.leave_type.replace(
                                                                '_',
                                                                ' ',
                                                            )}
                                                        </td>
                                                        <td className="px-4 py-3 text-muted-foreground">
                                                            {r.start_date} -{' '}
                                                            {r.end_date}
                                                            <SlaBadge
                                                                request={r}
                                                            />
                                                        </td>
                                                        <td className="px-4 py-3 text-muted-foreground">
                                                            {r.hours}h
                                                        </td>
                                                        <td className="px-4 py-3">
                                                            <StatusBadge
                                                                status={
                                                                    r.status
                                                                }
                                                            />
                                                        </td>
                                                        <td className="px-4 py-3 text-right">
                                                            <div className="flex items-center justify-end gap-1">
                                                                <Button
                                                                    variant="ghost"
                                                                    size="sm"
                                                                    className="h-7"
                                                                    asChild
                                                                >
                                                                    <Link
                                                                        href={`/hr/leave/${r.id}`}
                                                                    >
                                                                        View
                                                                    </Link>
                                                                </Button>
                                                                {can.approve &&
                                                                    r.status ===
                                                                        'pending' && (
                                                                        <>
                                                                            <Button
                                                                                variant="outline"
                                                                                size="sm"
                                                                                className="h-7 border-status-success/30 text-status-success"
                                                                                onClick={() =>
                                                                                    handleApprove(
                                                                                        r.id,
                                                                                    )
                                                                                }
                                                                                disabled={
                                                                                    processing
                                                                                }
                                                                            >
                                                                                Approve
                                                                            </Button>
                                                                            <Button
                                                                                variant="outline"
                                                                                size="sm"
                                                                                className="h-7 border-status-critical/30 text-status-critical"
                                                                                onClick={() =>
                                                                                    handleDecline(
                                                                                        r.id,
                                                                                    )
                                                                                }
                                                                                disabled={
                                                                                    processing
                                                                                }
                                                                            >
                                                                                Decline
                                                                            </Button>
                                                                        </>
                                                                    )}
                                                            </div>
                                                        </td>
                                                    </tr>
                                                ))}
                                            </tbody>
                                        </table>
                                    </div>
                                )}
                            </CardContent>
                        </Card>

                        {requests.total > 0 && requests.last_page > 1 && (
                            <div className="flex items-center justify-between">
                                <p className="text-sm text-muted-foreground">
                                    Showing{' '}
                                    {(requests.current_page - 1) *
                                        requests.per_page +
                                        1}{' '}
                                    to{' '}
                                    {Math.min(
                                        requests.current_page *
                                            requests.per_page,
                                        requests.total,
                                    )}{' '}
                                    of {requests.total}
                                </p>
                                <LaravelPagination links={requests.links} />
                            </div>
                        )}
                </div>
            </PageShell>

            {/* Bulk Approve Confirmation */}
            <AlertDialog
                open={bulkApproveDialogOpen}
                onOpenChange={setBulkApproveDialogOpen}
            >
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>
                            Approve {selectedPendingIds.length} Leave Request
                            {selectedPendingIds.length === 1 ? '' : 's'}?
                        </AlertDialogTitle>
                        <AlertDialogDescription>
                            This will approve {selectedPendingIds.length}{' '}
                            pending leave{' '}
                            {selectedPendingIds.length === 1
                                ? 'request'
                                : 'requests'}
                            . This action cannot be undone.
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel>Cancel</AlertDialogCancel>
                        <AlertDialogAction onClick={confirmBulkApprove}>
                            Approve {selectedPendingIds.length} Request
                            {selectedPendingIds.length === 1 ? '' : 's'}
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>

            {/* Decline Dialog */}
            <Dialog
                open={declineDialogOpen}
                onOpenChange={setDeclineDialogOpen}
            >
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>
                            {declineTarget?.type === 'bulk'
                                ? `Decline ${selectedPendingIds.length} Leave Request(s)`
                                : 'Decline Leave Request'}
                        </DialogTitle>
                    </DialogHeader>
                    <div className="space-y-2">
                        <Label htmlFor="decline-notes">
                            Reason for declining (required)
                        </Label>
                        <Textarea
                            id="decline-notes"
                            value={declineNotes}
                            onChange={(e) => setDeclineNotes(e.target.value)}
                            placeholder="Enter the reason for declining this request..."
                            rows={3}
                        />
                    </div>
                    <DialogFooter>
                        <Button
                            variant="outline"
                            onClick={() => setDeclineDialogOpen(false)}
                            disabled={processing}
                        >
                            Cancel
                        </Button>
                        <Button
                            variant="destructive"
                            onClick={submitDecline}
                            disabled={!declineNotes.trim() || processing}
                        >
                            {processing ? (
                                <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                            ) : null}{' '}
                            Decline
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            {can.create && (
                <LeaveRequestDialog
                    open={requestOpen}
                    onClose={() => setRequestOpen(false)}
                    staff={staff}
                    leaveTypes={leaveTypes}
                />
            )}
        </AppLayout>
    );
}
