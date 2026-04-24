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
    tasks: { data: any[]; links: any[] };
    staff: any[];
    filters: any;
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

export default function TasksIndex({ tasks, staff, filters }: Props) {
    const ANY = '__any__';

    const onFilter = (next: Record<string, any>) => {
        router.get('/respite/tasks', { ...filters, ...next }, { preserveState: true, preserveScroll: true });
    };

    return (
        <AppLayout breadcrumbs={[{ title: 'Respite', href: '/respite' }, { title: 'Tasks', href: '/respite/tasks' }]}>
            <Head title="Respite Tasks" />

            <div className="space-y-4">
                <div>
                    <h1 className="text-lg font-semibold">Tasks</h1>
                    <div className="mt-1 text-sm text-muted-foreground">Tasks linked to procedure runs and respite stays.</div>
                </div>
                <RespiteSubnav />

                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Filters</CardTitle>
                    </CardHeader>
                    <CardContent className="grid grid-cols-1 gap-3 sm:grid-cols-3">
                        <div>
                            <Label className="text-xs text-muted-foreground">Status</Label>
                            <Select value={filters.status ?? ANY} onValueChange={(v) => onFilter({ status: v === ANY ? null : v })}>
                                <SelectTrigger><SelectValue placeholder="Status" /></SelectTrigger>
                                <SelectContent>
                                    <SelectItem value={ANY}>Any</SelectItem>
                                    {['pending', 'assigned', 'in_progress', 'completed', 'submitted_for_approval', 'approved', 'rejected'].map((s) => (
                                        <SelectItem key={s} value={s}>{s.replace(/_/g, ' ')}</SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                        <div>
                            <Label className="text-xs text-muted-foreground">Priority</Label>
                            <Select value={filters.priority ?? ANY} onValueChange={(v) => onFilter({ priority: v === ANY ? null : v })}>
                                <SelectTrigger><SelectValue placeholder="Priority" /></SelectTrigger>
                                <SelectContent>
                                    <SelectItem value={ANY}>Any</SelectItem>
                                    {['low', 'medium', 'high', 'urgent'].map((p) => (
                                        <SelectItem key={p} value={p}>{p}</SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                        <div>
                            <Label className="text-xs text-muted-foreground">Assigned To</Label>
                            <Select value={filters.assigned_to ?? ANY} onValueChange={(v) => onFilter({ assigned_to: v === ANY ? null : v })}>
                                <SelectTrigger><SelectValue placeholder="Staff" /></SelectTrigger>
                                <SelectContent>
                                    <SelectItem value={ANY}>Any</SelectItem>
                                    {staff.map((s: any) => (
                                        <SelectItem key={s.id} value={String(s.id)}>{s.name}</SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                    </CardContent>
                </Card>

                <div className="space-y-2">
                    {tasks.data.map((t: any) => (
                        <Card key={t.id}>
                            <CardHeader>
                                <CardTitle className="text-base">
                                    <div className="flex items-start justify-between gap-3">
                                        <div className="flex-1">
                                            <div className="font-semibold">{t.title}</div>
                                            <div className="mt-2 flex flex-wrap gap-2">
                                                <Badge className={priorityColors[t.priority] || ''}>{t.priority}</Badge>
                                                <Badge className={statusColors[t.status] || ''}>{t.status?.replace(/_/g, ' ')}</Badge>
                                            </div>
                                            {t.assigned_to && (
                                                <div className="mt-2 text-xs text-muted-foreground">Assigned to: {t.assigned_to?.name}</div>
                                            )}
                                            {t.due_at && (
                                                <div className="mt-1 text-xs text-muted-foreground">Due: {formatDateTime(t.due_at)}</div>
                                            )}
                                            {t.procedure_run && (
                                                <div className="mt-1">
                                                    <Link href={`/respite/procedure-runs/${t.procedure_run.id}`} className="text-xs text-primary hover:text-primary">
                                                        Procedure: {t.procedure_run.template?.name || `Run #${t.procedure_run.id}`}
                                                    </Link>
                                                </div>
                                            )}
                                        </div>
                                        <Link href={`/respite/tasks/${t.id}`} className="rounded-md border px-3 py-2 text-xs hover:bg-muted">
                                            View
                                        </Link>
                                    </div>
                                </CardTitle>
                            </CardHeader>
                        </Card>
                    ))}
                    {!tasks.data.length && (
                        <div className="py-8 text-center text-sm text-muted-foreground">No tasks found.</div>
                    )}
                </div>

                {tasks?.links?.length ? (
                    <div className="flex flex-wrap gap-2">
                        {tasks.links.map((l: any) => (
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
