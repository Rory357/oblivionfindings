import { ConfirmDialog } from '@/components/confirm-dialog';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { restoreOverlayFocus } from '@/components/ui/overlay-focus-return';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import {
    ReviewCard,
    ReviewRow,
    WizardShell,
    WizardStepPane,
    WizardSuccessPane,
} from '@/components/wizard/shell';
import type {
    ItWorkTaskCommitted,
    ItWorkTaskRecord,
} from '@/hooks/it-work-task-command';
import {
    initialWorkTaskFields,
    useItWorkTaskEditor,
} from '@/hooks/use-it-work-task-editor';
import { useItWorkTaskHistory } from '@/hooks/use-it-work-task-history';
import { formatDateTime } from '@/lib/datetime';
import { ClipboardCheck, FileText, GitBranch, UsersRound } from 'lucide-react';
import {
    type FormEvent,
    type ReactNode,
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

type Option = { id: number; name: string };
export interface WorkTaskWizardProps {
    open: boolean;
    actorId: number;
    ticketId: number;
    version: number;
    task: ItWorkTaskRecord | null;
    tasks: ItWorkTaskRecord[];
    assignees: Option[];
    teams: Option[];
    approvals?: { id: number; status: string }[];
    intent?: 'cancel' | 'restore';
    canManage: boolean;
    canCreate?: boolean;
    onClose: () => void;
    onCommitted: (result: ItWorkTaskCommitted) => void;
    onAccessLost?: () => void;
    onSessionExpired?: () => void;
}
const steps = [
    {
        key: 'details',
        label: 'Details',
        blurb: 'Describe the task and its status',
        icon: FileText,
    },
    {
        key: 'ownership',
        label: 'Ownership',
        blurb: 'Assign responsibility and a due date',
        icon: UsersRound,
    },
    {
        key: 'dependencies',
        label: 'Prerequisites',
        blurb: 'Choose required work and evidence',
        icon: GitBranch,
    },
    {
        key: 'review',
        label: 'Review',
        blurb: 'Check the proposal before saving',
        icon: ClipboardCheck,
    },
] as const;
const localDateTime = (value: string | null | undefined) => {
    if (!value) return '';
    const date = new Date(value);
    if (!Number.isFinite(date.getTime())) return '';
    return new Date(date.getTime() - date.getTimezoneOffset() * 60000)
        .toISOString()
        .slice(0, 16);
};

export function TicketWorkTaskWizard(props: WorkTaskWizardProps) {
    return props.open ? (
        <WorkTaskWizardBody
            key={`${props.actorId}:${props.ticketId}:${props.task?.id ?? 'new'}`}
            {...props}
        />
    ) : null;
}
function WorkTaskWizardBody({
    actorId,
    ticketId,
    version,
    task,
    tasks,
    assignees,
    teams,
    approvals = [],
    intent,
    canManage,
    canCreate = false,
    onClose,
    onCommitted,
    onAccessLost,
    onSessionExpired,
}: WorkTaskWizardProps) {
    const editor = useItWorkTaskEditor({
        actorId,
        ticketId,
        taskId: task?.id ?? null,
        operation: task ? 'update' : 'create',
        version,
        initialFields: initialWorkTaskFields(task ? 'update' : 'create', task),
        initialProposal:
            intent === 'cancel'
                ? { status: 'cancelled' }
                : intent === 'restore'
                  ? { status: 'pending' }
                  : undefined,
        canManage,
        onCommitted,
        onAccessLost,
        onSessionExpired,
    });
    const [currentOptions, setCurrentOptions] = useState({
        assignees,
        teams,
        tasks,
        approvals,
    });
    const currentTask =
        currentOptions.tasks.find(({ id }) => id === task?.id) ?? task;
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
        history.page.readiness.storage_ready &&
        !history.busy &&
        !history.concealed;
    const needsImpactReview = !!task && editor.reasonRequired;
    const lifecycleAllowed =
        (!task && canCreate) ||
        (!!currentTask?.readiness?.storage_ready &&
            (editor.fields.status === 'pending' &&
            currentTask.status === 'cancelled'
                ? currentTask.readiness.can_restore
                : currentTask.readiness.can_edit) &&
            (editor.fields.status !== 'in_progress' ||
                currentTask.status === 'in_progress' ||
                currentTask.readiness.can_start));
    const canSubmitProposal =
        editor.canEdit &&
        lifecycleAllowed &&
        (!needsImpactReview || impactReviewed);
    const [closeChoice, setCloseChoice] = useState<'keep' | 'discard' | null>(
        null,
    );
    const formId = useId();
    const form = useRef<HTMLFormElement>(null);
    const body = useRef<HTMLDivElement>(null);
    const confirmationTarget = useRef<{
        scope: string;
        target: HTMLElement | null;
    } | null>(null);
    const scope = `${actorId}:${ticketId}:${task?.id ?? 'new'}`;
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
        const owner = body.current?.closest<HTMLElement>('[role="dialog"]');
        confirmationTarget.current = {
            scope,
            target:
                active instanceof HTMLElement && owner?.contains(active)
                    ? active
                    : null,
        };
        setCloseChoice(choice);
    };
    const restoreConfirmationFocus = (event: Event) => {
        const captured = confirmationTarget.current;
        confirmationTarget.current = null;
        const owner = body.current?.closest<HTMLElement>('[role="dialog"]');
        if (
            !captured ||
            !owner?.isConnected ||
            !focusScope.current.authorized ||
            captured.scope !== focusScope.current.scope
        )
            return;
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
    const focusInitialControl = (event: Event) => {
        // Dialog has captured the external opener before invoking this hook.
        // Prefer any current recovery action over the ordinary first field.
        const target = body.current?.querySelector<HTMLElement>(
            'button:not(:disabled), a[href], input:not(:disabled), textarea:not(:disabled), select:not(:disabled)',
        );
        if (!target) return;
        event.preventDefault();
        target.focus({ preventScroll: true });
    };
    const fields = editor.fields;
    const fieldA11y = (field: string, id: string) => ({
        'aria-invalid': !!editor.errors[field],
        'aria-describedby': editor.errors[field] ? `${id}-error` : undefined,
    });
    const { concealed, setStepIndex } = editor;
    const errorKey =
        Object.keys(editor.errors).find(
            (key) => !['form', 'expected_version'].includes(key),
        ) ?? '';
    useEffect(() => {
        if (!errorKey || concealed) return;
        const field = errorKey.split('.')[0];
        setStepIndex(
            ['team_id', 'assigned_to_user_id', 'due_at'].includes(field)
                ? 1
                : [
                        'dependency_ids',
                        'approval_id',
                        'is_required',
                        'evidence_required',
                    ].includes(field)
                  ? 2
                  : 0,
        );
        const timer = window.setTimeout(
            () =>
                form.current
                    ?.querySelector<HTMLElement>(`[data-task-field="${field}"]`)
                    ?.focus(),
            0,
        );
        return () => window.clearTimeout(timer);
    }, [errorKey, concealed, setStepIndex]);
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
            return;
        }
        openConfirmation('keep');
    };
    const continueStep = () => {
        if (!editor.canEdit) return;
        const errors = editor.validate();
        if (Object.keys(errors).length) return;
        editor.setStepIndex(Math.min(3, editor.stepIndex + 1));
    };
    const submit = (event: FormEvent) => {
        event.preventDefault();
        if (editor.stepIndex < 3) continueStep();
        else if (canSubmitProposal) editor.submit();
    };
    const selectedDependencies = fields.dependency_ids ?? [];
    const dependencyRows = [
        ...currentOptions.tasks.filter((item) => item.id !== task?.id),
        ...selectedDependencies
            .filter(
                (id) => !currentOptions.tasks.some((item) => item.id === id),
            )
            .map((id) => ({
                id,
                title: `Unavailable prerequisite #${id}`,
                status: 'unavailable',
            })),
    ];
    const pct = Math.round(
        ([
            !!fields.title?.trim(),
            !!fields.description?.trim(),
            !!fields.assigned_to_user_id,
            !!fields.team_id,
            !!fields.due_at,
            selectedDependencies.length > 0,
        ].filter(Boolean).length /
            6) *
            100,
    );
    const optionName = (
        kind: 'assignees' | 'teams',
        id: number | null | undefined,
    ) =>
        id
            ? (currentOptions[kind].find((item) => item.id === id)?.name ??
              (kind === 'assignees'
                  ? task?.assignee?.id === id
                      ? task.assignee.name
                      : `Previously selected person #${id}`
                  : task?.team?.id === id
                    ? task.team.name
                    : `Previously selected team #${id}`))
            : 'Unassigned';
    const currentAssigneeUnavailable =
        fields.assigned_to_user_id &&
        !currentOptions.assignees.some(
            ({ id }) => id === fields.assigned_to_user_id,
        );
    const currentTeamUnavailable =
        fields.team_id &&
        !currentOptions.teams.some(({ id }) => id === fields.team_id);
    return (
        <>
            <WizardShell
                open
                onClose={requestClose}
                onOpenAutoFocus={focusInitialControl}
                title={task ? 'Edit work task' : 'Add work task'}
                description="Define the task, responsibility and prerequisites, then review the changes before saving."
                railIcon={ClipboardCheck}
                railTitle={task ? 'Edit work task' : 'Add work task'}
                railSub="Ticket work and evidence"
                steps={steps}
                stepIndex={editor.stepIndex}
                onStepClick={(index) => {
                    if (editor.canEdit) editor.setStepIndex(index);
                }}
                pct={pct}
                footerStart={
                    <>
                        <Button
                            type="button"
                            variant="ghost"
                            onClick={requestClose}
                        >
                            Close
                        </Button>
                        {editor.stepIndex > 0 && (
                            <Button
                                type="button"
                                variant="outline"
                                disabled={!editor.canEdit}
                                onClick={() =>
                                    editor.setStepIndex(editor.stepIndex - 1)
                                }
                            >
                                Back
                            </Button>
                        )}
                        {editor.dirty && (
                            <Button
                                type="button"
                                variant="ghost"
                                disabled={
                                    editor.command.outcomeUnknown ||
                                    editor.command.busy ||
                                    editor.command.references.length > 0
                                }
                                onClick={() => openConfirmation('discard')}
                            >
                                Discard changes
                            </Button>
                        )}
                    </>
                }
                footerEnd={
                    editor.stepIndex < 3 ? (
                        <Button
                            key="continue"
                            type="button"
                            disabled={!editor.canEdit}
                            onClick={(event) => {
                                event.preventDefault();
                                continueStep();
                            }}
                        >
                            Continue
                        </Button>
                    ) : (
                        <Button
                            key="submit"
                            type="submit"
                            form={formId}
                            disabled={!canSubmitProposal}
                        >
                            {intent === 'cancel'
                                ? 'Cancel task'
                                : intent === 'restore'
                                  ? 'Restore task'
                                  : task
                                    ? 'Save task'
                                    : 'Add task'}
                        </Button>
                    )
                }
                success={
                    editor.command.result ? (
                        <WizardSuccessPane
                            title={
                                editor.command.result.changed
                                    ? task
                                        ? 'Task updated'
                                        : 'Task added'
                                    : 'No task changes needed'
                            }
                            blurb={
                                editor.retainedAfterCommit
                                    ? 'The earlier save is confirmed. Your newer unsaved work is available in Task work to recover.'
                                    : editor.command.result.changed
                                      ? 'Your task has been saved.'
                                      : 'The current task already matches this proposal. No extra change was recorded.'
                            }
                            actions={
                                <Button type="button" onClick={requestClose}>
                                    Done
                                </Button>
                            }
                        />
                    ) : undefined
                }
            >
                <div ref={body} className="space-y-5">
                    <TicketWorkTaskRecovery
                        editor={editor}
                        taskId={task?.id ?? null}
                        onAdopt={(review) =>
                            setCurrentOptions({
                                tasks: review.tasks,
                                assignees: review.assignees,
                                teams: review.teams,
                                approvals: review.approvals,
                            })
                        }
                    />
                    {!editor.concealed && (
                        <>
                            {currentTask && (
                                <TicketWorkTaskReadiness
                                    readiness={currentTask.readiness}
                                    ticketId={ticketId}
                                />
                            )}
                            {!lifecycleAllowed && (
                                <p
                                    role="status"
                                    className="text-sm text-status-warning"
                                >
                                    The current task does not permit this
                                    transition. Save a permitted prerequisite
                                    repair before starting work, or review the
                                    current task.
                                </p>
                            )}
                            <form id={formId} ref={form} onSubmit={submit}>
                                <fieldset
                                    disabled={!editor.canEdit}
                                    className="space-y-4"
                                >
                                    <WizardStepPane
                                        key={steps[editor.stepIndex].key}
                                    >
                                        {editor.stepIndex === 0 && (
                                            <div className="space-y-4">
                                                <TaskField
                                                    id={`${formId}-title`}
                                                    label="Task title"
                                                    required
                                                    error={editor.errors.title}
                                                >
                                                    <Input
                                                        id={`${formId}-title`}
                                                        data-task-field="title"
                                                        aria-describedby={
                                                            editor.errors.title
                                                                ? `${formId}-title-error`
                                                                : undefined
                                                        }
                                                        maxLength={255}
                                                        value={
                                                            fields.title ?? ''
                                                        }
                                                        aria-invalid={
                                                            !!editor.errors
                                                                .title
                                                        }
                                                        onChange={(event) =>
                                                            editor.setField(
                                                                'title',
                                                                event.target
                                                                    .value,
                                                            )
                                                        }
                                                    />
                                                </TaskField>
                                                <TaskField
                                                    id={`${formId}-description`}
                                                    label="Description"
                                                    error={
                                                        editor.errors
                                                            .description
                                                    }
                                                >
                                                    <Textarea
                                                        id={`${formId}-description`}
                                                        data-task-field="description"
                                                        {...fieldA11y(
                                                            'description',
                                                            `${formId}-description`,
                                                        )}
                                                        rows={5}
                                                        maxLength={5000}
                                                        value={
                                                            fields.description ??
                                                            ''
                                                        }
                                                        onChange={(event) =>
                                                            editor.setField(
                                                                'description',
                                                                event.target
                                                                    .value ||
                                                                    null,
                                                            )
                                                        }
                                                    />
                                                </TaskField>
                                                {task && (
                                                    <div
                                                        className="space-y-2"
                                                        role="group"
                                                        tabIndex={-1}
                                                        data-task-field="status"
                                                        aria-labelledby={`${formId}-status-label`}
                                                        {...fieldA11y(
                                                            'status',
                                                            `${formId}-status`,
                                                        )}
                                                    >
                                                        <p
                                                            id={`${formId}-status-label`}
                                                            className="text-sm font-medium"
                                                        >
                                                            Task status
                                                        </p>
                                                        <div className="grid grid-cols-2 gap-2">
                                                            {(
                                                                [
                                                                    'pending',
                                                                    'in_progress',
                                                                    'blocked',
                                                                    'cancelled',
                                                                ] as const
                                                            ).map((status) => (
                                                                <Button
                                                                    key={status}
                                                                    type="button"
                                                                    variant={
                                                                        fields.status ===
                                                                        status
                                                                            ? 'default'
                                                                            : 'outline'
                                                                    }
                                                                    aria-pressed={
                                                                        fields.status ===
                                                                        status
                                                                    }
                                                                    onClick={() =>
                                                                        editor.setField(
                                                                            'status',
                                                                            status,
                                                                        )
                                                                    }
                                                                >
                                                                    {status ===
                                                                    'in_progress'
                                                                        ? 'In progress'
                                                                        : status[0].toUpperCase() +
                                                                          status.slice(
                                                                              1,
                                                                          )}
                                                                </Button>
                                                            ))}
                                                        </div>
                                                        <InputError
                                                            id={`${formId}-status-error`}
                                                            message={
                                                                editor.errors
                                                                    .status
                                                            }
                                                        />
                                                    </div>
                                                )}
                                                {editor.reasonRequired && (
                                                    <TaskField
                                                        id={`${formId}-reason`}
                                                        label="Reason for this change"
                                                        required
                                                        error={
                                                            editor.errors.reason
                                                        }
                                                    >
                                                        <Textarea
                                                            id={`${formId}-reason`}
                                                            data-task-field="reason"
                                                            {...fieldA11y(
                                                                'reason',
                                                                `${formId}-reason`,
                                                            )}
                                                            maxLength={2000}
                                                            value={
                                                                fields.reason ??
                                                                ''
                                                            }
                                                            onChange={(event) =>
                                                                editor.setField(
                                                                    'reason',
                                                                    event.target
                                                                        .value,
                                                                )
                                                            }
                                                        />
                                                    </TaskField>
                                                )}
                                            </div>
                                        )}
                                        {editor.stepIndex === 1 && (
                                            <div className="space-y-4">
                                                <TaskField
                                                    id={`${formId}-assignee`}
                                                    label="Assigned technician"
                                                    error={
                                                        editor.errors
                                                            .assigned_to_user_id
                                                    }
                                                >
                                                    <Select
                                                        value={
                                                            fields.assigned_to_user_id
                                                                ? String(
                                                                      fields.assigned_to_user_id,
                                                                  )
                                                                : 'none'
                                                        }
                                                        onValueChange={(
                                                            value,
                                                        ) =>
                                                            editor.setField(
                                                                'assigned_to_user_id',
                                                                value === 'none'
                                                                    ? null
                                                                    : Number(
                                                                          value,
                                                                      ),
                                                            )
                                                        }
                                                    >
                                                        <SelectTrigger
                                                            id={`${formId}-assignee`}
                                                            data-task-field="assigned_to_user_id"
                                                            {...fieldA11y(
                                                                'assigned_to_user_id',
                                                                `${formId}-assignee`,
                                                            )}
                                                        >
                                                            <SelectValue />
                                                        </SelectTrigger>
                                                        <SelectContent>
                                                            <SelectItem value="none">
                                                                Unassigned
                                                            </SelectItem>
                                                            {currentAssigneeUnavailable && (
                                                                <SelectItem
                                                                    value={String(
                                                                        fields.assigned_to_user_id,
                                                                    )}
                                                                    disabled
                                                                >
                                                                    {optionName(
                                                                        'assignees',
                                                                        fields.assigned_to_user_id,
                                                                    )}{' '}
                                                                    · no longer
                                                                    eligible
                                                                </SelectItem>
                                                            )}
                                                            {currentOptions.assignees.map(
                                                                (option) => (
                                                                    <SelectItem
                                                                        key={
                                                                            option.id
                                                                        }
                                                                        value={String(
                                                                            option.id,
                                                                        )}
                                                                    >
                                                                        {
                                                                            option.name
                                                                        }
                                                                    </SelectItem>
                                                                ),
                                                            )}
                                                        </SelectContent>
                                                    </Select>
                                                    {currentAssigneeUnavailable && (
                                                        <p className="text-caption text-muted-foreground">
                                                            The current
                                                            assignment is
                                                            retained. Choose an
                                                            eligible technician
                                                            to reassign it.
                                                        </p>
                                                    )}
                                                </TaskField>
                                                <TaskField
                                                    id={`${formId}-team`}
                                                    label="Responsible team"
                                                    error={
                                                        editor.errors.team_id
                                                    }
                                                >
                                                    <Select
                                                        value={
                                                            fields.team_id
                                                                ? String(
                                                                      fields.team_id,
                                                                  )
                                                                : 'none'
                                                        }
                                                        onValueChange={(
                                                            value,
                                                        ) =>
                                                            editor.setField(
                                                                'team_id',
                                                                value === 'none'
                                                                    ? null
                                                                    : Number(
                                                                          value,
                                                                      ),
                                                            )
                                                        }
                                                    >
                                                        <SelectTrigger
                                                            id={`${formId}-team`}
                                                            data-task-field="team_id"
                                                            {...fieldA11y(
                                                                'team_id',
                                                                `${formId}-team`,
                                                            )}
                                                        >
                                                            <SelectValue />
                                                        </SelectTrigger>
                                                        <SelectContent>
                                                            <SelectItem value="none">
                                                                Unassigned
                                                            </SelectItem>
                                                            {currentTeamUnavailable && (
                                                                <SelectItem
                                                                    value={String(
                                                                        fields.team_id,
                                                                    )}
                                                                    disabled
                                                                >
                                                                    {optionName(
                                                                        'teams',
                                                                        fields.team_id,
                                                                    )}{' '}
                                                                    · no longer
                                                                    available
                                                                </SelectItem>
                                                            )}
                                                            {currentOptions.teams.map(
                                                                (option) => (
                                                                    <SelectItem
                                                                        key={
                                                                            option.id
                                                                        }
                                                                        value={String(
                                                                            option.id,
                                                                        )}
                                                                    >
                                                                        {
                                                                            option.name
                                                                        }
                                                                    </SelectItem>
                                                                ),
                                                            )}
                                                        </SelectContent>
                                                    </Select>
                                                </TaskField>
                                                <TaskField
                                                    id={`${formId}-due`}
                                                    label={`Due date and time (${Intl.DateTimeFormat().resolvedOptions().timeZone})`}
                                                    error={editor.errors.due_at}
                                                >
                                                    <Input
                                                        id={`${formId}-due`}
                                                        data-task-field="due_at"
                                                        {...fieldA11y(
                                                            'due_at',
                                                            `${formId}-due`,
                                                        )}
                                                        type="datetime-local"
                                                        value={localDateTime(
                                                            fields.due_at,
                                                        )}
                                                        onChange={(event) =>
                                                            editor.setField(
                                                                'due_at',
                                                                event.target
                                                                    .value
                                                                    ? new Date(
                                                                          event
                                                                              .target
                                                                              .value,
                                                                      ).toISOString()
                                                                    : null,
                                                            )
                                                        }
                                                    />
                                                </TaskField>
                                            </div>
                                        )}
                                        {editor.stepIndex === 2 && (
                                            <div className="space-y-4">
                                                <TaskField
                                                    id={`${formId}-approval`}
                                                    label="Approval request"
                                                    error={
                                                        editor.errors
                                                            .approval_id
                                                    }
                                                >
                                                    <Select
                                                        value={
                                                            fields.approval_id ===
                                                                null ||
                                                            fields.approval_id ===
                                                                undefined
                                                                ? 'none'
                                                                : String(
                                                                      fields.approval_id,
                                                                  )
                                                        }
                                                        onValueChange={(
                                                            value,
                                                        ) =>
                                                            editor.setField(
                                                                'approval_id',
                                                                value === 'none'
                                                                    ? null
                                                                    : Number(
                                                                          value,
                                                                      ),
                                                            )
                                                        }
                                                    >
                                                        <SelectTrigger
                                                            id={`${formId}-approval`}
                                                            data-task-field="approval_id"
                                                            {...fieldA11y(
                                                                'approval_id',
                                                                `${formId}-approval`,
                                                            )}
                                                        >
                                                            <SelectValue placeholder="Choose an approval request" />
                                                        </SelectTrigger>
                                                        <SelectContent>
                                                            <SelectItem value="none">
                                                                No linked
                                                                approval
                                                            </SelectItem>
                                                            {fields.approval_id &&
                                                                !currentOptions.approvals.some(
                                                                    ({ id }) =>
                                                                        id ===
                                                                        fields.approval_id,
                                                                ) && (
                                                                    <SelectItem
                                                                        value={String(
                                                                            fields.approval_id,
                                                                        )}
                                                                        disabled
                                                                    >
                                                                        Current
                                                                        approval
                                                                        #
                                                                        {
                                                                            fields.approval_id
                                                                        }{' '}
                                                                        is
                                                                        unavailable
                                                                    </SelectItem>
                                                                )}
                                                            {currentOptions.approvals.map(
                                                                (approval) => (
                                                                    <SelectItem
                                                                        key={
                                                                            approval.id
                                                                        }
                                                                        value={String(
                                                                            approval.id,
                                                                        )}
                                                                    >
                                                                        Request
                                                                        #
                                                                        {
                                                                            approval.id
                                                                        }{' '}
                                                                        ·{' '}
                                                                        {approval.status.replaceAll(
                                                                            '_',
                                                                            ' ',
                                                                        )}
                                                                    </SelectItem>
                                                                ),
                                                            )}
                                                        </SelectContent>
                                                    </Select>
                                                </TaskField>
                                                <label className="flex items-start gap-3 rounded-lg border border-border p-3">
                                                    <Checkbox
                                                        data-task-field="is_required"
                                                        {...fieldA11y(
                                                            'is_required',
                                                            `${formId}-required`,
                                                        )}
                                                        checked={
                                                            fields.is_required ===
                                                            true
                                                        }
                                                        onCheckedChange={(
                                                            value,
                                                        ) =>
                                                            editor.setField(
                                                                'is_required',
                                                                value === true,
                                                            )
                                                        }
                                                    />
                                                    <span>
                                                        <span className="block text-sm font-medium">
                                                            Required before
                                                            ticket settlement
                                                        </span>
                                                        <span className="text-caption text-muted-foreground">
                                                            The ticket cannot be
                                                            settled while this
                                                            required task is
                                                            incomplete.
                                                        </span>
                                                    </span>
                                                </label>
                                                <InputError
                                                    id={`${formId}-required-error`}
                                                    message={
                                                        editor.errors
                                                            .is_required
                                                    }
                                                />
                                                <label className="flex items-start gap-3 rounded-lg border border-border p-3">
                                                    <Checkbox
                                                        data-task-field="evidence_required"
                                                        {...fieldA11y(
                                                            'evidence_required',
                                                            `${formId}-evidence-required`,
                                                        )}
                                                        checked={
                                                            fields.evidence_required ===
                                                            true
                                                        }
                                                        onCheckedChange={(
                                                            value,
                                                        ) =>
                                                            editor.setField(
                                                                'evidence_required',
                                                                value === true,
                                                            )
                                                        }
                                                    />
                                                    <span>
                                                        <span className="block text-sm font-medium">
                                                            Completion evidence
                                                            required
                                                        </span>
                                                        <span className="text-caption text-muted-foreground">
                                                            Record evidence
                                                            references when
                                                            completing the task.
                                                        </span>
                                                    </span>
                                                </label>
                                                <InputError
                                                    id={`${formId}-evidence-required-error`}
                                                    message={
                                                        editor.errors
                                                            .evidence_required
                                                    }
                                                />
                                                <div
                                                    className="space-y-2"
                                                    role="group"
                                                    tabIndex={-1}
                                                    data-task-field="dependency_ids"
                                                    aria-labelledby={`${formId}-dependencies-label`}
                                                    {...fieldA11y(
                                                        'dependency_ids',
                                                        `${formId}-dependencies`,
                                                    )}
                                                >
                                                    <h3
                                                        id={`${formId}-dependencies-label`}
                                                        className="text-section-title"
                                                    >
                                                        Prerequisite tasks
                                                    </h3>
                                                    <p className="text-sm text-muted-foreground">
                                                        Prerequisites must be
                                                        complete before
                                                        dependent work can
                                                        proceed.
                                                    </p>
                                                    {dependencyRows.length ===
                                                    0 ? (
                                                        <p className="text-sm text-muted-foreground">
                                                            There are no other
                                                            tasks to select.
                                                        </p>
                                                    ) : (
                                                        dependencyRows.map(
                                                            (dependency) => {
                                                                const selected =
                                                                    selectedDependencies.includes(
                                                                        dependency.id,
                                                                    );
                                                                return (
                                                                    <label
                                                                        key={
                                                                            dependency.id
                                                                        }
                                                                        className="flex items-start gap-3 rounded-lg border border-border p-3"
                                                                    >
                                                                        <Checkbox
                                                                            checked={
                                                                                selected
                                                                            }
                                                                            disabled={
                                                                                !selected &&
                                                                                [
                                                                                    'cancelled',
                                                                                    'unavailable',
                                                                                ].includes(
                                                                                    dependency.status,
                                                                                )
                                                                            }
                                                                            onCheckedChange={(
                                                                                value,
                                                                            ) =>
                                                                                editor.setField(
                                                                                    'dependency_ids',
                                                                                    value ===
                                                                                        true
                                                                                        ? [
                                                                                              ...selectedDependencies,
                                                                                              dependency.id,
                                                                                          ]
                                                                                        : selectedDependencies.filter(
                                                                                              (
                                                                                                  id,
                                                                                              ) =>
                                                                                                  id !==
                                                                                                  dependency.id,
                                                                                          ),
                                                                                )
                                                                            }
                                                                        />
                                                                        <span className="min-w-0">
                                                                            <span className="block text-sm font-medium break-words">
                                                                                {
                                                                                    dependency.title
                                                                                }
                                                                            </span>
                                                                            <span className="text-caption text-muted-foreground">
                                                                                {dependency.status ===
                                                                                    'cancelled' ||
                                                                                dependency.status ===
                                                                                    'unavailable'
                                                                                    ? 'Unavailable prerequisite — retained so it can be reviewed or removed.'
                                                                                    : dependency.status.replaceAll(
                                                                                          '_',
                                                                                          ' ',
                                                                                      )}
                                                                            </span>
                                                                        </span>
                                                                    </label>
                                                                );
                                                            },
                                                        )
                                                    )}
                                                    <InputError
                                                        id={`${formId}-dependencies-error`}
                                                        message={
                                                            editor.errors
                                                                .dependency_ids
                                                        }
                                                    />
                                                </div>
                                            </div>
                                        )}
                                        {editor.stepIndex === 3 && (
                                            <div className="space-y-4">
                                                <ReviewCard
                                                    icon={FileText}
                                                    title="Task details"
                                                    onEdit={() =>
                                                        editor.setStepIndex(0)
                                                    }
                                                >
                                                    <ReviewRow
                                                        label="Title"
                                                        value={
                                                            <span className="break-words">
                                                                {fields.title}
                                                            </span>
                                                        }
                                                    />
                                                    <ReviewRow
                                                        label="Description"
                                                        value={
                                                            <span className="break-words whitespace-pre-wrap">
                                                                {
                                                                    fields.description
                                                                }
                                                            </span>
                                                        }
                                                    />
                                                    {task && (
                                                        <ReviewRow
                                                            label="Status"
                                                            value={fields.status?.replaceAll(
                                                                '_',
                                                                ' ',
                                                            )}
                                                        />
                                                    )}
                                                    <ReviewRow
                                                        label="Reason for the change"
                                                        value={fields.reason}
                                                    />
                                                </ReviewCard>
                                                <ReviewCard
                                                    icon={UsersRound}
                                                    title="Ownership"
                                                    onEdit={() =>
                                                        editor.setStepIndex(1)
                                                    }
                                                >
                                                    <ReviewRow
                                                        label="Technician"
                                                        value={optionName(
                                                            'assignees',
                                                            fields.assigned_to_user_id,
                                                        )}
                                                    />
                                                    <ReviewRow
                                                        label="Team"
                                                        value={optionName(
                                                            'teams',
                                                            fields.team_id,
                                                        )}
                                                    />
                                                    <ReviewRow
                                                        label="Due (New Zealand time)"
                                                        value={
                                                            fields.due_at
                                                                ? formatDateTime(
                                                                      fields.due_at,
                                                                  )
                                                                : 'Not set'
                                                        }
                                                    />
                                                </ReviewCard>
                                                <ReviewCard
                                                    icon={GitBranch}
                                                    title="Prerequisites and evidence"
                                                    onEdit={() =>
                                                        editor.setStepIndex(2)
                                                    }
                                                >
                                                    <ReviewRow
                                                        label="Required task"
                                                        value={
                                                            fields.is_required
                                                                ? 'Yes'
                                                                : 'No'
                                                        }
                                                    />
                                                    <ReviewRow
                                                        label="Evidence required"
                                                        value={
                                                            fields.evidence_required
                                                                ? 'Yes'
                                                                : 'No'
                                                        }
                                                    />
                                                    <ReviewRow
                                                        label="Prerequisites"
                                                        value={
                                                            selectedDependencies.length ? (
                                                                <span className="block space-y-1">
                                                                    {selectedDependencies.map(
                                                                        (
                                                                            id,
                                                                        ) => (
                                                                            <span
                                                                                key={
                                                                                    id
                                                                                }
                                                                                className="block break-words"
                                                                            >
                                                                                {dependencyRows.find(
                                                                                    (
                                                                                        item,
                                                                                    ) =>
                                                                                        item.id ===
                                                                                        id,
                                                                                )
                                                                                    ?.title ??
                                                                                    `Task #${id}`}
                                                                            </span>
                                                                        ),
                                                                    )}
                                                                </span>
                                                            ) : (
                                                                'None'
                                                            )
                                                        }
                                                    />
                                                </ReviewCard>
                                                <p className="text-caption text-muted-foreground">
                                                    This proposal uses ticket
                                                    version {editor.baseVersion}
                                                    . Saving is a separate,
                                                    explicit action.
                                                </p>
                                                {task && (
                                                    <ReviewCard
                                                        icon={GitBranch}
                                                        title="Reviewed changes"
                                                    >
                                                        <ReviewRow
                                                            label="Requirement"
                                                            value={`${editor.baseline.is_required ? 'Required' : 'Optional'} → ${fields.is_required ? 'Required' : 'Optional'}`}
                                                        />
                                                        <ReviewRow
                                                            label="Prior prerequisite IDs"
                                                            value={
                                                                editor.baseline.dependency_ids?.join(
                                                                    ', ',
                                                                ) || 'None'
                                                            }
                                                        />
                                                        <ReviewRow
                                                            label="Proposed prerequisite IDs"
                                                            value={
                                                                fields.dependency_ids?.join(
                                                                    ', ',
                                                                ) || 'None'
                                                            }
                                                        />
                                                        <ReviewRow
                                                            label="Approval request"
                                                            value={`${editor.baseline.approval_id ?? 'None'} → ${fields.approval_id ?? 'None'}`}
                                                        />
                                                        {editor.reasonRequired && (
                                                            <ReviewRow
                                                                label="Reason"
                                                                value={
                                                                    <span className="break-words whitespace-pre-wrap">
                                                                        {
                                                                            fields.reason
                                                                        }
                                                                    </span>
                                                                }
                                                            />
                                                        )}
                                                    </ReviewCard>
                                                )}
                                                {!task && (
                                                    <p className="text-sm">
                                                        Linked approval request:{' '}
                                                        {fields.approval_id ??
                                                            'None'}
                                                    </p>
                                                )}
                                                {needsImpactReview && (
                                                    <TaskImpactReview
                                                        history={history}
                                                        version={
                                                            editor.baseVersion
                                                        }
                                                        acknowledged={
                                                            impactReviewed
                                                        }
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
                                            </div>
                                        )}
                                    </WizardStepPane>
                                </fieldset>
                            </form>
                        </>
                    )}
                </div>
            </WizardShell>
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
                        ? 'Only this unsent proposal will be removed. The saved task and other drafts stay unchanged.'
                        : 'The current proposal stays in this browser session for explicit recovery. Closing does not cancel a submitted command. A full browser reload can lose unsent content.'
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
function TaskField({
    id,
    label,
    required,
    error,
    children,
}: {
    id: string;
    label: string;
    required?: boolean;
    error?: string;
    children: ReactNode;
}) {
    return (
        <div className="space-y-2">
            <label htmlFor={id} className="text-sm font-medium">
                {label}
                {required && <span aria-hidden="true"> *</span>}
            </label>
            {children}
            <InputError id={`${id}-error`} message={error} />
        </div>
    );
}
