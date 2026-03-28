import AppLayout from '@/layouts/app-layout';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { formatDate } from '@/lib/date-format';
import { Head, Link } from '@inertiajs/react';
import {
    Shield,
    AlertTriangle,
    Activity,
    Car,
    Clock,
    Flame,
    Heart,
    CalendarCheck,
    Bell,
    Users,
    FileWarning,
    Eye,
    Radio,
    Truck,
    ArrowRight,
    TrendingUp,
    ChevronRight,
    ShieldAlert,
} from 'lucide-react';
import {
    BarChart,
    Bar,
    XAxis,
    YAxis,
    CartesianGrid,
    Tooltip,
    Legend,
    ResponsiveContainer,
    PieChart,
    Pie,
    Cell,
} from 'recharts';

type Props = {
    kpis: Record<string, number>;
    incident_trends: Array<{ month: string; count: number; types: Record<string, number> }>;
    severity_breakdown: Record<string, number>;
    hazard_summary: Record<string, number>;
    site_drill_compliance: Array<{
        id: number;
        name: string;
        last_drill_date: string | null;
        days_since: number | null;
        status: 'compliant' | 'due_soon' | 'overdue';
    }>;
    recent_incidents: Array<{
        id: number;
        type: string;
        severity: string;
        status: string;
        occurred_at: string;
    }>;
    recent_fleet_incidents?: Array<{
        id: number;
        incident_type: string;
        severity: string;
        status: string;
        occurred_at: string;
        location: string | null;
        asset: { id: number; name: string } | null;
    }>;
    recent_hazards: Array<{
        id: number;
        type: string;
        risk_rating: string;
        status: string;
        site_name: string;
    }>;
};

/* ------------------------------------------------------------------ */
/*  KPI card configuration                                            */
/* ------------------------------------------------------------------ */

