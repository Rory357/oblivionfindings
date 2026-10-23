/**
 * Every action on a Control Room alert, as one pop-up each on the shared kit
 * (components/popup — Stephan, 23 Oct 2026). The alert page, the Live
 * response preview and the row menus all open the same pop-ups, so an action
 * looks and behaves the same wherever it starts.
 *
 * Consequential actions keep a review step (Resolve, Report incident, Confirm
 * sensor report). A lost response shows "Result not confirmed" with a way to
 * check the alert instead of inviting a second, duplicate submit.
 */
import type { AlertWorkspaceDetail } from '@/components/control-room/alert-record/alert-sections';
import { ControlRoomItHandoffDialog } from '@/components/control-room/it-handoff-dialog';
import { DateTimeField } from '@/components/fleet-assets/maintenance/date-time-field';
import { JourneyGateList } from '@/components/incidents/journey-gate-list';
import {
    PopupCallout,
    PopupConfirm,
    PopupFacts,
    PopupField,
    PopupForm,
    PopupSection,
    type PopupContextRow,
} from '@/components/popup';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { SelectInput } from '@/components/wizard/primitives';
import { formatDateTime, toDatetimeLocal } from '@/lib/datetime';
import { router, useForm } from '@inertiajs/react';
import {
    ArrowUpCircle,
    Bell,
    BellOff,
    BookOpen,
    CheckCircle2,
    Eye,
    EyeOff,
    FileWarning,
    Laptop,
    Pencil,
    Radar,
    RadioTower,
    ShieldQuestion,
    UserCheck,
    UserMinus,
    UserPlus,
    type LucideIcon,
} from 'lucide-react';
import { useMemo, useRef, useState, type ReactNode } from 'react';
import {
    INCIDENT_TYPE_OPTIONS,
    SEVERITY_OPTIONS,
    alertReference,
    alertSummary,
    incidentTypeFromAlert,
    isActiveAlert,
    plainLabel,
    severityBadge,
    statusBadge,
} from './alert-labels';

/* ------------------------------------------------------------------ */
/*  Which actions this person has on this alert                        */
/* ------------------------------------------------------------------ */

export type AlertActionKey =
    | 'acknowledge'
    | 'triage'
    | 'resolve'
    | 'close'
    | 'assign'
    | 'escalate'
    | 'snooze'
    | 'unsnooze'
    | 'report_incident'
    | 'confirm_sensor'
    | 'dismiss_sensor'
    | 'edit_details'
    | 'start_procedure'
    | 'it_handoff'
    | 'watch';

export type AlertActionItem = {
    key: AlertActionKey;
    label: string;
    icon: LucideIcon;
    tone?: 'critical';
};

/** The one next step for the alert's state (the header's main button). */
export function primaryAlertAction(
    d: AlertWorkspaceDetail,
): AlertActionItem | null {
    const a = d.alert;
    if (!d.can.manage) return null;
    if (a.status === 'open')
        return { key: 'acknowledge', label: 'Acknowledge', icon: UserCheck };
    if (a.status === 'ack')
        return { key: 'triage', label: 'Start triage', icon: Eye };
    if (a.status === 'triaging')
        return { key: 'resolve', label: 'Resolve', icon: CheckCircle2 };
    if (a.status === 'resolved')
        return { key: 'close', label: 'Close alert', icon: CheckCircle2 };
    return null;
}

/** Every action available now, in the order the menus show them. */
export function alertActions(d: AlertWorkspaceDetail): AlertActionItem[] {
    const a = d.alert;
    const active = isActiveAlert(a.status);
    const sensorOpen = a.source === 'sensor' && active && !d.linked_incident;
    const items: AlertActionItem[] = [];
    const add = (item: AlertActionItem, when: boolean) => {
        if (when) items.push(item);
    };

    add(
        { key: 'acknowledge', label: 'Acknowledge', icon: UserCheck },
        d.can.manage && a.status === 'open',
    );
    add(
        { key: 'triage', label: 'Start triage', icon: Eye },
        d.can.manage && (a.status === 'open' || a.status === 'ack'),
    );
    add(
        {
            key: 'assign',
            label: a.assigned_to ? 'Reassign' : 'Assign',
            icon: UserPlus,
        },
        d.can.assign && active,
    );
    add(
        { key: 'escalate', label: 'Escalate', icon: ArrowUpCircle },
        d.can.escalate && active,
    );
    add(
        { key: 'confirm_sensor', label: 'Confirm sensor report', icon: Radar },
        d.can.manage && sensorOpen,
    );
    add(
        {
            key: 'dismiss_sensor',
            label: 'Dismiss as false positive',
            icon: ShieldQuestion,
            tone: 'critical',
        },
        d.can.manage && sensorOpen,
    );
    add(
        {
            key: 'report_incident',
            label: 'Report incident',
            icon: FileWarning,
        },
        d.can.create_incident && !d.linked_incident && !sensorOpen,
    );
    add(
        { key: 'resolve', label: 'Resolve', icon: CheckCircle2 },
        d.can.manage && active,
    );
    add(
        { key: 'close', label: 'Close alert', icon: CheckCircle2 },
        d.can.manage && a.status === 'resolved',
    );
    add(
        { key: 'snooze', label: 'Snooze', icon: BellOff },
        d.can.manage && active && a.severity !== 'critical' && !a.is_snoozed,
    );
    add(
        { key: 'unsnooze', label: 'Bring back now', icon: Bell },
        d.can.manage && a.is_snoozed,
    );
    add(
        {
            key: 'start_procedure',
            label: 'Start a response procedure',
            icon: BookOpen,
        },
        d.can.manage &&
            active &&
            !d.playbook_run &&
            d.available_playbooks.length > 0,
    );
    add(
        { key: 'it_handoff', label: 'Hand off to IT', icon: Laptop },
        Boolean(d.it_handoff?.can_open),
    );
    add(
        {
            key: 'watch',
            label: d.is_watching ? 'Stop watching' : 'Watch this alert',
            icon: d.is_watching ? EyeOff : Eye,
        },
        d.can.watch,
    );
    add(
        { key: 'edit_details', label: 'Edit alert details', icon: Pencil },
        d.can.manage,
    );
    return items;
}

