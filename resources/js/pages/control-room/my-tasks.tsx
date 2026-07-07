import AppLayout from '@/layouts/app-layout';
import PageShell from '@/components/page-shell';
import { AlertWorkspaceDialog, ConfirmChip, type AlertWorkspaceDetail } from '@/components/control-room/alert-workspace-dialog';
import { KpiCard } from '@/components/dashboard/kpi-card';
import { Head, Link, router } from '@inertiajs/react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { PageHero } from '@/components/page';
import {
    AlertTriangle,
    ArrowRight,
    Bell,
    Calendar,
    CheckCircle,
    Clock,
    ExternalLink,
    ListChecks,
    Plus,
    Shield,
} from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';

// --- TypeScript Interfaces ---

interface AlertAsset {
    id: number;
    name: string;
    asset_tag: string;
}

interface MyAlert {
    id: number;
    source: string;
    alert_type: string;
    severity: string;
    status: string;
    escalation_level: number | null;
    triggered_at: string | null;
    acknowledged_at: string | null;
    asset: AlertAsset | null;
    client_name: string | null;
    sla_status: 'on_track' | 'at_risk' | 'breached' | null;
}

interface FollowupAlert {
    id: number;
    alert_type: string;
    severity: string;
    status: string;
}

interface MyFollowup {
    id: number;
    content: string;
    type: string;
    followup_at: string | null;
    created_at: string | null;
    alert: FollowupAlert | null;
}

interface MyShift {
    id: number;
    name: string;
    role: string;
    starts_at: string;
    duration_minutes: number;
    alerts_created: number;
    alerts_resolved: number;
    alerts_escalated: number;
}

interface Stats {
    my_open: number;
    my_resolved_today: number;
    my_critical: number;
}

interface Can {
    manage: boolean;
    create: boolean;
    assign: boolean;
    escalate: boolean;
}

interface Props {
    my_alerts: MyAlert[];
    my_followups: MyFollowup[];
    my_shift: MyShift | null;
    stats: Stats;
    can: Can;
    /** Workspace-over-list: present when ?alert= is in the URL. */
    detail?: AlertWorkspaceDetail | null;
}

// --- Helpers ---

const severityColors: Record<string, string> = {
    critical: 'bg-status-critical-bg text-status-critical dark:bg-status-critical-bg dark:text-status-critical',
    high: 'bg-status-warning-bg text-status-warning dark:bg-status-warning-bg dark:text-status-warning',
    medium: 'bg-status-warning-bg text-status-warning dark:bg-status-warning-bg dark:text-status-warning',
    low: 'bg-status-info-bg text-status-info dark:bg-status-info-bg dark:text-status-info',
};

const statusColors: Record<string, string> = {
    open: 'bg-status-critical-bg text-status-critical dark:bg-status-critical-bg dark:text-status-critical',
    ack: 'bg-status-info-bg text-status-info dark:bg-status-info-bg dark:text-status-info',
    triaging: 'bg-primary/10 text-primary dark:bg-primary/40 dark:text-primary/70',
    resolved: 'bg-status-success-bg text-status-success dark:bg-status-success-bg dark:text-status-success',
    closed: 'bg-muted text-foreground dark:bg-muted/40 dark:text-muted-foreground',
};

const slaStatusDot: Record<string, string> = {
    on_track: 'bg-status-success',
    at_risk: 'bg-status-warning',
    breached: 'bg-status-critical',
};

const severityBorder: Record<string, string> = {
    critical: 'border-l-red-500',
    high: 'border-l-orange-500',
    medium: 'border-l-yellow-500',
    low: 'border-l-blue-500',
};

function timeAgo(iso: string | null): string {
    if (!iso) return '';
    const diff = Date.now() - new Date(iso).getTime();
    const mins = Math.floor(diff / 60000);
    if (mins < 1) return 'just now';
    if (mins < 60) return `${mins}m ago`;
    const hrs = Math.floor(mins / 60);
    if (hrs < 24) return `${hrs}h ago`;
    const days = Math.floor(hrs / 24);
    return `${days}d ago`;
}

function formatDuration(minutes: number): string {
    const hrs = Math.floor(minutes / 60);
    const mins = minutes % 60;
    if (hrs === 0) return `${mins}m`;
    return `${hrs}h ${mins}m`;
}

