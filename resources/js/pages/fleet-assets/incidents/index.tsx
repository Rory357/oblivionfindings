import { FleetStatCard } from '@/components/fleet-stat-card';
import { FLEET_COLORS, ProgressRing } from '@/components/fleet-charts';
import FleetHero from '@/components/fleet-hero';
import PageShell from '@/components/page-shell';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, router } from '@inertiajs/react';
import {
    AlertOctagon,
    AlertTriangle,
    Download,
    Eye,
    FileWarning,
    Plus,
    Search,
    Shield,
} from 'lucide-react';
import { formatDate } from '@/lib/fleet-utils';


type Incident = {
    id: number;
    asset: { id: number; name: string; registration_number?: string | null } | null;
    reported_by: { id: number; name: string } | null;
    driver: { id: number; name: string } | null;
    incident_type: string;
    severity: string;
    occurred_at: string | null;
    location: string | null;
    status: string;
    police_notified: boolean;
    insurance_claimed: boolean;
};

type Vehicle = { id: number; name: string };

type PaginatedIncidents = {
    data: Incident[];
    links?: Array<{ url: string | null; label: string; active: boolean }>;
    meta?: { current_page: number; last_page: number; total: number };
};

type Props = {
    incidents: Incident[] | PaginatedIncidents;
    vehicles: Vehicle[];
    filters: {
        vehicle_id?: string;
        severity?: string;
        incident_type?: string;
        status?: string;
        date_from?: string;
        date_to?: string;
    };
    stats: {
        total_mtd: number;
        open_investigations: number;
        unresolved: number;
        insurance_claims: number;
    };
};

const SEVERITY_BORDER: Record<string, string> = {
    critical: 'border-l-4 border-l-red-600',
    major: 'border-l-4 border-l-red-500',
    moderate: 'border-l-4 border-l-orange-500',
    minor: 'border-l-4 border-l-yellow-500',
};

function typeBadge(type: string) {
    const labels: Record<string, string> = {
        collision: 'Collision', damage: 'Damage', theft: 'Theft',
        vandalism: 'Vandalism', breakdown: 'Breakdown', near_miss: 'Near Miss', other: 'Other',
    };
    return <Badge variant="outline">{labels[type] ?? type}</Badge>;
}

function severityBadge(severity: string) {
    switch (severity) {
        case 'minor': return <Badge className="bg-yellow-500 text-white">Minor</Badge>;
        case 'moderate': return <Badge className="bg-orange-500 text-white">Moderate</Badge>;
        case 'major': return <Badge className="bg-red-600 text-white">Major</Badge>;
        case 'critical': return <Badge className="bg-red-900 text-white">Critical</Badge>;
        default: return <Badge variant="outline">{severity}</Badge>;
    }
}

function statusBadge(status: string) {
    switch (status) {
        case 'reported': return <Badge variant="outline">Reported</Badge>;
        case 'investigating': return <Badge variant="default" className="bg-blue-600">Investigating</Badge>;
        case 'resolved': return <Badge variant="default" className="bg-green-600">Resolved</Badge>;
        case 'closed': return <Badge variant="secondary">Closed</Badge>;
        default: return <Badge variant="outline">{status}</Badge>;
    }
}

