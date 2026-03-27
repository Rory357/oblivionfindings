import AppLayout from '@/layouts/app-layout';
import PageHeader from '@/components/page-header';
import PageShell from '@/components/page-shell';
import { KpiCard } from '@/components/dashboard/kpi-card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Progress } from '@/components/ui/progress';
import { Checkbox } from '@/components/ui/checkbox';
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip';
import { Head, Link, router } from '@inertiajs/react';
import {
    AlertTriangle,
    ArrowRight,
    Bell,
    Calendar,
    CheckCircle2,
    ChevronDown,
    ChevronRight,
    ChevronUp,
    Clock,
    ClipboardList,
    ExternalLink,
    FileText,
    Flame,
    ListChecks,
    ListTodo,
    LogIn,
    LogOut,
    MapPin,
    MessageSquarePlus,
    OctagonAlert,
    Pill,
    RefreshCw,
    Shield,
    StickyNote,
    Sun,
    Timer,
    User,
    Users,
} from 'lucide-react';
import { useEffect, useState } from 'react';

// ---------------------------------------------------------------------------
// Types
// ---------------------------------------------------------------------------

interface ShiftClient {
    id: number;
    name: string;
    photo_url: string | null;
}

interface ShiftTaskItem {
    id: number;
    label: string;
    is_completed: boolean;
    completed_at: string | null;
}

interface MyShift {
    id: number;
    starts_at: string;
    ends_at: string;
    actual_starts_at: string | null;
    actual_ends_at: string | null;
    status: string;
    location: string | null;
    service_type: string | null;
    client: ShiftClient;
    tasks: ShiftTaskItem[];
    task_progress: number;
    is_today: boolean;
}

interface MedDue {
    client_name: string;
    medication_name: string;
    dose: string;
    scheduled_for: string;
    status: 'overdue' | 'due' | 'upcoming';
    emar_url: string;
}

interface MyTimesheet {
    id: number;
    work_date: string;
    client_name: string;
    hours: number;
    status: string;
    return_notes: string | null;
}

interface MyIncident {
    id: number;
    title: string;
    client_name: string;
    severity: string;
    status: string;
    occurred_at: string;
    url: string;
    requires_followup: boolean;
}

interface Task {
    id: string;
    type: 'alert' | 'followup' | 'note_followup';
    title: string;
    priority: 'critical' | 'high' | 'medium' | 'low';
    status: string;
    source_url: string;
    due_at: string | null;
    created_at: string;
    meta: {
        source?: string;
        client_name?: string;
        sla_status?: string;
        asset_name?: string;
    };
}

interface Props {
    today: string;
    shifts: MyShift[];
    medications_due: MedDue[];
    timesheets: MyTimesheet[];
    incidents: MyIncident[];
    tasks: Task[];
    stats: {
        shifts_today: number;
        meds_due: number;
        meds_overdue: number;
        tasks_open: number;
        timesheets_pending: number;
        incidents_open: number;
        cr_alerts: number;
        notifications_unread: number;
    };
    leave: {
        balances: Array<{ type: string; remaining_hours: number; total_hours: number }>;
        pending_requests: number;
    };
    is_manager: boolean;
    manager_data?: {
        team_shifts_today: number;
        unassigned_shifts: number;
        timesheets_pending_approval: number;
        staff_on_today: number;
    };
}

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

type OpenItemFilter = 'all' | 'shift' | 'alert' | 'incident' | 'followup' | 'calendar';

const priorityOrder: Record<string, number> = { critical: 0, high: 1, medium: 2, low: 3 };

const priorityConfig: Record<string, { border: string; badge: string; bg: string; icon: typeof AlertTriangle | null }> = {
    critical: { border: 'border-l-red-600', badge: 'bg-red-600 text-white', bg: 'bg-red-50/60 dark:bg-red-950/20', icon: Flame },
    high: { border: 'border-l-orange-500', badge: 'bg-orange-500 text-white', bg: 'bg-orange-50/40 dark:bg-orange-950/10', icon: AlertTriangle },
    medium: { border: 'border-l-yellow-500', badge: 'bg-yellow-500 text-black', bg: '', icon: null },
    low: { border: 'border-l-blue-400', badge: 'bg-blue-500 text-white', bg: '', icon: null },
};

