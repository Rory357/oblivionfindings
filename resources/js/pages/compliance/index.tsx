import AppLayout from '@/layouts/app-layout';
import { Head, Link } from '@inertiajs/react';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Separator } from '@/components/ui/separator';

import {
    ResponsiveContainer,
    CartesianGrid,
    Tooltip,
    BarChart,
    Bar,
    LineChart,
    Line,
    XAxis,
    YAxis,
    AreaChart,
    Area,
} from 'recharts';
import { AlertTriangle, Bell, TrendingUp, ArrowRight } from 'lucide-react';
import { Badge } from '@/components/ui/badge';

interface ControlRoomAlert {
    id: number;
    alert_type: string;
    severity: string;
    status: string;
    source: string;
    triggered_at: string | null;
}

type Props = {
    kpis: {
        openIncidents: number;
        openCdDiscrepancies: number;
        marExceptionsToday: number;
        breakGlassLast30d: number;
        carePlanReviewsDue: number;
        auditEvents30d: number;
    };
    controlRoom: {
        open: number;
        critical: number;
        escalated: number;
        recentAlerts: ControlRoomAlert[];
        alertTrend: Array<{ date: string; total: number }>;
    };
    charts: {
        incidentBySeverity: Array<{ severity: string; total: number }>;
        marTrend: Array<{
            date: string;
            given: number;
            missed: number;
            refused: number;
            withheld: number;
        }>;
        cdTrend: Array<{ date: string; total: number }>;
    };
};

function KpiCard({
    title,
    value,
    hint,
    href,
}: {
    title: string;
    value: number | string;
    hint?: string;
    href?: string;
}) {
    const inner = (
        <Card className="hover:shadow-sm transition-shadow">
            <CardHeader className="pb-2">
                <CardTitle className="text-sm font-medium text-slate-600">
                    {title}
                </CardTitle>
            </CardHeader>
            <CardContent className="space-y-1">
                <div className="text-3xl font-semibold">{value}</div>
                {hint ? (
                    <div className="text-xs text-slate-500">{hint}</div>
                ) : null}
            </CardContent>
        </Card>
    );

    return href ? (
        <Link href={href} className="block">
            {inner}
        </Link>
    ) : (
        inner
    );
}

