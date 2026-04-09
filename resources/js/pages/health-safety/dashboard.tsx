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
    AreaChart, Area, BarChart, Bar, LineChart, Line,
    XAxis, YAxis, CartesianGrid, Tooltip, Legend,
    ResponsiveContainer, PieChart, Pie, Cell,
    RadialBarChart, RadialBar,
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
    backbone?: {
        events: {
            open_events: number;
            open_events_high_critical: number;
            events_period: number;
            worksafe_notifiable_open: number;
            events_by_severity: Record<string, number>;
        };
        investigations: {
            active_investigations: number;
            overdue_investigations: number;
            awaiting_review: number;
        };
        corrective_actions: {
            open_actions: number;
            overdue_actions: number;
            awaiting_verification: number;
        };
        risk_assessments: {
            active_assessments: number;
            high_extreme_active: number;
            due_for_review: number;
        };
        training: {
            total_requirements: number;
            blocking_requirements: number;
            staff_non_compliant: number;
        };
    };
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
    medium: '#eab308',
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
    backbone,
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

    // Radial gauge data for compliance percentages
    const drillCompliancePct = kpis.drill_compliance_pct ?? 0;
    const staffCompliancePct = kpis.staff_compliance_pct ?? 0;
    const resolvedCount = recent_incidents.filter((i) => i.status === 'closed' || i.status === 'resolved').length;
    const incidentResolutionPct = recent_incidents.length > 0
        ? Math.round((resolvedCount / recent_incidents.length) * 100)
        : 100;

    const gaugeColor = (pct: number) => pct > 80 ? '#22c55e' : pct >= 50 ? '#eab308' : '#ef4444';

    const radialGauges = [
        { label: 'Drill Compliance', value: drillCompliancePct, fill: gaugeColor(drillCompliancePct) },
        { label: 'Staff Compliance', value: staffCompliancePct, fill: gaugeColor(staffCompliancePct) },
        { label: 'Resolution Rate', value: incidentResolutionPct, fill: gaugeColor(incidentResolutionPct) },
    ];

    // Monthly comparison: current month vs previous month
    const monthlyComparisonData = incident_trends.slice(-2).length === 2
        ? (() => {
            const prev = incident_trends[incident_trends.length - 2];
            const curr = incident_trends[incident_trends.length - 1];
            const prevTypes = prev.types ?? {};
            const currTypes = curr.types ?? {};
            const allKeys = Array.from(new Set([...Object.keys(prevTypes), ...Object.keys(currTypes)]));
            return allKeys.map((type) => ({
                type: type.replace(/_/g, ' '),
                previous: prevTypes[type] ?? 0,
                current: currTypes[type] ?? 0,
            }));
        })()
        : [];

    // Custom tooltip component
    const CustomTooltip = ({ active, payload, label }: any) => {
        if (!active || !payload?.length) return null;
        return (
            <div className="rounded-lg border-0 bg-slate-800 px-3 py-2 text-xs text-white shadow-lg">
                <p className="mb-1 font-medium">{label}</p>
                {payload.map((p: any, i: number) => (
                    <p key={i} className="flex items-center gap-1.5">
                        <span className="inline-block h-2 w-2 rounded-full" style={{ backgroundColor: p.color }} />
                        <span className="capitalize">{String(p.dataKey).replace(/_/g, ' ')}</span>:
                        <span className="font-semibold">{p.value}</span>
                    </p>
                ))}
            </div>
        );
    };

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
                    {/* Incident Trends - Gradient Area Chart */}
                    <Card className="lg:col-span-2 rounded-xl shadow-sm">
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
                                    <AreaChart data={trendChartData} margin={{ top: 5, right: 10, left: -10, bottom: 5 }}>
                                        <defs>
                                            {allTypes.map((type) => (
                                                <linearGradient key={type} id={`gradient-${type}`} x1="0" y1="0" x2="0" y2="1">
                                                    <stop offset="0%" stopColor={TYPE_COLORS[type] ?? '#94a3b8'} stopOpacity={0.4} />
                                                    <stop offset="95%" stopColor={TYPE_COLORS[type] ?? '#94a3b8'} stopOpacity={0.02} />
                                                </linearGradient>
                                            ))}
                                        </defs>
                                        <CartesianGrid strokeDasharray="3 3" stroke="#e2e8f0" vertical={false} />
                                        <XAxis dataKey="month" tick={{ fontSize: 11, fill: '#94a3b8' }} axisLine={false} tickLine={false} />
                                        <YAxis allowDecimals={false} tick={{ fontSize: 11, fill: '#94a3b8' }} axisLine={false} tickLine={false} />
                                        <Tooltip content={<CustomTooltip />} />
                                        <Legend
                                            formatter={(value: string) => <span className="text-xs capitalize text-slate-600">{value.replace(/_/g, ' ')}</span>}
                                        />
                                        {allTypes.map((type) => (
                                            <Area
                                                key={type}
                                                type="monotone"
                                                dataKey={type}
                                                stackId="incidents"
                                                stroke={TYPE_COLORS[type] ?? '#94a3b8'}
                                                strokeWidth={2}
                                                fill={`url(#gradient-${type})`}
                                                animationDuration={800}
                                            />
                                        ))}
                                    </AreaChart>
                                </ResponsiveContainer>
                            ) : (
                                <div className="flex h-[300px] items-center justify-center text-sm text-muted-foreground">
                                    No trend data available.
                                </div>
                            )}
                        </CardContent>
                    </Card>

                    {/* Severity Breakdown - Modern Donut with center text */}
                    <Card className="rounded-xl shadow-sm">
                        <CardHeader className="pb-2">
                            <CardTitle className="text-base font-semibold">Severity Breakdown</CardTitle>
                        </CardHeader>
                        <CardContent>
                            {totalSeverity > 0 ? (
                                <div className="flex flex-col items-center">
                                    <ResponsiveContainer width="100%" height={220}>
                                        <PieChart>
                                            <Pie
                                                data={severityData}
                                                cx="50%"
                                                cy="50%"
                                                innerRadius={60}
                                                outerRadius={85}
                                                paddingAngle={3}
                                                dataKey="value"
                                                stroke="none"
                                                animationDuration={800}
                                            >
                                                {severityData.map((entry) => (
                                                    <Cell key={entry.key} fill={SEVERITY_COLORS[entry.key] ?? '#94a3b8'} />
                                                ))}
                                            </Pie>
                                            <Tooltip
                                                content={({ active, payload }) => {
                                                    if (!active || !payload?.length) return null;
                                                    const d = payload[0];
                                                    return (
                                                        <div className="rounded-lg border-0 bg-slate-800 px-3 py-2 text-xs text-white shadow-lg">
                                                            <span className="font-medium">{d.name}</span>: {d.value} incidents
                                                        </div>
                                                    );
                                                }}
                                            />
                                            {/* Center label */}
                                            <text x="50%" y="48%" textAnchor="middle" dominantBaseline="central" className="fill-slate-900 text-2xl font-bold">
                                                {totalSeverity}
                                            </text>
                                            <text x="50%" y="60%" textAnchor="middle" dominantBaseline="central" className="fill-slate-500 text-xs">
                                                Total
                                            </text>
                                        </PieChart>
                                    </ResponsiveContainer>
                                    {/* Legend as colored dots */}
                                    <div className="mt-1 grid w-full grid-cols-2 gap-2">
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
                                <div className="flex h-[220px] items-center justify-center text-sm text-muted-foreground">
                                    No incident severity data.
                                </div>
                            )}
                        </CardContent>
                    </Card>
                </div>

                {/* ------------------------------------------------ */}
                {/*  Radial Progress Gauges                          */}
                {/* ------------------------------------------------ */}
                <div className="grid gap-4 sm:grid-cols-3">
                    {radialGauges.map((gauge) => (
                        <Card key={gauge.label} className="rounded-xl shadow-sm">
                            <CardHeader className="pb-0">
                                <CardTitle className="text-center text-sm font-medium text-muted-foreground">{gauge.label}</CardTitle>
                            </CardHeader>
                            <CardContent className="flex flex-col items-center pb-4">
                                <ResponsiveContainer width="100%" height={150}>
                                    <RadialBarChart
                                        cx="50%"
                                        cy="50%"
                                        innerRadius="70%"
                                        outerRadius="90%"
                                        barSize={12}
                                        data={[{ value: gauge.value, fill: gauge.fill }]}
                                        startAngle={210}
                                        endAngle={-30}
                                    >
                                        <RadialBar
                                            dataKey="value"
                                            cornerRadius={6}
                                            background={{ fill: '#f1f5f9' }}
                                            animationDuration={800}
                                        />
                                        <text x="50%" y="50%" textAnchor="middle" dominantBaseline="central" className="text-xl font-bold" fill={gauge.fill}>
                                            {gauge.value}%
                                        </text>
                                    </RadialBarChart>
                                </ResponsiveContainer>
                            </CardContent>
                        </Card>
                    ))}
                </div>

                {/* ------------------------------------------------ */}
                {/*  H&S Backbone Status (PR5)                       */}
                {/* ------------------------------------------------ */}
                {backbone && (
                    <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                        {/* Investigations */}
                        <Link href="/health-safety/events?status=investigating" className="group" preserveScroll>
                            <Card className="transition-all duration-150 group-hover:shadow-md group-hover:-translate-y-0.5">
                                <CardContent className="p-4">
                                    <div className="flex items-center justify-between">
                                        <span className="text-xs font-medium text-muted-foreground">Active Investigations</span>
                                        <Activity className="h-4 w-4 text-purple-500" />
                                    </div>
                                    <div className="mt-2 text-2xl font-bold">{backbone.investigations.active_investigations}</div>
                                    <div className="mt-1 flex gap-3 text-xs text-muted-foreground">
                                        {backbone.investigations.overdue_investigations > 0 && (
                                            <span className="text-red-600 font-medium">{backbone.investigations.overdue_investigations} overdue</span>
                                        )}
                                        {backbone.investigations.awaiting_review > 0 && (
                                            <span className="text-amber-600">{backbone.investigations.awaiting_review} awaiting review</span>
                                        )}
                                    </div>
                                </CardContent>
                            </Card>
                        </Link>

                        {/* Corrective Actions */}
                        <Link href={backbone.corrective_actions.overdue_actions > 0 ? '/health-safety/corrective-actions?overdue=true' : '/health-safety/corrective-actions'} className="group">
                            <Card className={`transition-all duration-150 group-hover:shadow-md group-hover:-translate-y-0.5 ${backbone.corrective_actions.overdue_actions > 0 ? 'border-red-200 bg-red-50/40' : ''}`}>
                                <CardContent className="p-4">
                                    <div className="flex items-center justify-between">
                                        <span className="text-xs font-medium text-muted-foreground">Open Corrective Actions</span>
                                        <Clock className="h-4 w-4 text-amber-500" />
                                    </div>
                                    <div className="mt-2 text-2xl font-bold">{backbone.corrective_actions.open_actions}</div>
                                    <div className="mt-1 flex gap-3 text-xs text-muted-foreground">
                                        {backbone.corrective_actions.overdue_actions > 0 && (
                                            <span className="text-red-600 font-medium">{backbone.corrective_actions.overdue_actions} overdue</span>
                                        )}
                                        {backbone.corrective_actions.awaiting_verification > 0 && (
                                            <span className="text-blue-600">{backbone.corrective_actions.awaiting_verification} awaiting verification</span>
                                        )}
                                    </div>
                                </CardContent>
                            </Card>
                        </Link>

                        {/* Risk Assessments */}
                        <Link href={backbone.risk_assessments.due_for_review > 0 ? '/health-safety/risk-assessments?due_for_review=true' : '/health-safety/risk-assessments?status=active'} className="group">
                            <Card className={`transition-all duration-150 group-hover:shadow-md group-hover:-translate-y-0.5 ${backbone.risk_assessments.due_for_review > 0 ? 'border-amber-200 bg-amber-50/40' : ''}`}>
                                <CardContent className="p-4">
                                    <div className="flex items-center justify-between">
                                        <span className="text-xs font-medium text-muted-foreground">Active Risk Assessments</span>
                                        <Shield className="h-4 w-4 text-blue-500" />
                                    </div>
                                    <div className="mt-2 text-2xl font-bold">{backbone.risk_assessments.active_assessments}</div>
                                    <div className="mt-1 flex gap-3 text-xs text-muted-foreground">
                                        {backbone.risk_assessments.high_extreme_active > 0 && (
                                            <span className="text-orange-600 font-medium">{backbone.risk_assessments.high_extreme_active} high/extreme</span>
                                        )}
                                        {backbone.risk_assessments.due_for_review > 0 && (
                                            <span className="text-amber-600">{backbone.risk_assessments.due_for_review} due for review</span>
                                        )}
                                    </div>
                                </CardContent>
                            </Card>
                        </Link>

                        {/* H&S Events Summary */}
                        <Link href="/health-safety/events?status=open" className="group">
                            <Card className={`transition-all duration-150 group-hover:shadow-md group-hover:-translate-y-0.5 ${backbone.events.worksafe_notifiable_open > 0 ? 'border-red-200 bg-red-50/40' : ''}`}>
                                <CardContent className="p-4">
                                    <div className="flex items-center justify-between">
                                        <span className="text-xs font-medium text-muted-foreground">Open H&S Events</span>
                                        <FileWarning className="h-4 w-4 text-slate-500" />
                                    </div>
                                    <div className="mt-2 text-2xl font-bold">{backbone.events.open_events}</div>
                                    <div className="mt-1 flex gap-3 text-xs text-muted-foreground">
                                        {backbone.events.open_events_high_critical > 0 && (
                                            <span className="text-red-600 font-medium">{backbone.events.open_events_high_critical} high/critical</span>
                                        )}
                                        {backbone.events.worksafe_notifiable_open > 0 && (
                                            <span className="text-red-700 font-semibold">{backbone.events.worksafe_notifiable_open} WorkSafe notifiable</span>
                                        )}
                                    </div>
                                </CardContent>
                            </Card>
                        </Link>
                    </div>
                )}

                {/* ------------------------------------------------ */}
                {/*  Charts Row 2: Hazard Risk + Drill Compliance    */}
                {/* ------------------------------------------------ */}
                <div className="grid gap-4 lg:grid-cols-2">
                    {/* Hazard Risk Distribution - Horizontal Bar with rounded ends */}
                    <Card className="rounded-xl shadow-sm">
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
                                    <XAxis type="number" allowDecimals={false} tick={{ fontSize: 11, fill: '#94a3b8' }} axisLine={false} tickLine={false} />
                                    <YAxis type="category" dataKey="level" tick={{ fontSize: 12, fill: '#64748b' }} width={65} axisLine={false} tickLine={false} />
                                    <Tooltip
                                        content={({ active, payload }) => {
                                            if (!active || !payload?.length) return null;
                                            const d = payload[0];
                                            return (
                                                <div className="rounded-lg border-0 bg-slate-800 px-3 py-2 text-xs text-white shadow-lg">
                                                    <span className="font-medium">{d.payload?.level}</span>: {d.value} hazards
                                                </div>
                                            );
                                        }}
                                    />
                                    <Bar dataKey="count" radius={[0, 6, 6, 0]} animationDuration={800}>
                                        {hazardChartData.map((entry) => (
                                            <Cell key={entry.key} fill={HAZARD_COLORS[entry.key] ?? '#94a3b8'} />
                                        ))}
                                    </Bar>
                                </BarChart>
                            </ResponsiveContainer>
                        </CardContent>
                    </Card>

                    {/* Site Drill Compliance */}
                    <Card className="rounded-xl shadow-sm">
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
                {/*  Monthly Comparison Mini-Chart                    */}
                {/* ------------------------------------------------ */}
                {monthlyComparisonData.length > 0 && (
                    <Card className="rounded-xl shadow-sm">
                        <CardHeader className="flex flex-row items-center justify-between pb-2">
                            <CardTitle className="flex items-center gap-2 text-base font-semibold">
                                <Activity className="h-4 w-4 text-muted-foreground" />
                                Monthly Comparison
                            </CardTitle>
                            <div className="flex items-center gap-3 text-xs text-muted-foreground">
                                <span className="flex items-center gap-1">
                                    <span className="inline-block h-2 w-6 rounded-full bg-blue-500" /> Current
                                </span>
                                <span className="flex items-center gap-1">
                                    <span className="inline-block h-0.5 w-6 border-t-2 border-dashed border-slate-400" /> Previous
                                </span>
                            </div>
                        </CardHeader>
                        <CardContent>
                            <ResponsiveContainer width="100%" height={150}>
                                <LineChart data={monthlyComparisonData} margin={{ top: 5, right: 20, left: 0, bottom: 5 }}>
                                    <CartesianGrid strokeDasharray="3 3" stroke="#e2e8f0" vertical={false} />
                                    <XAxis dataKey="type" tick={{ fontSize: 11, fill: '#94a3b8' }} axisLine={false} tickLine={false} />
                                    <YAxis allowDecimals={false} tick={{ fontSize: 11, fill: '#94a3b8' }} axisLine={false} tickLine={false} />
                                    <Tooltip content={<CustomTooltip />} />
                                    <Line
                                        type="monotone"
                                        dataKey="previous"
                                        stroke="#94a3b8"
                                        strokeWidth={2}
                                        strokeDasharray="5 3"
                                        dot={{ r: 3, fill: '#94a3b8' }}
                                        animationDuration={800}
                                    />
                                    <Line
                                        type="monotone"
                                        dataKey="current"
                                        stroke="#3b82f6"
                                        strokeWidth={2.5}
                                        dot={{ r: 4, fill: '#3b82f6', strokeWidth: 0 }}
                                        animationDuration={800}
                                    />
                                </LineChart>
                            </ResponsiveContainer>
                        </CardContent>
                    </Card>
                )}

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