const KPI_CONFIG: Array<{
    key: string;
    label: string;
    icon: React.ElementType;
    href: string;
    color: (v: number) => string;
    bgColor: (v: number) => string;
    iconBg: (v: number) => string;
}> = [
    {
        key: 'incidents_30d',
        label: 'Incidents (30 days)',
        icon: AlertTriangle,
        href: '/incidents',
        color: (v) => (v > 5 ? 'text-red-700' : v > 0 ? 'text-amber-700' : 'text-slate-700'),
        bgColor: (v) => (v > 5 ? 'border-red-200 bg-red-50/60' : v > 0 ? 'border-amber-200 bg-amber-50/60' : 'border-slate-200 bg-white'),
        iconBg: (v) => (v > 5 ? 'bg-red-100 text-red-600' : v > 0 ? 'bg-amber-100 text-amber-600' : 'bg-slate-100 text-slate-600'),
    },
    {
        key: 'near_misses_30d',
        label: 'Near Misses (30 days)',
        icon: Eye,
        href: '/incidents?type=near_miss',
        color: () => 'text-slate-700',
        bgColor: () => 'border-slate-200 bg-white',
        iconBg: () => 'bg-blue-100 text-blue-600',
    },
    {
        key: 'open_hazards',
        label: 'Open Hazards',
        icon: Flame,
        href: '/compliance/hazards',
        color: (v) => (v > 0 ? 'text-orange-700' : 'text-green-700'),
        bgColor: (v) => (v > 0 ? 'border-orange-200 bg-orange-50/60' : 'border-green-200 bg-green-50/60'),
        iconBg: (v) => (v > 0 ? 'bg-orange-100 text-orange-600' : 'bg-green-100 text-green-600'),
    },
    {
        key: 'overdue_actions',
        label: 'Overdue Actions',
        icon: Clock,
        href: '/compliance/hazards?status=open',
        color: (v) => (v > 0 ? 'text-red-700' : 'text-green-700'),
        bgColor: (v) => (v > 0 ? 'border-red-200 bg-red-50/60' : 'border-green-200 bg-green-50/60'),
        iconBg: (v) => (v > 0 ? 'bg-red-100 text-red-600' : 'bg-green-100 text-green-600'),
    },
    {
        key: 'workplace_injuries_ytd',
        label: 'Workplace Injuries (YTD)',
        icon: Heart,
        href: '/health-safety/injuries',
        color: (v) => (v > 0 ? 'text-red-700' : 'text-slate-700'),
        bgColor: (v) => (v > 0 ? 'border-red-200 bg-red-50/60' : 'border-slate-200 bg-white'),
        iconBg: (v) => (v > 0 ? 'bg-red-100 text-red-600' : 'bg-slate-100 text-slate-600'),
    },
    {
        key: 'lost_time_days_ytd',
        label: 'Lost Time Days (YTD)',
        icon: Activity,
        href: '/health-safety/injuries',
        color: (v) => (v > 0 ? 'text-amber-700' : 'text-slate-700'),
        bgColor: (v) => (v > 0 ? 'border-amber-200 bg-amber-50/60' : 'border-slate-200 bg-white'),
        iconBg: (v) => (v > 0 ? 'bg-amber-100 text-amber-600' : 'bg-slate-100 text-slate-600'),
    },
    {
        key: 'days_since_notifiable',
        label: 'Days Since Notifiable',
        icon: FileWarning,
        href: '/governance/compliance',
        color: (v) => (v > 30 ? 'text-green-700' : 'text-red-700'),
        bgColor: (v) => (v > 30 ? 'border-green-200 bg-green-50/60' : 'border-red-200 bg-red-50/60'),
        iconBg: (v) => (v > 30 ? 'bg-green-100 text-green-600' : 'bg-red-100 text-red-600'),
    },
    {
        key: 'drill_compliance_pct',
        label: 'Drill Compliance',
        icon: CalendarCheck,
        href: '/health-safety/drills',
        color: (v) => (v >= 90 ? 'text-green-700' : v >= 70 ? 'text-amber-700' : 'text-red-700'),
        bgColor: (v) => (v >= 90 ? 'border-green-200 bg-green-50/60' : v >= 70 ? 'border-amber-200 bg-amber-50/60' : 'border-red-200 bg-red-50/60'),
        iconBg: (v) => (v >= 90 ? 'bg-green-100 text-green-600' : v >= 70 ? 'bg-amber-100 text-amber-600' : 'bg-red-100 text-red-600'),
    },
    {
        key: 'active_alerts',
        label: 'Active Alerts',
        icon: Bell,
        href: '/health-safety/lone-workers',
        color: (v) => (v > 0 ? 'text-amber-700' : 'text-slate-700'),
        bgColor: (v) => (v > 0 ? 'border-amber-200 bg-amber-50/60' : 'border-slate-200 bg-white'),
        iconBg: (v) => (v > 0 ? 'bg-amber-100 text-amber-600' : 'bg-slate-100 text-slate-600'),
    },
    {
        key: 'open_safeguarding',
        label: 'Open Safeguarding',
        icon: ShieldAlert,
        href: '/safeguarding',
        color: (v) => (v > 0 ? 'text-purple-700' : 'text-slate-700'),
        bgColor: (v) => (v > 0 ? 'border-purple-200 bg-purple-50/60' : 'border-slate-200 bg-white'),
        iconBg: (v) => (v > 0 ? 'bg-purple-100 text-purple-600' : 'bg-slate-100 text-slate-600'),
    },
    {
        key: 'fleet_incidents_30d',
        label: 'Fleet Incidents (30 days)',
        icon: Truck,
        href: '/fleet-assets/incidents',
        color: (v) => (v > 0 ? 'text-amber-700' : 'text-slate-700'),
        bgColor: (v) => (v > 0 ? 'border-amber-200 bg-amber-50/60' : 'border-slate-200 bg-white'),
        iconBg: (v) => (v > 0 ? 'bg-amber-100 text-amber-600' : 'bg-slate-100 text-slate-600'),
    },
    {
        key: 'fleet_unresolved',
        label: 'Fleet Unresolved',
        icon: Car,
        href: '/fleet-assets/incidents',
        color: (v) => (v > 0 ? 'text-amber-700' : 'text-green-700'),
        bgColor: (v) => (v > 0 ? 'border-amber-200 bg-amber-50/60' : 'border-green-200 bg-green-50/60'),
        iconBg: (v) => (v > 0 ? 'bg-amber-100 text-amber-600' : 'bg-green-100 text-green-600'),
    },
    {
        key: 'staff_compliance_pct',
        label: 'Staff Compliance',
        icon: Users,
        href: '/hr/compliance',
        color: (v) => (v >= 90 ? 'text-green-700' : v >= 70 ? 'text-amber-700' : 'text-red-700'),
        bgColor: (v) => (v >= 90 ? 'border-green-200 bg-green-50/60' : v >= 70 ? 'border-amber-200 bg-amber-50/60' : 'border-red-200 bg-red-50/60'),
        iconBg: (v) => (v >= 90 ? 'bg-green-100 text-green-600' : v >= 70 ? 'bg-amber-100 text-amber-600' : 'bg-red-100 text-red-600'),
    },
];

