import AppLayout from '@/layouts/app-layout';
import PageHeader from '@/components/page-header';
import PageShell from '@/components/page-shell';
import { Head, router } from '@inertiajs/react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    BarChart,
    Bar,
    XAxis,
    YAxis,
    CartesianGrid,
    Tooltip,
    ResponsiveContainer,
    PieChart,
    Pie,
    Cell,
    AreaChart,
    Area,
} from 'recharts';
import {
    Activity,
    AlertTriangle,
    CheckCircle,
    Clock,
    ShieldCheck,
    Users,
    Wifi,
    WifiOff,
    Zap,
} from 'lucide-react';
import { useEffect, useRef } from 'react';

interface Props {
    period: string;
    kpis: {
        avg_acknowledge_minutes: number;
        avg_resolution_hours: number;
        sla_compliance_pct: number;
        open_alerts: number;
        alerts_today: number;
    };
    volume_trend: { label: string; count: number }[];
    top_sources: { name: string; count: number }[];
    top_alert_types: { name: string; count: number }[];
    severity_distribution: Record<string, number>;
    operator_performance: {
        name: string;
        alerts_handled: number;
        avg_response_minutes: number;
    }[];
    shift_comparison: {
        name: string;
        duration_hours: number;
        alerts_created: number;
        alerts_resolved: number;
        alerts_escalated: number;
    }[];
    signal_sources: {
        name: string;
        status: string;
        last_heartbeat_at: string | null;
        signal_count_24h: number;
        is_healthy: boolean;
    }[];
}

const SEVERITY_COLORS: Record<string, string> = {
    critical: '#ef4444',
    high: '#f97316',
    medium: '#eab308',
    low: '#3b82f6',
};

const CHART_COLORS = ['#6366f1', '#8b5cf6', '#a855f7', '#d946ef', '#ec4899', '#f43f5e', '#fb923c', '#facc15', '#4ade80', '#22d3ee'];

function formatRelativeTime(isoString: string | null): string {
    if (!isoString) return 'Never';
    const date = new Date(isoString);
    const now = new Date();
    const diffMs = now.getTime() - date.getTime();
    const diffSec = Math.floor(diffMs / 1000);
    if (diffSec < 60) return `${diffSec}s ago`;
    const diffMin = Math.floor(diffSec / 60);
    if (diffMin < 60) return `${diffMin}m ago`;
    const diffHr = Math.floor(diffMin / 60);
    if (diffHr < 24) return `${diffHr}h ago`;
    const diffDay = Math.floor(diffHr / 24);
    return `${diffDay}d ago`;
}

const breadcrumbs = [
    { title: 'Control Room', href: '/control-room' },
    { title: 'Live Statistics', href: '#' },
];

