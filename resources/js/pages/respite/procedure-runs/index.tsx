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
    runs: { data: any[]; links: any[] };
    templates: any[];
    filters: any;
};

const statusColors: Record<string, string> = {
    pending: 'bg-slate-100 text-slate-800',
    in_progress: 'bg-blue-100 text-blue-800',
    completed: 'bg-green-100 text-green-800',
    failed: 'bg-red-100 text-red-800',
    cancelled: 'bg-slate-100 text-slate-600',
    escalated: 'bg-orange-100 text-orange-800',
};

export default function ProcedureRunsIndex({ runs, templates, filters }: Props) {
    const ANY = '__any__';

    const onFilter = (next: Record<string, any>) => {
        router.get('/respite/procedure-runs', { ...filters, ...next }, { preserveState: true, preserveScroll: true });
    };

    return (
        <AppLayout breadcrumbs={[{ title: 'Respite', href: '/respite' }, { title: 'Procedure Runs', href: '/respite/procedure-runs' }]}>
            <Head title="Procedure Runs" />

            <div className="space-y-4">
                <div>
                    <h1 className="text-lg font-semibold">Procedure Runs</h1>
                    <div className="mt-1 text-sm text-slate-500">Track execution of procedure templates.</div>
                </div>
                <RespiteSubnav />

                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Filters</CardTitle>
                    </CardHeader>
                    <CardContent className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                        <div>
                            <Label className="text-xs text-slate-500">Status</Label>
                            <Select value={filters.status ?? ANY} onValueChange={(v) => onFilter({ status: v === ANY ? null : v })}>
                                <SelectTrigger><SelectValue placeholder="Status" /></SelectTrigger>
                                <SelectContent>
                                    <SelectItem value={ANY}>Any</SelectItem>
                                    {['pending', 'in_progress', 'completed', 'failed', 'cancelled', 'escalated'].map((s) => (
                                        <SelectItem key={s} value={s}>{s.replace(/_/g, ' ')}</SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                        <div>
                            <Label className="text-xs text-slate-500">Template</Label>
                            <Select value={filters.template_id ?? ANY} onValueChange={(v) => onFilter({ template_id: v === ANY ? null : v })}>
                                <SelectTrigger><SelectValue placeholder="Template" /></SelectTrigger>
                                <SelectContent>
                                    <SelectItem value={ANY}>Any</SelectItem>
                                    {templates.map((t: any) => (
                                        <SelectItem key={t.id} value={String(t.id)}>{t.name}</SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                    </CardContent>
                </Card>

                <div className="space-y-2">
                    {runs.data.map((r: any) => (
                        <Card key={r.id}>
                            <CardHeader>
                                <CardTitle className="text-base">
                                    <div className="flex items-start justify-between gap-3">
                                        <div className="flex-1">
                                            <div className="font-semibold">{r.template?.name || 'Unknown Template'}</div>
                                            <div className="mt-2 flex flex-wrap gap-2">
                                                <Badge className={statusColors[r.status] || ''}>{r.status?.replace(/_/g, ' ')}</Badge>
                                            </div>
                                            <div className="mt-2 text-xs text-slate-500">
                                                Progress: {r.current_step || 0}/{r.total_steps || 0} steps
                                            </div>
                                            {r.sla_deadline && (
                                                <div className="mt-1 text-xs text-slate-500">
                                                    SLA Deadline: {formatDateTime(r.sla_deadline)}
                                                </div>
                                            )}
                                            <div className="mt-1 text-xs text-slate-400">
                                                Initiated by: {r.initiated_by?.name || 'Unknown'}
                                            </div>
                                        </div>
                                        <Link href={`/respite/procedure-runs/${r.id}`} className="rounded-md border px-3 py-2 text-xs hover:bg-muted">
                                            View
                                        </Link>
                                    </div>
                                </CardTitle>
                            </CardHeader>
                        </Card>
                    ))}
                    {!runs.data.length && (
                        <div className="py-8 text-center text-sm text-slate-500">No procedure runs found.</div>
                    )}
                </div>

                {runs?.links?.length ? (
                    <div className="flex flex-wrap gap-2">
                        {runs.links.map((l: any) => (
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
