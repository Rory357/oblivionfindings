import AppLayout from '@/layouts/app-layout';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Button } from '@/components/ui/button';
import { useMemo } from 'react';

type Site = { id: number; name: string };
type Client = { id: number; first_name: string; last_name: string; site_id?: number | null };
type Asset = {
    id: number;
    name: string;
    asset_tag?: string | null;
    status: string;
    risk_level: string;
    category?: string | null;
    site?: Site | null;
    client?: { id: number; first_name: string; last_name: string; site_id?: number | null } | null;
    inspection_due_at?: string | null;
    maintenance_due_at?: string | null;
};

export default function AssetsIndex() {
    const { assets, sites, clients, filters, can } = usePage().props as any;

    const siteOptions: Site[] = sites ?? [];
    const clientOptions: Client[] = clients ?? [];

    const current = {
        site_id: filters?.site_id ?? '',
        client_id: filters?.client_id ?? '',
        status: filters?.status ?? 'all',
        risk: filters?.risk ?? 'all',
        search: filters?.search ?? '',
    };

    function apply(next: any) {
        router.get('/assets', { ...current, ...next }, { preserveState: true, preserveScroll: true });
    }

    const rows: Asset[] = assets?.data ?? [];

    const selectedSiteId = current.site_id ? Number(current.site_id) : null;

    const filteredClients = useMemo(() => {
        if (!selectedSiteId) return clientOptions;
        return clientOptions.filter((c) => c.site_id === selectedSiteId);
    }, [clientOptions, selectedSiteId]);

    return (
        <AppLayout breadcrumbs={[{ title: 'Assets', href: '/assets' }]}>
            <Head title="Assets" />
            <div className="space-y-4 p-4">
                <div className="flex items-center justify-between">
                    <div>
                        <h1 className="text-xl font-semibold">Assets</h1>
                        <p className="text-sm text-muted-foreground">Site + client assets, inspections, maintenance, documents.</p>
                    </div>
                    {can?.create ? (
                        <Link href="/assets/create">
                            <Button>Create Asset</Button>
                        </Link>
                    ) : null}
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Filters</CardTitle>
                    </CardHeader>
                    <CardContent className="grid grid-cols-1 gap-3 md:grid-cols-5">
                        <div className="space-y-1">
                            <Label>Site</Label>
                            <Select
                                value={current.site_id ? String(current.site_id) : 'all'}
                                onValueChange={(v) => apply({ site_id: v === 'all' ? '' : v, client_id: '' })}
                            >
                                <SelectTrigger>
                                    <SelectValue placeholder="All sites" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all">All</SelectItem>
                                    {siteOptions.map((s) => (
                                        <SelectItem key={s.id} value={String(s.id)}>
                                            {s.name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>

                        <div className="space-y-1">
                            <Label>Client</Label>
                            <Select
                                value={current.client_id ? String(current.client_id) : 'all'}
                                onValueChange={(v) => apply({ client_id: v === 'all' ? '' : v })}
                            >
                                <SelectTrigger>
                                    <SelectValue placeholder="All clients" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all">All</SelectItem>
                                    {filteredClients.map((c) => (
                                        <SelectItem key={c.id} value={String(c.id)}>
                                            {c.first_name} {c.last_name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>

                        <div className="space-y-1">
                            <Label>Status</Label>
                            <Select value={current.status} onValueChange={(v) => apply({ status: v })}>
                                <SelectTrigger>
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all">All</SelectItem>
                                    <SelectItem value="active">Active</SelectItem>
                                    <SelectItem value="out_of_service">Out of service</SelectItem>
                                    <SelectItem value="retired">Retired</SelectItem>
                                </SelectContent>
                            </Select>
                        </div>

                        <div className="space-y-1">
                            <Label>Risk</Label>
                            <Select value={current.risk} onValueChange={(v) => apply({ risk: v })}>
                                <SelectTrigger>
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all">All</SelectItem>
                                    <SelectItem value="low">Low</SelectItem>
                                    <SelectItem value="medium">Medium</SelectItem>
                                    <SelectItem value="high">High</SelectItem>
                                </SelectContent>
                            </Select>
                        </div>

                        <div className="space-y-1">
                            <Label>Search</Label>
                            <Input
                                value={current.search}
                                placeholder="Name, tag, serial..."
                                onChange={(e) => apply({ search: e.target.value })}
                            />
                        </div>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Results</CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-2">
                        {rows.length ? (
                            rows.map((a) => (
                                <div key={a.id} className="flex items-start justify-between gap-3 rounded-md border p-3">
                                    <div className="min-w-0">
                                        <div className="flex flex-wrap items-center gap-2">
                                            <Link href={`/assets/${a.id}`} className="truncate text-sm font-medium hover:underline">
                                                {a.name}
                                            </Link>
                                            {a.asset_tag ? (
                                                <span className="rounded bg-muted px-2 py-0.5 text-xs text-foreground">#{a.asset_tag}</span>
                                            ) : null}
                                            <span className="rounded bg-muted px-2 py-0.5 text-xs text-foreground">{a.status}</span>
                                            <span className="rounded bg-muted px-2 py-0.5 text-xs text-foreground">{a.risk_level}</span>
                                            {a.category ? (
                                                <span className="rounded bg-muted px-2 py-0.5 text-xs text-foreground">{a.category}</span>
                                            ) : null}
                                        </div>
                                        <div className="mt-1 text-xs text-muted-foreground">
                                            {a.site ? `Site: ${a.site.name}` : 'Site: —'}
                                            {a.client ? ` • Client: ${a.client.first_name} ${a.client.last_name}` : ''}
                                        </div>
                                    </div>
                                    <div className="shrink-0 text-right text-xs text-muted-foreground">
                                        {a.inspection_due_at ? <div>Inspection: {a.inspection_due_at}</div> : null}
                                        {a.maintenance_due_at ? <div>Maintenance: {a.maintenance_due_at}</div> : null}
                                    </div>
                                </div>
                            ))
                        ) : (
                            <div className="text-sm text-muted-foreground">No assets found.</div>
                        )}

                        {assets?.links ? (
                            <div className="flex flex-wrap gap-2 pt-2">
                                {assets.links.map((l: any, idx: number) => (
                                    <button
                                        key={idx}
                                        disabled={!l.url}
                                        onClick={() => l.url && router.get(l.url, {}, { preserveScroll: true, preserveState: true })}
                                        className={`rounded border px-3 py-1 text-sm ${l.active ? 'bg-muted' : 'hover:bg-muted'} ${!l.url ? 'opacity-50' : ''}`}
                                        dangerouslySetInnerHTML={{ __html: l.label }}
                                    />
                                ))}
                            </div>
                        ) : null}
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
