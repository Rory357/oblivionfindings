import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, usePage } from '@inertiajs/react';
import {
    ShieldAlert,
    Pill,
    CalendarCheck,
    Shield,
    AlertCircle,
    ClipboardCheck,
    FileText,
    BarChart3,
    Download,
    Eye,
} from 'lucide-react';

/* ------------------------------------------------------------------ */
/*  Stat Card                                                          */
/* ------------------------------------------------------------------ */

const STAT_COLORS = {
    blue: { bg: 'bg-blue-50 dark:bg-blue-500/10', icon: 'text-blue-600 dark:text-blue-400', ring: 'ring-blue-100 dark:ring-blue-500/20' },
    emerald: { bg: 'bg-emerald-50 dark:bg-emerald-500/10', icon: 'text-emerald-600 dark:text-emerald-400', ring: 'ring-emerald-100 dark:ring-emerald-500/20' },
    amber: { bg: 'bg-amber-50 dark:bg-amber-500/10', icon: 'text-amber-600 dark:text-amber-400', ring: 'ring-amber-100 dark:ring-amber-500/20' },
    red: { bg: 'bg-red-50 dark:bg-red-500/10', icon: 'text-red-600 dark:text-red-400', ring: 'ring-red-100 dark:ring-red-500/20' },
    purple: { bg: 'bg-purple-50 dark:bg-purple-500/10', icon: 'text-purple-600 dark:text-purple-400', ring: 'ring-purple-100 dark:ring-purple-500/20' },
    slate: { bg: 'bg-slate-50 dark:bg-slate-500/10', icon: 'text-slate-600 dark:text-slate-400', ring: 'ring-slate-100 dark:ring-slate-500/20' },
};

function StatCard({ label, value, subtitle, icon: Icon, color }: { label: string; value: number | string; subtitle?: string; icon: React.ElementType; color: keyof typeof STAT_COLORS }) {
    const c = STAT_COLORS[color];
    return (
        <div className={`relative flex items-center gap-4 rounded-xl p-4 ring-1 ${c.bg} ${c.ring} transition-shadow hover:shadow-md`}>
            <div className={`flex h-11 w-11 shrink-0 items-center justify-center rounded-lg ${c.bg} ${c.icon}`}>
                <Icon className="h-5 w-5" />
            </div>
            <div className="min-w-0">
                <p className="text-2xl font-bold tracking-tight">{value}</p>
                <p className="truncate text-xs font-medium text-muted-foreground">{label}</p>
                {subtitle && <p className="truncate text-[10px] text-muted-foreground/70">{subtitle}</p>}
            </div>
        </div>
    );
}

/* ------------------------------------------------------------------ */
/*  Page                                                               */
/* ------------------------------------------------------------------ */

