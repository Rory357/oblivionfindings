import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { StatusBadge, type StatusVariant } from '@/components/ui/status-badge';
import { Textarea } from '@/components/ui/textarea';
import { formatDateTime } from '@/lib/datetime';
import { router } from '@inertiajs/react';
import {
    Ban,
    CalendarClock,
    CheckCircle2,
    CircleAlert,
    ClipboardCheck,
    Clock3,
    FileCheck2,
    ListChecks,
    Pencil,
    PlayCircle,
    Plus,
    RotateCcw,
    UserRound,
    UsersRound,
} from 'lucide-react';
import { type FormEvent, type ReactNode, useState } from 'react';
import { toast } from 'sonner';

export interface TicketWorkTask {
    id: number;
    title: string;
    description: string | null;
    status: 'pending' | 'in_progress' | 'blocked' | 'completed' | 'cancelled';
    due_at: string | null;
    is_required: boolean;
    evidence_required: boolean;
    evidence: string[] | null;
    completion_note: string | null;
    completed_at: string | null;
    sort_order: number;
    team: { id: number; name: string } | null;
    assignee: { id: number; name: string } | null;
    completed_by: { id: number; name: string } | null;
    dependencies: {
        id: number;
        title: string;
        status: string;
    }[];
}

interface Option {
    id: number;
    name: string;
}

interface Props {
    ticketId: number;
    tasks: TicketWorkTask[];
    canManage: boolean;
    assignees: Option[];
    teams: Option[];
}

type TaskDialog =
    | { type: 'create' }
    | { type: 'edit'; task: TicketWorkTask }
    | { type: 'complete'; task: TicketWorkTask }
    | { type: 'reopen'; task: TicketWorkTask }
    | null;

interface TaskDraft {
    title: string;
    description: string;
    status: 'pending' | 'in_progress' | 'blocked' | 'cancelled';
    team_id: string;
    assigned_to_user_id: string;
    due_at: string;
    is_required: boolean;
    evidence_required: boolean;
    dependency_ids: number[];
    reason: string;
}

const NONE = 'none';

const taskStatus = (
    value: TicketWorkTask['status'],
): {
    label: string;
    variant: StatusVariant;
    icon: typeof Clock3;
} => {
    switch (value) {
        case 'in_progress':
            return { label: 'In progress', variant: 'info', icon: PlayCircle };
        case 'blocked':
            return { label: 'Blocked', variant: 'critical', icon: CircleAlert };
        case 'completed':
            return {
                label: 'Completed',
                variant: 'success',
                icon: CheckCircle2,
            };
        case 'cancelled':
            return { label: 'Cancelled', variant: 'neutral', icon: Ban };
        default:
            return { label: 'Pending', variant: 'warning', icon: Clock3 };
    }
};

const toDateTimeInput = (value: string | null): string => {
    if (!value) return '';
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return '';
    const local = new Date(date.getTime() - date.getTimezoneOffset() * 60_000);
    return local.toISOString().slice(0, 16);
};

const blankDraft = (): TaskDraft => ({
    title: '',
    description: '',
    status: 'pending',
    team_id: NONE,
    assigned_to_user_id: NONE,
    due_at: '',
    is_required: true,
    evidence_required: false,
    dependency_ids: [],
    reason: '',
});

const draftFromTask = (task: TicketWorkTask): TaskDraft => ({
    title: task.title,
    description: task.description ?? '',
    status: task.status === 'completed' ? 'pending' : task.status,
    team_id: task.team ? String(task.team.id) : NONE,
    assigned_to_user_id: task.assignee ? String(task.assignee.id) : NONE,
    due_at: toDateTimeInput(task.due_at),
    is_required: task.is_required,
    evidence_required: task.evidence_required,
    dependency_ids: task.dependencies.map((dependency) => dependency.id),
    reason: '',
});