/* ------------------------------------------------------------------ */
/*  Host: open an action and render its pop-up                         */
/* ------------------------------------------------------------------ */

/**
 * `run(key)` opens the action's pop-up (or, for one-step toggles such as
 * Watch and Bring back now, does it straight away); render `host` once.
 */
export function useAlertActions(d: AlertWorkspaceDetail | null) {
    const [active, setActive] = useState<AlertActionKey | null>(null);
    const close = () => setActive(null);

    const run = (key: AlertActionKey) => {
        if (!d) return;
        if (key === 'watch') {
            router.post(
                `/control-room/alerts/${d.alert.id}/watchers/toggle`,
                {},
                { preserveScroll: true, preserveState: true },
            );
            return;
        }
        if (key === 'unsnooze') {
            router.post(
                `/control-room/alerts/${d.alert.id}/unsnooze`,
                {},
                { preserveScroll: true, preserveState: true },
            );
            return;
        }
        setActive(key);
    };

    const host =
        d && active ? (
            <AlertActionPopup
                key={`${d.alert.id}:${active}`}
                d={d}
                action={active}
                onClose={close}
            />
        ) : null;

    return { run, host, active };
}

function AlertActionPopup({
    d,
    action,
    onClose,
}: {
    d: AlertWorkspaceDetail;
    action: AlertActionKey;
    onClose: () => void;
}) {
    switch (action) {
        case 'acknowledge':
            return <AcknowledgePopup d={d} onClose={onClose} />;
        case 'triage':
            return <TriagePopup d={d} onClose={onClose} />;
        case 'resolve':
            return <ResolvePopup d={d} onClose={onClose} />;
        case 'close':
            return <ClosePopup d={d} onClose={onClose} />;
        case 'assign':
            return <AssignPopup d={d} onClose={onClose} />;
        case 'escalate':
            return <EscalatePopup d={d} onClose={onClose} />;
        case 'snooze':
            return <SnoozePopup d={d} onClose={onClose} />;
        case 'report_incident':
            return <ReportIncidentPopup d={d} onClose={onClose} />;
        case 'confirm_sensor':
            return <ConfirmSensorPopup d={d} onClose={onClose} />;
        case 'dismiss_sensor':
            return <DismissSensorPopup d={d} onClose={onClose} />;
        case 'edit_details':
            return <EditDetailsPopup d={d} onClose={onClose} />;
        case 'start_procedure':
            return <StartProcedurePopup d={d} onClose={onClose} />;
        case 'it_handoff':
            return d.it_handoff ? (
                <ControlRoomItHandoffDialog
                    actorId={d.it_handoff.viewer_user_id}
                    alertId={d.alert.id}
                    alertReference={alertReference(d.alert)}
                    allowed={d.it_handoff.can_open}
                    onClose={() => {
                        onClose();
                        router.reload({ preserveScroll: true });
                    }}
                />
            ) : null;
        default:
            return null;
    }
}

/* ------------------------------------------------------------------ */
/*  Shared pieces                                                      */
/* ------------------------------------------------------------------ */

function contextRow(d: AlertWorkspaceDetail): PopupContextRow {
    const a = d.alert;
    return {
        icon: RadioTower,
        title: `${alertReference(a)} · ${alertSummary(a)}`,
        tag: d.client?.name ?? a.asset?.name ?? undefined,
        note: `${statusBadge(a.status).label} · ${severityBadge(a.severity).label}${
            a.triggered_at ? ` · raised ${formatDateTime(a.triggered_at)}` : ''
        }`,
    };
}

/** Server guardrails come back under errors.alert / errors.pack, not a field. */
function serverError(errors: object): string | undefined {
    const bag = errors as Record<string, string | undefined>;
    return bag.alert ?? bag.pack;
}

/**
 * Submit with an honest outcome: success closes, a refusal stays open with
 * its reason, and a response that never arrives says the result is unknown.
 */
function useActionSubmit(onDone: () => void) {
    const [flashError, setFlashError] = useState<string | undefined>();
    const [unconfirmed, setUnconfirmed] = useState(false);
    const settled = useRef(false);

    const options = {
        preserveScroll: true,
        onStart: () => {
            settled.current = false;
            setFlashError(undefined);
            setUnconfirmed(false);
        },
        onSuccess: (page: { props: Record<string, unknown> }) => {
            settled.current = true;
            const error = (page.props as { flash?: { error?: string } }).flash
                ?.error;
            if (error) setFlashError(error);
            else onDone();
        },
        onError: () => {
            settled.current = true;
        },
        onCancel: () => {
            settled.current = true;
        },
        onFinish: () => {
            if (!settled.current) setUnconfirmed(true);
        },
    };

    const checkResult = () => {
        setUnconfirmed(false);
        router.reload({ preserveScroll: true, onSuccess: () => onDone() });
    };

    return { options, flashError, unconfirmed, checkResult };
}

const UNCONFIRMED =
    "We couldn't confirm the result — the connection may have dropped. Check the alert before trying again, so the action isn't recorded twice.";

function textareaProps(
    id: string,
    value: string,
    onChange: (value: string) => void,
) {
    return {
        id,
        value,
        onChange: (event: { target: { value: string } }) =>
            onChange(event.target.value),
    };
}

/* ------------------------------------------------------------------ */
/*  Acknowledge · Start triage                                         */
/* ------------------------------------------------------------------ */

