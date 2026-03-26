import AppLayout from '@/layouts/app-layout';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import RespiteSubnav from '@/components/respite-subnav';
import { formatDateTime } from '@/lib/date-format';
import { Head, Link, router } from '@inertiajs/react';

type Props = {
    activations: { data: any[]; links: any[] };
};

const typeColors: Record<string, string> = {
    behaviour: 'bg-purple-100 text-purple-800',
    safety: 'bg-red-100 text-red-800',
    medical: 'bg-blue-100 text-blue-800',
    mobility: 'bg-orange-100 text-orange-800',
    communication: 'bg-teal-100 text-teal-800',
};

export default function RiskPlansNeedingAcknowledgment({ activations }: Props) {
    return (
        <AppLayout breadcrumbs={[{ title: 'Respite', href: '/respite' }, { title: 'Risk Plan Activations', href: '/respite/risk-plan-activations' }, { title: 'Needing Acknowledgment', href: '/respite/risk-plan-activations/needing-acknowledgment' }]}>
            <Head title="Risk Plans Needing Acknowledgment" />

            <div className="space-y-4">
                <div>
                    <h1 className="text-lg font-semibold">Risk Plans Needing Acknowledgment</h1>
                    <div className="mt-1 text-sm text-slate-500">Active risk plans you have not yet acknowledged.</div>
                </div>
                <RespiteSubnav />

                <div className="space-y-2">
                    {activations.data.map((a: any) => (
                        <Card key={a.id}>
                            <CardHeader>
                                <CardTitle className="text-base">
                                    <div className="flex items-start justify-between gap-3">
                                        <div className="flex-1">
                                            <div className="font-semibold">{a.plan_name}</div>
                                            <div className="mt-2 flex flex-wrap gap-2">
                                                <Badge className={typeColors[a.plan_type] || ''}>{a.plan_type?.replace(/_/g, ' ')}</Badge>
                                                <Badge className="bg-amber-100 text-amber-800">Needs Acknowledgment</Badge>
                                            </div>
                                            <div className="mt-2 text-xs text-slate-500">
                                                {a.stay?.client?.first_name} {a.stay?.client?.last_name}
                                            </div>
                                            <div className="mt-1 text-xs text-slate-400">{formatDateTime(a.created_at)}</div>
                                        </div>
                                        <div className="flex flex-col gap-2">
                                            <Link href={`/respite/risk-plan-activations/${a.id}`} className="rounded-md border px-3 py-2 text-xs hover:bg-muted text-center">
                                                View
                                            </Link>
                                            <Button size="sm" variant="outline" onClick={() => router.post(`/respite/risk-plan-activations/${a.id}/acknowledge`)}>
                                                Acknowledge
                                            </Button>
                                        </div>
                                    </div>
                                </CardTitle>
                            </CardHeader>
                        </Card>
                    ))}
                    {!activations.data.length && (
                        <div className="py-8 text-center text-sm text-slate-500">No risk plans needing acknowledgment.</div>
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
