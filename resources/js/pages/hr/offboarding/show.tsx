import AppLayout from '@/layouts/app-layout';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Progress } from '@/components/ui/progress';
import { type BreadcrumbItem } from '@/types';
import { ArrowLeft } from 'lucide-react';

interface Task {
    id: number;
    category: string;
    title: string;
    description: string | null;
    status: string;
    is_required: boolean;
    sign_off_required: boolean;
    due_date: string | null;
    completed_at: string | null;
    sort_order: number;
    assigned_to?: { id: number; name: string } | null;
}

interface Checklist {
    id: number;
    template_key: string;
    status: 'pending' | 'in_progress' | 'completed' | 'cancelled' | 'overdue';
    started_at: string | null;
    completed_at: string | null;
    due_date: string | null;
    employee_profile: {
        id: number;
        user: { id: number; name: string };
    };
    tasks: Task[];
}

interface Props {
    checklist: Checklist;
    progress: {
        total: number;
        completed: number;
        pending: number;
        percent: number;
    };
    can: { manage: boolean };
}

const statusConfig: Record<string, { className: string; label: string }> = {
    pending: {
        className: 'border-border/30 text-muted-foreground bg-muted-foreground/80/10',
        label: 'Pending',
    },
    in_progress: {
        className: 'border-status-info/30 text-status-info bg-status-info',
        label: 'In Progress',
    },
    completed: {
        className: 'border-status-success/30 text-status-success bg-status-success',
        label: 'Completed',
    },
    cancelled: {
        className: 'border-status-warning/30 text-status-warning bg-status-warning',
        label: 'Cancelled',
    },
    overdue: {
        className: 'border-status-critical/30 text-status-critical bg-status-critical',
        label: 'Overdue',
    },
};

export default function OffboardingShow({ checklist, progress, can }: Props) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'HR', href: '/hr' },
        { title: 'Offboarding', href: '/hr/offboarding' },
        { title: checklist.employee_profile.user.name, href: `/hr/offboarding/${checklist.id}` },
    ];

    const page = usePage();
    const authUserId = Number((page.props as { auth?: { user?: { id?: number } } }).auth?.user?.id ?? 0);

    const config = statusConfig[checklist.status] || statusConfig.pending;

    function completeTask(task: Task) {
        if (!can.manage || task.status === 'completed') return;

        const payload = task.sign_off_required && authUserId > 0
            ? { signed_off_by: authUserId }
            : {};

        router.post(`/hr/offboarding/tasks/${task.id}/complete`, payload, { preserveScroll: true });
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Offboarding - ${checklist.employee_profile.user.name}`} />
            <div className="flex flex-col gap-6 p-6">
                <div className="flex items-center gap-4">
                    <Button variant="ghost" size="sm" asChild>
                        <Link href="/hr/offboarding">
                            <ArrowLeft className="mr-1 h-4 w-4" />
                            Back
                        </Link>
                    </Button>
                </div>

                <Card>
                    <CardHeader>
                        <div className="flex items-center justify-between">
                            <div>
                                <CardTitle className="text-xl">{checklist.employee_profile.user.name}</CardTitle>
                                <p className="mt-1 text-sm capitalize text-muted-foreground">
                                    {checklist.template_key.replace(/_/g, ' ')}
                                </p>
                            </div>
                            <Badge variant="outline" className={config.className}>
                                {config.label}
                            </Badge>
                        </div>
                    </CardHeader>
                    <CardContent>
                        <div className="grid gap-4 sm:grid-cols-3">
                            <div>
                                <p className="text-sm text-muted-foreground">Last Working Day</p>
                                <p className="font-medium">{checklist.due_date || '-'}</p>
                            </div>
                            <div>
                                <p className="text-sm text-muted-foreground">Started</p>
                                <p className="font-medium">{checklist.started_at || '-'}</p>
                            </div>
                            <div>
                                <p className="text-sm text-muted-foreground">Completed</p>
                                <p className="font-medium">{checklist.completed_at || '-'}</p>
                            </div>
                        </div>
                        <div className="mt-4">
                            <div className="mb-1 flex items-center justify-between text-sm">
                                <span className="text-muted-foreground">Progress</span>
                                <span className="font-medium">
                                    {progress.completed}/{progress.total} tasks ({progress.percent}%)
                                </span>
                            </div>
                            <Progress value={progress.percent} />
                        </div>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>Tasks</CardTitle>
                    </CardHeader>
                    <CardContent>
                        {checklist.tasks.length === 0 ? (
                            <p className="py-4 text-center text-muted-foreground">No tasks found for this checklist.</p>
                        ) : (
                            <div className="space-y-3">
                                {checklist.tasks
                                    .sort((a, b) => a.sort_order - b.sort_order)
                                    .map((task) => {
                                        const completed = task.status === 'completed';
                                        return (
                                            <div
                                                key={task.id}
                                                className={`rounded-lg border p-3 ${completed ? 'bg-muted/30 opacity-70' : ''}`}
                                            >
                                                <div className="flex items-start justify-between gap-3">
                                                    <div className="flex-1">
                                                        <p className={`font-medium ${completed ? 'line-through' : ''}`}>{task.title}</p>
                                                        {task.description && (
                                                            <p className="mt-1 text-sm text-muted-foreground">{task.description}</p>
                                                        )}
                                                        <div className="mt-2 flex flex-wrap items-center gap-2 text-xs text-muted-foreground">
                                                            <span>Category: {task.category}</span>
                                                            <span>Due: {task.due_date || '-'}</span>
                                                            <span>Assigned: {task.assigned_to?.name || task.assigned_to?.id || '-'}</span>
                                                        </div>
                                                        <div className="mt-2 flex items-center gap-2">
                                                            {task.is_required && <Badge variant="secondary">Required</Badge>}
                                                            {task.sign_off_required && <Badge variant="outline">Sign-off</Badge>}
                                                            {completed && <Badge className="bg-status-success">Completed</Badge>}
                                                        </div>
                                                    </div>

                                                    <Button
                                                        size="sm"
                                                        variant={completed ? 'secondary' : 'default'}
                                                        disabled={!can.manage || completed}
                                                        onClick={() => completeTask(task)}
                                                    >
                                                        {completed ? 'Done' : 'Complete'}
                                                    </Button>
                                                </div>
                                            </div>
                                        );
                                    })}
                            </div>
                        )}
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
