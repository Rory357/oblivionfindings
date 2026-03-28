import AppLayout from '@/layouts/app-layout';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Head, Link, router } from '@inertiajs/react';
import { CalendarClock, CheckCircle2, AlertTriangle, Timer } from 'lucide-react';

type Drill = {
    id: number;
    site: { id: number; name: string } | null;
    drill_type: string;
    title: string;
    scheduled_at: string;
    duration_minutes: number | null;
    outcome: string | null;
    participants_count: number;
    findings_count: number;
    status: string;
};

type SiteCompliance = {
    id: number;
    name: string;
    last_drill_date: string | null;
    status: 'compliant' | 'due_soon' | 'overdue';
};

type Props = {
    filters: {
        q: string;
        site_id: string | null;
        drill_type: string | null;
        status: string | null;
    };
    stats: {
        scheduled_drills: number;
        completed_6mo: number;
        sites_overdue: number;
        avg_evacuation_time: string;
    };
    site_compliance: SiteCompliance[];
    drills: {
        data: Drill[];
        links: Array<{ label: string; url: string | null; active: boolean }>;
    };
    sites: Array<{ id: number; name: string }>;
};

const statusColor = (status: string) => {
    switch (status) {
        case 'completed':
            return 'bg-green-100 text-green-800';
        case 'scheduled':
            return 'bg-blue-100 text-blue-800';
        case 'in_progress':
            return 'bg-amber-100 text-amber-800';
        case 'cancelled':
            return 'bg-slate-100 text-slate-800';
        default:
            return 'bg-slate-100 text-slate-800';
    }
};

const complianceColor = (status: string) => {
    switch (status) {
        case 'compliant':
            return 'border-green-200 bg-green-50';
        case 'due_soon':
            return 'border-amber-200 bg-amber-50';
        case 'overdue':
            return 'border-red-200 bg-red-50';
        default:
            return '';
    }
};

const complianceBadge = (status: string) => {
    switch (status) {
        case 'compliant':
            return 'bg-green-100 text-green-800';
        case 'due_soon':
            return 'bg-amber-100 text-amber-800';
        case 'overdue':
            return 'bg-red-100 text-red-800';
        default:
            return 'bg-slate-100 text-slate-800';
    }
};

