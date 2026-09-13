import { ConfirmDialog } from '@/components/confirm-dialog';
import {
    ProvisioningPicker,
    type ProvisioningOption,
} from '@/components/it/provisioning-picker';
import {
    provisioningActionLabel,
    provisioningLabel,
} from '@/components/it/provisioning-workspace';
import {
    useProvisioningCommand,
    type ProvisioningCommandIdentity,
} from '@/components/it/use-provisioning-command';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
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
import { router } from '@inertiajs/react';
import { CheckCircle2, ClipboardList, Package } from 'lucide-react';
import { useEffect, useLayoutEffect, useRef, useState } from 'react';

export interface ProvisioningCommandField {
    key: string;
    label: string;
    kind:
        | 'text'
        | 'textarea'
        | 'date'
        | 'agent'
        | 'template'
        | 'checkbox'
        | 'select'
        | 'target';
    options?: string[];
    required?: boolean;
    help?: string;
}
type Values = Record<string, string | number | boolean | null>;

export function ProvisioningCommandDialog({
    context,
    version,
    title,
    description,
    fields,
    initial = {},
    review,
    onClose,
    onDenied,
    extraPayload = {},
}: {
    context: Omit<ProvisioningCommandIdentity, 'requestUuid'>;
    version: number;
    title: string;
    description: string;
    fields: ProvisioningCommandField[];
    initial?: Values;
    review: { label: string; value: string }[];
    onClose: () => void;
    onDenied: () => void;
    extraPayload?: Record<string, unknown>;
}) {
    const [values, setValues] = useState<Values>(() =>
        Object.fromEntries(
            fields.map((field) => [
                field.key,
                initial[field.key] ?? (field.kind === 'checkbox' ? false : ''),
            ]),
        ),
    );
    const [labels, setLabels] = useState<Record<string, string>>({});
    const [templateLifecycle, setTemplateLifecycle] = useState<string | null>(
        null,
    );
    const [templatePreview, setTemplatePreview] =
        useState<ProvisioningOption | null>(null);
    const [validatedChoices, setValidatedChoices] = useState<
        Record<string, boolean>
    >({});
    const [step, setStep] = useState(0);
    const [expectedVersion, setExpectedVersion] = useState(version);
    const [discard, setDiscard] = useState(false);
    const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});
    const [concealed, setConcealed] = useState(false);
    const command = useProvisioningCommand(context);
    const [original] = useState(() => JSON.stringify(values));
    const form = useRef<HTMLFormElement>(null);
    const feedback = useRef<HTMLDivElement>(null);
    const allowNavigation = useRef(false);
    const pendingNavigation = useRef<(() => void) | null>(null);
    const callbacks = useRef({ onDenied, onClose });
    useLayoutEffect(() => {
        callbacks.current = { onDenied, onClose };
    }, [onDenied, onClose]);
    const dirty = original !== JSON.stringify(values);
    const terminal =
        command.phase === 'committed' || command.phase === 'cancelled';
    const hidden = concealed || command.concealed;
    const stale = expectedVersion !== version;
    const errors = { ...fieldErrors, ...command.errors };
    useEffect(() => {
        if (command.phase === 'denied') {
            setValues({});
            setLabels({});
            setTemplatePreview(null);
            setValidatedChoices({});
            callbacks.current.onDenied();
        }
    }, [command.phase]);
    useEffect(() => {
        if (command.message && !command.busy) feedback.current?.focus();
    }, [command.message, command.busy]);
    useEffect(() => {
        if (terminal) return;
        const unload = (event: BeforeUnloadEvent) => {
            if (dirty || command.canRecover || command.busy) {
                event.preventDefault();
                event.returnValue = '';
            }
        };
        const remove = router.on('before', (event) => {
            if (allowNavigation.current) return;
            if (dirty || command.canRecover || command.busy) {
                event.preventDefault();
                const visit = event.detail.visit;
                pendingNavigation.current = () =>
                    router.visit(visit.url, visit);
                setDiscard(true);
            }
        });
        window.addEventListener('beforeunload', unload);
        return () => {
            window.removeEventListener('beforeunload', unload);
            remove();
        };
    }, [dirty, command.canRecover, command.busy, terminal]);
    const close = () => {
        if (command.busy) return;
        if (terminal) {
            allowNavigation.current = true;
            onClose();
            if (command.phase === 'committed') router.reload();
        } else if (!dirty && !command.canRecover) onClose();
        else setDiscard(true);
    };
    const denied = () => {
        setConcealed(true);
        setValues({});
        setLabels({});
        setTemplatePreview(null);
        setValidatedChoices({});
        onDenied();
    };
    const choose = (
        field: ProvisioningCommandField,
        selected: ProvisioningOption | null,
    ) => {
        setValues((current) => ({
            ...current,
            [field.key]: selected?.id ?? null,
        }));
        setLabels((current) => ({
            ...current,
            [field.key]: selected?.label ?? '',
        }));
        setValidatedChoices((current) => ({
            ...current,
            [field.key]: selected !== null,
        }));
        if (field.kind === 'template') {
            setTemplateLifecycle(selected?.lifecycle_type ?? null);
            setTemplatePreview(selected);
        }
    };
    const advance = (next: number) => {
        if (!command.canEdit || hidden) return;
        if (next === 0) {
            setStep(0);
            return;
        }
        const missing = Object.fromEntries(
            fields
                .filter(
                    (field) =>
                        field.required &&
                        ((['agent', 'template', 'target'].includes(
                            field.kind,
                        ) &&
                            !validatedChoices[field.key]) ||
                            values[field.key] === '' ||
                            values[field.key] === null ||
                            values[field.key] === undefined),
                )
                .map((field) => [
                    field.key,
                    'Choose or enter ' + field.label.toLowerCase() + '.',
                ]),
        );
        setFieldErrors(missing);
        if (Object.keys(missing).length || !form.current?.reportValidity()) {
            feedback.current?.focus();
            return;
        }
        setStep(1);
    };
    const actionLabel =
        provisioningActionLabel[context.operation] ??
        provisioningLabel(context.operation);
    return (
        <>
            <WizardShell
                open
                onClose={close}
                title={title}
                description={description}
                railIcon={Package}
                railTitle={actionLabel}
                railSub="Details and review"
                steps={[
                    {
                        key: 'details',
                        label: 'Details',
                        blurb: 'Record the action and responsibility',
                        icon: ClipboardList,
                        disabled: !command.canEdit,
                    },
                    {
                        key: 'review',
                        label: 'Review',
                        blurb: 'Check the work before confirming',
                        icon: CheckCircle2,
                        disabled: !command.canEdit,
                    },
                ]}
                stepIndex={step}
                onStepClick={advance}
                footerStart={
                    <Button
                        variant="outline"
                        onClick={close}
                        disabled={command.busy}
                    >
                        Close
                    </Button>
                }
                footerEnd={
                    command.canEdit && !hidden && !stale ? (
                        step === 0 ? (
                            <Button onClick={() => advance(1)}>Continue</Button>
                        ) : (
                            <div className="flex gap-2">
                                <Button
                                    variant="outline"
                                    onClick={() => advance(0)}
                                >
                                    Back
                                </Button>
                                <Button
                                    onClick={() =>
                                        command.submit({
                                            ...values,
                                            ...extraPayload,
                                            expected_version: expectedVersion,
                                            ...(templateLifecycle
                                                ? {
                                                      lifecycle_type:
                                                          templateLifecycle,
                                                  }
                                                : {}),
                                        })
                                    }
                                >
                                    {actionLabel}
                                </Button>
                            </div>
                        )
                    ) : undefined
                }
                success={
                    terminal ? (
                        <WizardSuccessPane
                            title={
                                command.phase === 'committed'
                                    ? 'Work updated'
                                    : 'Command cancelled'
                            }
                            blurb={
                                command.phase === 'committed'
                                    ? 'The original command is confirmed. Its evidence and history are retained.'
                                    : 'No saved work was undone.'
                            }
                            actions={
                                <>
                                    {command.outcome?.status ===
                                        'committed' && (
                                        <Button
                                            onClick={() => {
                                                if (
                                                    command.outcome?.status ===
                                                    'committed'
                                                )
                                                    router.visit(
                                                        command.outcome.url,
                                                    );
                                            }}
                                        >
                                            Open updated work
                                        </Button>
                                    )}
                                    <Button variant="outline" onClick={close}>
                                        Close
                                    </Button>
                                </>
                            }
                        />
                    ) : undefined
                }
            >
                <WizardStepPane>
                    <div className="space-y-5">
                        <div
                            ref={feedback}
                            tabIndex={-1}
                            className="outline-none"
                            role={command.busy ? 'status' : 'alert'}
                        >
                            {command.message && (
                                <p className="text-sm">{command.message}</p>
                            )}
                            {stale && (
                                <div className="space-y-3">
                                    <p>
                                        This work changed while the form was
                                        open. Your entries are retained. Review
                                        the current record before continuing.
                                    </p>
                                    {review.map((fact) => (
                                        <p key={fact.label} className="text-sm">
                                            {fact.label}: {fact.value}
                                        </p>
                                    ))}
                                    <Button
                                        variant="outline"
                                        disabled={!command.canEdit}
                                        onClick={() => {
                                            setExpectedVersion(version);
                                            setStep(0);
                                        }}
                                    >
                                        Use reviewed current version
                                    </Button>
                                </div>
                            )}
                            {Object.entries(errors).map(([key, error]) => (
                                <p
                                    key={key}
                                    className="mt-2 text-sm text-destructive"
                                >
                                    {error}
                                </p>
                            ))}
                            {(command.canRecover || command.busy) && (
                                <div className="mt-3 flex flex-wrap gap-2">
                                    {command.phase === 'session' && (
                                        <Button asChild variant="outline">
                                            <a
                                                href="/login"
                                                target="_blank"
                                                rel="noopener noreferrer"
                                            >
                                                Sign in again
                                            </a>
                                        </Button>
                                    )}
                                    {command.canRecover && (
                                        <Button
                                            variant="outline"
                                            disabled={command.busy}
                                            onClick={() =>
                                                void command.recover()
                                            }
                                        >
                                            Check saved outcome
                                        </Button>
                                    )}
                                    {command.canRetry && (
                                        <Button
                                            variant="outline"
                                            onClick={() => void command.retry()}
                                        >
                                            Retry original command
                                        </Button>
                                    )}
                                    {!command.busy && (
                                        <Button
                                            variant="outline"
                                            onClick={() =>
                                                void command.cancel()
                                            }
                                        >
                                            Cancel unsent command
                                        </Button>
                                    )}
                                    {command.busy && (
                                        <Button
                                            variant="ghost"
                                            onClick={command.stopWaiting}
                                        >
                                            Stop waiting
                                        </Button>
                                    )}
                                </div>
                            )}
                        </div>
                        {hidden ? (
                            <p role="alert">
                                This form is hidden until the original account
                                and its access are confirmed.
                            </p>
                        ) : step === 0 ? (
                            <form
                                ref={form}
                                className="space-y-5"
                                onSubmit={(event) => {
                                    event.preventDefault();
                                    advance(1);
                                }}
                            >
                                <p className="text-sm text-muted-foreground">
                                    {description}
                                </p>
                                <fieldset
                                    className="space-y-5"
                                    disabled={!command.canEdit}
                                >
                                    {fields.map((field) => (
                                        <div
                                            key={field.key}
                                            className="space-y-2"
                                        >
                                            {field.kind === 'agent' ||
                                            field.kind === 'template' ||
                                            field.kind === 'target' ? (
                                                <ProvisioningPicker
                                                    actorId={context.actorId}
                                                    kind={
                                                        field.kind === 'target'
                                                            ? values.canonical_target_type ===
                                                                  'asset_assignment' ||
                                                              values.canonical_target_type ===
                                                                  'device_assignment'
                                                                ? values.canonical_target_type
                                                                : 'identity'
                                                            : field.kind ===
                                                                'agent'
                                                              ? 'agents'
                                                              : 'templates'
                                                    }
                                                    contextKind={
                                                        context.kind ===
                                                        'template'
                                                            ? undefined
                                                            : context.kind ===
                                                                'manual'
                                                              ? 'launch'
                                                              : context.kind
                                                    }
                                                    contextId={context.targetId}
                                                    value={
                                                        typeof values[
                                                            field.key
                                                        ] === 'number'
                                                            ? (values[
                                                                  field.key
                                                              ] as number)
                                                            : null
                                                    }
                                                    label={field.label}
                                                    onChange={(selected) =>
                                                        choose(field, selected)
                                                    }
                                                    onValidated={(selected) => {
                                                        setValidatedChoices(
                                                            (current) => ({
                                                                ...current,
                                                                [field.key]:
                                                                    selected !==
                                                                    null,
                                                            }),
                                                        );
                                                        if (
                                                            field.kind ===
                                                            'template'
                                                        ) {
                                                            setTemplatePreview(
                                                                selected,
                                                            );
                                                            setTemplateLifecycle(
                                                                selected?.lifecycle_type ??
                                                                    null,
                                                            );
                                                        }
                                                    }}
                                                    onDenied={denied}
                                                    disabled={
                                                        !command.canEdit ||
                                                        (field.kind ===
                                                            'target' &&
                                                            !values.canonical_target_type)
                                                    }
                                                />
                                            ) : field.kind === 'select' ? (
                                                <div className="space-y-2">
                                                    <span className="text-sm font-medium">
                                                        {field.label}
                                                        {field.required
                                                            ? ' *'
                                                            : ''}
                                                    </span>
                                                    <Select
                                                        value={String(
                                                            values[field.key] ??
                                                                '',
                                                        )}
                                                        onValueChange={(
                                                            value,
                                                        ) => {
                                                            setValues(
                                                                (current) => ({
                                                                    ...current,
                                                                    [field.key]:
                                                                        field.key ===
                                                                            'canonical_target_type' &&
                                                                        value ===
                                                                            '__manual__'
                                                                            ? null
                                                                            : value,
                                                                    ...(field.key ===
                                                                    'canonical_target_type'
                                                                        ? {
                                                                              canonical_target_id:
                                                                                  null,
                                                                          }
                                                                        : {}),
                                                                }),
                                                            );
                                                            if (
                                                                field.key ===
                                                                'canonical_target_type'
                                                            )
                                                                setLabels(
                                                                    (
                                                                        current,
                                                                    ) => ({
                                                                        ...current,
                                                                        canonical_target_id:
                                                                            '',
                                                                    }),
                                                                );
                                                        }}
                                                    >
                                                        <SelectTrigger
                                                            aria-label={
                                                                field.label
                                                            }
                                                        >
                                                            <SelectValue placeholder="Choose…" />
                                                        </SelectTrigger>
                                                        <SelectContent>
                                                            {field.key ===
                                                                'canonical_target_type' && (
                                                                <SelectItem value="__manual__">
                                                                    Use manual
                                                                    work
                                                                    evidence
                                                                </SelectItem>
                                                            )}
                                                            {field.options?.map(
                                                                (value) => (
                                                                    <SelectItem
                                                                        key={
                                                                            value
                                                                        }
                                                                        value={
                                                                            value
                                                                        }
                                                                    >
                                                                        {provisioningLabel(
                                                                            value,
                                                                        )}
                                                                    </SelectItem>
                                                                ),
                                                            )}
                                                        </SelectContent>
                                                    </Select>
                                                </div>
                                            ) : field.kind === 'checkbox' ? (
                                                <label className="flex items-start gap-3 text-sm">
                                                    <Checkbox
                                                        checked={
                                                            values[
                                                                field.key
                                                            ] === true
                                                        }
                                                        onCheckedChange={(
                                                            checked,
                                                        ) =>
                                                            setValues(
                                                                (current) => ({
                                                                    ...current,
                                                                    [field.key]:
                                                                        checked ===
                                                                        true,
                                                                }),
                                                            )
                                                        }
                                                    />
                                                    {field.label}
                                                </label>
                                            ) : (
                                                <label className="block space-y-2 text-sm font-medium">
                                                    <span>
                                                        {field.label}
                                                        {field.required
                                                            ? ' *'
                                                            : ''}
                                                    </span>
                                                    {field.kind ===
                                                    'textarea' ? (
                                                        <Textarea
                                                            value={String(
                                                                values[
                                                                    field.key
                                                                ] ?? '',
                                                            )}
                                                            required={
                                                                field.required
                                                            }
                                                            maxLength={5000}
                                                            onChange={(event) =>
                                                                setValues(
                                                                    (
                                                                        current,
                                                                    ) => ({
                                                                        ...current,
                                                                        [field.key]:
                                                                            event
                                                                                .target
                                                                                .value,
                                                                    }),
                                                                )
                                                            }
                                                        />
                                                    ) : (
                                                        <Input
                                                            type={
                                                                field.kind ===
                                                                'date'
                                                                    ? 'date'
                                                                    : 'text'
                                                            }
                                                            value={String(
                                                                values[
                                                                    field.key
                                                                ] ?? '',
                                                            )}
                                                            required={
                                                                field.required
                                                            }
                                                            maxLength={255}
                                                            onChange={(event) =>
                                                                setValues(
                                                                    (
                                                                        current,
                                                                    ) => ({
                                                                        ...current,
                                                                        [field.key]:
                                                                            event
                                                                                .target
                                                                                .value,
                                                                    }),
                                                                )
                                                            }
                                                        />
                                                    )}
                                                </label>
                                            )}
                                            {field.help && (
                                                <p className="text-sm text-muted-foreground">
                                                    {field.help}
                                                </p>
                                            )}
                                        </div>
                                    ))}
                                </fieldset>
                            </form>
                        ) : (
                            <ReviewCard
                                icon={ClipboardList}
                                title="Review the change"
                                onEdit={() => advance(0)}
                            >
                                {review.map((fact) => (
                                    <ReviewRow
                                        key={fact.label}
                                        label={fact.label}
                                        value={fact.value}
                                    />
                                ))}
                                {fields.map((field) => (
                                    <ReviewRow
                                        key={field.key}
                                        label={field.label}
                                        value={
                                            labels[field.key] ??
                                            (typeof values[field.key] ===
                                            'boolean'
                                                ? values[field.key]
                                                    ? 'Yes'
                                                    : 'No'
                                                : String(
                                                      values[field.key] ?? '',
                                                  ) || 'Not recorded')
                                        }
                                    />
                                ))}
                                {templatePreview?.tasks && (
                                    <div className="mt-5 space-y-4">
                                        <h3 className="font-semibold">
                                            Published task preview
                                        </h3>
                                        <p className="text-sm text-muted-foreground">
                                            These instructions are retained with
                                            the workflow. Mover workflows
                                            include only tasks triggered by
                                            changed HR details.
                                        </p>
                                        {templatePreview.tasks.map((task) => (
                                            <div
                                                key={task.task_key}
                                                className="space-y-1 border-t pt-3 text-sm"
                                            >
                                                <p className="font-medium">
                                                    Stage {task.stage}:{' '}
                                                    {task.title}
                                                </p>
                                                {task.description && (
                                                    <p>{task.description}</p>
                                                )}
                                                <p>
                                                    {provisioningLabel(
                                                        task.action,
                                                    )}{' '}
                                                    ·{' '}
                                                    {task.approval_required
                                                        ? 'Approval required'
                                                        : 'No approval required'}{' '}
                                                    ·{' '}
                                                    {task.evidence_required
                                                        ? 'Evidence required'
                                                        : 'Evidence optional'}
                                                </p>
                                                <p>
                                                    {task.due_offset_days === 0
                                                        ? 'Due on the effective date'
                                                        : 'Due ' +
                                                          Math.abs(
                                                              task.due_offset_days,
                                                          ) +
                                                          ' days ' +
                                                          (task.due_offset_days <
                                                          0
                                                              ? 'before'
                                                              : 'after') +
                                                          ' the effective date'}
                                                </p>
                                                {task.dependency_task_keys
                                                    .length > 0 && (
                                                    <p>
                                                        After:{' '}
                                                        {task.dependency_task_keys
                                                            .map(
                                                                (key) =>
                                                                    templatePreview.tasks?.find(
                                                                        (
                                                                            candidate,
                                                                        ) =>
                                                                            candidate.task_key ===
                                                                            key,
                                                                    )?.title ??
                                                                    key,
                                                            )
                                                            .join(', ')}
                                                    </p>
                                                )}
                                            </div>
                                        ))}
                                    </div>
                                )}
                                <p className="mt-4 text-sm text-muted-foreground">
                                    {description}
                                </p>
                            </ReviewCard>
                        )}
                    </div>
                </WizardStepPane>
            </WizardShell>
            <ConfirmDialog
                open={discard}
                onClose={() => {
                    pendingNavigation.current = null;
                    setDiscard(false);
                }}
                onConfirm={() => {
                    if (command.busy) command.stopWaiting();
                    allowNavigation.current = true;
                    onClose();
                    pendingNavigation.current?.();
                }}
                title="Close this work?"
                description={
                    command.canRecover
                        ? 'The original command reference is retained for recovery. Private unsaved fields will be lost when this form closes. Check the original outcome before starting another command.'
                        : 'Your unsaved fields will be discarded. Cancel to keep working.'
                }
                confirmText="Close form"
            />
        </>
    );
}
