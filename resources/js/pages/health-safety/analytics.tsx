import AppLayout from '@/layouts/app-layout';
import FleetHero from '@/components/fleet-hero';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Head, router } from '@inertiajs/react';
import { BarChart3, TrendingUp } from 'lucide-react';
import { useState } from 'react';

type Props = {
    incident_data: Array<{ type: string; count: number }>;
    hazard_data: Array<{ risk_rating: string; count: number }>;
    injury_data: {
        by_type: Array<{ type: string; count: number }>;
        by_body_part: Array<{ body_part: string; count: number }>;
    };
    site_comparison: Array<{
        id: number;
        name: string;
        total_incidents: number;
        open_hazards: number;
        lost_time_days: number;
        drill_status: string;
        compliance_score: number;
    }>;
    root_cause_data: Array<{ category: string; count: number; percentage: number }>;
    filters: { from: string | null; to: string | null };
};

function severityBlockColor(s: string) {
    switch (s) {
        case 'critical': return 'bg-red-100 border-red-300 text-red-800';
        case 'high': return 'bg-orange-100 border-orange-300 text-orange-800';
        case 'medium': return 'bg-yellow-100 border-yellow-300 text-yellow-800';
        case 'low': return 'bg-blue-100 border-blue-300 text-blue-800';
        default: return 'bg-slate-100 border-slate-300 text-slate-800';
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

export default function HealthSafetyAnalytics({
    incident_data,
    hazard_data,
    injury_data,
    site_comparison,
    root_cause_data,
    filters,
}: Props) {
    const [from, setFrom] = useState(filters.from ?? '');
    const [to, setTo] = useState(filters.to ?? '');

    const applyFilters = () => {
        router.get('/health-safety/analytics', { from: from || null, to: to || null }, { preserveState: true, preserveScroll: true });
    };

    const maxIncidentCount = Math.max(...incident_data.map((d) => d.count), 1);
    const maxInjuryTypeCount = Math.max(...(injury_data.by_type?.map((d) => d.count) ?? []), 1);

    const totalIncidents = incident_data.reduce((sum, d) => sum + d.count, 0);
    const nearMissCount = incident_data.find((d) => d.type === 'near_miss')?.count ?? 0;
    const incidentCount = totalIncidents - nearMissCount;
    const ratio = incidentCount > 0 ? (nearMissCount / incidentCount).toFixed(1) : '-';

    return (
        <AppLayout breadcrumbs={[{ title: 'Health & Safety', href: '/health-safety' }, { title: 'Analytics', href: '/health-safety/analytics' }]}>
            <Head title="H&S Analytics" />

            <div className="flex flex-col gap-6 p-6">
                {/* Hero Header */}
                <FleetHero
                    title="Health & Safety Analytics"
                    description="Incident trends, root cause analysis, and site comparisons"
                    icon={<BarChart3 className="h-7 w-7 text-white" />}
                    stats={[
                        { label: 'Total Incidents', value: totalIncidents },
                        { label: 'Near Misses', value: nearMissCount },
                        { label: 'Ratio', value: ratio },
                    ]}
                />

                {/* Date Range Filter */}
                <Card>
                    <CardContent className="flex flex-wrap items-end gap-3 pt-4">
                        <div>
                            <Label className="text-xs text-slate-500">From</Label>
                            <Input type="date" value={from} onChange={(e) => setFrom(e.target.value)} />
                        </div>
                        <div>
                            <Label className="text-xs text-slate-500">To</Label>
                            <Input type="date" value={to} onChange={(e) => setTo(e.target.value)} />
                        </div>
                        <Button size="sm" onClick={applyFilters}>Apply</Button>
                    </CardContent>
                </Card>

                {/* Incident Analysis */}
                <div className="grid gap-4 lg:grid-cols-2">
                    {/* Incident Count by Type */}
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Incidents by Type</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-2">
                            {incident_data.map((d) => (
                                <div key={d.type} className="flex items-center gap-2">
                                    <span className="w-28 text-xs font-medium capitalize">{d.type.replace(/_/g, ' ')}</span>
                                    <div className="flex-1">
                                        <div
                                            className="h-5 rounded bg-blue-500"
                                            style={{ width: `${(d.count / maxIncidentCount) * 100}%`, minWidth: d.count > 0 ? '4px' : '0' }}
                                        />
                                    </div>
                                    <span className="w-8 text-right text-xs font-semibold">{d.count}</span>
                                </div>
                            ))}
                            {!incident_data.length && <div className="py-4 text-center text-sm text-slate-500">No incident data.</div>}
                        </CardContent>
                    </Card>

                    {/* Incident Severity + Near-Miss Ratio */}
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Incident Severity & Near-Miss Ratio</CardTitle>
                        </CardHeader>
                        <CardContent>
                            {/* Severity blocks */}
                            <div className="mb-4 grid grid-cols-2 gap-2 sm:grid-cols-4">
                                {hazard_data.map((d) => (
                                    <div key={d.risk_rating} className={`rounded-lg border p-3 text-center ${severityBlockColor(d.risk_rating)}`}>
                                        <div className="text-xl font-bold">{d.count}</div>
                                        <div className="text-xs capitalize">{d.risk_rating}</div>
                                    </div>
                                ))}
                            </div>
                            {/* Near-miss vs Incident ratio */}
                            <div className="flex items-center justify-center gap-6 rounded-lg border bg-slate-50 p-4">
                                <div className="text-center">
                                    <div className="text-2xl font-bold text-blue-600">{nearMissCount}</div>
                                    <div className="text-xs text-slate-500">Near Misses</div>
                                </div>
                                <div className="text-center">
                                    <div className="text-lg font-medium text-slate-400">vs</div>
                                </div>
                                <div className="text-center">
                                    <div className="text-2xl font-bold text-orange-600">{incidentCount}</div>
                                    <div className="text-xs text-slate-500">Incidents</div>
                                </div>
                                <div className="text-center">
                                    <div className="text-lg font-medium text-slate-400">=</div>
                                </div>
                                <div className="text-center">
                                    <div className="text-2xl font-bold text-green-600">{ratio}</div>
                                    <div className="text-xs text-slate-500">Ratio</div>
                                </div>
                            </div>
                        </CardContent>
                    </Card>
                </div>

                {/* Root Cause Analysis */}
                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Root Cause Analysis</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div className="overflow-x-auto">
                            <table className="w-full text-sm">
                                <thead>
                                    <tr className="border-b text-left text-xs text-slate-500">
                                        <th className="pb-2 font-medium">Category</th>
                                        <th className="pb-2 font-medium">Count</th>
                                        <th className="pb-2 font-medium">Percentage</th>
                                        <th className="pb-2 font-medium">Distribution</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {root_cause_data.map((d) => (
                                        <tr key={d.category} className="border-b last:border-0">
                                            <td className="py-2 font-medium capitalize">{d.category.replace(/_/g, ' ')}</td>
                                            <td className="py-2">{d.count}</td>
                                            <td className="py-2">{d.percentage}%</td>
                                            <td className="py-2">
                                                <div className="h-3 w-full max-w-[200px] rounded-full bg-slate-100">
                                                    <div className="h-3 rounded-full bg-blue-500" style={{ width: `${d.percentage}%` }} />
                                                </div>
                                            </td>
                                        </tr>
                                    ))}
                                    {!root_cause_data.length && (
                                        <tr>
                                            <td colSpan={4} className="py-4 text-center text-slate-500">No root cause data available.</td>
                                        </tr>
                                    )}
                                </tbody>
                            </table>
                        </div>
                    </CardContent>
                </Card>

                {/* Site Comparison */}
                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Site Comparison</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div className="overflow-x-auto">
                            <table className="w-full text-sm">
                                <thead>
                                    <tr className="border-b text-left text-xs text-slate-500">
                                        <th className="pb-2 font-medium">Site</th>
                                        <th className="pb-2 font-medium">Total Incidents</th>
                                        <th className="pb-2 font-medium">Open Hazards</th>
                                        <th className="pb-2 font-medium">Lost Time Days</th>
                                        <th className="pb-2 font-medium">Drill Status</th>
                                        <th className="pb-2 font-medium">Compliance Score</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {site_comparison.map((site) => (
                                        <tr key={site.id} className="border-b last:border-0">
                                            <td className="py-2 font-medium">{site.name}</td>
                                            <td className="py-2">{site.total_incidents}</td>
                                            <td className="py-2">{site.open_hazards}</td>
                                            <td className="py-2">{site.lost_time_days}</td>
                                            <td className="py-2">
                                                <Badge className={drillStatusBadge(site.drill_status)}>
                                                    {site.drill_status === 'due_soon' ? 'Due Soon' : site.drill_status.charAt(0).toUpperCase() + site.drill_status.slice(1)}
                                                </Badge>
                                            </td>
                                            <td className="py-2">
                                                <span className={site.compliance_score >= 90 ? 'font-semibold text-green-600' : site.compliance_score >= 70 ? 'font-semibold text-amber-600' : 'font-semibold text-red-600'}>
                                                    {site.compliance_score}%
                                                </span>
                                            </td>
                                        </tr>
                                    ))}
                                    {!site_comparison.length && (
                                        <tr>
                                            <td colSpan={6} className="py-4 text-center text-slate-500">No site data available.</td>
                                        </tr>
                                    )}
                                </tbody>
                            </table>
                        </div>
                    </CardContent>
                </Card>

                {/* Injury Analysis */}
                <div className="grid gap-4 lg:grid-cols-2">
                    {/* Injury Type Breakdown */}
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Injury Type Breakdown</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-2">
                            {(injury_data.by_type ?? []).map((d) => (
                                <div key={d.type} className="flex items-center gap-2">
                                    <span className="w-28 text-xs font-medium capitalize">{d.type.replace(/_/g, ' ')}</span>
                                    <div className="flex-1">
                                        <div
                                            className="h-5 rounded bg-orange-500"
                                            style={{ width: `${(d.count / maxInjuryTypeCount) * 100}%`, minWidth: d.count > 0 ? '4px' : '0' }}
                                        />
                                    </div>
                                    <span className="w-8 text-right text-xs font-semibold">{d.count}</span>
                                </div>
                            ))}
                            {!(injury_data.by_type ?? []).length && <div className="py-4 text-center text-sm text-slate-500">No injury type data.</div>}
                        </CardContent>
                    </Card>

                    {/* Body Part Frequency */}
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Body Part Frequency</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="space-y-1">
                                {(injury_data.by_body_part ?? []).map((d, idx) => (
                                    <div key={d.body_part} className="flex items-center justify-between rounded px-2 py-1 text-sm odd:bg-slate-50">
                                        <span className="capitalize">{d.body_part.replace(/_/g, ' ')}</span>
                                        <span className="font-semibold">{d.count}</span>
                                    </div>
                                ))}
                                {!(injury_data.by_body_part ?? []).length && <div className="py-4 text-center text-sm text-slate-500">No body part data.</div>}
                            </div>
                        </CardContent>
                    </Card>
                </div>
            </div>
        </AppLayout>
    );
}
