import { SelectInput } from '@/components/hr/wizard';
import { PageHero } from '@/components/page';
import PageShell from '@/components/page-shell';
import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Progress } from '@/components/ui/progress';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { MessageSquare, RotateCcw, XCircle } from 'lucide-react';
import { useState } from 'react';

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

interface Interviewer {
    id: number;
    name: string;
}
interface DepartureReason {
    value: string;
    label: string;
}

interface Props {
    checklist: Checklist;
    progress: {
        total: number;
        completed: number;
        pending: number;
        percent: number;
    };
    interviewers: Interviewer[];
    departureReasons: DepartureReason[];
    can: { manage: boolean };
}

const isExitInterviewTask = (task: Task) =>
    task.category === 'hr' && /exit interview/i.test(task.title);

/** Record a real HrExitInterview from the offboarding checklist's task. */
function ExitInterviewDialog({
    open,
    onClose,
    employeeProfileId,
    interviewers,
    departureReasons,
}: {
    open: boolean;
    onClose: () => void;
    employeeProfileId: number;
    interviewers: Interviewer[];
    departureReasons: DepartureReason[];
}) {
    const form = useForm<{
        employee_profile_id: number;
        interviewer_user_id: string;
        interview_date: string;
        departure_reason: string;
        overall_satisfaction: string;
        what_went_well: string;
        what_could_improve: string;
        from_offboarding: boolean;
    }>({
        employee_profile_id: employeeProfileId,
        interviewer_user_id: '',
        interview_date: '',
        departure_reason: '',
        overall_satisfaction: '',
        what_went_well: '',
        what_could_improve: '',
        from_offboarding: true,
    });

    const close = () => {
        form.reset();
        form.clearErrors();
        onClose();
    };

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        form.transform((data) => ({
            ...data,
            overall_satisfaction: data.overall_satisfaction || null,
        }));
        form.post('/hr/exit-interviews', {
            preserveScroll: true,
            onSuccess: close,
        });
    };

    const canSubmit =
        form.data.interviewer_user_id !== '' &&
        form.data.interview_date !== '' &&
        form.data.departure_reason !== '';

    return (
        <Dialog open={open} onOpenChange={(o) => !o && close()}>
            <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>Record exit interview</DialogTitle>
                    <DialogDescription>
                        Capture the departing employee's exit interview.
                    </DialogDescription>
                </DialogHeader>
                <form onSubmit={submit} className="space-y-4">
                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="space-y-1.5">
                            <Label>Interviewer</Label>
                            <SelectInput
                                value={form.data.interviewer_user_id}
                                onChange={(v) =>
                                    form.setData('interviewer_user_id', v)
                                }
                                placeholder="Select an interviewer"
                                options={interviewers.map((i) => ({
                                    value: String(i.id),
                                    label: i.name,
                                }))}
                            />
                            {form.errors.interviewer_user_id && (
                                <p className="text-xs text-status-critical">
                                    {form.errors.interviewer_user_id}
                                </p>
                            )}
                        </div>
                        <div className="space-y-1.5">
                            <Label htmlFor="interview_date">Interview date</Label>
                            <Input
                                id="interview_date"
                                type="date"
                                value={form.data.interview_date}
                                onChange={(e) =>
                                    form.setData('interview_date', e.target.value)
                                }
                            />
                            {form.errors.interview_date && (
                                <p className="text-xs text-status-critical">
                                    {form.errors.interview_date}
                                </p>
                            )}
                        </div>
                        <div className="space-y-1.5">
                            <Label>Departure reason</Label>
                            <SelectInput
                                value={form.data.departure_reason}
                                onChange={(v) =>
                                    form.setData('departure_reason', v)
                                }
                                placeholder="Select a reason"
                                options={departureReasons}
                            />
                            {form.errors.departure_reason && (
                                <p className="text-xs text-status-critical">
                                    {form.errors.departure_reason}
                                </p>
                            )}
                        </div>
                        <div className="space-y-1.5">
                            <Label>Overall satisfaction (1–5)</Label>
                            <Input
                                type="number"
                                min={1}
                                max={5}
                                value={form.data.overall_satisfaction}
                                onChange={(e) =>
                                    form.setData(
                                        'overall_satisfaction',
                                        e.target.value,
                                    )
                                }
                            />
                        </div>
                    </div>
                    <div className="space-y-1.5">
                        <Label htmlFor="what_went_well">What went well</Label>
                        <Textarea
                            id="what_went_well"
                            rows={3}
                            value={form.data.what_went_well}
                            onChange={(e) =>
                                form.setData('what_went_well', e.target.value)
                            }
                        />
                    </div>
                    <div className="space-y-1.5">
                        <Label htmlFor="what_could_improve">
                            What could improve
                        </Label>
                        <Textarea
                            id="what_could_improve"
                            rows={3}
                            value={form.data.what_could_improve}
                            onChange={(e) =>
                                form.setData('what_could_improve', e.target.value)
                            }
                        />
                    </div>
                    <DialogFooter>
                        <Button type="button" variant="ghost" onClick={close}>
                            Cancel
                        </Button>
                        <Button
                            type="submit"
                            disabled={!canSubmit || form.processing}
                        >
                            {form.processing ? 'Saving…' : 'Record interview'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

const statusConfig: Record<string, { className: string; label: string }> = {
    pending: {
        className:
            'border-border/30 text-muted-foreground bg-muted-foreground/10',
        label: 'Pending',
    },
    in_progress: {
        className: 'border-status-info/30 text-status-info bg-status-info-bg',
        label: 'In Progress',
    },
    completed: {
        className:
            'border-status-success/30 text-status-success bg-status-success-bg',
        label: 'Completed',
    },
    cancelled: {
        className:
            'border-status-warning/30 text-status-warning bg-status-warning-bg',
        label: 'Cancelled',
    },
    overdue: {
        className:
            'border-status-critical/30 text-status-critical bg-status-critical-bg',
        label: 'Overdue',
    },
};

export default function OffboardingShow({
    checklist,
    progress,
    interviewers,
    departureReasons,
    can,
}: Props) {
    const [exitDialogOpen, setExitDialogOpen] = useState(false);
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'HR', href: '/hr' },
        { title: 'Offboarding', href: '/hr/offboarding' },
        {
            title: checklist.employee_profile.user.name,
            href: `/hr/offboarding/${checklist.id}`,
        },
    ];

    const page = usePage();
    const authUserId = Number(
        (page.props as { auth?: { user?: { id?: number } } }).auth?.user?.id ??
            0,
    );

    const config = statusConfig[checklist.status] || statusConfig.pending;
    const [cancelOpen, setCancelOpen] = useState(false);
    const isOpenChecklist =
        checklist.status === 'pending' || checklist.status === 'in_progress';

    function completeTask(task: Task) {
        if (!can.manage || task.status === 'completed') return;

        const payload =
            task.sign_off_required && authUserId > 0
                ? { signed_off_by: authUserId }
                : {};

        router.post(`/hr/offboarding/tasks/${task.id}/complete`, payload, {
            preserveScroll: true,
        });
    }

    function reopenTask(task: Task) {
        if (!can.manage || task.status !== 'completed') return;
        router.post(
            `/hr/offboarding/tasks/${task.id}/uncomplete`,
            {},
            { preserveScroll: true },
        );
    }

    function setChecklistStatus(status: 'in_progress' | 'cancelled') {
        router.post(
            `/hr/offboarding/${checklist.id}/status`,
            { status },
            { preserveScroll: true },
        );
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head
                title={`Offboarding - ${checklist.employee_profile.user.name}`}
            />
            <PageShell>
                <PageHero
                    category="hr"
                    variant="compact"
                    title={`Offboarding: ${checklist.employee_profile.user.name}`}
                    description={`Last working day ${checklist.due_date || 'not set'}`}
                />

                <Card>
                    <CardHeader>
                        <div className="flex items-center justify-between">
                            <div>
                                <CardTitle className="text-xl">
                                    {checklist.employee_profile.user.name}
                                </CardTitle>
                                <p className="mt-1 text-sm text-muted-foreground capitalize">
                                    {checklist.template_key.replace(/[:_]/g, ' ')}
                                </p>
                            </div>
                            <div className="flex items-center gap-2">
                                {can.manage && isOpenChecklist && (
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        onClick={() => setCancelOpen(true)}
                                    >
                                        <XCircle className="mr-1.5 h-4 w-4" />
                                        Cancel offboarding
                                    </Button>
                                )}
                                {can.manage &&
                                    checklist.status === 'cancelled' && (
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            onClick={() =>
                                                setChecklistStatus(
                                                    'in_progress',
                                                )
                                            }
                                        >
                                            <RotateCcw className="mr-1.5 h-4 w-4" />
                                            Resume offboarding
                                        </Button>
                                    )}
                                <Badge
                                    variant="outline"
                                    className={config.className}
                                >
                                    {config.label}
                                </Badge>
                            </div>
                        </div>
                    </CardHeader>
                    <CardContent>
                        <div className="grid gap-4 sm:grid-cols-3">
                            <div>
                                <p className="text-sm text-muted-foreground">
                                    Last Working Day
                                </p>
                                <p className="font-medium">
                                    {checklist.due_date || '-'}
                                </p>
                            </div>
                            <div>
                                <p className="text-sm text-muted-foreground">
                                    Started
                                </p>
                                <p className="font-medium">
                                    {checklist.started_at || '-'}
                                </p>
                            </div>
                            <div>
                                <p className="text-sm text-muted-foreground">
                                    Completed
                                </p>
                                <p className="font-medium">
                                    {checklist.completed_at || '-'}
                                </p>
                            </div>
                        </div>
                        <div className="mt-4">
                            <div className="mb-1 flex items-center justify-between text-sm">
                                <span className="text-muted-foreground">
                                    Progress
                                </span>
                                <span className="font-medium">
                                    {progress.completed}/{progress.total} tasks
                                    ({progress.percent}%)
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
                            <p className="py-4 text-center text-muted-foreground">
                                No tasks found for this checklist.
                            </p>
                        ) : (
                            <div className="space-y-3">
                                {checklist.tasks
                                    .sort((a, b) => a.sort_order - b.sort_order)
                                    .map((task) => {
                                        const completed =
                                            task.status === 'completed';
                                        return (
                                            <div
                                                key={task.id}
                                                className={`rounded-lg border p-3 ${completed ? 'bg-muted/30 opacity-70' : ''}`}
                                            >
                                                <div className="flex items-start justify-between gap-3">
                                                    <div className="flex-1">
                                                        <p
                                                            className={`font-medium ${completed ? 'line-through' : ''}`}
                                                        >
                                                            {task.title}
                                                        </p>
                                                        {task.description && (
                                                            <p className="mt-1 text-sm text-muted-foreground">
                                                                {
                                                                    task.description
                                                                }
                                                            </p>
                                                        )}
                                                        <div className="mt-2 flex flex-wrap items-center gap-2 text-xs text-muted-foreground">
                                                            <span>
                                                                Category:{' '}
                                                                {task.category}
                                                            </span>
                                                            <span>
                                                                Due:{' '}
                                                                {task.due_date ||
                                                                    '-'}
                                                            </span>
                                                            <span>
                                                                Assigned:{' '}
                                                                {task
                                                                    .assigned_to
                                                                    ?.name ||
                                                                    task
                                                                        .assigned_to
                                                                        ?.id ||
                                                                    '-'}
                                                            </span>
                                                        </div>
                                                        <div className="mt-2 flex items-center gap-2">
                                                            {task.is_required && (
                                                                <Badge variant="secondary">
                                                                    Required
                                                                </Badge>
                                                            )}
                                                            {task.sign_off_required && (
                                                                <Badge variant="outline">
                                                                    Sign-off
                                                                </Badge>
                                                            )}
                                                            {completed && (
                                                                <Badge className="bg-status-success">
                                                                    Completed
                                                                </Badge>
                                                            )}
                                                        </div>
                                                    </div>

                                                    <div className="flex shrink-0 items-center gap-2">
                                                        {can.manage &&
                                                            isExitInterviewTask(
                                                                task,
                                                            ) && (
                                                                <Button
                                                                    size="sm"
                                                                    variant="outline"
                                                                    onClick={() =>
                                                                        setExitDialogOpen(
                                                                            true,
                                                                        )
                                                                    }
                                                                >
                                                                    <MessageSquare className="mr-1.5 h-4 w-4" />
                                                                    Record exit
                                                                    interview
                                                                </Button>
                                                            )}
                                                        {completed ? (
                                                            <Button
                                                                size="sm"
                                                                variant="secondary"
                                                                disabled={
                                                                    !can.manage
                                                                }
                                                                onClick={() =>
                                                                    reopenTask(
                                                                        task,
                                                                    )
                                                                }
                                                            >
                                                                <RotateCcw className="mr-1.5 h-3.5 w-3.5" />
                                                                Reopen
                                                            </Button>
                                                        ) : (
                                                            <Button
                                                                size="sm"
                                                                disabled={
                                                                    !can.manage
                                                                }
                                                                onClick={() =>
                                                                    completeTask(
                                                                        task,
                                                                    )
                                                                }
                                                            >
                                                                Complete
                                                            </Button>
                                                        )}
                                                    </div>
                                                </div>
                                            </div>
                                        );
                                    })}
                            </div>
                        )}
                    </CardContent>
                </Card>
            </PageShell>

            {can.manage && (
                <ExitInterviewDialog
                    open={exitDialogOpen}
                    onClose={() => setExitDialogOpen(false)}
                    employeeProfileId={checklist.employee_profile.id}
                    interviewers={interviewers}
                    departureReasons={departureReasons}
                />
            )}

            <AlertDialog open={cancelOpen} onOpenChange={setCancelOpen}>
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>
                            Cancel this offboarding?
                        </AlertDialogTitle>
                        <AlertDialogDescription>
                            Use this when the departure is not going ahead —
                            e.g. a retracted resignation. The checklist and
                            its history are kept, tasks stop counting as due,
                            and it can be resumed later. System access is not
                            changed.
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel>Keep offboarding</AlertDialogCancel>
                        <AlertDialogAction
                            onClick={() => {
                                setCancelOpen(false);
                                setChecklistStatus('cancelled');
                            }}
                        >
                            Cancel offboarding
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        </AppLayout>
    );
}
