import { KpiCard } from '@/components/dashboard/kpi-card';
import PageShell from '@/components/page-shell';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { PageHero } from '@/components/page';
import { AlertWorkspaceDialog, type AlertWorkspaceDetail } from '@/components/control-room/alert-workspace-dialog';
import { CommandCentreTabs } from '@/components/control-room/command-centre-tabs';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, router } from '@inertiajs/react';
import {
    AlertCircle,
    ChevronRight,
    AlertTriangle,
    Bell,
    Clock,
    Info,
    MapPin,
    MinusCircle,
    Radio,
    Search,
    Shield,
    ShieldCheck,
    TrendingUp,
    User,
    XCircle,
} from 'lucide-react';
import React, { useEffect, useRef, useState } from 'react';
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

interface Alert {
    id: number;
    source: string;
    alert_type: string;
    severity: string;
    status: string;
    escalation_level: number;
    triggered_at: string | null;
    acknowledged_at: string | null;
    asset_id: number | null;
    asset: { id: number; name: string; asset_tag: string } | null;
    assigned_to: { id: number; name: string } | null;
    client_id: number | null;
    client_name: string | null;
    site_id: number | null;
    sla_status: 'on_track' | 'at_risk' | 'breached' | null;
    notes: string | null;
}

interface ActiveShift {
    name: string;
    lead_name: string | null;
    started_at: string | null;
}

interface ActivityEvent {
    id: number;
    type: string;
    occurred_at: string;
    subject: string;
    body: string | null;
    meta: Record<string, unknown> | null;
}

interface Props {
    alerts: {
        data: Alert[];
        links: { url: string | null; label: string; active: boolean }[];
        meta: {
            current_page: number;
            last_page: number;
            per_page: number;
            total: number;
        };
    };
    stats: {
        total: number;
        open: number;
        acknowledged: number;
        triaging: number;
        resolved: number;
        closed: number;
        critical: number;
        high: number;
        escalated: number;
        unassigned: number;
        my_alerts: number;
    };
    daily_trend: { date: string; count: number }[];
    by_severity: Record<string, number>;
    unresolved_by_severity: Record<string, number>;
    by_source: Record<string, number>;
    top_alert_types: Record<string, number>;
    sparkline_data: number[];
    alerts_today: number;
    alerts_yesterday: number;
    avg_response_minutes: number;
    sla_compliance_pct: number;
    active_shift: ActiveShift | null;
    recent_activity: ActivityEvent[];
    staff: { id: number; name: string }[];
    filters: Record<string, string>;
    /** Workspace-over-list: present when ?alert= is in the URL. */
    detail?: AlertWorkspaceDetail | null;
    can: {
        manage: boolean;
        assign: boolean;
        escalate: boolean;
        create: boolean;
        viewReports: boolean;
    };
    // PR12 additions
    escalation_rate?: number;
    attention_flags?: Array<{
        level: 'critical' | 'warning';
        message: string;
        metric: string;
        value: number;
    }>;
    site_comparison?: Array<{
        site_id: number;
        site_name: string;
        total_alerts: number;
        critical_count: number;
        escalated_count: number;
        resolution_rate: number;
    }>;
    period?: string;
    sites?: Array<{ id: number; name: string }>;
    workload?: {
        active_per_user: Array<{
            user: string;
            user_id: number;
            active_alerts: number;
        }>;
        handled_per_user: Array<{
            user: string;
            user_id: number;
            alerts_handled: number;
        }>;
        per_queue: Array<{
            queue: string;
            tier: number;
            active_alerts: number;
        }>;
        unassigned: number;
    };
    queues?: Array<{ name: string; tier: number; active_alerts: number }>;
    sla_daily_trend?: Array<{ date: string; compliance_pct: number }>;
    escalation_daily_trend?: Array<{ date: string; count: number }>;
}

const severityColors: Record<string, string> = {
    critical: 'bg-status-critical text-white',
    high: 'bg-status-warning text-white',
    medium: 'bg-status-warning text-black',
    low: 'bg-status-info text-white',
};

const statusColors: Record<string, string> = {
    open: 'bg-status-critical-bg text-status-critical-foreground border-status-critical/30',
    ack: 'bg-status-warning-bg text-status-warning-foreground border-status-warning/30',
    triaging:
        'bg-status-info-bg text-status-info-foreground border-status-info/30',
    resolved:
        'bg-status-success-bg text-status-success-foreground border-status-success/30',
    closed: 'bg-muted text-foreground border-border',
};

const DONUT_COLORS: Record<string, string> = {
    critical: '#dc2626',
    high: '#f97316',
    medium: '#eab308',
    low: '#3b82f6',
};

const severityIcons: Record<string, React.ReactNode> = {
    critical: <AlertTriangle className="mr-1 h-3 w-3" />,
    high: <AlertCircle className="mr-1 h-3 w-3" />,
    medium: <Info className="mr-1 h-3 w-3" />,
    low: <MinusCircle className="mr-1 h-3 w-3" />,
};

function formatRelativeTime(isoString: string | null): string {
    if (!isoString) return '-';
    const date = new Date(isoString);
    const now = new Date();
    const diffMs = now.getTime() - date.getTime();
    const diffMins = Math.floor(diffMs / 60000);
    const diffHours = Math.floor(diffMins / 60);
    const diffDays = Math.floor(diffHours / 24);

    if (diffMins < 1) return 'Just now';
    if (diffMins < 60) return `${diffMins}m ago`;
    if (diffHours < 24) return `${diffHours}h ago`;
    return `${diffDays}d ago`;
}

function actionLabel(action: string): string {
    const map: Record<string, string> = {
        'alert.acknowledge': 'Alert acknowledged',
        'alert.triage': 'Triage started',
        'alert.resolve': 'Alert resolved',
        'alert.close': 'Alert closed',
        'alert.assign': 'Alert assigned',
        'alert.escalate': 'Alert escalated',
        'alert.create': 'Alert created',
        'alert.addNote': 'Note added',
    };
    return (
        map[action] ||
        action
            .split('.')
            .pop()
            ?.replace(/([A-Z])/g, ' $1')
            .trim() ||
        action
    );
}

