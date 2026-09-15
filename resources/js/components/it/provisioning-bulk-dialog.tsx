import { ConfirmDialog } from '@/components/confirm-dialog';
import {
    ProvisioningPicker,
    type ProvisioningOption,
} from '@/components/it/provisioning-picker';
import {
    provisioningActionLabel,
    type ProvisioningTask,
} from '@/components/it/provisioning-workspace';
import {
    useProvisioningBulkCommand,
    type ProvisioningBulkOperation,
} from '@/components/it/use-provisioning-bulk-command';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import {
    ReviewCard,
    ReviewRow,
    WizardShell,
    WizardStepPane,
} from '@/components/wizard/shell';
import { router } from '@inertiajs/react';
import { CheckCircle2, ClipboardList, Package } from 'lucide-react';
import { useEffect, useLayoutEffect, useRef, useState } from 'react';

export function ProvisioningBulkDialog({
    actorId,
    selection,
    onClose,
    onDenied,
}: {
    actorId: number;
    selection?: {
        operation: ProvisioningBulkOperation;
        tasks: ProvisioningTask[];
    };
    onClose: () => void;
    onDenied: () => void;
}) {
    const command = useProvisioningBulkCommand(
        actorId,
        selection
            ? {
                  operation: selection.operation,
                  items: selection.tasks.map((task) => ({
                      id: task.id,
                      version: task.version,
                  })),
              }
            : undefined,
    );
    const [step, setStep] = useState(0);
    const [reason, setReason] = useState('');
    const [agent, setAgent] = useState<ProvisioningOption | null>(null);
    const [validAgent, setValidAgent] = useState(false);
    const [cover, setCover] = useState<ProvisioningOption | null>(null);
    const [validCover, setValidCover] = useState(false);
    const [expiresOn, setExpiresOn] = useState('');
    const [discard, setDiscard] = useState(false);
    const pendingNavigation = useRef<(() => void) | null>(null);
    const allowNavigation = useRef(false);
    const callbacks = useRef({ onClose, onDenied });
    useLayoutEffect(() => {
        callbacks.current = { onClose, onDenied };
    }, [onClose, onDenied]);
    const operation =
        command.reference?.operation ?? selection?.operation ?? 'assign';
    const firstTaskId = command.reference?.items[0]?.id;
    const preparing = command.canSubmit && !command.restored;
    const submitted =
        command.rows.some((row) => row.phase !== 'ready') || command.restored;
    const dirty =
        reason.length > 0 ||
        agent !== null ||
        cover !== null ||
        expiresOn !== '';
    const ready =
        operation === 'assign'
            ? agent !== null && validAgent
            : operation === 'request_approval'
              ? agent !== null &&
                validAgent &&
                cover !== null &&
                validCover &&
                agent.id !== cover.id &&
                /^\d{4}-\d{2}-\d{2}$/.test(expiresOn) &&
                reason.trim().length > 0
              : reason.trim().length > 0;
    const guarded = !command.terminal && (dirty || submitted || command.busy);
    const close = () => {
        if (command.busy || guarded) setDiscard(true);
        else {
            command.forgetIfSafe();
            onClose();
        }
    };
    useEffect(() => {
        if (!command.concealed) return;
        setReason('');
        setAgent(null);
        setCover(null);
        setExpiresOn('');
        callbacks.current.onDenied();
    }, [command.concealed]);
    useEffect(() => {
        const unload = (event: BeforeUnloadEvent) => {
            if (guarded) {
                event.preventDefault();
                event.returnValue = '';
            }
        };
        const remove = router.on('before', (event) => {
            if (allowNavigation.current || !guarded) return;
            event.preventDefault();
            const visit = event.detail.visit;
            pendingNavigation.current = () => router.visit(visit.url, visit);
            setDiscard(true);
        });
        window.addEventListener('beforeunload', unload);
        return () => {
            window.removeEventListener('beforeunload', unload);
            remove();
        };
    }, [guarded]);
    return (
        <>
            <WizardShell
                open
                onClose={close}
                title={provisioningActionLabel[operation] + ' · selected tasks'}
                description="Review one change for each selected task. Each task keeps its own recorded outcome."
                railIcon={Package}
                railTitle="Selected provisioning work"
                railSub="Review and recorded results"
                steps={[
                    {
                        key: 'details',
                        label: 'Details',
                        blurb: 'Choose the recorded change',
                        icon: ClipboardList,
                        disabled: !preparing,
                    },
                    {
                        key: 'review',
                        label: 'Review & results',
                        blurb: 'Check each selected task',
                        icon: CheckCircle2,
                        disabled: !preparing,
                    },
                ]}
                stepIndex={preparing ? step : 1}
                onStepClick={(index) => {
                    if (preparing && (index === 0 || ready)) setStep(index);
                }}
                footerStart={
                    <Button variant="outline" onClick={close}>
                        Close
                    </Button>
                }
                footerEnd={
                    preparing ? (
                        step === 0 ? (
                            <Button
                                disabled={!ready}
                                onClick={() => setStep(1)}
                            >
                                Continue
                            </Button>
                        ) : (
                            <div className="flex gap-2">
                                <Button
                                    variant="outline"
                                    onClick={() => setStep(0)}
                                >
                                    Back
                                </Button>
                                <Button
                                    disabled={!ready}
                                    onClick={() =>
                                        command.submit(
                                            operation === 'assign'
                                                ? {
                                                      assigned_to_user_id:
                                                          agent?.id,
                                                  }
                                                : operation ===
                                                    'request_approval'
                                                  ? {
                                                        primary_approver_user_id:
                                                            agent?.id,
                                                        cover_approver_user_id:
                                                            cover?.id,
                                                        approval_expires_on:
                                                            expiresOn,
                                                        reason: reason.trim(),
                                                    }
                                                  : { reason: reason.trim() },
                                        )
                                    }
                                >
                                    Confirm {command.rows.length} task changes
                                </Button>
                            </div>
                        )
                    ) : command.busy ? (
                        <Button variant="outline" onClick={command.stopWaiting}>
                            Stop waiting
                        </Button>
                    ) : undefined
                }
            >
                <WizardStepPane>
                    <div className="space-y-5">
                        {command.message && (
                            <p role="alert">{command.message}</p>
                        )}
                        {command.concealed ? (
                            <p role="alert">
                                Private details are hidden because your account
                                or access changed.
                            </p>
                        ) : preparing && step === 0 ? (
                            <div className="space-y-4">
                                <p className="text-sm text-muted-foreground">
                                    Up to 20 selected tasks are checked
                                    individually against their current access
                                    and work requirements.
                                </p>
                                {operation === 'assign' ? (
                                    <ProvisioningPicker
                                        actorId={actorId}
                                        kind="agents"
                                        contextKind="request"
                                        {...(firstTaskId
                                            ? { contextId: firstTaskId }
                                            : {})}
                                        label="Assignee"
                                        value={agent?.id ?? null}
                                        onChange={setAgent}
                                        onValidated={(value) =>
                                            setValidAgent(value !== null)
                                        }
                                        onDenied={onDenied}
                                    />
                                ) : operation === 'request_approval' ? (
                                    <div className="space-y-4">
                                        <ProvisioningPicker
                                            actorId={actorId}
                                            kind="agents"
                                            contextKind="request"
                                            {...(firstTaskId
                                                ? { contextId: firstTaskId }
                                                : {})}
                                            label="Primary approver"
                                            value={agent?.id ?? null}
                                            onChange={setAgent}
                                            onValidated={(value) =>
                                                setValidAgent(value !== null)
                                            }
                                            onDenied={onDenied}
                                        />
                                        <ProvisioningPicker
                                            actorId={actorId}
                                            kind="agents"
                                            contextKind="request"
                                            {...(firstTaskId
                                                ? { contextId: firstTaskId }
                                                : {})}
                                            label="Absence cover"
                                            value={cover?.id ?? null}
                                            onChange={setCover}
                                            onValidated={(value) =>
                                                setValidCover(value !== null)
                                            }
                                            onDenied={onDenied}
                                        />
                                        {agent &&
                                            cover &&
                                            agent.id === cover.id && (
                                                <p
                                                    role="alert"
                                                    className="text-sm text-status-critical"
                                                >
                                                    Choose two different people.
                                                </p>
                                            )}
                                        <label className="block space-y-2 text-sm font-medium">
                                            <span>
                                                Approval deadline (NZ date) *
                                            </span>
                                            <Input
                                                type="date"
                                                value={expiresOn}
                                                onChange={(event) =>
                                                    setExpiresOn(
                                                        event.target.value,
                                                    )
                                                }
                                            />
                                        </label>
                                        <label className="block space-y-2 text-sm font-medium">
                                            <span>
                                                Reason and next action *
                                            </span>
                                            <Textarea
                                                value={reason}
                                                maxLength={5000}
                                                onChange={(event) =>
                                                    setReason(
                                                        event.target.value,
                                                    )
                                                }
                                            />
                                        </label>
                                    </div>
                                ) : (
                                    <label className="block space-y-2 text-sm font-medium">
                                        <span>Reason and next action *</span>
                                        <Textarea
                                            value={reason}
                                            maxLength={5000}
                                            onChange={(event) =>
                                                setReason(event.target.value)
                                            }
                                        />
                                    </label>
                                )}
                                <p className="text-sm text-muted-foreground">
                                    {operation === 'cancel'
                                        ? 'Completed work stays recorded. This cancels only work that can still be cancelled; reversal work is reviewed separately.'
                                        : operation === 'retry'
                                          ? 'Retry returns failed tasks to the work queue. It does not perform external account or equipment changes.'
                                          : operation === 'fail'
                                            ? 'Each task is marked failed with this reason and needs an explicit retry before completion.'
                                            : operation === 'request_approval'
                                              ? 'Both approvers must be eligible for every selected task; the requester and beneficiary of a task can never approve it. Approval expires at the end of the NZ date.'
                                              : operation === 'approve' ||
                                                  operation === 'reject'
                                                ? 'Only tasks where you are the currently responsible approver will be decided. A rejection needs the reason below.'
                                                : 'The assignee must be eligible for every individual task. Any task that cannot accept the change will have its own result.'}
                                </p>
                            </div>
                        ) : preparing ? (
                            <ReviewCard
                                icon={ClipboardList}
                                title="Review selected work"
                                onEdit={() => setStep(0)}
                            >
                                <ReviewRow
                                    label="Action"
                                    value={
                                        provisioningActionLabel[operation] ??
                                        operation
                                    }
                                />
                                {operation === 'request_approval' ? (
                                    <>
                                        <ReviewRow
                                            label="Primary approver"
                                            value={agent?.label ?? ''}
                                        />
                                        <ReviewRow
                                            label="Absence cover"
                                            value={cover?.label ?? ''}
                                        />
                                        <ReviewRow
                                            label="Approval deadline"
                                            value={expiresOn}
                                        />
                                        <ReviewRow
                                            label="Reason and next action"
                                            value={reason}
                                        />
                                    </>
                                ) : (
                                    <ReviewRow
                                        label={
                                            operation === 'assign'
                                                ? 'Assignee'
                                                : 'Reason and next action'
                                        }
                                        value={agent?.label ?? reason}
                                    />
                                )}
                                {selection?.tasks.map((task) => (
                                    <ReviewRow
                                        key={task.id}
                                        label={task.reference}
                                        value={
                                            task.employee.name +
                                            ' · ' +
                                            task.title
                                        }
                                    />
                                ))}
                            </ReviewCard>
                        ) : (
                            <div className="space-y-4">
                                {command.restored && (
                                    <p className="text-sm text-muted-foreground">
                                        This browser retained only the original
                                        task references. Check their outcomes
                                        before preparing new changes.
                                    </p>
                                )}
                                {command.terminal && (
                                    <p role="status" className="font-medium">
                                        Every original command is accounted for.
                                    </p>
                                )}
                                {command.rows.map((row) => {
                                    const task = selection?.tasks.find(
                                        (candidate) => candidate.id === row.id,
                                    );
                                    const finished =
                                        row.phase === 'committed' ||
                                        row.phase === 'cancelled';
                                    return (
                                        <section
                                            key={row.id}
                                            aria-label={
                                                'Result for task ' + row.id
                                            }
                                            className="space-y-3 rounded-lg border p-4"
                                        >
                                            <h3 className="font-medium">
                                                {task
                                                    ? task.reference +
                                                      ' · ' +
                                                      task.title
                                                    : 'Task ' + row.id}
                                            </h3>
                                            <p
                                                role="status"
                                                className="text-sm"
                                            >
                                                {row.message}
                                            </p>
                                            <div className="flex flex-wrap gap-2">
                                                {row.outcome?.status ===
                                                    'committed' && (
                                                    <Button
                                                        variant="outline"
                                                        onClick={() => {
                                                            const outcome =
                                                                row.outcome;
                                                            if (
                                                                outcome?.status ===
                                                                'committed'
                                                            )
                                                                router.visit(
                                                                    outcome.url,
                                                                );
                                                        }}
                                                    >
                                                        Open updated task
                                                    </Button>
                                                )}
                                                {!finished && (
                                                    <Button
                                                        variant="outline"
                                                        disabled={command.busy}
                                                        onClick={() =>
                                                            void command.recover(
                                                                row.id,
                                                            )
                                                        }
                                                    >
                                                        Check outcome
                                                    </Button>
                                                )}
                                                {!finished && (
                                                    <Button
                                                        variant="outline"
                                                        disabled={command.busy}
                                                        onClick={() =>
                                                            void command.cancel(
                                                                row.id,
                                                            )
                                                        }
                                                    >
                                                        Cancel uncommitted
                                                        command
                                                    </Button>
                                                )}
                                                {command.canRetry(row.id) && (
                                                    <Button
                                                        disabled={command.busy}
                                                        onClick={() =>
                                                            void command.retry(
                                                                row.id,
                                                            )
                                                        }
                                                    >
                                                        Retry original change
                                                    </Button>
                                                )}
                                            </div>
                                        </section>
                                    );
                                })}
                            </div>
                        )}
                    </div>
                </WizardStepPane>
            </WizardShell>
            <ConfirmDialog
                open={discard}
                onClose={() => {
                    setDiscard(false);
                    pendingNavigation.current = null;
                }}
                onConfirm={() => {
                    command.stopWaiting();
                    command.forgetIfSafe();
                    allowNavigation.current = true;
                    onClose();
                    pendingNavigation.current?.();
                }}
                title="Close selected work?"
                description={
                    submitted || command.busy
                        ? 'Original command references remain available from Check bulk outcomes. Private form fields will be lost. Stopping the wait does not stop a server change.'
                        : 'Your unsaved fields will be discarded.'
                }
                confirmText="Close form"
            />
        </>
    );
}
