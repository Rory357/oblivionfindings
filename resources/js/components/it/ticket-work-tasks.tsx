import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { EmptyState } from '@/components/ui/empty-state';
import { StatusBadge, type StatusVariant } from '@/components/ui/status-badge';
import type {
    ItWorkTaskCommitted,
    ItWorkTaskOperation,
    ItWorkTaskRecord,
} from '@/hooks/it-work-task-command';
import { summarizeItWorkTasks } from '@/hooks/it-work-task-lifecycle';
import {
    purgeItWorkTaskMemory,
    useItWorkTaskMemoryNotices,
} from '@/hooks/use-it-ticket-draft-memory';
import { pendingItWorkTaskCommands } from '@/hooks/use-it-work-task-command';
import { formatDateTime } from '@/lib/datetime';
import {
    Ban,
    CalendarClock,
    CheckCircle2,
    CircleAlert,
    ClipboardCheck,
    Clock3,
    ListChecks,
    ListOrdered,
    Pencil,
    PlayCircle,
    Plus,
    RotateCcw,
    UserRound,
    UsersRound,
} from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { TicketWorkTaskActionDialog } from './ticket-work-task-action-dialog';
import {
    TicketWorkTaskHistory,
    TicketWorkTaskReadiness,
} from './ticket-work-task-history';
import { TicketWorkTaskWizard } from './ticket-work-task-wizard';

