import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Progress } from '@/components/ui/progress';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';

interface Task {
    id: number;
    title: string;
    description: string | null;
    is_completed: boolean;
    completed_at: string | null;
    sort_order: number;
    sign_off_required?: boolean;
}

interface Checklist {
    id: number;
    template_key: string;
    status: 'pending' | 'in_progress' | 'completed' | 'overdue';
    started_at: string | null;
    completed_at: string | null;
    due_date: string | null;
    tasks_count: number;
    tasks_completed_count: number;
    employee_profile: {
        id: number;
        user: { id: number; name: string };
    };
    tasks: Task[];
}

interface Props {
    checklist: Checklist;
    can: { manage: boolean };
}

const statusConfig: Record<string, { className: string; label: string }> = {
    pending: {
        className:
            'border-border/30 text-muted-foreground bg-muted-foreground/80/10',
        label: 'Pending',
    },
    in_progress: {
        className: 'border-status-info/30 text-status-info bg-status-info',
        label: 'In Progress',
    },
    completed: {
        className:
            'border-status-success/30 text-status-success bg-status-success',
        label: 'Completed',
    },
    overdue: {
        className:
            'border-status-critical/30 text-status-critical bg-status-critical',
        label: 'Overdue',
    },
};

export default function OnboardingShow({ checklist, can }: Props) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'HR', href: '/hr' },
        { title: 'Onboarding', href: '/hr/onboarding' },
        {
            title: checklist.employee_profile.user.name,
            href: `/hr/onboarding/${checklist.id}`,
        },
    ];

    const progressPercent =
        checklist.tasks_count > 0
            ? Math.round(
                  (checklist.tasks_completed_count / checklist.tasks_count) *
                      100,
              )
            : 0;

    const config = statusConfig[checklist.status] || statusConfig.pending;
    const page = usePage();
    const authUserId = Number(
        (page.props as { auth?: { user?: { id?: number } } }).auth?.user?.id ??
            0,
    );

    function toggleTask(task: Task) {
        if (!can.manage) return;
        if (task.is_completed) return;
        if (task.sign_off_required && authUserId <= 0) return;

        const payload = task.sign_off_required
            ? { signed_off_by: authUserId }
            : {};
        router.post(`/hr/onboarding/tasks/${task.id}/complete`, payload, {
            preserveScroll: true,
        });
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head
                title={`Onboarding - ${checklist.employee_profile.user.name}`}
            />
            <div className="flex flex-col gap-6 p-6">
                <div className="flex items-center gap-4">
                    <Button variant="ghost" size="sm" asChild>
                        <Link href="/hr/onboarding">
                            <ArrowLeft className="mr-1 h-4 w-4" />
                            Back
                        </Link>
                    </Button>
                </div>

                {/* Header Card */}
                <Card>
                    <CardHeader>
                        <div className="flex items-center justify-between">
                            <div>
                                <CardTitle className="text-xl">
                                    {checklist.employee_profile.user.name}
                                </CardTitle>
                                <p className="mt-1 text-sm text-muted-foreground capitalize">
                                    {checklist.template_key.replace(/_/g, ' ')}
                                </p>
                            </div>
                            <Badge
                                variant="outline"
                                className={config.className}
                            >
                                {config.label}
                            </Badge>
                        </div>
                    </CardHeader>
                    <CardContent>
                        <div className="grid gap-4 sm:grid-cols-3">
                            <div>
                                <p className="text-sm text-muted-foreground">
                                    Due Date
                                </p>
                                <p className="font-medium">
                                    {checklist.due_date || '\u2014'}
                                </p>
                            </div>
                            <div>
                                <p className="text-sm text-muted-foreground">
                                    Started
                                </p>
                                <p className="font-medium">
                                    {checklist.started_at || '\u2014'}
                                </p>
                            </div>
                            <div>
                                <p className="text-sm text-muted-foreground">
                                    Completed
                                </p>
                                <p className="font-medium">
                                    {checklist.completed_at || '\u2014'}
                                </p>
                            </div>
                        </div>
                        <div className="mt-4">
                            <div className="mb-1 flex items-center justify-between text-sm">
                                <span className="text-muted-foreground">
                                    Progress
                                </span>
                                <span className="font-medium">
                                    {checklist.tasks_completed_count}/
                                    {checklist.tasks_count} tasks (
                                    {progressPercent}%)
                                </span>
                            </div>
                            <Progress value={progressPercent} />
                        </div>
                    </CardContent>
                </Card>

                {/* Tasks List */}
                <Card>
                    <CardHeader>
                        <CardTitle>Tasks</CardTitle>
                    </CardHeader>
                    <CardContent>
                        {checklist.tasks.length === 0 ? (
                            <p className="py-4 text-center text-muted-foreground">
                                No tasks found for this checklist.
                            </p>
                        ) : (
                            <div className="space-y-3">
                                {checklist.tasks
                                    .sort((a, b) => a.sort_order - b.sort_order)
                                    .map((task) => (
                                        <div
                                            key={task.id}
                                            className={`flex items-start gap-3 rounded-lg border p-3 ${
                                                task.is_completed
                                                    ? 'bg-muted/30 opacity-70'
                                                    : ''
                                            }`}
                                        >
                                            <Checkbox
                                                checked={task.is_completed}
                                                disabled={
                                                    !can.manage ||
                                                    task.is_completed
                                                }
                                                onCheckedChange={() =>
                                                    toggleTask(task)
                                                }
                                                className="mt-0.5"
                                            />
                                            <div className="flex-1">
                                                <p
                                                    className={`font-medium ${task.is_completed ? 'line-through' : ''}`}
                                                >
                                                    {task.title}
                                                </p>
                                                {task.description && (
                                                    <p className="mt-1 text-sm text-muted-foreground">
                                                        {task.description}
                                                    </p>
                                                )}
                                                {task.completed_at && (
                                                    <p className="mt-1 text-xs text-muted-foreground">
                                                        Completed:{' '}
                                                        {task.completed_at}
                                                    </p>
                                                )}
                                                {task.sign_off_required && (
                                                    <p className="mt-1 text-xs text-muted-foreground">
                                                        Sign-off required
                                                    </p>
                                                )}
                                            </div>
                                        </div>
                                    ))}
                            </div>
                        )}
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
