import {
    DateTimeField,
    localDateTimeLabel,
    validLocalDateTime,
} from '@/components/fleet-assets/maintenance/date-time-field';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { StatusBadge } from '@/components/ui/status-badge';
import { Textarea } from '@/components/ui/textarea';
import { ReviewCard, ReviewRow } from '@/components/wizard/shell';
import {
    Bell,
    CalendarClock,
    FileCheck2,
    Gauge,
    Link2,
    Loader2,
    ShieldAlert,
    UserRound,
    Wrench,
} from 'lucide-react';
import { useEffect, useState } from 'react';
import type {
    AlertDetail,
    AlertItem,
    AlertPlan,
    AssessmentOutcome,
    TriageDecision,
} from './alerts-types';
import { CataloguePicker, PersonPicker } from './choice-picker';
import {
    ACTION_LABELS,
    availableActions,
    deadlineText,
    DECISIONS,
    duplicatesText,
    noticeFor,
    OUTCOMES,
    PLAN_PRESETS,
    planFormFrom,
    planStepError,
    statusLabel,
    statusVariant,
    type PlanForm,
} from './map-alerts-model';
import { ReviewEventWizard } from './map-driving-dialogs';
import type { DrivingReviews } from './map-driving-types';
import { useNow, useWorkspaceJson } from './map-insights-data';
import { InsightModal, ModalRow, whenLabel } from './map-insights-kit';
import { WITHHELD_LABEL } from './map-model';
import { isJsonObject, useVehicleRecordCommand } from './record-command';
import { VehicleSearchSelect } from './search-select';
import type { VehicleWorkspace } from './types';
import {
    fieldProps,
    StudioNotice,
    WizardField,
    WizardSuccess,
    WorkspaceWizard,
} from './wizard-kit';
import { todayInAuckland } from './workspace-model';

export type Lifecycle = 'acknowledge' | 'triage' | 'escalate' | 'resolve';

function vehicleLine(workspace: VehicleWorkspace) {
    const vehicle = workspace.vehicle;
    return {
        name: vehicle.name,
        registration: vehicle.registration_number ?? vehicle.asset_tag ?? '',
    };
}

export type DetailActions = {
    onLifecycle: (action: Lifecycle, detail: AlertDetail) => void;
    onCreateWork: (detail: AlertDetail) => void;
    onLinkWork: (detail: AlertDetail) => void;
    onFollowUp: (detail: AlertDetail) => void;
    onReviewSource: (detail: AlertDetail) => void;
    onOpenTrip: (date: string | null) => void;
    onOpenWork: (workOrderId: number) => void;
};