export default function ReportsIndex() {
    const { kpis, modules = [], combined_reports = [] } = usePage().props as any;

    return (
        <AppLayout breadcrumbs={[{ title: 'Reports', href: '/reports' }]}>
            <Head title="Reports" />

            <div className="flex flex-col gap-6 p-6">
                {/* Header */}
                <div>
                    <h1 className="text-2xl font-bold tracking-tight">Reports</h1>
                    <p className="text-sm text-muted-foreground">
                        Overview of key metrics and exportable reports across all modules
                    </p>
                </div>

                {/* KPI Cards */}
                {kpis && (
                    <div className="grid grid-cols-2 gap-4 lg:grid-cols-4">
                        <StatCard label="Open Incidents" value={kpis.openIncidents} subtitle="Submitted / reviewed" icon={ShieldAlert} color="red" />
                        <StatCard label="Medication Exceptions" value={kpis.missedMeds7d} subtitle="Last 7 days" icon={Pill} color="amber" />
                        <StatCard label="Completed Shifts" value={kpis.completedShifts7d} subtitle="Last 7 days" icon={CalendarCheck} color="emerald" />
                        <StatCard label="Open Safeguarding" value={kpis.openSafeguarding} subtitle="Not closed" icon={Shield} color="purple" />
                    </div>
                )}

                {kpis && (
                    <div className="grid grid-cols-2 gap-4 lg:grid-cols-3">
                        <StatCard label="Open Discrepancies" value={kpis.openDiscrepancies} subtitle="Controlled drugs" icon={AlertCircle} color="slate" />
                        <StatCard label="Overdue Asset Checks" value={kpis.overdueAssetChecks} subtitle="Inspection + maintenance" icon={ClipboardCheck} color="amber" />
                        <StatCard label="Audit Events" value={kpis.auditEvents7d} subtitle="Last 7 days" icon={FileText} color="blue" />
                    </div>
                )}

                {/* Combined Reports */}
                <Card>
                    <CardHeader>
                        <CardTitle className="flex items-center gap-2 text-base">
                            <BarChart3 className="h-4 w-4" />
                            Combined Reports
                        </CardTitle>
                    </CardHeader>
                    <CardContent className="grid grid-cols-1 gap-4 md:grid-cols-3">
                        {combined_reports.map((report: any) => (
                            <div key={report.key} className="rounded-lg border p-4">
                                <div className="text-sm font-semibold">{report.label}</div>
                                <div className="mt-1 text-xs text-muted-foreground">
                                    {report.description}
                                </div>
                                <div className="mt-2 flex flex-wrap gap-1">
                                    {report.modules?.map((module: string) => (
                                        <Badge key={module} variant="outline" className="text-[10px]">
                                            {module}
                                        </Badge>
                                    ))}
                                </div>
                                <div className="mt-3 space-y-1">
                                    {(report.preview ?? []).map((item: any) => (
                                        <div key={item.label} className="flex items-center justify-between text-xs">
                                            <span className="text-muted-foreground">{item.label}</span>
                                            <span className="font-medium">{item.value}</span>
                                        </div>
                                    ))}
                                </div>
                                <div className="mt-3 flex gap-2">
                                    <Link href={report.route}>
                                        <Button variant="outline" size="sm" className="gap-1.5 text-xs">
                                            <Eye className="h-3.5 w-3.5" />
                                            View
                                        </Button>
                                    </Link>
                                    <a href={report.export_route}>
                                        <Button variant="outline" size="sm" className="gap-1.5 text-xs">
                                            <Download className="h-3.5 w-3.5" />
                                            Export CSV
                                        </Button>
                                    </a>
                                </div>
                            </div>
                        ))}
                        {combined_reports.length === 0 && (
                            <div className="rounded-lg border border-dashed p-4 text-sm text-muted-foreground">
                                No combined reports are configured.
                            </div>
                        )}
                    </CardContent>
                </Card>

                {/* Module Reports */}
                <Card>
                    <CardHeader>
                        <CardTitle className="flex items-center gap-2 text-base">
                            <FileText className="h-4 w-4" />
                            Module Reports
                        </CardTitle>
                    </CardHeader>
                    <CardContent className="grid grid-cols-1 gap-4 md:grid-cols-2">
                        {modules.map((module: any) => (
                            <div key={module.key} className="rounded-lg border p-4">
                                <div className="flex items-center justify-between gap-3">
                                    <div className="text-sm font-semibold">{module.label}</div>
                                    <span className="text-xs text-muted-foreground">{module.summary?.total_records ?? 0} rows</span>
                                </div>
                                <div className="mt-1 text-xs text-muted-foreground">{module.description}</div>
                                <div className="mt-2 text-xs text-muted-foreground">
                                    Last activity: {module.summary?.last_activity ?? 'N/A'}
                                </div>
                                <div className="mt-2 flex flex-wrap gap-1">
                                    {module.summary?.has_search_filter && <Badge variant="outline" className="text-[10px]">search</Badge>}
                                    {module.summary?.has_date_filter && <Badge variant="outline" className="text-[10px]">date</Badge>}
                                    {module.summary?.has_status_filter && <Badge variant="outline" className="text-[10px]">status</Badge>}
                                </div>
                                <div className="mt-3 flex gap-2">
                                    <Link href={module.route}>
                                        <Button variant="outline" size="sm" className="gap-1.5 text-xs">
                                            <Eye className="h-3.5 w-3.5" />
                                            View
                                        </Button>
                                    </Link>
                                    <a href={`${module.route}/export`}>
                                        <Button variant="outline" size="sm" className="gap-1.5 text-xs">
                                            <Download className="h-3.5 w-3.5" />
                                            Export CSV
                                        </Button>
                                    </a>
                                </div>
                            </div>
                        ))}
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