function NoteActionPopup({
    d,
    onClose,
    icon,
    title,
    description,
    endpoint,
    noteLabel,
    placeholder,
    submitLabel,
}: {
    d: AlertWorkspaceDetail;
    onClose: () => void;
    icon: LucideIcon;
    title: string;
    description: string;
    endpoint: string;
    noteLabel: string;
    placeholder: string;
    submitLabel: string;
}) {
    const form = useForm<{ notes: string }>({ notes: '' });
    const submit = useActionSubmit(onClose);
    return (
        <PopupForm
            open
            onClose={onClose}
            icon={icon}
            title={title}
            description={description}
            context={contextRow(d)}
            error={serverError(form.errors) ?? submit.flashError}
            callout={
                submit.unconfirmed
                    ? { tone: 'warning', content: UNCONFIRMED }
                    : undefined
            }
            dirty={form.isDirty}
            draftName={`${title} for ${alertReference(d.alert)}`}
            processing={form.processing}
            submitLabel={submit.unconfirmed ? 'Check result' : submitLabel}
            onSubmit={() =>
                submit.unconfirmed
                    ? submit.checkResult()
                    : form.post(endpoint, submit.options)
            }
        >
            <PopupField
                label={noteLabel}
                htmlFor="alert-action-note"
                hint="Optional — kept in the alert's notes"
            >
                <Textarea
                    rows={3}
                    placeholder={placeholder}
                    {...textareaProps('alert-action-note', form.data.notes, (v) =>
                        form.setData('notes', v),
                    )}
                />
            </PopupField>
        </PopupForm>
    );
}

function AcknowledgePopup({
    d,
    onClose,
}: {
    d: AlertWorkspaceDetail;
    onClose: () => void;
}) {
    return (
        <NoteActionPopup
            d={d}
            onClose={onClose}
            icon={UserCheck}
            title="Acknowledge alert"
            description="Tells the team someone has seen it, and stops the acknowledgement target."
            endpoint={`/control-room/alerts/${d.alert.id}/acknowledge`}
            noteLabel="Note"
            placeholder="e.g. On it — checking the camera feed now"
            submitLabel="Acknowledge alert"
        />
    );
}

function TriagePopup({
    d,
    onClose,
}: {
    d: AlertWorkspaceDetail;
    onClose: () => void;
}) {
    return (
        <NoteActionPopup
            d={d}
            onClose={onClose}
            icon={Eye}
            title="Start triage"
            description="Marks the alert as being worked on, and stops the response target."
            endpoint={`/control-room/alerts/${d.alert.id}/triage`}
            noteLabel="What are you doing?"
            placeholder="e.g. Calling the site lead to check"
            submitLabel="Start triage"
        />
    );
}

/* ------------------------------------------------------------------ */
/*  Resolve (form → review) · Close                                    */
/* ------------------------------------------------------------------ */

function ResolvePopup({
    d,
    onClose,
}: {
    d: AlertWorkspaceDetail;
    onClose: () => void;
}) {
    const [reviewing, setReviewing] = useState(false);
    const form = useForm<{ resolution_notes: string; resolution_code: string }>(
        {
            resolution_notes: '',
            resolution_code: d.alert.resolution_code ?? '',
        },
    );
    const codes = d.config_options.resolution_codes ?? [];
    const gate = d.resolve_gate;
    const submit = useActionSubmit(onClose);

    const resolve = () => {
        const code = form.data.resolution_code;
        form.post(`/control-room/alerts/${d.alert.id}/resolve`, {
            ...submit.options,
            onSuccess: (page) => {
                const failed = (page.props as { flash?: { error?: string } })
                    .flash?.error;
                if (!failed && code && code !== d.alert.resolution_code) {
                    router.post(
                        `/control-room/alerts/${d.alert.id}/meta`,
                        { resolution_code: code },
                        { preserveScroll: true },
                    );
                }
                submit.options.onSuccess(page);
                if (failed) setReviewing(false);
            },
        });
    };

    return (
        <>
            <PopupForm
                open={!reviewing}
                onClose={onClose}
                size="md"
                icon={CheckCircle2}
                title="Resolve alert"
                description="Ends the live response. A linked incident or H&S work stays open with its own owner."
                context={contextRow(d)}
                error={serverError(form.errors) ?? submit.flashError}
                dirty={form.isDirty}
                draftName={`Resolve alert for ${alertReference(d.alert)}`}
                submitLabel="Review resolution"
                submitDisabled={!form.data.resolution_notes.trim() || !gate.allowed}
                onSubmit={() => setReviewing(true)}
            >
                <JourneyGateList gate={gate} />
                <PopupField
                    label="What was found and what was done"
                    htmlFor="resolve-notes"
                    required
                    error={form.errors.resolution_notes}
                >
                    <Textarea
                        rows={4}
                        placeholder="e.g. Hemi was found in the garden with a staff member. Tracker reset."
                        {...textareaProps(
                            'resolve-notes',
                            form.data.resolution_notes,
                            (v) => form.setData('resolution_notes', v),
                        )}
                    />
                </PopupField>
                {codes.length ? (
                    <PopupField
                        label="Resolution code"
                        hint="Optional — used in reports"
                    >
                        <SelectInput
                            value={form.data.resolution_code}
                            onChange={(v) => form.setData('resolution_code', v)}
                            placeholder="Choose a code"
                            options={codes}
                        />
                    </PopupField>
                ) : null}
            </PopupForm>
            <PopupConfirm
                open={reviewing}
                onClose={() => setReviewing(false)}
                onConfirm={() =>
                    submit.unconfirmed ? submit.checkResult() : resolve()
                }
                icon={CheckCircle2}
                title="Resolve this alert?"
                description="The live response ends and the alert leaves the worklist. This is kept in the alert's history."
                cancelLabel="Back"
                confirmLabel={submit.unconfirmed ? 'Check result' : 'Resolve alert'}
                processing={form.processing}
                error={submit.flashError}
            >
                {submit.unconfirmed ? (
                    <PopupCallout tone="warning">{UNCONFIRMED}</PopupCallout>
                ) : null}
                <PopupFacts
                    rows={[
                        ['Alert', alertReference(d.alert)],
                        ['What was done', form.data.resolution_notes],
                        [
                            'Resolution code',
                            codes.find(
                                (c) => c.value === form.data.resolution_code,
                            )?.label ?? 'Not set',
                        ],
                    ]}
                />
            </PopupConfirm>
        </>
    );
}

