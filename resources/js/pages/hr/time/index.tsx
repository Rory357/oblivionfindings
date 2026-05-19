import PageShell from '@/components/page-shell';
import { KpiCard } from '@/components/recruitment/kpi-card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { LaravelPagination } from '@/components/ui/laravel-pagination';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    TabsRoot as Tabs,
    TabsContent,
    TabsList,
    TabsTrigger,
} from '@/components/ui/tabs';
import { Textarea } from '@/components/ui/textarea';
import { PageHero } from '@/components/page';
import AppLayout from '@/layouts/app-layout';
import { Head, router } from '@inertiajs/react';
import {
    Activity,
    AlertTriangle,
    ArrowRight,
    CheckCircle,
    ClipboardCheck,
    Clock,
    FileText,
    LayoutDashboard,
    List,
    Loader2,
    Pencil,
    Play,
    RotateCcw,
    Search,
    Square,
    Timer,
    TrendingUp,
    UserPlus,
    Users,
    XCircle,
} from 'lucide-react';
import { useState } from 'react';

/* ------------------------------------------------------------------ */
/*  Types                                                              */
/* ------------------------------------------------------------------ */

interface TimeEntry {
    id: number;
    user_name: string;
    user_id: number;
    entry_date: string;
    clock_in: string;
    clock_in_short: string;
    clock_out: string | null;
    clock_out_short: string | null;
    break_minutes: number;
    total_hours: number | null;
    entry_type: string;
    status: string;
    pay_type: string;
    is_sleepover: boolean;
    is_on_call: boolean;
    is_public_holiday: boolean;
    break_compliance_met: boolean | null;
    notes: string | null;
    project_code: string | null;
    approved_by: string | null;
    amended_by: number | null;
    amendment_reason: string | null;
    client_name: string | null;
    shift: { id: number; starts_at: string; ends_at: string } | null;
}

interface TimesheetRow {
    id: number;
    user_name: string;
    user_id: number;
    period_start: string;
    period_end: string;
    status: string;
    total_hours: number | null;
    submitted_at: string | null;
    approved_by: string | null;
    approved_at: string | null;
    rejection_reason: string | null;
    returned_notes: string | null;
    returned_at: string | null;
}

interface ApprovalTimesheet {
    id: number;
    user_name: string;
    user_id: number;
    period_start: string;
    period_end: string;
    total_hours: number | null;
    submitted_at: string | null;
    hours_waiting: number;
}

interface WeeklySummary {
    week_start: string;
    week_end: string;
    daily_hours: Record<string, number>;
    total_hours: number;
    total_entries: number;
}

interface KpiStats {
    total_hours_this_week: number;
    active_clocked_in: number;
    pending_timesheets: number;
    overtime_hours: number;
    avg_hours_per_day: number;
}

interface RecentActivityItem {
    id: number;
    user_name: string;
    action: 'clocked_in' | 'clocked_out';
    time: string;
    pay_type: string;
    entry_type: string;
}

interface TeamMember {
    id: number;
    name: string;
}

