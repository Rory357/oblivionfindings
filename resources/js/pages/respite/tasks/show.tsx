import { useState } from 'react';
import AppLayout from '@/layouts/app-layout';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Label } from '@/components/ui/label';
import { PageHero, PageLayout } from '@/components/page';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import RespiteSubnav from '@/components/respite-subnav';
import { formatDateTimeLong } from '@/lib/datetime';
import { Head, Link, router } from '@inertiajs/react';

type Props = {
    task: any;
    staff?: any[];
};

const priorityColors: Record<string, string> = {
    low: 'bg-muted text-foreground',
    medium: 'bg-status-info-bg text-status-info',
    high: 'bg-status-warning-bg text-status-warning',
    urgent: 'bg-status-critical-bg text-status-critical',
};

const statusColors: Record<string, string> = {
    pending: 'bg-muted text-foreground',
    assigned: 'bg-status-info-bg text-status-info',
    in_progress: 'bg-primary/10 text-primary',
    completed: 'bg-status-success-bg text-status-success',
    submitted_for_approval: 'bg-status-warning-bg text-status-warning',
    approved: 'bg-status-success-bg text-status-success',
    rejected: 'bg-status-critical-bg text-status-critical',
};

export default function TaskShow({ task, staff }: Props) {
    const [assignToUserId, setAssignToUserId] = useState('');
    const [completionNotes, setCompletionNotes] = useState('');
    const [approvalNotes, setApprovalNotes] = useState('');

    const base = `/respite/tasks/${task.id}`;

    return (
        <AppLayout breadcrumbs={[{ title: 'Respite', href: '/respite' }, { title: 'Tasks', href: '/respite/tasks' }, { title: task.title, href: base }]}>
            <Head title="Task Details" />

            <PageLayout
                hero={
                    <PageHero
                        variant="compact"
                        backHref="/respite/tasks"
                        title={task.title}
                        description={`Task #${task.id}`}
                    />
                }
            >
                <RespiteSubnav />

                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Task Details</CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-3 text-sm text-muted-foreground">
                        {task.description && <div className="whitespace-pre-wrap">{task.description}</div>}
                        <div className="flex flex-wrap gap-2">
                            <Badge className={priorityColors[task.priority] || ''}>{task.priority}</Badge>
                            <Badge className={statusColors[task.status] || ''}>{task.status?.replace(/_/g, ' ')}</Badge>
                            {task.type && <Badge variant="outline">{task.type}</Badge>}
                        </div>
                        {task.due_at && <div>Due: {formatDateTimeLong(task.due_at)}</div>}
                        {task.assigned_to && <div>Assigned to: {task.assigned_to?.name}</div>}
                        {task.assigned_by && <div>Assigned by: {task.assigned_by?.name}</div>}
                        {task.completed_by && <div>Completed by: {task.completed_by?.name}</div>}
                        {task.approved_by && <div>Approved by: {task.approved_by?.name}</div>}
                        {task.procedure_run && (
                            <div>
                                Procedure Run:{' '}
                                <Link href={`/respite/procedure-runs/${task.procedure_run.id}`} className="text-primary hover:text-primary">
                                    {task.procedure_run.template?.name || `Run #${task.procedure_run.id}`}
                                </Link>
                            </div>
                        )}
                    </CardContent>
                </Card>

                {task.checklist_items?.length > 0 && (
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Checklist</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <ul className="space-y-2">
                                {task.checklist_items.map((item: any, idx: number) => (
                                    <li key={idx} className="flex items-center gap-2 text-sm">
                                        <input
                                            type="checkbox"
                                            checked={!!item.checked}
                                            onChange={() => router.post(`${base}/update-checklist`, { index: idx, checked: !item.checked })}
                                        />
                                        <span className={item.checked ? 'line-through text-muted-foreground' : ''}>{item.label || item.name || `Item ${idx + 1}`}</span>
                                    </li>
                                ))}
                            </ul>
                        </CardContent>
                    </Card>
                )}

                {(task.required_evidence?.length > 0 || task.evidence_collected?.length > 0) && (
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Evidence</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-3 text-sm text-muted-foreground">
                            {task.required_evidence?.length > 0 && (
                                <div>
                                    <div className="font-medium text-foreground">Required Evidence</div>
                                    <ul className="mt-1 list-disc pl-5 space-y-1">
                                        {task.required_evidence.map((e: string, i: number) => <li key={i}>{e}</li>)}
                                    </ul>
                                </div>
                            )}
                            {task.evidence_collected?.length > 0 && (
                                <div>
                                    <div className="font-medium text-foreground">Evidence Collected</div>
                                    <ul className="mt-1 list-disc pl-5 space-y-1">
                                        {task.evidence_collected.map((e: string, i: number) => <li key={i}>{e}</li>)}
                                    </ul>
                                </div>
                            )}
                        </CardContent>
                    </Card>
                )}

                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Actions</CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-4">
                        {task.status === 'pending' && (
                            <div className="space-y-2">
                                <Label>Assign To</Label>
                                <Select value={assignToUserId} onValueChange={setAssignToUserId}>
                                    <SelectTrigger><SelectValue placeholder="Select staff" /></SelectTrigger>
                                    <SelectContent>
                                        {(staff || []).map((s: any) => (
                                            <SelectItem key={s.id} value={String(s.id)}>{s.name}</SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                <Button size="sm" onClick={() => router.post(`${base}/assign`, { assigned_to_user_id: assignToUserId })}>
                                    Assign
                                </Button>
                            </div>
                        )}

                        {task.status === 'assigned' && (
                            <Button size="sm" onClick={() => router.post(`${base}/start`)}>
                                Start
                            </Button>
                        )}

                        {task.status === 'in_progress' && (
                            <div className="space-y-4">
                                <div className="space-y-2">
                                    <Label>Completion Notes</Label>
                                    <Textarea value={completionNotes} onChange={(e) => setCompletionNotes(e.target.value)} placeholder="Notes on completion..." />
                                    <div className="flex gap-2">
                                        <Button size="sm" onClick={() => router.post(`${base}/complete`, { completion_notes: completionNotes })}>
                                            Complete
                                        </Button>
                                        <Button size="sm" variant="outline" onClick={() => router.post(`${base}/submit-for-approval`, { completion_notes: completionNotes })}>
                                            Submit for Approval
                                        </Button>
                                    </div>
                                </div>
                            </div>
                        )}

                        {task.status === 'submitted_for_approval' && (
                            <div className="space-y-2">
                                <Label>Approval Notes</Label>
                                <Textarea value={approvalNotes} onChange={(e) => setApprovalNotes(e.target.value)} placeholder="Notes..." />
                                <div className="flex gap-2">
                                    <Button size="sm" onClick={() => router.post(`${base}/approve`, { notes: approvalNotes })}>
                                        Approve
                                    </Button>
                                    <Button size="sm" variant="destructive" onClick={() => router.post(`${base}/reject`, { notes: approvalNotes })}>
                                        Reject
                                    </Button>
                                </div>
                            </div>
                        )}

                        {['completed', 'approved', 'rejected'].includes(task.status) && (
                            <div className="text-sm text-muted-foreground">No further actions available.</div>
                        )}
                    </CardContent>
                </Card>
            </PageLayout>
        </AppLayout>
    );
}