function HorizontalBars({
    data,
    maxItems = 5,
}: {
    data: Record<string, number>;
    maxItems?: number;
}) {
    const entries = Object.entries(data).slice(0, maxItems);
    const max = Math.max(...entries.map(([, v]) => v), 1);

    return (
        <div className="space-y-2.5">
            {entries.map(([label, count]) => (
                <div key={label}>
                    <div className="flex items-center justify-between text-xs">
                        <span className="truncate text-muted-foreground capitalize">
                            {label.replace(/_/g, ' ')}
                        </span>
                        <span className="ml-2 font-medium">{count}</span>
                    </div>
                    <div className="mt-1 h-2 rounded-full bg-muted">
                        <div
                            className="h-full rounded-full bg-primary transition-all"
                            style={{ width: `${(count / max) * 100}%` }}
                        />
                    </div>
                </div>
            ))}
            {entries.length === 0 && (
                <p className="py-4 text-center text-xs text-muted-foreground">
                    No data
                </p>
            )}
        </div>
    );
}

export default function ControlRoomIndex({
    alerts,
    stats,
    daily_trend,
    unresolved_by_severity,
    by_source,
    top_alert_types,
    sparkline_data,
    alerts_today,
    alerts_yesterday,
    avg_response_minutes,
    sla_compliance_pct,
    active_shift,
    recent_activity,
    staff,
    filters,
    detail = null,
    can,
    escalation_rate,
    attention_flags,
    site_comparison,
    period,
    sites,
    workload,
    queues,
    sla_daily_trend,
    escalation_daily_trend,
}: Props) {
    const [searchValue, setSearchValue] = useState(filters.search || '');
    const prevCriticalRef = useRef(stats.critical);

    // Workspace-over-list: fetch only the `detail` prop and open the dialog
    // without leaving the dashboard; closing drops the param again.
    const openWorkspace = (id: number) => {
        const params = new URLSearchParams(window.location.search);
        params.set('alert', String(id));
        router.get(`/control-room?${params.toString()}`, {}, { preserveState: true, preserveScroll: true, only: ['detail'] });
    };
    const closeWorkspace = () => {
        const params = new URLSearchParams(window.location.search);
        params.delete('alert');
        router.get(`/control-room${params.size ? `?${params.toString()}` : ''}`, {}, { preserveState: true, preserveScroll: true, only: ['detail'] });
    };

    // Auto-refresh every 30 seconds
    useEffect(() => {
        const interval = setInterval(() => {
            if (!document.hidden) {
                router.reload({
                    only: [
                        'alerts',
                        'stats',
                        'daily_trend',
                        'unresolved_by_severity',
                        'by_source',
                        'top_alert_types',
                        'sparkline_data',
                        'alerts_today',
                        'alerts_yesterday',
                        'avg_response_minutes',
                        'sla_compliance_pct',
                        'active_shift',
                        'recent_activity',
                        'attention_flags',
                        'site_comparison',
                        'escalation_rate',
                        'workload',
                        'queues',
                        'sla_daily_trend',
                        'escalation_daily_trend',
                    ],
                });
            }
        }, 30000);
        return () => clearInterval(interval);
    }, []);

    // Sound notification for new critical alerts
    useEffect(() => {
        if (stats.critical > prevCriticalRef.current) {
            try {
                const audio = new Audio('/sounds/alert.mp3');
                audio.volume = 0.5;
                audio.play().catch(() => {});
            } catch {
                /* ignore */
            }
        }
        prevCriticalRef.current = stats.critical;
    }, [stats.critical]);

    const applyFilter = (key: string, value: string) => {
        router.get(
            '/control-room',
            { ...filters, [key]: value || undefined },
            { preserveState: true, preserveScroll: true },
        );
    };

    const handleSearch = (e: React.FormEvent) => {
        e.preventDefault();
        applyFilter('search', searchValue);
    };

    const clearFilters = () => {
        router.get('/control-room', {}, { preserveState: true });
        setSearchValue('');
    };

    const hasFilters = Object.values(filters).some((v) => v);

    // Prepare donut data
    const donutData = ['critical', 'high', 'medium', 'low']
        .map((sev) => ({ name: sev, value: unresolved_by_severity[sev] || 0 }))
        .filter((d) => d.value > 0);
    const totalUnresolved = donutData.reduce((sum, d) => sum + d.value, 0);

    // Trend calculation
    const todayTrend =
        alerts_yesterday > 0
            ? Math.round(
                  ((alerts_today - alerts_yesterday) / alerts_yesterday) * 100,
              )
            : 0;

    return (
        <AppLayout
            breadcrumbs={[{ title: 'Control Room', href: '/control-room' }]}
        >
            <Head title="Control Room" />
            <PageShell>
                <PageHero
                    icon={Radio}
                    title="Command Centre"
                    description="One workspace for alerts, escalations and incident triage."
                    stats={[
                        { label: 'Open alerts', value: stats.open },
                        { label: 'Critical', value: stats.critical },
                        { label: 'SLA compliance', value: `${sla_compliance_pct}%` },
                        { label: 'Avg response', value: `${avg_response_minutes}m` },
                    ]}
                    actions={
                        <div className="flex items-center gap-3">
                            <div className="flex items-center gap-1.5 text-xs text-primary-foreground/80">
                                <span className="relative flex h-2 w-2">
                                    <span className="absolute inline-flex h-full w-full animate-ping rounded-full bg-status-success opacity-75" />
                                    <span className="relative inline-flex h-2 w-2 rounded-full bg-status-success" />
                                </span>
                                Live
                            </div>
                            <Button
                                variant="outline"
                                size="sm"
                                asChild
                                className="border-primary-foreground/30 bg-primary-foreground/10 text-primary-foreground backdrop-blur-sm hover:bg-primary-foreground/20 hover:text-primary-foreground"
                            >
                                <Link href="/control-room/map">
                                    <MapPin className="mr-2 h-4 w-4" />
                                    Map
                                </Link>
                            </Button>
                            <Button
                                variant="outline"
                                size="sm"
                                asChild
                                className="border-primary-foreground/30 bg-primary-foreground/10 text-primary-foreground backdrop-blur-sm hover:bg-primary-foreground/20 hover:text-primary-foreground"
                            >
                                <Link href="/control-room/shifts">
                                    <Clock className="mr-2 h-4 w-4" />
                                    Shifts
                                </Link>
                            </Button>
                        </div>
                    }
                />

                {/* Command centre tabs — the four alert surfaces are one workspace */}
                <CommandCentreTabs current="/control-room" badges={{ '/control-room/alerts': stats.open }} />

                {/* Row 1: KPI Cards */}
                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <KpiCard
                        label="Open Alerts"
                        value={stats.open}
                        icon={Bell}
                        sparklineData={sparkline_data}
                        trend={
                            todayTrend !== 0
                                ? {
                                      value: todayTrend,
                                      label: 'vs yesterday',
                                      direction:
                                          todayTrend > 0
                                              ? 'up'
                                              : todayTrend < 0
                                                ? 'down'
                                                : 'neutral',
                                  }
                                : undefined
                        }
                        href="/control-room?status=open"
                    />
                    <KpiCard
                        label="Critical"
                        value={stats.critical}
                        icon={AlertTriangle}
                        href="/control-room?severity=critical"
                        className={
                            stats.critical > 0
                                ? 'border-status-critical/30 bg-status-critical-bg'
                                : undefined
                        }
                    />
                    <KpiCard
                        label="Avg Response"
                        value={`${avg_response_minutes}m`}
                        icon={Clock}
                        href="/control-room/sla"
                    />
                    <KpiCard
                        label="SLA Compliance"
                        value={`${sla_compliance_pct}%`}
                        icon={ShieldCheck}
                        href="/control-room/sla"
                        className={
                            sla_compliance_pct < 90
                                ? 'border-status-warning/30 bg-status-warning-bg'
                                : undefined
                        }
                    />
                </div>

                {/* Row 2: Critical Banner + Active Shift */}
                {(stats.critical > 0 || active_shift) && (
                    <div className="mt-4 flex flex-col gap-3 sm:flex-row">
                        {stats.critical > 0 && (
                            <div className="flex flex-1 items-center gap-3 rounded-lg border border-status-critical/30 bg-status-critical-bg px-4 py-2.5 dark:border-status-critical/30">
                                <div className="relative flex h-3 w-3">
                                    <span className="absolute inline-flex h-full w-full animate-ping rounded-full bg-status-critical opacity-75" />
                                    <span className="relative inline-flex h-3 w-3 rounded-full bg-status-critical" />
                                </div>
                                <span className="text-sm font-semibold text-status-critical dark:text-status-critical">
                                    {stats.critical} CRITICAL ALERT
                                    {stats.critical !== 1 ? 'S' : ''} REQUIRE
                                    ATTENTION
                                </span>
                                <Button
                                    variant="destructive"
                                    size="sm"
                                    className="ml-auto"
                                    asChild
                                >
                                    <Link href="/control-room?severity=critical">
                                        View
                                    </Link>
                                </Button>
                            </div>
                        )}
                        {active_shift && (
                            <Card className="flex flex-row items-center gap-3 rounded-lg px-4 py-2.5">
                                <span className="relative flex h-2.5 w-2.5">
                                    <span className="absolute inline-flex h-full w-full animate-ping rounded-full bg-status-success opacity-75" />
                                    <span className="relative inline-flex h-2.5 w-2.5 rounded-full bg-status-success" />
                                </span>
                                <span className="text-sm">
                                    <span className="font-medium">
                                        {active_shift.name}
                                    </span>
                                    {active_shift.lead_name && (
                                        <span className="text-muted-foreground">
                                            {' '}
                                            | Lead: {active_shift.lead_name}
                                        </span>
                                    )}
                                </span>
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    className="ml-auto"
                                    asChild
                                >
                                    <Link href="/control-room/shifts">
                                        Manage
                                    </Link>
                                </Button>
                            </Card>
                        )}
                    </div>
                )}

                {/* What needs attention — ONE compact card instead of a stack of
                    full-width warning banners (the big critical banner above
                    already carries the #1 item, so it's filtered out here). */}
                {(() => {
                    const flags = (attention_flags ?? []).filter(
                        (f) => !(stats.critical > 0 && f.message.toLowerCase().includes('critical alert')),
                    );
                    if (!flags.length) return null;
                    // Each flag deep-links to the view where you fix it.
                    const FLAG_HREFS: Record<string, string> = {
                        critical_alerts: '/control-room/alerts?severity=critical',
                        unassigned: '/control-room/alerts?assigned_to=unassigned',
                        high_escalation: '/control-room/escalations',
                        stale_open: '/control-room/alerts?status=open&sort=triggered_at&dir=asc',
                        sla_compliance: '/control-room/sla/breaches',
                    };
                    return (
                        <Card className="mt-4 gap-0 py-0">
                            <div className="border-b border-border px-4 py-2.5 text-sm font-semibold text-foreground">
                                What needs attention
                            </div>
                            <div className="divide-y divide-border/60">
                                {flags.slice(0, 4).map((flag, i) => {
                                    const href = FLAG_HREFS[flag.metric];
                                    const inner = (
                                        <>
                                            {flag.level === 'critical' ? (
                                                <AlertTriangle className="h-4 w-4 shrink-0 text-status-critical" />
                                            ) : (
                                                <AlertCircle className="h-4 w-4 shrink-0 text-status-warning" />
                                            )}
                                            <span className="flex-1 text-foreground">{flag.message}</span>
                                            {href ? <ChevronRight className="h-4 w-4 shrink-0 text-muted-foreground" /> : null}
                                        </>
                                    );
                                    return href ? (
                                        <Link key={i} href={href} className="flex items-center gap-2.5 px-4 py-2 text-sm transition-colors hover:bg-muted/50">
                                            {inner}
                                        </Link>
                                    ) : (
                                        <div key={i} className="flex items-center gap-2.5 px-4 py-2 text-sm">
                                            {inner}
                                        </div>
                                    );
                                })}
                            </div>
                        </Card>
                    );
                })()}

                {/* Row 3: Trend Charts (unified 3-column) */}
                <div className="mt-4 grid gap-4 lg:grid-cols-3">
                    {/* Alert Volume Trend */}
                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-sm font-medium">
                                Alert Volume
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="h-48">
                                <ResponsiveContainer width="100%" height="100%">
                                    <AreaChart
                                        data={daily_trend}
                                        margin={{
                                            top: 5,
                                            right: 10,
                                            left: -20,
                                            bottom: 0,
                                        }}
                                    >
                                        <defs>
                                            <linearGradient
                                                id="colorAlerts"
                                                x1="0"
                                                y1="0"
                                                x2="0"
                                                y2="1"
                                            >
                                                <stop
                                                    offset="5%"
                                                    stopColor="hsl(var(--primary))"
                                                    stopOpacity={0.3}
                                                />
                                                <stop
                                                    offset="95%"
                                                    stopColor="hsl(var(--primary))"
                                                    stopOpacity={0}
                                                />
                                            </linearGradient>
                                        </defs>
                                        <CartesianGrid
                                            strokeDasharray="3 3"
                                            className="stroke-muted"
                                        />
                                        <XAxis
                                            dataKey="date"
                                            tick={{ fontSize: 10 }}
                                            className="fill-muted-foreground"
                                        />
                                        <YAxis
                                            tick={{ fontSize: 10 }}
                                            className="fill-muted-foreground"
                                            allowDecimals={false}
                                        />
                                        <Tooltip
                                            contentStyle={{
                                                backgroundColor:
                                                    'hsl(var(--card))',
                                                border: '1px solid hsl(var(--border))',
                                                borderRadius: '8px',
                                                fontSize: '12px',
                                            }}
                                        />
                                        <Area
                                            type="monotone"
                                            dataKey="count"
                                            stroke="hsl(var(--primary))"
                                            strokeWidth={2}
                                            fill="url(#colorAlerts)"
                                        />
                                    </AreaChart>
                                </ResponsiveContainer>
                            </div>
                        </CardContent>
                    </Card>

                    {/* SLA Compliance Trend */}
                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-sm font-medium">
                                SLA Compliance
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="h-48">
                                {sla_daily_trend &&
                                sla_daily_trend.length > 0 ? (
                                    <ResponsiveContainer
                                        width="100%"
                                        height="100%"
                                    >
                                        <AreaChart
                                            data={sla_daily_trend}
                                            margin={{
                                                top: 5,
                                                right: 10,
                                                left: -20,
                                                bottom: 0,
                                            }}
                                        >
                                            <defs>
                                                <linearGradient
                                                    id="colorSla"
                                                    x1="0"
                                                    y1="0"
                                                    x2="0"
                                                    y2="1"
                                                >
                                                    <stop
                                                        offset="5%"
                                                        stopColor="#22c55e"
                                                        stopOpacity={0.3}
                                                    />
                                                    <stop
                                                        offset="95%"
                                                        stopColor="#22c55e"
                                                        stopOpacity={0}
                                                    />
                                                </linearGradient>
                                            </defs>
                                            <CartesianGrid
                                                strokeDasharray="3 3"
                                                className="stroke-muted"
                                            />
                                            <XAxis
                                                dataKey="date"
                                                tick={{ fontSize: 10 }}
                                                className="fill-muted-foreground"
                                            />
                                            <YAxis
                                                tick={{ fontSize: 10 }}
                                                className="fill-muted-foreground"
                                                domain={[0, 100]}
                                            />
                                            <Tooltip
                                                contentStyle={{
                                                    backgroundColor:
                                                        'hsl(var(--card))',
                                                    border: '1px solid hsl(var(--border))',
                                                    borderRadius: '8px',
                                                    fontSize: '12px',
                                                }}
                                                formatter={(v?: number) => [
                                                    `${v ?? 0}%`,
                                                    'Compliance',
                                                ]}
                                            />
                                            <Area
                                                type="monotone"
                                                dataKey="compliance_pct"
                                                stroke="#22c55e"
                                                strokeWidth={2}
                                                fill="url(#colorSla)"
                                            />
                                        </AreaChart>
                                    </ResponsiveContainer>
                                ) : (
                                    <div className="flex h-full items-center justify-center text-sm text-muted-foreground">
                                        No SLA data for this period
                                    </div>
                                )}
                            </div>
                        </CardContent>
                    </Card>

                    {/* Escalation Trend */}
                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-sm font-medium">
                                Escalations
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="h-48">
                                {escalation_daily_trend &&
                                escalation_daily_trend.length > 0 ? (
                                    <ResponsiveContainer
                                        width="100%"
                                        height="100%"
                                    >
                                        <BarChart
                                            data={escalation_daily_trend}
                                            margin={{
                                                top: 5,
                                                right: 10,
                                                left: -20,
                                                bottom: 0,
                                            }}
                                        >
                                            <CartesianGrid
                                                strokeDasharray="3 3"
                                                className="stroke-muted"
                                            />
                                            <XAxis
                                                dataKey="date"
                                                tick={{ fontSize: 10 }}
                                                className="fill-muted-foreground"
                                            />
                                            <YAxis
                                                tick={{ fontSize: 10 }}
                                                className="fill-muted-foreground"
                                                allowDecimals={false}
                                            />
                                            <Tooltip
                                                contentStyle={{
                                                    backgroundColor:
                                                        'hsl(var(--card))',
                                                    border: '1px solid hsl(var(--border))',
                                                    borderRadius: '8px',
                                                    fontSize: '12px',
                                                }}
                                                formatter={(v?: number) => [
                                                    v ?? 0,
                                                    'Escalated',
                                                ]}
                                            />
                                            <Bar
                                                dataKey="count"
                                                fill="#f97316"
                                                radius={[4, 4, 0, 0]}
                                            />
                                        </BarChart>
                                    </ResponsiveContainer>
                                ) : (
                                    <div className="flex h-full items-center justify-center text-sm text-muted-foreground">
                                        No escalations in this period
                                    </div>
                                )}
                            </div>
                        </CardContent>
                    </Card>
                </div>

                {/* Row 4: Distribution + Insights */}
                <div className="mt-4 grid gap-4 lg:grid-cols-4">
                    {/* Severity Donut */}
                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-sm font-medium">
                                Severity
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="flex h-44 items-center justify-center">
                                {totalUnresolved > 0 ? (
                                    <div className="relative">
                                        <ResponsiveContainer
                                            width={150}
                                            height={150}
                                        >
                                            <PieChart>
                                                <Pie
                                                    data={donutData}
                                                    cx="50%"
                                                    cy="50%"
                                                    innerRadius={42}
                                                    outerRadius={65}
                                                    paddingAngle={2}
                                                    dataKey="value"
                                                >
                                                    {donutData.map((entry) => (
                                                        <Cell
                                                            key={entry.name}
                                                            fill={
                                                                DONUT_COLORS[
                                                                    entry.name
                                                                ] || '#94a3b8'
                                                            }
                                                        />
                                                    ))}
                                                </Pie>
                                                <Tooltip
                                                    formatter={(
                                                        value?: number,
                                                        name?: string,
                                                    ) => [
                                                        value ?? 0,
                                                        name ?? '',
                                                    ]}
                                                    contentStyle={{
                                                        backgroundColor:
                                                            'hsl(var(--card))',
                                                        border: '1px solid hsl(var(--border))',
                                                        borderRadius: '8px',
                                                        fontSize: '12px',
                                                    }}
                                                />
                                            </PieChart>
                                        </ResponsiveContainer>
                                        <div className="absolute inset-0 flex flex-col items-center justify-center">
                                            <span className="text-xl font-bold">
                                                {totalUnresolved}
                                            </span>
                                            <span className="text-[9px] text-muted-foreground">
                                                Unresolved
                                            </span>
                                        </div>
                                    </div>
                                ) : (
                                    <div className="flex flex-col items-center gap-2 text-muted-foreground">
                                        <Shield className="h-8 w-8" />
                                        <span className="text-xs">
                                            All clear
                                        </span>
                                    </div>
                                )}
                            </div>
                            <div className="mt-1 flex flex-wrap justify-center gap-2">
                                {donutData.map((d) => (
                                    <div
                                        key={d.name}
                                        className="flex items-center gap-1 text-[10px]"
                                    >
                                        <span
                                            className="inline-block h-2 w-2 rounded-full"
                                            style={{
                                                backgroundColor:
                                                    DONUT_COLORS[d.name],
                                            }}
                                        />
                                        <span className="capitalize">
                                            {d.name}: {d.value}
                                        </span>
                                    </div>
                                ))}
                            </div>
                        </CardContent>
                    </Card>

                    {/* Top Alert Types */}
                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-sm font-medium">
                                Top Alert Types (7d)
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <HorizontalBars data={top_alert_types} />
                        </CardContent>
                    </Card>

                    {/* By Source */}
                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-sm font-medium">
                                By Source (7d)
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <HorizontalBars data={by_source} maxItems={8} />
                        </CardContent>
                    </Card>

                    {/* Recent Activity */}
                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-sm font-medium">
                                Recent Activity
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div
                                className="max-h-48 space-y-2.5 overflow-y-auto"
                                role="region"
                                aria-label="Recent activity feed"
                                tabIndex={0}
                            >
                                {recent_activity.length > 0 ? (
                                    recent_activity
                                        .slice(0, 10)
                                        .map((event) => (
                                            <div
                                                key={event.id}
                                                className="flex items-start gap-2 text-xs"
                                            >
                                                <div className="mt-1 h-1.5 w-1.5 shrink-0 rounded-full bg-primary" />
                                                <div className="min-w-0 flex-1">
                                                    <span className="font-medium">
                                                        {actionLabel(
                                                            event.subject,
                                                        )}
                                                    </span>
                                                    <span className="ml-1.5 text-muted-foreground">
                                                        {formatRelativeTime(
                                                            event.occurred_at,
                                                        )}
                                                    </span>
                                                </div>
                                            </div>
                                        ))
                                ) : (
                                    <p className="py-4 text-center text-xs text-muted-foreground">
                                        No recent activity
                                    </p>
                                )}
                            </div>
                        </CardContent>
                    </Card>
                </div>

                {/* Row 4b: Workload + Queue Pressure */}
                <div className="mt-4 grid gap-4 lg:grid-cols-2">
                    {/* Workload Distribution */}
                    {workload && workload.active_per_user.length > 0 && (
                        <Card>
                            <CardHeader className="pb-2">
                                <CardTitle className="text-sm font-medium">
                                    Active Alerts by Staff
                                </CardTitle>
                            </CardHeader>
                            <CardContent>
                                <div className="h-52">
                                    <ResponsiveContainer
                                        width="100%"
                                        height="100%"
                                    >
                                        <BarChart
                                            data={workload.active_per_user.slice(
                                                0,
                                                8,
                                            )}
                                            layout="vertical"
                                            margin={{
                                                top: 0,
                                                right: 10,
                                                left: 0,
                                                bottom: 0,
                                            }}
                                        >
                                            <CartesianGrid
                                                strokeDasharray="3 3"
                                                className="stroke-muted"
                                                horizontal={false}
                                            />
                                            <XAxis
                                                type="number"
                                                tick={{ fontSize: 11 }}
                                                className="fill-muted-foreground"
                                                allowDecimals={false}
                                            />
                                            <YAxis
                                                type="category"
                                                dataKey="user"
                                                tick={{ fontSize: 11 }}
                                                className="fill-muted-foreground"
                                                width={90}
                                            />
                                            <Tooltip
                                                contentStyle={{
                                                    backgroundColor:
                                                        'hsl(var(--card))',
                                                    border: '1px solid hsl(var(--border))',
                                                    borderRadius: '8px',
                                                    fontSize: '12px',
                                                }}
                                                formatter={(v?: number) => [
                                                    v ?? 0,
                                                    'Active alerts',
                                                ]}
                                            />
                                            <Bar
                                                dataKey="active_alerts"
                                                fill="hsl(var(--primary))"
                                                radius={[0, 4, 4, 0]}
                                                barSize={16}
                                            />
                                        </BarChart>
                                    </ResponsiveContainer>
                                </div>
                            </CardContent>
                        </Card>
                    )}

                    {/* Queue Pressure */}
                    {queues && queues.length > 0 && (
                        <Card>
                            <CardHeader className="pb-2">
                                <CardTitle className="text-sm font-medium">
                                    Queue Pressure
                                </CardTitle>
                            </CardHeader>
                            <CardContent>
                                <div className="space-y-4">
                                    {queues.map((q) => {
                                        const isHot = q.active_alerts >= 5;
                                        const isWarm = q.active_alerts >= 2;
                                        return (
                                            <div key={q.name}>
                                                <div className="mb-1.5 flex items-center justify-between">
                                                    <span className="text-sm font-medium">
                                                        {q.name}
                                                    </span>
                                                    <span
                                                        className={`text-lg font-bold ${
                                                            isHot
                                                                ? 'text-status-critical'
                                                                : isWarm
                                                                  ? 'text-status-warning'
                                                                  : 'text-status-success'
                                                        }`}
                                                    >
                                                        {q.active_alerts}
                                                    </span>
                                                </div>
                                                <div className="h-2 rounded-full bg-muted">
                                                    <div
                                                        className={`h-full rounded-full transition-all ${
                                                            isHot
                                                                ? 'bg-status-critical'
                                                                : isWarm
                                                                  ? 'bg-status-warning'
                                                                  : 'bg-status-success'
                                                        }`}
                                                        style={{
                                                            width: `${Math.min((q.active_alerts / 10) * 100, 100)}%`,
                                                        }}
                                                    />
                                                </div>
                                                <div className="mt-0.5 text-[10px] text-muted-foreground">
                                                    Tier {q.tier}
                                                </div>
                                            </div>
                                        );
                                    })}
                                    {workload && (
                                        <div className="border-t pt-3">
                                            <div className="flex items-center justify-between text-sm">
                                                <span className="text-muted-foreground">
                                                    Unassigned
                                                </span>
                                                <span
                                                    className={`font-bold ${workload.unassigned >= 5 ? 'text-status-critical' : 'text-muted-foreground'}`}
                                                >
                                                    {workload.unassigned}
                                                </span>
                                            </div>
                                        </div>
                                    )}
                                </div>
                            </CardContent>
                        </Card>
                    )}
                </div>

                {/* Row 4c: Site Comparison (PR12) */}
                {site_comparison && site_comparison.length > 0 && (
                    <div className="mt-4">
                        <Card>
                            <CardHeader className="pb-2">
                                <CardTitle className="text-sm font-medium">
                                    Alerts by Site
                                </CardTitle>
                            </CardHeader>
                            <CardContent>
                                <div className="h-56">
                                    <ResponsiveContainer
                                        width="100%"
                                        height="100%"
                                    >
                                        <BarChart
                                            data={site_comparison.slice(0, 8)}
                                            margin={{
                                                top: 5,
                                                right: 10,
                                                left: -10,
                                                bottom: 0,
                                            }}
                                            layout="vertical"
                                        >
                                            <CartesianGrid
                                                strokeDasharray="3 3"
                                                className="stroke-muted"
                                                horizontal={false}
                                            />
                                            <XAxis
                                                type="number"
                                                tick={{ fontSize: 11 }}
                                                className="fill-muted-foreground"
                                                allowDecimals={false}
                                            />
                                            <YAxis
                                                type="category"
                                                dataKey="site_name"
                                                tick={{ fontSize: 11 }}
                                                className="fill-muted-foreground"
                                                width={100}
                                            />
                                            <Tooltip
                                                contentStyle={{
                                                    backgroundColor:
                                                        'hsl(var(--card))',
                                                    border: '1px solid hsl(var(--border))',
                                                    borderRadius: '8px',
                                                    fontSize: '12px',
                                                }}
                                                formatter={(
                                                    value?: number,
                                                    name?: string,
                                                ) => {
                                                    const labels: Record<
                                                        string,
                                                        string
                                                    > = {
                                                        total_alerts: 'Total',
                                                        critical_count:
                                                            'Critical',
                                                        escalated_count:
                                                            'Escalated',
                                                    };
                                                    return [
                                                        value ?? 0,
                                                        labels[name ?? ''] ||
                                                            name ||
                                                            '',
                                                    ];
                                                }}
                                            />
                                            <Legend
                                                wrapperStyle={{
                                                    fontSize: '11px',
                                                }}
                                                formatter={(value: string) => {
                                                    const labels: Record<
                                                        string,
                                                        string
                                                    > = {
                                                        total_alerts: 'Total',
                                                        critical_count:
                                                            'Critical',
                                                        escalated_count:
                                                            'Escalated',
                                                    };
                                                    return (
                                                        labels[value] || value
                                                    );
                                                }}
                                            />
                                            <Bar
                                                dataKey="total_alerts"
                                                fill="hsl(var(--primary))"
                                                radius={[0, 4, 4, 0]}
                                                barSize={14}
                                            />
                                            <Bar
                                                dataKey="critical_count"
                                                fill="#dc2626"
                                                radius={[0, 4, 4, 0]}
                                                barSize={14}
                                            />
                                            <Bar
                                                dataKey="escalated_count"
                                                fill="#f97316"
                                                radius={[0, 4, 4, 0]}
                                                barSize={14}
                                            />
                                        </BarChart>
                                    </ResponsiveContainer>
                                </div>
                            </CardContent>
                        </Card>
                    </div>
                )}

                {/* Row 5: Quick Stats Bar */}
                <div className="mt-4 grid grid-cols-3 gap-3 sm:grid-cols-6">
                    {[
                        {
                            label: 'Open',
                            value: stats.open,
                            color: 'text-status-critical',
                            filter: () => applyFilter('status', 'open'),
                        },
                        {
                            label: 'Acknowledged',
                            value: stats.acknowledged,
                            color: 'text-status-warning',
                            filter: () => applyFilter('status', 'ack'),
                        },
                        {
                            label: 'Triaging',
                            value: stats.triaging,
                            color: 'text-status-info',
                            filter: () => applyFilter('status', 'triaging'),
                        },
                        {
                            label: escalation_rate
                                ? `Escalated (${escalation_rate}%)`
                                : 'Escalated',
                            value: stats.escalated,
                            color: 'text-status-warning',
                            filter: () => applyFilter('escalation_level', '1'),
                        },
                        {
                            label: 'Unassigned',
                            value: stats.unassigned,
                            color: 'text-primary',
                            filter: () =>
                                applyFilter('assigned_to', 'unassigned'),
                        },
                        {
                            label: 'My Alerts',
                            value: stats.my_alerts,
                            color: 'text-primary',
                            filter: () => applyFilter('assigned_to', 'me'),
                        },
                    ].map((s) => (
                        <Button
                            key={s.label}
                            type="button"
                            variant="outline"
                            onClick={s.filter}
                            className="h-auto flex-col items-start justify-start gap-0 rounded-lg bg-card px-3 py-2 text-left transition-colors hover:bg-accent"
                        >
                            <div className={`text-lg font-bold ${s.color}`}>
                                {s.value}
                            </div>
                            <div className="text-[11px] text-muted-foreground">
                                {s.label}
                            </div>
                        </Button>
                    ))}
                </div>

                {/* Row 6: Filters */}
                <Card className="mt-4 gap-0 rounded-lg p-4">
                    <div className="flex flex-wrap items-end gap-3">
                        <form onSubmit={handleSearch} className="flex gap-2">
                            <div className="relative">
                                <Search className="absolute top-2.5 left-2.5 h-4 w-4 text-muted-foreground" />
                                <Input
                                    type="text"
                                    placeholder="Search alerts..."
                                    value={searchValue}
                                    onChange={(e) =>
                                        setSearchValue(e.target.value)
                                    }
                                    className="w-48 pl-9"
                                />
                            </div>
                            <Button type="submit" variant="secondary" size="sm">
                                Search
                            </Button>
                        </form>

                        <Select
                            value={filters.status || 'all'}
                            onValueChange={(v) => applyFilter('status', v)}
                        >
                            <SelectTrigger
                                className="w-32"
                                aria-label="Filter alerts by status"
                            >
                                <SelectValue placeholder="Status" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">All Status</SelectItem>
                                <SelectItem value="open">Open</SelectItem>
                                <SelectItem value="ack">
                                    Acknowledged
                                </SelectItem>
                                <SelectItem value="triaging">
                                    Triaging
                                </SelectItem>
                                <SelectItem value="resolved">
                                    Resolved
                                </SelectItem>
                                <SelectItem value="closed">Closed</SelectItem>
                            </SelectContent>
                        </Select>

                        <Select
                            value={filters.severity || 'all'}
                            onValueChange={(v) => applyFilter('severity', v)}
                        >
                            <SelectTrigger
                                className="w-32"
                                aria-label="Filter alerts by severity"
                            >
                                <SelectValue placeholder="Severity" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">
                                    All Severity
                                </SelectItem>
                                <SelectItem value="critical">
                                    Critical
                                </SelectItem>
                                <SelectItem value="high">High</SelectItem>
                                <SelectItem value="medium">Medium</SelectItem>
                                <SelectItem value="low">Low</SelectItem>
                            </SelectContent>
                        </Select>

                        <Select
                            value={filters.source || 'all'}
                            onValueChange={(v) => applyFilter('source', v)}
                        >
                            <SelectTrigger
                                className="w-36"
                                aria-label="Filter alerts by source"
                            >
                                <SelectValue placeholder="Source" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">All Sources</SelectItem>
                                <SelectItem value="fleet">Fleet</SelectItem>
                                <SelectItem value="personal_tracker">
                                    Personal Tracker
                                </SelectItem>
                                <SelectItem value="manual">Manual</SelectItem>
                                <SelectItem value="compliance">
                                    Compliance
                                </SelectItem>
                                <SelectItem value="medication">
                                    Medication
                                </SelectItem>
                                <SelectItem value="safeguarding">
                                    Safeguarding
                                </SelectItem>
                                <SelectItem value="incident">
                                    Incident
                                </SelectItem>
                            </SelectContent>
                        </Select>

                        <Select
                            value={filters.assigned_to || 'all'}
                            onValueChange={(v) =>
                                applyFilter('assigned_to', v === 'all' ? '' : v)
                            }
                        >
                            <SelectTrigger
                                className="w-40"
                                aria-label="Filter alerts by assignee"
                            >
                                <SelectValue placeholder="Assignee" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">
                                    All Assignees
                                </SelectItem>
                                <SelectItem value="me">
                                    Assigned to Me
                                </SelectItem>
                                <SelectItem value="unassigned">
                                    Unassigned
                                </SelectItem>
                                {staff.map((s) => (
                                    <SelectItem
                                        key={s.id}
                                        value={s.id.toString()}
                                    >
                                        {s.name}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>

                        {hasFilters && (
                            <Button
                                variant="ghost"
                                size="sm"
                                onClick={clearFilters}
                            >
                                <XCircle className="mr-1 h-4 w-4" />
                                Clear
                            </Button>
                        )}
                    </div>
                </Card>

                {/* Row 7: Alert List */}
                <div className="mt-4 rounded-lg border">
                    {/* Table Header */}
                    <div className="hidden border-b bg-muted/50 px-4 py-2 md:grid md:grid-cols-12 md:gap-2">
                        <span className="col-span-4 text-xs font-medium text-muted-foreground">
                            Alert
                        </span>
                        <span className="col-span-2 text-xs font-medium text-muted-foreground">
                            Source
                        </span>
                        <span className="col-span-1 text-xs font-medium text-muted-foreground">
                            Severity
                        </span>
                        <span className="col-span-1 text-xs font-medium text-muted-foreground">
                            Status
                        </span>
                        <span className="col-span-1 text-xs font-medium text-muted-foreground">
                            SLA
                        </span>
                        <span className="col-span-1 text-xs font-medium text-muted-foreground">
                            Triggered
                        </span>
                        <span className="col-span-1 text-xs font-medium text-muted-foreground">
                            Assigned
                        </span>
                        <span className="col-span-1 text-xs font-medium text-muted-foreground">
                            Action
                        </span>
                    </div>

                    {/* Mobile header */}
                    <div className="flex items-center justify-between border-b bg-muted/50 px-4 py-2 md:hidden">
                        <span className="text-sm font-medium">
                            Alerts ({alerts.meta.total})
                        </span>
                        {alerts.meta.last_page > 1 && (
                            <span className="text-xs text-muted-foreground">
                                Page {alerts.meta.current_page} of{' '}
                                {alerts.meta.last_page}
                            </span>
                        )}
                    </div>

                    <div className="divide-y">
                        {alerts.data.length ? (
                            alerts.data.map((alert, idx) => (
                                <Button unstyled
                                    type="button"
                                    key={alert.id}
                                    onClick={() => openWorkspace(alert.id)}
                                    className={`flex w-full items-center justify-between gap-4 px-4 py-3 text-left transition-colors hover:bg-muted/50 ${
                                        idx % 2 === 1 ? 'bg-muted/20' : ''
                                    } ${
                                        alert.severity === 'critical'
                                            ? 'border-l-4 border-l-red-500'
                                            : alert.severity === 'high'
                                              ? 'border-l-4 border-l-orange-500'
                                              : ''
                                    }`}
                                >
                                    <div className="min-w-0 flex-1">
                                        <div className="flex items-center gap-2">
                                            <span className="font-medium">
                                                {alert.alert_type}
                                            </span>
                                            {alert.escalation_level > 0 && (
                                                <Badge
                                                    variant="outline"
                                                    className="border-status-warning/30 text-status-warning"
                                                >
                                                    L{alert.escalation_level}
                                                </Badge>
                                            )}
                                        </div>
                                        <div className="mt-1 flex flex-wrap items-center gap-2 text-xs text-muted-foreground">
                                            <span>
                                                {formatRelativeTime(
                                                    alert.triggered_at,
                                                )}
                                            </span>
                                            <span>|</span>
                                            <span>{alert.source}</span>
                                            {alert.asset && (
                                                <>
                                                    <span>|</span>
                                                    <span>
                                                        {alert.asset.name}
                                                    </span>
                                                </>
                                            )}
                                            {alert.client_name && (
                                                <>
                                                    <span>|</span>
                                                    <span>
                                                        {alert.client_name}
                                                    </span>
                                                </>
                                            )}
                                            {alert.assigned_to && (
                                                <>
                                                    <span>|</span>
                                                    <span className="flex items-center gap-1">
                                                        <User className="h-3 w-3" />
                                                        {alert.assigned_to.name}
                                                    </span>
                                                </>
                                            )}
                                        </div>
                                    </div>
                                    <div className="flex items-center gap-2">
                                        {alert.sla_status && (
                                            <span
                                                className={`inline-block h-2.5 w-2.5 rounded-full ${
                                                    alert.sla_status ===
                                                    'on_track'
                                                        ? 'bg-status-success'
                                                        : alert.sla_status ===
                                                            'at_risk'
                                                          ? 'bg-status-warning'
                                                          : 'bg-status-critical'
                                                }`}
                                                title={`SLA: ${alert.sla_status.replace('_', ' ')}`}
                                            />
                                        )}
                                        <Badge
                                            className={
                                                severityColors[
                                                    alert.severity
                                                ] || ''
                                            }
                                        >
                                            {severityIcons[alert.severity]}
                                            {alert.severity}
                                        </Badge>
                                        <Badge
                                            variant="outline"
                                            className={
                                                statusColors[alert.status] || ''
                                            }
                                        >
                                            {alert.status}
                                        </Badge>
                                    </div>
                                </Button>
                            ))
                        ) : (
                            <div className="px-4 py-8 text-center text-sm text-muted-foreground">
                                No alerts found matching your filters.
                            </div>
                        )}
                    </div>

                    {/* Pagination */}
                    {alerts.meta.last_page > 1 && (
                        <div className="flex items-center justify-center gap-2 border-t px-4 py-3">
                            {alerts.links
                                .filter(
                                    (link) =>
                                        link.url &&
                                        !link.label.includes('Previous') &&
                                        !link.label.includes('Next'),
                                )
                                .slice(0, 10)
                                .map((link, i) => (
                                    <Button
                                        key={i}
                                        variant={
                                            link.active ? 'default' : 'outline'
                                        }
                                        size="sm"
                                        asChild
                                        disabled={!link.url}
                                    >
                                        <Link
                                            href={link.url || '#'}
                                            preserveState
                                            preserveScroll
                                        >
                                            {link.label
                                                .replace('&laquo;', '«')
                                                .replace('&raquo;', '»')}
                                        </Link>
                                    </Button>
                                ))}
                        </div>
                    )}
                </div>
            </PageShell>

            {/* Workspace-over-list */}
            {detail ? (
                <AlertWorkspaceDialog detail={detail} open onClose={closeWorkspace} />
            ) : null}
        </AppLayout>
    );
}
