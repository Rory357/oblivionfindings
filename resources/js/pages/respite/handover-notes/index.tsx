import AppLayout from '@/layouts/app-layout';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import RespiteSubnav from '@/components/respite-subnav';
import { formatDateTime } from '@/lib/date-format';
import { Head, Link, router } from '@inertiajs/react';

type Props = {
    notes: { data: any[]; links: any[] };
    filters: any;
};

export default function HandoverNotesIndex({ notes, filters }: Props) {
    const ANY = '__any__';

    const onFilter = (next: Record<string, any>) => {
        router.get('/respite/handover-notes', { ...filters, ...next }, { preserveState: true, preserveScroll: true });
    };

    return (
        <AppLayout breadcrumbs={[{ title: 'Respite', href: '/respite' }, { title: 'Handover Notes', href: '/respite/handover-notes' }]}>
            <Head title="Handover Notes" />

            <div className="space-y-4">
                <div className="flex items-start justify-between gap-3">
                    <div>
                        <h1 className="text-lg font-semibold">Handover Notes</h1>
                        <div className="mt-1 text-sm text-slate-500">Shift handover notes for respite stays.</div>
                    </div>
                    <Link href="/respite/handover-notes/create">
                        <Button size="sm">New Handover Note</Button>
                    </Link>
                </div>
                <RespiteSubnav />

                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Filters</CardTitle>
                    </CardHeader>
                    <CardContent className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                        <div>
                            <Label className="text-xs text-slate-500">Handover Type</Label>
                            <Select value={filters.handover_type ?? ANY} onValueChange={(v) => onFilter({ handover_type: v === ANY ? null : v })}>
                                <SelectTrigger><SelectValue placeholder="Type" /></SelectTrigger>
                                <SelectContent>
                                    <SelectItem value={ANY}>Any</SelectItem>
                                    {['shift_start', 'shift_end', 'critical', 'medication', 'behaviour', 'general'].map((t) => (
                                        <SelectItem key={t} value={t}>{t.replace(/_/g, ' ')}</SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                        <div className="flex items-end gap-2">
                            <label className="flex items-center gap-2 text-sm">
                                <input
                                    type="checkbox"
                                    checked={!!filters.unacknowledged}
                                    onChange={(e) => onFilter({ unacknowledged: e.target.checked ? '1' : null })}
                                />
                                Unacknowledged only
                            </label>
                        </div>
                    </CardContent>
                </Card>

                <div className="space-y-2">
                    {notes.data.map((n: any) => (
                        <Card key={n.id}>
                            <CardHeader>
                                <CardTitle className="text-base">
                                    <div className="flex items-start justify-between gap-3">
                                        <div className="flex-1">
                                            <div className="font-semibold">
                                                {n.stay?.client?.first_name} {n.stay?.client?.last_name}
                                            </div>
                                            <div className="mt-2 flex flex-wrap gap-2">
                                                <Badge variant="outline">{n.handover_type?.replace(/_/g, ' ')}</Badge>
                                                {!n.acknowledged_at && <Badge className="bg-amber-100 text-amber-800">Unacknowledged</Badge>}
                                                {n.acknowledged_at && <Badge className="bg-green-100 text-green-800">Acknowledged</Badge>}
                                            </div>
                                            <div className="mt-2 text-xs text-slate-500 line-clamp-2">{n.notes}</div>
                                            <div className="mt-1 text-xs text-slate-400">{formatDateTime(n.created_at)}</div>
                                        </div>
                                        <Link href={`/respite/handover-notes/${n.id}`} className="rounded-md border px-3 py-2 text-xs hover:bg-muted">
                                            View
                                        </Link>
                                    </div>
                                </CardTitle>
                            </CardHeader>
                        </Card>
                    ))}
                    {!notes.data.length && (
                        <div className="py-8 text-center text-sm text-slate-500">No handover notes found.</div>
                    )}
                </div>

                {notes?.links?.length ? (
                    <div className="flex flex-wrap gap-2">
                        {notes.links.map((l: any) => (
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
