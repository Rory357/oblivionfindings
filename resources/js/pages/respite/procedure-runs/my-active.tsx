import AppLayout from '@/layouts/app-layout';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import RespiteSubnav from '@/components/respite-subnav';
import { formatDateTime } from '@/lib/date-format';
import { Head, Link } from '@inertiajs/react';

type Props = {
    runs: any[];
};

const statusColors: Record<string, string> = {
    pending: 'bg-muted text-foreground',
    in_progress: 'bg-blue-100 text-blue-800',
    escalated: 'bg-orange-100 text-orange-800',
};

export default function MyActiveProcedureRuns({ runs }: Props) {
    return (
        <AppLayout breadcrumbs={[{ title: 'Respite', href: '/respite' }, { title: 'Procedure Runs', href: '/respite/procedure-runs' }, { title: 'My Active', href: '/respite/procedure-runs/my-active' }]}>
            <Head title="My Active Procedure Runs" />

            <div className="space-y-4">
                <div>
                    <h1 className="text-lg font-semibold">My Active Procedure Runs</h1>
                    <div className="mt-1 text-sm text-muted-foreground">Procedure runs currently assigned to you.</div>
                </div>
                <RespiteSubnav />

                <div className="space-y-2">
                    {runs.map((r: any) => (
                        <Card key={r.id}>
                            <CardHeader>
                                <CardTitle className="text-base">
                                    <div className="flex items-start justify-between gap-3">
                                        <div className="flex-1">
                                            <div className="font-semibold">{r.template?.name || 'Unknown Template'}</div>
                                            <div className="mt-2 flex flex-wrap gap-2">
                                                <Badge className={statusColors[r.status] || ''}>{r.status?.replace(/_/g, ' ')}</Badge>
                                            </div>
                                            <div className="mt-2 text-xs text-muted-foreground">
                                                Progress: {r.current_step || 0}/{r.total_steps || 0} steps
                                            </div>
                                            {r.sla_deadline && (
                                                <div className={`mt-1 text-xs ${r.sla_breached ? 'text-red-600 font-medium' : 'text-muted-foreground'}`}>
                                                    SLA Deadline: {formatDateTime(r.sla_deadline)}
                                                    {r.sla_breached && ' (BREACHED)'}
                                                </div>
                                            )}
                                        </div>
                                        <Link href={`/respite/procedure-runs/${r.id}`} className="rounded-md border px-3 py-2 text-xs hover:bg-muted">
                                            View
                                        </Link>
                                    </div>
                                </CardTitle>
                            </CardHeader>
                        </Card>
                    ))}
                    {!runs.length && (
                        <div className="py-8 text-center text-sm text-muted-foreground">No active procedure runs assigned to you.</div>
                    )}
                </div>
            </div>
        </AppLayout>
    );
}