function ClosePopup({
    d,
    onClose,
}: {
    d: AlertWorkspaceDetail;
    onClose: () => void;
}) {
    const form = useForm<{ closure_notes: string }>({ closure_notes: '' });
    const submit = useActionSubmit(onClose);
    return (
        <PopupForm
            open
            onClose={onClose}
            size="md"
            icon={CheckCircle2}
            title="Close alert"
            description="Closes the response record. This is only possible once the linked incident and H&S work are closed."
            context={contextRow(d)}
            error={serverError(form.errors) ?? submit.flashError}
            callout={
                submit.unconfirmed
                    ? { tone: 'warning', content: UNCONFIRMED }
                    : undefined
            }
            dirty={form.isDirty}
            draftName={`Close alert for ${alertReference(d.alert)}`}
            processing={form.processing}
            submitDisabled={!d.close_gate.allowed && !submit.unconfirmed}
            submitLabel={submit.unconfirmed ? 'Check result' : 'Close alert'}
            onSubmit={() =>
                submit.unconfirmed
                    ? submit.checkResult()
                    : form.post(
                          `/control-room/alerts/${d.alert.id}/close`,
                          submit.options,
                      )
            }
        >
            <JourneyGateList gate={d.close_gate} />
            <PopupFacts
                rows={[
                    [
                        'Resolved',
                        d.alert.resolved_at
                            ? formatDateTime(d.alert.resolved_at)
                            : 'Not recorded',
                    ],
                    ['Resolved by', d.alert.resolved_by?.name ?? 'Not recorded'],
                    ['What was done', d.alert.notes ?? 'Not recorded'],
                ]}
            />
            <PopupField
                label="Closing note"
                htmlFor="close-notes"
                hint="Optional"
            >
                <Textarea
                    rows={2}
                    {...textareaProps(
                        'close-notes',
                        form.data.closure_notes,
                        (v) => form.setData('closure_notes', v),
                    )}
                />
            </PopupField>
        </PopupForm>
    );
}

/* ------------------------------------------------------------------ */
/*  Assign · Escalate · Snooze                                         */
/* ------------------------------------------------------------------ */

function AssignPopup({
    d,
    onClose,
}: {
    d: AlertWorkspaceDetail;
    onClose: () => void;
}) {
    const a = d.alert;
    const [unassigning, setUnassigning] = useState(false);
    const form = useForm<{ assigned_to_user_id: string; reason: string }>({
        assigned_to_user_id: a.assigned_to ? String(a.assigned_to.id) : '',
        reason: '',
    });
    const submit = useActionSubmit(onClose);
    const unassign = useActionSubmit(onClose);
    const [unassignBusy, setUnassignBusy] = useState(false);
    const selected = d.staff.find(
        (s) => String(s.id) === form.data.assigned_to_user_id,
    );
    const unchanged = Boolean(a.assigned_to && selected?.id === a.assigned_to.id);

    return (
        <>
            <PopupForm
                open={!unassigning}
                onClose={onClose}
                icon={UserPlus}
                title={a.assigned_to ? 'Reassign alert' : 'Assign alert'}
                description="Choose who owns the response. The change is kept in the alert's history."
                context={contextRow(d)}
                error={
                    serverError(form.errors) ??
                    form.errors.assigned_to_user_id ??
                    submit.flashError
                }
                callout={
                    submit.unconfirmed
                        ? { tone: 'warning', content: UNCONFIRMED }
                        : a.assigned_to
                          ? {
                                tone: 'info',
                                content: (
                                    <>
                                        Owned by{' '}
                                        <b className="font-semibold">
                                            {a.assigned_to.name}
                                        </b>
                                        {a.assigned_at
                                            ? ` since ${formatDateTime(a.assigned_at)}`
                                            : ''}
                                        .
                                    </>
                                ),
                            }
                          : undefined
                }
                dirty={form.isDirty}
                draftName={`Assign alert for ${alertReference(a)}`}
                processing={form.processing}
                submitDisabled={!selected || (unchanged && !submit.unconfirmed)}
                submitLabel={
                    submit.unconfirmed
                        ? 'Check result'
                        : selected
                          ? `Assign to ${selected.name}`
                          : 'Assign alert'
                }
                onSubmit={() => {
                    if (submit.unconfirmed) return submit.checkResult();
                    form.transform((data) => ({
                        ...data,
                        assigned_to_user_id: Number(data.assigned_to_user_id),
                    }));
                    form.post(
                        `/control-room/alerts/${a.id}/assign`,
                        submit.options,
                    );
                }}
                footerStart={
                    a.assigned_to ? (
                        <Button
                            type="button"
                            variant="ghost"
                            onClick={() => setUnassigning(true)}
                        >
                            <UserMinus aria-hidden="true" />
                            Unassign
                        </Button>
                    ) : null
                }
            >
                <PopupField label="Assign to" required>
                    <SelectInput
                        value={form.data.assigned_to_user_id}
                        onChange={(v) => form.setData('assigned_to_user_id', v)}
                        placeholder="Choose a person"
                        ariaLabel="Assign to"
                        options={d.staff.map((s) => ({
                            value: String(s.id),
                            label: s.name,
                        }))}
                    />
                </PopupField>
                <PopupField
                    label="Reason"
                    htmlFor="assign-reason"
                    hint="Optional — kept in the history"
                >
                    <Input
                        id="assign-reason"
                        value={form.data.reason}
                        onChange={(e) => form.setData('reason', e.target.value)}
                        placeholder="e.g. On shift and closest to the site"
                    />
                </PopupField>
            </PopupForm>
            <PopupConfirm
                open={unassigning}
                onClose={() => setUnassigning(false)}
                onConfirm={() => {
                    if (unassign.unconfirmed) return unassign.checkResult();
                    setUnassignBusy(true);
                    router.post(
                        `/control-room/alerts/${a.id}/unassign`,
                        {},
                        {
                            ...unassign.options,
                            onFinish: () => {
                                setUnassignBusy(false);
                                unassign.options.onFinish();
                            },
                        },
                    );
                }}
                icon={UserMinus}
                title={`Unassign ${a.assigned_to?.name ?? 'this alert'}?`}
                description="The alert goes back to the worklist with no owner until someone claims it. This is kept in the history."
                cancelLabel="Back"
                confirmLabel={unassign.unconfirmed ? 'Check result' : 'Unassign'}
                processing={unassignBusy}
                error={unassign.flashError}
            >
                {unassign.unconfirmed ? (
                    <PopupCallout tone="warning">{UNCONFIRMED}</PopupCallout>
                ) : null}
            </PopupConfirm>
        </>
    );
}

