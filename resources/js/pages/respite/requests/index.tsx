import AppLayout from '@/layouts/app-layout';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Badge } from '@/components/ui/badge';
import RespiteSubnav from '@/components/respite-subnav';
import { formatDateTime } from '@/lib/date-format';
import { Head, Link, router, usePage } from '@inertiajs/react';

type Props = {
    requests: any;
    filters: {
        status?: string | null;
        q?: string | null;
    };
    stats?: {
        submitted: number;
        approved: number;
        rejected: number;
    };
};

export default function RespiteRequestsIndex({ requests, filters, stats }: Props) {
    const { auth } = usePage().props as any;
    const can = auth?.can?.respite ?? {};
    const ANY = '__any__';

    const onFilter = (next: Partial<Props['filters']>) => {
        router.get('/respite/requests', { ...filters, ...next }, { preserveState: true, preserveScroll: true });
    };

    return (
        <AppLayout breadcrumbs={[
            { title: 'Respite', href: '/respite' },
            { title: 'Booking Requests', href: '/respite/requests' },
        ]}>
            <Head title="Respite Booking Requests" />

            <div className="space-y-4">
                <div className="flex items-start justify-between gap-3">
                    <div>
                        <h1 className="text-lg font-semibold">Booking Requests</h1>
                        <div className="mt-1 text-sm text-slate-500">
                            Requests are reviewed and approved before bookings are created.
                        </div>
                    </div>
                    {can.create && (
                        <Link href="/respite/requests/create">
                            <Button size="sm">New Request</Button>
                        </Link>
                    )}
                </div>
                <RespiteSubnav />

                {stats && (
                    <div className="grid gap-4 sm:grid-cols-3">
                        <Card>
                            <CardHeader className="pb-3">
                                <CardTitle className="text-sm font-medium text-slate-500">Submitted</CardTitle>
                            </CardHeader>
                            <CardContent>
                                <div className="text-2xl font-bold">{stats.submitted}</div>
                            </CardContent>
                        </Card>
                        <Card>
                            <CardHeader className="pb-3">
                                <CardTitle className="text-sm font-medium text-slate-500">Approved</CardTitle>
                            </CardHeader>
                            <CardContent>
                                <div className="text-2xl font-bold">{stats.approved}</div>
                            </CardContent>
                        </Card>
                        <Card>
                            <CardHeader className="pb-3">
                                <CardTitle className="text-sm font-medium text-slate-500">Rejected</CardTitle>
                            </CardHeader>
                            <CardContent>
                                <div className="text-2xl font-bold">{stats.rejected}</div>
                            </CardContent>
                        </Card>
                    </div>
                )}

                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Filters</CardTitle>
                    </CardHeader>
                    <CardContent className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                        <div>
                            <Label className="text-xs text-slate-500">Search</Label>
                            <Input
                                placeholder="Search notes or funding reference"
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
                                    {['draft', 'submitted', 'under_review', 'approved', 'rejected', 'waitlisted'].map((s) => (
                                        <SelectItem key={s} value={s}>{s.replace(/_/g, ' ')}</SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                    </CardContent>
                </Card>

                <div className="space-y-2">
                    {requests.data.map((r: any) => (
                        <Card key={r.id}>
                            <CardHeader>
                                <CardTitle className="text-base">
                                    <div className="flex items-start justify-between gap-3">
                                        <div className="flex-1">
                                            <div className="font-semibold">
                                                {r.client?.first_name} {r.client?.last_name}
                                            </div>
                                            <div className="mt-2 flex flex-wrap gap-2">
                                                <Badge variant="outline">{r.status}</Badge>
                                                {r.funding_reference && (
                                                    <Badge variant="outline">Funding: {r.funding_reference}</Badge>
                                                )}
                                            </div>
                                            <div className="mt-2 text-xs text-slate-500">
                                                Requested: {formatDateTime(r.requested_start)} → {formatDateTime(r.requested_end)}
                                            </div>
                                        </div>
                                        <Link href={`/respite/requests/${r.id}`} className="rounded-md border px-3 py-2 text-xs hover:bg-muted">
                                            View
                                        </Link>
                                    </div>
                                </CardTitle>
                            </CardHeader>
                        </Card>
                    ))}
                    {!requests.data.length && (
                        <div className="py-8 text-center text-sm text-slate-500">
                            No booking requests found.
                        </div>
                    )}
                </div>

                {requests?.links?.length ? (
                    <div className="flex flex-wrap gap-2">
                        {requests.links.map((l: any) => (
                            <Button
                                key={l.label}
                                variant="outline"
                                size="sm"
                                disabled={!l.url}
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
