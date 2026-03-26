import { useState } from 'react';
import AppLayout from '@/layouts/app-layout';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Textarea } from '@/components/ui/textarea';
import { Label } from '@/components/ui/label';
import RespiteSubnav from '@/components/respite-subnav';
import { formatDateTime } from '@/lib/date-format';
import { Head, Link, router } from '@inertiajs/react';

type Props = {
    activation: any;
    hasAcknowledged: boolean;
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

export default function RiskPlanActivationShow({ activation, hasAcknowledged }: Props) {
    const [reviewNotes, setReviewNotes] = useState('');
    const [deactivateReason, setDeactivateReason] = useState('');
    const [suspendReason, setSuspendReason] = useState('');

    const base = `/respite/risk-plan-activations/${activation.id}`;

    return (
        <AppLayout breadcrumbs={[{ title: 'Respite', href: '/respite' }, { title: 'Risk Plan Activations', href: '/respite/risk-plan-activations' }, { title: activation.plan_name, href: base }]}>
            <Head title="Risk Plan Activation" />

            <div className="space-y-4">
                <div className="flex items-start justify-between gap-3">
                    <div>
                        <h1 className="text-lg font-semibold">{activation.plan_name}</h1>
                        <div className="mt-1 text-sm text-slate-500">
                            {activation.stay?.client?.first_name} {activation.stay?.client?.last_name}
                        </div>
                    </div>
                    <Link href="/respite/risk-plan-activations" className="rounded-md border px-3 py-2 text-xs hover:bg-muted">
                        Back to list
                    </Link>
                </div>
                <RespiteSubnav />

                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Plan Details</CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-3 text-sm text-slate-600">
                        <div className="flex flex-wrap gap-2">
                            <Badge className={typeColors[activation.plan_type] || ''}>{activation.plan_type?.replace(/_/g, ' ')}</Badge>
                            <Badge className={statusColors[activation.status] || ''}>{activation.status?.replace(/_/g, ' ')}</Badge>
                        </div>

                        {activation.plan_details?.length > 0 && (
                            <div>
                                <div className="font-medium text-slate-700">Plan Details</div>
                                <ul className="mt-1 list-disc pl-5 space-y-1">
                                    {activation.plan_details.map((d: string, i: number) => <li key={i}>{d}</li>)}
                                </ul>
                            </div>
                        )}

                        {activation.triggers?.length > 0 && (
                            <div>
                                <div className="font-medium text-slate-700">Triggers</div>
                                <ul className="mt-1 list-disc pl-5 space-y-1">
                                    {activation.triggers.map((t: string, i: number) => <li key={i}>{t}</li>)}
                                </ul>
                            </div>
                        )}

                        {activation.interventions?.length > 0 && (
                            <div>
                                <div className="font-medium text-slate-700">Interventions</div>
                                <ul className="mt-1 list-disc pl-5 space-y-1">
                                    {activation.interventions.map((v: string, i: number) => <li key={i}>{v}</li>)}
                                </ul>
                            </div>
                        )}

                        {activation.escalation_steps?.length > 0 && (
                            <div>
                                <div className="font-medium text-slate-700">Escalation Steps</div>
                                <ol className="mt-1 list-decimal pl-5 space-y-1">
                                    {activation.escalation_steps.map((s: string, i: number) => <li key={i}>{s}</li>)}
                                </ol>
                            </div>
                        )}
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Review Info</CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-2 text-sm text-slate-600">
                        {activation.reviewed_by ? (
                            <>
                                <div>Reviewed by: {activation.reviewed_by?.name || 'Unknown'}</div>
                                <div>Review notes: {activation.review_notes || 'None'}</div>
                            </>
                        ) : (
                            <div className="text-slate-500">Not yet reviewed.</div>
                        )}
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Staff Acknowledgments</CardTitle>
                    </CardHeader>
                    <CardContent>
                        {activation.acknowledgments?.length ? (
                            <div className="space-y-2">
                                <div className="text-sm text-slate-500">{activation.acknowledgments.length} staff acknowledged</div>
                                <ul className="space-y-1 text-sm">
                                    {activation.acknowledgments.map((ack: any, i: number) => (
                                        <li key={i} className="flex justify-between">
                                            <span>{ack.user?.name || 'Unknown'}</span>
                                            <span className="text-xs text-slate-400">{formatDateTime(ack.acknowledged_at)}</span>
                                        </li>
                                    ))}
                                </ul>
                            </div>
                        ) : (
                            <div className="text-sm text-slate-500">No acknowledgments yet.</div>
                        )}
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Actions</CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-4">
                        {activation.status === 'pending_review' && (
                            <div className="space-y-2">
                                <Label>Review Notes</Label>
                                <Textarea value={reviewNotes} onChange={(e) => setReviewNotes(e.target.value)} placeholder="Enter review notes..." />
                                <div className="flex gap-2">
                                    <Button size="sm" onClick={() => router.post(`${base}/review`, { review_notes: reviewNotes })}>
                                        Review
                                    </Button>
                                    <Button size="sm" onClick={() => router.post(`${base}/activate`)}>
                                        Activate
                                    </Button>
                                </div>
                            </div>
                        )}

                        {activation.status === 'active' && (
                            <>
                                <div className="space-y-2">
                                    <Label>Deactivation Reason</Label>
                                    <Textarea value={deactivateReason} onChange={(e) => setDeactivateReason(e.target.value)} placeholder="Reason for deactivation..." />
                                    <Button size="sm" variant="outline" onClick={() => router.post(`${base}/deactivate`, { reason: deactivateReason })}>
                                        Deactivate
                                    </Button>
                                </div>

                                <div className="border-t pt-4 space-y-2">
                                    <Label>Suspension Reason</Label>
                                    <Textarea value={suspendReason} onChange={(e) => setSuspendReason(e.target.value)} placeholder="Reason for suspension..." />
                                    <Button size="sm" variant="outline" onClick={() => router.post(`${base}/suspend`, { reason: suspendReason })}>
                                        Suspend
                                    </Button>
                                </div>
                            </>
                        )}

                        {!hasAcknowledged && (
                            <div className="border-t pt-4">
                                <Button size="sm" onClick={() => router.post(`${base}/acknowledge`)}>
                                    Acknowledge
                                </Button>
                            </div>
                        )}
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
