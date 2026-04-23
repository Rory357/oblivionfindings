import AppLayout from '@/layouts/app-layout';
import FleetHero from '@/components/fleet-hero';
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
            return 'bg-muted text-foreground';
        default:
            return 'bg-muted text-foreground';
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
            return 'bg-muted text-foreground';
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

            <div className="flex flex-col gap-6 p-6">
                {/* Hero Header */}
                <FleetHero
                    title="Injuries & Return to Work"
                    description="Track workplace injuries, RTW plans, and capacity assessments"
                    icon={<HeartPulse className="h-7 w-7 text-white" />}
                    stats={[
                        { label: 'Active Injuries', value: stats.active_injuries },
                        { label: 'RTW Plans', value: stats.active_rtw_plans },
                        { label: 'Lost Days (30d)', value: stats.lost_days_30d },
                        { label: 'Pending', value: stats.pending_assessments },
                    ]}
                    actions={
                        <Link href="/health-safety/injuries/create">
                            <Button size="sm">Record Injury</Button>
                        </Link>
                    }
                />

                {/* Filters */}
                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Filters</CardTitle>
                    </CardHeader>
                    <CardContent className="grid grid-cols-1 gap-3 sm:grid-cols-3">
                        <div>
                            <Label className="text-xs text-muted-foreground">Search</Label>
                            <Input
                                placeholder="Worker name"
                                value={filters.q || ''}
                                onChange={(e) => onFilter({ q: e.target.value })}
                            />
                        </div>
                        <div>
                            <Label className="text-xs text-muted-foreground">Status</Label>
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
                            <Label className="text-xs text-muted-foreground">Severity</Label>
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
                                    <tr className="border-b text-left text-xs text-muted-foreground">
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
                                                    <span className="text-xs text-muted-foreground">No</span>
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
                                <div className="py-4 text-center text-sm text-muted-foreground">
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