export function TicketWorkTasks({
    ticketId,
    tasks,
    canManage,
    assignees,
    teams,
}: Props) {
    const [dialog, setDialog] = useState<TaskDialog>(null);
    const [draft, setDraft] = useState<TaskDraft>(blankDraft);
    const [completionNote, setCompletionNote] = useState('');
    const [evidence, setEvidence] = useState('');
    const [reopenReason, setReopenReason] = useState('');
    const [processing, setProcessing] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});

    const completedCount = tasks.filter(
        (task) => task.status === 'completed',
    ).length;
    const requiredOutstanding = tasks.filter(
        (task) => task.is_required && task.status !== 'completed',
    ).length;
    if (tasks.length === 0 && !canManage) return null;

    const resetDialog = () => {
        setDialog(null);
        setErrors({});
        setCompletionNote('');
        setEvidence('');
        setReopenReason('');
    };

    const closeDialog = () => {
        if (processing) return;
        resetDialog();
    };

    const openCreate = () => {
        setDraft(blankDraft());
        setErrors({});
        setDialog({ type: 'create' });
    };

    const openEdit = (task: TicketWorkTask) => {
        setDraft(draftFromTask(task));
        setErrors({});
        setDialog({ type: 'edit', task });
    };

    const flashResult = (
        page: { props: Record<string, unknown> },
        fallback: string,
    ): boolean => {
        const flash = page.props.flash as
            | { error?: string; success?: string }
            | undefined;
        if (flash?.error) {
            toast.error(flash.error);
            return false;
        }
        toast.success(flash?.success ?? fallback);
        return true;
    };

    const taskPayload = () => ({
        title: draft.title.trim(),
        description: draft.description.trim() || null,
        ...(dialog?.type === 'edit' ? { status: draft.status } : {}),
        team_id: draft.team_id === NONE ? null : Number(draft.team_id),
        assigned_to_user_id:
            draft.assigned_to_user_id === NONE
                ? null
                : Number(draft.assigned_to_user_id),
        due_at: draft.due_at || null,
        is_required: draft.is_required,
        evidence_required: draft.evidence_required,
        dependency_ids: draft.dependency_ids,
        ...(dialog?.type === 'edit' && draft.status === 'cancelled'
            ? { reason: draft.reason.trim() }
            : {}),
    });

    const submitTask = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        if (draft.title.trim() === '' || !dialog) return;
        if (
            dialog.type === 'edit' &&
            draft.status === 'cancelled' &&
            (draft.is_required || draft.reason.trim() === '')
        )
            return;

        const options = {
            preserveScroll: true,
            onStart: () => setProcessing(true),
            onError: (nextErrors: Record<string, string>) =>
                setErrors(nextErrors),
            onSuccess: (page: { props: Record<string, unknown> }) => {
                if (
                    flashResult(
                        page,
                        dialog.type === 'create'
                            ? 'Work task added.'
                            : 'Work task updated.',
                    )
                )
                    resetDialog();
            },
            onFinish: () => setProcessing(false),
        };

        if (dialog.type === 'create') {
            router.post(
                `/it/tickets/${ticketId}/tasks`,
                taskPayload(),
                options,
            );
        } else if (dialog.type === 'edit') {
            router.patch(
                `/it/tickets/${ticketId}/tasks/${dialog.task.id}`,
                taskPayload(),
                options,
            );
        }
    };

    const submitCompletion = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        if (dialog?.type !== 'complete') return;
        const evidenceRows = evidence
            .split(/\r?\n/)
            .map((row) => row.trim())
            .filter(Boolean)
            .slice(0, 20);
        if (dialog.task.evidence_required && evidenceRows.length === 0) return;

        router.post(
            `/it/tickets/${ticketId}/tasks/${dialog.task.id}/complete`,
            {
                completion_note: completionNote.trim() || null,
                evidence: evidenceRows,
            },
            {
                preserveScroll: true,
                onStart: () => setProcessing(true),
                onError: (nextErrors) => setErrors(nextErrors),
                onSuccess: (page) => {
                    if (flashResult(page, 'Work task completed.'))
                        resetDialog();
                },
                onFinish: () => setProcessing(false),
            },
        );
    };

    const submitReopen = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        if (dialog?.type !== 'reopen' || reopenReason.trim() === '') return;

        router.post(
            `/it/tickets/${ticketId}/tasks/${dialog.task.id}/reopen`,
            { reason: reopenReason.trim() },
            {
                preserveScroll: true,
                onStart: () => setProcessing(true),
                onError: (nextErrors) => setErrors(nextErrors),
                onSuccess: (page) => {
                    if (flashResult(page, 'Work task reopened.')) resetDialog();
                },
                onFinish: () => setProcessing(false),
            },
        );
    };

    const toggleDependency = (id: number, checked: boolean) => {
        setDraft((current) => ({
            ...current,
            dependency_ids: checked
                ? [...current.dependency_ids, id]
                : current.dependency_ids.filter(
                      (candidate) => candidate !== id,
                  ),
        }));
    };

    const editingTaskId = dialog?.type === 'edit' ? dialog.task.id : null;
    const dependencyOptions = tasks.filter(
        (task) => task.id !== editingTaskId && task.status !== 'cancelled',
    );

    return (
        <section
            aria-labelledby="ticket-work-tasks"
            className="border-t border-border/60 pt-3"
        >
            <div className="flex flex-wrap items-center justify-between gap-2">
                <div className="flex items-center gap-2">
                    <ListChecks
                        aria-hidden="true"
                        className="h-3.5 w-3.5 text-muted-foreground"
                    />
                    <h2
                        id="ticket-work-tasks"
                        className="text-[11px] font-bold tracking-wide text-muted-foreground uppercase"
                    >
                        Work tasks
                    </h2>
                </div>
                {canManage ? (
                    <Button
                        type="button"
                        size="sm"
                        variant="outline"
                        className="frontline-focus min-h-11"
                        onClick={openCreate}
                    >
                        <Plus aria-hidden="true" className="h-3.5 w-3.5" />
                        Add task
                    </Button>
                ) : null}
            </div>

            {tasks.length > 0 ? (
                <div className="mt-2 flex flex-wrap items-center gap-1.5">
                    <StatusBadge
                        variant={
                            completedCount === tasks.length ? 'success' : 'info'
                        }
                        size="sm"
                    >
                        <ClipboardCheck
                            aria-hidden="true"
                            className="h-3 w-3"
                        />
                        {completedCount} of {tasks.length} complete
                    </StatusBadge>
                    {requiredOutstanding > 0 ? (
                        <StatusBadge variant="warning" size="sm">
                            <CircleAlert
                                aria-hidden="true"
                                className="h-3 w-3"
                            />
                            {requiredOutstanding} required outstanding
                        </StatusBadge>
                    ) : null}
                </div>
            ) : null}

            {tasks.length === 0 ? (
                <div className="mt-2 rounded-xl border border-dashed border-border px-3 py-3 text-center">
                    <ListChecks
                        aria-hidden="true"
                        className="mx-auto h-4 w-4 text-muted-foreground"
                    />
                    <p className="mt-1 text-[12px] text-muted-foreground">
                        No work tasks have been added.
                    </p>
                </div>
            ) : (
                <ul className="mt-2 space-y-2">
                    {tasks.map((task) => {
                        const presentation = taskStatus(task.status);
                        const StatusIcon = presentation.icon;
                        const incompleteDependencies = task.dependencies.filter(
                            (dependency) => dependency.status !== 'completed',
                        );
                        const overdue =
                            task.due_at !== null &&
                            task.status !== 'completed' &&
                            task.status !== 'cancelled' &&
                            new Date(task.due_at).getTime() < Date.now();

                        return (
                            <li
                                key={task.id}
                                className="rounded-xl border border-border/70 bg-muted/20 p-3"
                            >
                                <div className="flex items-start justify-between gap-2">
                                    <div className="min-w-0 flex-1">
                                        <p className="text-[12.5px] font-semibold text-foreground">
                                            {task.title}
                                        </p>
                                        <div className="mt-1 flex flex-wrap gap-1.5">
                                            <StatusBadge
                                                variant={presentation.variant}
                                                size="sm"
                                            >
                                                <StatusIcon
                                                    aria-hidden="true"
                                                    className="h-3 w-3"
                                                />
                                                {presentation.label}
                                            </StatusBadge>
                                            <StatusBadge
                                                variant={
                                                    task.is_required
                                                        ? 'warning'
                                                        : 'neutral'
                                                }
                                                size="sm"
                                            >
                                                {task.is_required
                                                    ? 'Required'
                                                    : 'Optional'}
                                            </StatusBadge>
                                            {task.evidence_required ? (
                                                <StatusBadge
                                                    variant="info"
                                                    size="sm"
                                                >
                                                    <FileCheck2
                                                        aria-hidden="true"
                                                        className="h-3 w-3"
                                                    />
                                                    Evidence required
                                                </StatusBadge>
                                            ) : null}
                                        </div>
                                    </div>
                                </div>

                                {task.description ? (
                                    <p className="mt-2 text-[11.5px] whitespace-pre-wrap text-muted-foreground">
                                        {task.description}
                                    </p>
                                ) : null}

                                <div className="mt-2 space-y-1 text-[11px] text-muted-foreground">
                                    {task.due_at ? (
                                        <p
                                            className={
                                                overdue
                                                    ? 'flex items-center gap-1.5 font-semibold text-destructive'
                                                    : 'flex items-center gap-1.5'
                                            }
                                        >
                                            <CalendarClock
                                                aria-hidden="true"
                                                className="h-3.5 w-3.5"
                                            />
                                            {overdue ? 'Overdue' : 'Due'} ·{' '}
                                            {formatDateTime(task.due_at)}
                                        </p>
                                    ) : null}
                                    {task.team ? (
                                        <p className="flex items-center gap-1.5">
                                            <UsersRound
                                                aria-hidden="true"
                                                className="h-3.5 w-3.5"
                                            />
                                            Team · {task.team.name}
                                        </p>
                                    ) : null}
                                    {task.assignee ? (
                                        <p className="flex items-center gap-1.5">
                                            <UserRound
                                                aria-hidden="true"
                                                className="h-3.5 w-3.5"
                                            />
                                            Owner · {task.assignee.name}
                                        </p>
                                    ) : null}
                                </div>

                                {task.dependencies.length > 0 ? (
                                    <Card className="mt-2 gap-0 rounded-lg border-border/60 px-2.5 py-2 shadow-none">
                                        <p className="text-[10.5px] font-semibold text-muted-foreground">
                                            Depends on
                                        </p>
                                        <ul className="mt-1 space-y-1">
                                            {task.dependencies.map(
                                                (dependency) => (
                                                    <li
                                                        key={dependency.id}
                                                        className="flex items-center gap-1.5 text-[11px]"
                                                    >
                                                        {dependency.status ===
                                                        'completed' ? (
                                                            <CheckCircle2
                                                                aria-hidden="true"
                                                                className="h-3.5 w-3.5 text-status-success"
                                                            />
                                                        ) : (
                                                            <Clock3
                                                                aria-hidden="true"
                                                                className="h-3.5 w-3.5 text-status-warning"
                                                            />
                                                        )}
                                                        <span>
                                                            {dependency.title}
                                                        </span>
                                                    </li>
                                                ),
                                            )}
                                        </ul>
                                    </Card>
                                ) : null}

                                {task.status === 'completed' ? (
                                    <div className="mt-2 rounded-lg border border-status-success/25 bg-status-success-bg px-2.5 py-2 text-[11px]">
                                        <p className="font-semibold text-status-success">
                                            Completed
                                            {task.completed_by
                                                ? ` by ${task.completed_by.name}`
                                                : ''}
                                            {task.completed_at
                                                ? ` · ${formatDateTime(task.completed_at)}`
                                                : ''}
                                        </p>
                                        {task.completion_note ? (
                                            <p className="mt-1 text-foreground">
                                                {task.completion_note}
                                            </p>
                                        ) : null}
                                        {task.evidence?.length ? (
                                            <ul className="mt-1 list-disc space-y-0.5 pl-4 text-muted-foreground">
                                                {task.evidence.map(
                                                    (item, index) => (
                                                        <li
                                                            key={`${task.id}-evidence-${index}`}
                                                        >
                                                            {item}
                                                        </li>
                                                    ),
                                                )}
                                            </ul>
                                        ) : null}
                                    </div>
                                ) : null}

                                {canManage ? (
                                    <div className="mt-2 flex flex-wrap items-center gap-2 border-t border-border/60 pt-2">
                                        {task.status === 'completed' ? (
                                            <Button
                                                type="button"
                                                size="sm"
                                                variant="outline"
                                                className="frontline-focus min-h-11"
                                                onClick={() => {
                                                    setReopenReason('');
                                                    setErrors({});
                                                    setDialog({
                                                        type: 'reopen',
                                                        task,
                                                    });
                                                }}
                                            >
                                                <RotateCcw
                                                    aria-hidden="true"
                                                    className="h-3.5 w-3.5"
                                                />
                                                Reopen task
                                            </Button>
                                        ) : (
                                            <>
                                                <Button
                                                    type="button"
                                                    size="sm"
                                                    variant="outline"
                                                    className="frontline-focus min-h-11"
                                                    onClick={() =>
                                                        openEdit(task)
                                                    }
                                                >
                                                    <Pencil
                                                        aria-hidden="true"
                                                        className="h-3.5 w-3.5"
                                                    />
                                                    Edit task
                                                </Button>
                                                {task.status !== 'cancelled' ? (
                                                    <Button
                                                        type="button"
                                                        size="sm"
                                                        className="frontline-focus min-h-11"
                                                        disabled={
                                                            incompleteDependencies.length >
                                                            0
                                                        }
                                                        onClick={() => {
                                                            setCompletionNote(
                                                                '',
                                                            );
                                                            setEvidence('');
                                                            setErrors({});
                                                            setDialog({
                                                                type: 'complete',
                                                                task,
                                                            });
                                                        }}
                                                    >
                                                        <CheckCircle2
                                                            aria-hidden="true"
                                                            className="h-3.5 w-3.5"
                                                        />
                                                        Complete task
                                                    </Button>
                                                ) : null}
                                            </>
                                        )}
                                        {incompleteDependencies.length > 0 &&
                                        task.status !== 'completed' ? (
                                            <p className="w-full text-[10.5px] text-status-warning">
                                                Complete{' '}
                                                {incompleteDependencies.length}{' '}
                                                prerequisite
                                                {incompleteDependencies.length ===
                                                1
                                                    ? ''
                                                    : 's'}{' '}
                                                first.
                                            </p>
                                        ) : null}
                                    </div>
                                ) : null}
                            </li>
                        );
                    })}
                </ul>
            )}

            <Dialog
                open={dialog?.type === 'create' || dialog?.type === 'edit'}
                onOpenChange={(open) => !open && closeDialog()}
            >
                <DialogContent className="max-h-[88vh] overflow-y-auto sm:max-w-2xl">
                    <form onSubmit={submitTask}>
                        <DialogHeader>
                            <DialogTitle>
                                {dialog?.type === 'edit'
                                    ? 'Edit work task'
                                    : 'Add work task'}
                            </DialogTitle>
                            <DialogDescription>
                                Break the ticket into owned, auditable work.
                                Required tasks and prerequisites must be
                                complete before settlement.
                            </DialogDescription>
                        </DialogHeader>

                        <div className="mt-5 grid gap-4 sm:grid-cols-2">
                            <TaskField
                                id="task-title"
                                label="Task title"
                                error={errors.title}
                                className="sm:col-span-2"
                            >
                                <Input
                                    id="task-title"
                                    value={draft.title}
                                    onChange={(event) =>
                                        setDraft((current) => ({
                                            ...current,
                                            title: event.target.value,
                                        }))
                                    }
                                    maxLength={255}
                                    required
                                    placeholder="State the specific outcome"
                                />
                            </TaskField>

                            <TaskField
                                id="task-description"
                                label="Instructions (optional)"
                                error={errors.description}
                                className="sm:col-span-2"
                            >
                                <Textarea
                                    id="task-description"
                                    value={draft.description}
                                    onChange={(event) =>
                                        setDraft((current) => ({
                                            ...current,
                                            description: event.target.value,
                                        }))
                                    }
                                    maxLength={5000}
                                    rows={3}
                                    placeholder="What must be done and how should it be verified?"
                                />
                            </TaskField>

                            {dialog?.type === 'edit' ? (
                                <TaskField
                                    id="task-status"
                                    label="Working status"
                                    error={errors.status}
                                >
                                    <Select
                                        value={draft.status}
                                        onValueChange={(value) =>
                                            setDraft((current) => ({
                                                ...current,
                                                status: value as TaskDraft['status'],
                                                reason:
                                                    value === 'cancelled'
                                                        ? current.reason
                                                        : '',
                                            }))
                                        }
                                    >
                                        <SelectTrigger
                                            id="task-status"
                                            className="frontline-focus min-h-11"
                                        >
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="pending">
                                                Pending
                                            </SelectItem>
                                            <SelectItem value="in_progress">
                                                In progress
                                            </SelectItem>
                                            <SelectItem value="blocked">
                                                Blocked
                                            </SelectItem>
                                            <SelectItem value="cancelled">
                                                Cancelled
                                            </SelectItem>
                                        </SelectContent>
                                    </Select>
                                </TaskField>
                            ) : null}

                            <TaskField
                                id="task-due-at"
                                label="Due date and time (optional)"
                                error={errors.due_at}
                            >
                                <Input
                                    id="task-due-at"
                                    type="datetime-local"
                                    value={draft.due_at}
                                    onChange={(event) =>
                                        setDraft((current) => ({
                                            ...current,
                                            due_at: event.target.value,
                                        }))
                                    }
                                    className="min-h-11"
                                />
                            </TaskField>

                            <TaskField
                                id="task-team"
                                label="Responsible team (optional)"
                                error={errors.team_id}
                            >
                                <Select
                                    value={draft.team_id}
                                    onValueChange={(value) =>
                                        setDraft((current) => ({
                                            ...current,
                                            team_id: value,
                                        }))
                                    }
                                >
                                    <SelectTrigger
                                        id="task-team"
                                        className="frontline-focus min-h-11"
                                    >
                                        <SelectValue placeholder="No team" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value={NONE}>
                                            No team
                                        </SelectItem>
                                        {teams.map((team) => (
                                            <SelectItem
                                                key={team.id}
                                                value={String(team.id)}
                                            >
                                                {team.name}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </TaskField>

                            <TaskField
                                id="task-assignee"
                                label="Task owner (optional)"
                                error={errors.assigned_to_user_id}
                            >
                                <Select
                                    value={draft.assigned_to_user_id}
                                    onValueChange={(value) =>
                                        setDraft((current) => ({
                                            ...current,
                                            assigned_to_user_id: value,
                                        }))
                                    }
                                >
                                    <SelectTrigger
                                        id="task-assignee"
                                        className="frontline-focus min-h-11"
                                    >
                                        <SelectValue placeholder="No owner" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value={NONE}>
                                            No owner
                                        </SelectItem>
                                        {assignees.map((assignee) => (
                                            <SelectItem
                                                key={assignee.id}
                                                value={String(assignee.id)}
                                            >
                                                {assignee.name}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </TaskField>

                            <div className="space-y-2 sm:col-span-2">
                                <CheckRow
                                    id="task-required"
                                    checked={draft.is_required}
                                    onChange={(checked) =>
                                        setDraft((current) => ({
                                            ...current,
                                            is_required: checked,
                                            status:
                                                checked &&
                                                current.status === 'cancelled'
                                                    ? 'pending'
                                                    : current.status,
                                        }))
                                    }
                                    title="Required before settlement"
                                    description="The ticket cannot be resolved until this task is complete."
                                />
                                <CheckRow
                                    id="task-evidence-required"
                                    checked={draft.evidence_required}
                                    onChange={(checked) =>
                                        setDraft((current) => ({
                                            ...current,
                                            evidence_required: checked,
                                        }))
                                    }
                                    title="Require completion evidence"
                                    description="The technician must record at least one evidence reference."
                                />
                            </div>

                            {dependencyOptions.length > 0 ? (
                                <fieldset className="rounded-xl border border-border p-3 sm:col-span-2">
                                    <legend className="px-1 text-sm font-medium">
                                        Prerequisites (optional)
                                    </legend>
                                    <p className="mb-2 text-xs text-muted-foreground">
                                        This task cannot be completed until
                                        every selected prerequisite is complete.
                                    </p>
                                    <div className="grid gap-2 sm:grid-cols-2">
                                        {dependencyOptions.map((candidate) => (
                                            <CheckRow
                                                key={candidate.id}
                                                id={`task-dependency-${candidate.id}`}
                                                checked={draft.dependency_ids.includes(
                                                    candidate.id,
                                                )}
                                                onChange={(checked) =>
                                                    toggleDependency(
                                                        candidate.id,
                                                        checked,
                                                    )
                                                }
                                                title={candidate.title}
                                                description={
                                                    taskStatus(candidate.status)
                                                        .label
                                                }
                                            />
                                        ))}
                                    </div>
                                    {errors.dependency_ids ? (
                                        <p
                                            role="alert"
                                            className="mt-2 text-xs text-destructive"
                                        >
                                            {errors.dependency_ids}
                                        </p>
                                    ) : null}
                                </fieldset>
                            ) : null}

                            {dialog?.type === 'edit' &&
                            draft.status === 'cancelled' ? (
                                <TaskField
                                    id="task-cancel-reason"
                                    label="Reason for cancelling"
                                    error={errors.reason}
                                    className="sm:col-span-2"
                                >
                                    <Textarea
                                        id="task-cancel-reason"
                                        value={draft.reason}
                                        onChange={(event) =>
                                            setDraft((current) => ({
                                                ...current,
                                                reason: event.target.value,
                                            }))
                                        }
                                        rows={3}
                                        maxLength={2000}
                                        required
                                        disabled={draft.is_required}
                                        placeholder="Explain why this optional task is no longer needed"
                                    />
                                    {draft.is_required ? (
                                        <p className="text-xs text-status-warning">
                                            Required tasks cannot be cancelled.
                                            Make it optional first or complete
                                            it.
                                        </p>
                                    ) : null}
                                </TaskField>
                            ) : null}
                        </div>

                        <DialogFooter className="mt-6 gap-2 sm:gap-0">
                            <Button
                                type="button"
                                variant="outline"
                                className="min-h-11"
                                disabled={processing}
                                onClick={closeDialog}
                            >
                                Keep reviewing
                            </Button>
                            <Button
                                type="submit"
                                className="min-h-11"
                                disabled={
                                    processing ||
                                    draft.title.trim() === '' ||
                                    (dialog?.type === 'edit' &&
                                        draft.status === 'cancelled' &&
                                        (draft.is_required ||
                                            draft.reason.trim() === ''))
                                }
                            >
                                {dialog?.type === 'edit' ? (
                                    <Pencil
                                        aria-hidden="true"
                                        className="h-4 w-4"
                                    />
                                ) : (
                                    <Plus
                                        aria-hidden="true"
                                        className="h-4 w-4"
                                    />
                                )}
                                {processing
                                    ? 'Saving…'
                                    : dialog?.type === 'edit'
                                      ? 'Save task'
                                      : 'Add task'}
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            <Dialog
                open={dialog?.type === 'complete'}
                onOpenChange={(open) => !open && closeDialog()}
            >
                <DialogContent className="sm:max-w-lg">
                    <form onSubmit={submitCompletion}>
                        <DialogHeader>
                            <DialogTitle>Complete work task</DialogTitle>
                            <DialogDescription>
                                Confirm the outcome for “
                                {dialog?.type === 'complete'
                                    ? dialog.task.title
                                    : 'this task'}
                                ”. This becomes part of the ticket&apos;s
                                working record.
                            </DialogDescription>
                        </DialogHeader>
                        <div className="mt-5 space-y-4">
                            <TaskField
                                id="task-completion-note"
                                label="Completion note (optional)"
                                error={errors.completion_note}
                            >
                                <Textarea
                                    id="task-completion-note"
                                    value={completionNote}
                                    onChange={(event) =>
                                        setCompletionNote(event.target.value)
                                    }
                                    rows={4}
                                    maxLength={5000}
                                    placeholder="What was completed and verified?"
                                />
                            </TaskField>
                            <TaskField
                                id="task-completion-evidence"
                                label={
                                    dialog?.type === 'complete' &&
                                    dialog.task.evidence_required
                                        ? 'Evidence references'
                                        : 'Evidence references (optional)'
                                }
                                error={errors.evidence}
                            >
                                <Textarea
                                    id="task-completion-evidence"
                                    value={evidence}
                                    onChange={(event) =>
                                        setEvidence(event.target.value)
                                    }
                                    rows={4}
                                    maxLength={10_000}
                                    required={
                                        dialog?.type === 'complete' &&
                                        dialog.task.evidence_required
                                    }
                                    placeholder="One ticket, screenshot, test or change reference per line"
                                />
                            </TaskField>
                        </div>
                        <DialogFooter className="mt-6 gap-2 sm:gap-0">
                            <Button
                                type="button"
                                variant="outline"
                                className="min-h-11"
                                disabled={processing}
                                onClick={closeDialog}
                            >
                                Keep open
                            </Button>
                            <Button
                                type="submit"
                                className="min-h-11"
                                disabled={
                                    processing ||
                                    (dialog?.type === 'complete' &&
                                        dialog.task.evidence_required &&
                                        evidence.trim() === '')
                                }
                            >
                                <CheckCircle2
                                    aria-hidden="true"
                                    className="h-4 w-4"
                                />
                                {processing ? 'Completing…' : 'Complete task'}
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            <Dialog
                open={dialog?.type === 'reopen'}
                onOpenChange={(open) => !open && closeDialog()}
            >
                <DialogContent className="sm:max-w-lg">
                    <form onSubmit={submitReopen}>
                        <DialogHeader>
                            <DialogTitle>Reopen work task</DialogTitle>
                            <DialogDescription>
                                Reopening clears the previous completion note
                                and evidence. Record what changed so the next
                                technician knows what to verify again.
                            </DialogDescription>
                        </DialogHeader>
                        <div className="mt-5">
                            <TaskField
                                id="task-reopen-reason"
                                label="Reason for reopening"
                                error={errors.reason}
                            >
                                <Textarea
                                    id="task-reopen-reason"
                                    value={reopenReason}
                                    onChange={(event) =>
                                        setReopenReason(event.target.value)
                                    }
                                    rows={4}
                                    maxLength={2000}
                                    required
                                    placeholder="For example, the change was rolled back and evidence must be collected again"
                                />
                            </TaskField>
                        </div>
                        <DialogFooter className="mt-6 gap-2 sm:gap-0">
                            <Button
                                type="button"
                                variant="outline"
                                className="min-h-11"
                                disabled={processing}
                                onClick={closeDialog}
                            >
                                Keep completed
                            </Button>
                            <Button
                                type="submit"
                                className="min-h-11"
                                disabled={
                                    processing || reopenReason.trim() === ''
                                }
                            >
                                <RotateCcw
                                    aria-hidden="true"
                                    className="h-4 w-4"
                                />
                                {processing ? 'Reopening…' : 'Reopen task'}
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </section>
    );
}

function TaskField({
    id,
    label,
    error,
    className,
    children,
}: {
    id: string;
    label: string;
    error?: string;
    className?: string;
    children: ReactNode;
}) {
    return (
        <div className={className}>
            <label htmlFor={id} className="text-sm font-medium">
                {label}
            </label>
            <div className="mt-1.5">{children}</div>
            {error ? (
                <p role="alert" className="mt-1 text-xs text-destructive">
                    {error}
                </p>
            ) : null}
        </div>
    );
}

function CheckRow({
    id,
    checked,
    onChange,
    title,
    description,
}: {
    id: string;
    checked: boolean;
    onChange: (checked: boolean) => void;
    title: string;
    description: string;
}) {
    return (
        <label
            htmlFor={id}
            className="frontline-focus flex min-h-11 cursor-pointer items-start gap-2 rounded-lg border border-border/70 bg-muted/20 px-3 py-2"
        >
            <Checkbox
                id={id}
                checked={checked}
                onCheckedChange={(value) => onChange(value === true)}
                className="mt-0.5"
            />
            <span className="min-w-0">
                <span className="block text-[12px] font-semibold">{title}</span>
                <span className="block text-[10.5px] text-muted-foreground">
                    {description}
                </span>
            </span>
        </label>
    );
}
