import AppLayout from '@/layouts/app-layout';
import PageShell from '@/components/page-shell';
import PageHeader from '@/components/page-header';
import { Head, Link, router } from '@inertiajs/react';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { TabsRoot as Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import {
    AlertTriangle,
    CalendarDays,
    Plus,
    Clock,
    CheckCircle2,
    XCircle,
    Loader2,
    LayoutDashboard,
    List,
    Users,
    TrendingDown,
    Timer,
    BarChart3,
    Calendar,
    MapPin,
} from 'lucide-react';
import { useMemo, useState } from 'react';
import { LaravelPagination } from '@/components/ui/laravel-pagination';
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
import {
    AreaChart, Area, BarChart, Bar, PieChart, Pie, Cell,
    XAxis, YAxis, CartesianGrid, Tooltip, ResponsiveContainer, Legend,
} from 'recharts';

/* ------------------------------------------------------------------ */
/*  Types                                                              */
/* ------------------------------------------------------------------ */

type LeaveRequest = {
    id: number;
    staff_name: string;
    staff_id: number;
    leave_type: string;
    start_date: string;
    end_date: string;
    hours: number;
    status: 'pending' | 'approved' | 'declined' | 'cancelled';
    reason?: string | null;
    reviewed_by?: string | null;
    approval_due_at?: string | null;
    is_overdue?: boolean;
    due_within_24h?: boolean;
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
    monthlyTrend: Array<{ month: string; approved: number; pending: number; declined: number; total_hours: number }>;
    typeBreakdown: Array<{ type: string; value: number }>;
    topAbsentees: Array<{ name: string; hours: number; occurrences: number }>;
    onLeaveToday: Array<{ id: number; name: string; leave_type: string; end_date: string }>;
    upcomingLeaveThisWeek: Array<{ id: number; name: string; leave_type: string; start_date: string }>;
    absenceRate: number;
    totalActiveStaff: number;
    rosterImpact: number;
};

type Props = {
    requests: PaginatedRequests;
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

const statusConfig: Record<string, { variant: StatusVariant; className: string; label: string }> = {
    pending: { variant: 'outline', className: 'border-yellow-500/30 text-yellow-400 bg-yellow-500/10', label: 'Pending' },
    approved: { variant: 'outline', className: 'border-emerald-500/30 text-emerald-400 bg-emerald-500/10', label: 'Approved' },
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

const CHART_COLORS = ['#3b82f6', '#ef4444', '#8b5cf6', '#f59e0b', '#10b981', '#94a3b8', '#06b6d4', '#64748b'];

function StatusBadge({ status }: { status: string }) {
    const config = statusConfig[status] || statusConfig.pending;
    return <Badge variant={config.variant} className={config.className || undefined}>{config.label}</Badge>;
}

function SlaBadge({ request }: { request: LeaveRequest }) {
    if (request.is_overdue) {
        return <Badge variant="destructive" className="ml-2 gap-1"><AlertTriangle className="h-3 w-3" /> Overdue</Badge>;
    }
    if (request.due_within_24h) {
        return <Badge variant="outline" className="ml-2 gap-1 border-amber-500/30 text-amber-400 bg-amber-500/10"><Clock className="h-3 w-3" /> Due in 24h</Badge>;
    }
    return null;
}

/* ------------------------------------------------------------------ */
/*  Component                                                          */
/* ------------------------------------------------------------------ */

export default function LeaveIndex({ requests, filters, sla, pendingAging, dashboardData, can }: Props) {
    const pendingRequests = requests.data.filter((r) => r.status === 'pending');
    const allRequests = requests.data;
    const [selectedRequestIds, setSelectedRequestIds] = useState<number[]>([]);
    const [declineDialogOpen, setDeclineDialogOpen] = useState(false);
    const [declineTarget, setDeclineTarget] = useState<{ type: 'single'; id: number } | { type: 'bulk' } | null>(null);
    const [declineNotes, setDeclineNotes] = useState('');
    const [processing, setProcessing] = useState(false);
    const [bulkApproveDialogOpen, setBulkApproveDialogOpen] = useState(false);
    const selectedPendingIds = useMemo(
        () => selectedRequestIds.filter((id) => pendingRequests.some((request) => request.id === id)),
        [selectedRequestIds, pendingRequests],
    );

    const updateFilter = (key: string, value: string | null) => {
        const newFilters = { ...filters, [key]: value };
        if (value === null || value === 'all') delete newFilters[key as keyof typeof newFilters];
        router.get('/hr/leave', newFilters, { preserveState: true, replace: true });
    };

    function handleApprove(requestId: number) {
        setProcessing(true);
        router.post(`/hr/leave/${requestId}/approve`, {}, { preserveScroll: true, onFinish: () => setProcessing(false) });
    }
    function handleDecline(requestId: number) {
        setDeclineTarget({ type: 'single', id: requestId });
        setDeclineNotes('');
        setDeclineDialogOpen(true);
    }
    function toggleRequestSelection(requestId: number, checked: boolean) {
        setSelectedRequestIds((current) => checked ? (current.includes(requestId) ? current : [...current, requestId]) : current.filter((id) => id !== requestId));
    }
    function toggleSelectAllPending(checked: boolean) {
        setSelectedRequestIds(checked ? pendingRequests.map((r) => r.id) : []);
    }
    function handleBulkApprove() { if (selectedPendingIds.length > 0) setBulkApproveDialogOpen(true); }
    function confirmBulkApprove() {
        setProcessing(true);
        setBulkApproveDialogOpen(false);
        router.post('/hr/leave/bulk-approve', { request_ids: selectedPendingIds }, { preserveScroll: true, onFinish: () => setProcessing(false), onSuccess: () => setSelectedRequestIds([]) });
    }
    function handleBulkDecline() {
        if (selectedPendingIds.length > 0) { setDeclineTarget({ type: 'bulk' }); setDeclineNotes(''); setDeclineDialogOpen(true); }
    }
    function submitDecline() {
        if (!declineNotes.trim() || !declineTarget) return;
        setProcessing(true);
        if (declineTarget.type === 'single') {
            router.post(`/hr/leave/${declineTarget.id}/decline`, { review_notes: declineNotes.trim() }, { preserveScroll: true, onFinish: () => setProcessing(false), onSuccess: () => setDeclineDialogOpen(false) });
        } else {
            router.post('/hr/leave/bulk-decline', { request_ids: selectedPendingIds, review_notes: declineNotes.trim() }, { preserveScroll: true, onFinish: () => setProcessing(false), onSuccess: () => { setSelectedRequestIds([]); setDeclineDialogOpen(false); } });
        }
    }
    function extendSlaByHours(requestId: number, hours: number) {
        router.post(`/hr/leave/${requestId}/sla-due`, { hours }, { preserveScroll: true });
    }
    function escalateNow() {
        router.post('/hr/leave/escalate-now', {}, { preserveScroll: true });
    }

    const dd = dashboardData;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Leave Management" />

            <PageShell>
                <PageHeader
                    title="Leave Management"
                    description="Manage leave requests, approvals, balances, and absence analytics."
                    actions={
                        <div className="flex items-center gap-2">
                            {can.approve && (
                                <Button variant="outline" size="sm" onClick={escalateNow}>Escalate Overdue</Button>
                            )}
                            {can.create && (
                                <Button size="sm" asChild>
                                    <Link href="/hr/leave/create"><Plus className="h-4 w-4 mr-1.5" /> New Request</Link>
                                </Button>
                            )}
                        </div>
                    }
                />

                {/* KPI Cards */}
                <div className="grid grid-cols-2 gap-3 md:grid-cols-3 lg:grid-cols-5">
                    <KpiCard label="Pending Queue" value={sla.pending_total} icon={Clock} color="bg-amber-500/10 text-amber-500" />
                    <KpiCard label="On Leave Today" value={dd.onLeaveToday.length} icon={Users} description={`of ${dd.totalActiveStaff} staff`} color="bg-blue-500/10 text-blue-500" />
                    <KpiCard label="Absence Rate" value={dd.absenceRate} icon={TrendingDown} suffix="%" decimals={1} description="Sick leave (30d)" color="bg-red-500/10 text-red-500" />
                    <KpiCard label="Avg Decision" value={sla.avg_decision_hours_30d} icon={Timer} suffix="h" decimals={1} description="Last 30 days" color="bg-primary/10 text-primary" />
                    <KpiCard label="Roster Impact" value={dd.rosterImpact} icon={AlertTriangle} description="Shifts affected" color="bg-orange-500/10 text-orange-500" />
                </div>

                {/* Tabs */}
                <Tabs defaultValue="dashboard" className="space-y-4">
                    <TabsList className="flex h-auto flex-wrap gap-1">
                        <TabsTrigger value="dashboard"><LayoutDashboard className="mr-1.5 h-3.5 w-3.5" /> Dashboard</TabsTrigger>
                        <TabsTrigger value="requests" className="relative">
                            <List className="mr-1.5 h-3.5 w-3.5" /> Requests
                            {sla.pending_total > 0 && (
                                <Badge className="ml-1.5 h-5 min-w-[20px] rounded-full bg-amber-500 px-1.5 text-[10px] text-white">{sla.pending_total}</Badge>
                            )}
                        </TabsTrigger>
                        <TabsTrigger value="balances"><BarChart3 className="mr-1.5 h-3.5 w-3.5" /> Balances</TabsTrigger>
                        <TabsTrigger value="reports"><TrendingDown className="mr-1.5 h-3.5 w-3.5" /> Reports</TabsTrigger>
                    </TabsList>

                    {/* ===== Dashboard Tab ===== */}
                    <TabsContent value="dashboard" className="space-y-4">
                        <div className="grid gap-6 lg:grid-cols-3">
                            <div className="space-y-4 lg:col-span-2">
                                {/* Monthly Leave Trend */}
                                <Card>
                                    <CardHeader className="pb-3">
                                        <CardTitle className="text-base">Leave Requests Trend (6 Months)</CardTitle>
                                    </CardHeader>
                                    <CardContent>
                                        {dd.monthlyTrend.length > 0 ? (
                                            <ResponsiveContainer width="100%" height={220}>
                                                <AreaChart data={dd.monthlyTrend}>
                                                    <CartesianGrid strokeDasharray="3 3" className="stroke-muted" />
                                                    <XAxis dataKey="month" className="text-xs" tick={{ fontSize: 12 }} />
                                                    <YAxis className="text-xs" tick={{ fontSize: 12 }} />
                                                    <Tooltip />
                                                    <Area type="monotone" dataKey="approved" stackId="1" stroke="#10b981" fill="#10b981" fillOpacity={0.3} name="Approved" />
                                                    <Area type="monotone" dataKey="pending" stackId="1" stroke="#f59e0b" fill="#f59e0b" fillOpacity={0.3} name="Pending" />
                                                    <Area type="monotone" dataKey="declined" stackId="1" stroke="#ef4444" fill="#ef4444" fillOpacity={0.3} name="Declined" />
                                                    <Legend />
                                                </AreaChart>
                                            </ResponsiveContainer>
                                        ) : (
                                            <div className="flex h-[220px] items-center justify-center text-sm text-muted-foreground">No leave data available yet</div>
                                        )}
                                    </CardContent>
                                </Card>

                                {/* Type Breakdown + Top Absentees */}
                                <div className="grid gap-4 sm:grid-cols-2">
                                    <Card>
                                        <CardHeader className="pb-3">
                                            <CardTitle className="text-base">Leave by Type</CardTitle>
                                        </CardHeader>
                                        <CardContent>
                                            {dd.typeBreakdown.length > 0 ? (
                                                <ResponsiveContainer width="100%" height={200}>
                                                    <PieChart>
                                                        <Pie data={dd.typeBreakdown} dataKey="value" nameKey="type" cx="50%" cy="50%" outerRadius={70} innerRadius={40} paddingAngle={2}>
                                                            {dd.typeBreakdown.map((entry, i) => (
                                                                <Cell key={entry.type} fill={leaveTypeColors[entry.type] || CHART_COLORS[i % CHART_COLORS.length]} />
                                                            ))}
                                                        </Pie>
                                                        <Tooltip />
                                                        <Legend />
                                                    </PieChart>
                                                </ResponsiveContainer>
                                            ) : (
                                                <div className="flex h-[200px] items-center justify-center text-sm text-muted-foreground">No leave data</div>
                                            )}
                                        </CardContent>
                                    </Card>

                                    <Card>
                                        <CardHeader className="pb-3">
                                            <CardTitle className="text-base">Top Absentees (Sick Leave)</CardTitle>
                                        </CardHeader>
                                        <CardContent>
                                            {dd.topAbsentees.length > 0 ? (
                                                <ResponsiveContainer width="100%" height={200}>
                                                    <BarChart data={dd.topAbsentees} layout="vertical" margin={{ left: 0, right: 10 }}>
                                                        <CartesianGrid strokeDasharray="3 3" className="stroke-muted" />
                                                        <XAxis type="number" tick={{ fontSize: 11 }} />
                                                        <YAxis dataKey="name" type="category" width={90} tick={{ fontSize: 11 }} />
                                                        <Tooltip formatter={(value?: number) => `${value ?? 0}h`} />
                                                        <Bar dataKey="hours" fill="#ef4444" radius={[0, 4, 4, 0]} name="Sick Hours" />
                                                    </BarChart>
                                                </ResponsiveContainer>
                                            ) : (
                                                <div className="flex h-[200px] items-center justify-center text-sm text-muted-foreground">No sick leave recorded</div>
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
                                            <CalendarDays className="h-4 w-4" /> On Leave Today
                                        </CardTitle>
                                    </CardHeader>
                                    <CardContent className="space-y-2">
                                        {dd.onLeaveToday.length === 0 ? (
                                            <p className="text-sm text-muted-foreground">No staff on leave today.</p>
                                        ) : (
                                            dd.onLeaveToday.map((person) => (
                                                <div key={person.id} className="flex items-center justify-between rounded-md border p-2 text-sm">
                                                    <div>
                                                        <p className="font-medium">{person.name}</p>
                                                        <p className="text-xs capitalize text-muted-foreground">{person.leave_type.replace('_', ' ')}</p>
                                                    </div>
                                                    <p className="text-xs text-muted-foreground">Until {person.end_date}</p>
                                                </div>
                                            ))
                                        )}
                                    </CardContent>
                                </Card>

                                {/* Upcoming This Week */}
                                <Card>
                                    <CardHeader className="pb-3">
                                        <CardTitle className="flex items-center gap-2 text-base">
                                            <Calendar className="h-4 w-4" /> Upcoming This Week
                                        </CardTitle>
                                    </CardHeader>
                                    <CardContent className="space-y-2">
                                        {dd.upcomingLeaveThisWeek.length === 0 ? (
                                            <p className="text-sm text-muted-foreground">No upcoming leave this week.</p>
                                        ) : (
                                            dd.upcomingLeaveThisWeek.map((person) => (
                                                <div key={person.id} className="flex items-center justify-between rounded-md border p-2 text-sm">
                                                    <div>
                                                        <p className="font-medium">{person.name}</p>
                                                        <p className="text-xs capitalize text-muted-foreground">{person.leave_type.replace('_', ' ')}</p>
                                                    </div>
                                                    <p className="text-xs text-muted-foreground">From {person.start_date}</p>
                                                </div>
                                            ))
                                        )}
                                    </CardContent>
                                </Card>

                                {/* Pending Aging */}
                                {pendingAging.length > 0 && (
                                    <Card>
                                        <CardHeader className="pb-3">
                                            <CardTitle className="flex items-center gap-2 text-base">
                                                <Clock className="h-4 w-4 text-amber-500" /> Longest Waiting
                                            </CardTitle>
                                        </CardHeader>
                                        <CardContent className="space-y-2">
                                            {pendingAging.slice(0, 5).map((row) => (
                                                <div key={row.id} className="flex items-center justify-between rounded-md border p-2 text-sm">
                                                    <div>
                                                        <p className="font-medium">{row.staff_name}</p>
                                                        <p className="text-xs capitalize text-muted-foreground">{row.leave_type.replace('_', ' ')}</p>
                                                    </div>
                                                    <Badge variant="outline" className={row.hours_waiting > 48 ? 'border-red-500/30 text-red-400 bg-red-500/10' : 'border-amber-500/30 text-amber-400 bg-amber-500/10'}>
                                                        {row.hours_waiting.toFixed(0)}h
                                                    </Badge>
                                                </div>
                                            ))}
                                        </CardContent>
                                    </Card>
                                )}

                                {/* Quick Links */}
                                <Card>
                                    <CardHeader className="pb-3">
                                        <CardTitle className="text-base">Quick Links</CardTitle>
                                    </CardHeader>
                                    <CardContent className="space-y-2">
                                        <Button variant="outline" size="sm" className="w-full justify-start" asChild>
                                            <Link href="/hr/leave/balances"><BarChart3 className="mr-2 h-4 w-4" /> View All Balances</Link>
                                        </Button>
                                        <Button variant="outline" size="sm" className="w-full justify-start" asChild>
                                            <Link href="/hr/leave/reports"><TrendingDown className="mr-2 h-4 w-4" /> Absence Reports</Link>
                                        </Button>
                                        <Button variant="outline" size="sm" className="w-full justify-start" asChild>
                                            <Link href="/hr/calendar/time-off"><CalendarDays className="mr-2 h-4 w-4" /> Time-Off Calendar</Link>
                                        </Button>
                                        <Button variant="outline" size="sm" className="w-full justify-start" asChild>
                                            <Link href="/operations/rostering"><MapPin className="mr-2 h-4 w-4" /> View Roster</Link>
                                        </Button>
                                    </CardContent>
                                </Card>
                            </div>
                        </div>
                    </TabsContent>

                    {/* ===== Requests Tab ===== */}
                    <TabsContent value="requests" className="space-y-4">
                        {/* Pending Approval Section */}
                        {can.approve && pendingRequests.length > 0 && (
                            <Card className="border-yellow-500/20 bg-yellow-500/5">
                                <CardHeader>
                                    <div className="flex flex-wrap items-center justify-between gap-2">
                                        <CardTitle className="flex items-center gap-2">
                                            <Clock className="h-5 w-5 text-yellow-400" /> Pending Approval ({pendingRequests.length})
                                        </CardTitle>
                                        <div className="flex items-center gap-2">
                                            <Button variant="outline" size="sm" onClick={handleBulkApprove} disabled={selectedPendingIds.length === 0 || processing}>
                                                {processing ? <Loader2 className="h-3 w-3 mr-1 animate-spin" /> : null}
                                                Approve Selected ({selectedPendingIds.length})
                                            </Button>
                                            <Button variant="outline" size="sm" className="border-red-500/30 text-red-400 hover:bg-red-500/10" onClick={handleBulkDecline} disabled={selectedPendingIds.length === 0 || processing}>
                                                Decline Selected
                                            </Button>
                                        </div>
                                    </div>
                                </CardHeader>
                                <CardContent>
                                    <div className="overflow-hidden rounded-xl border">
                                        <table className="w-full text-sm">
                                            <thead className="border-b bg-muted/50">
                                                <tr>
                                                    <th className="px-4 py-3 text-left font-medium">
                                                        <input type="checkbox" checked={pendingRequests.length > 0 && selectedPendingIds.length === pendingRequests.length} onChange={(e) => toggleSelectAllPending(e.target.checked)} className="h-4 w-4 rounded" />
                                                    </th>
                                                    <th className="px-4 py-3 text-left font-medium">Staff</th>
                                                    <th className="px-4 py-3 text-left font-medium">Type</th>
                                                    <th className="px-4 py-3 text-left font-medium">Dates</th>
                                                    <th className="px-4 py-3 text-left font-medium">Hours</th>
                                                    <th className="px-4 py-3 text-left font-medium">SLA</th>
                                                    <th className="px-4 py-3 text-right font-medium">Actions</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                {pendingRequests.map((r) => (
                                                    <tr key={r.id} className="border-b last:border-b-0 hover:bg-muted/50">
                                                        <td className="px-4 py-3"><input type="checkbox" checked={selectedPendingIds.includes(r.id)} onChange={(e) => toggleRequestSelection(r.id, e.target.checked)} className="h-4 w-4 rounded" /></td>
                                                        <td className="px-4 py-3 font-medium">{r.staff_name}</td>
                                                        <td className="px-4 py-3 capitalize text-muted-foreground">{r.leave_type.replace('_', ' ')}</td>
                                                        <td className="px-4 py-3 text-muted-foreground">{r.start_date} - {r.end_date}</td>
                                                        <td className="px-4 py-3 text-muted-foreground">{r.hours}h</td>
                                                        <td className="px-4 py-3"><SlaBadge request={r} /></td>
                                                        <td className="px-4 py-3 text-right">
                                                            <div className="flex items-center justify-end gap-1">
                                                                <Button variant="outline" size="sm" className="h-7 border-emerald-500/30 text-emerald-400 hover:bg-emerald-500/10" onClick={() => handleApprove(r.id)} disabled={processing}><CheckCircle2 className="h-3 w-3 mr-1" /> Approve</Button>
                                                                <Button variant="outline" size="sm" className="h-7 border-red-500/30 text-red-400 hover:bg-red-500/10" onClick={() => handleDecline(r.id)} disabled={processing}><XCircle className="h-3 w-3 mr-1" /> Decline</Button>
                                                                <Button variant="ghost" size="sm" className="h-7" onClick={() => extendSlaByHours(r.id, 24)} disabled={processing}>+24h</Button>
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
                        <div className="flex flex-wrap items-center gap-2 rounded-lg border bg-card p-3">
                            <Select value={filters.status ?? 'all'} onValueChange={(v) => updateFilter('status', v === 'all' ? null : v)}>
                                <SelectTrigger className="w-[140px]"><SelectValue placeholder="All Statuses" /></SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all">All Statuses</SelectItem>
                                    <SelectItem value="pending">Pending</SelectItem>
                                    <SelectItem value="approved">Approved</SelectItem>
                                    <SelectItem value="declined">Declined</SelectItem>
                                    <SelectItem value="cancelled">Cancelled</SelectItem>
                                </SelectContent>
                            </Select>
                            <Select value={filters.leave_type ?? 'all'} onValueChange={(v) => updateFilter('leave_type', v === 'all' ? null : v)}>
                                <SelectTrigger className="w-[160px]"><SelectValue placeholder="All Leave Types" /></SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all">All Leave Types</SelectItem>
                                    <SelectItem value="annual">Annual</SelectItem>
                                    <SelectItem value="sick">Sick</SelectItem>
                                    <SelectItem value="bereavement">Bereavement</SelectItem>
                                    <SelectItem value="parental">Parental</SelectItem>
                                    <SelectItem value="other">Other</SelectItem>
                                </SelectContent>
                            </Select>
                            <Select value={filters.sla ?? 'all'} onValueChange={(v) => updateFilter('sla', v === 'all' ? null : v)}>
                                <SelectTrigger className="w-[170px]"><SelectValue placeholder="All SLA Windows" /></SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all">All SLA Windows</SelectItem>
                                    <SelectItem value="overdue">Overdue only</SelectItem>
                                    <SelectItem value="due_24h">Due within 24h</SelectItem>
                                </SelectContent>
                            </Select>
                            <Button variant="ghost" size="sm" onClick={() => router.get('/hr/leave', {}, { preserveState: true })}>Clear</Button>
                        </div>

                        {/* All Requests Table */}
                        <Card>
                            <CardHeader className="pb-3"><CardTitle className="text-base">All Requests</CardTitle></CardHeader>
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
                                                    <th className="px-4 py-3 text-left font-medium">Staff</th>
                                                    <th className="px-4 py-3 text-left font-medium">Type</th>
                                                    <th className="px-4 py-3 text-left font-medium">Dates</th>
                                                    <th className="px-4 py-3 text-left font-medium">Hours</th>
                                                    <th className="px-4 py-3 text-left font-medium">Status</th>
                                                    <th className="px-4 py-3 text-right font-medium">Actions</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                {allRequests.map((r) => (
                                                    <tr key={r.id} className="border-b last:border-b-0 hover:bg-muted/50">
                                                        <td className="px-4 py-3 font-medium">{r.staff_name}</td>
                                                        <td className="px-4 py-3 capitalize text-muted-foreground">{r.leave_type.replace('_', ' ')}</td>
                                                        <td className="px-4 py-3 text-muted-foreground">{r.start_date} - {r.end_date}<SlaBadge request={r} /></td>
                                                        <td className="px-4 py-3 text-muted-foreground">{r.hours}h</td>
                                                        <td className="px-4 py-3"><StatusBadge status={r.status} /></td>
                                                        <td className="px-4 py-3 text-right">
                                                            <div className="flex items-center justify-end gap-1">
                                                                <Button variant="ghost" size="sm" className="h-7" asChild><Link href={`/hr/leave/${r.id}`}>View</Link></Button>
                                                                {can.approve && r.status === 'pending' && (
                                                                    <>
                                                                        <Button variant="outline" size="sm" className="h-7 border-emerald-500/30 text-emerald-400" onClick={() => handleApprove(r.id)} disabled={processing}>Approve</Button>
                                                                        <Button variant="outline" size="sm" className="h-7 border-red-500/30 text-red-400" onClick={() => handleDecline(r.id)} disabled={processing}>Decline</Button>
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
                                <p className="text-sm text-muted-foreground">Showing {(requests.current_page - 1) * requests.per_page + 1} to {Math.min(requests.current_page * requests.per_page, requests.total)} of {requests.total}</p>
                                <LaravelPagination links={requests.links} />
                            </div>
                        )}
                    </TabsContent>

                    {/* ===== Balances Tab ===== */}
                    <TabsContent value="balances" className="space-y-4">
                        <div className="py-8 text-center">
                            <BarChart3 className="mx-auto mb-3 h-12 w-12 text-muted-foreground/40" />
                            <p className="font-medium text-muted-foreground">Balance Management</p>
                            <p className="mt-1 text-sm text-muted-foreground">View and manage staff leave balances, entitlements, and usage.</p>
                            <Button className="mt-4" asChild><Link href="/hr/leave/balances">Open Balances</Link></Button>
                        </div>
                    </TabsContent>

                    {/* ===== Reports Tab ===== */}
                    <TabsContent value="reports" className="space-y-4">
                        <div className="py-8 text-center">
                            <TrendingDown className="mx-auto mb-3 h-12 w-12 text-muted-foreground/40" />
                            <p className="font-medium text-muted-foreground">Absence Reports & Analytics</p>
                            <p className="mt-1 text-sm text-muted-foreground">Absenteeism trends, Bradford Factor analysis, and leave utilisation reports.</p>
                            <Button className="mt-4" asChild><Link href="/hr/leave/reports">Open Reports</Link></Button>
                        </div>
                    </TabsContent>
                </Tabs>
            </PageShell>

            {/* Bulk Approve Confirmation */}
            <AlertDialog open={bulkApproveDialogOpen} onOpenChange={setBulkApproveDialogOpen}>
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>Approve {selectedPendingIds.length} Leave Request{selectedPendingIds.length === 1 ? '' : 's'}?</AlertDialogTitle>
                        <AlertDialogDescription>This will approve {selectedPendingIds.length} pending leave {selectedPendingIds.length === 1 ? 'request' : 'requests'}. This action cannot be undone.</AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel>Cancel</AlertDialogCancel>
                        <AlertDialogAction onClick={confirmBulkApprove}>Approve {selectedPendingIds.length} Request{selectedPendingIds.length === 1 ? '' : 's'}</AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>

            {/* Decline Dialog */}
            <Dialog open={declineDialogOpen} onOpenChange={setDeclineDialogOpen}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>{declineTarget?.type === 'bulk' ? `Decline ${selectedPendingIds.length} Leave Request(s)` : 'Decline Leave Request'}</DialogTitle>
                    </DialogHeader>
                    <div className="space-y-2">
                        <Label htmlFor="decline-notes">Reason for declining (required)</Label>
                        <Textarea id="decline-notes" value={declineNotes} onChange={(e) => setDeclineNotes(e.target.value)} placeholder="Enter the reason for declining this request..." rows={3} />
                    </div>
                    <DialogFooter>
                        <Button variant="outline" onClick={() => setDeclineDialogOpen(false)} disabled={processing}>Cancel</Button>
                        <Button variant="destructive" onClick={submitDecline} disabled={!declineNotes.trim() || processing}>
                            {processing ? <Loader2 className="h-4 w-4 mr-2 animate-spin" /> : null} Decline
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
