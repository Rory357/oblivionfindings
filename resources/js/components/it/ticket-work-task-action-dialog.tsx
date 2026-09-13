import { ConfirmDialog } from '@/components/confirm-dialog';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { restoreOverlayFocus } from '@/components/ui/overlay-focus-return';
import { Textarea } from '@/components/ui/textarea';
import type {
    ItWorkTaskCommitted,
    ItWorkTaskRecord,
} from '@/hooks/it-work-task-command';
import {
    initialWorkTaskFields,
    useItWorkTaskEditor,
} from '@/hooks/use-it-work-task-editor';
import { useItWorkTaskHistory } from '@/hooks/use-it-work-task-history';
import {
    ArrowDown,
    ArrowUp,
    CheckCircle2,
    ListOrdered,
    RotateCcw,
} from 'lucide-react';
import {
    type FormEvent,
    useEffect,
    useId,
    useLayoutEffect,
    useRef,
    useState,
} from 'react';
import {
    TaskImpactReview,
    TicketWorkTaskReadiness,
} from './ticket-work-task-history';
import { TicketWorkTaskRecovery } from './ticket-work-task-recovery';

export interface WorkTaskActionProps {
    open: boolean;
    actorId: number;
    ticketId: number;
    version: number;
    operation: 'complete' | 'reopen' | 'reorder';
    task: ItWorkTaskRecord | null;
    tasks: ItWorkTaskRecord[];
    canManage: boolean;
    canReorder?: boolean;
    onClose: () => void;
    onCommitted: (result: ItWorkTaskCommitted) => void;
    onAccessLost?: () => void;
    onSessionExpired?: () => void;
}
export function TicketWorkTaskActionDialog(props: WorkTaskActionProps) {
    return props.open ? (
        <WorkTaskActionBody
            key={`${props.actorId}:${props.ticketId}:${props.operation}:${props.task?.id ?? 'order'}`}
            {...props}
        />
    ) : null;
}
function WorkTaskActionBody({
    actorId,
    ticketId,
    version,
    operation,
    task,
    tasks,
    canManage,
    canReorder = false,
    onClose,
    onCommitted,
    onAccessLost,
    onSessionExpired,
}: WorkTaskActionProps) {
    const [currentTasks, setCurrentTasks] = useState(tasks);
    const [currentTask, setCurrentTask] = useState(task);
    const [closeChoice, setCloseChoice] = useState<'keep' | 'discard' | null>(
        null,
    );
    const [orderMessage, setOrderMessage] = useState('');
    const [originalOrder] = useState(() => tasks.map(({ id }) => id));
    const allowedLifecycle =
        operation === 'reorder' ||
        (operation === 'reopen'
            ? currentTask?.readiness?.can_reopen === true
            : currentTask?.readiness?.can_complete === true);
    const editor = useItWorkTaskEditor({
        actorId,
        ticketId,
        taskId: task?.id ?? null,
        operation,
        version,
        initialFields:
            operation === 'reorder'
                ? { ordered_ids: originalOrder }
                : initialWorkTaskFields(operation, task),
        canManage: canManage && !!allowedLifecycle,
        evidenceRequired: currentTask?.evidence_required,
        onCommitted,
        onAccessLost,
        onSessionExpired,
    });
    const history = useItWorkTaskHistory({
        actorId,
        ticketId,
        taskId: task?.id ?? null,
        enabled: canManage && !editor.concealed,
        onAccessLost: editor.command.denyCurrentAccess,
        onSessionExpired,
    });
    const [impactAcknowledgement, setImpactAcknowledgement] = useState('');
    const impactKey = history.page
        ? `${history.page.review_nonce}:${editor.snapshotKey}`
        : '';
    const impactReviewed =
        !!impactKey &&
        impactAcknowledgement === impactKey &&
        history.page?.lock_version === editor.baseVersion &&
        history.page.readiness.can_reopen &&
        !history.busy &&
        !history.concealed;
    const form = useRef<HTMLFormElement>(null);
    const dialogContent = useRef<HTMLDivElement>(null);
    const confirmationTarget = useRef<{
        scope: string;
        target: HTMLElement | null;
    } | null>(null);
    const scope = `${actorId}:${ticketId}:${operation}:${task?.id ?? 'order'}`;
    const focusScope = useRef({ scope, authorized: false });
    useLayoutEffect(() => {
        focusScope.current = {
            scope,
            authorized: canManage && !editor.concealed,
        };
        return () => {
            focusScope.current.authorized = false;
        };
    }, [scope, canManage, editor.concealed]);
    const openConfirmation = (choice: 'keep' | 'discard') => {
        const active = document.activeElement;
        confirmationTarget.current = {
            scope,
            target:
                active instanceof HTMLElement &&
                dialogContent.current?.contains(active)
                    ? active
                    : null,
        };
        setCloseChoice(choice);
    };
    const restoreConfirmationFocus = (event: Event) => {
        const captured = confirmationTarget.current;
        confirmationTarget.current = null;
        const owner = dialogContent.current;
        if (
            !captured ||
            !owner?.isConnected ||
            !focusScope.current.authorized ||
            captured.scope !== focusScope.current.scope
        )
            return;
        // The original field may have disappeared while the confirmation was
        // open. Restore only inside the still-authorized task dialog; the
        // shared helper rejects hidden, disabled and disconnected targets.
        event.preventDefault();
        restoreOverlayFocus({
            current: {
                target:
                    captured.target &&
                    owner.contains(captured.target) &&
                    !captured.target.matches(':disabled')
                        ? captured.target
                        : null,
                owner,
            },
        });
    };
    const formId = useId();
    const fieldA11y = (field: string, id: string) => ({
        'aria-invalid': !!editor.errors[field],
        'aria-describedby': editor.errors[field] ? `${id}-error` : undefined,
    });
    const focusField = editor.errors.reason
        ? 'reason'
        : editor.errors.evidence
          ? 'evidence'
          : editor.errors.completion_note
            ? 'completion_note'
            : editor.errors.ordered_ids
              ? 'ordered_ids'
              : null;
    useEffect(() => {
        if (!focusField || editor.concealed) return;
        const timer = window.setTimeout(
            () =>
                form.current
                    ?.querySelector<HTMLElement>(
                        `[data-task-field="${focusField}"]`,
                    )
                    ?.focus(),
            0,
        );
        return () => window.clearTimeout(timer);
    }, [focusField, editor.concealed]);
    const label =
        operation === 'complete'
            ? 'Complete work task'
            : operation === 'reopen'
              ? 'Reopen work task'
              : 'Reorder work tasks';
    const action =
        operation === 'complete'
            ? 'Complete task'
            : operation === 'reopen'
              ? 'Reopen task'
              : 'Save task order';
    const Icon =
        operation === 'complete'
            ? CheckCircle2
            : operation === 'reopen'
              ? RotateCcw
              : ListOrdered;
    const requestClose = () => {
        if (editor.command.stage === 'access') {
            onClose();
            return;
        }
        if (
            editor.command.result ||
            (!editor.dirty &&
                !editor.command.outcomeUnknown &&
                !editor.command.references.length)
        ) {
            if (editor.keepForClose()) onClose();
        } else openConfirmation('keep');
    };
    const submit = (event: FormEvent) => {
        event.preventDefault();
        if (operation === 'reorder' && (!sequenceCurrent || !canReorder))
            return;
        if (operation === 'reopen' && !impactReviewed) return;
        editor.submit();
    };
    const ordered = editor.fields.ordered_ids ?? originalOrder;
    const sequenceCurrent =
        ordered.length === currentTasks.length &&
        new Set(ordered).size === ordered.length &&
        currentTasks.every(({ id }) => ordered.includes(id));
    const move = (id: number, direction: -1 | 1) => {
        const index = ordered.indexOf(id);
        if (
            index < 0 ||
            index + direction < 0 ||
            index + direction >= ordered.length
        )
            return;
        const next = [...ordered];
        [next[index], next[index + direction]] = [
            next[index + direction],
            next[index],
        ];
        editor.setField('ordered_ids', next);
        setOrderMessage(
            `Task moved to position ${index + direction + 1} of ${ordered.length}.`,
        );
    };
    return (
        <>
            <Dialog
                open
                onOpenChange={(open) => {
                    if (!open) requestClose();
                }}
            >
                <DialogContent
                    ref={dialogContent}
                    className="max-h-[88vh] overflow-y-auto"
                    style={{
                        maxWidth: 'min(92vw, 720px)',
                        width: 'min(92vw, 720px)',
                    }}
                >
                    <DialogHeader>
                        <DialogTitle className="flex items-center gap-2">
                            <Icon className="size-5" />
                            {label}
                        </DialogTitle>
                        <DialogDescription>
                            {operation === 'complete'
                                ? 'Record what was completed and the supporting evidence.'
                                : operation === 'reopen'
                                  ? 'Explain why this task needs more work before reopening it.'
                                  : 'Move tasks with the order controls, review the sequence, then save the complete register order.'}
                        </DialogDescription>
                    </DialogHeader>
                    {editor.command.result ? (
                        <div role="status" className="space-y-4 py-4">
                            <h3 className="text-section-title">
                                {editor.command.result.changed
                                    ? operation === 'complete'
                                        ? 'Task completed'
                                        : operation === 'reopen'
                                          ? 'Task reopened'
                                          : 'Task order saved'
                                    : 'No task changes needed'}
                            </h3>
                            <p className="text-sm">
                                {editor.retainedAfterCommit
                                    ? 'The earlier save is confirmed. Your newer unsaved work is available in Task work to recover.'
                                    : editor.command.result.changed
                                      ? 'The task change is saved.'
                                      : 'The task already matched this request. No additional change was recorded.'}
                            </p>
                            <Button type="button" onClick={requestClose}>
                                Done
                            </Button>
                        </div>
                    ) : (
                        <>
                            <TicketWorkTaskRecovery
                                editor={editor}
                                taskId={task?.id ?? null}
                                onAdopt={(review) => {
                                    setCurrentTasks(review.tasks);
                                    setCurrentTask(
                                        review.tasks.find(
                                            ({ id }) => id === task?.id,
                                        ) ?? null,
                                    );
                                }}
                            />
                            {!editor.concealed && (
                                <>
                                    {currentTask && (
                                        <div className="space-y-1 rounded-lg border border-border bg-muted/20 p-3">
                                            <p className="font-medium break-words">
                                                {currentTask.title}
                                            </p>
                                            <p className="text-sm text-muted-foreground">
                                                Current status:{' '}
                                                {currentTask.status.replaceAll(
                                                    '_',
                                                    ' ',
                                                )}
                                            </p>
                                        </div>
                                    )}
                                    {!allowedLifecycle && (
                                        <p
                                            role="status"
                                            className="text-sm text-status-warning"
                                        >
                                            The current task status does not
                                            allow this action. Your proposal is
                                            retained; close and review the
                                            task's current work.
                                        </p>
                                    )}
                                    {currentTask && (
                                        <TicketWorkTaskReadiness
                                            readiness={currentTask.readiness}
                                            ticketId={ticketId}
                                        />
                                    )}
                                    <form
                                        id={formId}
                                        ref={form}
                                        onSubmit={submit}
                                    >
                                        <fieldset
                                            disabled={!editor.canEdit}
                                            className="space-y-4"
                                        >
                                            {operation === 'complete' && (
                                                <>
                                                    <div className="space-y-2">
                                                        <label
                                                            htmlFor={`${formId}-note`}
                                                            className="text-sm font-medium"
                                                        >
                                                            Completion note
                                                        </label>
                                                        <Textarea
                                                            id={`${formId}-note`}
                                                            data-task-field="completion_note"
                                                            {...fieldA11y(
                                                                'completion_note',
                                                                `${formId}-note`,
                                                            )}
                                                            maxLength={5000}
                                                            rows={4}
                                                            value={
                                                                editor.fields
                                                                    .completion_note ??
                                                                ''
                                                            }
                                                            onChange={(event) =>
                                                                editor.setField(
                                                                    'completion_note',
                                                                    event.target
                                                                        .value ||
                                                                        null,
                                                                )
                                                            }
                                                        />
                                                        <InputError
                                                            id={`${formId}-note-error`}
                                                            message={
                                                                editor.errors
                                                                    .completion_note
                                                            }
                                                        />
                                                    </div>
                                                    <div className="space-y-2">
                                                        <label
                                                            htmlFor={`${formId}-evidence`}
                                                            className="text-sm font-medium"
                                                        >
                                                            Evidence references
                                                            {currentTask?.evidence_required && (
                                                                <span aria-hidden="true">
                                                                    {' '}
                                                                    *
                                                                </span>
                                                            )}
                                                        </label>
                                                        <Textarea
                                                            id={`${formId}-evidence`}
                                                            data-task-field="evidence"
                                                            {...fieldA11y(
                                                                'evidence',
                                                                `${formId}-evidence`,
                                                            )}
                                                            maxLength={40020}
                                                            rows={5}
                                                            value={(
                                                                editor.fields
                                                                    .evidence ??
                                                                []
                                                            ).join('\n')}
                                                            onChange={(event) =>
                                                                editor.setField(
                                                                    'evidence',
                                                                    event.target.value.split(
                                                                        '\n',
                                                                    ),
                                                                )
                                                            }
                                                        />
                                                        <p className="text-caption text-muted-foreground">
                                                            One reference per
                                                            line, up to 20
                                                            references. Use
                                                            existing records or
                                                            governed evidence
                                                            links.
                                                        </p>
                                                        <InputError
                                                            id={`${formId}-evidence-error`}
                                                            message={
                                                                editor.errors
                                                                    .evidence
                                                            }
                                                        />
                                                    </div>
                                                </>
                                            )}
                                            {operation === 'reopen' && (
                                                <div className="space-y-2">
                                                    <label
                                                        htmlFor={`${formId}-reason`}
                                                        className="text-sm font-medium"
                                                    >
                                                        Reason for reopening{' '}
                                                        <span aria-hidden="true">
                                                            *
                                                        </span>
                                                    </label>
                                                    <Textarea
                                                        id={`${formId}-reason`}
                                                        data-task-field="reason"
                                                        {...fieldA11y(
                                                            'reason',
                                                            `${formId}-reason`,
                                                        )}
                                                        maxLength={2000}
                                                        rows={5}
                                                        value={
                                                            editor.fields
                                                                .reason ?? ''
                                                        }
                                                        onChange={(event) =>
                                                            editor.setField(
                                                                'reason',
                                                                event.target
                                                                    .value,
                                                            )
                                                        }
                                                    />
                                                    <InputError
                                                        id={`${formId}-reason-error`}
                                                        message={
                                                            editor.errors.reason
                                                        }
                                                    />
                                                </div>
                                            )}
                                            {operation === 'reorder' && (
                                                <div className="space-y-3">
                                                    {!sequenceCurrent && (
                                                        <div
                                                            role="status"
                                                            className="space-y-2 rounded-lg border border-status-warning/30 bg-status-warning-bg p-3 text-sm"
                                                        >
                                                            <p>
                                                                The current
                                                                register has
                                                                different tasks.
                                                                Reconcile the
                                                                list before
                                                                saving. Existing
                                                                proposed
                                                                relative order
                                                                is preserved;
                                                                new tasks are
                                                                added at the end
                                                                for your review.
                                                            </p>
                                                            <Button
                                                                type="button"
                                                                variant="outline"
                                                                onClick={() => {
                                                                    const present =
                                                                        ordered.filter(
                                                                            (
                                                                                id,
                                                                            ) =>
                                                                                currentTasks.some(
                                                                                    (
                                                                                        task,
                                                                                    ) =>
                                                                                        task.id ===
                                                                                        id,
                                                                                ),
                                                                        );
                                                                    editor.setField(
                                                                        'ordered_ids',
                                                                        [
                                                                            ...present,
                                                                            ...currentTasks
                                                                                .filter(
                                                                                    ({
                                                                                        id,
                                                                                    }) =>
                                                                                        !present.includes(
                                                                                            id,
                                                                                        ),
                                                                                )
                                                                                .map(
                                                                                    ({
                                                                                        id,
                                                                                    }) =>
                                                                                        id,
                                                                                ),
                                                                        ],
                                                                    );
                                                                    setOrderMessage(
                                                                        'The current task list is included. Review the proposed order before saving.',
                                                                    );
                                                                }}
                                                            >
                                                                Reconcile task
                                                                list
                                                            </Button>
                                                        </div>
                                                    )}
                                                    <ol
                                                        className="space-y-2"
                                                        aria-label="Proposed task order"
                                                        tabIndex={-1}
                                                        data-task-field="ordered_ids"
                                                        {...fieldA11y(
                                                            'ordered_ids',
                                                            `${formId}-order`,
                                                        )}
                                                    >
                                                        {ordered.map(
                                                            (id, index) => (
                                                                <li
                                                                    key={id}
                                                                    className="flex items-center gap-3 rounded-lg border border-border p-3"
                                                                >
                                                                    <span className="text-caption text-muted-foreground">
                                                                        {index +
                                                                            1}
                                                                    </span>
                                                                    <span className="min-w-0 flex-1 text-sm font-medium break-words">
                                                                        {currentTasks.find(
                                                                            (
                                                                                item,
                                                                            ) =>
                                                                                item.id ===
                                                                                id,
                                                                        )
                                                                            ?.title ??
                                                                            `Unavailable task #${id}`}
                                                                    </span>
                                                                    <Button
                                                                        type="button"
                                                                        variant="outline"
                                                                        size="icon"
                                                                        aria-label={`Move task ${id} up`}
                                                                        disabled={
                                                                            index ===
                                                                            0
                                                                        }
                                                                        onClick={() =>
                                                                            move(
                                                                                id,
                                                                                -1,
                                                                            )
                                                                        }
                                                                    >
                                                                        <ArrowUp className="size-4" />
                                                                    </Button>
                                                                    <Button
                                                                        type="button"
                                                                        variant="outline"
                                                                        size="icon"
                                                                        aria-label={`Move task ${id} down`}
                                                                        disabled={
                                                                            index ===
                                                                            ordered.length -
                                                                                1
                                                                        }
                                                                        onClick={() =>
                                                                            move(
                                                                                id,
                                                                                1,
                                                                            )
                                                                        }
                                                                    >
                                                                        <ArrowDown className="size-4" />
                                                                    </Button>
                                                                </li>
                                                            ),
                                                        )}
                                                    </ol>
                                                    <p
                                                        role="status"
                                                        aria-live="polite"
                                                        className="text-caption"
                                                    >
                                                        {orderMessage}
                                                    </p>
                                                    <InputError
                                                        id={`${formId}-order-error`}
                                                        message={
                                                            editor.errors
                                                                .ordered_ids
                                                        }
                                                    />
                                                    {editor.command.review && (
                                                        <p className="text-sm text-muted-foreground">
                                                            Review any newly
                                                            added or removed
                                                            tasks before
                                                            applying this
                                                            sequence.
                                                        </p>
                                                    )}
                                                </div>
                                            )}
                                        </fieldset>
                                    </form>
                                    {operation === 'reopen' && (
                                        <TaskImpactReview
                                            history={history}
                                            version={editor.baseVersion}
                                            acknowledged={impactReviewed}
                                            onAcknowledge={() =>
                                                setImpactAcknowledgement(
                                                    impactKey,
                                                )
                                            }
                                            onReviewCurrent={() =>
                                                void editor.command.reviewCurrent()
                                            }
                                            ticketId={ticketId}
                                        />
                                    )}
                                </>
                            )}
                            <DialogFooter className="gap-2">
                                <Button
                                    type="button"
                                    variant="ghost"
                                    onClick={requestClose}
                                >
                                    Close
                                </Button>
                                {editor.dirty && (
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        disabled={
                                            editor.command.outcomeUnknown ||
                                            editor.command.references.length >
                                                0 ||
                                            editor.command.busy
                                        }
                                        onClick={() =>
                                            openConfirmation('discard')
                                        }
                                    >
                                        Discard changes
                                    </Button>
                                )}
                                <Button
                                    type="submit"
                                    form={formId}
                                    disabled={
                                        !editor.canEdit ||
                                        (operation === 'reopen' &&
                                            !impactReviewed) ||
                                        (operation === 'reorder' &&
                                            (!canReorder || !sequenceCurrent))
                                    }
                                >
                                    {action}
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </DialogContent>
            </Dialog>
            <ConfirmDialog
                open={closeChoice !== null}
                onClose={() => setCloseChoice(null)}
                onCloseAutoFocus={restoreConfirmationFocus}
                title={
                    closeChoice === 'discard'
                        ? 'Discard these task changes?'
                        : 'Keep this task work for later?'
                }
                description={
                    closeChoice === 'discard'
                        ? 'Only this unsent task proposal will be removed. Existing task records and other drafts are unchanged.'
                        : 'This proposal stays in this browser session for explicit recovery. Closing does not cancel a submitted command. A full browser reload can lose unsent content.'
                }
                confirmText={
                    closeChoice === 'discard'
                        ? 'Discard changes'
                        : 'Keep draft and close'
                }
                variant={closeChoice === 'discard' ? 'destructive' : 'default'}
                onConfirm={() => {
                    if (closeChoice === 'discard') {
                        if (editor.discardOwned()) onClose();
                    } else {
                        if (editor.keepForClose()) onClose();
                    }
                }}
            />
        </>
    );
}
