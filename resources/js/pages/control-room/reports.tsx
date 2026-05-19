import PageShell from '@/components/page-shell';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { PageHero } from '@/components/page';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, router } from '@inertiajs/react';
import {
    AlertTriangle,
    ArrowLeft,
    BarChart3,
    CheckCircle,
    Clock,
    Download,
    TrendingUp,
} from 'lucide-react';

interface Props {
    period: string;
    stats: {
        total_alerts: number;
        resolved_alerts: number;
        resolution_rate: number;
        avg_resolution_hours: number;
        escalated_count: number;
        escalation_rate: number;
    };
    by_severity: Record<string, number>;
    by_status: Record<string, number>;
    by_source: Record<string, number>;
    by_alert_type: Record<string, number>;
    daily_trend: { date: string; count: number }[];
    response_time_by_severity: Record<string, number>;
    top_assignees: { user: string; count: number }[];
}

const severityColors: Record<string, string> = {
    critical: 'bg-status-critical',
    high: 'bg-status-warning',
    medium: 'bg-status-warning',
    low: 'bg-status-info',
};

const statusColors: Record<string, string> = {
    open: 'bg-status-critical',
    ack: 'bg-status-warning',
    triaging: 'bg-status-info',
    resolved: 'bg-status-success',
    closed: 'bg-muted',
};