/** The design's Control Room response modal for one of the vehicle's alerts. */
export function AlertDetailDialog({
    workspace,
    alertId,
    onClose,
    actions,
}: {
    workspace: VehicleWorkspace;
    alertId: number;
    onClose: () => void;
    actions: DetailActions;
}) {
    const { data, load, reload } = useWorkspaceJson<AlertDetail>(
        `/fleet-assets/vehicles/${workspace.vehicle.id}/alerts/${alertId}`,
    );
    const now = useNow();
    const { registration } = vehicleLine(workspace);

    if (!data)
        return (
            <InsightModal
                title="Control Room response"
                description={registration}
                icon={ShieldAlert}
                onClose={onClose}
                footer={
                    <>
                        {load === 'error' && (
                            <Button variant="outline" onClick={reload}>
                                Try again
                            </Button>
                        )}
                        <Button variant="outline" onClick={onClose}>
                            Back to vehicle alerts
                        </Button>
                    </>
                }
            >
                <p className="vehicle-insight-text">
                    {load === 'unavailable'
                        ? 'This response is not available to you any more.'
                        : load === 'error'
                          ? 'The response could not be loaded.'
                          : 'Loading the response…'}
                </p>
            </InsightModal>
        );

    const notice = noticeFor(data.kind_key);
    const lifecycle = availableActions(data.status).filter(
        (action): action is Lifecycle => action !== 'retry',
    );
    const openWork = workspace.work.can_view ? workspace.work.open : [];

    return (
        <InsightModal
            title={data.kind}
            description={`${data.reference} · Control Room · ${registration || data.vehicle}`}
            icon={ShieldAlert}
            onClose={onClose}
            className="vehicle-alert-dialog"
            footer={
                <Button variant="outline" onClick={onClose}>
                    Back to vehicle alerts
                </Button>
            }
        >
            <div className="control-room-summary">
                <StatusBadge variant={statusVariant(data.status)}>
                    {statusLabel(data)}
                </StatusBadge>
                <strong>
                    {data.priority} · {data.owner ?? 'Unassigned'}
                </strong>
            </div>
            <div>
                <ModalRow
                    label="Observation"
                    value={whenLabel(data.observed_at)}
                />
                <ModalRow
                    label="Response deadline"
                    value={deadlineText(data, now)}
                />
                {data.work && (
                    <ModalRow
                        label="Maintenance outcome"
                        value={`${data.work.reference} · ${data.work.status.replace(/_/g, ' ')} · review independently before resolving alert`}
                    />
                )}
                <ModalRow
                    label="Vehicle & device"
                    value={`${data.vehicle} · ${data.device ?? 'tracker details need device access'}`}
                />
                <ModalRow
                    label="Recorded location"
                    value={
                        data.location
                            ? `${data.location.lat.toFixed(5)}, ${data.location.lng.toFixed(5)} · ${data.location.basis === 'recorded_event' ? 'where the event was recorded' : 'vehicle position when received'}`
                            : data.location_withheld
                              ? WITHHELD_LABEL[data.location_withheld]
                              : 'No recorded position with this signal'
                    }
                />
                <ModalRow
                    label="Current driver evidence"
                    value={data.driver.label}
                />
                <ModalRow
                    label="Source record"
                    value={`${data.source.label}${data.source.sent_by ? ` · sent by ${data.source.sent_by}` : ''}`}
                />
                <ModalRow
                    label="Signal correlation"
                    value={`${duplicatesText(data.correlation.duplicates)}${data.correlation.signal_id ? ` · vehicle signal #${data.correlation.signal_id}` : ''}`}
                />
                <ModalRow
                    label="Fault / event evidence"
                    value={data.evidence}
                />
                <ModalRow
                    label="Triage decision"
                    value={data.decision?.label ?? 'Awaiting assessment'}
                />
                {data.resolution && (
                    <ModalRow
                        label="Assessment outcome"
                        value={data.resolution}
                    />
                )}
                {data.follow_ups.map((reminder) => (
                    <ModalRow
                        key={reminder.id}
                        label="Follow-up reminder"
                        value={`${reminder.title} · ${reminder.owner ?? 'Owner not shown'} · ${whenLabel(reminder.due_at)} · ${reminder.state}`}
                    />
                ))}
            </div>
            <StudioNotice title={notice.title}>{notice.body}</StudioNotice>
            <div className="alert-actions">
                {data.review_source && (
                    <Button
                        variant="outline"
                        disabled={!data.can.review_source}
                        onClick={() => actions.onReviewSource(data)}
                    >
                        Review source event & score
                    </Button>
                )}
                {data.source.trip && (
                    <Button
                        variant="outline"
                        onClick={() =>
                            actions.onOpenTrip(
                                data.source.trip?.local_date ?? null,
                            )
                        }
                    >
                        Open source trip
                    </Button>
                )}
                {lifecycle.map((action) => (
                    <Button
                        key={action}
                        disabled={!data.can[action]}
                        variant={action === 'resolve' ? 'outline' : 'default'}
                        onClick={() => actions.onLifecycle(action, data)}
                    >
                        {ACTION_LABELS[action]}
                    </Button>
                ))}
                {data.work ? (
                    <Button
                        variant="outline"
                        disabled={!data.can.open_work}
                        onClick={() => actions.onOpenWork(data.work!.id)}
                    >
                        Open {data.work.reference}
                    </Button>
                ) : (
                    <>
                        <Button
                            variant="outline"
                            disabled={!data.can.maintenance_create}
                            onClick={() => actions.onCreateWork(data)}
                        >
                            Create Maintenance assessment
                        </Button>
                        <Button
                            variant="outline"
                            disabled={
                                !data.can.maintenance_link || !openWork.length
                            }
                            onClick={() => actions.onLinkWork(data)}
                        >
                            Link existing work
                        </Button>
                    </>
                )}
                <Button
                    variant="ghost"
                    disabled={!data.can.follow_up || !workspace.people.length}
                    onClick={() => actions.onFollowUp(data)}
                >
                    Add follow-up reminder
                </Button>
            </div>
            {!data.decision &&
                !data.work &&
                (data.can.maintenance_create ||
                    data.can.maintenance_link ||
                    data.can.triage) && (
                    <p className="vehicle-insight-text text-caption">
                        Maintenance can be created or linked once the triage
                        decision is “Maintenance assessment required”.
                    </p>
                )}
            <h3 className="vehicle-insight-heading">Response history</h3>
            <ol className="alert-history">
                {data.history.map((entry, index) => (
                    <li key={`${entry.at}-${index}`}>
                        {whenLabel(entry.at)} · {entry.text}
                    </li>
                ))}
            </ol>
        </InsightModal>
    );
}

/** A vehicle signal Control Room has not received, with its retry. */
export function DeliveryFailureDialog({
    workspace,
    item,
    canRetry,
    onClose,
    onSaved,
}: {
    workspace: VehicleWorkspace;
    item: AlertItem;
    canRetry: boolean;
    onClose: () => void;
    onSaved: () => void;
}) {
    const command = useVehicleRecordCommand(isJsonObject);
    const [result, setResult] = useState('');
    const [delivery, setDelivery] = useState(item.delivery);
    const pending = delivery === 'pending' || delivery === 'processing';
    const received = delivery === 'sent';
    const retry = async () => {
        const response = await command.submit(
            `/fleet-assets/vehicles/${workspace.vehicle.id}/alerts/signals/${item.signal_id}/retry`,
            { expected_attempts: item.attempts ?? 0 },
        );
        if (response) {
            setDelivery(
                typeof response.delivery === 'string'
                    ? response.delivery
                    : item.delivery,
            );
            setResult(
                typeof response.message === 'string'
                    ? response.message
                    : 'Delivery retried.',
            );
            onSaved();
        }
    };

    return (
        <InsightModal
            title={item.kind}
            description={`${item.reference} · ${received ? 'Control Room delivery received' : pending ? 'Waiting for Control Room receipt' : 'Control Room delivery failed'}`}
            icon={ShieldAlert}
            size="detail"
            onClose={onClose}
            className="vehicle-alert-dialog"
            footer={
                <>
                    <Button variant="outline" onClick={onClose}>
                        Back to vehicle alerts
                    </Button>
                    {!result && !pending && !received && (
                        <Button
                            disabled={
                                !canRetry ||
                                command.processing ||
                                command.requiresReload
                            }
                            onClick={retry}
                        >
                            {command.processing && (
                                <Loader2 className="size-4 animate-spin" />
                            )}
                            {command.uncertain
                                ? 'Retry again'
                                : 'Retry delivery'}
                        </Button>
                    )}
                </>
            }
        >
            <div className="control-room-summary">
                <StatusBadge
                    variant={
                        received ? 'success' : pending ? 'info' : 'critical'
                    }
                >
                    {received
                        ? 'Received'
                        : pending
                          ? 'Waiting for Control Room'
                          : 'Delivery failed'}
                </StatusBadge>
                <strong>
                    {item.attempts ?? 0} delivery{' '}
                    {item.attempts === 1 ? 'attempt' : 'attempts'}
                </strong>
            </div>
            <div>
                <ModalRow
                    label="Observation"
                    value={whenLabel(item.observed_at)}
                />
                <ModalRow
                    label="Delivery"
                    value={
                        received
                            ? 'Control Room confirmed delivery. Return to the queue for its response record.'
                            : pending
                              ? 'Queued for processing. Receipt has not yet been confirmed.'
                              : delivery === 'unroutable'
                                ? 'Control Room could not route it: its site or signal source needs attention'
                                : 'Control Room did not confirm receipt'
                    }
                />
                <ModalRow label="Source record" value={item.reference} />
            </div>
            <StudioNotice
                title={
                    result ||
                    (command.message
                        ? command.message
                        : 'The original signal is kept')
                }
                tone={result ? 'info' : command.message ? 'warning' : 'info'}
            >
                {pending
                    ? 'The delivery worker must process this signal before Control Room can respond. It stays in the vehicle queue until delivery succeeds.'
                    : 'Retrying sends the same signal identity again, so Control Room still opens one response for it.'}
            </StudioNotice>
            {!canRetry && !pending && !received && (
                <p className="vehicle-insight-text text-caption">
                    Retrying a delivery needs Control Room alert management
                    access.
                </p>
            )}
        </InsightModal>
    );
}

const ACTION_STEPS = [
    {
        key: 'assessment',
        label: 'Assessment & responsibility',
        blurb: 'Owner, decision and notes',
        icon: ShieldAlert,
    },
    {
        key: 'review',
        label: 'Review',
        blurb: 'Confirm the Control Room update',
        icon: FileCheck2,
    },
];

/** Acknowledge, triage, escalate or resolve through Control Room's lifecycle. */
export function AlertActionWizard({
    workspace,
    detail,
    action,
    onClose,
    onSaved,
}: {
    workspace: VehicleWorkspace;
    detail: AlertDetail;
    action: Lifecycle;
    onClose: () => void;
    onSaved: () => void;
}) {
    const [initial] = useState(() => ({
        decision: (detail.decision?.key ?? '') as TriageDecision | '',
        outcome: '' as AssessmentOutcome | '',
        note: '',
    }));
    const [form, setForm] = useState(initial);
    const [step, setStep] = useState(0);
    const [local, setLocal] = useState<Record<string, string>>({});
    const [saved, setSaved] = useState('');
    const command = useVehicleRecordCommand(isJsonObject);
    const server = command.errors;
    const errors = {
        decision: local.decision ?? server.decision,
        outcome: local.outcome ?? server.outcome,
        note: local.note ?? server.note,
    };
    const { name, registration } = vehicleLine(workspace);
    useEffect(() => {
        if (Object.keys(server).length) setStep(0);
    }, [server]);
    const update = <K extends keyof typeof form>(
        key: K,
        value: (typeof form)[K],
    ) => {
        setForm((old) => ({ ...old, [key]: value }));
        setLocal((old) => {
            const next = { ...old };
            delete next[key as string];
            return next;
        });
        command.clearError(key as string);
    };
    const validate = () => {
        const found: Record<string, string> = {};
        if (action === 'triage' && !form.decision)
            found.decision = 'Choose the triage decision.';
        if (action === 'resolve' && !form.outcome)
            found.outcome = 'Choose the assessment outcome.';
        if (!form.note.trim())
            found.note =
                action === 'resolve'
                    ? 'Record the assessment and follow-up notes.'
                    : action === 'escalate'
                      ? 'Record why this response is escalated.'
                      : 'Record the action notes.';
        setLocal(found);
        return Object.keys(found).length === 0;
    };
    const submit = async () => {
        if (!command.uncertain && !validate()) {
            setStep(0);
            return;
        }
        const result = await command.submit(
            `/fleet-assets/vehicles/${workspace.vehicle.id}/alerts/${detail.id}/${action}`,
            {
                note: form.note.trim(),
                decision: action === 'triage' ? form.decision : null,
                outcome: action === 'resolve' ? form.outcome : null,
                expected_version: detail.version,
            },
        );
        if (result) {
            setSaved(
                typeof result.message === 'string'
                    ? result.message
                    : 'Control Room updated.',
            );
            onSaved();
        }
    };
    const decision = DECISIONS.find((item) => item.value === form.decision);
    const outcome = OUTCOMES.find((item) => item.value === form.outcome);
    const noteLabel =
        action === 'resolve'
            ? 'Assessment and follow-up notes'
            : action === 'escalate'
              ? 'Reason for escalation'
              : 'Action notes';

    return (
        <WorkspaceWizard
            title={`${ACTION_LABELS[action]} · ${detail.reference}`}
            description={
                action === 'resolve'
                    ? 'Record the human assessment. This does not release the vehicle, close Maintenance work or contact emergency services.'
                    : `${detail.kind} · ${whenLabel(detail.observed_at)} · ${registration || name}.`
            }
            railIcon={ShieldAlert}
            railSub={detail.reference}
            steps={ACTION_STEPS}
            step={step}
            setStep={setStep}
            pct={Math.round(
                ([
                    action !== 'triage' || !!form.decision,
                    action !== 'resolve' || !!form.outcome,
                    !!form.note.trim(),
                ].filter(Boolean).length /
                    3) *
                    100,
            )}
            context={{
                name: detail.kind,
                detail: `${detail.reference} · ${statusLabel(detail)}`,
            }}
            command={command}
            dirty={JSON.stringify(form) !== JSON.stringify(initial)}
            saved={saved !== ''}
            submitLabel={ACTION_LABELS[action]}
            onValidateStep={(at) => (at === 0 ? validate() : true)}
            onSubmit={submit}
            onClose={onClose}
            onReload={() => {
                onSaved();
                onClose();
            }}
            errorKey={JSON.stringify(errors)}
            success={
                <WizardSuccess
                    title={`${ACTION_LABELS[action]} recorded`}
                    blurb={saved}
                    onClose={onClose}
                />
            }
        >
            {step === 0 && (
                <div className="space-y-5">
                    <div className="rounded-lg border bg-muted/40 p-4 text-sm">
                        <p className="text-caption font-semibold tracking-wide uppercase">
                            Responsible person
                        </p>
                        <p className="mt-1">
                            {detail.owner ?? 'Unassigned'} · the response owner
                            is assigned in Control Room.
                        </p>
                    </div>
                    {action === 'triage' && (
                        <WizardField
                            id="alert-decision"
                            label="Triage decision"
                            error={errors.decision}
                            hint={decision?.detail}
                        >
                            <VehicleSearchSelect
                                id="alert-decision"
                                label="Triage decision"
                                value={form.decision}
                                options={DECISIONS.map((item) => ({
                                    value: item.value,
                                    label: item.label,
                                    description: item.detail,
                                }))}
                                onChange={(value) =>
                                    update('decision', value as TriageDecision)
                                }
                                invalid={!!errors.decision}
                                describedBy={
                                    errors.decision
                                        ? 'alert-decision-error'
                                        : undefined
                                }
                            />
                        </WizardField>
                    )}
                    {action === 'resolve' && (
                        <WizardField
                            id="alert-outcome"
                            label="Assessment outcome"
                            error={errors.outcome}
                        >
                            <VehicleSearchSelect
                                id="alert-outcome"
                                label="Assessment outcome"
                                value={form.outcome}
                                options={OUTCOMES.map((item) => ({
                                    value: item.value,
                                    label: item.label,
                                    description: item.detail,
                                }))}
                                onChange={(value) =>
                                    update(
                                        'outcome',
                                        value as AssessmentOutcome,
                                    )
                                }
                                invalid={!!errors.outcome}
                                describedBy={
                                    errors.outcome
                                        ? 'alert-outcome-error'
                                        : undefined
                                }
                            />
                        </WizardField>
                    )}
                    <WizardField
                        id="alert-note"
                        label={noteLabel}
                        error={errors.note}
                    >
                        <Textarea
                            {...fieldProps('alert-note', errors.note)}
                            rows={4}
                            maxLength={2000}
                            value={form.note}
                            onChange={(change) =>
                                update('note', change.target.value)
                            }
                        />
                    </WizardField>
                </div>
            )}
            {step === 1 && (
                <ReviewCard
                    icon={ShieldAlert}
                    title="Control Room update"
                    onEdit={() => setStep(0)}
                >
                    <ReviewRow
                        label="Response"
                        value={`${detail.reference} · ${detail.kind}`}
                    />
                    <ReviewRow label="Action" value={ACTION_LABELS[action]} />
                    {action === 'triage' && (
                        <ReviewRow label="Decision" value={decision?.label} />
                    )}
                    {action === 'resolve' && (
                        <ReviewRow label="Outcome" value={outcome?.label} />
                    )}
                    <ReviewRow
                        label="Notes"
                        value={form.note.trim() || undefined}
                    />
                    <ReviewRow
                        label="Owner"
                        value={detail.owner ?? 'Unassigned'}
                    />
                </ReviewCard>
            )}
        </WorkspaceWizard>
    );
}

const LINK_STEPS = [
    {
        key: 'work',
        label: 'Existing work',
        blurb: 'Keep one job for the response',
        icon: Link2,
    },
    {
        key: 'review',
        label: 'Review',
        blurb: 'Confirm the link',
        icon: FileCheck2,
    },
];

/** Link the response to open Maintenance work instead of creating a duplicate. */
export function LinkWorkWizard({
    workspace,
    detail,
    onClose,
    onSaved,
}: {
    workspace: VehicleWorkspace;
    detail: AlertDetail;
    onClose: () => void;
    onSaved: () => void;
}) {
    const open = workspace.work.open;
    const [initial] = useState(() => ({ work: '', reason: '' }));
    const [form, setForm] = useState(initial);
    const [step, setStep] = useState(0);
    const [local, setLocal] = useState<Record<string, string>>({});
    const [saved, setSaved] = useState(false);
    const command = useVehicleRecordCommand(isJsonObject);
    const errors = {
        work: local.work ?? command.errors.work_order_id ?? command.errors.mode,
        reason: local.reason ?? command.errors.reason,
    };
    useEffect(() => {
        if (Object.keys(command.errors).length) setStep(0);
    }, [command.errors]);
    const validate = () => {
        const found: Record<string, string> = {};
        if (!form.work) found.work = 'Choose the open work record.';
        if (!form.reason.trim())
            found.reason = 'Record why this work covers the response.';
        setLocal(found);
        return Object.keys(found).length === 0;
    };
    const submit = async () => {
        if (!command.uncertain && !validate()) {
            setStep(0);
            return;
        }
        const result = await command.submit(
            `/fleet-assets/vehicles/${workspace.vehicle.id}/alerts/${detail.id}/maintenance`,
            {
                mode: 'link',
                work_order_id: Number(form.work),
                reason: form.reason.trim(),
                expected_version: detail.version,
            },
        );
        if (result) {
            setSaved(true);
            onSaved();
        }
    };
    const chosen = open.find((row) => String(row.id) === form.work);

    return (
        <WorkspaceWizard
            title="Link existing Maintenance work"
            description={`${detail.reference}: preserve the Control Room source and avoid duplicate work.`}
            railIcon={Wrench}
            railSub={detail.reference}
            steps={LINK_STEPS}
            step={step}
            setStep={setStep}
            pct={Math.round(
                ([!!form.work, !!form.reason.trim()].filter(Boolean).length /
                    2) *
                    100,
            )}
            context={{ name: detail.kind, detail: detail.reference }}
            command={command}
            dirty={JSON.stringify(form) !== JSON.stringify(initial)}
            saved={saved}
            submitLabel="Link work record"
            onValidateStep={(at) => (at === 0 ? validate() : true)}
            onSubmit={submit}
            onClose={onClose}
            onReload={() => {
                onSaved();
                onClose();
            }}
            errorKey={JSON.stringify(errors)}
            success={
                <WizardSuccess
                    title="Existing work linked"
                    blurb={`${chosen?.reference ?? 'The work'} now carries ${detail.reference} as a source. The response and the work keep their own outcomes.`}
                    onClose={onClose}
                />
            }
        >
            {step === 0 && (
                <div className="space-y-5">
                    <WizardField
                        id="link-work"
                        label="Open work record"
                        error={errors.work}
                    >
                        <VehicleSearchSelect
                            id="link-work"
                            label="Open work record"
                            value={form.work}
                            options={open.map((row) => ({
                                value: String(row.id),
                                label:
                                    [row.reference, row.title]
                                        .filter(Boolean)
                                        .join(' · ') || `Work #${row.id}`,
                                description: row.status.replace(/_/g, ' '),
                            }))}
                            onChange={(value) => {
                                setForm((old) => ({ ...old, work: value }));
                                setLocal((old) => ({ ...old, work: '' }));
                                command.clearError('work_order_id');
                            }}
                            invalid={!!errors.work}
                        />
                    </WizardField>
                    <WizardField
                        id="link-reason"
                        label="Why this work covers the alert"
                        error={errors.reason}
                    >
                        <Textarea
                            {...fieldProps('link-reason', errors.reason)}
                            rows={4}
                            maxLength={2000}
                            value={form.reason}
                            onChange={(change) => {
                                setForm((old) => ({
                                    ...old,
                                    reason: change.target.value,
                                }));
                                setLocal((old) => ({ ...old, reason: '' }));
                            }}
                        />
                    </WizardField>
                </div>
            )}
            {step === 1 && (
                <ReviewCard icon={Link2} title="Link" onEdit={() => setStep(0)}>
                    <ReviewRow
                        label="Response"
                        value={`${detail.reference} · ${detail.kind}`}
                    />
                    <ReviewRow
                        label="Work"
                        value={
                            chosen
                                ? [chosen.reference, chosen.title]
                                      .filter(Boolean)
                                      .join(' · ')
                                : undefined
                        }
                    />
                    <ReviewRow
                        label="Reason"
                        value={form.reason.trim() || undefined}
                    />
                </ReviewCard>
            )}
        </WorkspaceWizard>
    );
}

const FOLLOW_STEPS = [
    {
        key: 'source',
        label: 'Reminder & source',
        blurb: 'Title, source and action',
        icon: Bell,
    },
    {
        key: 'timing',
        label: 'Timing & responsibility',
        blurb: 'When and who',
        icon: CalendarClock,
    },
    {
        key: 'review',
        label: 'Review',
        blurb: 'Create the reminder',
        icon: FileCheck2,
    },
];

function tomorrowNine(): string {
    const date = new Date(`${todayInAuckland()}T12:00:00Z`);
    date.setUTCDate(date.getUTCDate() + 1);
    return `${date.toISOString().slice(0, 10)}T09:00`;
}

/** A vehicle reminder linked to the response; it never reserves the vehicle or resolves the response. */
export function AlertFollowUpWizard({
    workspace,
    detail,
    onClose,
    onSaved,
}: {
    workspace: VehicleWorkspace;
    detail: AlertDetail;
    onClose: () => void;
    onSaved: () => void;
}) {
    const [initial] = useState(() => ({
        title: '',
        action_text: '',
        remind_local: tomorrowNine(),
        owner_user_id: workspace.vehicle.responsible?.id ?? null,
        backup_user_id: null as number | null,
        repeat_months: '0',
    }));
    const [form, setForm] = useState(initial);
    const [step, setStep] = useState(0);
    const [local, setLocal] = useState<Record<string, string>>({});
    const [saved, setSaved] = useState(false);
    const command = useVehicleRecordCommand(isJsonObject);
    const errors = { ...command.errors, ...local };
    useEffect(() => {
        const field = Object.keys(command.errors)[0];
        if (field) setStep(['title', 'action_text'].includes(field) ? 0 : 1);
    }, [command.errors]);
    const update = <K extends keyof typeof form>(
        key: K,
        value: (typeof form)[K],
    ) => {
        setForm((old) => ({ ...old, [key]: value }));
        setLocal((old) => {
            const next = { ...old };
            delete next[key as string];
            return next;
        });
        command.clearError(key as string);
    };
    const validateStep = (at: number) => {
        const found: Record<string, string> = {};
        if (at === 0) {
            if (!form.title.trim()) found.title = 'Choose the reminder title.';
            if (!form.action_text.trim())
                found.action_text = 'Record the action to take.';
        }
        if (at === 1) {
            if (!validLocalDateTime(form.remind_local))
                found.remind_local = 'Choose when to remind.';
            if (!form.owner_user_id) found.owner_user_id = 'Choose the owner.';
            if (
                form.backup_user_id &&
                form.backup_user_id === form.owner_user_id
            )
                found.backup_user_id =
                    'Choose a different person as the backup owner.';
        }
        setLocal(found);
        return Object.keys(found).length === 0;
    };
    const submit = async () => {
        if (!command.uncertain) {
            for (const at of [0, 1]) {
                if (!validateStep(at)) {
                    setStep(at);
                    return;
                }
            }
        }
        const result = await command.submit(
            `/fleet-assets/vehicles/${workspace.vehicle.id}/alerts/${detail.id}/follow-up`,
            {
                title: form.title.trim(),
                action_text: form.action_text.trim(),
                remind_local: form.remind_local,
                owner_user_id: form.owner_user_id,
                backup_user_id: form.backup_user_id,
                repeat_months: Number(form.repeat_months || 0),
            },
        );
        if (result) {
            setSaved(true);
            onSaved();
        }
    };
    const owner = workspace.people.find(
        (person) => person.id === form.owner_user_id,
    );
    const backup = workspace.people.find(
        (person) => person.id === form.backup_user_id,
    );
    const source = `Control Room response · ${detail.reference} · ${detail.kind}`;

    return (
        <WorkspaceWizard
            title="Add vehicle reminder"
            description={`${workspace.vehicle.name}: a reminder is a prompt for its owner; it never reserves the vehicle or resolves the response.`}
            railIcon={Bell}
            railSub={detail.reference}
            steps={FOLLOW_STEPS}
            step={step}
            setStep={setStep}
            pct={Math.round(
                ([
                    !!form.title,
                    !!form.action_text.trim(),
                    !!form.remind_local,
                    !!form.owner_user_id,
                ].filter(Boolean).length /
                    4) *
                    100,
            )}
            context={{ name: workspace.vehicle.name, detail: source }}
            command={command}
            dirty={JSON.stringify(form) !== JSON.stringify(initial)}
            saved={saved}
            submitLabel="Create reminder"
            onValidateStep={validateStep}
            onSubmit={submit}
            onClose={onClose}
            onReload={onClose}
            errorKey={JSON.stringify(errors)}
            success={
                <WizardSuccess
                    title="Reminder created"
                    blurb="It shows on this vehicle, in All Tasks for its owner and on the site calendar, and in this response's history."
                    onClose={onClose}
                />
            }
        >
            {step === 0 && (
                <div className="space-y-5">
                    <p className="text-subtle">
                        Link the follow-up to the Control Room response. A
                        reminder never reserves the vehicle.
                    </p>
                    <WizardField
                        id="follow-title"
                        label="Reminder title"
                        error={errors.title}
                    >
                        <CataloguePicker
                            id="follow-title"
                            kind="reminder_title"
                            label="Reminder title"
                            value={form.title}
                            onChange={(value) => update('title', value)}
                            invalid={!!errors.title}
                        />
                    </WizardField>
                    <WizardField
                        id="follow-source"
                        label="Linked source"
                        hint="Locked to this response."
                    >
                        <Input
                            id="follow-source"
                            value={source}
                            readOnly
                            aria-readonly
                        />
                    </WizardField>
                    <WizardField
                        id="follow-action"
                        label="Action to take"
                        error={errors.action_text}
                    >
                        <Textarea
                            {...fieldProps('follow-action', errors.action_text)}
                            rows={4}
                            maxLength={1800}
                            value={form.action_text}
                            onChange={(change) =>
                                update('action_text', change.target.value)
                            }
                        />
                    </WizardField>
                </div>
            )}
            {step === 1 && (
                <div className="space-y-5">
                    <p className="text-subtle">
                        Pacific/Auckland · an in-app task for its owner.
                    </p>
                    <DateTimeField
                        id="follow-at"
                        label="Remind at"
                        value={form.remind_local}
                        onChange={(value) => update('remind_local', value)}
                        error={errors.remind_local}
                    />
                    <div className="vehicle-wizard-fields">
                        <WizardField
                            id="follow-owner"
                            label="Responsible person"
                            error={errors.owner_user_id}
                            hint="Current staff at this vehicle's site."
                        >
                            <PersonPicker
                                id="follow-owner"
                                label="Responsible person"
                                value={form.owner_user_id}
                                people={workspace.people}
                                onChange={(value) =>
                                    update('owner_user_id', value)
                                }
                                invalid={!!errors.owner_user_id}
                            />
                        </WizardField>
                        <WizardField
                            id="follow-backup"
                            label="Backup owner"
                            optional
                            error={errors.backup_user_id}
                        >
                            <PersonPicker
                                id="follow-backup"
                                label="Backup owner"
                                value={form.backup_user_id}
                                people={workspace.people}
                                onChange={(value) =>
                                    update('backup_user_id', value)
                                }
                                invalid={!!errors.backup_user_id}
                            />
                        </WizardField>
                    </div>
                    <WizardField
                        id="follow-repeat"
                        label="Repeat every (months)"
                        optional
                        hint="One-off unless a repeat is chosen."
                        error={errors.repeat_months}
                    >
                        <CataloguePicker
                            id="follow-repeat"
                            kind="repeat_months"
                            label="Repeat every (months)"
                            value={form.repeat_months}
                            onChange={(value) => update('repeat_months', value)}
                        />
                    </WizardField>
                </div>
            )}
            {step === 2 && (
                <ReviewCard
                    icon={Bell}
                    title="Follow-up reminder"
                    onEdit={() => setStep(0)}
                >
                    <ReviewRow label="Title" value={form.title || undefined} />
                    <ReviewRow label="Linked source" value={source} />
                    <ReviewRow
                        label="Action"
                        value={form.action_text.trim() || undefined}
                    />
                    <ReviewRow
                        label="Remind at"
                        value={localDateTimeLabel(form.remind_local)}
                    />
                    <ReviewRow label="Owner" value={owner?.name} />
                    <ReviewRow label="Backup" value={backup?.name ?? 'None'} />
                </ReviewCard>
            )}
        </WorkspaceWizard>
    );
}

const PLAN_STEPS = [
    {
        key: 'responsibility',
        label: 'Responsibility',
        blurb: 'Primary and backup owners',
        icon: UserRound,
    },
    {
        key: 'overspeed',
        label: 'Overspeed & Control Room',
        blurb: 'Threshold, tolerance and episodes',
        icon: Gauge,
    },
    {
        key: 'thresholds',
        label: 'Thresholds & review',
        blurb: 'Reporting and voltage',
        icon: ShieldAlert,
    },
    {
        key: 'review',
        label: 'Review',
        blurb: 'Save the draft',
        icon: FileCheck2,
    },
];

type NumericKey = Exclude<
    keyof PlanForm,
    'owner_user_id' | 'backup_user_id' | 'notes'
>;

function PresetNumber({
    id,
    label,
    unit,
    value,
    presets,
    onChange,
    invalid,
}: {
    id: string;
    label: string;
    unit: string;
    value: string;
    presets: string[];
    onChange: (value: string) => void;
    invalid?: boolean;
}) {
    const options = [
        ...presets,
        ...(value && !presets.includes(value) ? [value] : []),
    ];
    return (
        <VehicleSearchSelect
            id={id}
            label={label}
            value={value}
            options={options.map((option) => ({
                value: option,
                label: `${option} ${unit}`,
            }))}
            onChange={onChange}
            onAdd={(proposed) => {
                const next = proposed.replace(/[^\d]/g, '');
                if (next) onChange(next);
            }}
            invalid={invalid}
        />
    );
}

/** The vehicle's draft response plan; saving adds a version and activates nothing. */
export function ResponsePlanWizard({
    workspace,
    plan,
    onClose,
    onSaved,
}: {
    workspace: VehicleWorkspace;
    plan: AlertPlan | null;
    onClose: () => void;
    onSaved: () => void;
}) {
    const [initial] = useState(() => ({ ...planFormFrom(plan), reason: '' }));
    const [form, setForm] = useState<PlanForm & { reason: string }>(initial);
    const [step, setStep] = useState(0);
    const [error, setError] = useState('');
    const [saved, setSaved] = useState(false);
    const command = useVehicleRecordCommand(isJsonObject);
    const server = command.errors;
    useEffect(() => {
        const keys = Object.keys(server);
        if (!keys.length) return;
        setStep(
            keys.some((key) =>
                ['owner_user_id', 'backup_user_id'].includes(key),
            )
                ? 0
                : keys.some((key) => key.startsWith('speed_'))
                  ? 1
                  : 2,
        );
    }, [server]);
    const update = <K extends keyof typeof form>(
        key: K,
        value: (typeof form)[K],
    ) => {
        setForm((old) => ({ ...old, [key]: value }));
        setError('');
        command.clearError(key as string);
    };
    const validateStep = (at: number) => {
        const message =
            planStepError(form, at) ||
            (at === 2 && plan && !form.reason.trim()
                ? 'Record why the draft plan changed.'
                : '');
        setError(message);
        return !message;
    };
    const submit = async () => {
        if (!command.uncertain) {
            for (const at of [0, 1, 2]) {
                if (!validateStep(at)) {
                    setStep(at);
                    return;
                }
            }
        }
        const result = await command.submit(
            `/fleet-assets/vehicles/${workspace.vehicle.id}/alert-plan`,
            {
                owner_user_id: form.owner_user_id,
                backup_user_id: form.backup_user_id,
                speed_threshold_kph: Number(form.speed_threshold_kph),
                speed_tolerance_kph: Number(form.speed_tolerance_kph),
                speed_duration_s: Number(form.speed_duration_s),
                speed_cooldown_s: Number(form.speed_cooldown_s),
                offline_minutes: Number(form.offline_minutes),
                low_voltage_v: Number(form.low_voltage_v),
                low_voltage_minutes: Number(form.low_voltage_minutes),
                notes: form.notes.trim(),
                reason: form.reason.trim() || null,
                expected_version: plan?.version ?? 0,
            },
            { method: 'PUT' },
        );
        if (result) {
            setSaved(true);
            onSaved();
        }
    };
    const owner = workspace.people.find(
        (person) => person.id === form.owner_user_id,
    );
    const backup = workspace.people.find(
        (person) => person.id === form.backup_user_id,
    );
    const numeric = (
        key: NumericKey,
        label: string,
        unit: string,
        presets?: string[],
    ) => (
        <WizardField
            key={key}
            id={`plan-${key}`}
            label={label}
            error={server[key]}
        >
            {presets ? (
                <PresetNumber
                    id={`plan-${key}`}
                    label={label}
                    unit={unit}
                    value={form[key]}
                    presets={presets}
                    onChange={(value) => update(key, value)}
                    invalid={!!server[key]}
                />
            ) : (
                <Input
                    {...fieldProps(`plan-${key}`, server[key])}
                    type="number"
                    inputMode="decimal"
                    step="any"
                    min={0}
                    value={form[key]}
                    onChange={(change) => update(key, change.target.value)}
                />
            )}
        </WizardField>
    );

    return (
        <WorkspaceWizard
            title="Vehicle alert response plan"
            description={`${workspace.vehicle.name}: a draft plan. Draft rules are not live device settings and start no monitoring.`}
            railIcon={ShieldAlert}
            railSub={plan ? `Draft v${plan.version}` : 'New draft'}
            steps={PLAN_STEPS}
            step={step}
            setStep={(next) => {
                setError('');
                setStep(next);
            }}
            pct={Math.round(
                ([
                    !!form.owner_user_id,
                    !!form.backup_user_id,
                    !!form.speed_threshold_kph,
                    !!form.speed_tolerance_kph,
                    !!form.speed_duration_s,
                    !!form.speed_cooldown_s,
                    !!form.offline_minutes,
                    !!form.low_voltage_v,
                    !!form.low_voltage_minutes,
                    !!form.notes.trim(),
                ].filter(Boolean).length /
                    10) *
                    100,
            )}
            context={{
                name: workspace.vehicle.name,
                detail: 'Draft · activation pending',
            }}
            command={command}
            dirty={JSON.stringify(form) !== JSON.stringify(initial)}
            saved={saved}
            submitLabel="Save draft response plan"
            onValidateStep={validateStep}
            onSubmit={submit}
            onClose={onClose}
            onReload={() => {
                onSaved();
                onClose();
            }}
            errorKey={JSON.stringify({ error, server })}
            success={
                <WizardSuccess
                    title="Draft response plan saved"
                    blurb="Activation stays pending: no device setting, monitoring rule or Control Room target changed. Evaluations use the new draft from now on; earlier evidence keeps its rule."
                    onClose={onClose}
                />
            }
        >
            {error && (
                <p role="alert" className="mb-4 text-sm text-status-critical">
                    {error}
                </p>
            )}
            {step === 0 && (
                <div className="space-y-5">
                    <p className="text-subtle">
                        Collision → urgent review. Towing, disconnection and
                        geofence events → contextual assessment. Draft rules are
                        not live device settings.
                    </p>
                    <div className="vehicle-wizard-fields">
                        <WizardField
                            id="plan-owner"
                            label="Primary response owner"
                            error={server.owner_user_id}
                        >
                            <PersonPicker
                                id="plan-owner"
                                label="Primary response owner"
                                value={form.owner_user_id}
                                people={workspace.people}
                                onChange={(value) =>
                                    update('owner_user_id', value)
                                }
                                invalid={!!server.owner_user_id}
                            />
                        </WizardField>
                        <WizardField
                            id="plan-backup"
                            label="Backup response owner"
                            error={server.backup_user_id}
                        >
                            <PersonPicker
                                id="plan-backup"
                                label="Backup response owner"
                                value={form.backup_user_id}
                                people={workspace.people}
                                onChange={(value) =>
                                    update('backup_user_id', value)
                                }
                                invalid={!!server.backup_user_id}
                            />
                        </WizardField>
                    </div>
                </div>
            )}
            {step === 1 && (
                <div className="space-y-5">
                    <p className="text-subtle">
                        A sustained speed above the fleet threshold plus
                        tolerance creates one Control Room record per episode.
                        This is not a verified road-limit breach. Draft changes
                        apply to future evaluations; historical evidence retains
                        its rule.
                    </p>
                    <div className="vehicle-wizard-fields">
                        {numeric(
                            'speed_threshold_kph',
                            'Fleet speed threshold · km/h',
                            'km/h',
                            PLAN_PRESETS.speed_threshold_kph,
                        )}
                        {numeric(
                            'speed_tolerance_kph',
                            'Tolerance · km/h',
                            'km/h',
                            PLAN_PRESETS.speed_tolerance_kph,
                        )}
                        {numeric(
                            'speed_duration_s',
                            'Minimum continuous duration · seconds',
                            'seconds',
                            PLAN_PRESETS.speed_duration_s,
                        )}
                        {numeric(
                            'speed_cooldown_s',
                            'Below-threshold time before a new episode · seconds',
                            'seconds',
                            PLAN_PRESETS.speed_cooldown_s,
                        )}
                    </div>
                </div>
            )}
            {step === 2 && (
                <div className="space-y-5">
                    <p className="text-subtle">
                        Vehicle-specific values. Validate firmware, battery
                        type, reporting cadence and operational policy before
                        enabling monitoring.
                    </p>
                    <div className="vehicle-wizard-fields">
                        {numeric(
                            'offline_minutes',
                            'Minutes overdue before review',
                            'minutes',
                        )}
                        {numeric(
                            'low_voltage_v',
                            'Vehicle voltage below which to review',
                            'V',
                        )}
                        {numeric(
                            'low_voltage_minutes',
                            'Low voltage duration in minutes',
                            'minutes',
                        )}
                    </div>
                    <WizardField
                        id="plan-notes"
                        label="Response, cooldown and escalation instructions"
                        error={server.notes}
                    >
                        <Textarea
                            {...fieldProps('plan-notes', server.notes)}
                            rows={4}
                            maxLength={2000}
                            value={form.notes}
                            onChange={(change) =>
                                update('notes', change.target.value)
                            }
                        />
                    </WizardField>
                    {plan && (
                        <WizardField
                            id="plan-reason"
                            label="Reason for this change"
                            error={server.reason}
                        >
                            <Textarea
                                {...fieldProps('plan-reason', server.reason)}
                                rows={2}
                                maxLength={2000}
                                value={form.reason}
                                onChange={(change) =>
                                    update('reason', change.target.value)
                                }
                            />
                        </WizardField>
                    )}
                </div>
            )}
            {step === 3 && (
                <ReviewCard
                    icon={ShieldAlert}
                    title="Draft response plan"
                    onEdit={() => setStep(0)}
                >
                    <ReviewRow
                        label="Owners"
                        value={`${owner?.name ?? '—'} → ${backup?.name ?? '—'}`}
                    />
                    <ReviewRow
                        label="Overspeed"
                        value={`above ${form.speed_threshold_kph} km/h + ${form.speed_tolerance_kph} km/h for ${form.speed_duration_s} s · new episode after ${form.speed_cooldown_s} s`}
                    />
                    <ReviewRow
                        label="Review thresholds"
                        value={`overdue ${form.offline_minutes} min · below ${form.low_voltage_v} V for ${form.low_voltage_minutes} min`}
                    />
                    <ReviewRow
                        label="Instructions"
                        value={form.notes.trim() || undefined}
                    />
                    <ReviewRow
                        label="Status"
                        value="Draft · activation pending"
                    />
                </ReviewCard>
            )}
        </WorkspaceWizard>
    );
}

/** "Review source event & score" from a response: the event's review wizard. */
export function ReviewSourceEvent({
    workspace,
    tripId,
    eventKey,
    onClose,
    onSaved,
}: {
    workspace: VehicleWorkspace;
    tripId: number;
    eventKey: string;
    onClose: () => void;
    onSaved: () => void;
}) {
    const { data, load } = useWorkspaceJson<DrivingReviews>(
        `/fleet-assets/vehicles/${workspace.vehicle.id}/driving/reviews?trip=${tripId}`,
    );
    const event =
        data?.trip?.events.find((item) => item.key === eventKey) ?? null;
    if (data?.trip && event)
        return (
            <ReviewEventWizard
                vehicle={{
                    id: workspace.vehicle.id,
                    name: workspace.vehicle.name,
                    registration:
                        workspace.vehicle.registration_number ??
                        workspace.vehicle.asset_tag ??
                        null,
                }}
                trip={data.trip}
                event={event}
                people={data.people}
                onClose={onClose}
                onSaved={onSaved}
            />
        );

    return (
        <InsightModal
            title="Review source event & score"
            description={`Trip #${tripId}`}
            icon={Gauge}
            size="detail"
            onClose={onClose}
        >
            <p className="vehicle-insight-text">
                {data
                    ? 'This event is no longer in the trip’s recorded data, so it cannot be reviewed here.'
                    : load === 'unavailable'
                      ? 'This trip is not available to you.'
                      : load === 'error'
                        ? 'The trip could not be loaded. Close and try again.'
                        : 'Loading the recorded event…'}
            </p>
        </InsightModal>
    );
}
