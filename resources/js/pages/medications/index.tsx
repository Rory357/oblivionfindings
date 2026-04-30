import FleetHero from '@/components/fleet-hero';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, usePage } from '@inertiajs/react';
import {
    AlertCircle,
    AlertTriangle,
    BarChart3,
    ClipboardList,
    LayoutDashboard,
    Pill,
    ShieldAlert,
    XCircle,
} from 'lucide-react';

type Props = {
    date: string;
    clients: Array<{
        id: number;
        name: string;
        status?: string | null;
        counts: { due: number; late: number; missed: number };
        has_alerts?: boolean;
        has_critical_alerts?: boolean;
        discrepancy_count?: number;
    }>;
};

function pill(label: string, kind: 'ok' | 'warn' | 'bad') {
    const className =
        kind === 'ok'
            ? 'bg-status-success-bg text-status-success border-status-success/30'
            : kind === 'warn'
              ? 'bg-status-warning-bg text-status-warning border-status-warning/30'
              : 'bg-status-critical-bg text-status-critical border-status-critical/30';
    return (
        <Badge variant="outline" className={className}>
            {label}
        </Badge>
    );
}

export default function MedicationsIndex({ date, clients }: Props) {
    const { auth } = usePage().props as any;
    const canViewDashboard = auth?.can?.medications?.view;
    const canViewReports =
        auth?.can?.medications?.reports?.export || auth?.can?.reports?.viewAny;
    const canViewAudit = auth?.can?.medications?.audit?.view;

    return (
        <AppLayout
            breadcrumbs={[{ title: 'Medications', href: '/medications' }]}
        >
            <Head title="Medications" />

            <div className="flex flex-col gap-6 p-6">
                {/* Hero Header */}
                <FleetHero
                    title="Medications"
                    description={`Centralised "run-the-day" view • ${date}`}
                    icon={<Pill className="h-7 w-7 text-white" />}
                    stats={[
                        { label: 'Clients', value: clients.length },
                        {
                            label: 'Due Today',
                            value: clients.reduce(
                                (sum, c) => sum + c.counts.due,
                                0,
                            ),
                        },
                        {
                            label: 'Late',
                            value: clients.reduce(
                                (sum, c) => sum + c.counts.late,
                                0,
                            ),
                        },
                        {
                            label: 'Missed',
                            value: clients.reduce(
                                (sum, c) => sum + c.counts.missed,
                                0,
                            ),
                        },
                    ]}
                    actions={
                        <div className="flex flex-wrap gap-2">
                            {canViewDashboard && (
                                <Button variant="outline" asChild>
                                    <Link href="/emar">Dashboard</Link>
                                </Button>
                            )}
                            {canViewReports && (
                                <Button variant="outline" asChild>
                                    <Link href="/reports/medications">
                                        Reports
                                    </Link>
                                </Button>
                            )}
                        </div>
                    }
                />

                {/* Client Cards Grid */}
                <div>
                    <h2 className="mb-3 text-sm font-medium text-foreground">
                        Client Medication Status
                    </h2>
                    <div className="grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-3">
                        {clients.map((c) => (
                            <Card
                                key={c.id}
                                className={`transition-shadow hover:shadow-md ${c.has_critical_alerts || (c.discrepancy_count ?? 0) > 0 ? 'border-status-critical/30' : c.has_alerts ? 'border-status-warning/30' : ''}`}
                            >
                                <CardHeader className="pb-2">
                                    <div className="flex items-center justify-between">
                                        <CardTitle className="text-base">
                                            <Link
                                                className="hover:text-status-info hover:underline"
                                                href={`/clients/${c.id}/mar`}
                                            >
                                                {c.name}
                                            </Link>
                                        </CardTitle>
                                        {/* Alert indicators */}
                                        <div className="flex items-center gap-1">
                                            {(c.discrepancy_count ?? 0) > 0 && (
                                                <div
                                                    className="flex items-center gap-1 rounded bg-status-critical-bg px-2 py-0.5 text-xs font-medium text-status-critical"
                                                    title="Controlled drug discrepancy"
                                                >
                                                    <XCircle className="h-3 w-3" />
                                                    {c.discrepancy_count ?? 0}
                                                </div>
                                            )}
                                            {c.has_critical_alerts && (
                                                <div
                                                    className="flex items-center gap-1 rounded bg-status-critical-bg px-2 py-0.5 text-xs font-medium text-status-critical"
                                                    title="Critical alert"
                                                >
                                                    <AlertCircle className="h-3 w-3" />
                                                </div>
                                            )}
                                            {c.has_alerts &&
                                                !c.has_critical_alerts && (
                                                    <div
                                                        className="flex items-center gap-1 rounded bg-status-warning-bg px-2 py-0.5 text-xs font-medium text-status-warning"
                                                        title="Active alert"
                                                    >
                                                        <AlertTriangle className="h-3 w-3" />
                                                    </div>
                                                )}
                                        </div>
                                    </div>
                                </CardHeader>
                                <CardContent className="space-y-3">
                                    <div className="text-xs text-muted-foreground">
                                        Status: {c.status ?? '—'}
                                    </div>
                                    <div className="flex flex-wrap items-center gap-2">
                                        {pill(
                                            `Due: ${c.counts.due}`,
                                            c.counts.due > 0 ? 'warn' : 'ok',
                                        )}
                                        {pill(
                                            `Late: ${c.counts.late}`,
                                            c.counts.late > 0 ? 'bad' : 'ok',
                                        )}
                                        {pill(
                                            `Missed: ${c.counts.missed}`,
                                            c.counts.missed > 0 ? 'bad' : 'ok',
                                        )}
                                    </div>
                                    <div className="flex flex-wrap gap-2 pt-1">
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            asChild
                                        >
                                            <Link href={`/clients/${c.id}/mar`}>
                                                <ClipboardList className="mr-1 h-3 w-3" />
                                                MAR
                                            </Link>
                                        </Button>
                                        <Button
                                            size="sm"
                                            variant="ghost"
                                            asChild
                                        >
                                            <Link
                                                href={`/clients/${c.id}/medical`}
                                            >
                                                <Pill className="mr-1 h-3 w-3" />
                                                Orders
                                            </Link>
                                        </Button>
                                        <Button
                                            size="sm"
                                            variant="ghost"
                                            asChild
                                        >
                                            <Link
                                                href={`/emar/mar?client_id=${c.id}`}
                                            >
                                                <LayoutDashboard className="mr-1 h-3 w-3" />
                                                Enhanced
                                            </Link>
                                        </Button>
                                    </div>
                                </CardContent>
                            </Card>
                        ))}
                        {!clients.length && (
                            <div className="col-span-full text-center text-sm text-muted-foreground">
                                No clients available.
                            </div>
                        )}
                    </div>
                </div>

                {/* Information Cards */}
                <div className="grid grid-cols-1 gap-4 md:grid-cols-3">
                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2 text-sm">
                                <LayoutDashboard className="h-4 w-4" />
                                Medication Dashboard
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <p className="mb-3 text-xs text-muted-foreground">
                                Real-time overview of medication status, alerts,
                                and compliance metrics.
                            </p>
                            <Button
                                size="sm"
                                variant="outline"
                                className="w-full"
                                asChild
                            >
                                <Link href="/emar">Open Dashboard</Link>
                            </Button>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2 text-sm">
                                <BarChart3 className="h-4 w-4" />
                                Reports
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <p className="mb-3 text-xs text-muted-foreground">
                                Generate MAR exports, PRN usage reports, and
                                compliance reports.
                            </p>
                            <Button
                                size="sm"
                                variant="outline"
                                className="w-full"
                                asChild
                            >
                                <Link href="/reports/medications">
                                    View Reports
                                </Link>
                            </Button>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2 text-sm">
                                <ShieldAlert className="h-4 w-4" />
                                Safety & Compliance
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <p className="mb-3 text-xs text-muted-foreground">
                                Audit logs, controlled drug register, and
                                medication safety checks.
                            </p>
                            <Button
                                size="sm"
                                variant="outline"
                                className="w-full"
                                asChild
                            >
                                <Link href="/medications/audit">
                                    View Audit
                                </Link>
                            </Button>
                        </CardContent>
                    </Card>
                </div>
            </div>
        </AppLayout>
    );
}