export default function IncidentIndex({ incidents: rawIncidents, vehicles, filters, stats }: Props) {
    const allIncidents = Array.isArray(rawIncidents) ? rawIncidents : (rawIncidents?.data ?? []);
    const paginationLinks = !Array.isArray(rawIncidents) ? rawIncidents?.links ?? [] : [];
    const paginationMeta = !Array.isArray(rawIncidents) ? rawIncidents?.meta ?? {} : {};
    const resolvedCount = allIncidents.filter((i) => i.status === 'resolved' || i.status === 'closed').length;
    const resolutionRate = allIncidents.length > 0 ? Math.round((resolvedCount / allIncidents.length) * 100) : 0;

    const applyFilter = (key: string, value: string) => {
        const cleaned = value === '__all__' ? undefined : (value || undefined);
        router.get('/fleet-assets/incidents', { ...filters, [key]: cleaned }, { preserveState: true });
    };

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Fleet & Assets', href: '/fleet-assets' },
                { title: 'Incidents', href: '#' },
            ]}
        >
            <Head title="Incident Reports" />
            <PageShell>
                <FleetHero
                    title="Incident Reports"
                    actions={
                        <div className="flex gap-2">
                            <Button variant="outline" asChild>
                                <a href={`/fleet-assets/incidents?export=csv&${new URLSearchParams(filters as Record<string, string>).toString()}`}>
                                    <Download className="mr-2 h-4 w-4" />
                                    Export CSV
                                </a>
                            </Button>
                            <Button asChild>
                                <Link href="/fleet-assets/incidents/create">
                                    <Plus className="mr-2 h-4 w-4" />
                                    Report Incident
                                </Link>
                            </Button>
                        </div>
                    }
                />

                {/* Dark KPI Cards + ProgressRing */}
                <div className="grid gap-3 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-5">
                    <FleetStatCard label="TOTAL (MTD)" value={stats?.total_mtd ?? 0} icon={FileWarning} subtitle="Incidents this month" />
                    <FleetStatCard label="OPEN INVESTIGATIONS" value={stats?.open_investigations ?? 0} icon={Search} color="blue" valueClassName="text-blue-400" subtitle="Under investigation" />
                    <FleetStatCard label="UNRESOLVED" value={stats?.unresolved ?? 0} icon={AlertOctagon} color="amber" valueClassName="text-amber-400" subtitle="Awaiting resolution" />
                    <FleetStatCard label="INSURANCE CLAIMS" value={stats?.insurance_claims ?? 0} icon={Shield} subtitle="Claims submitted" />
                    <Card className="border bg-purple-50 dark:bg-purple-950/20">
                        <CardContent className="flex items-center justify-center p-4">
                            <ProgressRing value={resolutionRate} size={80} color={FLEET_COLORS.primary} label="Resolution Rate" />
                        </CardContent>
                    </Card>
                </div>

                {/* Filters */}
                <div className="flex flex-wrap items-end gap-3">
                    <div className="min-w-[140px]">
                        <label className="mb-1 block text-xs font-medium text-muted-foreground">Vehicle</label>
                        <Select value={filters.vehicle_id ?? ''} onValueChange={(v) => applyFilter('vehicle_id', v)}>
                            <SelectTrigger><SelectValue placeholder="All" /></SelectTrigger>
                            <SelectContent>
                                <SelectItem value="__all__">All vehicles</SelectItem>
                                {(vehicles ?? []).map((v) => (
                                    <SelectItem key={v.id} value={String(v.id)}>{v.name}</SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </div>
                    <div className="min-w-[130px]">
                        <label className="mb-1 block text-xs font-medium text-muted-foreground">Severity</label>
                        <Select value={filters.severity ?? ''} onValueChange={(v) => applyFilter('severity', v)}>
                            <SelectTrigger><SelectValue placeholder="All" /></SelectTrigger>
                            <SelectContent>
                                <SelectItem value="__all__">All severities</SelectItem>
                                <SelectItem value="minor">Minor</SelectItem>
                                <SelectItem value="moderate">Moderate</SelectItem>
                                <SelectItem value="major">Major</SelectItem>
                                <SelectItem value="critical">Critical</SelectItem>
                            </SelectContent>
                        </Select>
                    </div>
                    <div className="min-w-[130px]">
                        <label className="mb-1 block text-xs font-medium text-muted-foreground">Type</label>
                        <Select value={filters.incident_type ?? ''} onValueChange={(v) => applyFilter('incident_type', v)}>
                            <SelectTrigger><SelectValue placeholder="All" /></SelectTrigger>
                            <SelectContent>
                                <SelectItem value="__all__">All types</SelectItem>
                                <SelectItem value="collision">Collision</SelectItem>
                                <SelectItem value="damage">Damage</SelectItem>
                                <SelectItem value="theft">Theft</SelectItem>
                                <SelectItem value="vandalism">Vandalism</SelectItem>
                                <SelectItem value="breakdown">Breakdown</SelectItem>
                                <SelectItem value="near_miss">Near Miss</SelectItem>
                                <SelectItem value="other">Other</SelectItem>
                            </SelectContent>
                        </Select>
                    </div>
                    <div className="min-w-[130px]">
                        <label className="mb-1 block text-xs font-medium text-muted-foreground">Status</label>
                        <Select value={filters.status ?? ''} onValueChange={(v) => applyFilter('status', v)}>
                            <SelectTrigger><SelectValue placeholder="All" /></SelectTrigger>
                            <SelectContent>
                                <SelectItem value="__all__">All statuses</SelectItem>
                                <SelectItem value="reported">Reported</SelectItem>
                                <SelectItem value="investigating">Investigating</SelectItem>
                                <SelectItem value="resolved">Resolved</SelectItem>
                                <SelectItem value="closed">Closed</SelectItem>
                            </SelectContent>
                        </Select>
                    </div>
                    <div>
                        <label className="mb-1 block text-xs font-medium text-muted-foreground">From</label>
                        <Input type="date" value={filters.date_from ?? ''} onChange={(e) => applyFilter('date_from', e.target.value)} className="w-[150px]" />
                    </div>
                    <div>
                        <label className="mb-1 block text-xs font-medium text-muted-foreground">To</label>
                        <Input type="date" value={filters.date_to ?? ''} onChange={(e) => applyFilter('date_to', e.target.value)} className="w-[150px]" />
                    </div>
                </div>

                {/* Table with colored left borders by severity */}
                <div className="rounded-lg border overflow-hidden">
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="bg-muted/50 text-xs uppercase tracking-wider text-muted-foreground">
                                <th className="px-3 py-2 text-left font-medium">Date</th>
                                <th className="px-3 py-2 text-left font-medium">Vehicle</th>
                                <th className="px-3 py-2 text-left font-medium">Type</th>
                                <th className="px-3 py-2 text-left font-medium">Severity</th>
                                <th className="px-3 py-2 text-left font-medium">Location</th>
                                <th className="px-3 py-2 text-left font-medium">Reported By</th>
                                <th className="px-3 py-2 text-left font-medium">Status</th>
                                <th className="px-3 py-2 text-right font-medium">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            {allIncidents.length === 0 && (
                                <tr>
                                    <td colSpan={8} className="px-3 py-8 text-center text-muted-foreground">
                                        No incidents found.
                                    </td>
                                </tr>
                            )}
                            {allIncidents.map((inc) => (
                                <tr key={inc.id} className={`border-b transition-colors hover:bg-muted/30 transition-colors ${SEVERITY_BORDER[inc.severity] ?? ''}`}>
                                    <td className="px-3 py-2 whitespace-nowrap">
                                        {inc.occurred_at ? formatDate(inc.occurred_at) : '---'}
                                    </td>
                                    <td className="px-3 py-2">
                                        <div className="font-medium">{inc.asset?.name ?? '---'}</div>
                                        {inc.asset?.registration_number && (
                                            <div className="text-xs text-muted-foreground">{inc.asset.registration_number}</div>
                                        )}
                                    </td>
                                    <td className="px-3 py-2">{typeBadge(inc.incident_type)}</td>
                                    <td className="px-3 py-2">{severityBadge(inc.severity)}</td>
                                    <td className="px-3 py-2 max-w-[150px] truncate">{inc.location ?? '---'}</td>
                                    <td className="px-3 py-2">{inc.reported_by?.name ?? '---'}</td>
                                    <td className="px-3 py-2">{statusBadge(inc.status)}</td>
                                    <td className="px-3 py-2 text-right">
                                        <Button variant="ghost" size="sm" asChild>
                                            <Link href={`/fleet-assets/incidents/${inc.id}`}>
                                                <Eye className="mr-1 h-3.5 w-3.5" />
                                                View
                                            </Link>
                                        </Button>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>

                {/* Pagination */}
                {(paginationMeta.last_page ?? 1) > 1 && paginationLinks.length > 0 && (
                    <div className="flex items-center justify-center gap-1 pt-4">
                        {paginationLinks.map((link, i) => (
                            <Button
                                key={i}
                                variant={link.active ? 'default' : 'outline'}
                                size="sm"
                                disabled={!link.url}
                                onClick={() => link.url && router.get(link.url)}
                                dangerouslySetInnerHTML={{ __html: link.label }}
                            />
                        ))}
                    </div>
                )}
            </PageShell>
        </AppLayout>
    );
}
