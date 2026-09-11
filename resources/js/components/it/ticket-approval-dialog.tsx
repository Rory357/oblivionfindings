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
import type { ItApprovalWork } from '@/hooks/it-approval-work';
import type {
    ItApprovalCommitted,
    ItApprovalOperation,
} from '@/hooks/it-ticket-approval-contract';
import { useItApprovalEditor } from '@/hooks/use-it-approval-editor';
import { formatDateTime } from '@/lib/datetime';
import {
    CalendarClock,
    ClipboardCheck,
    ShieldCheck,
    UsersRound,
} from 'lucide-react';
import {
    type FormEvent,
    type ReactNode,
    useEffect,
    useId,
    useLayoutEffect,
    useRef,
    useState,
} from 'react';
import { TicketApprovalRecord } from './ticket-approval-record';
import { TicketApprovalRecovery } from './ticket-approval-recovery';

export interface ApprovalDialogProps {
    actorId: number;
    ticketId: number;
    approvalId: number | null;
    operation: ItApprovalOperation;
    version: number;
    work: ItApprovalWork;
    initialDecision?: 'approve' | 'reject';
    onClose: () => void;
    onCommitted: (result: ItApprovalCommitted) => void;
    onAccessLost: () => void;
    onSessionExpired?: () => void;
}
const steps = [
    {
        key: 'responsibility',
        label: 'Responsibility',
        blurb: 'Choose the approver and cover',
        icon: UsersRound,
    },
    {
        key: 'timing',
        label: 'Reason and timing',
        blurb: 'Explain the work and set optional dates',
        icon: CalendarClock,
    },
    {
        key: 'review',
        label: 'Review',
        blurb: 'Check the request before saving',
        icon: ClipboardCheck,
    },
];
function ApprovalField({
    id,
    label,
    error,
    children,
}: {
    id: string;
    label: string;
    error?: string;
    children: ReactNode;
}) {
    return (
        <div className="space-y-2">
            <label htmlFor={id} className="text-sm font-medium">
                {label}
            </label>
            {children}
            <InputError id={`${id}-error`} message={error} />
        </div>
    );
}
function ApprovalPane({
    active,
    title,
    description,
    children,
}: {
    active: boolean;
    title: string;
    description: string;
    children: ReactNode;
}) {
    return active ? (
        <WizardStepPane>
            <div className="space-y-5">
                <div>
                    <h3 className="text-section-title">{title}</h3>
                    <p className="mt-1 text-sm text-muted-foreground">
                        {description}
                    </p>
                </div>
                {children}
            </div>
        </WizardStepPane>
    ) : null;
}
function localDate(value?: string | null) {
    if (!value || !Number.isFinite(Date.parse(value))) return '';
    const date = new Date(value);
    const pad = (number: number) => String(number).padStart(2, '0');
    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`;
}
export function TicketApprovalDialog(props: ApprovalDialogProps) {
    const { operation, approvalId, actorId, ticketId, onClose } = props;
    const editor = useItApprovalEditor(props);
    const formId = useId();
    const form = useRef<HTMLFormElement>(null);
    const [closeChoice, setCloseChoice] = useState<'keep' | 'discard' | null>(
        null,
    );
    const confirmationTarget = useRef<HTMLElement | null>(null);
    const scope = `${actorId}:${ticketId}:${operation}:${approvalId}`;
    const focusScope = useRef({ scope, authorized: false });
    useLayoutEffect(() => {
        focusScope.current = { scope, authorized: !editor.concealed };
        return () => {
            focusScope.current.authorized = false;
        };
    }, [scope, editor.concealed]);
    const confirmClose = (choice: 'keep' | 'discard') => {
        const active = document.activeElement;
        confirmationTarget.current =
            active instanceof HTMLElement ? active : null;
        setCloseChoice(choice);
    };
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
        } else confirmClose('keep');
    };
    const firstError = Object.keys(editor.errors)[0];
    const setStepIndex = editor.setStepIndex;
    useEffect(() => {
        if (!firstError || editor.concealed) return;
        if (operation === 'request')
            setStepIndex(
                ['primary_approver_user_id', 'cover_approver_user_id'].includes(
                    firstError,
                )
                    ? 0
                    : 1,
            );
        const timer = window.setTimeout(
            () =>
                form.current
                    ?.querySelector<HTMLElement>(
                        `[data-approval-field="${firstError}"]`,
                    )
                    ?.focus(),
            0,
        );
        return () => window.clearTimeout(timer);
    }, [firstError, operation, editor.concealed, setStepIndex]);
    const continueStep = () => {
        if (!editor.canEdit) return;
        if (editor.stepIndex === 0 && !editor.fields.primary_approver_user_id) {
            editor.validate();
            return;
        }
        if (editor.stepIndex === 1 && Object.keys(editor.validate()).length)
            return;
        editor.setStepIndex(Math.min(2, editor.stepIndex + 1));
    };
    const submit = (event: FormEvent) => {
        event.preventDefault();
        if (operation === 'request' && editor.stepIndex < 2) continueStep();
        else editor.submit();
    };
    const actionLabel =
        operation === 'request'
            ? 'Request approval'
            : operation === 'withdraw'
              ? 'Cancel approval request'
              : editor.fields.decision === 'reject'
                ? 'Reject request'
                : 'Approve request';
    const a11y = (field: string) => ({
        id: `${formId}-${field}`,
        'data-approval-field': field,
        'aria-invalid': !!editor.errors[field],
        'aria-describedby': editor.errors[field]
            ? `${formId}-${field}-error`
            : undefined,
    });
    const personName = (id?: number | null) =>
        id
            ? (editor.currentWork.candidates.find((person) => person.id === id)
                  ?.name ?? `Unavailable selection #${id}`)
            : 'None selected';
    const reasonField = (
        <ApprovalField
            id={`${formId}-reason`}
            label={
                operation === 'request'
                    ? 'Why is approval needed? (optional)'
                    : operation === 'withdraw'
                      ? 'Reason for cancellation'
                      : editor.fields.decision === 'reject'
                        ? 'Reason for rejection'
                        : 'Decision note (optional)'
            }
            error={editor.errors.reason}
        >
            <Textarea
                {...a11y('reason')}
                value={editor.fields.reason ?? ''}
                onChange={(event) =>
                    editor.setField('reason', event.target.value)
                }
                rows={4}
                maxLength={1000}
                disabled={!editor.canEdit}
                required={
                    operation === 'withdraw' ||
                    editor.fields.decision === 'reject'
                }
            />
        </ApprovalField>
    );
    const recovery = (
        <TicketApprovalRecovery
            editor={editor}
            operation={operation}
            approvalId={approvalId}
        />
    );
    const contents = (
        <form
            ref={form}
            id={formId}
            onSubmit={submit}
            className="space-y-5"
            noValidate
        >
            {recovery}
            {editor.stale && (
                <p role="status" className="text-sm text-status-warning">
                    The ticket changed. Review its current approval before
                    saving this proposal.
                </p>
            )}
            {editor.concealed ? (
                <p role="status" className="text-sm">
                    Private approval fields are hidden until current access is
                    confirmed.
                </p>
            ) : operation === 'request' ? (
                <>
                    <ApprovalPane
                        active={editor.stepIndex === 0}
                        title="Choose responsibility"
                        description="Choose a different approved IT manager to make the decision. Cover becomes responsible only when the primary approver is unavailable."
                    >
                        {(
                            [
                                'primary_approver_user_id',
                                'cover_approver_user_id',
                            ] as const
                        ).map((field) => (
                            <ApprovalField
                                key={field}
                                id={`${formId}-${field}`}
                                label={
                                    field === 'primary_approver_user_id'
                                        ? 'Primary approver'
                                        : 'Absence cover (optional)'
                                }
                                error={editor.errors[field]}
                            >
                                <Select
                                    value={
                                        editor.fields[field]
                                            ? String(editor.fields[field])
                                            : 'none'
                                    }
                                    onValueChange={(value) =>
                                        editor.setField(
                                            field,
                                            value === 'none'
                                                ? null
                                                : Number(value),
                                        )
                                    }
                                    disabled={!editor.canEdit}
                                >
                                    <SelectTrigger {...a11y(field)}>
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="none">
                                            {field ===
                                            'primary_approver_user_id'
                                                ? 'Choose an approver'
                                                : 'No cover selected'}
                                        </SelectItem>
                                        {!!editor.fields[field] &&
                                            !editor.currentWork.candidates.some(
                                                (person) =>
                                                    person.id ===
                                                    editor.fields[field],
                                            ) && (
                                                <SelectItem
                                                    value={String(
                                                        editor.fields[field],
                                                    )}
                                                    disabled
                                                >
                                                    {personName(
                                                        editor.fields[field],
                                                    )}
                                                </SelectItem>
                                            )}
                                        {editor.currentWork.candidates.map(
                                            (person) => (
                                                <SelectItem
                                                    key={person.id}
                                                    value={String(person.id)}
                                                    disabled={
                                                        field ===
                                                            'cover_approver_user_id' &&
                                                        person.id ===
                                                            editor.fields
                                                                .primary_approver_user_id
                                                    }
                                                >
                                                    {person.name}
                                                    {person.available
                                                        ? ''
                                                        : ' · currently unavailable'}
                                                </SelectItem>
                                            ),
                                        )}
                                    </SelectContent>
                                </Select>
                            </ApprovalField>
                        ))}
                        {!editor.currentWork.candidates.length && (
                            <p role="status" className="text-sm">
                                No other eligible approvers are available for
                                this ticket. An administrator must check their
                                approved account, employment and Site access.
                            </p>
                        )}
                    </ApprovalPane>
                    <ApprovalPane
                        active={editor.stepIndex === 1}
                        title="Reason and timing"
                        description="Dates apply to this request only. Leave either date empty when no deadline or reminder is needed."
                    >
                        {reasonField}
                        {(['expires_at', 'remind_at'] as const).map((field) => (
                            <ApprovalField
                                key={field}
                                id={`${formId}-${field}`}
                                label={`${field === 'expires_at' ? 'Deadline' : 'Reminder'} (optional · ${Intl.DateTimeFormat().resolvedOptions().timeZone})`}
                                error={editor.errors[field]}
                            >
                                <Input
                                    {...a11y(field)}
                                    type="datetime-local"
                                    value={localDate(editor.fields[field])}
                                    disabled={!editor.canEdit}
                                    onChange={(event) =>
                                        editor.setField(
                                            field,
                                            event.target.value
                                                ? new Date(
                                                      event.target.value,
                                                  ).toISOString()
                                                : null,
                                        )
                                    }
                                />
                            </ApprovalField>
                        ))}
                        <p className="text-sm text-muted-foreground">
                            A deadline closes the request without a human
                            decision. A reminder is queued for the responsible
                            approver; delivery depends on the notification
                            service.
                        </p>
                    </ApprovalPane>
                    <ApprovalPane
                        active={editor.stepIndex === 2}
                        title="Review approval request"
                        description="Check the responsibility and reason. Saving creates a recorded request and queues its notification."
                    >
                        <ReviewCard icon={ShieldCheck} title="Your proposal">
                            <ReviewRow
                                label="Primary approver"
                                value={personName(
                                    editor.fields.primary_approver_user_id,
                                )}
                            />
                            <ReviewRow
                                label="Absence cover"
                                value={personName(
                                    editor.fields.cover_approver_user_id,
                                )}
                            />
                            <ReviewRow
                                label="Reason"
                                value={
                                    <span className="break-words whitespace-pre-wrap">
                                        {editor.fields.reason ||
                                            'No reason supplied'}
                                    </span>
                                }
                            />
                            <ReviewRow
                                label="Deadline"
                                value={
                                    editor.fields.expires_at
                                        ? formatDateTime(
                                              editor.fields.expires_at,
                                          )
                                        : 'No deadline'
                                }
                            />
                            <ReviewRow
                                label="Reminder"
                                value={
                                    editor.fields.remind_at
                                        ? formatDateTime(
                                              editor.fields.remind_at,
                                          )
                                        : 'No reminder'
                                }
                            />
                        </ReviewCard>
                    </ApprovalPane>
                </>
            ) : (
                <>
                    {editor.currentWork.current?.id === approvalId && (
                        <TicketApprovalRecord
                            record={editor.currentWork.current}
                            anchor={false}
                        />
                    )}
                    {reasonField}
                </>
            )}
        </form>
    );
    const closeButtons = (
        <>
            <Button type="button" variant="ghost" onClick={requestClose}>
                Close
            </Button>
            {operation === 'request' && editor.stepIndex > 0 && (
                <Button
                    type="button"
                    variant="outline"
                    disabled={!editor.canEdit}
                    onClick={() => editor.setStepIndex(editor.stepIndex - 1)}
                >
                    Back
                </Button>
            )}
            {editor.dirty && (
                <Button
                    type="button"
                    variant="ghost"
                    disabled={
                        editor.busy ||
                        editor.command.outcomeUnknown ||
                        !!editor.command.references.length
                    }
                    onClick={() => confirmClose('discard')}
                >
                    Discard changes
                </Button>
            )}
        </>
    );
    const saveButton = (
        <Button
            key="save-approval"
            type="submit"
            form={formId}
            variant={
                operation === 'withdraw' || editor.fields.decision === 'reject'
                    ? 'destructive'
                    : 'default'
            }
            disabled={!editor.canEdit || editor.stale}
        >
            {actionLabel}
        </Button>
    );
    const successTitle =
        editor.command.result?.approval_status === 'approved'
            ? 'Approval recorded'
            : editor.command.result?.approval_status === 'rejected'
              ? 'Rejection recorded'
              : editor.command.result?.approval_status === 'cancelled'
                ? 'Approval request cancelled'
                : 'Approval requested';
    return (
        <>
            {operation === 'request' ? (
                <WizardShell
                    open
                    onClose={requestClose}
                    title="Request manager approval"
                    description="Choose responsibility, set the reason and timing, then review this request."
                    railIcon={ShieldCheck}
                    railTitle="Request approval"
                    railSub="Ticket work and decisions"
                    steps={steps}
                    stepIndex={editor.stepIndex}
                    onStepClick={(index) => {
                        if (editor.canEdit && index < editor.stepIndex)
                            editor.setStepIndex(index);
                    }}
                    footerStart={closeButtons}
                    footerEnd={
                        editor.stepIndex < 2 ? (
                            <Button
                                key="continue-approval"
                                type="button"
                                onClick={(event) => {
                                    event.preventDefault();
                                    continueStep();
                                }}
                                disabled={!editor.canEdit}
                            >
                                Continue
                            </Button>
                        ) : (
                            saveButton
                        )
                    }
                    success={
                        editor.command.result ? (
                            <WizardSuccessPane
                                title={successTitle}
                                blurb={
                                    editor.retainedAfterCommit
                                        ? 'The earlier command is confirmed. Your separate unsaved proposal is retained for recovery.'
                                        : 'The saved request is confirmed. Notification delivery is tracked separately.'
                                }
                                actions={
                                    <Button onClick={requestClose}>Done</Button>
                                }
                            />
                        ) : undefined
                    }
                >
                    {contents}
                </WizardShell>
            ) : (
                <Dialog
                    open
                    onOpenChange={(open) => {
                        if (!open) requestClose();
                    }}
                >
                    <DialogContent className="max-h-[88vh] overflow-y-auto sm:max-w-3xl">
                        <DialogHeader>
                            <DialogTitle>{actionLabel}</DialogTitle>
                            <DialogDescription>
                                {operation === 'withdraw'
                                    ? 'Cancel this pending request with a recorded reason. Previous evidence is preserved.'
                                    : 'Review the request and record your decision. Required work still needs to be completed before ticket settlement.'}
                            </DialogDescription>
                        </DialogHeader>
                        {editor.command.result ? (
                            <div role="status" className="space-y-3">
                                <h3 className="text-section-title">
                                    {successTitle}
                                </h3>
                                <p className="text-sm">
                                    The original command is confirmed.{' '}
                                    {editor.retainedAfterCommit
                                        ? 'Your separate proposal remains available for recovery.'
                                        : 'Notification delivery is tracked separately.'}
                                </p>
                                <Button onClick={requestClose}>Done</Button>
                            </div>
                        ) : (
                            <>
                                {contents}
                                <DialogFooter className="flex-wrap gap-2">
                                    {closeButtons}
                                    {saveButton}
                                </DialogFooter>
                            </>
                        )}
                    </DialogContent>
                </Dialog>
            )}
            <ConfirmDialog
                open={closeChoice !== null}
                onClose={() => setCloseChoice(null)}
                title={
                    closeChoice === 'discard'
                        ? 'Discard your approval changes?'
                        : 'Keep this draft and close?'
                }
                description={
                    closeChoice === 'discard'
                        ? 'Only this unsent proposal is removed. Saved requests, decisions and other drafts are preserved.'
                        : 'Your proposal stays in this browser page’s memory. Resume checks access before showing it again. Reloading or closing the browser loses unsent text; an unresolved command keeps its recovery reference.'
                }
                confirmText={
                    closeChoice === 'discard'
                        ? 'Discard changes'
                        : 'Keep draft and close'
                }
                variant={closeChoice === 'discard' ? 'destructive' : 'default'}
                onConfirm={() => {
                    if (
                        closeChoice === 'discard'
                            ? editor.discardOwned()
                            : editor.keepForClose()
                    )
                        onClose();
                }}
                onCloseAutoFocus={(event) => {
                    const owner =
                        form.current?.closest<HTMLElement>('[role="dialog"]');
                    if (
                        !owner ||
                        focusScope.current.scope !== scope ||
                        !focusScope.current.authorized
                    )
                        return;
                    event.preventDefault();
                    const target = confirmationTarget.current;
                    restoreOverlayFocus({
                        current: {
                            owner,
                            target:
                                target &&
                                owner.contains(target) &&
                                !target.matches(':disabled')
                                    ? target
                                    : null,
                        },
                    });
                }}
            />
        </>
    );
}
