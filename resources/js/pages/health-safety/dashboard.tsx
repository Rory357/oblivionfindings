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
    Plus,
    Eye,
    HardHat,
    Siren,
    Radio,
    Truck,
} from 'lucide-react';

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

const KPI_CONFIG: Array<{
    key: string;
    label: string;
    icon: React.ElementType;
    color: (v: number) => string;
}> = [
    { key: 'incidents_30d', label: 'Incidents (30d)', icon: AlertTriangle, color: () => 'text-slate-700' },
    { key: 'near_misses_30d', label: 'Near Misses (30d)', icon: Eye, color: () => 'text-slate-700' },
    { key: 'open_hazards', label: 'Open Hazards', icon: Flame, color: () => 'text-orange-600' },
    { key: 'overdue_actions', label: 'Overdue Actions', icon: Clock, color: (v) => (v > 0 ? 'text-red-600' : 'text-green-600') },
    { key: 'workplace_injuries_ytd', label: 'Workplace Injuries (YTD)', icon: Heart, color: () => 'text-slate-700' },
    { key: 'lost_time_days_ytd', label: 'Lost Time Days (YTD)', icon: Activity, color: () => 'text-slate-700' },
    { key: 'days_since_notifiable', label: 'Days Since Notifiable', icon: FileWarning, color: (v) => (v > 30 ? 'text-green-600' : 'text-red-600') },
    { key: 'drill_compliance_pct', label: 'Drill Compliance %', icon: CalendarCheck, color: (v) => (v >= 90 ? 'text-green-600' : v >= 70 ? 'text-amber-600' : 'text-red-600') },
    { key: 'active_alerts', label: 'Active Alerts', icon: Bell, color: () => 'text-slate-700' },
    { key: 'open_safeguarding', label: 'Open Safeguarding', icon: Shield, color: () => 'text-purple-600' },
    { key: 'fleet_incidents_30d', label: 'Fleet Incidents (30d)', icon: Truck, color: () => 'text-slate-700' },
    { key: 'fleet_unresolved', label: 'Fleet Unresolved', icon: Car, color: (v) => (v > 0 ? 'text-amber-600' : 'text-green-600') },
    { key: 'staff_compliance_pct', label: 'Staff Compliance %', icon: Users, color: () => 'text-slate-700' },
];

function kpiBg(key: string, value: number): string {
    if (key === 'overdue_actions') return value > 0 ? 'border-red-200 bg-red-50' : 'border-green-200 bg-green-50';
    if (key === 'days_since_notifiable') return value > 30 ? 'border-green-200 bg-green-50' : 'border-red-200 bg-red-50';
    if (key === 'drill_compliance_pct') {
        if (value >= 90) return 'border-green-200 bg-green-50';
        if (value >= 70) return 'border-amber-200 bg-amber-50';
        return 'border-red-200 bg-red-50';
    }
    return '';
}

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