/* ------------------------------------------------------------------ */
/*  Chart colour constants                                            */
/* ------------------------------------------------------------------ */

const TYPE_COLORS: Record<string, string> = {
    injury: '#ef4444',
    behaviour: '#f59e0b',
    medication: '#3b82f6',
    safeguarding: '#8b5cf6',
    near_miss: '#6b7280',
    other: '#94a3b8',
};

const SEVERITY_COLORS: Record<string, string> = {
    critical: '#ef4444',
    high: '#f97316',
    medium: '#f59e0b',
    low: '#3b82f6',
};

const HAZARD_COLORS: Record<string, string> = {
    extreme: '#ef4444',
    high: '#f97316',
    medium: '#eab308',
    low: '#22c55e',
};

/* ------------------------------------------------------------------ */
/*  Badge helpers                                                     */
/* ------------------------------------------------------------------ */

function severityColor(s: string) {
    switch (s) {
        case 'critical': return 'bg-red-100 text-red-800 border-red-200';
        case 'high': return 'bg-orange-100 text-orange-800 border-orange-200';
        case 'medium': return 'bg-yellow-100 text-yellow-800 border-yellow-200';
        case 'low': return 'bg-blue-100 text-blue-800 border-blue-200';
        default: return 'bg-slate-100 text-slate-800 border-slate-200';
    }
}

function statusColor(s: string) {
    switch (s) {
        case 'closed': case 'resolved': return 'bg-green-100 text-green-800 border-green-200';
        case 'open': case 'reported': return 'bg-blue-100 text-blue-800 border-blue-200';
        case 'in_progress': return 'bg-purple-100 text-purple-800 border-purple-200';
        default: return 'bg-slate-100 text-slate-800 border-slate-200';
    }
}

function riskColor(r: string) {
    switch (r) {
        case 'extreme': return 'bg-red-100 text-red-800 border-red-200';
        case 'high': return 'bg-orange-100 text-orange-800 border-orange-200';
        case 'medium': return 'bg-yellow-100 text-yellow-800 border-yellow-200';
        case 'low': return 'bg-green-100 text-green-800 border-green-200';
        default: return 'bg-slate-100 text-slate-800 border-slate-200';
    }
}

function drillStatusBadge(status: string) {
    switch (status) {
        case 'compliant': return 'bg-green-100 text-green-800 border-green-200';
        case 'due_soon': return 'bg-amber-100 text-amber-800 border-amber-200';
        case 'overdue': return 'bg-red-100 text-red-800 border-red-200';
        default: return 'bg-slate-100 text-slate-800 border-slate-200';
    }
}

/* ------------------------------------------------------------------ */
/*  Quick actions                                                     */
/* ------------------------------------------------------------------ */

