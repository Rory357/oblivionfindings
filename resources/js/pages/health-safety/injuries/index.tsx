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
import { HeartPulse, ClipboardList, CalendarX, Clock } from 'lucide-react';

type Injury = {
    id: number;
    user: { id: number; name: string } | null;
    injury_date: string;
    injury_type: string;
    body_part_affected: string | null;
    severity: string;
    status: string;
    lost_time_days: number;
    acc_claim_lodged: boolean;
    acc_claim_number: string | null;
};

type Props = {
    filters: {
        q: string;
        status: string | null;
        severity: string | null;
    };
    stats: {
        active_injuries: number;
        active_rtw_plans: number;
        lost_days_30d: number;
        pending_assessments: number;
    };
    injuries: {
        data: Injury[];
        links: Array<{ label: string; url: string | null; active: boolean }>;
    };
};

const statusColor = (status: string) => {
    switch (status) {
        case 'active':
        case 'open':
            return 'bg-amber-100 text-amber-800';
        case 'recovering':
            return 'bg-blue-100 text-blue-800';
        case 'returned_to_work':
            return 'bg-green-100 text-green-800';
        case 'closed':
            return 'bg-slate-100 text-slate-800';
        default:
            return 'bg-slate-100 text-slate-800';
    }
};

const severityColor = (severity: string) => {
    switch (severity) {
        case 'minor':
            return 'bg-green-100 text-green-800';
        case 'moderate':
            return 'bg-amber-100 text-amber-800';
        case 'serious':
            return 'bg-orange-100 text-orange-800';
        case 'critical':
            return 'bg-red-100 text-red-800';
        default:
            return 'bg-slate-100 text-slate-800';
    }
};