export default function ControlRoomReports({
    period,
    stats,
    by_severity,
    by_status,
    by_source,
    by_alert_type,
    daily_trend,
    response_time_by_severity,
    top_assignees,
}: Props) {
    const handlePeriodChange = (newPeriod: string) => {
        router.get(
            '/control-room/reports',
            { period: newPeriod },
            { preserveState: true },
        );
    };

    const handleExport = () => {
        window.location.href = `/control-room/reports/export?period=${period}`;
    };

    const maxTrend = Math.max(...daily_trend.map((d) => d.count), 1);

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Control Room', href: '/control-room' },
                { title: 'Reports', href: '#' },
            ]}
        >
            <Head title="Control Room Reports" />
            <PageShell>
                <PageHero
                    icon={BarChart3}
                    title="Control Room Reports"
                    description="Alert statistics and performance metrics"
                    stats={[
                        { label: 'Total alerts', value: stats.total_alerts },
                        { label: 'Resolution rate', value: `${stats.resolution_rate}%` },
                        { label: 'Avg resolution', value: `${stats.avg_resolution_hours}h` },
                        { label: 'Escalation rate', value: `${stats.escalation_rate}%` },
                    ]}
                    actions={
                        <div className="flex items-center gap-2">
                            <Select
                                value={period}
                                onValueChange={handlePeriodChange}
                            >
                                <SelectTrigger className="w-32 border-primary-foreground/30 bg-primary-foreground/10 text-primary-foreground backdrop-blur-sm hover:bg-primary-foreground/20">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="7d">Last 7 days</SelectItem>
                                    <SelectItem value="30d">
                                        Last 30 days
                                    </SelectItem>
                                    <SelectItem value="90d">
                                        Last 90 days
                                    </SelectItem>
                                    <SelectItem value="1y">Last year</SelectItem>
                                </SelectContent>
                            </Select>
                            <Button
                                variant="outline"
                                size="sm"
                                onClick={handleExport}
                                className="border-primary-foreground/30 bg-primary-foreground/10 text-primary-foreground backdrop-blur-sm hover:bg-primary-foreground/20 hover:text-primary-foreground"
                            >
                                <Download className="mr-2 h-4 w-4" />
                                Export CSV
                            </Button>
                            <Button
                                variant="outline"
                                size="sm"
                                asChild
                                className="border-primary-foreground/30 bg-primary-foreground/10 text-primary-foreground backdrop-blur-sm hover:bg-primary-foreground/20 hover:text-primary-foreground"
                            >
                                <Link href="/control-room">
                                    <ArrowLeft className="mr-2 h-4 w-4" />
                                    Dashboard
                                </Link>
                            </Button>
                        </div>
                    }
                />

                {/* Key Metrics */}
                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="flex items-center gap-2 text-sm font-medium text-muted-foreground">
                                <AlertTriangle className="h-4 w-4" />
                                Total Alerts
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="text-3xl font-bold">
                                {stats.total_alerts}
                            </div>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="flex items-center gap-2 text-sm font-medium text-muted-foreground">
                                <CheckCircle className="h-4 w-4" />
                                Resolution Rate
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="text-3xl font-bold">
                                {stats.resolution_rate}%
                            </div>
                            <p className="text-xs text-muted-foreground">
                                {stats.resolved_alerts} resolved
                            </p>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="flex items-center gap-2 text-sm font-medium text-muted-foreground">
                                <Clock className="h-4 w-4" />
                                Avg Resolution Time
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="text-3xl font-bold">
                                {stats.avg_resolution_hours}h
                            </div>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="flex items-center gap-2 text-sm font-medium text-muted-foreground">
                                <TrendingUp className="h-4 w-4" />
                                Escalation Rate
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="text-3xl font-bold">
                                {stats.escalation_rate}%
                            </div>
                            <p className="text-xs text-muted-foreground">
                                {stats.escalated_count} escalated
                            </p>
                        </CardContent>
                    </Card>
                </div>

                {/* Charts Row */}
                <div className="mt-6 grid gap-4 lg:grid-cols-2">
                    {/* Daily Trend */}
                    <Card>
                        <CardHeader>
                            <CardTitle>Daily Alert Trend</CardTitle>
                        </CardHeader>
                        <CardContent>
                            {daily_trend.length > 0 ? (
                                <div className="flex h-40 items-end gap-1">
                                    {daily_trend.slice(-30).map((day, i) => (
                                        <div
                                            key={i}
                                            className="group relative flex-1"
                                        >
                                            <div
                                                className="w-full rounded-t bg-primary transition-all hover:bg-primary/80"
                                                style={{
                                                    height: `${(day.count / maxTrend) * 100}%`,
                                                    minHeight: day.count > 0 ? '4px' : '0',
                                                }}
                                            />
                                            <div className="absolute bottom-full left-1/2 mb-1 hidden -translate-x-1/2 rounded bg-popover px-2 py-1 text-xs shadow-md group-hover:block">
                                                {day.date}: {day.count}
                                            </div>
                                        </div>
                                    ))}
                                </div>
                            ) : (
                                <p className="text-sm text-muted-foreground">
                                    No data available
                                </p>
                            )}
                        </CardContent>
                    </Card>

                    {/* By Severity */}
                    <Card>
                        <CardHeader>
                            <CardTitle>By Severity</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="space-y-3">
                                {['critical', 'high', 'medium', 'low'].map(
                                    (sev) => {
                                        const count = by_severity[sev] || 0;
                                        const pct =
                                            stats.total_alerts > 0
                                                ? (count /
                                                      stats.total_alerts) *
                                                  100
                                                : 0;
                                        return (
                                            <div key={sev}>
                                                <div className="mb-1 flex justify-between text-sm">
                                                    <span className="capitalize">
                                                        {sev}
                                                    </span>
                                                    <span className="text-muted-foreground">
                                                        {count} ({pct.toFixed(1)}
                                                        %)
                                                    </span>
                                                </div>
                                                <div className="h-2 rounded-full bg-muted">
                                                    <div
                                                        className={`h-full rounded-full ${severityColors[sev]}`}
                                                        style={{
                                                            width: `${pct}%`,
                                                        }}
                                                    />
                                                </div>
                                            </div>
                                        );
                                    },
                                )}
                            </div>
                        </CardContent>
                    </Card>
                </div>

                {/* Second Row */}
                <div className="mt-4 grid gap-4 lg:grid-cols-3">
                    {/* By Status */}
                    <Card>
                        <CardHeader>
                            <CardTitle>By Status</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="space-y-2">
                                {Object.entries(by_status).map(([status, count]) => (
                                    <div
                                        key={status}
                                        className="flex items-center justify-between"
                                    >
                                        <div className="flex items-center gap-2">
                                            <div
                                                className={`h-3 w-3 rounded-full ${statusColors[status] || 'bg-muted'}`}
                                            />
                                            <span className="text-sm capitalize">
                                                {status}
                                            </span>
                                        </div>
                                        <span className="text-sm font-medium">
                                            {count}
                                        </span>
                                    </div>
                                ))}
                            </div>
                        </CardContent>
                    </Card>

                    {/* By Source */}
                    <Card>
                        <CardHeader>
                            <CardTitle>By Source</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="space-y-2">
                                {Object.entries(by_source).map(
                                    ([source, count]) => (
                                        <div
                                            key={source}
                                            className="flex items-center justify-between"
                                        >
                                            <span className="text-sm">
                                                {source.replace('_', ' ')}
                                            </span>
                                            <span className="text-sm font-medium">
                                                {count}
                                            </span>
                                        </div>
                                    ),
                                )}
                            </div>
                        </CardContent>
                    </Card>

                    {/* Top Alert Types */}
                    <Card>
                        <CardHeader>
                            <CardTitle>Top Alert Types</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="space-y-2">
                                {Object.entries(by_alert_type)
                                    .slice(0, 5)
                                    .map(([type, count]) => (
                                        <div
                                            key={type}
                                            className="flex items-center justify-between"
                                        >
                                            <span className="truncate text-sm">
                                                {type}
                                            </span>
                                            <span className="ml-2 text-sm font-medium">
                                                {count}
                                            </span>
                                        </div>
                                    ))}
                            </div>
                        </CardContent>
                    </Card>
                </div>

                {/* Third Row */}
                <div className="mt-4 grid gap-4 lg:grid-cols-2">
                    {/* Response Time by Severity */}
                    <Card>
                        <CardHeader>
                            <CardTitle>
                                Avg Response Time by Severity (minutes)
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="space-y-3">
                                {['critical', 'high', 'medium', 'low'].map(
                                    (sev) => {
                                        const mins =
                                            response_time_by_severity[sev] || 0;
                                        return (
                                            <div
                                                key={sev}
                                                className="flex items-center justify-between"
                                            >
                                                <span className="capitalize">
                                                    {sev}
                                                </span>
                                                <span className="font-medium">
                                                    {mins > 0
                                                        ? `${Math.round(mins)} min`
                                                        : '-'}
                                                </span>
                                            </div>
                                        );
                                    },
                                )}
                            </div>
                        </CardContent>
                    </Card>

                    {/* Top Assignees */}
                    <Card>
                        <CardHeader>
                            <CardTitle>Top Assignees</CardTitle>
                        </CardHeader>
                        <CardContent>
                            {top_assignees.length > 0 ? (
                                <div className="space-y-2">
                                    {top_assignees.map((a, i) => (
                                        <div
                                            key={i}
                                            className="flex items-center justify-between"
                                        >
                                            <span className="text-sm">
                                                {a.user}
                                            </span>
                                            <span className="text-sm font-medium">
                                                {a.count} alerts
                                            </span>
                                        </div>
                                    ))}
                                </div>
                            ) : (
                                <p className="text-sm text-muted-foreground">
                                    No assignments in this period
                                </p>
                            )}
                        </CardContent>
                    </Card>
                </div>
            </PageShell>
        </AppLayout>
    );
}