const QUICK_ACTIONS = [
    { label: 'Report Incident', icon: AlertTriangle, href: '/incidents/create', variant: 'destructive' as const },
    { label: 'Report Near-Miss', icon: Eye, href: '/incidents/create', variant: 'outline' as const },
    { label: 'Report Hazard', icon: Flame, href: '/compliance/hazards', variant: 'outline' as const },
    { label: 'Record First Aid', icon: Heart, href: '/health-safety/first-aid', variant: 'outline' as const },
    { label: 'Start Lone Worker', icon: Radio, href: '/health-safety/lone-workers', variant: 'outline' as const },
    { label: 'Report Safeguarding', icon: ShieldAlert, href: '/safeguarding/create', variant: 'outline' as const },
    { label: 'Log Fleet Incident', icon: Truck, href: '/fleet-assets/incidents/create', variant: 'outline' as const },
];

/* ================================================================== */
/*  COMPONENT                                                         */
/* ================================================================== */

export default function HealthSafetyDashboard({
    kpis,
    incident_trends,
    severity_breakdown,
    hazard_summary,
    site_drill_compliance,
    recent_incidents,
    recent_hazards,
    recent_fleet_incidents = [],
}: Props) {

    /* -------------------------------------------------------------- */
    /*  Derive chart data                                             */
    /* -------------------------------------------------------------- */

    // Collect all incident type keys across the trends
    const allTypes = Array.from(
        new Set(incident_trends.flatMap((t) => Object.keys(t.types ?? {})))
    );

    // Stacked bar chart data: one row per month, one key per type
    const trendChartData = incident_trends.map((t) => {
        const row: Record<string, string | number> = { month: t.month };
        allTypes.forEach((type) => {
            row[type] = (t.types ?? {})[type] ?? 0;
        });
        return row;
    });

    // Severity donut data
    const severityData = ['critical', 'high', 'medium', 'low']
        .map((key) => ({ name: key.charAt(0).toUpperCase() + key.slice(1), value: severity_breakdown[key] ?? 0, key }))
        .filter((d) => d.value > 0);

    const totalSeverity = severityData.reduce((sum, d) => sum + d.value, 0);

    // Hazard horizontal bar data
    const hazardChartData = [
        { level: 'Extreme', count: hazard_summary['extreme'] ?? 0, key: 'extreme' },
        { level: 'High', count: hazard_summary['high'] ?? 0, key: 'high' },
        { level: 'Medium', count: hazard_summary['medium'] ?? 0, key: 'medium' },
        { level: 'Low', count: hazard_summary['low'] ?? 0, key: 'low' },
    ];

    /* -------------------------------------------------------------- */
    /*  Render                                                        */
    /* -------------------------------------------------------------- */

    return (
        <AppLayout breadcrumbs={[{ title: 'Health & Safety', href: '/health-safety' }, { title: 'Dashboard', href: '/health-safety/dashboard' }]}>
            <Head title="Health & Safety Dashboard" />

            <div className="mx-auto max-w-[1600px] space-y-8 p-1">

                {/* ------------------------------------------------ */}
                {/*  Page Header                                     */}
                {/* ------------------------------------------------ */}
                <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                    <div className="flex items-center gap-3">
                        <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-blue-600 text-white">
                            <Shield className="h-5 w-5" />
                        </div>
                        <div>
                            <h1 className="text-2xl font-bold tracking-tight">Health & Safety Dashboard</h1>
                            <p className="text-sm text-muted-foreground">Real-time overview of workplace safety performance</p>
                        </div>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        <Link href="/incidents/create">
                            <Button size="sm" variant="destructive" className="gap-1.5">
                                <AlertTriangle className="h-4 w-4" />
                                Report Incident
                            </Button>
                        </Link>
                        <Link href="/compliance/hazards">
                            <Button size="sm" variant="outline" className="gap-1.5">
                                <Flame className="h-4 w-4" />
                                Report Hazard
                            </Button>
                        </Link>
                    </div>
                </div>

                {/* ------------------------------------------------ */}
                {/*  KPI Grid                                        */}
                {/* ------------------------------------------------ */}
                <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    {KPI_CONFIG.map((cfg) => {
                        const value = kpis[cfg.key] ?? 0;
                        const Icon = cfg.icon;
                        const displayValue = cfg.key.endsWith('_pct') ? `${value}%` : value;

                        return (
                            <Link
                                key={cfg.key}
                                href={cfg.href}
                                className="group"
                            >
                                <Card className={`transition-all duration-150 group-hover:shadow-md group-hover:-translate-y-0.5 ${cfg.bgColor(value)}`}>
                                    <CardContent className="flex items-center gap-4 p-4">
                                        <div className={`flex h-10 w-10 shrink-0 items-center justify-center rounded-lg ${cfg.iconBg(value)}`}>
                                            <Icon className="h-5 w-5" />
                                        </div>
                                        <div className="min-w-0 flex-1">
                                            <div className={`text-2xl font-bold leading-none ${cfg.color(value)}`}>
                                                {displayValue}
                                            </div>
                                            <div className="mt-1 truncate text-xs text-muted-foreground">
                                                {cfg.label}
                                            </div>
                                        </div>
                                        <ChevronRight className="h-4 w-4 shrink-0 text-muted-foreground opacity-0 transition-opacity group-hover:opacity-100" />
                                    </CardContent>
                                </Card>
                            </Link>
                        );
                    })}
                </div>

                {/* ------------------------------------------------ */}
                {/*  Charts Row 1: Incident Trends + Severity Donut  */}
                {/* ------------------------------------------------ */}
                <div className="grid gap-4 lg:grid-cols-3">
                    {/* Incident Trends - takes 2 cols */}
                    <Card className="lg:col-span-2">
                        <CardHeader className="flex flex-row items-center justify-between pb-2">
                            <CardTitle className="flex items-center gap-2 text-base font-semibold">
                                <TrendingUp className="h-4 w-4 text-muted-foreground" />
                                Incident Trends (12 months)
                            </CardTitle>
                            <Link href="/incidents" className="text-xs text-muted-foreground hover:text-foreground">
                                View all <ArrowRight className="ml-0.5 inline h-3 w-3" />
                            </Link>
                        </CardHeader>
                        <CardContent>
                            {incident_trends.length > 0 ? (
                                <ResponsiveContainer width="100%" height={300}>
                                    <BarChart data={trendChartData} margin={{ top: 5, right: 10, left: -10, bottom: 5 }}>
                                        <CartesianGrid strokeDasharray="3 3" className="stroke-muted" />
                                        <XAxis dataKey="month" tick={{ fontSize: 11 }} className="text-muted-foreground" />
                                        <YAxis allowDecimals={false} tick={{ fontSize: 11 }} className="text-muted-foreground" />
                                        <Tooltip
                                            contentStyle={{ fontSize: 12, borderRadius: 8, border: '1px solid hsl(var(--border))' }}
                                            labelFormatter={(label) => `Month: ${label}`}
                                            formatter={(value: number, name: string) => [value, name.replace(/_/g, ' ')]}
                                        />
                                        <Legend
                                            formatter={(value: string) => <span className="text-xs capitalize">{value.replace(/_/g, ' ')}</span>}
                                        />
                                        {allTypes.map((type) => (
                                            <Bar
                                                key={type}
                                                dataKey={type}
                                                stackId="incidents"
                                                fill={TYPE_COLORS[type] ?? '#94a3b8'}
                                                radius={type === allTypes[allTypes.length - 1] ? [2, 2, 0, 0] : [0, 0, 0, 0]}
                                            />
                                        ))}
                                    </BarChart>
                                </ResponsiveContainer>
                            ) : (
                                <div className="flex h-[300px] items-center justify-center text-sm text-muted-foreground">
                                    No trend data available.
                                </div>
                            )}
                        </CardContent>
                    </Card>

                    {/* Severity Breakdown Donut */}
                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-base font-semibold">Severity Breakdown</CardTitle>
                        </CardHeader>
                        <CardContent>
                            {totalSeverity > 0 ? (
                                <div className="flex flex-col items-center">
                                    <ResponsiveContainer width="100%" height={200}>
                                        <PieChart>
                                            <Pie
                                                data={severityData}
                                                cx="50%"
                                                cy="50%"
                                                innerRadius={55}
                                                outerRadius={85}
                                                paddingAngle={3}
                                                dataKey="value"
                                                stroke="none"
                                            >
                                                {severityData.map((entry) => (
                                                    <Cell key={entry.key} fill={SEVERITY_COLORS[entry.key] ?? '#94a3b8'} />
                                                ))}
                                            </Pie>
                                            <Tooltip
                                                contentStyle={{ fontSize: 12, borderRadius: 8, border: '1px solid hsl(var(--border))' }}
                                                formatter={(value: number, name: string) => [`${value} incidents`, name]}
                                            />
                                        </PieChart>
                                    </ResponsiveContainer>
                                    {/* Legend */}
                                    <div className="mt-2 grid w-full grid-cols-2 gap-2">
                                        {severityData.map((d) => (
                                            <div key={d.key} className="flex items-center gap-2 text-xs">
                                                <span
                                                    className="inline-block h-2.5 w-2.5 rounded-full"
                                                    style={{ backgroundColor: SEVERITY_COLORS[d.key] }}
                                                />
                                                <span className="text-muted-foreground">{d.name}</span>
                                                <span className="ml-auto font-semibold">{d.value}</span>
                                            </div>
                                        ))}
                                    </div>
                                </div>
                            ) : (
                                <div className="flex h-[200px] items-center justify-center text-sm text-muted-foreground">
                                    No incident severity data.
                                </div>
                            )}
                        </CardContent>
                    </Card>
                </div>

                {/* ------------------------------------------------ */}
                {/*  Charts Row 2: Hazard Risk + Drill Compliance    */}
                {/* ------------------------------------------------ */}
                <div className="grid gap-4 lg:grid-cols-2">
                    {/* Hazard Risk Distribution - Horizontal Bar */}
                    <Card>
                        <CardHeader className="flex flex-row items-center justify-between pb-2">
                            <CardTitle className="flex items-center gap-2 text-base font-semibold">
                                <Flame className="h-4 w-4 text-muted-foreground" />
                                Hazard Risk Distribution
                            </CardTitle>
                            <Link href="/compliance/hazards" className="text-xs text-muted-foreground hover:text-foreground">
                                View all <ArrowRight className="ml-0.5 inline h-3 w-3" />
                            </Link>
                        </CardHeader>
                        <CardContent>
                            <ResponsiveContainer width="100%" height={200}>
                                <BarChart data={hazardChartData} layout="vertical" margin={{ top: 5, right: 20, left: 10, bottom: 5 }}>
                                    <CartesianGrid strokeDasharray="3 3" className="stroke-muted" horizontal={false} />
                                    <XAxis type="number" allowDecimals={false} tick={{ fontSize: 11 }} />
                                    <YAxis type="category" dataKey="level" tick={{ fontSize: 12 }} width={65} />
                                    <Tooltip
                                        contentStyle={{ fontSize: 12, borderRadius: 8, border: '1px solid hsl(var(--border))' }}
                                        formatter={(value: number) => [`${value} hazards`]}
                                    />
                                    <Bar dataKey="count" radius={[0, 4, 4, 0]}>
                                        {hazardChartData.map((entry) => (
                                            <Cell key={entry.key} fill={HAZARD_COLORS[entry.key] ?? '#94a3b8'} />
                                        ))}
                                    </Bar>
                                </BarChart>
                            </ResponsiveContainer>
                        </CardContent>
                    </Card>

                    {/* Site Drill Compliance */}
                    <Card>
                        <CardHeader className="flex flex-row items-center justify-between pb-2">
                            <CardTitle className="flex items-center gap-2 text-base font-semibold">
                                <CalendarCheck className="h-4 w-4 text-muted-foreground" />
                                Site Drill Compliance
                            </CardTitle>
                            <Link href="/health-safety/drills" className="text-xs text-muted-foreground hover:text-foreground">
                                View all <ArrowRight className="ml-0.5 inline h-3 w-3" />
                            </Link>
                        </CardHeader>
                        <CardContent>
                            <div className="overflow-x-auto">
                                <table className="w-full text-sm">
                                    <thead>
                                        <tr className="border-b text-left text-xs text-muted-foreground">
                                            <th className="pb-2 font-medium">Site</th>
                                            <th className="pb-2 font-medium">Last Drill</th>
                                            <th className="pb-2 text-right font-medium">Days</th>
                                            <th className="pb-2 text-right font-medium">Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {site_drill_compliance.slice(0, 8).map((site) => (
                                            <tr key={site.id} className="border-b last:border-0">
                                                <td className="py-2 font-medium">{site.name}</td>
                                                <td className="py-2 text-muted-foreground">
                                                    {site.last_drill_date ? formatDate(site.last_drill_date) : (
                                                        <span className="font-medium text-red-600">Never</span>
                                                    )}
                                                </td>
                                                <td className="py-2 text-right text-muted-foreground">{site.days_since ?? '-'}</td>
                                                <td className="py-2 text-right">
                                                    <Badge className={drillStatusBadge(site.status)}>
                                                        {site.status === 'due_soon' ? 'Due Soon' : site.status.charAt(0).toUpperCase() + site.status.slice(1)}
                                                    </Badge>
                                                </td>
                                            </tr>
                                        ))}
                                        {!site_drill_compliance.length && (
                                            <tr>
                                                <td colSpan={4} className="py-6 text-center text-muted-foreground">No sites found.</td>
                                            </tr>
                                        )}
                                    </tbody>
                                </table>
                            </div>
                        </CardContent>
                    </Card>
                </div>

                {/* ------------------------------------------------ */}
                {/*  Recent Activity (3-column)                      */}
                {/* ------------------------------------------------ */}
                <div className="grid gap-4 lg:grid-cols-3">
                    {/* Recent Incidents */}
                    <Card>
                        <CardHeader className="flex flex-row items-center justify-between pb-2">
                            <CardTitle className="flex items-center gap-2 text-base font-semibold">
                                <AlertTriangle className="h-4 w-4 text-muted-foreground" />
                                Recent Incidents
                            </CardTitle>
                            <Link href="/incidents" className="text-xs text-muted-foreground hover:text-foreground">
                                View all <ArrowRight className="ml-0.5 inline h-3 w-3" />
                            </Link>
                        </CardHeader>
                        <CardContent className="space-y-1.5">
                            {recent_incidents.slice(0, 6).map((inc) => (
                                <Link
                                    key={inc.id}
                                    href={`/incidents/${inc.id}`}
                                    className="group/item flex items-center justify-between rounded-lg border px-3 py-2.5 transition-colors hover:bg-muted/50"
                                >
                                    <div className="flex flex-wrap items-center gap-1.5">
                                        <Badge className={severityColor(inc.severity)}>{inc.severity}</Badge>
                                        <span className="text-sm font-medium capitalize">{inc.type.replace(/_/g, ' ')}</span>
                                    </div>
                                    <div className="flex items-center gap-1.5">
                                        <Badge variant="outline" className={statusColor(inc.status)}>{inc.status.replace(/_/g, ' ')}</Badge>
                                        <span className="hidden text-xs text-muted-foreground sm:inline">{formatDate(inc.occurred_at)}</span>
                                        <ChevronRight className="h-3.5 w-3.5 text-muted-foreground opacity-0 transition-opacity group-hover/item:opacity-100" />
                                    </div>
                                </Link>
                            ))}
                            {!recent_incidents.length && (
                                <div className="py-6 text-center text-sm text-muted-foreground">No recent incidents.</div>
                            )}
                        </CardContent>
                    </Card>

                    {/* Recent Hazards */}
                    <Card>
                        <CardHeader className="flex flex-row items-center justify-between pb-2">
                            <CardTitle className="flex items-center gap-2 text-base font-semibold">
                                <Flame className="h-4 w-4 text-muted-foreground" />
                                Recent Hazards
                            </CardTitle>
                            <Link href="/compliance/hazards" className="text-xs text-muted-foreground hover:text-foreground">
                                View all <ArrowRight className="ml-0.5 inline h-3 w-3" />
                            </Link>
                        </CardHeader>
                        <CardContent className="space-y-1.5">
                            {recent_hazards.slice(0, 6).map((h) => (
                                <Link
                                    key={h.id}
                                    href={`/compliance/hazards/${h.id}`}
                                    className="group/item flex items-center justify-between rounded-lg border px-3 py-2.5 transition-colors hover:bg-muted/50"
                                >
                                    <div className="flex flex-wrap items-center gap-1.5">
                                        <Badge className={riskColor(h.risk_rating)}>{h.risk_rating}</Badge>
                                        <span className="text-sm font-medium capitalize">{h.type?.replace(/_/g, ' ') ?? 'Hazard'}</span>
                                    </div>
                                    <div className="flex items-center gap-1.5">
                                        <Badge variant="outline" className={statusColor(h.status)}>{h.status?.replace(/_/g, ' ')}</Badge>
                                        <span className="hidden text-xs text-muted-foreground sm:inline">{h.site_name}</span>
                                        <ChevronRight className="h-3.5 w-3.5 text-muted-foreground opacity-0 transition-opacity group-hover/item:opacity-100" />
                                    </div>
                                </Link>
                            ))}
                            {!recent_hazards.length && (
                                <div className="py-6 text-center text-sm text-muted-foreground">No recent hazards.</div>
                            )}
                        </CardContent>
                    </Card>

                    {/* Recent Fleet Incidents */}
                    <Card>
                        <CardHeader className="flex flex-row items-center justify-between pb-2">
                            <CardTitle className="flex items-center gap-2 text-base font-semibold">
                                <Truck className="h-4 w-4 text-muted-foreground" />
                                Fleet Incidents
                            </CardTitle>
                            <Link href="/fleet-assets/incidents" className="text-xs text-muted-foreground hover:text-foreground">
                                View all <ArrowRight className="ml-0.5 inline h-3 w-3" />
                            </Link>
                        </CardHeader>
                        <CardContent className="space-y-1.5">
                            {recent_fleet_incidents.slice(0, 6).map((fi) => (
                                <Link
                                    key={fi.id}
                                    href={`/fleet-assets/incidents/${fi.id}`}
                                    className="group/item flex items-center justify-between rounded-lg border px-3 py-2.5 transition-colors hover:bg-muted/50"
                                >
                                    <div className="flex flex-wrap items-center gap-1.5">
                                        <Badge className={severityColor(fi.severity)}>{fi.severity}</Badge>
                                        <span className="text-sm font-medium capitalize">{fi.incident_type?.replace(/_/g, ' ')}</span>
                                    </div>
                                    <div className="flex items-center gap-1.5">
                                        <Badge variant="outline" className={statusColor(fi.status)}>{fi.status?.replace(/_/g, ' ')}</Badge>
                                        {fi.asset && <span className="hidden text-xs text-muted-foreground sm:inline">{fi.asset.name}</span>}
                                        <ChevronRight className="h-3.5 w-3.5 text-muted-foreground opacity-0 transition-opacity group-hover/item:opacity-100" />
                                    </div>
                                </Link>
                            ))}
                            {!recent_fleet_incidents.length && (
                                <div className="py-6 text-center text-sm text-muted-foreground">No recent fleet incidents.</div>
                            )}
                        </CardContent>
                    </Card>
                </div>

                {/* ------------------------------------------------ */}
                {/*  Quick Actions                                   */}
                {/* ------------------------------------------------ */}
                <Card>
                    <CardHeader className="pb-3">
                        <CardTitle className="text-base font-semibold">Quick Actions</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div className="flex flex-wrap gap-2">
                            {QUICK_ACTIONS.map((action) => {
                                const Icon = action.icon;
                                return (
                                    <Link key={action.label} href={action.href}>
                                        <Button size="sm" variant={action.variant} className="gap-1.5">
                                            <Icon className="h-4 w-4" />
                                            {action.label}
                                        </Button>
                                    </Link>
                                );
                            })}
                        </div>
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
