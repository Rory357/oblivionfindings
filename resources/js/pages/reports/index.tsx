import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import FleetHero from '@/components/fleet-hero';
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
    blue: { bg: 'bg-status-info-bg', icon: 'text-status-info dark:text-status-info', ring: 'ring-status-info dark:ring-status-info/20' },
    emerald: { bg: 'bg-status-success-bg', icon: 'text-status-success dark:text-status-success', ring: 'ring-status-success dark:ring-status-success/20' },
    amber: { bg: 'bg-status-warning-bg', icon: 'text-status-warning dark:text-status-warning', ring: 'ring-status-warning dark:ring-status-warning/20' },
    red: { bg: 'bg-status-critical-bg', icon: 'text-status-critical dark:text-status-critical', ring: 'ring-status-critical dark:ring-status-critical/20' },
    purple: { bg: 'bg-primary/10 dark:bg-primary/10', icon: 'text-primary dark:text-primary', ring: 'ring-ring dark:ring-ring/20' },
    slate: { bg: 'bg-muted dark:bg-muted', icon: 'text-muted-foreground dark:text-muted-foreground', ring: 'ring-border dark:ring-border' },
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
                {/* Hero Header */}
                <FleetHero
                    title="Reports"
                    description="Overview of key metrics and exportable reports across all modules"
                    icon={<BarChart3 className="h-7 w-7 text-white" />}
                    stats={kpis ? [
                        { label: 'Incidents', value: kpis.openIncidents },
                        { label: 'Med Exceptions', value: kpis.missedMeds7d },
                        { label: 'Shifts (7d)', value: kpis.completedShifts7d },
                        { label: 'Safeguarding', value: kpis.openSafeguarding },
                    ] : undefined}
                />

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