const severityColors: Record<string, string> = {
    critical: 'bg-red-600 text-white',
    high: 'bg-orange-500 text-white',
    medium: 'bg-yellow-500 text-black',
    low: 'bg-blue-500 text-white',
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

export default function ComplianceIndex({ kpis, controlRoom, charts }: Props) {
    return (
        <AppLayout breadcrumbs={[{ title: 'Compliance', href: '/compliance' }]}>
            <Head title="Compliance" />

            <div className="space-y-6">
                <div className="flex items-start justify-between gap-3 flex-wrap">
                    <div>
                        <div className="text-xl font-semibold">
                            Compliance Dashboard
                        </div>
                        <div className="text-sm text-slate-600">
                            Exceptions, registers due, and audit evidence at a glance.
                        </div>
                    </div>

                    <div className="flex gap-2 flex-wrap">
                        <Button asChild variant="outline">
                            <Link href="/reports">Reports</Link>
                        </Button>
                        <Button asChild variant="outline">
                            <Link href="/audit-logs">Audit Logs</Link>
                        </Button>
                        <Button asChild>
                            <Link href="/incidents">Incidents</Link>
                        </Button>
                    </div>
                </div>

                <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-6 gap-3">
                    <KpiCard
                        title="Open Incidents"
                        value={kpis.openIncidents}
                        hint="Submitted / reviewed"
                        href="/incidents?status=submitted"
                    />
                    <KpiCard
                        title="CD Discrepancies"
                        value={kpis.openCdDiscrepancies}
                        hint="Open"
                        href="/medications?tab=controlled"
                    />
                    <KpiCard
                        title="MAR Exceptions (Today)"
                        value={kpis.marExceptionsToday}
                        hint="Missed / refused / withheld"
                        href="/medications?tab=mar"
                    />
                    <KpiCard
                        title="Break-glass (30d)"
                        value={kpis.breakGlassLast30d}
                        hint="Emergency access events"
                        href="/emergency-access"
                    />
                    <KpiCard
                        title="Care Plan Reviews Due"
                        value={kpis.carePlanReviewsDue}
                        hint="Next 30 days"
                        href="/clients"
                    />
                    <KpiCard
                        title="Audit Events (30d)"
                        value={kpis.auditEvents30d}
                        hint="Logged activity"
                        href="/audit-logs"
                    />
                </div>

                {/* Control Room Section */}
                <Card>
                    <CardHeader className="pb-2">
                        <div className="flex items-center justify-between">
                            <CardTitle className="flex items-center gap-2 text-base font-semibold">
                                <Bell className="h-5 w-5 text-red-500" />
                                Control Room Alerts
                            </CardTitle>
                            <Button asChild variant="outline" size="sm">
                                <Link href="/control-room">
                                    View All
                                    <ArrowRight className="ml-1 h-4 w-4" />
                                </Link>
                            </Button>
                        </div>
                    </CardHeader>
                    <CardContent>
                        <div className="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-4">
                            <div className="text-center p-3 rounded-lg bg-red-50 border border-red-100">
                                <div className="flex items-center justify-center gap-1 text-red-600 mb-1">
                                    <Bell className="h-4 w-4" />
                                    <span className="text-xs font-medium">Open</span>
                                </div>
                                <div className="text-2xl font-bold text-red-700">{controlRoom.open}</div>
                            </div>
                            <div className="text-center p-3 rounded-lg bg-orange-50 border border-orange-100">
                                <div className="flex items-center justify-center gap-1 text-orange-600 mb-1">
                                    <AlertTriangle className="h-4 w-4" />
                                    <span className="text-xs font-medium">Critical</span>
                                </div>
                                <div className="text-2xl font-bold text-orange-700">{controlRoom.critical}</div>
                            </div>
                            <div className="text-center p-3 rounded-lg bg-yellow-50 border border-yellow-100">
                                <div className="flex items-center justify-center gap-1 text-yellow-600 mb-1">
                                    <TrendingUp className="h-4 w-4" />
                                    <span className="text-xs font-medium">Escalated</span>
                                </div>
                                <div className="text-2xl font-bold text-yellow-700">{controlRoom.escalated}</div>
                            </div>
                        </div>

                        <div className="grid grid-cols-1 lg:grid-cols-2 gap-4">
                            {/* Recent Alerts */}
                            <div>
                                <h4 className="text-sm font-medium text-slate-600 mb-2">Recent Alerts</h4>
                                {controlRoom.recentAlerts.length > 0 ? (
                                    <div className="space-y-2">
                                        {controlRoom.recentAlerts.map((alert) => (
                                            <Link
                                                key={alert.id}
                                                href={`/control-room/alerts/${alert.id}`}
                                                className="flex items-center justify-between p-2 rounded border hover:bg-slate-50 transition-colors"
                                            >
                                                <div className="min-w-0 flex-1">
                                                    <div className="text-sm font-medium truncate">{alert.alert_type}</div>
                                                    <div className="text-xs text-slate-500">
                                                        {alert.source} &middot; {formatRelativeTime(alert.triggered_at)}
                                                    </div>
                                                </div>
                                                <Badge className={severityColors[alert.severity] || 'bg-gray-500'}>
                                                    {alert.severity}
                                                </Badge>
                                            </Link>
                                        ))}
                                    </div>
                                ) : (
                                    <p className="text-sm text-slate-500 text-center py-4">No open alerts</p>
                                )}
                            </div>

                            {/* Alert Trend */}
                            <div>
                                <h4 className="text-sm font-medium text-slate-600 mb-2">Alert Trend (14 days)</h4>
                                <div style={{ height: 140 }}>
                                    <ResponsiveContainer width="100%" height="100%">
                                        <AreaChart data={controlRoom.alertTrend}>
                                            <CartesianGrid strokeDasharray="3 3" />
                                            <XAxis dataKey="date" hide />
                                            <YAxis allowDecimals={false} width={30} />
                                            <Tooltip />
                                            <Area type="monotone" dataKey="total" fill="#ef4444" stroke="#dc2626" fillOpacity={0.3} />
                                        </AreaChart>
                                    </ResponsiveContainer>
                                </div>
                            </div>
                        </div>
                    </CardContent>
                </Card>

                <Separator />

                <div className="grid grid-cols-1 lg:grid-cols-3 gap-3">
                    <Card className="lg:col-span-1">
                        <CardHeader className="pb-2">
                            <CardTitle className="text-sm font-medium text-slate-600">
                                Incidents by severity (30 days)
                            </CardTitle>
                        </CardHeader>
                        <CardContent style={{ height: 260 }}>
                            <ResponsiveContainer width="100%" height="100%">
                                <BarChart data={charts.incidentBySeverity}>
                                    <CartesianGrid strokeDasharray="3 3" />
                                    <XAxis dataKey="severity" />
                                    <YAxis allowDecimals={false} />
                                    <Tooltip />
                                    <Bar dataKey="total" />
                                </BarChart>
                            </ResponsiveContainer>
                        </CardContent>
                    </Card>

                    <Card className="lg:col-span-2">
                        <CardHeader className="pb-2">
                            <CardTitle className="text-sm font-medium text-slate-600">
                                MAR outcomes trend (14 days)
                            </CardTitle>
                        </CardHeader>
                        <CardContent style={{ height: 260 }}>
                            <ResponsiveContainer width="100%" height="100%">
                                <LineChart data={charts.marTrend}>
                                    <CartesianGrid strokeDasharray="3 3" />
                                    <XAxis dataKey="date" hide />
                                    <YAxis allowDecimals={false} />
                                    <Tooltip />
                                    <Line type="monotone" dataKey="given" dot={false} />
                                    <Line type="monotone" dataKey="missed" dot={false} />
                                    <Line type="monotone" dataKey="refused" dot={false} />
                                    <Line type="monotone" dataKey="withheld" dot={false} />
                                </LineChart>
                            </ResponsiveContainer>
                        </CardContent>
                    </Card>
                </div>

                <Card>
                    <CardHeader className="pb-2">
                        <CardTitle className="text-sm font-medium text-slate-600">
                            Controlled drug discrepancies trend (30 days)
                        </CardTitle>
                    </CardHeader>
                    <CardContent style={{ height: 260 }}>
                        <ResponsiveContainer width="100%" height="100%">
                            <LineChart data={charts.cdTrend}>
                                <CartesianGrid strokeDasharray="3 3" />
                                <XAxis dataKey="date" hide />
                                <YAxis allowDecimals={false} />
                                <Tooltip />
                                <Line type="monotone" dataKey="total" dot={false} />
                            </LineChart>
                        </ResponsiveContainer>
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