export default function DrillsIndex({ filters, stats, site_compliance, drills, sites }: Props) {
    const ANY = '__any__';

    const onFilter = (next: Partial<typeof filters>) => {
        router.get('/health-safety/drills', { ...filters, ...next }, { preserveState: true, preserveScroll: true });
    };

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Health & Safety', href: '/health-safety' },
                { title: 'Emergency Drills', href: '/health-safety/drills' },
            ]}
        >
            <Head title="Emergency Drills" />

            <div className="space-y-4">
                <div className="flex items-start justify-between gap-3">
                    <div>
                        <h1 className="text-lg font-semibold">Emergency Drills</h1>
                        <div className="mt-1 text-sm text-slate-500">
                            Schedule, run, and track emergency evacuation drills
                        </div>
                    </div>
                    <Link href="/health-safety/drills/create">
                        <Button size="sm">Schedule Drill</Button>
                    </Link>
                </div>

                {/* Stats Row */}
                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <Card>
                        <CardContent className="flex items-center gap-3 pt-6">
                            <div className="rounded-lg bg-blue-50 p-2">
                                <CalendarClock className="h-5 w-5 text-blue-600" />
                            </div>
                            <div>
                                <div className="text-2xl font-bold">{stats.scheduled_drills}</div>
                                <div className="text-xs text-slate-500">Scheduled Drills</div>
                            </div>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="flex items-center gap-3 pt-6">
                            <div className="rounded-lg bg-green-50 p-2">
                                <CheckCircle2 className="h-5 w-5 text-green-600" />
                            </div>
                            <div>
                                <div className="text-2xl font-bold">{stats.completed_6mo}</div>
                                <div className="text-xs text-slate-500">Completed (6 months)</div>
                            </div>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="flex items-center gap-3 pt-6">
                            <div className="rounded-lg bg-red-50 p-2">
                                <AlertTriangle className="h-5 w-5 text-red-600" />
                            </div>
                            <div>
                                <div className="text-2xl font-bold">{stats.sites_overdue}</div>
                                <div className="text-xs text-slate-500">Sites Overdue</div>
                            </div>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="flex items-center gap-3 pt-6">
                            <div className="rounded-lg bg-amber-50 p-2">
                                <Timer className="h-5 w-5 text-amber-600" />
                            </div>
                            <div>
                                <div className="text-2xl font-bold">{stats.avg_evacuation_time}</div>
                                <div className="text-xs text-slate-500">Avg Evacuation Time</div>
                            </div>
                        </CardContent>
                    </Card>
                </div>

                {/* Site Compliance Cards */}
                {site_compliance.length > 0 && (
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Site Compliance Status</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
                                {site_compliance.map((site) => (
                                    <div
                                        key={site.id}
                                        className={`rounded-lg border p-3 ${complianceColor(site.status)}`}
                                    >
                                        <div className="flex items-center justify-between">
                                            <div className="font-medium text-sm">{site.name}</div>
                                            <Badge className={complianceBadge(site.status)}>
                                                {site.status === 'compliant'
                                                    ? 'Compliant'
                                                    : site.status === 'due_soon'
                                                      ? 'Due Soon'
                                                      : 'Overdue'}
                                            </Badge>
                                        </div>
                                        <div className="mt-1 text-xs text-slate-500">
                                            {site.last_drill_date
                                                ? `Last drill: ${new Date(site.last_drill_date).toLocaleDateString('en-GB')}`
                                                : 'No drills recorded'}
                                        </div>
                                    </div>
                                ))}
                            </div>
                        </CardContent>
                    </Card>
                )}

                {/* Filters */}
                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Filters</CardTitle>
                    </CardHeader>
                    <CardContent className="grid grid-cols-1 gap-3 sm:grid-cols-4">
                        <div>
                            <Label className="text-xs text-slate-500">Search</Label>
                            <Input
                                placeholder="Title or description"
                                value={filters.q || ''}
                                onChange={(e) => onFilter({ q: e.target.value })}
                            />
                        </div>
                        <div>
                            <Label className="text-xs text-slate-500">Site</Label>
                            <Select
                                value={filters.site_id ?? ANY}
                                onValueChange={(v) => onFilter({ site_id: v === ANY ? null : v })}
                            >
                                <SelectTrigger><SelectValue placeholder="Site" /></SelectTrigger>
                                <SelectContent>
                                    <SelectItem value={ANY}>Any</SelectItem>
                                    {sites.map((s) => (
                                        <SelectItem key={s.id} value={String(s.id)}>{s.name}</SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                        <div>
                            <Label className="text-xs text-slate-500">Type</Label>
                            <Select
                                value={filters.drill_type ?? ANY}
                                onValueChange={(v) => onFilter({ drill_type: v === ANY ? null : v })}
                            >
                                <SelectTrigger><SelectValue placeholder="Type" /></SelectTrigger>
                                <SelectContent>
                                    <SelectItem value={ANY}>Any</SelectItem>
                                    <SelectItem value="fire_evacuation">Fire Evacuation</SelectItem>
                                    <SelectItem value="earthquake">Earthquake</SelectItem>
                                    <SelectItem value="lockdown">Lockdown</SelectItem>
                                    <SelectItem value="tsunami">Tsunami</SelectItem>
                                    <SelectItem value="chemical_spill">Chemical Spill</SelectItem>
                                    <SelectItem value="medical_emergency">Medical Emergency</SelectItem>
                                    <SelectItem value="other">Other</SelectItem>
                                </SelectContent>
                            </Select>
                        </div>
                        <div>
                            <Label className="text-xs text-slate-500">Status</Label>
                            <Select
                                value={filters.status ?? ANY}
                                onValueChange={(v) => onFilter({ status: v === ANY ? null : v })}
                            >
                                <SelectTrigger><SelectValue placeholder="Status" /></SelectTrigger>
                                <SelectContent>
                                    <SelectItem value={ANY}>Any</SelectItem>
                                    <SelectItem value="scheduled">Scheduled</SelectItem>
                                    <SelectItem value="in_progress">In Progress</SelectItem>
                                    <SelectItem value="completed">Completed</SelectItem>
                                    <SelectItem value="cancelled">Cancelled</SelectItem>
                                </SelectContent>
                            </Select>
                        </div>
                    </CardContent>
                </Card>

                {/* Drills Table */}
                <Card>
                    <CardContent className="pt-6">
                        <div className="overflow-x-auto">
                            <table className="w-full text-sm">
                                <thead>
                                    <tr className="border-b text-left text-xs text-slate-500">
                                        <th className="pb-2 pr-4 font-medium">Site</th>
                                        <th className="pb-2 pr-4 font-medium">Type</th>
                                        <th className="pb-2 pr-4 font-medium">Date</th>
                                        <th className="pb-2 pr-4 font-medium">Duration</th>
                                        <th className="pb-2 pr-4 font-medium">Outcome</th>
                                        <th className="pb-2 pr-4 font-medium">Participants</th>
                                        <th className="pb-2 pr-4 font-medium">Findings</th>
                                        <th className="pb-2 pr-4 font-medium">Status</th>
                                        <th className="pb-2 font-medium"></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {drills.data.map((drill) => (
                                        <tr key={drill.id} className="border-b last:border-0">
                                            <td className="py-2 pr-4">{drill.site?.name ?? '-'}</td>
                                            <td className="py-2 pr-4 capitalize">
                                                {drill.drill_type.replace(/_/g, ' ')}
                                            </td>
                                            <td className="py-2 pr-4">
                                                {new Date(drill.scheduled_at).toLocaleDateString('en-GB')}
                                            </td>
                                            <td className="py-2 pr-4">
                                                {drill.duration_minutes ? `${drill.duration_minutes} min` : '-'}
                                            </td>
                                            <td className="py-2 pr-4 capitalize">{drill.outcome ?? '-'}</td>
                                            <td className="py-2 pr-4">{drill.participants_count}</td>
                                            <td className="py-2 pr-4">{drill.findings_count}</td>
                                            <td className="py-2 pr-4">
                                                <Badge className={statusColor(drill.status)}>{drill.status}</Badge>
                                            </td>
                                            <td className="py-2">
                                                <Link
                                                    href={`/health-safety/drills/${drill.id}`}
                                                    className="rounded-md border px-3 py-1.5 text-xs hover:bg-muted"
                                                >
                                                    View
                                                </Link>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                            {!drills.data.length && (
                                <div className="py-4 text-center text-sm text-slate-500">
                                    No drills found.
                                </div>
                            )}
                        </div>
                    </CardContent>
                </Card>

                {/* Pagination */}
                {drills?.links?.length ? (
                    <div className="flex flex-wrap gap-2">
                        {drills.links.map((l) => (
                            <button
                                key={l.label}
                                disabled={!l.url}
                                className={`rounded-md border px-3 py-2 text-xs ${l.active ? 'bg-muted' : 'hover:bg-muted'}`}
                                onClick={() => l.url && router.get(l.url, {}, { preserveState: true, preserveScroll: true })}
                                dangerouslySetInnerHTML={{ __html: l.label }}
                            />
                        ))}
                    </div>
                ) : null}
            </div>
        </AppLayout>
    );
}