function EscalatePopup({
    d,
    onClose,
}: {
    d: AlertWorkspaceDetail;
    onClose: () => void;
}) {
    const current = d.alert.escalation_level ?? 0;
    const next = Math.min(current + 1, 5);
    const form = useForm<{ escalation_reason: string }>({
        escalation_reason: '',
    });
    const submit = useActionSubmit(onClose);
    return (
        <PopupConfirm
            open
            onClose={onClose}
            onConfirm={() =>
                submit.unconfirmed
                    ? submit.checkResult()
                    : form.post(
                          `/control-room/alerts/${d.alert.id}/escalate`,
                          submit.options,
                      )
            }
            variant="warning"
            icon={ArrowUpCircle}
            title={`Escalate to level ${next}?`}
            description={`This flags the alert for senior attention${current > 0 ? ` (it is at level ${current} now)` : ''}. The reason is kept in the history.`}
            context={contextRow(d)}
            confirmLabel={
                submit.unconfirmed ? 'Check result' : `Escalate to level ${next}`
            }
            confirmDisabled={
                !form.data.escalation_reason.trim() && !submit.unconfirmed
            }
            processing={form.processing}
            error={serverError(form.errors) ?? submit.flashError}
        >
            {submit.unconfirmed ? (
                <PopupCallout tone="warning">{UNCONFIRMED}</PopupCallout>
            ) : null}
            <PopupField
                label="Why does it need senior attention?"
                htmlFor="escalate-reason"
                required
                error={form.errors.escalation_reason}
            >
                <Textarea
                    rows={3}
                    {...textareaProps(
                        'escalate-reason',
                        form.data.escalation_reason,
                        (v) => form.setData('escalation_reason', v),
                    )}
                />
            </PopupField>
        </PopupConfirm>
    );
}

const SNOOZE_WINDOWS = [
    { value: '15m', label: '15 minutes' },
    { value: '1h', label: '1 hour' },
    { value: 'shift', label: 'End of day' },
    { value: 'custom', label: 'Choose a time' },
];

function SnoozePopup({
    d,
    onClose,
}: {
    d: AlertWorkspaceDetail;
    onClose: () => void;
}) {
    const form = useForm<{ window: string; snoozed_until: string; note: string }>(
        { window: '15m', snoozed_until: '', note: '' },
    );
    const submit = useActionSubmit(onClose);
    const custom = form.data.window === 'custom';
    return (
        <PopupForm
            open
            onClose={onClose}
            size="md"
            icon={BellOff}
            title="Snooze alert"
            description="Takes it off the worklist for a while. It stays open, its targets keep running, and it comes back on its own."
            context={contextRow(d)}
            error={
                serverError(form.errors) ??
                form.errors.window ??
                submit.flashError
            }
            callout={
                submit.unconfirmed
                    ? { tone: 'warning', content: UNCONFIRMED }
                    : undefined
            }
            dirty={form.isDirty}
            draftName={`Snooze alert for ${alertReference(d.alert)}`}
            processing={form.processing}
            submitDisabled={custom && !form.data.snoozed_until && !submit.unconfirmed}
            submitLabel={submit.unconfirmed ? 'Check result' : 'Snooze alert'}
            onSubmit={() => {
                if (submit.unconfirmed) return submit.checkResult();
                form.transform((data) => ({
                    window: data.window,
                    snoozed_until:
                        data.window === 'custom'
                            ? data.snoozed_until || null
                            : null,
                    note: data.note || null,
                }));
                form.post(
                    `/control-room/alerts/${d.alert.id}/snooze`,
                    submit.options,
                );
            }}
        >
            <PopupField label="Snooze for" required>
                <ToggleGroup
                    type="single"
                    variant="outline"
                    value={form.data.window}
                    onValueChange={(value) => {
                        if (value) form.setData('window', value);
                    }}
                    aria-label="Snooze for"
                    className="grid w-full grid-cols-2 sm:grid-cols-4"
                >
                    {SNOOZE_WINDOWS.map((window) => (
                        <ToggleGroupItem key={window.value} value={window.value}>
                            {window.label}
                        </ToggleGroupItem>
                    ))}
                </ToggleGroup>
            </PopupField>
            {custom ? (
                <DateTimeField
                    id="snooze-until"
                    label="Snooze until"
                    value={form.data.snoozed_until}
                    onChange={(value) => form.setData('snoozed_until', value)}
                    error={form.errors.snoozed_until}
                    clearable={false}
                />
            ) : null}
            <PopupField
                label="Why are you snoozing it?"
                htmlFor="snooze-note"
                hint="Optional — kept in the history"
            >
                <Input
                    id="snooze-note"
                    value={form.data.note}
                    onChange={(e) => form.setData('note', e.target.value)}
                    placeholder="e.g. Waiting for the family to call back"
                />
            </PopupField>
        </PopupForm>
    );
}

/* ------------------------------------------------------------------ */
/*  Report incident · Confirm sensor report · Dismiss                  */
/* ------------------------------------------------------------------ */

function ImmediateControlsNotice({
    d,
    required,
}: {
    d: AlertWorkspaceDetail;
    required: boolean;
}) {
    const source = d.incident_defaults?.source_note;
    if (!source) {
        return required ? (
            <PopupCallout tone="warning">
                No Immediate controls note was found. Record or confirm the
                immediate action below before reporting the incident.
            </PopupCallout>
        ) : null;
    }
    return (
        <PopupCallout tone="info">
            Filled in from an Immediate controls note
            {source.user_name ? ` by ${source.user_name}` : ''}
            {source.created_at ? ` on ${formatDateTime(source.created_at)}` : ''}
            . Check it before reporting the incident.
        </PopupCallout>
    );
}

