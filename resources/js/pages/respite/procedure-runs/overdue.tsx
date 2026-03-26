import AppLayout from '@/layouts/app-layout';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import RespiteSubnav from '@/components/respite-subnav';
import { formatDateTime } from '@/lib/date-format';
import { Head, Link, router } from '@inertiajs/react';

type Props = {
    runs: { data: any[]; links: any[] };
};

export default function OverdueProcedureRuns({ runs }: Props) {
    return (
        <AppLayout breadcrumbs={[{ title: 'Respite', href: '/respite' }, { title: 'Procedure Runs', href: '/respite/procedure-runs' }, { title: 'Overdue', href: '/respite/procedure-runs/overdue' }]}>
            <Head title="Overdue Procedure Runs" />

            <div className="space-y-4">
                <div className="flex items-center gap-3">
                    <h1 className="text-lg font-semibold">Overdue Procedure Runs</h1>
                    <Badge className="bg-red-100 text-red-800">{runs.data.length}</Badge>
                </div>
                <div className="text-sm text-slate-500">Procedure runs that have breached their SLA deadline.</div>
                <RespiteSubnav />

                <div className="space-y-2">
                    {runs.data.map((r: any) => (
                        <Card key={r.id} className="border-red-200">
                            <CardHeader>
                                <CardTitle className="text-base">
                                    <div className="flex items-start justify-between gap-3">
                                        <div className="flex-1">
                                            <div className="font-semibold">{r.template?.name || 'Unknown Template'}</div>
                                            <div className="mt-2 flex flex-wrap gap-2">
                                                <Badge className="bg-red-100 text-red-800">{r.status?.replace(/_/g, ' ')}</Badge>
                                                <Badge className="bg-red-100 text-red-800">SLA Breached</Badge>
                                            </div>
                                            <div className="mt-2 text-xs text-slate-500">
                                                Progress: {r.current_step || 0}/{r.total_steps || 0} steps
                                            </div>
                                            <div className="mt-1 text-xs text-red-600 font-medium">
                                                SLA Deadline: {formatDateTime(r.sla_deadline)}
                                            </div>
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
                        <div className="py-8 text-center text-sm text-slate-500">No overdue procedure runs.</div>
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
