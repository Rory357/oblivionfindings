import AppLayout from '@/layouts/app-layout';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Badge } from '@/components/ui/badge';
import RespiteSubnav from '@/components/respite-subnav';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { CalendarDays, Plus } from 'lucide-react';

type Props = {
    referrals: any;
    filters: {
        status?: string | null;
        urgency?: string | null;
        q?: string | null;
    };
    stats?: {
        received: number;
        triaged: number;
        accepted: number;
    };
};

export default function RespiteIndex({ referrals, filters, stats }: Props) {
    const { auth, labels } = usePage().props as any;
    const can = auth?.can?.respite ?? {};
    const label = labels?.['respite.plural'] ?? 'Respite';
    const ANY = '__any__';

    const onFilter = (next: Partial<Props['filters']>) => {
        router.get('/respite', { ...filters, ...next }, { preserveState: true, preserveScroll: true });
    };

    return (
        <AppLayout breadcrumbs={[{ title: label, href: '/respite' }]}>
            <Head title={label} />

            <div className="space-y-4">
                <div className="flex items-start justify-between gap-3">
                    <div>
                        <h1 className="text-lg font-semibold">{label} Referrals</h1>
                        <div className="mt-1 text-sm text-slate-500">
                            Referrals start the intake. Booking requests are reviewed and approved before creating bookings.
                        </div>
                    </div>
                    {can.create && (
                        <Link href="/respite/referrals/create">
                            <Button size="sm">
                                <Plus className="mr-1.5 h-4 w-4" />
                                New Referral
                            </Button>
                        </Link>
                    )}
                </div>

                <RespiteSubnav />

                {stats && (
                    <div className="grid gap-4 sm:grid-cols-3">
                        <Card>
                            <CardHeader className="pb-3">
                                <CardTitle className="text-sm font-medium text-slate-500">Received</CardTitle>
                            </CardHeader>
                            <CardContent>
                                <div className="text-2xl font-bold">{stats.received}</div>
                            </CardContent>
                        </Card>
                        <Card>
                            <CardHeader className="pb-3">
                                <CardTitle className="text-sm font-medium text-slate-500">Triaged</CardTitle>
                            </CardHeader>
                            <CardContent>
                                <div className="text-2xl font-bold">{stats.triaged}</div>
                            </CardContent>
                        </Card>
                        <Card>
                            <CardHeader className="pb-3">
                                <CardTitle className="text-sm font-medium text-slate-500">Accepted</CardTitle>
                            </CardHeader>
                            <CardContent>
                                <div className="text-2xl font-bold">{stats.accepted}</div>
                            </CardContent>
                        </Card>
                    </div>
                )}

                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Filters</CardTitle>
                    </CardHeader>
                    <CardContent className="grid grid-cols-1 gap-3 sm:grid-cols-3">
                        <div>
                            <Label className="text-xs text-slate-500">Search</Label>
                            <Input
                                placeholder="Search referrer or reason"
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
                                    {['received', 'triaged', 'accepted', 'declined'].map((s) => (
                                        <SelectItem key={s} value={s}>{s}</SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                        <div>
                            <Label className="text-xs text-slate-500">Urgency</Label>
                            <Select
                                value={filters.urgency ?? ANY}
                                onValueChange={(v) => onFilter({ urgency: v === ANY ? null : v })}
                            >
                                <SelectTrigger><SelectValue placeholder="Urgency" /></SelectTrigger>
                                <SelectContent>
                                    <SelectItem value={ANY}>Any</SelectItem>
                                    {['planned', 'urgent', 'crisis'].map((s) => (
                                        <SelectItem key={s} value={s}>{s}</SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                    </CardContent>
                </Card>

                <div className="space-y-2">
                    {referrals.data.map((ref: any) => (
                        <Card key={ref.id}>
                            <CardHeader>
                                <CardTitle className="text-base">
                                    <div className="flex items-start justify-between gap-3">
                                        <div className="flex-1">
                                            <div className="flex items-center gap-2 font-semibold">
                                                <CalendarDays className="h-4 w-4 text-slate-500" />
                                                {ref.client?.first_name} {ref.client?.last_name}
                                            </div>
                                            <div className="mt-2 flex flex-wrap gap-2">
                                                <Badge variant="outline">{ref.status}</Badge>
                                                <Badge variant="outline">{ref.urgency}</Badge>
                                            </div>
                                            <div className="mt-2 text-xs text-slate-500">
                                                Referrer: {ref.referrer_name}
                                            </div>
                                        </div>
                                        <Link href={`/respite/referrals/${ref.id}`} className="rounded-md border px-3 py-2 text-xs hover:bg-muted">
                                            View
                                        </Link>
                                    </div>
                                </CardTitle>
                            </CardHeader>
                        </Card>
                    ))}
                    {!referrals.data.length && (
                        <div className="py-8 text-center text-sm text-slate-500">
                            No respite referrals found.
                        </div>
                    )}
                </div>

                {referrals?.links?.length ? (
                    <div className="flex flex-wrap gap-2">
                        {referrals.links.map((l: any) => (
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
