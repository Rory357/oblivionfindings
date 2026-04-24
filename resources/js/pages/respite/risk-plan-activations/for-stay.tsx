import AppLayout from '@/layouts/app-layout';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import RespiteSubnav from '@/components/respite-subnav';
import { formatDateTime } from '@/lib/date-format';
import { Head, Link } from '@inertiajs/react';

type Props = {
    stay: any;
    activations: any[];
    planTypes: string[];
};

const statusColors: Record<string, string> = {
    pending_review: 'bg-status-warning-bg text-status-warning',
    active: 'bg-status-success-bg text-status-success',
    modified: 'bg-status-info-bg text-status-info',
    suspended: 'bg-muted text-muted-foreground',
    completed: 'bg-muted text-foreground',
};

const typeColors: Record<string, string> = {
    behaviour: 'bg-primary/10 text-primary',
    safety: 'bg-status-critical-bg text-status-critical',
    medical: 'bg-status-info-bg text-status-info',
    mobility: 'bg-status-warning-bg text-status-warning',
    communication: 'bg-status-info-bg text-status-info',
};

export default function RiskPlanActivationsForStay({ stay, activations, planTypes }: Props) {
    return (
        <AppLayout breadcrumbs={[{ title: 'Respite', href: '/respite' }, { title: 'Risk Plan Activations', href: '/respite/risk-plan-activations' }, { title: 'For Stay', href: '#' }]}>
            <Head title="Risk Plans for Stay" />

            <div className="space-y-4">
                <div className="flex items-start justify-between gap-3">
                    <div>
                        <h1 className="text-lg font-semibold">
                            Risk Plans for {stay.client?.first_name} {stay.client?.last_name}
                        </h1>
                        <div className="mt-1 text-sm text-muted-foreground">
                            Stay #{stay.id} — {formatDateTime(stay.check_in)} to {formatDateTime(stay.check_out)}
                        </div>
                    </div>
                    <Link href={`/respite/risk-plan-activations/create?stay_id=${stay.id}`}>
                        <Button size="sm">New Activation</Button>
                    </Link>
                </div>
                <RespiteSubnav />

                <div className="space-y-2">
                    {activations.map((a: any) => (
                        <Card key={a.id}>
                            <CardHeader>
                                <CardTitle className="text-base">
                                    <div className="flex items-start justify-between gap-3">
                                        <div className="flex-1">
                                            <div className="font-semibold">{a.plan_name}</div>
                                            <div className="mt-2 flex flex-wrap gap-2">
                                                <Badge className={typeColors[a.plan_type] || ''}>{a.plan_type?.replace(/_/g, ' ')}</Badge>
                                                <Badge className={statusColors[a.status] || ''}>{a.status?.replace(/_/g, ' ')}</Badge>
                                            </div>
                                            <div className="mt-1 text-xs text-muted-foreground">{formatDateTime(a.created_at)}</div>
                                        </div>
                                        <Link href={`/respite/risk-plan-activations/${a.id}`} className="rounded-md border px-3 py-2 text-xs hover:bg-muted">
                                            View
                                        </Link>
                                    </div>
                                </CardTitle>
                            </CardHeader>
                        </Card>
                    ))}
                    {!activations.length && (
                        <div className="py-8 text-center text-sm text-muted-foreground">No risk plan activations for this stay.</div>
                    )}
                </div>
            </div>
        </AppLayout>
    );
}
