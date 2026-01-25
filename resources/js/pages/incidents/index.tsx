import AppLayout from '@/layouts/app-layout';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Head, Link, router, usePage } from '@inertiajs/react';

type Props = {
    filters: {
        q: string;
        status: string | null;
        severity: string | null;
        client_id: string | number | null;
        reviewed: string | null;
        from: string | null;
        to: string | null;
    };
    incidents: any;
    clients?: Array<{ id: number; first_name: string; last_name: string }> | null;
};

export default function IncidentsIndex({ filters, incidents, clients }: Props) {
    const ANY = '__any__';
    const { auth } = usePage().props as any;
    const can = auth?.can?.incidents ?? {};

    const onFilter = (next: Partial<typeof filters>) => {
        router.get('/incidents', { ...filters, ...next }, { preserveState: true, preserveScroll: true });
    };

    return (
        <AppLayout breadcrumbs={[{ title: 'Incidents', href: '/incidents' }]}>
            <Head title="Incidents" />

            <div className="space-y-4">
                <div className="flex items-start justify-between gap-3">
                    <div>
                        <h1 className="text-lg font-semibold">Incidents</h1>
                        <div className="mt-1 text-sm text-slate-500">
                            {can.viewAny ? 'All incidents' : 'Incidents for assigned clients'}
                        </div>
                    </div>

                    <div className="flex flex-wrap items-center gap-2">
                        {can.templatesManage && (
                            <Link
                                href="/incidents/templates"
                                className="rounded-md border px-3 py-2 text-xs hover:bg-muted"
                            >
                                Templates
                            </Link>
                        )}
                        {can.create && (
                            <Link href="/incidents/create">
                                <Button size="sm">New incident</Button>
                            </Link>
                        )}
                    </div>
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Filters</CardTitle>
                    </CardHeader>
                    <CardContent className="grid grid-cols-1 gap-3 sm:grid-cols-6">
                        <div className="sm:col-span-2">
                            <Label className="text-xs text-slate-500">Search</Label>
                            <Input
                                placeholder="Type / text"
                                value={filters.q || ''}
                                onChange={(e) => onFilter({ q: e.target.value })}
                            />
                        </div>

                        {clients?.length ? (
                            <div>
                                <Label className="text-xs text-slate-500">Client</Label>
                                <Select
                                    value={filters.client_id ? String(filters.client_id) : ANY}
                                    onValueChange={(v) => onFilter({ client_id: v === ANY ? null : v })}
                                >
                                    <SelectTrigger><SelectValue placeholder="Client" /></SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value={ANY}>Any</SelectItem>
                                        {clients.map((c) => (
                                            <SelectItem key={c.id} value={String(c.id)}>{c.first_name} {c.last_name}</SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                        ) : null}

                        <div>
                            <Label className="text-xs text-slate-500">Status</Label>
                            <Select
                                value={filters.status ?? ANY}
                                onValueChange={(v) => onFilter({ status: v === ANY ? null : v })}
                            >
                                <SelectTrigger><SelectValue placeholder="Status" /></SelectTrigger>
                                <SelectContent>
                                    <SelectItem value={ANY}>Any</SelectItem>
                                    {['draft', 'submitted', 'reviewed', 'closed'].map((s) => (
                                        <SelectItem key={s} value={s}>{s}</SelectItem>
                                    ))}
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
                                    {['low', 'medium', 'high'].map((s) => (
                                        <SelectItem key={s} value={s}>{s}</SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>

                        <div>
                            <Label className="text-xs text-slate-500">Reviewed</Label>
                            <Select
                                value={filters.reviewed ?? ANY}
                                onValueChange={(v) => onFilter({ reviewed: v === ANY ? null : v })}
                            >
                                <SelectTrigger><SelectValue placeholder="Reviewed?" /></SelectTrigger>
                                <SelectContent>
                                    <SelectItem value={ANY}>Any</SelectItem>
                                    <SelectItem value="yes">Yes</SelectItem>
                                    <SelectItem value="no">No</SelectItem>
                                </SelectContent>
                            </Select>
                        </div>

                        <div>
                            <Label className="text-xs text-slate-500">From</Label>
                            <Input type="date" value={filters.from ?? ''} onChange={(e) => onFilter({ from: e.target.value || null })} />
                        </div>

                        <div>
                            <Label className="text-xs text-slate-500">To</Label>
                            <Input type="date" value={filters.to ?? ''} onChange={(e) => onFilter({ to: e.target.value || null })} />
                        </div>
                    </CardContent>
                </Card>

                <div className="space-y-2">
                    {incidents.data.map((i: any) => (
                        <Card key={i.id}>
                            <CardHeader>
                                <CardTitle className="text-base">
                                    <div className="flex items-start justify-between gap-3">
                                        <div>
                                            <div className="font-semibold">{i.type} • {i.severity}</div>
                                            <div className="mt-1 text-xs text-slate-500">
                                                {i.client?.first_name} {i.client?.last_name} • {i.status}
                                                {i.shift_id ? <span className="ml-2">• Shift-linked</span> : <span className="ml-2">• Standalone</span>}
                                                {i.occurred_at ? <span className="ml-2">• {i.occurred_at}</span> : null}
                                            </div>
                                        </div>
                                        <Link href={`/incidents/${i.id}`} className="rounded-md border px-3 py-2 text-xs hover:bg-muted">
                                            Open
                                        </Link>
                                    </div>
                                </CardTitle>
                            </CardHeader>
                        </Card>
                    ))}
                    {!incidents.data.length && <div className="text-sm text-slate-500">No incidents found.</div>}
                </div>

                {incidents?.links?.length ? (
                    <div className="flex flex-wrap gap-2">
                        {incidents.links.map((l: any) => (
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