function formatFollowupDate(iso: string | null): string {
    if (!iso) return 'No date set';
    const d = new Date(iso);
    const now = new Date();
    const diffMs = d.getTime() - now.getTime();
    const diffDays = Math.floor(diffMs / (1000 * 60 * 60 * 24));

    if (diffDays < 0) return `Overdue by ${Math.abs(diffDays)}d`;
    if (diffDays === 0) return 'Due today';
    if (diffDays === 1) return 'Due tomorrow';
    return `Due in ${diffDays}d`;
}

// --- Component ---

const breadcrumbs = [
    { title: 'Control Room', href: '/control-room' },
    { title: 'My Tasks', href: '#' },
];

export default function MyTasks({ my_alerts, my_followups, my_shift, stats, can, detail = null }: Props) {
    // Auto-refresh every 30 seconds
    const refreshTimer = useRef<ReturnType<typeof setInterval> | null>(null);

    useEffect(() => {
        refreshTimer.current = setInterval(() => {
            router.reload({ only: ['my_alerts', 'my_followups', 'my_shift', 'stats'] });
        }, 30000);
        return () => {
            if (refreshTimer.current) clearInterval(refreshTimer.current);
        };
    }, []);

    // Workspace-over-list: fetch only the `detail` prop and open the dialog
    // over My Tasks; the guided panes replace the old inline Ack/Resolve.
    const openWorkspace = useCallback((id: number) => {
        router.get('/control-room/my-tasks', { alert: String(id) }, { preserveState: true, preserveScroll: true, only: ['detail'] });
    }, []);
    const closeWorkspace = useCallback(() => {
        router.get('/control-room/my-tasks', {}, { preserveState: true, preserveScroll: true, only: ['detail'] });
    }, []);

    const handleCompleteFollowup = useCallback((noteId: number) => {
        router.post(`/control-room/my-tasks/followups/${noteId}/complete`, {}, {
            preserveScroll: true,
        });
    }, []);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="My Tasks - Control Room" />

            <PageShell>
                <PageHero
                    icon={ListChecks}
                    title="My Tasks"
                    description="Your personal control room dashboard"
                    stats={[
                        { label: 'Open alerts', value: stats.my_open },
                        { label: 'Resolved today', value: stats.my_resolved_today },
                        { label: 'Critical', value: stats.my_critical },
                        { label: 'Follow-ups', value: my_followups.length },
                    ]}
                    actions={
                        <Button
                            variant="outline"
                            size="sm"
                            asChild
                            className="border-primary-foreground/30 bg-primary-foreground/10 text-primary-foreground backdrop-blur-sm hover:bg-primary-foreground/20 hover:text-primary-foreground"
                        >
                            <Link href="/my-tasks">
                                <Calendar className="mr-2 h-4 w-4" />
                                View Full My Day
                            </Link>
                        </Button>
                    }
                />

                {/* KPI Row */}
                <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
                    <KpiCard
                        label="My Open Alerts"
                        value={stats.my_open}
                        icon={Bell}
                    />
                    <KpiCard
                        label="Resolved Today"
                        value={stats.my_resolved_today}
                        icon={CheckCircle}
                        className={stats.my_resolved_today > 0 ? 'border-status-success/30 dark:border-status-success/30' : undefined}
                    />
                    <KpiCard
                        label="Critical"
                        value={stats.my_critical}
                        icon={AlertTriangle}
                        className={stats.my_critical > 0 ? 'border-status-critical/30 bg-status-critical-bg dark:border-status-critical/30' : undefined}
                    />
                </div>

                {/* Two-Column Layout */}
                <div className="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-3">
                    {/* Left Column (2/3) */}
                    <div className="space-y-6 lg:col-span-2">
                        {/* My Alerts */}
                        <Card>
                            <CardHeader className="pb-3">
                                <CardTitle className="flex items-center gap-2 text-lg">
                                    <Bell className="h-5 w-5 text-muted-foreground" />
                                    My Alerts
                                    {my_alerts.length > 0 && (
                                        <Badge variant="secondary" className="ml-1">{my_alerts.length}</Badge>
                                    )}
                                </CardTitle>
                            </CardHeader>
                            <CardContent>
                                {my_alerts.length === 0 ? (
                                    <div className="flex flex-col items-center justify-center py-10 text-muted-foreground">
                                        <CheckCircle className="mb-2 h-10 w-10 text-status-success" />
                                        <p className="text-sm">No alerts assigned to you</p>
                                    </div>
                                ) : (
                                    <div className="space-y-2">
                                        {my_alerts.map((alert) => (
                                            <div
                                                key={alert.id}
                                                className={`flex items-center justify-between rounded-lg border-l-4 bg-muted/30 px-4 py-3 transition-colors hover:bg-muted/50 ${severityBorder[alert.severity] ?? 'border-l-gray-300'}`}
                                            >
                                                <div className="flex min-w-0 flex-1 items-center gap-3">
                                                    <div className="min-w-0 flex-1">
                                                        <div className="flex items-center gap-2">
                                                            <button
                                                                type="button"
                                                                onClick={() => openWorkspace(alert.id)}
                                                                className="truncate text-left font-semibold hover:underline"
                                                            >
                                                                {alert.alert_type}
                                                            </button>
                                                            {alert.client_name && (
                                                                <span className="truncate text-xs text-muted-foreground">
                                                                    - {alert.client_name}
                                                                </span>
                                                            )}
                                                        </div>
                                                        <div className="mt-1 flex flex-wrap items-center gap-2">
                                                            <Badge variant="outline" className="text-xs">
                                                                {alert.source}
                                                            </Badge>
                                                            <Badge className={`text-xs ${severityColors[alert.severity] ?? ''}`}>
                                                                {alert.severity}
                                                            </Badge>
                                                            <Badge className={`text-xs ${statusColors[alert.status] ?? ''}`}>
                                                                {alert.status}
                                                            </Badge>
                                                            <span className="text-xs text-muted-foreground">
                                                                {timeAgo(alert.triggered_at)}
                                                            </span>
                                                            {alert.sla_status && (
                                                                <span className="flex items-center gap-1 text-xs text-muted-foreground">
                                                                    <span className={`inline-block h-2 w-2 rounded-full ${slaStatusDot[alert.sla_status]}`} />
                                                                    SLA
                                                                </span>
                                                            )}
                                                        </div>
                                                    </div>
                                                </div>

                                                {/* Work happens in the guided workspace */}
                                                <div className="ml-3 flex shrink-0 items-center gap-2">
                                                    <Button
                                                        variant="outline"
                                                        size="sm"
                                                        onClick={() => openWorkspace(alert.id)}
                                                    >
                                                        <ExternalLink className="mr-1.5 h-4 w-4" />
                                                        Open
                                                    </Button>
                                                </div>
                                            </div>
                                        ))}
                                    </div>
                                )}
                            </CardContent>
                        </Card>

                        {/* Follow-ups Due */}
                        <Card>
                            <CardHeader className="pb-3">
                                <CardTitle className="flex items-center gap-2 text-lg">
                                    <ListChecks className="h-5 w-5 text-muted-foreground" />
                                    Follow-ups Due
                                    {my_followups.length > 0 && (
                                        <Badge variant="secondary" className="ml-1">{my_followups.length}</Badge>
                                    )}
                                </CardTitle>
                            </CardHeader>
                            <CardContent>
                                {my_followups.length === 0 ? (
                                    <div className="flex flex-col items-center justify-center py-10 text-muted-foreground">
                                        <CheckCircle className="mb-2 h-10 w-10 text-status-success" />
                                        <p className="text-sm">No follow-ups pending</p>
                                    </div>
                                ) : (
                                    <div className="space-y-2">
                                        {my_followups.map((note) => (
                                            <div
                                                key={note.id}
                                                className="flex items-center justify-between rounded-lg bg-muted/30 px-4 py-3 transition-colors hover:bg-muted/50"
                                            >
                                                <div className="min-w-0 flex-1">
                                                    <p className="truncate text-sm">
                                                        {note.content.length > 120
                                                            ? note.content.slice(0, 120) + '...'
                                                            : note.content}
                                                    </p>
                                                    <div className="mt-1 flex items-center gap-3">
                                                        <span className={`text-xs font-medium ${
                                                            note.followup_at && new Date(note.followup_at) < new Date()
                                                                ? 'text-status-critical dark:text-status-critical'
                                                                : 'text-muted-foreground'
                                                        }`}>
                                                            <Clock className="mr-1 inline h-3 w-3" />
                                                            {formatFollowupDate(note.followup_at)}
                                                        </span>
                                                        {note.alert && (
                                                            <button
                                                                type="button"
                                                                onClick={() => openWorkspace(note.alert!.id)}
                                                                className="flex items-center gap-1 text-xs text-primary hover:underline"
                                                            >
                                                                Alert #{note.alert.id}
                                                                <Badge className={`text-xs ${severityColors[note.alert.severity] ?? ''}`}>
                                                                    {note.alert.severity}
                                                                </Badge>
                                                            </button>
                                                        )}
                                                    </div>
                                                </div>
                                                <div className="ml-3 shrink-0">
                                                    <ConfirmChip
                                                        label="Complete"
                                                        icon={CheckCircle}
                                                        onConfirm={() => handleCompleteFollowup(note.id)}
                                                    />
                                                </div>
                                            </div>
                                        ))}
                                    </div>
                                )}
                            </CardContent>
                        </Card>
                    </div>

                    {/* Right Column (1/3) */}
                    <div className="space-y-6">
                        {/* My Shift */}
                        {my_shift && (
                            <Card>
                                <CardHeader className="pb-3">
                                    <CardTitle className="flex items-center gap-2 text-lg">
                                        <Shield className="h-5 w-5 text-muted-foreground" />
                                        My Shift
                                    </CardTitle>
                                </CardHeader>
                                <CardContent>
                                    <div className="space-y-3">
                                        <div>
                                            <p className="font-semibold">{my_shift.name}</p>
                                            <div className="mt-1 flex items-center gap-2">
                                                <Badge variant="outline">{my_shift.role}</Badge>
                                                <span className="text-xs text-muted-foreground">
                                                    Started {timeAgo(my_shift.starts_at)}
                                                </span>
                                            </div>
                                        </div>
                                        <div className="text-sm text-muted-foreground">
                                            Duration: {formatDuration(my_shift.duration_minutes)}
                                        </div>
                                        <div className="grid grid-cols-3 gap-2 rounded-lg bg-muted/40 p-3 text-center">
                                            <div>
                                                <div className="text-lg font-bold">{my_shift.alerts_created}</div>
                                                <div className="text-xs text-muted-foreground">Created</div>
                                            </div>
                                            <div>
                                                <div className="text-lg font-bold text-status-success">{my_shift.alerts_resolved}</div>
                                                <div className="text-xs text-muted-foreground">Resolved</div>
                                            </div>
                                            <div>
                                                <div className="text-lg font-bold text-status-warning">{my_shift.alerts_escalated}</div>
                                                <div className="text-xs text-muted-foreground">Escalated</div>
                                            </div>
                                        </div>
                                        <Link href="/control-room/shifts">
                                            <Button variant="outline" className="w-full" size="sm">
                                                <ArrowRight className="mr-1 h-4 w-4" />
                                                Go to Shift
                                            </Button>
                                        </Link>
                                    </div>
                                </CardContent>
                            </Card>
                        )}

                        {/* Quick Actions */}
                        <Card>
                            <CardHeader className="pb-3">
                                <CardTitle className="text-lg">Quick Actions</CardTitle>
                            </CardHeader>
                            <CardContent>
                                <div className="space-y-2">
                                    {can.create && (
                                        <Link href="/control-room/alerts?new=1" className="block">
                                            <Button variant="outline" className="w-full justify-start" size="sm">
                                                <Plus className="mr-2 h-4 w-4" />
                                                New Alert
                                            </Button>
                                        </Link>
                                    )}
                                    <Link href="/control-room" className="block">
                                        <Button variant="outline" className="w-full justify-start" size="sm">
                                            <Bell className="mr-2 h-4 w-4" />
                                            View All Alerts
                                        </Button>
                                    </Link>
                                    <Link href="/control-room/escalations" className="block">
                                        <Button variant="outline" className="w-full justify-start" size="sm">
                                            <AlertTriangle className="mr-2 h-4 w-4" />
                                            View Escalation Queue
                                        </Button>
                                    </Link>
                                </div>
                            </CardContent>
                        </Card>
                    </div>
                </div>

            </PageShell>

            {/* Workspace-over-list */}
            {detail ? (
                <AlertWorkspaceDialog detail={detail} open onClose={closeWorkspace} />
            ) : null}
        </AppLayout>
    );
}
