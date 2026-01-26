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
} from 'recharts';

type Props = {
    kpis: {
        openIncidents: number;
        openCdDiscrepancies: number;
        marExceptionsToday: number;
        breakGlassLast30d: number;
        carePlanReviewsDue: number;
        auditEvents30d: number;
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

export default function ComplianceIndex({ kpis, charts }: Props) {
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