function ImmediateActionField({
    id,
    value,
    onChange,
    required,
    error,
}: {
    id: string;
    value: string;
    onChange: (value: string) => void;
    required: boolean;
    error?: string;
}) {
    return (
        <PopupField
            label="Immediate action taken"
            htmlFor={id}
            required={required}
            error={error}
            hint={
                required
                    ? 'Needed for high and critical alerts. If nothing could be done, write "No immediate control was possible".'
                    : 'Optional'
            }
            wide
        >
            <Textarea
                rows={3}
                placeholder="What was done straight away to keep people safe?"
                {...textareaProps(id, value, onChange)}
            />
        </PopupField>
    );
}

function ReportIncidentPopup({
    d,
    onClose,
}: {
    d: AlertWorkspaceDetail;
    onClose: () => void;
}) {
    const [reviewing, setReviewing] = useState(false);
    const form = useForm<{
        type: string;
        severity: string;
        description: string;
        immediate_action_taken: string;
    }>({
        type: incidentTypeFromAlert(d.alert.alert_type),
        severity: d.alert.severity,
        description: d.alert.notes ?? '',
        immediate_action_taken: d.incident_defaults?.immediate_action_taken ?? '',
    });
    const submit = useActionSubmit(onClose);
    const serious =
        d.alert.severity === 'critical' ||
        ['high', 'critical'].includes(form.data.severity);
    const missingAction = serious && !form.data.immediate_action_taken.trim();

    return (
        <>
            <PopupForm
                open={!reviewing}
                onClose={onClose}
                size="md"
                icon={FileWarning}
                title="Report incident"
                description="Creates the official incident record from this alert and hands it to Health & Safety."
                context={contextRow(d)}
                error={serverError(form.errors) ?? submit.flashError}
                dirty={form.isDirty}
                draftName={`Report incident for ${alertReference(d.alert)}`}
                submitLabel="Review report"
                submitDisabled={missingAction}
                onSubmit={() => setReviewing(true)}
            >
                <ImmediateControlsNotice d={d} required={serious} />
                <PopupSection columns={2}>
                    <PopupField label="Incident type" error={form.errors.type}>
                        <SelectInput
                            value={form.data.type}
                            onChange={(v) => form.setData('type', v)}
                            placeholder="Choose a type"
                            ariaLabel="Incident type"
                            options={INCIDENT_TYPE_OPTIONS}
                        />
                    </PopupField>
                    <PopupField label="Severity" error={form.errors.severity}>
                        <SelectInput
                            value={form.data.severity}
                            onChange={(v) => form.setData('severity', v)}
                            placeholder="Choose severity"
                            ariaLabel="Severity"
                            options={SEVERITY_OPTIONS}
                        />
                    </PopupField>
                    <ImmediateActionField
                        id="incident-immediate-action"
                        value={form.data.immediate_action_taken}
                        onChange={(v) => form.setData('immediate_action_taken', v)}
                        required={serious}
                        error={form.errors.immediate_action_taken}
                    />
                    <PopupField
                        label="What happened"
                        htmlFor="incident-description"
                        hint="Optional"
                        wide
                    >
                        <Textarea
                            rows={3}
                            {...textareaProps(
                                'incident-description',
                                form.data.description,
                                (v) => form.setData('description', v),
                            )}
                        />
                    </PopupField>
                </PopupSection>
            </PopupForm>
            <PopupConfirm
                open={reviewing}
                onClose={() => setReviewing(false)}
                onConfirm={() =>
                    submit.unconfirmed
                        ? submit.checkResult()
                        : form.post(
                              `/control-room/alerts/${d.alert.id}/create-incident`,
                              {
                                  ...submit.options,
                                  onError: () => {
                                      submit.options.onError();
                                      setReviewing(false);
                                  },
                              },
                          )
                }
                icon={FileWarning}
                title="Report this incident?"
                description="One official incident is created and Health & Safety is asked to take over its follow-up. The alert stays open until the response is done."
                cancelLabel="Back"
                confirmLabel={submit.unconfirmed ? 'Check result' : 'Report incident'}
                processing={form.processing}
                error={submit.flashError}
            >
                {submit.unconfirmed ? (
                    <PopupCallout tone="warning">{UNCONFIRMED}</PopupCallout>
                ) : null}
                <PopupFacts
                    rows={[
                        [
                            'Type',
                            INCIDENT_TYPE_OPTIONS.find(
                                (o) => o.value === form.data.type,
                            )?.label ?? plainLabel(form.data.type),
                        ],
                        [
                            'Severity',
                            SEVERITY_OPTIONS.find(
                                (o) => o.value === form.data.severity,
                            )?.label ?? plainLabel(form.data.severity),
                        ],
                        [
                            'Immediate action',
                            form.data.immediate_action_taken || 'Not recorded',
                        ],
                    ]}
                />
            </PopupConfirm>
        </>
    );
}

function SensorEvidence({ d }: { d: AlertWorkspaceDetail }) {
    const a = d.alert;
    const ctx = (a.context ?? {}) as Record<string, unknown>;
    const signal = ((ctx.signal ?? ctx.normalized_data ?? {}) as Record<
        string,
        unknown
    >) ?? {};
    const payload = ((signal.payload ?? ctx.payload ?? {}) as Record<
        string,
        unknown
    >) ?? {};
    const rows: Array<readonly [string, ReactNode]> = [
        ['Signal', plainLabel(String(signal.signal_type_code ?? a.alert_type))],
    ];
    if (signal.device) rows.push(['Device', String(signal.device)]);
    if (payload.confidence != null)
        rows.push(['Confidence', String(payload.confidence)]);
    const location = payload.location
        ? String(payload.location)
        : d.location?.description;
    if (location) rows.push(['Location', location]);
    if (a.triggered_at) rows.push(['Detected', formatDateTime(a.triggered_at)]);
    if (d.client) rows.push(['Person', d.client.name]);
    return <PopupFacts rows={rows} />;
}