export type TicketWorkTask = ItWorkTaskRecord;
interface Option {
    id: number;
    name: string;
}
interface Props {
    actorId: number;
    ticketId: number;
    version: number;
    tasks: TicketWorkTask[];
    /** A redacted task array is not an empty work register. */
    canViewWork: boolean;
    canManage: boolean;
    taskWork?: {
        storage_ready: boolean;
        can_create: boolean;
        can_reorder: boolean;
    };
    assignees: Option[];
    teams: Option[];
    approvals?: { id: number; status: string }[];
    onCommitted: (result: ItWorkTaskCommitted) => void;
    onAccessLost?: () => void;
    onSessionExpired?: () => void;
}
type TaskDialog = {
    type: ItWorkTaskOperation;
    task: TicketWorkTask | null;
    version: number;
    intent?: 'cancel' | 'restore';
} | null;
const presentation = (
    status: TicketWorkTask['status'],
): { label: string; variant: StatusVariant; icon: typeof Clock3 } => {
    switch (status) {
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

/** Existing work register; mutations now use actor/version-bound canonical commands. */
export function TicketWorkTasks({
    actorId,
    ticketId,
    version,
    tasks,
    canViewWork,
    canManage,
    taskWork,
    assignees,
    teams,
    approvals = [],
    onCommitted,
    onAccessLost,
    onSessionExpired,
}: Props) {
    const [dialog, setDialog] = useState<TaskDialog>(null);
    const [accessLost, setAccessLost] = useState(false);
    const [revision, setRevision] = useState(0);
    const [recoveryMessage, setRecoveryMessage] = useState<string | null>(null);
    const previousScope = useRef({ actorId, ticketId });
    const notices = useItWorkTaskMemoryNotices(actorId, ticketId);
    let pending: ReturnType<typeof pendingItWorkTaskCommands> = [];
    let pendingUnavailable = false;
    if (canViewWork && !accessLost && Number.isSafeInteger(actorId)) {
        try {
            pending = pendingItWorkTaskCommands(actorId, ticketId);
        } catch {
            pendingUnavailable = true;
        }
    }
    useEffect(() => {
        if (!canViewWork || accessLost) {
            purgeItWorkTaskMemory(actorId, ticketId);
            setDialog(null);
        }
    }, [actorId, ticketId, canViewWork, accessLost]);
    useEffect(() => {
        const previous = previousScope.current;
        if (previous.actorId !== actorId || previous.ticketId !== ticketId) {
            purgeItWorkTaskMemory(previous.actorId, previous.ticketId);
            setDialog(null);
            setAccessLost(false);
            previousScope.current = { actorId, ticketId };
        }
    }, [actorId, ticketId]);
    useEffect(() => {
        if (!canViewWork || accessLost || (!notices.length && !pending.length))
            return;
        const warn = (event: BeforeUnloadEvent) => {
            event.preventDefault();
            event.returnValue = '';
        };
        window.addEventListener('beforeunload', warn);
        return () => window.removeEventListener('beforeunload', warn);
    }, [canViewWork, accessLost, notices.length, pending.length, revision]);
    if (!canViewWork) return null;
    const deny = () => {
        purgeItWorkTaskMemory(actorId, ticketId);
        setDialog(null);
        setAccessLost(true);
        onAccessLost?.();
    };
    if (accessLost)
        return (
            <div
                role="status"
                className="rounded-lg border border-border p-4 text-sm"
            >
                Current access to private task work could not be confirmed. Task
                details have been removed. Refresh the ticket before continuing.
            </div>
        );
    const {
        completed: complete,
        outstanding,
        needsReview,
        unverified,
    } = summarizeItWorkTasks(tasks);
    const commandsReady =
        canManage &&
        Number.isSafeInteger(actorId) &&
        actorId > 0 &&
        Number.isSafeInteger(version) &&
        version > 0;
    const open = (
        type: ItWorkTaskOperation,
        task: TicketWorkTask | null = null,
        intent?: 'cancel' | 'restore',
    ) => {
        if (
            commandsReady &&
            (type !== 'create' ||
                (taskWork?.storage_ready && taskWork.can_create)) &&
            (type !== 'reorder' ||
                (taskWork?.storage_ready && taskWork.can_reorder))
        )
            setDialog({ type, task, version, intent });
    };
    const recover = (operation: ItWorkTaskOperation, taskId: number | null) => {
        const task =
            taskId === null
                ? null
                : (tasks.find((item) => item.id === taskId) ?? null);
        if (taskId !== null && task === null) {
            setRecoveryMessage(
                'The task is not in the current authorized register. Refresh the ticket to check current access. The retained proposal and pending command reference have not been discarded.',
            );
            onAccessLost?.();
            return;
        }
        setRecoveryMessage(null);
        setDialog({ type: operation, task, version });
    };
    const handleCommitted = (result: ItWorkTaskCommitted) => {
        setRevision((value) => value + 1);
        onCommitted(result);
    };
    const close = () => {
        setDialog(null);
        setRevision((value) => value + 1);
    };
    return (
        <section aria-labelledby="ticket-work-tasks" className="space-y-4">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <div className="flex items-center gap-2">
                    <ListChecks
                        aria-hidden="true"
                        className="size-4 text-muted-foreground"
                    />
                    <h2 id="ticket-work-tasks" className="text-section-title">
                        Work tasks
                    </h2>
                </div>
                {canManage && (
                    <div className="flex gap-2">
                        {tasks.length > 1 && (
                            <Button
                                type="button"
                                size="sm"
                                variant="outline"
                                disabled={
                                    !commandsReady ||
                                    !taskWork?.storage_ready ||
                                    !taskWork.can_reorder
                                }
                                onClick={() => open('reorder')}
                            >
                                <ListOrdered className="size-4" />
                                Reorder tasks
                            </Button>
                        )}
                        <Button
                            type="button"
                            size="sm"
                            variant="outline"
                            disabled={
                                !commandsReady ||
                                !taskWork?.storage_ready ||
                                !taskWork.can_create
                            }
                            onClick={() => open('create')}
                        >
                            <Plus className="size-4" />
                            Add task
                        </Button>
                    </div>
                )}
            </div>
            {pendingUnavailable && (
                <p role="alert" className="text-sm text-status-warning">
                    Pending command references could not be read. Restore
                    browser session storage before changing tasks.
                </p>
            )}
            {canManage && !taskWork?.storage_ready && (
                <p role="status" className="text-sm text-muted-foreground">
                    Task creation and reordering are unavailable until current
                    work capabilities are ready.
                </p>
            )}
            {recoveryMessage && (
                <p role="status" className="text-sm text-status-warning">
                    {recoveryMessage}
                </p>
            )}
            {(notices.length > 0 || pending.length > 0) && (
                <div className="space-y-2 rounded-lg border border-border bg-muted/20 p-3">
                    <h3 className="text-sm font-semibold">
                        Task work to recover
                    </h3>
                    <p className="text-caption text-muted-foreground">
                        Entered details remain concealed until current access is
                        checked. A pending command must be checked or cancelled
                        before a new command can replace it.
                    </p>
                    {notices.map((notice, index) => (
                        <Button
                            key={notice.bufferId}
                            type="button"
                            variant="outline"
                            onClick={() =>
                                recover(
                                    notice.context.operation,
                                    notice.context.taskId,
                                )
                            }
                        >
                            Open retained {notice.context.operation} draft{' '}
                            {index + 1}
                        </Button>
                    ))}
                    {pending.map((entry, index) => (
                        <Button
                            key={`${entry.operation}:${entry.taskId}:${entry.requestUuid}`}
                            type="button"
                            variant="outline"
                            onClick={() =>
                                recover(entry.operation, entry.taskId)
                            }
                        >
                            Review pending task command {index + 1}
                        </Button>
                    ))}
                </div>
            )}
            {tasks.length > 0 && (
                <div className="flex flex-wrap gap-2">
                    <StatusBadge
                        variant={complete === tasks.length ? 'success' : 'info'}
                        size="sm"
                    >
                        <ClipboardCheck className="size-3" />
                        {complete} of {tasks.length} verified complete
                    </StatusBadge>
                    {needsReview > 0 && (
                        <StatusBadge variant="warning" size="sm">
                            {needsReview}{' '}
                            {needsReview === 1
                                ? 'completion needs'
                                : 'completions need'}{' '}
                            review
                        </StatusBadge>
                    )}
                    {unverified > 0 && (
                        <StatusBadge variant="warning" size="sm">
                            {unverified}{' '}
                            {unverified === 1 ? 'completion' : 'completions'}{' '}
                            unverified
                        </StatusBadge>
                    )}
                    {outstanding > 0 && (
                        <StatusBadge variant="warning" size="sm">
                            <CircleAlert className="size-3" />
                            {outstanding} required outstanding
                        </StatusBadge>
                    )}
                </div>
            )}
            {tasks.length === 0 ? (
                <EmptyState
                    icon={ListChecks}
                    title="No work tasks yet"
                    description="Add concrete work and evidence requirements when they are needed for this ticket."
                    variant="compact"
                />
            ) : (
                <ul className="space-y-3">
                    {tasks.map((task) => {
                        const state = presentation(task.status);
                        const Icon = state.icon;
                        const prerequisitesBlocked =
                            task.readiness?.prerequisites !== 'ready';
                        const overdue =
                            task.due_at &&
                            !['completed', 'cancelled'].includes(task.status) &&
                            new Date(task.due_at).getTime() < Date.now();
                        return (
                            <li key={task.id} id={`task-${task.id}`}>
                                <Card className="gap-3 p-4">
                                    <div className="flex flex-wrap items-start justify-between gap-2">
                                        <h3 className="min-w-0 flex-1 text-sm font-semibold break-words">
                                            {task.title}
                                        </h3>
                                        <div className="flex flex-wrap gap-1.5">
                                            <StatusBadge
                                                variant={state.variant}
                                                size="sm"
                                            >
                                                <Icon className="size-3" />
                                                {state.label}
                                            </StatusBadge>
                                            {task.is_required && (
                                                <StatusBadge
                                                    variant="info"
                                                    size="sm"
                                                >
                                                    Required
                                                </StatusBadge>
                                            )}
                                            {task.evidence_required && (
                                                <StatusBadge
                                                    variant="neutral"
                                                    size="sm"
                                                >
                                                    Evidence required
                                                </StatusBadge>
                                            )}
                                        </div>
                                    </div>
                                    {task.description && (
                                        <p className="text-sm break-words whitespace-pre-wrap">
                                            {task.description}
                                        </p>
                                    )}
                                    <div className="text-caption flex flex-wrap gap-x-5 gap-y-2 text-muted-foreground">
                                        {task.due_at && (
                                            <span
                                                className={`flex items-center gap-1.5 ${overdue ? 'text-status-critical' : ''}`}
                                            >
                                                <CalendarClock className="size-3.5" />
                                                {overdue ? 'Overdue' : 'Due'} ·{' '}
                                                {formatDateTime(task.due_at)}
                                            </span>
                                        )}
                                        {task.team && (
                                            <span className="flex items-center gap-1.5">
                                                <UsersRound className="size-3.5" />
                                                Team · {task.team.name}
                                            </span>
                                        )}
                                        {task.assignee && (
                                            <span className="flex items-center gap-1.5">
                                                <UserRound className="size-3.5" />
                                                Owner · {task.assignee.name}
                                            </span>
                                        )}
                                    </div>
                                    {task.dependencies.length > 0 && (
                                        <div className="space-y-1 rounded-lg border border-border bg-muted/20 p-3">
                                            <p className="text-caption font-semibold">
                                                Depends on
                                            </p>
                                            <ul className="space-y-1">
                                                {task.dependencies.map(
                                                    (dependency) => (
                                                        <li
                                                            key={dependency.id}
                                                            className="flex items-center gap-2 text-sm"
                                                        >
                                                            {dependency.status ===
                                                            'completed' ? (
                                                                <CheckCircle2 className="size-3.5 shrink-0 text-status-success" />
                                                            ) : (
                                                                <Clock3 className="size-3.5 shrink-0 text-status-warning" />
                                                            )}
                                                            <a
                                                                href={`#task-${dependency.id}`}
                                                                className="min-w-0 break-words text-primary underline-offset-4 hover:underline focus-visible:ring-2 focus-visible:ring-ring"
                                                            >
                                                                {
                                                                    dependency.title
                                                                }
                                                            </a>
                                                        </li>
                                                    ),
                                                )}
                                            </ul>
                                        </div>
                                    )}
                                    {task.status === 'completed' && (
                                        <div className="space-y-2 rounded-lg border border-status-success/25 bg-status-success-bg p-3 text-sm">
                                            <p className="font-semibold text-status-success">
                                                Completed
                                                {task.completed_by
                                                    ? ` by ${task.completed_by.name}`
                                                    : ''}
                                                {task.completed_at
                                                    ? ` · ${formatDateTime(task.completed_at)}`
                                                    : ''}
                                            </p>
                                            {task.completion_note && (
                                                <p className="break-words whitespace-pre-wrap">
                                                    {task.completion_note}
                                                </p>
                                            )}
                                            {task.evidence?.length ? (
                                                <ul className="list-disc space-y-1 pl-5">
                                                    {task.evidence.map(
                                                        (reference, index) => (
                                                            <li
                                                                key={index}
                                                                className="break-words"
                                                            >
                                                                {reference}
                                                            </li>
                                                        ),
                                                    )}
                                                </ul>
                                            ) : null}
                                        </div>
                                    )}
                                    <TicketWorkTaskReadiness
                                        readiness={task.readiness}
                                        ticketId={ticketId}
                                    />
                                    {task.approval && (
                                        <p className="text-sm">
                                            Approval request #{task.approval.id}{' '}
                                            ·{' '}
                                            {task.approval.status.replaceAll(
                                                '_',
                                                ' ',
                                            )}
                                        </p>
                                    )}
                                    <TicketWorkTaskHistory
                                        actorId={actorId}
                                        ticketId={ticketId}
                                        taskId={task.id}
                                        version={version}
                                        canView={canViewWork}
                                        onAccessLost={deny}
                                        onSessionExpired={onSessionExpired}
                                    />
                                    {canManage && (
                                        <div className="flex flex-wrap items-center gap-2 border-t border-border pt-3">
                                            {task.status === 'completed' ? (
                                                <Button
                                                    type="button"
                                                    size="sm"
                                                    variant="outline"
                                                    disabled={
                                                        !commandsReady ||
                                                        task.readiness
                                                            ?.can_reopen !==
                                                            true
                                                    }
                                                    onClick={() =>
                                                        open('reopen', task)
                                                    }
                                                >
                                                    <RotateCcw className="size-4" />
                                                    Reopen task
                                                </Button>
                                            ) : (
                                                <>
                                                    <Button
                                                        type="button"
                                                        size="sm"
                                                        variant="outline"
                                                        disabled={
                                                            !commandsReady ||
                                                            task.readiness
                                                                ?.can_edit !==
                                                                true
                                                        }
                                                        onClick={() =>
                                                            open('update', task)
                                                        }
                                                    >
                                                        <Pencil className="size-4" />
                                                        Edit task
                                                    </Button>
                                                    {task.status !==
                                                        'cancelled' && (
                                                        <Button
                                                            type="button"
                                                            size="sm"
                                                            disabled={
                                                                !commandsReady ||
                                                                task.readiness
                                                                    ?.can_complete !==
                                                                    true
                                                            }
                                                            onClick={() =>
                                                                open(
                                                                    'complete',
                                                                    task,
                                                                )
                                                            }
                                                        >
                                                            <CheckCircle2 className="size-4" />
                                                            Complete task
                                                        </Button>
                                                    )}
                                                    {task.status ===
                                                    'cancelled' ? (
                                                        <Button
                                                            type="button"
                                                            size="sm"
                                                            variant="outline"
                                                            disabled={
                                                                !commandsReady ||
                                                                task.readiness
                                                                    ?.can_restore !==
                                                                    true
                                                            }
                                                            onClick={() =>
                                                                open(
                                                                    'update',
                                                                    task,
                                                                    'restore',
                                                                )
                                                            }
                                                        >
                                                            <RotateCcw className="size-4" />
                                                            Restore task
                                                        </Button>
                                                    ) : (
                                                        <Button
                                                            type="button"
                                                            size="sm"
                                                            variant="outline"
                                                            disabled={
                                                                !commandsReady ||
                                                                task.readiness
                                                                    ?.can_cancel !==
                                                                    true
                                                            }
                                                            onClick={() =>
                                                                open(
                                                                    'update',
                                                                    task,
                                                                    'cancel',
                                                                )
                                                            }
                                                        >
                                                            <Ban className="size-4" />
                                                            Cancel task
                                                        </Button>
                                                    )}
                                                </>
                                            )}
                                            {prerequisitesBlocked &&
                                                task.status !== 'completed' && (
                                                    <p className="text-caption w-full text-status-warning">
                                                        Review the current
                                                        prerequisite blockers
                                                        before completing this
                                                        task.
                                                    </p>
                                                )}
                                            {task.is_required &&
                                                ![
                                                    'completed',
                                                    'cancelled',
                                                ].includes(task.status) && (
                                                    <p className="text-caption w-full text-muted-foreground">
                                                        Required work cannot be
                                                        cancelled. Edit the task
                                                        to review and record why
                                                        it is no longer
                                                        required.
                                                    </p>
                                                )}
                                        </div>
                                    )}
                                </Card>
                            </li>
                        );
                    })}
                </ul>
            )}
            {dialog &&
                (dialog.type === 'create' || dialog.type === 'update') && (
                    <TicketWorkTaskWizard
                        open
                        actorId={actorId}
                        ticketId={ticketId}
                        version={dialog.version}
                        task={dialog.task}
                        tasks={tasks}
                        assignees={assignees}
                        teams={teams}
                        approvals={approvals}
                        canCreate={
                            !!taskWork?.storage_ready && taskWork.can_create
                        }
                        intent={dialog.intent}
                        canManage={canManage}
                        onClose={close}
                        onCommitted={handleCommitted}
                        onAccessLost={deny}
                        onSessionExpired={onSessionExpired}
                    />
                )}
            {dialog &&
                (dialog.type === 'complete' ||
                    dialog.type === 'reopen' ||
                    dialog.type === 'reorder') && (
                    <TicketWorkTaskActionDialog
                        open
                        actorId={actorId}
                        ticketId={ticketId}
                        version={dialog.version}
                        operation={dialog.type}
                        canReorder={
                            !!taskWork?.storage_ready && taskWork.can_reorder
                        }
                        task={dialog.task}
                        tasks={tasks}
                        canManage={canManage}
                        onClose={close}
                        onCommitted={handleCommitted}
                        onAccessLost={deny}
                        onSessionExpired={onSessionExpired}
                    />
                )}
        </section>
    );
}
