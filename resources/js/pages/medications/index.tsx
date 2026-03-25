import AppLayout from '@/layouts/app-layout';
import { Head, Link, usePage } from '@inertiajs/react';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { 
    AlertTriangle, 
    BarChart3, 
    ClipboardList, 
    FileText, 
    LayoutDashboard, 
    Pill, 
    ShieldAlert,
    TrendingUp,
    AlertCircle,
    XCircle
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
            ? 'bg-emerald-100 text-emerald-800 border-emerald-200'
            : kind === 'warn'
              ? 'bg-amber-100 text-amber-800 border-amber-200'
              : 'bg-rose-100 text-rose-800 border-rose-200';
    return (
        <Badge variant="outline" className={className}>
            {label}
        </Badge>
    );
}

export default function MedicationsIndex({ date, clients }: Props) {
    const { auth } = usePage().props as any;
    const canViewDashboard = auth?.can?.medications?.view;
    const canViewReports = auth?.can?.medications?.reports?.export || auth?.can?.reports?.viewAny;
    const canViewAudit = auth?.can?.medications?.audit?.view;

    return (
        <AppLayout breadcrumbs={[{ title: 'Medications', href: '/medications' }]}>
            <Head title="Medications" />

            <div className="space-y-6">
                {/* Header with Quick Actions */}
                <div className="flex flex-col gap-4 md:flex-row md:items-start md:justify-between">
                    <div>
                        <h1 className="text-xl font-semibold">Medications</h1>
                        <p className="text-sm text-slate-500">Centralised "run-the-day" view • {date}</p>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        {canViewDashboard && (
                            <Button variant="outline" asChild>
                                <Link href="/medications/dashboard">
                                    <LayoutDashboard className="mr-2 h-4 w-4" />
                                    Dashboard
                                </Link>
                            </Button>
                        )}
                        {canViewReports && (
                            <Button variant="outline" asChild>
                                <Link href="/reports/medications">
                                    <BarChart3 className="mr-2 h-4 w-4" />
                                    Reports
                                </Link>
                            </Button>
                        )}
                        {canViewAudit && (
                            <Button variant="outline" asChild>
                                <Link href="/medications/audit">
                                    <FileText className="mr-2 h-4 w-4" />
                                    Audit
                                </Link>
                            </Button>
                        )}
                    </div>
                </div>

                {/* Quick Stats Summary */}
                <div className="grid grid-cols-2 gap-3 md:grid-cols-4">
                    <Card className="border-blue-200 bg-blue-50">
                        <CardContent className="py-4">
                            <div className="flex items-center gap-2">
                                <ClipboardList className="h-5 w-5 text-blue-600" />
                                <div>
                                    <div className="text-xs text-blue-600">Total Clients</div>
                                    <div className="text-lg font-bold text-blue-800">{clients.length}</div>
                                </div>
                            </div>
                        </CardContent>
                    </Card>
                    <Card className="border-amber-200 bg-amber-50">
                        <CardContent className="py-4">
                            <div className="flex items-center gap-2">
                                <Pill className="h-5 w-5 text-amber-600" />
                                <div>
                                    <div className="text-xs text-amber-600">Due Today</div>
                                    <div className="text-lg font-bold text-amber-800">
                                        {clients.reduce((sum, c) => sum + c.counts.due, 0)}
                                    </div>
                                </div>
                            </div>
                        </CardContent>
                    </Card>
                    <Card className="border-orange-200 bg-orange-50">
                        <CardContent className="py-4">
                            <div className="flex items-center gap-2">
                                <TrendingUp className="h-5 w-5 text-orange-600" />
                                <div>
                                    <div className="text-xs text-orange-600">Late</div>
                                    <div className="text-lg font-bold text-orange-800">
                                        {clients.reduce((sum, c) => sum + c.counts.late, 0)}
                                    </div>
                                </div>
                            </div>
                        </CardContent>
                    </Card>
                    <Card className="border-red-200 bg-red-50">
                        <CardContent className="py-4">
                            <div className="flex items-center gap-2">
                                <AlertTriangle className="h-5 w-5 text-red-600" />
                                <div>
                                    <div className="text-xs text-red-600">Missed</div>
                                    <div className="text-lg font-bold text-red-800">
                                        {clients.reduce((sum, c) => sum + c.counts.missed, 0)}
                                    </div>
                                </div>
                            </div>
                        </CardContent>
                    </Card>
                </div>

                {/* Client Cards Grid */}
                <div>
                    <h2 className="mb-3 text-sm font-medium text-slate-700">Client Medication Status</h2>
                    <div className="grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-3">
                        {clients.map((c) => (
                            <Card key={c.id} className={`transition-shadow hover:shadow-md ${c.has_critical_alerts || c.discrepancy_count > 0 ? 'border-red-300' : c.has_alerts ? 'border-amber-300' : ''}`}>
                                <CardHeader className="pb-2">
                                    <div className="flex items-center justify-between">
                                        <CardTitle className="text-base">
                                            <Link className="hover:text-blue-600 hover:underline" href={`/clients/${c.id}/mar`}>
                                                {c.name}
                                            </Link>
                                        </CardTitle>
                                        {/* Alert indicators */}
                                        <div className="flex items-center gap-1">
                                            {c.discrepancy_count > 0 && (
                                                <div className="flex items-center gap-1 rounded bg-red-100 px-2 py-0.5 text-xs font-medium text-red-800" title="Controlled drug discrepancy">
                                                    <XCircle className="h-3 w-3" />
                                                    {c.discrepancy_count}
                                                </div>
                                            )}
                                            {c.has_critical_alerts && (
                                                <div className="flex items-center gap-1 rounded bg-red-100 px-2 py-0.5 text-xs font-medium text-red-800" title="Critical alert">
                                                    <AlertCircle className="h-3 w-3" />
                                                </div>
                                            )}
                                            {c.has_alerts && !c.has_critical_alerts && (
                                                <div className="flex items-center gap-1 rounded bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-800" title="Active alert">
                                                    <AlertTriangle className="h-3 w-3" />
                                                </div>
                                            )}
                                        </div>
                                    </div>
                                </CardHeader>
                                <CardContent className="space-y-3">
                                    <div className="text-xs text-slate-500">Status: {c.status ?? '—'}</div>
                                    <div className="flex flex-wrap items-center gap-2">
                                        {pill(`Due: ${c.counts.due}`, c.counts.due > 0 ? 'warn' : 'ok')}
                                        {pill(`Late: ${c.counts.late}`, c.counts.late > 0 ? 'bad' : 'ok')}
                                        {pill(`Missed: ${c.counts.missed}`, c.counts.missed > 0 ? 'bad' : 'ok')}
                                    </div>
                                    <div className="flex flex-wrap gap-2 pt-1">
                                        <Button size="sm" variant="outline" asChild>
                                            <Link href={`/clients/${c.id}/mar`}>
                                                <ClipboardList className="mr-1 h-3 w-3" />
                                                MAR
                                            </Link>
                                        </Button>
                                        <Button size="sm" variant="ghost" asChild>
                                            <Link href={`/clients/${c.id}/medical`}>
                                                <Pill className="mr-1 h-3 w-3" />
                                                Orders
                                            </Link>
                                        </Button>
                                        <Button size="sm" variant="ghost" asChild>
                                            <Link href={`/medications/enhanced-mar/${c.id}`}>
                                                <LayoutDashboard className="mr-1 h-3 w-3" />
                                                Enhanced
                                            </Link>
                                        </Button>
                                    </div>
                                </CardContent>
                            </Card>
                        ))}
                        {!clients.length && (
                            <div className="col-span-full text-center text-sm text-slate-500">
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
                            <p className="mb-3 text-xs text-slate-600">
                                Real-time overview of medication status, alerts, and compliance metrics.
                            </p>
                            <Button size="sm" variant="outline" className="w-full" asChild>
                                <Link href="/medications/dashboard">
                                    Open Dashboard
                                </Link>
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
                            <p className="mb-3 text-xs text-slate-600">
                                Generate MAR exports, PRN usage reports, and compliance reports.
                            </p>
                            <Button size="sm" variant="outline" className="w-full" asChild>
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
                            <p className="mb-3 text-xs text-slate-600">
                                Audit logs, controlled drug register, and medication safety checks.
                            </p>
                            <Button size="sm" variant="outline" className="w-full" asChild>
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