export default function InjuriesIndex({ filters, stats, injuries }: Props) {
    const ANY = '__any__';

    const onFilter = (next: Partial<typeof filters>) => {
        router.get('/health-safety/injuries', { ...filters, ...next }, { preserveState: true, preserveScroll: true });
    };

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Health & Safety', href: '/health-safety' },
                { title: 'Injuries & Return to Work', href: '/health-safety/injuries' },
            ]}
        >
            <Head title="Injuries & Return to Work" />

            <div className="space-y-4">
                <div className="flex items-start justify-between gap-3">
                    <div>
                        <h1 className="text-lg font-semibold">Injuries & Return to Work</h1>
                        <div className="mt-1 text-sm text-slate-500">
                            Track workplace injuries, RTW plans, and capacity assessments
                        </div>
                    </div>
                    <Link href="/health-safety/injuries/create">
                        <Button size="sm">Record Injury</Button>
                    </Link>
                </div>

                {/* Stats Row */}
                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <Card>
                        <CardContent className="flex items-center gap-3 pt-6">
                            <div className="rounded-lg bg-amber-50 p-2">
                                <HeartPulse className="h-5 w-5 text-amber-600" />
                            </div>
                            <div>
                                <div className="text-2xl font-bold">{stats.active_injuries}</div>
                                <div className="text-xs text-slate-500">Active Injuries</div>
                            </div>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="flex items-center gap-3 pt-6">
                            <div className="rounded-lg bg-blue-50 p-2">
                                <ClipboardList className="h-5 w-5 text-blue-600" />
                            </div>
                            <div>
                                <div className="text-2xl font-bold">{stats.active_rtw_plans}</div>
                                <div className="text-xs text-slate-500">Active RTW Plans</div>
                            </div>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="flex items-center gap-3 pt-6">
                            <div className="rounded-lg bg-red-50 p-2">
                                <CalendarX className="h-5 w-5 text-red-600" />
                            </div>
                            <div>
                                <div className="text-2xl font-bold">{stats.lost_days_30d}</div>
                                <div className="text-xs text-slate-500">Lost Days (30d)</div>
                            </div>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="flex items-center gap-3 pt-6">
                            <div className="rounded-lg bg-purple-50 p-2">
                                <Clock className="h-5 w-5 text-purple-600" />
                            </div>
                            <div>
                                <div className="text-2xl font-bold">{stats.pending_assessments}</div>
                                <div className="text-xs text-slate-500">Pending Assessments</div>
                            </div>
                        </CardContent>
                    </Card>
                </div>

                {/* Filters */}
                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Filters</CardTitle>
                    </CardHeader>
                    <CardContent className="grid grid-cols-1 gap-3 sm:grid-cols-3">
                        <div>
                            <Label className="text-xs text-slate-500">Search</Label>
                            <Input
                                placeholder="Worker name"
                                value={filters.q || ''}
                                onChange={(e) => onFilter({ q: e.target.value })}
                            />
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
                                    <SelectItem value="open">Open</SelectItem>
                                    <SelectItem value="active">Active</SelectItem>
                                    <SelectItem value="recovering">Recovering</SelectItem>
                                    <SelectItem value="returned_to_work">Returned to Work</SelectItem>
                                    <SelectItem value="closed">Closed</SelectItem>
                                </SelectContent>
                            </Select>
                        </div>
                        <div>
                            <Label className="text-xs text-slate-500">Severity</Label>
                            <Select
                                value={filters.severity ?? ANY}
                                onValueChange={(v) => onFilter({ severity: v === ANY ? null : v })}
                            >
                                <SelectTrigger><SelectValue placeholder="Severity" /></SelectTrigger>
                                <SelectContent>
                                    <SelectItem value={ANY}>Any</SelectItem>
                                    <SelectItem value="minor">Minor</SelectItem>
                                    <SelectItem value="moderate">Moderate</SelectItem>
                                    <SelectItem value="serious">Serious</SelectItem>
                                    <SelectItem value="critical">Critical</SelectItem>
                                </SelectContent>
                            </Select>
                        </div>
                    </CardContent>
                </Card>

                {/* Injuries Table */}
                <Card>
                    <CardContent className="pt-6">
                        <div className="overflow-x-auto">
                            <table className="w-full text-sm">
                                <thead>
                                    <tr className="border-b text-left text-xs text-slate-500">
                                        <th className="pb-2 pr-4 font-medium">Worker</th>
                                        <th className="pb-2 pr-4 font-medium">Date</th>
                                        <th className="pb-2 pr-4 font-medium">Type</th>
                                        <th className="pb-2 pr-4 font-medium">Body Part</th>
                                        <th className="pb-2 pr-4 font-medium">Severity</th>
                                        <th className="pb-2 pr-4 font-medium">Status</th>
                                        <th className="pb-2 pr-4 font-medium">Lost Days</th>
                                        <th className="pb-2 pr-4 font-medium">ACC Claim</th>
                                        <th className="pb-2 font-medium"></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {injuries.data.map((inj) => (
                                        <tr key={inj.id} className="border-b last:border-0">
                                            <td className="py-2 pr-4 font-medium">{inj.user?.name ?? 'Unknown'}</td>
                                            <td className="py-2 pr-4">
                                                {new Date(inj.injury_date).toLocaleDateString('en-GB')}
                                            </td>
                                            <td className="py-2 pr-4 capitalize">{inj.injury_type.replace(/_/g, ' ')}</td>
                                            <td className="py-2 pr-4">{inj.body_part_affected ?? '-'}</td>
                                            <td className="py-2 pr-4">
                                                <Badge className={severityColor(inj.severity)}>{inj.severity}</Badge>
                                            </td>
                                            <td className="py-2 pr-4">
                                                <Badge className={statusColor(inj.status)}>{inj.status}</Badge>
                                            </td>
                                            <td className="py-2 pr-4">{inj.lost_time_days}</td>
                                            <td className="py-2 pr-4">
                                                {inj.acc_claim_lodged ? (
                                                    <Badge className="bg-blue-100 text-blue-800">
                                                        {inj.acc_claim_number ?? 'Lodged'}
                                                    </Badge>
                                                ) : (
                                                    <span className="text-xs text-slate-400">No</span>
                                                )}
                                            </td>
                                            <td className="py-2">
                                                <Link
                                                    href={`/health-safety/injuries/${inj.id}`}
                                                    className="rounded-md border px-3 py-1.5 text-xs hover:bg-muted"
                                                >
                                                    View
                                                </Link>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                            {!injuries.data.length && (
                                <div className="py-4 text-center text-sm text-slate-500">
                                    No injuries found.
                                </div>
                            )}
                        </div>
                    </CardContent>
                </Card>

                {/* Pagination */}
                {injuries?.links?.length ? (
                    <div className="flex flex-wrap gap-2">
                        {injuries.links.map((l) => (
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
