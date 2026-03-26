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
                        <div className="mt-1 text-sm text-slate-500">
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
                    {!activations.length && (
                        <div className="py-8 text-center text-sm text-slate-500">No risk plan activations for this stay.</div>
                    )}
                </div>
            </div>
        </AppLayout>
    );
}
