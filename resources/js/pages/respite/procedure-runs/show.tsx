import { useState } from 'react';
import AppLayout from '@/layouts/app-layout';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import RespiteSubnav from '@/components/respite-subnav';
import { formatDateTime } from '@/lib/date-format';
import { Head, Link, router } from '@inertiajs/react';

type Props = {
    run: any;
    staff?: any[];
};

const statusColors: Record<string, string> = {
    pending: 'bg-slate-100 text-slate-800',
    in_progress: 'bg-blue-100 text-blue-800',
    completed: 'bg-green-100 text-green-800',
    failed: 'bg-red-100 text-red-800',
    cancelled: 'bg-slate-100 text-slate-600',
    escalated: 'bg-orange-100 text-orange-800',
};

export default function ProcedureRunShow({ run, staff }: Props) {
    const [failureReason, setFailureReason] = useState('');
    const [cancellationReason, setCancellationReason] = useState('');
    const [escalateToUserId, setEscalateToUserId] = useState('');
    const [escalateReason, setEscalateReason] = useState('');

    const base = `/respite/procedure-runs/${run.id}`;

    return (
        <AppLayout breadcrumbs={[{ title: 'Respite', href: '/respite' }, { title: 'Procedure Runs', href: '/respite/procedure-runs' }, { title: `Run #${run.id}`, href: base }]}>
            <Head title="Procedure Run" />

            <div className="space-y-4">
                <div className="flex items-start justify-between gap-3">
                    <div>
                        <h1 className="text-lg font-semibold">{run.template?.name || 'Procedure Run'}</h1>
                        <div className="mt-1 text-sm text-slate-500">Run #{run.id}</div>
                    </div>
                    <Link href="/respite/procedure-runs" className="rounded-md border px-3 py-2 text-xs hover:bg-muted">
                        Back to list
                    </Link>
                </div>
                <RespiteSubnav />

                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Run Details</CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-3 text-sm text-slate-600">
                        <div className="flex flex-wrap gap-2">
                            <Badge className={statusColors[run.status] || ''}>{run.status?.replace(/_/g, ' ')}</Badge>
                        </div>
                        <div>Progress: {run.current_step || 0}/{run.total_steps || 0} steps</div>
                        {run.sla_deadline && (
                            <div className={run.sla_breached ? 'text-red-600 font-medium' : ''}>
                                SLA Deadline: {formatDateTime(run.sla_deadline)}
                                {run.sla_breached && ' (BREACHED)'}
                            </div>
                        )}
                        <div>Initiated by: {run.initiated_by?.name || 'Unknown'}</div>
                        {run.escalated_to && <div>Escalated to: {run.escalated_to?.name}</div>}
                        {run.failure_reason && <div className="text-red-600">Failure reason: {run.failure_reason}</div>}
                        {run.cancellation_reason && <div>Cancellation reason: {run.cancellation_reason}</div>}
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Steps</CardTitle>
                    </CardHeader>
                    <CardContent>
                        {run.step_states?.length ? (
                            <ul className="space-y-2">
                                {run.step_states.map((step: any, idx: number) => (
                                    <li key={idx} className="flex items-center gap-2 text-sm">
                                        {step.completed ? (
                                            <span className="text-green-600">&#10003;</span>
                                        ) : (
                                            <span className="text-slate-400">&#9675;</span>
                                        )}
                                        <span className={step.completed ? 'text-slate-600' : 'text-slate-800'}>{step.name || `Step ${idx + 1}`}</span>
                                    </li>
                                ))}
                            </ul>
                        ) : (
                            <div className="text-sm text-slate-500">No step information available.</div>
                        )}
                    </CardContent>
                </Card>

                {(run.status === 'pending' || run.status === 'in_progress') && (
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Actions</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            {run.status === 'pending' && (
                                <Button size="sm" onClick={() => router.post(`${base}/start`)}>
                                    Start
                                </Button>
                            )}

                            {run.status === 'in_progress' && (
                                <div className="space-y-4">
                                    <Button size="sm" onClick={() => router.post(`${base}/complete`)}>
                                        Complete
                                    </Button>

                                    <div className="border-t pt-4 space-y-2">
                                        <Label>Failure Reason</Label>
                                        <Textarea value={failureReason} onChange={(e) => setFailureReason(e.target.value)} placeholder="Reason for failure..." />
                                        <Button size="sm" variant="destructive" onClick={() => router.post(`${base}/fail`, { failure_reason: failureReason })}>
                                            Fail
                                        </Button>
                                    </div>

                                    <div className="border-t pt-4 space-y-2">
                                        <Label>Cancellation Reason</Label>
                                        <Textarea value={cancellationReason} onChange={(e) => setCancellationReason(e.target.value)} placeholder="Reason for cancellation..." />
                                        <Button size="sm" variant="outline" onClick={() => router.post(`${base}/cancel`, { cancellation_reason: cancellationReason })}>
                                            Cancel
                                        </Button>
                                    </div>

                                    <div className="border-t pt-4 space-y-2">
                                        <Label>Escalate To</Label>
                                        <Select value={escalateToUserId} onValueChange={setEscalateToUserId}>
                                            <SelectTrigger><SelectValue placeholder="Select staff" /></SelectTrigger>
                                            <SelectContent>
                                                {(staff || []).map((s: any) => (
                                                    <SelectItem key={s.id} value={String(s.id)}>{s.name}</SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                        <Label>Escalation Reason</Label>
                                        <Textarea value={escalateReason} onChange={(e) => setEscalateReason(e.target.value)} placeholder="Reason for escalation..." />
                                        <Button size="sm" variant="outline" onClick={() => router.post(`${base}/escalate`, { escalate_to_user_id: escalateToUserId, reason: escalateReason })}>
                                            Escalate
                                        </Button>
                                    </div>
                                </div>
                            )}
                        </CardContent>
                    </Card>
                )}
            </div>
        </AppLayout>
    );
}
