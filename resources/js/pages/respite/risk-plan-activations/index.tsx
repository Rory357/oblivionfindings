import AppLayout from '@/layouts/app-layout';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import RespiteSubnav from '@/components/respite-subnav';
import { formatDateTime } from '@/lib/date-format';
import { Head, Link, router } from '@inertiajs/react';

type Props = {
    activations: { data: any[]; links: any[] };
    filters: any;
    planTypes: Record<string, string>;
    statuses: Record<string, string>;
};

const statusColors: Record<string, string> = {
    pending_review: 'bg-amber-100 text-amber-800',
    active: 'bg-green-100 text-green-800',
    modified: 'bg-blue-100 text-blue-800',
    suspended: 'bg-slate-100 text-slate-600',
    completed: 'bg-slate-100 text-slate-800',
};

const typeColors: Record<string, string> = {
    behaviour: 'bg-purple-100 text-purple-800',
    safety: 'bg-red-100 text-red-800',
    medical: 'bg-blue-100 text-blue-800',
    mobility: 'bg-orange-100 text-orange-800',
    communication: 'bg-teal-100 text-teal-800',
};

export default function RiskPlanActivationsIndex({ activations, filters, planTypes, statuses }: Props) {
    const ANY = '__any__';

    const onFilter = (next: Record<string, any>) => {
        router.get('/respite/risk-plan-activations', { ...filters, ...next }, { preserveState: true, preserveScroll: true });
    };

    return (
        <AppLayout breadcrumbs={[{ title: 'Respite', href: '/respite' }, { title: 'Risk Plan Activations', href: '/respite/risk-plan-activations' }]}>
            <Head title="Risk Plan Activations" />

            <div className="space-y-4">
                <div className="flex items-start justify-between gap-3">
                    <div>
                        <h1 className="text-lg font-semibold">Risk Plan Activations</h1>
                        <div className="mt-1 text-sm text-slate-500">Activated risk plans for respite stays.</div>
                    </div>
                    <Link href="/respite/risk-plan-activations/create">
                        <Button size="sm">New Activation</Button>
                    </Link>
                </div>
                <RespiteSubnav />

                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Filters</CardTitle>
                    </CardHeader>
                    <CardContent className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                        <div>
                            <Label className="text-xs text-slate-500">Plan Type</Label>
                            <Select value={filters.plan_type ?? ANY} onValueChange={(v) => onFilter({ plan_type: v === ANY ? null : v })}>
                                <SelectTrigger><SelectValue placeholder="Type" /></SelectTrigger>
                                <SelectContent>
                                    <SelectItem value={ANY}>Any</SelectItem>
                                    {Object.entries(planTypes).map(([value, label]) => (
                                        <SelectItem key={value} value={value}>{label}</SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                        <div>
                            <Label className="text-xs text-slate-500">Status</Label>
                            <Select value={filters.status ?? ANY} onValueChange={(v) => onFilter({ status: v === ANY ? null : v })}>
                                <SelectTrigger><SelectValue placeholder="Status" /></SelectTrigger>
                                <SelectContent>
                                    <SelectItem value={ANY}>Any</SelectItem>
                                    {Object.entries(statuses).map(([value, label]) => (
                                        <SelectItem key={value} value={value}>{label}</SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                    </CardContent>
                </Card>

                <div className="space-y-2">
                    {activations.data.map((a: any) => (
                        <Card key={a.id}>
                            <CardHeader>
                                <CardTitle className="text-base">
                                    <div className="flex items-start justify-between gap-3">
                                        <div className="flex-1">
                                            <div className="font-semibold">{a.plan_name}</div>
                                            <div className="mt-2 flex flex-wrap gap-2">
                                                <Badge className={typeColors[a.plan_type] || 'bg-slate-100 text-slate-800'}>{a.plan_type?.replace(/_/g, ' ')}</Badge>
                                                <Badge className={statusColors[a.status] || ''}>{a.status?.replace(/_/g, ' ')}</Badge>
                                            </div>
                                            <div className="mt-2 text-xs text-slate-500">
                                                Client: {a.stay?.client?.first_name} {a.stay?.client?.last_name}
                                            </div>
                                            {a.stay && (
                                                <div className="mt-1 text-xs text-slate-400">Stay #{a.stay.id}</div>
                                            )}
                                            <div className="mt-1 text-xs text-slate-400">{formatDateTime(a.created_at)}</div>
                                        </div>
                                        <Link href={`/respite/risk-plan-activations/${a.id}`} className="rounded-md border px-3 py-2 text-xs hover:bg-muted">
                                            View
                                        </Link>
                                    </div>
                                </CardTitle>
                            </CardHeader>
                        </Card>
                    ))}
                    {!activations.data.length && (
                        <div className="py-8 text-center text-sm text-slate-500">No risk plan activations found.</div>
                    )}
                </div>

                {activations?.links?.length ? (
                    <div className="flex flex-wrap gap-2">
                        {activations.links.map((l: any) => (
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