export default function ControlRoomStats({
    period,
    kpis,
    volume_trend,
    top_sources,
    top_alert_types,
    severity_distribution,
    operator_performance,
    shift_comparison,
    signal_sources,
}: Props) {
    // Auto-refresh every 30 seconds
    const timerRef = useRef<ReturnType<typeof setInterval> | null>(null);

    useEffect(() => {
        timerRef.current = setInterval(() => {
            router.reload({ only: ['kpis', 'volume_trend', 'top_sources', 'top_alert_types', 'severity_distribution', 'operator_performance', 'shift_comparison', 'signal_sources'] });
        }, 30_000);
        return () => {
            if (timerRef.current) clearInterval(timerRef.current);
        };
    }, []);

    const handlePeriodChange = (newPeriod: string) => {
        router.get('/control-room/stats', { period: newPeriod }, { preserveState: true });
    };

    // Prepare severity pie data
    const severityPieData = Object.entries(severity_distribution).map(([name, value]) => ({
        name: name.charAt(0).toUpperCase() + name.slice(1),
        value,
        color: SEVERITY_COLORS[name] ?? '#94a3b8',
    }));

    // Max for operator bar visualization
    const maxOperatorAlerts = Math.max(...operator_performance.map((o) => o.alerts_handled), 1);

    const periodLabel = period === '24h' ? 'Last 24 Hours' : period === '7d' ? 'Last 7 Days' : 'Last 30 Days';

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Live Statistics - Control Room" />
            <PageShell>
                <PageHeader title="Live Statistics">
                    <div className="flex items-center gap-3">
                        <div className="flex items-center gap-1.5 text-xs text-muted-foreground">
                            <span className="relative flex h-2 w-2">
                                <span className="absolute inline-flex h-full w-full animate-ping rounded-full bg-green-400 opacity-75" />
                                <span className="relative inline-flex h-2 w-2 rounded-full bg-green-500" />
                            </span>
                            Auto-refreshing
                        </div>
                        <Select value={period} onValueChange={handlePeriodChange}>
                            <SelectTrigger className="w-[160px]">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="24h">Last 24 Hours</SelectItem>
                                <SelectItem value="7d">Last 7 Days</SelectItem>
                                <SelectItem value="30d">Last 30 Days</SelectItem>
                            </SelectContent>
                        </Select>
                    </div>
                </PageHeader>

                {/* KPI Cards */}
                <div className="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-5">
                    <Card>
                        <CardContent className="pt-6">
                            <div className="flex items-center gap-3">
                                <div className="rounded-lg bg-blue-100 p-2 dark:bg-blue-900/30">
                                    <Clock className="h-5 w-5 text-blue-600 dark:text-blue-400" />
                                </div>
                                <div>
                                    <p className="text-xs font-medium text-muted-foreground">Avg Acknowledge</p>
                                    <p className="text-2xl font-bold">{kpis.avg_acknowledge_minutes}m</p>
                                </div>
                            </div>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="pt-6">
                            <div className="flex items-center gap-3">
                                <div className="rounded-lg bg-purple-100 p-2 dark:bg-purple-900/30">
                                    <CheckCircle className="h-5 w-5 text-purple-600 dark:text-purple-400" />
                                </div>
                                <div>
                                    <p className="text-xs font-medium text-muted-foreground">Avg Resolution</p>
                                    <p className="text-2xl font-bold">{kpis.avg_resolution_hours}h</p>
                                </div>
                            </div>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="pt-6">
                            <div className="flex items-center gap-3">
                                <div className="rounded-lg bg-green-100 p-2 dark:bg-green-900/30">
                                    <ShieldCheck className="h-5 w-5 text-green-600 dark:text-green-400" />
                                </div>
                                <div>
                                    <p className="text-xs font-medium text-muted-foreground">SLA Compliance</p>
                                    <p className="text-2xl font-bold">{kpis.sla_compliance_pct}%</p>
                                </div>
                            </div>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="pt-6">
                            <div className="flex items-center gap-3">
                                <div className="rounded-lg bg-orange-100 p-2 dark:bg-orange-900/30">
                                    <AlertTriangle className="h-5 w-5 text-orange-600 dark:text-orange-400" />
                                </div>
                                <div>
                                    <p className="text-xs font-medium text-muted-foreground">Open Alerts</p>
                                    <p className="text-2xl font-bold">{kpis.open_alerts}</p>
                                </div>
                            </div>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="pt-6">
                            <div className="flex items-center gap-3">
                                <div className="rounded-lg bg-indigo-100 p-2 dark:bg-indigo-900/30">
                                    <Zap className="h-5 w-5 text-indigo-600 dark:text-indigo-400" />
                                </div>
                                <div>
                                    <p className="text-xs font-medium text-muted-foreground">Alerts Today</p>
                                    <p className="text-2xl font-bold">{kpis.alerts_today}</p>
                                </div>
                            </div>
                        </CardContent>
                    </Card>
                </div>

                {/* Charts Grid */}
                <div className="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-2">
                    {/* Alert Volume Trend */}
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Alert Volume Trend</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="h-[280px]">
                                <ResponsiveContainer width="100%" height="100%">
                                    <AreaChart data={volume_trend}>
                                        <defs>
                                            <linearGradient id="volumeGradient" x1="0" y1="0" x2="0" y2="1">
                                                <stop offset="5%" stopColor="#6366f1" stopOpacity={0.3} />
                                                <stop offset="95%" stopColor="#6366f1" stopOpacity={0} />
                                            </linearGradient>
                                        </defs>
                                        <CartesianGrid strokeDasharray="3 3" className="stroke-muted" />
                                        <XAxis
                                            dataKey="label"
                                            tick={{ fontSize: 11 }}
                                            interval="preserveStartEnd"
                                            className="text-muted-foreground"
                                        />
                                        <YAxis tick={{ fontSize: 11 }} allowDecimals={false} className="text-muted-foreground" />
                                        <Tooltip
                                            contentStyle={{
                                                backgroundColor: 'hsl(var(--card))',
                                                border: '1px solid hsl(var(--border))',
                                                borderRadius: '8px',
                                                fontSize: '12px',
                                            }}
                                        />
                                        <Area
                                            type="monotone"
                                            dataKey="count"
                                            stroke="#6366f1"
                                            strokeWidth={2}
                                            fill="url(#volumeGradient)"
                                            name="Alerts"
                                        />
                                    </AreaChart>
                                </ResponsiveContainer>
                            </div>
                        </CardContent>
                    </Card>

                    {/* Severity Distribution */}
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Severity Distribution (Unresolved)</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="h-[280px]">
                                {severityPieData.length === 0 ? (
                                    <div className="flex h-full items-center justify-center text-sm text-muted-foreground">
                                        No unresolved alerts
                                    </div>
                                ) : (
                                    <ResponsiveContainer width="100%" height="100%">
                                        <PieChart>
                                            <Pie
                                                data={severityPieData}
                                                cx="50%"
                                                cy="50%"
                                                innerRadius={60}
                                                outerRadius={100}
                                                paddingAngle={3}
                                                dataKey="value"
                                                nameKey="name"
                                                label={({ name, value }) => `${name}: ${value}`}
                                            >
                                                {severityPieData.map((entry, index) => (
                                                    <Cell key={index} fill={entry.color} />
                                                ))}
                                            </Pie>
                                            <Tooltip
                                                contentStyle={{
                                                    backgroundColor: 'hsl(var(--card))',
                                                    border: '1px solid hsl(var(--border))',
                                                    borderRadius: '8px',
                                                    fontSize: '12px',
                                                }}
                                            />
                                        </PieChart>
                                    </ResponsiveContainer>
                                )}
                            </div>
                        </CardContent>
                    </Card>

                    {/* Top Sources */}
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Top Sources ({periodLabel})</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="h-[280px]">
                                {top_sources.length === 0 ? (
                                    <div className="flex h-full items-center justify-center text-sm text-muted-foreground">
                                        No data for this period
                                    </div>
                                ) : (
                                    <ResponsiveContainer width="100%" height="100%">
                                        <BarChart data={top_sources} layout="vertical" margin={{ left: 80 }}>
                                            <CartesianGrid strokeDasharray="3 3" className="stroke-muted" />
                                            <XAxis type="number" tick={{ fontSize: 11 }} allowDecimals={false} />
                                            <YAxis
                                                type="category"
                                                dataKey="name"
                                                tick={{ fontSize: 11 }}
                                                width={75}
                                            />
                                            <Tooltip
                                                contentStyle={{
                                                    backgroundColor: 'hsl(var(--card))',
                                                    border: '1px solid hsl(var(--border))',
                                                    borderRadius: '8px',
                                                    fontSize: '12px',
                                                }}
                                            />
                                            <Bar dataKey="count" fill="#8b5cf6" radius={[0, 4, 4, 0]} name="Alerts" />
                                        </BarChart>
                                    </ResponsiveContainer>
                                )}
                            </div>
                        </CardContent>
                    </Card>

                    {/* Top Alert Types */}
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Top Alert Types ({periodLabel})</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="h-[280px]">
                                {top_alert_types.length === 0 ? (
                                    <div className="flex h-full items-center justify-center text-sm text-muted-foreground">
                                        No data for this period
                                    </div>
                                ) : (
                                    <ResponsiveContainer width="100%" height="100%">
                                        <BarChart data={top_alert_types} layout="vertical" margin={{ left: 80 }}>
                                            <CartesianGrid strokeDasharray="3 3" className="stroke-muted" />
                                            <XAxis type="number" tick={{ fontSize: 11 }} allowDecimals={false} />
                                            <YAxis
                                                type="category"
                                                dataKey="name"
                                                tick={{ fontSize: 11 }}
                                                width={75}
                                            />
                                            <Tooltip
                                                contentStyle={{
                                                    backgroundColor: 'hsl(var(--card))',
                                                    border: '1px solid hsl(var(--border))',
                                                    borderRadius: '8px',
                                                    fontSize: '12px',
                                                }}
                                            />
                                            <Bar dataKey="count" fill="#f97316" radius={[0, 4, 4, 0]} name="Alerts" />
                                        </BarChart>
                                    </ResponsiveContainer>
                                )}
                            </div>
                        </CardContent>
                    </Card>
                </div>

                {/* Operator Performance */}
                <div className="mt-6">
                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2 text-base">
                                <Users className="h-4 w-4" />
                                Operator Performance ({periodLabel})
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            {operator_performance.length === 0 ? (
                                <p className="text-sm text-muted-foreground">No operator data for this period.</p>
                            ) : (
                                <div className="overflow-x-auto">
                                    <table className="w-full text-sm">
                                        <thead>
                                            <tr className="border-b text-left text-muted-foreground">
                                                <th className="pb-3 pr-4 font-medium">Operator</th>
                                                <th className="pb-3 pr-4 font-medium text-right">Alerts Handled</th>
                                                <th className="pb-3 pr-4 font-medium text-right">Avg Response</th>
                                                <th className="hidden pb-3 font-medium sm:table-cell">Volume</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {operator_performance.map((op, i) => (
                                                <tr key={i} className="border-b last:border-0">
                                                    <td className="py-3 pr-4 font-medium">{op.name}</td>
                                                    <td className="py-3 pr-4 text-right">{op.alerts_handled}</td>
                                                    <td className="py-3 pr-4 text-right">
                                                        {op.avg_response_minutes > 0
                                                            ? `${op.avg_response_minutes}m`
                                                            : '-'}
                                                    </td>
                                                    <td className="hidden py-3 sm:table-cell">
                                                        <div className="h-2 w-full max-w-[200px] rounded-full bg-muted">
                                                            <div
                                                                className="h-2 rounded-full bg-indigo-500"
                                                                style={{
                                                                    width: `${(op.alerts_handled / maxOperatorAlerts) * 100}%`,
                                                                }}
                                                            />
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
                </div>

                {/* Shift Comparison */}
                <div className="mt-6">
                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2 text-base">
                                <Activity className="h-4 w-4" />
                                Shift Comparison (Last 5 Completed)
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            {shift_comparison.length === 0 ? (
                                <p className="text-sm text-muted-foreground">No completed shifts found.</p>
                            ) : (
                                <div className="overflow-x-auto">
                                    <table className="w-full text-sm">
                                        <thead>
                                            <tr className="border-b text-left text-muted-foreground">
                                                <th className="pb-3 pr-4 font-medium">Shift</th>
                                                <th className="pb-3 pr-4 font-medium text-right">Duration</th>
                                                <th className="pb-3 pr-4 font-medium text-right">Created</th>
                                                <th className="pb-3 pr-4 font-medium text-right">Resolved</th>
                                                <th className="pb-3 font-medium text-right">Escalated</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {shift_comparison.map((s, i) => (
                                                <tr key={i} className="border-b last:border-0">
                                                    <td className="py-3 pr-4 font-medium">{s.name}</td>
                                                    <td className="py-3 pr-4 text-right">{s.duration_hours}h</td>
                                                    <td className="py-3 pr-4 text-right">{s.alerts_created}</td>
                                                    <td className="py-3 pr-4 text-right text-green-600 dark:text-green-400">
                                                        {s.alerts_resolved}
                                                    </td>
                                                    <td className="py-3 text-right">
                                                        {s.alerts_escalated > 0 ? (
                                                            <span className="text-orange-600 dark:text-orange-400">
                                                                {s.alerts_escalated}
                                                            </span>
                                                        ) : (
                                                            <span>{s.alerts_escalated}</span>
                                                        )}
                                                    </td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            )}
                        </CardContent>
                    </Card>
                </div>

                {/* Signal Source Health */}
                <div className="mt-6">
                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2 text-base">
                                <Wifi className="h-4 w-4" />
                                Signal Source Health
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            {signal_sources.length === 0 ? (
                                <p className="text-sm text-muted-foreground">No signal sources configured.</p>
                            ) : (
                                <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                                    {signal_sources.map((ss, i) => (
                                        <div
                                            key={i}
                                            className={`rounded-lg border p-4 ${
                                                !ss.is_healthy
                                                    ? 'border-red-300 bg-red-50 dark:border-red-800 dark:bg-red-950/30'
                                                    : 'border-border'
                                            }`}
                                        >
                                            <div className="flex items-start justify-between">
                                                <div className="flex items-center gap-2">
                                                    <span
                                                        className={`inline-block h-2.5 w-2.5 rounded-full ${
                                                            ss.is_healthy ? 'bg-green-500' : 'bg-red-500'
                                                        }`}
                                                    />
                                                    <span className="text-sm font-medium">{ss.name}</span>
                                                </div>
                                                <Badge variant="secondary" className="text-xs">
                                                    {ss.signal_count_24h} / 24h
                                                </Badge>
                                            </div>
                                            <div className="mt-2 flex items-center gap-1 text-xs text-muted-foreground">
                                                {ss.is_healthy ? (
                                                    <Wifi className="h-3 w-3" />
                                                ) : (
                                                    <WifiOff className="h-3 w-3 text-red-500" />
                                                )}
                                                <span>
                                                    {ss.last_heartbeat_at
                                                        ? formatRelativeTime(ss.last_heartbeat_at)
                                                        : 'No heartbeat'}
                                                </span>
                                            </div>
                                        </div>
                                    ))}
                                </div>
                            )}
                        </CardContent>
                    </Card>
                </div>
            </PageShell>
        </AppLayout>
    );
}