interface PaginatedData<T> {
    data: T[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    links: Array<{ url: string | null; label: string; active: boolean }>;
}

interface Props {
    entries: PaginatedData<TimeEntry>;
    timesheets: PaginatedData<TimesheetRow>;
    approvalTimesheets: ApprovalTimesheet[];
    pendingApprovalCount: number;
    teamMembers: TeamMember[];
    activeClock: { id: number; clock_in: string; notes: string | null } | null;
    weeklySummary: WeeklySummary;
    kpiStats: KpiStats;
    recentActivity: RecentActivityItem[];
    filters: {
        status?: string;
        pay_type?: string;
        q?: string;
        tab?: string;
        scope?: string;
    };
    can: {
        manage?: boolean;
        approveTeam?: boolean;
        approveAny?: boolean;
        editEntry?: boolean;
        clockOnBehalf?: boolean;
    };
}

/* ------------------------------------------------------------------ */
/*  Config                                                             */
/* ------------------------------------------------------------------ */

const breadcrumbs = [
    { title: 'HR', href: '/hr' },
    { title: 'Timekeeping', href: '/hr/time' },
];

const statusConfig: Record<string, { className: string; label: string }> = {
    active: {
        className: 'border-status-info/30 text-status-info bg-status-info',
        label: 'Active',
    },
    submitted: {
        className:
            'border-status-warning/30 text-status-warning bg-status-warning',
        label: 'Submitted',
    },
    approved: {
        className:
            'border-status-success/30 text-status-success bg-status-success',
        label: 'Approved',
    },
    rejected: {
        className:
            'border-status-critical/30 text-status-critical bg-status-critical',
        label: 'Rejected',
    },
    returned: {
        className:
            'border-status-warning/30 text-status-warning bg-status-warning',
        label: 'Returned',
    },
    draft: {
        className:
            'border-border/30 text-muted-foreground bg-muted-foreground/80/10',
        label: 'Draft',
    },
};

const payTypeConfig: Record<string, { className: string; label: string }> = {
    standard: {
        className:
            'border-border/30 text-muted-foreground bg-muted-foreground/80/10',
        label: 'Standard',
    },
    sleepover: {
        className: 'border-primary/30 text-primary bg-primary/10',
        label: 'Sleepover',
    },
    on_call: {
        className: 'border-primary/30 text-primary bg-primary/10',
        label: 'On-Call',
    },
    public_holiday: {
        className:
            'border-status-warning/30 text-status-warning bg-status-warning',
        label: 'Public Holiday',
    },
    night: {
        className: 'border-status-info/30 text-status-info bg-status-info',
        label: 'Night',
    },
    weekend: {
        className: 'border-status-info/30 text-status-info bg-status-info',
        label: 'Weekend',
    },
    evening: {
        className: 'border-primary/30 text-primary bg-primary/10',
        label: 'Evening',
    },
};

const dayLabels = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
const NONE = '__none__';

/* ------------------------------------------------------------------ */
/*  Component                                                          */
/* ------------------------------------------------------------------ */

export default function TimeIndex({
    entries,
    timesheets,
    approvalTimesheets,
    pendingApprovalCount,
    teamMembers,
    activeClock,
    weeklySummary,
    kpiStats,
    recentActivity,
    filters,
    can,
}: Props) {
    const [searchValue, setSearchValue] = useState(filters.q ?? '');
    const [processing, setProcessing] = useState<number | string | null>(null);

    // Edit dialog state
    const [editEntry, setEditEntry] = useState<TimeEntry | null>(null);
    const [editForm, setEditForm] = useState({
        clock_in: '',
        clock_out: '',
        break_minutes: 0,
        pay_type: 'standard',
        notes: '',
        amendment_reason: '',
    });

    // Clock on behalf dialog state
    const [showClockOnBehalf, setShowClockOnBehalf] = useState(false);
    const [cobForm, setCobForm] = useState({
        target_user_id: '',
        clock_in: '',
        clock_out: '',
        break_minutes: 0,
        pay_type: 'standard',
        notes: '',
        reason: '',
    });

    // Return / reject dialog state
    const [returnTimesheetId, setReturnTimesheetId] = useState<number | null>(
        null,
    );
    const [returnNotes, setReturnNotes] = useState('');
    const [rejectTimesheetId, setRejectTimesheetId] = useState<number | null>(
        null,
    );
    const [rejectReason, setRejectReason] = useState('');

    // Bulk selection
    const [selectedApprovalIds, setSelectedApprovalIds] = useState<number[]>(
        [],
    );
    const [bulkNotes, setBulkNotes] = useState('');
    const [showBulkReject, setShowBulkReject] = useState(false);
    const [showBulkReturn, setShowBulkReturn] = useState(false);

    function updateFilter(key: string, value: string | null) {
        const newFilters = { ...filters, [key]: value };
        if (value === null || value === NONE)
            delete newFilters[key as keyof typeof newFilters];
        router.get('/hr/time', newFilters, {
            preserveState: true,
            replace: true,
        });
    }

    function handleClockIn() {
        router.post('/hr/time/clock-in', {}, { preserveScroll: true });
    }
    function handleClockOut() {
        router.post('/hr/time/clock-out', {}, { preserveScroll: true });
    }

    // --- Edit entry ---
    function openEditDialog(entry: TimeEntry) {
        setEditEntry(entry);
        setEditForm({
            clock_in: entry.clock_in,
            clock_out: entry.clock_out ?? '',
            break_minutes: entry.break_minutes,
            pay_type: entry.pay_type,
            notes: entry.notes ?? '',
            amendment_reason: '',
        });
    }

    function submitEditEntry() {
        if (!editEntry || !editForm.amendment_reason.trim()) return;
        setProcessing('edit');
        router.put(`/hr/time/entries/${editEntry.id}`, editForm, {
            preserveScroll: true,
            onSuccess: () => {
                setEditEntry(null);
                setProcessing(null);
            },
            onError: () => setProcessing(null),
        });
    }

    // --- Clock on behalf ---
    function submitClockOnBehalf() {
        if (!cobForm.target_user_id || !cobForm.clock_in) return;
        setProcessing('cob');
        router.post('/hr/time/clock-on-behalf', cobForm, {
            preserveScroll: true,
            onSuccess: () => {
                setShowClockOnBehalf(false);
                setProcessing(null);
                setCobForm({
                    target_user_id: '',
                    clock_in: '',
                    clock_out: '',
                    break_minutes: 0,
                    pay_type: 'standard',
                    notes: '',
                    reason: '',
                });
            },
            onError: () => setProcessing(null),
        });
    }

    // --- Single timesheet actions ---
    function handleApprove(id: number) {
        setProcessing(id);
        router.post(
            `/hr/time/timesheets/${id}/approve`,
            {},
            { preserveScroll: true, onFinish: () => setProcessing(null) },
        );
    }
    function submitReturn() {
        if (!returnTimesheetId || !returnNotes.trim()) return;
        setProcessing(returnTimesheetId);
        router.post(
            `/hr/time/timesheets/${returnTimesheetId}/return`,
            { notes: returnNotes },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setReturnTimesheetId(null);
                    setReturnNotes('');
                    setProcessing(null);
                },
                onError: () => setProcessing(null),
            },
        );
    }
    function submitReject() {
        if (!rejectTimesheetId || !rejectReason.trim()) return;
        setProcessing(rejectTimesheetId);
        router.post(
            `/hr/time/timesheets/${rejectTimesheetId}/reject`,
            { rejection_reason: rejectReason },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setRejectTimesheetId(null);
                    setRejectReason('');
                    setProcessing(null);
                },
                onError: () => setProcessing(null),
            },
        );
    }

    // --- Bulk actions ---
    function toggleApprovalSelect(id: number) {
        setSelectedApprovalIds((prev) =>
            prev.includes(id) ? prev.filter((i) => i !== id) : [...prev, id],
        );
    }
    function toggleAllApprovals() {
        if (selectedApprovalIds.length === approvalTimesheets.length) {
            setSelectedApprovalIds([]);
        } else {
            setSelectedApprovalIds(approvalTimesheets.map((t) => t.id));
        }
    }
    function bulkApprove() {
        if (selectedApprovalIds.length === 0) return;
        setProcessing('bulk');
        router.post(
            '/hr/time/timesheets/bulk-approve',
            { ids: selectedApprovalIds },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setSelectedApprovalIds([]);
                    setProcessing(null);
                },
                onError: () => setProcessing(null),
            },
        );
    }
    function submitBulkReject() {
        if (!bulkNotes.trim()) return;
        setProcessing('bulk');
        router.post(
            '/hr/time/timesheets/bulk-reject',
            { ids: selectedApprovalIds, reason: bulkNotes },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setSelectedApprovalIds([]);
                    setBulkNotes('');
                    setShowBulkReject(false);
                    setProcessing(null);
                },
                onError: () => setProcessing(null),
            },
        );
    }
    function submitBulkReturn() {
        if (!bulkNotes.trim()) return;
        setProcessing('bulk');
        router.post(
            '/hr/time/timesheets/bulk-return',
            { ids: selectedApprovalIds, notes: bulkNotes },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setSelectedApprovalIds([]);
                    setBulkNotes('');
                    setShowBulkReturn(false);
                    setProcessing(null);
                },
                onError: () => setProcessing(null),
            },
        );
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Timekeeping" />
            <PageShell>
                <PageHero variant="compact"
                    title="Timekeeping"
                    description="Clocking, time entries, period timesheets, and shift timesheets."
                    actions={
                        <div className="flex flex-wrap items-center gap-2">
                            <Button variant="outline" size="sm" asChild>
                                <a href="/operations/timesheets">
                                    <FileText className="mr-1.5 h-4 w-4" />
                                    Shift Timesheets
                                </a>
                            </Button>
                            {can.approveAny && (
                                <Button variant="outline" size="sm" asChild>
                                    <a href="/operations/timesheets/approvals">
                                        <ArrowRight className="mr-1.5 h-4 w-4" />
                                        Shift Approvals
                                    </a>
                                </Button>
                            )}
                            {can.clockOnBehalf && (
                                <Button
                                    variant="outline"
                                    size="sm"
                                    onClick={() => setShowClockOnBehalf(true)}
                                >
                                    <UserPlus className="mr-1.5 h-4 w-4" />{' '}
                                    Clock On Behalf
                                </Button>
                            )}
                        </div>
                    }
                />

                {/* KPI Cards */}
                <div className="grid grid-cols-2 gap-3 md:grid-cols-3 lg:grid-cols-5">
                    <KpiCard
                        label="Hours This Week"
                        value={kpiStats.total_hours_this_week}
                        icon={Clock}
                        suffix="h"
                        decimals={1}
                        color="bg-primary/10 text-primary"
                    />
                    <KpiCard
                        label="Active Staff"
                        value={kpiStats.active_clocked_in}
                        icon={Users}
                        description="Currently clocked in"
                        color="bg-status-success-bg text-status-success"
                    />
                    <KpiCard
                        label="Pending Timesheets"
                        value={kpiStats.pending_timesheets}
                        icon={FileText}
                        description="Awaiting approval"
                        color="bg-status-warning-bg text-status-warning"
                    />
                    <KpiCard
                        label="Overtime Hours"
                        value={kpiStats.overtime_hours}
                        icon={AlertTriangle}
                        suffix="h"
                        decimals={1}
                        description="Over 40h/week"
                        color="bg-status-critical-bg text-status-critical"
                    />
                    <KpiCard
                        label="Avg Hours/Day"
                        value={kpiStats.avg_hours_per_day}
                        icon={TrendingUp}
                        suffix="h"
                        decimals={1}
                        color="bg-status-info-bg text-status-info"
                    />
                </div>

                {/* Tabs */}
                <Tabs
                    defaultValue={filters.tab ?? 'dashboard'}
                    className="space-y-4"
                >
                    <TabsList className="flex h-auto flex-wrap gap-1">
                        <TabsTrigger value="dashboard">
                            <LayoutDashboard className="mr-1.5 h-3.5 w-3.5" />{' '}
                            Dashboard
                        </TabsTrigger>
                        <TabsTrigger value="entries">
                            <List className="mr-1.5 h-3.5 w-3.5" /> Time Entries
                        </TabsTrigger>
                        <TabsTrigger value="timesheets">
                            <ClipboardCheck className="mr-1.5 h-3.5 w-3.5" />{' '}
                            Period Timesheets
                        </TabsTrigger>
                        {can.approveAny && (
                            <TabsTrigger value="approvals" className="relative">
                                <CheckCircle className="mr-1.5 h-3.5 w-3.5" />{' '}
                                Period Approvals
                                {pendingApprovalCount > 0 && (
                                    <Badge className="ml-1.5 h-5 min-w-[20px] rounded-full bg-status-warning px-1.5 text-[10px] text-white">
                                        {pendingApprovalCount}
                                    </Badge>
                                )}
                            </TabsTrigger>
                        )}
                    </TabsList>

                    {/* ===== Dashboard Tab ===== */}
                    <TabsContent value="dashboard" className="space-y-4">
                        <div className="grid gap-6 lg:grid-cols-3">
                            <div className="space-y-4 lg:col-span-2">
                                <div className="grid gap-4 sm:grid-cols-2">
                                    <Card>
                                        <CardHeader className="pb-3">
                                            <CardTitle className="flex items-center gap-2 text-base">
                                                <Clock className="h-4 w-4" />{' '}
                                                Clock Status
                                            </CardTitle>
                                        </CardHeader>
                                        <CardContent>
                                            {activeClock ? (
                                                <div className="space-y-3">
                                                    <div className="flex items-center gap-2">
                                                        <div className="h-3 w-3 animate-pulse rounded-full bg-status-success" />
                                                        <span className="text-sm font-medium">
                                                            Clocked in since{' '}
                                                            {
                                                                activeClock.clock_in
                                                            }
                                                        </span>
                                                    </div>
                                                    <Button
                                                        onClick={handleClockOut}
                                                        variant="destructive"
                                                        className="w-full"
                                                        size="sm"
                                                    >
                                                        <Square className="mr-2 h-4 w-4" />{' '}
                                                        Clock Out
                                                    </Button>
                                                </div>
                                            ) : (
                                                <div className="space-y-3">
                                                    <div className="flex items-center gap-2">
                                                        <div className="h-3 w-3 rounded-full bg-muted" />
                                                        <span className="text-sm text-muted-foreground">
                                                            Not clocked in
                                                        </span>
                                                    </div>
                                                    <Button
                                                        onClick={handleClockIn}
                                                        className="w-full"
                                                        size="sm"
                                                    >
                                                        <Play className="mr-2 h-4 w-4" />{' '}
                                                        Clock In
                                                    </Button>
                                                </div>
                                            )}
                                        </CardContent>
                                    </Card>
                                    <Card>
                                        <CardHeader className="pb-3">
                                            <CardTitle className="flex items-center gap-2 text-base">
                                                <Timer className="h-4 w-4" />{' '}
                                                This Week
                                            </CardTitle>
                                        </CardHeader>
                                        <CardContent>
                                            <div className="mb-3 text-center">
                                                <p className="text-3xl font-bold">
                                                    {weeklySummary.total_hours}h
                                                </p>
                                                <p className="text-xs text-muted-foreground">
                                                    {weeklySummary.week_start}{' '}
                                                    to {weeklySummary.week_end}
                                                </p>
                                            </div>
                                            <div className="flex items-end justify-between gap-1">
                                                {Object.entries(
                                                    weeklySummary.daily_hours,
                                                ).map(([date, hours], i) => {
                                                    const h =
                                                        Number(hours) || 0;
                                                    const max = Math.max(
                                                        10,
                                                        ...Object.values(
                                                            weeklySummary.daily_hours,
                                                        ).map(
                                                            (v) =>
                                                                Number(v) || 0,
                                                        ),
                                                    );
                                                    const bar =
                                                        max > 0
                                                            ? (h / max) * 50
                                                            : 0;
                                                    return (
                                                        <div
                                                            key={date}
                                                            className="flex flex-1 flex-col items-center gap-1"
                                                        >
                                                            <div
                                                                className="w-full rounded bg-primary/20"
                                                                style={{
                                                                    height: `${Math.max(4, bar)}px`,
                                                                }}
                                                            >
                                                                <div
                                                                    className="w-full rounded bg-primary"
                                                                    style={{
                                                                        height: `${bar}px`,
                                                                    }}
                                                                />
                                                            </div>
                                                            <span className="text-[10px] text-muted-foreground">
                                                                {dayLabels[i] ??
                                                                    date.slice(
                                                                        5,
                                                                    )}
                                                            </span>
                                                        </div>
                                                    );
                                                })}
                                            </div>
                                        </CardContent>
                                    </Card>
                                </div>
                            </div>
                            {can.approveAny && recentActivity.length > 0 && (
                                <Card>
                                    <CardHeader className="pb-3">
                                        <CardTitle className="flex items-center gap-2 text-base">
                                            <Activity className="h-4 w-4" />{' '}
                                            Recent Activity
                                        </CardTitle>
                                    </CardHeader>
                                    <CardContent className="space-y-3">
                                        {recentActivity.map((item) => (
                                            <div
                                                key={item.id}
                                                className="flex items-start gap-2"
                                            >
                                                <div
                                                    className={`mt-0.5 h-2 w-2 shrink-0 rounded-full ${item.action === 'clocked_in' ? 'bg-status-success' : 'bg-muted'}`}
                                                />
                                                <div className="min-w-0 flex-1">
                                                    <p className="truncate text-sm font-medium">
                                                        {item.user_name}
                                                    </p>
                                                    <p className="text-xs text-muted-foreground">
                                                        {item.action ===
                                                        'clocked_in'
                                                            ? 'Clocked in'
                                                            : 'Clocked out'}{' '}
                                                        {item.time}
                                                        {item.entry_type ===
                                                            'admin_clock' && (
                                                            <span className="ml-1 text-status-warning">
                                                                (on behalf)
                                                            </span>
                                                        )}
                                                    </p>
                                                </div>
                                                {item.pay_type !==
                                                    'standard' && (
                                                    <Badge
                                                        variant="outline"
                                                        className={`shrink-0 text-[10px] ${payTypeConfig[item.pay_type]?.className ?? ''}`}
                                                    >
                                                        {payTypeConfig[
                                                            item.pay_type
                                                        ]?.label ??
                                                            item.pay_type}
                                                    </Badge>
                                                )}
                                            </div>
                                        ))}
                                    </CardContent>
                                </Card>
                            )}
                        </div>
                    </TabsContent>

                    {/* ===== Time Entries Tab ===== */}
                    <TabsContent value="entries" className="space-y-4">
                        <div className="flex flex-col gap-3 sm:flex-row">
                            <div className="relative flex-1">
                                <Search className="absolute top-1/2 left-3 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                                <Input
                                    placeholder="Search by staff name..."
                                    className="pl-9"
                                    value={searchValue}
                                    onChange={(e) =>
                                        setSearchValue(e.target.value)
                                    }
                                    onKeyDown={(e) =>
                                        e.key === 'Enter' &&
                                        updateFilter(
                                            'q',
                                            searchValue.trim() || null,
                                        )
                                    }
                                />
                            </div>
                            <div className="flex flex-wrap gap-2">
                                {can.approveAny && (
                                    <Select
                                        value={filters.scope ?? 'team'}
                                        onValueChange={(v) =>
                                            updateFilter('scope', v)
                                        }
                                    >
                                        <SelectTrigger className="h-9 w-28 text-xs">
                                            <SelectValue placeholder="Scope" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="mine">
                                                My Entries
                                            </SelectItem>
                                            <SelectItem value="team">
                                                My Team
                                            </SelectItem>
                                            {can.manage && (
                                                <SelectItem value="all">
                                                    All Staff
                                                </SelectItem>
                                            )}
                                        </SelectContent>
                                    </Select>
                                )}
                                <Select
                                    value={filters.status ?? NONE}
                                    onValueChange={(v) =>
                                        updateFilter(
                                            'status',
                                            v === NONE ? null : v,
                                        )
                                    }
                                >
                                    <SelectTrigger className="h-9 w-32 text-xs">
                                        <SelectValue placeholder="All Statuses" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value={NONE}>
                                            All Statuses
                                        </SelectItem>
                                        <SelectItem value="active">
                                            Active
                                        </SelectItem>
                                        <SelectItem value="submitted">
                                            Submitted
                                        </SelectItem>
                                        <SelectItem value="approved">
                                            Approved
                                        </SelectItem>
                                        <SelectItem value="rejected">
                                            Rejected
                                        </SelectItem>
                                    </SelectContent>
                                </Select>
                                <Select
                                    value={filters.pay_type ?? NONE}
                                    onValueChange={(v) =>
                                        updateFilter(
                                            'pay_type',
                                            v === NONE ? null : v,
                                        )
                                    }
                                >
                                    <SelectTrigger className="h-9 w-32 text-xs">
                                        <SelectValue placeholder="All Pay Types" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value={NONE}>
                                            All Pay Types
                                        </SelectItem>
                                        {Object.entries(payTypeConfig).map(
                                            ([k, v]) => (
                                                <SelectItem key={k} value={k}>
                                                    {v.label}
                                                </SelectItem>
                                            ),
                                        )}
                                    </SelectContent>
                                </Select>
                            </div>
                        </div>

                        {entries.data.length === 0 ? (
                            <div className="py-16 text-center">
                                <Clock className="mx-auto mb-4 h-12 w-12 text-muted-foreground/40" />
                                <p className="font-medium text-muted-foreground">
                                    No time entries found
                                </p>
                            </div>
                        ) : (
                            <>
                                <div className="overflow-hidden rounded-xl border">
                                    <table className="w-full text-sm">
                                        <thead className="border-b bg-muted/50">
                                            <tr>
                                                {can.approveAny && (
                                                    <th className="px-4 py-3 text-left font-medium">
                                                        Staff
                                                    </th>
                                                )}
                                                <th className="px-4 py-3 text-left font-medium">
                                                    Date
                                                </th>
                                                <th className="px-4 py-3 text-left font-medium">
                                                    Shift / Client
                                                </th>
                                                <th className="px-4 py-3 text-left font-medium">
                                                    In
                                                </th>
                                                <th className="px-4 py-3 text-left font-medium">
                                                    Out
                                                </th>
                                                <th className="px-4 py-3 text-right font-medium">
                                                    Break
                                                </th>
                                                <th className="px-4 py-3 text-right font-medium">
                                                    Hours
                                                </th>
                                                <th className="px-4 py-3 text-left font-medium">
                                                    Pay Type
                                                </th>
                                                <th className="px-4 py-3 text-left font-medium">
                                                    Status
                                                </th>
                                                {can.editEntry && (
                                                    <th className="px-4 py-3 text-right font-medium">
                                                        Actions
                                                    </th>
                                                )}
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {entries.data.map((entry) => {
                                                const sc =
                                                    statusConfig[
                                                        entry.status
                                                    ] || statusConfig.active;
                                                const pc =
                                                    payTypeConfig[
                                                        entry.pay_type
                                                    ] || payTypeConfig.standard;
                                                return (
                                                    <tr
                                                        key={entry.id}
                                                        className="border-b last:border-b-0 hover:bg-muted/50"
                                                    >
                                                        {can.approveAny && (
                                                            <td className="px-4 py-3 font-medium">
                                                                {
                                                                    entry.user_name
                                                                }
                                                                {entry.entry_type ===
                                                                    'admin_clock' && (
                                                                    <span className="ml-1 text-[10px] text-status-warning">
                                                                        (on
                                                                        behalf)
                                                                    </span>
                                                                )}
                                                            </td>
                                                        )}
                                                        <td className="px-4 py-3 text-muted-foreground">
                                                            {entry.entry_date}
                                                        </td>
                                                        <td className="px-4 py-3">
                                                            {entry.client_name ||
                                                                '-'}
                                                            {entry.shift && (
                                                                <span className="ml-1 text-xs text-muted-foreground">
                                                                    (
                                                                    {
                                                                        entry
                                                                            .shift
                                                                            .starts_at
                                                                    }
                                                                    -
                                                                    {
                                                                        entry
                                                                            .shift
                                                                            .ends_at
                                                                    }
                                                                    )
                                                                </span>
                                                            )}
                                                        </td>
                                                        <td className="px-4 py-3">
                                                            {
                                                                entry.clock_in_short
                                                            }
                                                        </td>
                                                        <td className="px-4 py-3">
                                                            {entry.clock_out_short ??
                                                                '-'}
                                                        </td>
                                                        <td className="px-4 py-3 text-right text-muted-foreground">
                                                            {entry.break_minutes >
                                                            0
                                                                ? `${entry.break_minutes}m`
                                                                : '-'}
                                                            {entry.break_compliance_met ===
                                                                false && (
                                                                <AlertTriangle className="ml-1 inline h-3 w-3 text-status-warning" />
                                                            )}
                                                        </td>
                                                        <td className="px-4 py-3 text-right font-medium">
                                                            {entry.total_hours !=
                                                            null
                                                                ? `${entry.total_hours}h`
                                                                : '-'}
                                                        </td>
                                                        <td className="px-4 py-3">
                                                            <Badge
                                                                variant="outline"
                                                                className={`text-[10px] ${pc.className}`}
                                                            >
                                                                {pc.label}
                                                            </Badge>
                                                        </td>
                                                        <td className="px-4 py-3">
                                                            <Badge
                                                                variant="outline"
                                                                className={
                                                                    sc.className
                                                                }
                                                            >
                                                                {sc.label}
                                                            </Badge>
                                                            {entry.amended_by && (
                                                                <span className="ml-1 text-[10px] text-muted-foreground">
                                                                    (amended)
                                                                </span>
                                                            )}
                                                        </td>
                                                        {can.editEntry && (
                                                            <td className="px-4 py-3 text-right">
                                                                {entry.status !==
                                                                    'approved' && (
                                                                    <Button
                                                                        variant="ghost"
                                                                        size="sm"
                                                                        className="h-7 w-7 p-0"
                                                                        onClick={() =>
                                                                            openEditDialog(
                                                                                entry,
                                                                            )
                                                                        }
                                                                    >
                                                                        <Pencil className="h-3.5 w-3.5" />
                                                                    </Button>
                                                                )}
                                                            </td>
                                                        )}
                                                    </tr>
                                                );
                                            })}
                                        </tbody>
                                    </table>
                                </div>
                                {entries.last_page > 1 && (
                                    <div className="flex items-center justify-between">
                                        <p className="text-sm text-muted-foreground">
                                            Showing{' '}
                                            {(entries.current_page - 1) *
                                                entries.per_page +
                                                1}{' '}
                                            to{' '}
                                            {Math.min(
                                                entries.current_page *
                                                    entries.per_page,
                                                entries.total,
                                            )}{' '}
                                            of {entries.total}
                                        </p>
                                        <LaravelPagination
                                            links={entries.links}
                                        />
                                    </div>
                                )}
                            </>
                        )}
                    </TabsContent>

                    {/* ===== Timesheets Tab ===== */}
                    <TabsContent value="timesheets" className="space-y-4">
                        {timesheets.data.length === 0 ? (
                            <div className="py-16 text-center">
                                <ClipboardCheck className="mx-auto mb-4 h-12 w-12 text-muted-foreground/40" />
                                <p className="font-medium text-muted-foreground">
                                    No timesheets found
                                </p>
                            </div>
                        ) : (
                            <div className="overflow-hidden rounded-xl border">
                                <table className="w-full text-sm">
                                    <thead className="border-b bg-muted/50">
                                        <tr>
                                            {can.approveAny && (
                                                <th className="px-4 py-3 text-left font-medium">
                                                    Staff
                                                </th>
                                            )}
                                            <th className="px-4 py-3 text-left font-medium">
                                                Period
                                            </th>
                                            <th className="px-4 py-3 text-right font-medium">
                                                Hours
                                            </th>
                                            <th className="px-4 py-3 text-left font-medium">
                                                Status
                                            </th>
                                            <th className="px-4 py-3 text-left font-medium">
                                                Submitted
                                            </th>
                                            {can.approveAny && (
                                                <th className="px-4 py-3 text-right font-medium">
                                                    Actions
                                                </th>
                                            )}
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {timesheets.data.map((ts) => {
                                            const sc =
                                                statusConfig[ts.status] ||
                                                statusConfig.draft;
                                            return (
                                                <tr
                                                    key={ts.id}
                                                    className="border-b last:border-b-0 hover:bg-muted/50"
                                                >
                                                    {can.approveAny && (
                                                        <td className="px-4 py-3 font-medium">
                                                            {ts.user_name}
                                                        </td>
                                                    )}
                                                    <td className="px-4 py-3">
                                                        {ts.period_start}{' '}
                                                        <ArrowRight className="mx-1 inline h-3 w-3" />{' '}
                                                        {ts.period_end}
                                                    </td>
                                                    <td className="px-4 py-3 text-right font-medium">
                                                        {ts.total_hours != null
                                                            ? `${ts.total_hours}h`
                                                            : '-'}
                                                    </td>
                                                    <td className="px-4 py-3">
                                                        <Badge
                                                            variant="outline"
                                                            className={
                                                                sc.className
                                                            }
                                                        >
                                                            {sc.label}
                                                        </Badge>
                                                    </td>
                                                    <td className="px-4 py-3 text-muted-foreground">
                                                        {ts.submitted_at?.slice(
                                                            0,
                                                            10,
                                                        ) ?? '-'}
                                                    </td>
                                                    {can.approveAny && (
                                                        <td className="px-4 py-3 text-right">
                                                            {ts.status ===
                                                                'submitted' && (
                                                                <div className="flex justify-end gap-1">
                                                                    <Button
                                                                        variant="ghost"
                                                                        size="sm"
                                                                        className="h-7 text-status-success"
                                                                        onClick={() =>
                                                                            handleApprove(
                                                                                ts.id,
                                                                            )
                                                                        }
                                                                        disabled={
                                                                            processing ===
                                                                            ts.id
                                                                        }
                                                                    >
                                                                        <CheckCircle className="mr-1 h-3 w-3" />{' '}
                                                                        Approve
                                                                    </Button>
                                                                    <Button
                                                                        variant="ghost"
                                                                        size="sm"
                                                                        className="h-7 text-status-warning"
                                                                        onClick={() => {
                                                                            setReturnTimesheetId(
                                                                                ts.id,
                                                                            );
                                                                            setReturnNotes(
                                                                                '',
                                                                            );
                                                                        }}
                                                                    >
                                                                        <RotateCcw className="mr-1 h-3 w-3" />{' '}
                                                                        Return
                                                                    </Button>
                                                                    <Button
                                                                        variant="ghost"
                                                                        size="sm"
                                                                        className="h-7 text-status-critical"
                                                                        onClick={() => {
                                                                            setRejectTimesheetId(
                                                                                ts.id,
                                                                            );
                                                                            setRejectReason(
                                                                                '',
                                                                            );
                                                                        }}
                                                                    >
                                                                        <XCircle className="mr-1 h-3 w-3" />{' '}
                                                                        Reject
                                                                    </Button>
                                                                </div>
                                                            )}
                                                        </td>
                                                    )}
                                                </tr>
                                            );
                                        })}
                                    </tbody>
                                </table>
                            </div>
                        )}
                    </TabsContent>

                    {/* ===== Approvals Tab ===== */}
                    {can.approveAny && (
                        <TabsContent value="approvals" className="space-y-4">
                            {approvalTimesheets.length === 0 ? (
                                <div className="py-16 text-center">
                                    <CheckCircle className="mx-auto mb-4 h-12 w-12 text-muted-foreground/40" />
                                    <p className="font-medium text-muted-foreground">
                                        No timesheets awaiting approval
                                    </p>
                                    <p className="mt-1 text-sm text-muted-foreground">
                                        All caught up!
                                    </p>
                                </div>
                            ) : (
                                <>
                                    {/* Bulk actions */}
                                    {selectedApprovalIds.length > 0 && (
                                        <div className="flex items-center gap-2 rounded-lg border border-status-warning/20 bg-status-warning p-3">
                                            <span className="text-sm font-medium">
                                                {selectedApprovalIds.length}{' '}
                                                selected
                                            </span>
                                            <Button
                                                size="sm"
                                                className="bg-status-success hover:bg-status-success"
                                                onClick={bulkApprove}
                                                disabled={processing === 'bulk'}
                                            >
                                                {processing === 'bulk' ? (
                                                    <Loader2 className="mr-1 h-3 w-3 animate-spin" />
                                                ) : (
                                                    <CheckCircle className="mr-1 h-3 w-3" />
                                                )}{' '}
                                                Approve Selected
                                            </Button>
                                            <Button
                                                size="sm"
                                                variant="outline"
                                                className="text-status-warning"
                                                onClick={() => {
                                                    setBulkNotes('');
                                                    setShowBulkReturn(true);
                                                }}
                                            >
                                                <RotateCcw className="mr-1 h-3 w-3" />{' '}
                                                Return Selected
                                            </Button>
                                            <Button
                                                size="sm"
                                                variant="outline"
                                                className="text-status-critical"
                                                onClick={() => {
                                                    setBulkNotes('');
                                                    setShowBulkReject(true);
                                                }}
                                            >
                                                <XCircle className="mr-1 h-3 w-3" />{' '}
                                                Reject Selected
                                            </Button>
                                        </div>
                                    )}

                                    <Card className="border-status-warning/20 bg-status-warning">
                                        <CardHeader className="pb-3">
                                            <CardTitle className="text-base">
                                                Pending Approval (
                                                {approvalTimesheets.length})
                                            </CardTitle>
                                        </CardHeader>
                                        <CardContent className="p-0">
                                            <div className="overflow-hidden rounded-b-xl">
                                                <table className="w-full text-sm">
                                                    <thead className="border-b bg-muted/50">
                                                        <tr>
                                                            <th className="w-10 px-4 py-3">
                                                                <input
                                                                    type="checkbox"
                                                                    checked={
                                                                        selectedApprovalIds.length ===
                                                                            approvalTimesheets.length &&
                                                                        approvalTimesheets.length >
                                                                            0
                                                                    }
                                                                    onChange={
                                                                        toggleAllApprovals
                                                                    }
                                                                    className="h-4 w-4 rounded border-border"
                                                                />
                                                            </th>
                                                            <th className="px-4 py-3 text-left font-medium">
                                                                Staff
                                                            </th>
                                                            <th className="px-4 py-3 text-left font-medium">
                                                                Period
                                                            </th>
                                                            <th className="px-4 py-3 text-right font-medium">
                                                                Hours
                                                            </th>
                                                            <th className="px-4 py-3 text-left font-medium">
                                                                Waiting
                                                            </th>
                                                            <th className="px-4 py-3 text-right font-medium">
                                                                Actions
                                                            </th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        {approvalTimesheets.map(
                                                            (ts) => (
                                                                <tr
                                                                    key={ts.id}
                                                                    className="border-b last:border-b-0 hover:bg-muted/50"
                                                                >
                                                                    <td className="px-4 py-3">
                                                                        <input
                                                                            type="checkbox"
                                                                            checked={selectedApprovalIds.includes(
                                                                                ts.id,
                                                                            )}
                                                                            onChange={() =>
                                                                                toggleApprovalSelect(
                                                                                    ts.id,
                                                                                )
                                                                            }
                                                                            className="h-4 w-4 rounded border-border"
                                                                        />
                                                                    </td>
                                                                    <td className="px-4 py-3 font-medium">
                                                                        {
                                                                            ts.user_name
                                                                        }
                                                                    </td>
                                                                    <td className="px-4 py-3">
                                                                        {
                                                                            ts.period_start
                                                                        }{' '}
                                                                        <ArrowRight className="mx-1 inline h-3 w-3" />{' '}
                                                                        {
                                                                            ts.period_end
                                                                        }
                                                                    </td>
                                                                    <td className="px-4 py-3 text-right font-medium">
                                                                        {ts.total_hours !=
                                                                        null
                                                                            ? `${ts.total_hours}h`
                                                                            : '-'}
                                                                    </td>
                                                                    <td className="px-4 py-3">
                                                                        <Badge
                                                                            variant="outline"
                                                                            className={
                                                                                ts.hours_waiting >
                                                                                48
                                                                                    ? 'border-status-critical/30 bg-status-critical text-status-critical'
                                                                                    : ts.hours_waiting >
                                                                                        24
                                                                                      ? 'border-status-warning/30 bg-status-warning text-status-warning'
                                                                                      : 'border-status-info/30 bg-status-info text-status-info'
                                                                            }
                                                                        >
                                                                            {ts.hours_waiting >
                                                                            48
                                                                                ? `${Math.round(ts.hours_waiting)}h overdue`
                                                                                : ts.hours_waiting >
                                                                                    24
                                                                                  ? 'Due soon'
                                                                                  : `${Math.round(ts.hours_waiting)}h`}
                                                                        </Badge>
                                                                    </td>
                                                                    <td className="px-4 py-3 text-right">
                                                                        <div className="flex justify-end gap-1">
                                                                            <Button
                                                                                variant="ghost"
                                                                                size="sm"
                                                                                className="h-7 text-status-success"
                                                                                onClick={() =>
                                                                                    handleApprove(
                                                                                        ts.id,
                                                                                    )
                                                                                }
                                                                                disabled={
                                                                                    processing ===
                                                                                    ts.id
                                                                                }
                                                                            >
                                                                                <CheckCircle className="mr-1 h-3 w-3" />{' '}
                                                                                Approve
                                                                            </Button>
                                                                            <Button
                                                                                variant="ghost"
                                                                                size="sm"
                                                                                className="h-7 text-status-warning"
                                                                                onClick={() => {
                                                                                    setReturnTimesheetId(
                                                                                        ts.id,
                                                                                    );
                                                                                    setReturnNotes(
                                                                                        '',
                                                                                    );
                                                                                }}
                                                                            >
                                                                                <RotateCcw className="mr-1 h-3 w-3" />{' '}
                                                                                Return
                                                                            </Button>
                                                                            <Button
                                                                                variant="ghost"
                                                                                size="sm"
                                                                                className="h-7 text-status-critical"
                                                                                onClick={() => {
                                                                                    setRejectTimesheetId(
                                                                                        ts.id,
                                                                                    );
                                                                                    setRejectReason(
                                                                                        '',
                                                                                    );
                                                                                }}
                                                                            >
                                                                                <XCircle className="mr-1 h-3 w-3" />{' '}
                                                                                Reject
                                                                            </Button>
                                                                        </div>
                                                                    </td>
                                                                </tr>
                                                            ),
                                                        )}
                                                    </tbody>
                                                </table>
                                            </div>
                                        </CardContent>
                                    </Card>
                                </>
                            )}
                        </TabsContent>
                    )}
                </Tabs>
            </PageShell>

            {/* ===== DIALOGS ===== */}

            {/* Edit Entry Dialog */}
            <Dialog
                open={!!editEntry}
                onOpenChange={(open) => !open && setEditEntry(null)}
            >
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Edit Time Entry</DialogTitle>
                        <DialogDescription>
                            Amend {editEntry?.user_name}'s time entry for{' '}
                            {editEntry?.entry_date}. A reason is required for
                            audit.
                        </DialogDescription>
                    </DialogHeader>
                    <div className="space-y-4">
                        <div className="grid grid-cols-2 gap-4">
                            <div>
                                <Label>Clock In</Label>
                                <Input
                                    type="datetime-local"
                                    value={editForm.clock_in}
                                    onChange={(e) =>
                                        setEditForm({
                                            ...editForm,
                                            clock_in: e.target.value,
                                        })
                                    }
                                />
                            </div>
                            <div>
                                <Label>Clock Out</Label>
                                <Input
                                    type="datetime-local"
                                    value={editForm.clock_out}
                                    onChange={(e) =>
                                        setEditForm({
                                            ...editForm,
                                            clock_out: e.target.value,
                                        })
                                    }
                                />
                            </div>
                        </div>
                        <div className="grid grid-cols-2 gap-4">
                            <div>
                                <Label>Break (minutes)</Label>
                                <Input
                                    type="number"
                                    min={0}
                                    max={480}
                                    value={editForm.break_minutes}
                                    onChange={(e) =>
                                        setEditForm({
                                            ...editForm,
                                            break_minutes: Number(
                                                e.target.value,
                                            ),
                                        })
                                    }
                                />
                            </div>
                            <div>
                                <Label>Pay Type</Label>
                                <Select
                                    value={editForm.pay_type}
                                    onValueChange={(v) =>
                                        setEditForm({
                                            ...editForm,
                                            pay_type: v,
                                        })
                                    }
                                >
                                    <SelectTrigger>
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {Object.entries(payTypeConfig).map(
                                            ([k, v]) => (
                                                <SelectItem key={k} value={k}>
                                                    {v.label}
                                                </SelectItem>
                                            ),
                                        )}
                                    </SelectContent>
                                </Select>
                            </div>
                        </div>
                        <div>
                            <Label>Notes</Label>
                            <Input
                                value={editForm.notes}
                                onChange={(e) =>
                                    setEditForm({
                                        ...editForm,
                                        notes: e.target.value,
                                    })
                                }
                            />
                        </div>
                        <div>
                            <Label>Amendment Reason *</Label>
                            <Textarea
                                placeholder="Why is this entry being amended?"
                                value={editForm.amendment_reason}
                                onChange={(e) =>
                                    setEditForm({
                                        ...editForm,
                                        amendment_reason: e.target.value,
                                    })
                                }
                            />
                        </div>
                    </div>
                    <DialogFooter>
                        <Button
                            variant="outline"
                            onClick={() => setEditEntry(null)}
                        >
                            Cancel
                        </Button>
                        <Button
                            onClick={submitEditEntry}
                            disabled={
                                !editForm.amendment_reason.trim() ||
                                processing === 'edit'
                            }
                        >
                            {processing === 'edit' ? (
                                <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                            ) : null}{' '}
                            Save Changes
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            {/* Clock On Behalf Dialog */}
            <Dialog
                open={showClockOnBehalf}
                onOpenChange={setShowClockOnBehalf}
            >
                <DialogContent className="sm:max-w-lg">
                    <DialogHeader>
                        <DialogTitle className="flex items-center gap-2">
                            <div className="flex h-8 w-8 items-center justify-center rounded-lg bg-primary/10 text-primary">
                                <UserPlus className="h-4 w-4" />
                            </div>
                            Clock On Behalf
                        </DialogTitle>
                        <DialogDescription>
                            Create a time entry for a team member who forgot to
                            clock in or out. This will be recorded as an admin
                            entry with your name as the creator.
                        </DialogDescription>
                    </DialogHeader>

                    <div className="space-y-5">
                        {/* Staff member selection */}
                        <div className="rounded-lg border bg-muted/30 p-4">
                            <Label className="text-xs font-semibold tracking-wider text-muted-foreground uppercase">
                                Staff Member
                            </Label>
                            <Select
                                value={cobForm.target_user_id}
                                onValueChange={(v) =>
                                    setCobForm({
                                        ...cobForm,
                                        target_user_id: v,
                                    })
                                }
                            >
                                <SelectTrigger className="mt-2 bg-background">
                                    <SelectValue placeholder="Select a team member..." />
                                </SelectTrigger>
                                <SelectContent>
                                    {teamMembers.map((m) => (
                                        <SelectItem
                                            key={m.id}
                                            value={String(m.id)}
                                        >
                                            <span className="flex items-center gap-2">
                                                <span className="flex h-6 w-6 items-center justify-center rounded-full bg-primary/10 text-[10px] font-medium text-primary">
                                                    {m.name
                                                        .split(' ')
                                                        .map((n) => n[0])
                                                        .join('')
                                                        .slice(0, 2)}
                                                </span>
                                                {m.name}
                                            </span>
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>

                        {/* Time details */}
                        <div>
                            <Label className="text-xs font-semibold tracking-wider text-muted-foreground uppercase">
                                Shift Times
                            </Label>
                            <div className="mt-2 grid grid-cols-2 gap-3">
                                <div>
                                    <Label className="text-sm">
                                        Clock In{' '}
                                        <span className="text-status-critical">
                                            *
                                        </span>
                                    </Label>
                                    <Input
                                        type="datetime-local"
                                        className="mt-1"
                                        value={cobForm.clock_in}
                                        onChange={(e) =>
                                            setCobForm({
                                                ...cobForm,
                                                clock_in: e.target.value,
                                            })
                                        }
                                    />
                                </div>
                                <div>
                                    <Label className="text-sm">Clock Out</Label>
                                    <Input
                                        type="datetime-local"
                                        className="mt-1"
                                        value={cobForm.clock_out}
                                        onChange={(e) =>
                                            setCobForm({
                                                ...cobForm,
                                                clock_out: e.target.value,
                                            })
                                        }
                                    />
                                    <p className="mt-1 text-[10px] text-muted-foreground">
                                        Leave blank if still on shift
                                    </p>
                                </div>
                            </div>
                        </div>

                        {/* Pay & Break details */}
                        <div className="grid grid-cols-2 gap-3">
                            <div>
                                <Label className="text-sm">
                                    Break (minutes)
                                </Label>
                                <Input
                                    type="number"
                                    className="mt-1"
                                    min={0}
                                    max={480}
                                    value={cobForm.break_minutes}
                                    onChange={(e) =>
                                        setCobForm({
                                            ...cobForm,
                                            break_minutes: Number(
                                                e.target.value,
                                            ),
                                        })
                                    }
                                />
                            </div>
                            <div>
                                <Label className="text-sm">Pay Type</Label>
                                <Select
                                    value={cobForm.pay_type}
                                    onValueChange={(v) =>
                                        setCobForm({ ...cobForm, pay_type: v })
                                    }
                                >
                                    <SelectTrigger className="mt-1">
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {Object.entries(payTypeConfig).map(
                                            ([k, v]) => (
                                                <SelectItem key={k} value={k}>
                                                    <span className="flex items-center gap-2">
                                                        <span
                                                            className={`h-2 w-2 rounded-full ${v.className.includes('indigo') ? 'bg-primary' : v.className.includes('purple') ? 'bg-primary' : v.className.includes('orange') ? 'bg-status-warning' : v.className.includes('cyan') ? 'bg-status-info' : v.className.includes('blue') ? 'bg-status-info' : v.className.includes('violet') ? 'bg-primary' : 'bg-muted-foreground/80'}`}
                                                        />
                                                        {v.label}
                                                    </span>
                                                </SelectItem>
                                            ),
                                        )}
                                    </SelectContent>
                                </Select>
                            </div>
                        </div>

                        {/* Reason & Notes */}
                        <div>
                            <Label className="text-sm">
                                Reason for Entry{' '}
                                <span className="text-status-critical">*</span>
                            </Label>
                            <Textarea
                                className="mt-1"
                                placeholder="e.g., Staff member forgot to clock in due to emergency handover"
                                value={cobForm.reason}
                                onChange={(e) =>
                                    setCobForm({
                                        ...cobForm,
                                        reason: e.target.value,
                                    })
                                }
                                rows={2}
                            />
                        </div>
                        <div>
                            <Label className="text-sm">Additional Notes</Label>
                            <Input
                                className="mt-1"
                                placeholder="Optional notes for the time entry"
                                value={cobForm.notes}
                                onChange={(e) =>
                                    setCobForm({
                                        ...cobForm,
                                        notes: e.target.value,
                                    })
                                }
                            />
                        </div>

                        {/* Summary preview */}
                        {cobForm.target_user_id && cobForm.clock_in && (
                            <div className="rounded-lg border border-primary/20 bg-primary/5 p-3">
                                <p className="text-xs font-medium text-primary">
                                    Entry Preview
                                </p>
                                <div className="mt-1.5 grid grid-cols-2 gap-x-4 gap-y-1 text-sm">
                                    <span className="text-muted-foreground">
                                        Staff:
                                    </span>
                                    <span className="font-medium">
                                        {
                                            teamMembers.find(
                                                (m) =>
                                                    String(m.id) ===
                                                    cobForm.target_user_id,
                                            )?.name
                                        }
                                    </span>
                                    <span className="text-muted-foreground">
                                        Clock In:
                                    </span>
                                    <span>
                                        {cobForm.clock_in.replace('T', ' ')}
                                    </span>
                                    {cobForm.clock_out && (
                                        <>
                                            <span className="text-muted-foreground">
                                                Clock Out:
                                            </span>
                                            <span>
                                                {cobForm.clock_out.replace(
                                                    'T',
                                                    ' ',
                                                )}
                                            </span>
                                        </>
                                    )}
                                    <span className="text-muted-foreground">
                                        Pay Type:
                                    </span>
                                    <span>
                                        <Badge
                                            variant="outline"
                                            className={`text-[10px] ${payTypeConfig[cobForm.pay_type]?.className}`}
                                        >
                                            {
                                                payTypeConfig[cobForm.pay_type]
                                                    ?.label
                                            }
                                        </Badge>
                                    </span>
                                </div>
                            </div>
                        )}
                    </div>

                    <DialogFooter className="gap-2 sm:gap-0">
                        <Button
                            variant="outline"
                            onClick={() => setShowClockOnBehalf(false)}
                        >
                            Cancel
                        </Button>
                        <Button
                            onClick={submitClockOnBehalf}
                            disabled={
                                !cobForm.target_user_id ||
                                !cobForm.clock_in ||
                                !cobForm.reason.trim() ||
                                processing === 'cob'
                            }
                        >
                            {processing === 'cob' ? (
                                <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                            ) : (
                                <UserPlus className="mr-2 h-4 w-4" />
                            )}
                            Create Time Entry
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            {/* Return Dialog */}
            <Dialog
                open={!!returnTimesheetId}
                onOpenChange={(open) => !open && setReturnTimesheetId(null)}
            >
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Return Timesheet for Changes</DialogTitle>
                        <DialogDescription>
                            The timesheet will be returned for the staff member
                            to correct and resubmit.
                        </DialogDescription>
                    </DialogHeader>
                    <div>
                        <Label>Return Notes *</Label>
                        <Textarea
                            placeholder="What needs to be corrected?"
                            value={returnNotes}
                            onChange={(e) => setReturnNotes(e.target.value)}
                        />
                    </div>
                    <DialogFooter>
                        <Button
                            variant="outline"
                            onClick={() => setReturnTimesheetId(null)}
                        >
                            Cancel
                        </Button>
                        <Button
                            className="bg-status-warning hover:bg-status-warning"
                            onClick={submitReturn}
                            disabled={!returnNotes.trim()}
                        >
                            <RotateCcw className="mr-2 h-4 w-4" /> Return for
                            Changes
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            {/* Reject Dialog */}
            <Dialog
                open={!!rejectTimesheetId}
                onOpenChange={(open) => !open && setRejectTimesheetId(null)}
            >
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Reject Timesheet</DialogTitle>
                        <DialogDescription>
                            This timesheet will be permanently rejected.
                        </DialogDescription>
                    </DialogHeader>
                    <div>
                        <Label>Rejection Reason *</Label>
                        <Textarea
                            placeholder="Why is this timesheet being rejected?"
                            value={rejectReason}
                            onChange={(e) => setRejectReason(e.target.value)}
                        />
                    </div>
                    <DialogFooter>
                        <Button
                            variant="outline"
                            onClick={() => setRejectTimesheetId(null)}
                        >
                            Cancel
                        </Button>
                        <Button
                            variant="destructive"
                            onClick={submitReject}
                            disabled={!rejectReason.trim()}
                        >
                            <XCircle className="mr-2 h-4 w-4" /> Reject
                            Timesheet
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            {/* Bulk Reject Dialog */}
            <Dialog open={showBulkReject} onOpenChange={setShowBulkReject}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>
                            Reject {selectedApprovalIds.length} Timesheet(s)
                        </DialogTitle>
                    </DialogHeader>
                    <div>
                        <Label>Rejection Reason *</Label>
                        <Textarea
                            value={bulkNotes}
                            onChange={(e) => setBulkNotes(e.target.value)}
                        />
                    </div>
                    <DialogFooter>
                        <Button
                            variant="outline"
                            onClick={() => setShowBulkReject(false)}
                        >
                            Cancel
                        </Button>
                        <Button
                            variant="destructive"
                            onClick={submitBulkReject}
                            disabled={
                                !bulkNotes.trim() || processing === 'bulk'
                            }
                        >
                            Reject All
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            {/* Bulk Return Dialog */}
            <Dialog open={showBulkReturn} onOpenChange={setShowBulkReturn}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>
                            Return {selectedApprovalIds.length} Timesheet(s) for
                            Changes
                        </DialogTitle>
                    </DialogHeader>
                    <div>
                        <Label>Return Notes *</Label>
                        <Textarea
                            value={bulkNotes}
                            onChange={(e) => setBulkNotes(e.target.value)}
                        />
                    </div>
                    <DialogFooter>
                        <Button
                            variant="outline"
                            onClick={() => setShowBulkReturn(false)}
                        >
                            Cancel
                        </Button>
                        <Button
                            className="bg-status-warning hover:bg-status-warning"
                            onClick={submitBulkReturn}
                            disabled={
                                !bulkNotes.trim() || processing === 'bulk'
                            }
                        >
                            Return All
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