function ConfirmSensorPopup({
    d,
    onClose,
}: {
    d: AlertWorkspaceDetail;
    onClose: () => void;
}) {
    const [reviewing, setReviewing] = useState(false);
    const form = useForm<{
        type: string;
        severity: string;
        note: string;
        immediate_action_taken: string;
    }>({
        type: d.alert.alert_type === 'fall_detected' ? 'fall' : '',
        severity: 'high',
        note: '',
        immediate_action_taken: d.incident_defaults?.immediate_action_taken ?? '',
    });
    const submit = useActionSubmit(onClose);
    const serious =
        d.alert.severity === 'critical' ||
        ['high', 'critical'].includes(form.data.severity);
    const missingAction = serious && !form.data.immediate_action_taken.trim();

    return (
        <>
            <PopupForm
                open={!reviewing}
                onClose={onClose}
                size="md"
                icon={Radar}
                title="Confirm sensor report"
                description="Confirms the detection is real and reports an incident with the sensor evidence."
                context={contextRow(d)}
                error={serverError(form.errors) ?? submit.flashError}
                callout={{
                    tone: 'info',
                    content: (
                        <>
                            If it was a false positive, use{' '}
                            <b className="font-semibold">
                                Dismiss as false positive
                            </b>{' '}
                            instead — that records why and reports nothing.
                        </>
                    ),
                }}
                dirty={form.isDirty}
                draftName={`Confirm sensor report for ${alertReference(d.alert)}`}
                submitLabel="Review confirmation"
                submitDisabled={missingAction}
                onSubmit={() => setReviewing(true)}
            >
                <SensorEvidence d={d} />
                <ImmediateControlsNotice d={d} required={serious} />
                <PopupSection columns={2}>
                    <PopupField
                        label="Incident type"
                        hint="Filled in from the signal"
                        error={form.errors.type}
                    >
                        <SelectInput
                            value={form.data.type}
                            onChange={(v) => form.setData('type', v)}
                            placeholder="Choose a type"
                            ariaLabel="Incident type"
                            options={INCIDENT_TYPE_OPTIONS}
                        />
                    </PopupField>
                    <PopupField label="Severity" error={form.errors.severity}>
                        <SelectInput
                            value={form.data.severity}
                            onChange={(v) => form.setData('severity', v)}
                            placeholder="Choose severity"
                            ariaLabel="Severity"
                            options={SEVERITY_OPTIONS.filter(
                                (o) => o.value !== 'critical',
                            )}
                        />
                    </PopupField>
                    <ImmediateActionField
                        id="sensor-immediate-action"
                        value={form.data.immediate_action_taken}
                        onChange={(v) => form.setData('immediate_action_taken', v)}
                        required={serious}
                        error={form.errors.immediate_action_taken}
                    />
                    <PopupField
                        label="What did you check?"
                        htmlFor="sensor-note"
                        hint="Optional — added to the incident"
                        wide
                    >
                        <Textarea
                            rows={3}
                            {...textareaProps('sensor-note', form.data.note, (v) =>
                                form.setData('note', v),
                            )}
                        />
                    </PopupField>
                </PopupSection>
            </PopupForm>
            <PopupConfirm
                open={reviewing}
                onClose={() => setReviewing(false)}
                onConfirm={() =>
                    submit.unconfirmed
                        ? submit.checkResult()
                        : form.post(
                              `/control-room/alerts/${d.alert.id}/confirm`,
                              {
                                  ...submit.options,
                                  onError: () => {
                                      submit.options.onError();
                                      setReviewing(false);
                                  },
                              },
                          )
                }
                icon={Radar}
                title="Confirm this sensor report?"
                description="An incident is reported with the sensor evidence and Health & Safety is asked to take over its follow-up."
                cancelLabel="Back"
                confirmLabel={
                    submit.unconfirmed ? 'Check result' : 'Confirm and report incident'
                }
                processing={form.processing}
                error={submit.flashError}
            >
                {submit.unconfirmed ? (
                    <PopupCallout tone="warning">{UNCONFIRMED}</PopupCallout>
                ) : null}
                <PopupFacts
                    rows={[
                        [
                            'Type',
                            INCIDENT_TYPE_OPTIONS.find(
                                (o) => o.value === form.data.type,
                            )?.label ?? 'Not chosen',
                        ],
                        [
                            'Severity',
                            SEVERITY_OPTIONS.find(
                                (o) => o.value === form.data.severity,
                            )?.label ?? plainLabel(form.data.severity),
                        ],
                        [
                            'Immediate action',
                            form.data.immediate_action_taken || 'Not recorded',
                        ],
                    ]}
                />
            </PopupConfirm>
        </>
    );
}

const DISMISS_REASONS = [
    'Resident sat down',
    'Pet or animal',
    'Object dropped',
    'Staff present',
    'Other',
];

function DismissSensorPopup({
    d,
    onClose,
}: {
    d: AlertWorkspaceDetail;
    onClose: () => void;
}) {
    const [reason, setReason] = useState('');
    const [other, setOther] = useState('');
    const form = useForm<{ reason: string }>({ reason: '' });
    const submit = useActionSubmit(onClose);
    const finalReason = reason === 'Other' ? other.trim() : reason;
    return (
        <PopupConfirm
            open
            onClose={onClose}
            onConfirm={() => {
                if (submit.unconfirmed) return submit.checkResult();
                form.transform(() => ({ reason: finalReason }));
                form.post(
                    `/control-room/alerts/${d.alert.id}/dismiss`,
                    submit.options,
                );
            }}
            variant="destructive"
            icon={ShieldQuestion}
            title="Dismiss as a false positive?"
            description="No incident is reported. The reason is kept so the sensor rules can be tuned."
            context={contextRow(d)}
            confirmLabel={submit.unconfirmed ? 'Check result' : 'Dismiss alert'}
            confirmDisabled={!finalReason && !submit.unconfirmed}
            processing={form.processing}
            error={serverError(form.errors) ?? form.errors.reason ?? submit.flashError}
        >
            {submit.unconfirmed ? (
                <PopupCallout tone="warning">{UNCONFIRMED}</PopupCallout>
            ) : null}
            <SensorEvidence d={d} />
            <PopupField label="Why is it a false positive?" required>
                <ToggleGroup
                    type="single"
                    variant="outline"
                    value={reason}
                    onValueChange={(value) => setReason(value)}
                    aria-label="Why is it a false positive?"
                    className="flex w-full flex-wrap justify-start gap-1.5"
                >
                    {DISMISS_REASONS.map((r) => (
                        <ToggleGroupItem
                            key={r}
                            value={r}
                            className="rounded-full border px-3"
                        >
                            {r}
                        </ToggleGroupItem>
                    ))}
                </ToggleGroup>
            </PopupField>
            {reason === 'Other' ? (
                <PopupField
                    label="Describe it"
                    htmlFor="dismiss-other"
                    required
                >
                    <Input
                        id="dismiss-other"
                        value={other}
                        onChange={(e) => setOther(e.target.value)}
                    />
                </PopupField>
            ) : null}
        </PopupConfirm>
    );
}

