import AppLayout from '@/layouts/app-layout';
import { PageHero, PageLayout } from '@/components/page';
import { Card, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import RespiteSubnav from '@/components/respite-subnav';
import { formatDateTimeLong } from '@/lib/datetime';
import { Head, Link } from '@inertiajs/react';
import { ListChecks } from 'lucide-react';

type Props = {
    tasks: any[];
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
    submitted_for_approval: 'bg-status-warning-bg text-status-warning',
};

const priorityOrder: Record<string, number> = { urgent: 0, high: 1, medium: 2, low: 3 };

export default function MyTasks({ tasks }: Props) {
    const sorted = [...tasks].sort((a, b) => (priorityOrder[a.priority] ?? 99) - (priorityOrder[b.priority] ?? 99));

    return (
        <AppLayout breadcrumbs={[{ title: 'Respite', href: '/respite' }, { title: 'Tasks', href: '/respite/tasks' }, { title: 'My Tasks', href: '/respite/tasks/my-tasks' }]}>
            <Head title="My Tasks" />

            <PageLayout
                hero={
                    <PageHero
                        icon={ListChecks}
                        title="My Tasks"
                        description="Tasks assigned to you, sorted by priority."
                        stats={[
                            { label: 'Total', value: tasks.length },
                            { label: 'Urgent', value: tasks.filter((t: any) => t.priority === 'urgent').length },
                            { label: 'High', value: tasks.filter((t: any) => t.priority === 'high').length },
                        ]}
                    />
                }
            >
                <RespiteSubnav />

                <div className="space-y-2">
                    {sorted.map((t: any) => (
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
                                            {t.due_at && (
                                                <div className="mt-2 text-xs text-muted-foreground">Due: {formatDateTimeLong(t.due_at)}</div>
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
                    {!tasks.length && (
                        <div className="py-8 text-center text-sm text-muted-foreground">No tasks assigned to you.</div>
                    )}
                </div>
            </PageLayout>
        </AppLayout>
    );
}
