import { Check, RotateCcw } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';

import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { StatusBadge } from '@/components/ui/status-badge';
import { formatDateTime } from '@/lib/datetime';

import { taskRequest } from '../lib/task-api';
import type { MyDayShiftTask } from '../lib/types';
import { TaskHelp } from './task-help';
import { TaskSteps } from './task-steps';

interface Props {
    task: MyDayShiftTask;
    personName: string;
    actorId?: number;
    requestedBy?: string;
    onClose: () => void;
    onSaved: (task: MyDayShiftTask) => void;
    onAddNote: () => void;
}

export function TaskDetailDialog(p: Props) {
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const stepsPending = (p.task.steps ?? []).some(
        (step) => !step.is_completed,
    );
    const change = async (task: MyDayShiftTask, completed: boolean) => {
        const result = await taskRequest<{ task: MyDayShiftTask }>(
            `/my-day/tasks/${task.id}/completion`,
            'PUT',
            { is_completed: completed, expected_version: task.version ?? 0 },
        );
        p.onSaved(result.task);
        return result.task;
    };
    const complete = async () => {
        if (busy) return;
        setBusy(true);
        setError('');
        const completed = !p.task.is_completed;
        try {
            const saved = await change(p.task, completed);
            // Toast actions sit outside the modal focus boundary. Close the
            // finished detail so Undo is reachable with one click or the keyboard.
            p.onClose();
            toast.success(completed ? 'Task marked done.' : 'Task reopened.', {
                action: {
                    label: 'Undo',
                    onClick: () => {
                        void change(saved, !completed)
                            .then(() => toast.success('Task restored.'))
                            .catch((cause) =>
                                toast.error(
                                    cause instanceof Error
                                        ? cause.message
                                        : 'Could not undo. Refresh your day.',
                                ),
                            );
                    },
                },
            });
        } catch (cause) {
            setError(
                cause instanceof Error
                    ? cause.message
                    : 'Could not save this task. Please retry.',
            );
        } finally {
            setBusy(false);
        }
    };
    return (
        <Dialog
            open
            onOpenChange={(open) => {
                if (!open && !busy) p.onClose();
            }}
        >
            <DialogContent
                className="max-h-[90vh] overflow-y-auto"
                style={{ width: 'min(92vw, 720px)', maxWidth: 'none' }}
            >
                <DialogHeader>
                    <DialogTitle className="pr-6 break-words">
                        {p.task.label}
                    </DialogTitle>
                    <DialogDescription>
                        {p.personName} ·{' '}
                        {p.requestedBy
                            ? `Help requested by ${p.requestedBy}`
                            : 'Assigned to you'}
                    </DialogDescription>
                </DialogHeader>
                <div className="flex flex-wrap items-center gap-3">
                    <StatusBadge
                        variant={p.task.is_completed ? 'success' : 'neutral'}
                    >
                        {p.task.is_completed ? 'Done' : 'To do'}
                    </StatusBadge>
                    <span className="text-sm text-muted-foreground">
                        {p.task.source_label ?? 'Shift task'}
                    </span>
                </div>
                <dl className="grid grid-cols-[120px_1fr] gap-3 text-sm">
                    <dt className="text-muted-foreground">When</dt>
                    <dd>
                        {p.task.scheduled_for
                            ? formatDateTime(p.task.scheduled_for)
                            : 'Any time today'}
                    </dd>
                    {p.task.completed_at && (
                        <>
                            <dt className="text-muted-foreground">Completed</dt>
                            <dd>{formatDateTime(p.task.completed_at)}</dd>
                        </>
                    )}
                </dl>
                <TaskSteps task={p.task} onSaved={p.onSaved} disabled={busy} />
                <TaskHelp
                    task={p.task}
                    actorId={p.actorId}
                    onSaved={p.onSaved}
                />
                <p className="rounded-lg bg-muted p-4 text-sm">
                    Mark this done once the task is finished. Record care
                    details in the person’s daily notes.
                </p>
                {error && (
                    <p
                        role="alert"
                        className="rounded-lg bg-status-critical-bg p-3 text-sm text-status-critical"
                    >
                        {error}
                    </p>
                )}
                {p.task.can_complete === false && (
                    <p className="text-sm text-muted-foreground">
                        {p.task.help?.recipient_id === p.actorId &&
                        p.task.help?.status === 'requested'
                            ? 'Accept responsibility above before completing steps or the task.'
                            : 'This task is read-only. Your shift or permissions no longer allow changes.'}
                    </p>
                )}
                <DialogFooter className="sticky bottom-0 bg-background py-2">
                    <Button
                        variant="outline"
                        onClick={p.onClose}
                        disabled={busy}
                    >
                        Close
                    </Button>
                    {p.task.client_id && (
                        <Button
                            variant="outline"
                            onClick={p.onAddNote}
                            disabled={busy}
                        >
                            Add daily note
                        </Button>
                    )}
                    {p.task.can_complete !== false && (
                        <Button
                            onClick={() => void complete()}
                            disabled={
                                busy || (!p.task.is_completed && stepsPending)
                            }
                        >
                            {p.task.is_completed ? (
                                <RotateCcw className="size-4" />
                            ) : (
                                <Check className="size-4" />
                            )}
                            {busy
                                ? 'Saving…'
                                : p.task.is_completed
                                  ? 'Reopen task'
                                  : 'Mark done'}
                        </Button>
                    )}
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