const TYPE_COLORS: Record<string, string> = {
    injury: '#ef4444',
    behaviour: '#f59e0b',
    medication: '#3b82f6',
    safeguarding: '#8b5cf6',
    near_miss: '#6b7280',
    other: '#94a3b8',
};

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
    const maxTrendCount = Math.max(...incident_trends.map((t) => t.count), 1);

    const hazardLevels = [
        { key: 'extreme', label: 'Extreme', color: 'bg-red-500' },
        { key: 'high', label: 'High', color: 'bg-orange-500' },
        { key: 'medium', label: 'Medium', color: 'bg-yellow-500' },
        { key: 'low', label: 'Low', color: 'bg-green-500' },
    ];
    const maxHazardCount = Math.max(...hazardLevels.map((h) => hazard_summary[h.key] ?? 0), 1);

    const severityLevels = [
        { key: 'critical', label: 'Critical', bg: 'bg-red-100 border-red-300 text-red-800' },
        { key: 'high', label: 'High', bg: 'bg-orange-100 border-orange-300 text-orange-800' },
        { key: 'medium', label: 'Medium', bg: 'bg-yellow-100 border-yellow-300 text-yellow-800' },
        { key: 'low', label: 'Low', bg: 'bg-blue-100 border-blue-300 text-blue-800' },
    ];

    return (
        <AppLayout breadcrumbs={[{ title: 'Health & Safety', href: '/health-safety' }, { title: 'Dashboard', href: '/health-safety/dashboard' }]}>
            <Head title="Health & Safety Dashboard" />

            <div className="space-y-6">
                {/* Page Title */}
                <div className="flex items-center gap-2">
                    <Shield className="h-6 w-6 text-blue-600" />
                    <h1 className="text-xl font-semibold">Health & Safety Dashboard</h1>
                </div>

                {/* KPI Grid */}
                <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    {KPI_CONFIG.map((cfg) => {
                        const value = kpis[cfg.key] ?? 0;
                        const Icon = cfg.icon;
                        return (
                            <Card key={cfg.key} className={kpiBg(cfg.key, value)}>
                                <CardContent className="flex items-center gap-3 pt-4">
                                    <Icon className={`h-5 w-5 ${cfg.color(value)}`} />
                                    <div>
                                        <div className={`text-2xl font-bold ${cfg.color(value)}`}>{cfg.key.endsWith('_pct') ? `${value}%` : value}</div>
                                        <div className="text-xs text-slate-500">{cfg.label}</div>
                                    </div>
                                </CardContent>
                            </Card>
                        );
                    })}
                </div>

                {/* Charts Section */}
                <div className="grid gap-4 lg:grid-cols-2">
                    {/* Incident Trends */}
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Incident Trends (12 months)</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-2">
                            {incident_trends.map((t) => (
                                <div key={t.month} className="flex items-center gap-2">
                                    <span className="w-16 text-xs text-slate-500">{t.month}</span>
                                    <div className="flex flex-1 items-center">
                                        <div className="flex h-5 overflow-hidden rounded" style={{ width: `${(t.count / maxTrendCount) * 100}%`, minWidth: t.count > 0 ? '2px' : '0' }}>
                                            {Object.entries(t.types ?? {}).map(([type, count]) => (
                                                <div
                                                    key={type}
                                                    className="h-full"
                                                    style={{ width: `${(count / t.count) * 100}%`, backgroundColor: TYPE_COLORS[type] ?? '#94a3b8' }}
                                                    title={`${type}: ${count}`}
                                                />
                                            ))}
                                            {!Object.keys(t.types ?? {}).length && t.count > 0 && (
                                                <div className="h-full w-full bg-blue-500" />
                                            )}
                                        </div>
                                    </div>
                                    <span className="w-8 text-right text-xs font-medium">{t.count}</span>
                                </div>
                            ))}
                            {!incident_trends.length && <div className="py-4 text-center text-sm text-slate-500">No data available.</div>}
                        </CardContent>
                    </Card>

                    {/* Hazard Risk Distribution */}
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Hazard Risk Distribution</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-3">
                            {hazardLevels.map((h) => {
                                const count = hazard_summary[h.key] ?? 0;
                                return (
                                    <div key={h.key} className="flex items-center gap-2">
                                        <span className="w-16 text-xs font-medium capitalize">{h.label}</span>
                                        <div className="flex-1">
                                            <div className={`h-6 rounded ${h.color}`} style={{ width: `${(count / maxHazardCount) * 100}%`, minWidth: count > 0 ? '4px' : '0' }} />
                                        </div>
                                        <span className="w-8 text-right text-sm font-semibold">{count}</span>
                                    </div>
                                );
                            })}
                        </CardContent>
                    </Card>
                </div>

                {/* Severity Breakdown */}
                <div className="grid gap-3 sm:grid-cols-4">
                    {severityLevels.map((s) => (
                        <div key={s.key} className={`rounded-lg border p-4 text-center ${s.bg}`}>
                            <div className="text-2xl font-bold">{severity_breakdown[s.key] ?? 0}</div>
                            <div className="text-xs font-medium capitalize">{s.label}</div>
                        </div>
                    ))}
                </div>

                {/* Site Drill Compliance */}
                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Site Drill Compliance</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div className="overflow-x-auto">
                            <table className="w-full text-sm">
                                <thead>
                                    <tr className="border-b text-left text-xs text-slate-500">
                                        <th className="pb-2 font-medium">Site Name</th>
                                        <th className="pb-2 font-medium">Last Drill Date</th>
                                        <th className="pb-2 font-medium">Days Since</th>
                                        <th className="pb-2 font-medium">Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {site_drill_compliance.map((site) => (
                                        <tr key={site.id} className="border-b last:border-0">
                                            <td className="py-2 font-medium">{site.name}</td>
                                            <td className="py-2">
                                                {site.last_drill_date ? (
                                                    formatDate(site.last_drill_date)
                                                ) : (
                                                    <span className="font-medium text-red-600">Never</span>
                                                )}
                                            </td>
                                            <td className="py-2">{site.days_since ?? '-'}</td>
                                            <td className="py-2">
                                                <Badge className={drillStatusBadge(site.status)}>
                                                    {site.status === 'due_soon' ? 'Due Soon' : site.status.charAt(0).toUpperCase() + site.status.slice(1)}
                                                </Badge>
                                            </td>
                                        </tr>
                                    ))}
                                    {!site_drill_compliance.length && (
                                        <tr>
                                            <td colSpan={4} className="py-4 text-center text-slate-500">No sites found.</td>
                                        </tr>
                                    )}
                                </tbody>
                            </table>
                        </div>
                    </CardContent>
                </Card>

                {/* Recent Activity */}
                <div className="grid gap-4 lg:grid-cols-2">
                    {/* Recent Incidents */}
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Recent Incidents</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-2">
                            {recent_incidents.map((inc) => (
                                <Link key={inc.id} href={`/incidents/${inc.id}`} className="flex items-center justify-between rounded-md border px-3 py-2 hover:bg-muted">
                                    <div className="flex flex-wrap items-center gap-2">
                                        <Badge className={severityColor(inc.severity)}>{inc.severity}</Badge>
                                        <span className="text-sm font-medium capitalize">{inc.type.replace(/_/g, ' ')}</span>
                                        <Badge className={statusColor(inc.status)}>{inc.status.replace(/_/g, ' ')}</Badge>
                                    </div>
                                    <span className="text-xs text-slate-500">{formatDate(inc.occurred_at)}</span>
                                </Link>
                            ))}
                            {!recent_incidents.length && <div className="py-4 text-center text-sm text-slate-500">No recent incidents.</div>}
                        </CardContent>
                    </Card>

                    {/* Recent Hazards */}
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Recent Hazards</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-2">
                            {recent_hazards.map((h) => (
                                <div key={h.id} className="flex items-center justify-between rounded-md border px-3 py-2">
                                    <div className="flex flex-wrap items-center gap-2">
                                        <Badge className={riskColor(h.risk_rating)}>{h.risk_rating}</Badge>
                                        <span className="text-sm font-medium capitalize">{h.type?.replace(/_/g, ' ') ?? 'Hazard'}</span>
                                        <Badge className={statusColor(h.status)}>{h.status?.replace(/_/g, ' ')}</Badge>
                                    </div>
                                    <span className="text-xs text-slate-500">{h.site_name}</span>
                                </div>
                            ))}
                            {!recent_hazards.length && <div className="py-4 text-center text-sm text-slate-500">No recent hazards.</div>}
                        </CardContent>
                    </Card>
                    {/* Recent Fleet Incidents */}
                    {recent_fleet_incidents.length > 0 && (
                        <Card className="lg:col-span-2">
                            <CardHeader>
                                <CardTitle className="flex items-center gap-2 text-base">
                                    <Truck className="h-4 w-4" />
                                    Recent Fleet Incidents
                                </CardTitle>
                            </CardHeader>
                            <CardContent className="space-y-2">
                                {recent_fleet_incidents.map((fi) => (
                                    <Link key={fi.id} href={`/fleet-assets/incidents/${fi.id}`} className="flex items-center justify-between rounded-md border px-3 py-2 hover:bg-slate-50">
                                        <div className="flex flex-wrap items-center gap-2">
                                            <Badge className={
                                                fi.severity === 'critical' ? 'bg-red-100 text-red-700 border-0' :
                                                fi.severity === 'major' ? 'bg-orange-100 text-orange-700 border-0' :
                                                fi.severity === 'moderate' ? 'bg-amber-100 text-amber-700 border-0' :
                                                'bg-slate-100 text-slate-700 border-0'
                                            }>{fi.severity}</Badge>
                                            <span className="text-sm font-medium capitalize">{fi.incident_type?.replace(/_/g, ' ')}</span>
                                            <Badge className={statusColor(fi.status)}>{fi.status?.replace(/_/g, ' ')}</Badge>
                                        </div>
                                        <div className="flex items-center gap-3 text-xs text-slate-500">
                                            {fi.asset && <span>{fi.asset.name}</span>}
                                            {fi.occurred_at && <span>{formatDate(fi.occurred_at)}</span>}
                                        </div>
                                    </Link>
                                ))}
                            </CardContent>
                        </Card>
                    )}
                </div>

                {/* Quick Actions */}
                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Quick Actions</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div className="flex flex-wrap gap-2">
                            <Link href="/health-safety/incidents/create">
                                <Button size="sm" variant="outline">
                                    <AlertTriangle className="mr-1.5 h-4 w-4" />
                                    Report Incident
                                </Button>
                            </Link>
                            <Link href="/health-safety/incidents/create?type=near_miss">
                                <Button size="sm" variant="outline">
                                    <Eye className="mr-1.5 h-4 w-4" />
                                    Report Near-Miss
                                </Button>
                            </Link>
                            <Link href="/health-safety/hazards/create">
                                <Button size="sm" variant="outline">
                                    <Flame className="mr-1.5 h-4 w-4" />
                                    Report Hazard
                                </Button>
                            </Link>
                            <Link href="/health-safety/first-aid/create">
                                <Button size="sm" variant="outline">
                                    <Heart className="mr-1.5 h-4 w-4" />
                                    Record First Aid
                                </Button>
                            </Link>
                            <Link href="/health-safety/lone-worker/start">
                                <Button size="sm" variant="outline">
                                    <Radio className="mr-1.5 h-4 w-4" />
                                    Start Lone Worker Session
                                </Button>
                            </Link>
                        </div>
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