const typeConfig: Record<string, { badge: string; icon: typeof Bell; label: string }> = {
    shift: { badge: 'bg-blue-100 text-blue-800 border-blue-200 dark:bg-blue-900/30 dark:text-blue-300', icon: Calendar, label: 'Shift' },
    alert: { badge: 'bg-purple-100 text-purple-800 border-purple-200 dark:bg-purple-900/30 dark:text-purple-300', icon: Bell, label: 'Alert' },
    followup: { badge: 'bg-sky-100 text-sky-800 border-sky-200 dark:bg-sky-900/30 dark:text-sky-300', icon: ClipboardList, label: 'Follow-up' },
    note_followup: { badge: 'bg-slate-100 text-slate-700 border-slate-200 dark:bg-slate-800 dark:text-slate-300', icon: FileText, label: 'Note' },
};

function formatTime(iso: string): string {
    return new Date(iso).toLocaleTimeString('en-NZ', { hour: 'numeric', minute: '2-digit', hour12: true });
}

function formatRelative(iso: string): string {
    const d = new Date(iso);
    const now = new Date();
    const diffMs = now.getTime() - d.getTime();
    const mins = Math.floor(diffMs / 60000);
    const hrs = Math.floor(mins / 60);
    const days = Math.floor(hrs / 24);
    if (mins < 1) return 'Just now';
    if (mins < 60) return `${mins}m ago`;
    if (hrs < 24) return `${hrs}h ago`;
    if (days < 7) return `${days}d ago`;
    return d.toLocaleDateString('en-NZ', { day: 'numeric', month: 'short' });
}

function shiftBorderColor(shift: MyShift): string {
    if (shift.status === 'in_progress') return 'border-l-green-500';
    if (shift.status === 'completed') return 'border-l-yellow-500';
    if (shift.is_today) return 'border-l-blue-500';
    return 'border-l-gray-300 dark:border-l-gray-600';
}

function shiftStatusBadge(status: string): { variant: 'default' | 'secondary' | 'outline' | 'destructive'; label: string } {
    switch (status) {
        case 'in_progress': return { variant: 'default', label: 'In Progress' };
        case 'completed': return { variant: 'secondary', label: 'Completed' };
        case 'scheduled': return { variant: 'outline', label: 'Scheduled' };
        case 'cancelled': return { variant: 'destructive', label: 'Cancelled' };
        default: return { variant: 'outline', label: status };
    }
}

function timesheetStatusBadge(status: string): string {
    switch (status) {
        case 'draft': return 'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300';
        case 'submitted': return 'bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-300';
        case 'approved': return 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-300';
        case 'returned': return 'bg-yellow-100 text-yellow-700 dark:bg-yellow-900/30 dark:text-yellow-300';
        default: return 'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300';
    }
}

function medStatusStyle(status: 'overdue' | 'due' | 'upcoming'): string {
    switch (status) {
        case 'overdue': return 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-300';
        case 'due': return 'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-300';
        case 'upcoming': return 'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-400';
    }
}

function ClientAvatar({ client }: { client: ShiftClient }) {
    if (client.photo_url) {
        return <img src={client.photo_url} alt={client.name} className="h-10 w-10 rounded-full object-cover" />;
    }
    const initials = client.name.split(' ').map(n => n[0]).join('').slice(0, 2).toUpperCase();
    return (
        <div className="flex h-10 w-10 items-center justify-center rounded-full bg-primary/10 text-sm font-semibold text-primary">
            {initials}
        </div>
    );
}

// ---------------------------------------------------------------------------
// Component
// ---------------------------------------------------------------------------

