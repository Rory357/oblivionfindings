import AppLayout from '@/layouts/app-layout';
import { PageHero, PageLayout } from '@/components/page';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Badge } from '@/components/ui/badge';
import RespiteSubnav from '@/components/respite-subnav';
import { formatDateTimeLong } from '@/lib/datetime';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { Inbox, Plus } from 'lucide-react';

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

            <PageLayout
                hero={
                    <PageHero
                        icon={Inbox}
                        title="Booking Requests"
                        description="Requests are reviewed and approved before bookings are created."
                        stats={
                            stats
                                ? [
                                      { label: 'Submitted', value: stats.submitted },
                                      { label: 'Approved', value: stats.approved },
                                      { label: 'Rejected', value: stats.rejected },
                                  ]
                                : [
                                      { label: 'Total', value: requests.data.length },
                                  ]
                        }
                        actions={
                            can.create ? (
                                <Link href="/respite/requests/create">
                                    <Button size="sm" variant="outline" className="border-primary-foreground/30 bg-primary-foreground/10 text-primary-foreground backdrop-blur-sm hover:bg-primary-foreground/20 hover:text-primary-foreground">
                                        <Plus className="mr-1.5 h-4 w-4" />
                                        New Request
                                    </Button>
                                </Link>
                            ) : null
                        }
                    />
                }
            >
                <RespiteSubnav />

                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Filters</CardTitle>
                    </CardHeader>
                    <CardContent className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                        <div>
                            <Label className="text-xs text-muted-foreground">Search</Label>
                            <Input
                                placeholder="Search notes or funding reference"
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
                                            <div className="mt-2 text-xs text-muted-foreground">
                                                Requested: {formatDateTimeLong(r.requested_start)} → {formatDateTimeLong(r.requested_end)}
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
                        <div className="py-8 text-center text-sm text-muted-foreground">
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
            </PageLayout>
        </AppLayout>
    );
}
