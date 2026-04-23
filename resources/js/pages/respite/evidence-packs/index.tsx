import AppLayout from '@/layouts/app-layout';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import RespiteSubnav from '@/components/respite-subnav';
import { formatDateTime } from '@/lib/date-format';
import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';

type Props = {
    packs: { data: any[]; links: any[] };
    filters: any;
};

const statuses = ['draft', 'pending_review', 'complete', 'sealed'];

export default function EvidencePacksIndex({ packs, filters }: Props) {
    const ANY = '__any__';
    const [localFilters, setLocalFilters] = useState(filters || {});

    const applyFilter = (key: string, value: string) => {
        const updated = { ...localFilters, [key]: value };
        setLocalFilters(updated);
        router.get('/respite/evidence-packs', updated, { preserveState: true, preserveScroll: true });
    };

    return (
        <AppLayout breadcrumbs={[
            { title: 'Respite', href: '/respite' },
            { title: 'Evidence Packs', href: '/respite/evidence-packs' },
        ]}>
            <Head title="Evidence Packs" />

            <div className="space-y-4">
                <div className="flex items-start justify-between gap-3">
                    <div>
                        <h1 className="text-lg font-semibold">Evidence Packs</h1>
                        <div className="mt-1 text-sm text-slate-500">
                            Bundled evidence collections for respite stays.
                        </div>
                    </div>
                    <Link href="/respite/evidence-packs/create" className="rounded-md border px-3 py-2 text-xs hover:bg-muted">
                        New Pack
                    </Link>
                </div>
                <RespiteSubnav />

                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Filters</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div className="grid gap-4 sm:grid-cols-3">
                            <div>
                                <Label>Status</Label>
                                <Select value={localFilters.status || ANY} onValueChange={(v) => applyFilter('status', v === ANY ? '' : v)}>
                                    <SelectTrigger><SelectValue placeholder="All statuses" /></SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value={ANY}>All statuses</SelectItem>
                                        {statuses.map((s) => (
                                            <SelectItem key={s} value={s}>{s.replace(/_/g, ' ')}</SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                        </div>
                    </CardContent>
                </Card>

                <div className="space-y-2">
                    {packs.data.map((pack: any) => (
                        <Card key={pack.id}>
                            <CardHeader>
                                <CardTitle className="text-base">
                                    <div className="flex items-start justify-between gap-3">
                                        <div className="flex-1">
                                            <div className="font-semibold">{pack.title || `Evidence Pack #${pack.id}`}</div>
                                            {pack.description && (
                                                <div className="mt-1 text-xs font-normal text-slate-500">
                                                    {pack.description.length > 100 ? `${pack.description.substring(0, 100)}...` : pack.description}
                                                </div>
                                            )}
                                            <div className="mt-2 flex flex-wrap gap-2">
                                                <Badge variant="outline">{pack.status?.replace(/_/g, ' ')}</Badge>
                                                {pack.items_count != null && (
                                                    <Badge variant="outline">{pack.items_count} item{pack.items_count !== 1 ? 's' : ''}</Badge>
                                                )}
                                                {pack.sealed_at && <Badge variant="outline">Sealed</Badge>}
                                            </div>
                                            <div className="mt-2 text-xs text-slate-500">
                                                {formatDateTime(pack.created_at)}
                                            </div>
                                        </div>
                                        <Link href={`/respite/evidence-packs/${pack.id}`} className="rounded-md border px-3 py-2 text-xs hover:bg-muted">
                                            View
                                        </Link>
                                    </div>
                                </CardTitle>
                            </CardHeader>
                        </Card>
                    ))}
                    {!packs.data.length && (
                        <div className="py-8 text-center text-sm text-slate-500">
                            No items found.
                        </div>
                    )}
                </div>

                {packs?.links?.length ? (
                    <div className="flex flex-wrap gap-2">
                        {packs.links.map((l: any) => (
                            <Button
                                key={l.label}
                                variant="outline"
                                size="sm"
                                disabled={!l.url}
                                className={l.active ? 'bg-muted' : ''}
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