export default function MyDay({
    today,
    shifts,
    medications_due,
    timesheets,
    incidents,
    tasks,
    stats,
    leave,
    is_manager,
    manager_data,
}: Props) {
    const [isRefreshing, setIsRefreshing] = useState(false);
    const [shiftsOpen, setShiftsOpen] = useState(true);
    const [openItemFilter, setOpenItemFilter] = useState<OpenItemFilter>('all');

    // Auto-refresh every 60s
    useEffect(() => {
        const interval = setInterval(() => {
            if (!document.hidden) router.reload({ preserveScroll: true });
        }, 60000);
        return () => clearInterval(interval);
    }, []);

    const handleRefresh = () => {
        setIsRefreshing(true);
        router.reload({ preserveScroll: true, onFinish: () => setIsRefreshing(false) });
    };

    const handleClockIn = (shiftId: number) => {
        router.post(`/my-tasks/clock-in/${shiftId}`, {}, { preserveScroll: true });
    };

    const handleClockOut = (shiftId: number) => {
        router.post(`/my-tasks/clock-out/${shiftId}`, {}, { preserveScroll: true });
    };

    const handleTaskComplete = (taskId: number) => {
        router.post(`/my-tasks/shift-task/${taskId}/complete`, {}, { preserveScroll: true });
    };

    const handleTimesheetSubmit = (tsId: number) => {
        router.post(`/my-tasks/timesheet/${tsId}/submit`, {}, { preserveScroll: true });
    };

    // Build unified open-items list
    const openItems: Array<{
        id: string;
        type: 'alert' | 'followup' | 'note_followup' | 'incident';
        title: string;
        priority: string;
        client_name?: string;
        url: string;
        time: string;
    }> = [];

    shifts.forEach((s) => {
        openItems.push({
            id: `shift-${s.id}`,
            type: 'shift' as any,
            title: `${s.client.name} — ${formatTime(s.starts_at)} to ${formatTime(s.ends_at)}`,
            priority: s.status === 'in_progress' ? 'high' : 'medium',
            client_name: s.client.name,
            url: `/clients/${s.client.id}`,
            time: s.starts_at,
        });
    });

    tasks.forEach((t) => {
        openItems.push({
            id: `task-${t.id}`,
            type: t.type,
            title: t.title,
            priority: t.priority,
            client_name: t.meta.client_name,
            url: t.source_url,
            time: t.created_at,
        });
    });

    incidents.forEach((inc) => {
        openItems.push({
            id: `inc-${inc.id}`,
            type: 'incident',
            title: inc.title,
            priority: inc.severity,
            client_name: inc.client_name,
            url: inc.url,
            time: inc.occurred_at,
        });
    });

    const filteredOpenItems = openItemFilter === 'all'
        ? openItems
        : openItemFilter === 'shift'
            ? openItems.filter((i) => i.type === 'shift')
            : openItemFilter === 'incident'
                ? openItems.filter((i) => i.type === 'incident')
                : openItemFilter === 'alert'
                    ? openItems.filter((i) => i.type === 'alert')
                    : openItemFilter === 'calendar'
                        ? [] // calendar tab shows a different view
                        : openItems.filter((i) => i.type === 'followup' || i.type === 'note_followup');

    const sortedOpenItems = [...filteredOpenItems].sort((a, b) => {
        const pa = priorityOrder[a.priority] ?? 3;
        const pb = priorityOrder[b.priority] ?? 3;
        if (pa !== pb) return pa - pb;
        return new Date(b.time).getTime() - new Date(a.time).getTime();
    });

    const openItemCounts: Record<string, number> = {
        all: openItems.length,
        shift: shifts.length,
        alert: openItems.filter((i) => i.type === 'alert').length,
        incident: openItems.filter((i) => i.type === 'incident').length,
        followup: openItems.filter((i) => i.type === 'followup' || i.type === 'note_followup').length,
        calendar: shifts.length,
    };

    // Build calendar week data (7 days starting from today)
    const calendarDays: Array<{ date: string; dayName: string; dayNum: number; isToday: boolean; shifts: typeof shifts }> = [];
    for (let i = 0; i < 7; i++) {
        const d = new Date();
        d.setDate(d.getDate() + i);
        const dateStr = d.toISOString().split('T')[0];
        calendarDays.push({
            date: dateStr,
            dayName: d.toLocaleDateString('en-NZ', { weekday: 'short' }),
            dayNum: d.getDate(),
            isToday: i === 0,
            shifts: shifts.filter((s) => s.starts_at.startsWith(dateStr)),
        });
    }

    // Sort meds: overdue first, then by scheduled_for
    const sortedMeds = [...medications_due].sort((a, b) => {
        const statusOrder = { overdue: 0, due: 1, upcoming: 2 };
        const sd = statusOrder[a.status] - statusOrder[b.status];
        if (sd !== 0) return sd;
        return new Date(a.scheduled_for).getTime() - new Date(b.scheduled_for).getTime();
    });

    return (
        <AppLayout breadcrumbs={[{ title: 'My Day', href: '/my-tasks' }]}>
            <Head title="My Day" />
            <PageShell>
                {/* ── Header ────────────────────────────────────────────── */}
                <PageHeader
                    title="My Day"
                    description={today}
                    actions={
                        <div className="flex items-center gap-2">
                            <Button variant="ghost" size="sm" onClick={handleRefresh} disabled={isRefreshing}>
                                <RefreshCw className={`mr-2 h-4 w-4 ${isRefreshing ? 'animate-spin' : ''}`} />
                                Refresh
                            </Button>
                            <Button variant="outline" size="sm" asChild className="relative">
                                <Link href="/notifications">
                                    <Bell className="h-4 w-4" />
                                    {stats.notifications_unread > 0 && (
                                        <span className="absolute -right-1 -top-1 flex h-5 min-w-[20px] items-center justify-center rounded-full bg-red-500 px-1 text-[10px] font-bold text-white">
                                            {stats.notifications_unread}
                                        </span>
                                    )}
                                </Link>
                            </Button>
                        </div>
                    }
                />

                {/* ── Stats Row ─────────────────────────────────────────── */}
                <div className="grid grid-cols-2 gap-4 md:grid-cols-3 lg:grid-cols-6">
                    <KpiCard
                        label="Shifts Today"
                        value={stats.shifts_today}
                        icon={Calendar}
                        className="border-blue-200 dark:border-blue-800"
                    />
                    <KpiCard
                        label="Meds Due"
                        value={stats.meds_due}
                        icon={Pill}
                        className={stats.meds_overdue > 0 ? 'border-red-300 bg-red-50/50 dark:border-red-800 dark:bg-red-950/20' : undefined}
                    />
                    <KpiCard
                        label="Open Tasks"
                        value={stats.tasks_open}
                        icon={ListChecks}
                    />
                    <KpiCard
                        label="Timesheets"
                        value={stats.timesheets_pending}
                        icon={Clock}
                        className={stats.timesheets_pending > 0 ? 'border-yellow-300 bg-yellow-50/50 dark:border-yellow-800 dark:bg-yellow-950/20' : undefined}
                    />
                    <KpiCard
                        label="Incidents"
                        value={stats.incidents_open}
                        icon={AlertTriangle}
                        className={stats.incidents_open > 0 ? 'border-red-300 bg-red-50/50 dark:border-red-800 dark:bg-red-950/20' : undefined}
                    />
                    <KpiCard
                        label="CR Alerts"
                        value={stats.cr_alerts}
                        icon={Bell}
                        className={stats.cr_alerts > 0 ? 'border-red-300 bg-red-50/50 dark:border-red-800 dark:bg-red-950/20' : undefined}
                    />
                </div>

                {/* ── Manager Banner ────────────────────────────────────── */}
                {is_manager && manager_data && (
                    <Card className="border-blue-200 bg-blue-50/60 dark:border-blue-800 dark:bg-blue-950/20">
                        <CardContent className="flex flex-col gap-4 p-4 sm:flex-row sm:items-center sm:justify-between">
                            <div className="flex flex-wrap items-center gap-x-6 gap-y-2 text-sm">
                                <div className="flex items-center gap-2">
                                    <Users className="h-4 w-4 text-blue-600 dark:text-blue-400" />
                                    <span className="font-semibold">{manager_data.staff_on_today}</span>
                                    <span className="text-muted-foreground">staff on today</span>
                                </div>
                                <div className="hidden h-4 w-px bg-blue-300 dark:bg-blue-700 sm:block" />
                                <div>
                                    <span className="font-semibold">{manager_data.unassigned_shifts}</span>{' '}
                                    <span className="text-muted-foreground">unassigned shifts</span>
                                </div>
                                <div className="hidden h-4 w-px bg-blue-300 dark:bg-blue-700 sm:block" />
                                <div>
                                    <span className="font-semibold">{manager_data.timesheets_pending_approval}</span>{' '}
                                    <span className="text-muted-foreground">timesheets to approve</span>
                                </div>
                                <div className="hidden h-4 w-px bg-blue-300 dark:bg-blue-700 sm:block" />
                                <div>
                                    <span className="font-semibold">{manager_data.team_shifts_today}</span>{' '}
                                    <span className="text-muted-foreground">total shifts</span>
                                </div>
                            </div>
                            <Button variant="outline" size="sm" asChild>
                                <Link href="/operations">
                                    View Operations Dashboard
                                    <ArrowRight className="ml-2 h-4 w-4" />
                                </Link>
                            </Button>
                        </CardContent>
                    </Card>
                )}

                {/* My Shifts section removed - shifts now in Open Items tabs */}

                {/* ── Section 2: Medications Due ────────────────────────── */}
                {medications_due.length > 0 && (
                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2 text-lg">
                                <Pill className="h-5 w-5" />
                                Medications Due
                                {stats.meds_overdue > 0 && (
                                    <Badge variant="destructive" className="ml-1">{stats.meds_overdue} overdue</Badge>
                                )}
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="overflow-x-auto">
                                <table className="w-full text-sm">
                                    <thead>
                                        <tr className="border-b text-left text-xs font-medium text-muted-foreground">
                                            <th className="pb-2 pr-4">Client</th>
                                            <th className="pb-2 pr-4">Medication</th>
                                            <th className="pb-2 pr-4">Dose</th>
                                            <th className="pb-2 pr-4">Time</th>
                                            <th className="pb-2">Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {sortedMeds.map((med, idx) => (
                                            <tr key={idx} className="border-b last:border-0 hover:bg-muted/50">
                                                <td className="py-2.5 pr-4 font-medium">{med.client_name}</td>
                                                <td className="py-2.5 pr-4">
                                                    <Link href={med.emar_url} className="text-primary hover:underline">
                                                        {med.medication_name}
                                                    </Link>
                                                </td>
                                                <td className="py-2.5 pr-4 text-muted-foreground">{med.dose}</td>
                                                <td className="py-2.5 pr-4 text-muted-foreground">{formatTime(med.scheduled_for)}</td>
                                                <td className="py-2.5">
                                                    <span className={`relative inline-flex items-center gap-1.5 rounded-full px-2 py-0.5 text-xs font-medium ${medStatusStyle(med.status)}`}>
                                                        {med.status === 'overdue' && (
                                                            <span className="absolute -left-0.5 -top-0.5 flex h-2 w-2">
                                                                <span className="absolute inline-flex h-full w-full animate-ping rounded-full bg-red-400 opacity-75" />
                                                                <span className="relative inline-flex h-2 w-2 rounded-full bg-red-500" />
                                                            </span>
                                                        )}
                                                        <span className={med.status === 'overdue' ? 'ml-2' : ''}>{med.status}</span>
                                                    </span>
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        </CardContent>
                    </Card>
                )}

                {/* ── Section 3: My Timesheets ──────────────────────────── */}
                {timesheets.length > 0 && (
                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2 text-lg">
                                <Clock className="h-5 w-5" />
                                My Timesheets
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="overflow-x-auto">
                                <table className="w-full text-sm">
                                    <thead>
                                        <tr className="border-b text-left text-xs font-medium text-muted-foreground">
                                            <th className="pb-2 pr-4">Date</th>
                                            <th className="pb-2 pr-4">Client</th>
                                            <th className="pb-2 pr-4">Hours</th>
                                            <th className="pb-2 pr-4">Status</th>
                                            <th className="pb-2">Action</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {timesheets.map((ts) => (
                                            <tr key={ts.id} className="border-b last:border-0 hover:bg-muted/50">
                                                <td className="py-2.5 pr-4 text-muted-foreground">
                                                    {new Date(ts.work_date).toLocaleDateString('en-NZ', { day: 'numeric', month: 'short' })}
                                                </td>
                                                <td className="py-2.5 pr-4 font-medium">{ts.client_name}</td>
                                                <td className="py-2.5 pr-4">{ts.hours}h</td>
                                                <td className="py-2.5 pr-4">
                                                    <TooltipProvider>
                                                        <Tooltip>
                                                            <TooltipTrigger asChild>
                                                                <span className={`inline-flex rounded-full px-2 py-0.5 text-xs font-medium ${timesheetStatusBadge(ts.status)}`}>
                                                                    {ts.status}
                                                                </span>
                                                            </TooltipTrigger>
                                                            {ts.return_notes && (
                                                                <TooltipContent>
                                                                    <p className="max-w-xs text-xs">{ts.return_notes}</p>
                                                                </TooltipContent>
                                                            )}
                                                        </Tooltip>
                                                    </TooltipProvider>
                                                </td>
                                                <td className="py-2.5">
                                                    {ts.status === 'draft' && (
                                                        <Button size="sm" variant="outline" onClick={() => handleTimesheetSubmit(ts.id)}>
                                                            Submit
                                                        </Button>
                                                    )}
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        </CardContent>
                    </Card>
                )}

                {/* ── Section 4: Open Items ─────────────────────────────── */}
                <Card>
                    <CardHeader>
                        <CardTitle className="flex items-center gap-2 text-lg">
                            <OctagonAlert className="h-5 w-5" />
                            Open Items
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-4 pt-0">
                            {/* Filter tabs */}
                            <div className="flex gap-1 rounded-lg border bg-muted/50 p-1">
                                {([
                                    { key: 'all' as OpenItemFilter, label: 'All' },
                                    { key: 'shift' as OpenItemFilter, label: 'Shifts' },
                                    { key: 'alert' as OpenItemFilter, label: 'Alerts' },
                                    { key: 'incident' as OpenItemFilter, label: 'Incidents' },
                                    { key: 'followup' as OpenItemFilter, label: 'Follow-ups' },
                                    { key: 'calendar' as OpenItemFilter, label: 'Calendar' },
                                ] as const).map((tab) => (
                                    <button
                                        key={tab.key}
                                        onClick={() => setOpenItemFilter(tab.key)}
                                        className={`flex items-center gap-1.5 rounded-md px-3 py-1.5 text-sm font-medium transition-colors ${
                                            openItemFilter === tab.key
                                                ? 'bg-background text-foreground shadow-sm'
                                                : 'text-muted-foreground hover:text-foreground'
                                        }`}
                                    >
                                        {tab.label}
                                        <span className={`ml-0.5 inline-flex h-5 min-w-[20px] items-center justify-center rounded-full px-1.5 text-xs font-semibold ${
                                            openItemFilter === tab.key
                                                ? 'bg-primary text-primary-foreground'
                                                : 'bg-muted-foreground/15 text-muted-foreground'
                                        }`}>
                                            {openItemCounts[tab.key]}
                                        </span>
                                    </button>
                                ))}
                            </div>

                            {/* Calendar view */}
                            {openItemFilter === 'calendar' ? (
                                <div>
                                    <div className="mb-3 flex items-center justify-between">
                                        <div className="flex items-center gap-2 text-sm text-muted-foreground">
                                            <Calendar className="h-4 w-4" />
                                            <span>Next 7 days</span>
                                        </div>
                                        <Button variant="outline" size="sm" asChild>
                                            <Link href="/my-calendar">View Full Calendar</Link>
                                        </Button>
                                    </div>
                                    <div className="grid grid-cols-7 gap-2">
                                        {calendarDays.map((day) => (
                                            <div
                                                key={day.date}
                                                className={`rounded-lg border p-2 ${
                                                    day.isToday
                                                        ? 'border-primary bg-primary/5'
                                                        : 'bg-card'
                                                }`}
                                            >
                                                <div className="mb-1.5 text-center">
                                                    <div className={`text-[10px] font-medium uppercase ${day.isToday ? 'text-primary' : 'text-muted-foreground'}`}>
                                                        {day.dayName}
                                                    </div>
                                                    <div className={`text-lg font-bold ${day.isToday ? 'text-primary' : ''}`}>
                                                        {day.dayNum}
                                                    </div>
                                                </div>
                                                {day.shifts.length === 0 ? (
                                                    <div className="rounded bg-muted/50 px-1.5 py-1 text-center text-[10px] text-muted-foreground">
                                                        No shifts
                                                    </div>
                                                ) : (
                                                    <div className="space-y-1">
                                                        {day.shifts.map((s) => (
                                                            <Link
                                                                key={s.id}
                                                                href={`/clients/${s.client.id}`}
                                                                className={`block rounded px-1.5 py-1 text-[10px] transition-colors hover:ring-1 hover:ring-primary/50 ${
                                                                    s.status === 'in_progress'
                                                                        ? 'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-300'
                                                                        : s.status === 'completed'
                                                                            ? 'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-400'
                                                                            : 'bg-blue-100 text-blue-800 dark:bg-blue-900/30 dark:text-blue-300'
                                                                }`}
                                                            >
                                                                <div className="truncate font-medium">{s.client.name}</div>
                                                                <div className="text-[9px] opacity-75">
                                                                    {formatTime(s.starts_at)}
                                                                </div>
                                                            </Link>
                                                        ))}
                                                    </div>
                                                )}
                                            </div>
                                        ))}
                                    </div>
                                </div>
                            ) : sortedOpenItems.length === 0 ? (
                                <div className="flex flex-col items-center py-8 text-center">
                                    <CheckCircle2 className="h-8 w-8 text-green-500" />
                                    <p className="mt-2 text-sm text-muted-foreground">No items in this category.</p>
                                </div>
                            ) : (
                                <div className="space-y-2">
                                    {sortedOpenItems.map((item) => {
                                        const isShift = item.type === 'shift';
                                        const isIncident = item.type === 'incident';
                                        const tConfig = isShift
                                            ? { badge: 'bg-blue-100 text-blue-800 border-blue-200 dark:bg-blue-900/30 dark:text-blue-300', icon: Calendar, label: 'Shift' }
                                            : isIncident
                                                ? { badge: 'bg-red-100 text-red-800 border-red-200 dark:bg-red-900/30 dark:text-red-300', icon: AlertTriangle, label: 'Incident' }
                                                : typeConfig[item.type] ?? typeConfig.alert;
                                        const pConfig = priorityConfig[item.priority] ?? priorityConfig.medium;
                                        const TypeIcon = tConfig.icon;
                                        const PriorityIcon = pConfig.icon;

                                        return (
                                            <Link
                                                key={item.id}
                                                href={item.url}
                                                className={`group flex items-stretch rounded-lg border border-l-4 ${pConfig.border} ${pConfig.bg} transition-all hover:shadow-md hover:border-primary/30`}
                                            >
                                                <div className="flex min-w-0 flex-1 items-center gap-4 px-4 py-3">
                                                    <div className={`flex h-9 w-9 shrink-0 items-center justify-center rounded-full ${tConfig.badge}`}>
                                                        <TypeIcon className="h-4 w-4" />
                                                    </div>
                                                    <div className="min-w-0 flex-1">
                                                        <div className="flex items-center gap-2">
                                                            <span className="truncate font-semibold text-foreground group-hover:text-primary transition-colors">
                                                                {item.title}
                                                            </span>
                                                            {PriorityIcon && (
                                                                <PriorityIcon className={`h-4 w-4 shrink-0 ${item.priority === 'critical' ? 'text-red-500' : 'text-orange-500'}`} />
                                                            )}
                                                        </div>
                                                        <div className="mt-0.5 flex flex-wrap items-center gap-x-3 gap-y-0.5 text-xs text-muted-foreground">
                                                            <Badge variant="outline" className={`${pConfig.badge} px-1.5 py-0 text-[10px]`}>
                                                                {item.priority}
                                                            </Badge>
                                                            <Badge variant="outline" className="px-1.5 py-0 text-[10px]">
                                                                {tConfig.label}
                                                            </Badge>
                                                            {item.client_name && (
                                                                <span className="flex items-center gap-1">
                                                                    <User className="h-3 w-3" />
                                                                    {item.client_name}
                                                                </span>
                                                            )}
                                                            <span className="flex items-center gap-1">
                                                                <Clock className="h-3 w-3" />
                                                                {formatRelative(item.time)}
                                                            </span>
                                                        </div>
                                                    </div>
                                                </div>
                                                <div className="flex shrink-0 items-center px-4">
                                                    <ChevronRight className="h-4 w-4 text-muted-foreground/50 transition-transform group-hover:translate-x-0.5" />
                                                </div>
                                            </Link>
                                        );
                                    })}
                                </div>
                            )}
                        </CardContent>
                    </Card>

                {/* ── Section 5: Leave Balance ──────────────────────────── */}
                {leave && leave.balances.length > 0 && (
                    <Card>
                        <CardHeader>
                            <div className="flex items-center justify-between">
                                <CardTitle className="text-lg">Leave Balances</CardTitle>
                                {leave.pending_requests > 0 && (
                                    <Badge variant="secondary">{leave.pending_requests} pending</Badge>
                                )}
                            </div>
                        </CardHeader>
                        <CardContent className="space-y-3 pt-0">
                            {leave.balances.map((bal) => {
                                const pct = bal.total_hours > 0 ? Math.round((bal.remaining_hours / bal.total_hours) * 100) : 0;
                                return (
                                    <div key={bal.type} className="space-y-1">
                                        <div className="flex items-center justify-between text-sm">
                                            <span className="font-medium">{bal.type}</span>
                                            <span className="text-muted-foreground">{bal.remaining_hours}h / {bal.total_hours}h</span>
                                        </div>
                                        <Progress value={pct} className="h-2" />
                                    </div>
                                );
                            })}
                            <Button variant="outline" size="sm" asChild className="mt-2">
                                <Link href="/hr/leave">
                                    Request Leave
                                    <ArrowRight className="ml-2 h-4 w-4" />
                                </Link>
                            </Button>
                        </CardContent>
                    </Card>
                )}

                {/* ── Footer Quick Links ────────────────────────────────── */}
                <div className="flex flex-wrap gap-2">
                    <Button variant="outline" size="sm" asChild>
                        <Link href="/operations">
                            <Shield className="mr-2 h-4 w-4" />
                            Operations Dashboard
                        </Link>
                    </Button>
                    <Button variant="outline" size="sm" asChild>
                        <Link href="/control-room">
                            <Bell className="mr-2 h-4 w-4" />
                            Control Room
                        </Link>
                    </Button>
                    <Button variant="outline" size="sm" asChild>
                        <Link href="/control-room/alerts">
                            <ListTodo className="mr-2 h-4 w-4" />
                            All Alerts
                        </Link>
                    </Button>
                    <Button variant="outline" size="sm" asChild>
                        <Link href="/emar">
                            <Pill className="mr-2 h-4 w-4" />
                            eMAR
                        </Link>
                    </Button>
                </div>
            </PageShell>
        </AppLayout>
    );
}