/* ------------------------------------------------------------------ */
/*  Edit details · Start a response procedure                          */
/* ------------------------------------------------------------------ */

function EditDetailsPopup({
    d,
    onClose,
}: {
    d: AlertWorkspaceDetail;
    onClose: () => void;
}) {
    const a = d.alert;
    const form = useForm<{
        priority: string;
        category: string;
        due_at: string;
        resolution_code: string;
    }>({
        priority: a.priority ?? '',
        category: a.category ?? '',
        due_at: a.due_at ? toDatetimeLocal(a.due_at) : '',
        resolution_code: a.resolution_code ?? '',
    });
    const submit = useActionSubmit(onClose);
    const categories = d.config_options.categories ?? [];
    const codes = d.config_options.resolution_codes ?? [];
    const notSetUp = (what: string) => (
        <p className="text-caption rounded-[10px] border border-dashed border-border px-3 py-2">
            No {what} set up yet — add them in Control Room › Settings ›
            Options.
        </p>
    );
    return (
        <PopupForm
            open
            onClose={onClose}
            size="md"
            icon={Pencil}
            title="Edit alert details"
            description="Working details for the response: priority, category, internal due time and resolution code."
            context={contextRow(d)}
            error={serverError(form.errors) ?? submit.flashError}
            callout={
                submit.unconfirmed
                    ? { tone: 'warning', content: UNCONFIRMED }
                    : undefined
            }
            dirty={form.isDirty}
            draftName={`Edit alert details for ${alertReference(a)}`}
            processing={form.processing}
            submitLabel={submit.unconfirmed ? 'Check result' : 'Save changes'}
            onSubmit={() => {
                if (submit.unconfirmed) return submit.checkResult();
                form.transform((data) => ({
                    priority: data.priority || null,
                    category: data.category || null,
                    due_at: data.due_at || null,
                    resolution_code: data.resolution_code || null,
                }));
                form.post(`/control-room/alerts/${a.id}/meta`, submit.options);
            }}
        >
            <PopupSection columns={2}>
                <PopupField label="Priority" error={form.errors.priority}>
                    <SelectInput
                        value={form.data.priority}
                        onChange={(v) => form.setData('priority', v)}
                        placeholder="No priority set"
                        ariaLabel="Priority"
                        options={[...SEVERITY_OPTIONS].reverse()}
                    />
                </PopupField>
                <PopupField label="Category" error={form.errors.category}>
                    {categories.length ? (
                        <SelectInput
                            value={form.data.category}
                            onChange={(v) => form.setData('category', v)}
                            placeholder="No category"
                            ariaLabel="Category"
                            options={categories}
                        />
                    ) : (
                        notSetUp('categories')
                    )}
                </PopupField>
                <PopupField
                    label="Resolution code"
                    error={form.errors.resolution_code}
                >
                    {codes.length ? (
                        <SelectInput
                            value={form.data.resolution_code}
                            onChange={(v) => form.setData('resolution_code', v)}
                            placeholder="Not set"
                            ariaLabel="Resolution code"
                            options={codes}
                        />
                    ) : (
                        notSetUp('resolution codes')
                    )}
                </PopupField>
            </PopupSection>
            <DateTimeField
                id="alert-due"
                label="Internal due time"
                hint="Optional — your own target, separate from the response targets"
                value={form.data.due_at}
                onChange={(value) => form.setData('due_at', value)}
                error={form.errors.due_at}
            />
        </PopupForm>
    );
}

function StartProcedurePopup({
    d,
    onClose,
}: {
    d: AlertWorkspaceDetail;
    onClose: () => void;
}) {
    const [playbookId, setPlaybookId] = useState('');
    const form = useForm<{ playbook_id: number | null }>({ playbook_id: null });
    const submit = useActionSubmit(onClose);
    const chosen = useMemo(
        () => d.available_playbooks.find((p) => String(p.id) === playbookId),
        [d.available_playbooks, playbookId],
    );
    const fieldError = (form.errors as Record<string, string | undefined>)
        .playbook_id;
    return (
        <PopupForm
            open
            onClose={onClose}
            icon={BookOpen}
            title="Start a response procedure"
            description="Adds step-by-step response steps to this alert. Work through them on its Procedure tab."
            context={contextRow(d)}
            error={serverError(form.errors) ?? fieldError ?? submit.flashError}
            callout={
                submit.unconfirmed
                    ? { tone: 'warning', content: UNCONFIRMED }
                    : chosen?.description
                      ? { tone: 'info', content: chosen.description }
                      : undefined
            }
            dirty={playbookId !== ''}
            draftName={`Start a response procedure for ${alertReference(d.alert)}`}
            processing={form.processing}
            submitDisabled={!playbookId && !submit.unconfirmed}
            submitLabel={submit.unconfirmed ? 'Check result' : 'Start procedure'}
            onSubmit={() => {
                if (submit.unconfirmed) return submit.checkResult();
                form.transform(() => ({ playbook_id: Number(playbookId) }));
                form.post(
                    `/control-room/alerts/${d.alert.id}/playbook/start`,
                    submit.options,
                );
            }}
        >
            <PopupField label="Procedure" required>
                <SelectInput
                    value={playbookId}
                    onChange={setPlaybookId}
                    placeholder="Choose a procedure"
                    ariaLabel="Procedure"
                    options={d.available_playbooks.map((p) => ({
                        value: String(p.id),
                        label: `${p.name} · ${plainLabel(p.category)}`,
                    }))}
                />
            </PopupField>
        </PopupForm>
    );
}
