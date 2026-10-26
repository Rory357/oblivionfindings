import { DatePicker } from '@/components/fleet-assets/maintenance/date-picker';
import {
    DateTimeField,
    validLocalDateTime,
} from '@/components/fleet-assets/maintenance/date-time-field';
import { ConfirmDialog } from '@/components/confirm-dialog';
import { JourneyTermHelp } from '@/components/journey-term-help';
import { compactMenu } from '@/components/lists/entity-menu';
import {
    FactRows,
    Journey,
    Log,
    QuietLine,
    Ring,
    RowList,
    type JourneyStep,
    type LogItem,
    type RowItem,
    type SgTone,
} from '@/components/signature/blocks';
import { Button } from '@/components/ui/button';
import { AttachmentUploader } from '@/components/ui/file-dropzone';
import { Input } from '@/components/ui/input';
import { StatusBadge } from '@/components/ui/status-badge';
import { Textarea } from '@/components/ui/textarea';
import {
    Field,
    FieldErr,
    InfoCard,
    SelectInput,
    StepHead,
} from '@/components/wizard/primitives';
import { ReviewCard, ReviewRow, WizardShell } from '@/components/wizard/shell';
import type { IncidentDraftState } from '@/hooks/use-incident-workspace-drafts';
import {
    formatDateOnly,
    formatDateTime,
    formatTime,
    toDatetimeLocal,
} from '@/lib/datetime';
import { Link, router, useForm, usePage } from '@inertiajs/react';
import {
    AlertTriangle,
    CheckCircle2,
    Clock,
    Download,
    ExternalLink,
    Eye,
    EyeOff,
    FileText,
    Hand,
    HeartPulse,
    Image as ImageIcon,
    LinkIcon,
    ListTodo,
    Paperclip,
    Pencil,
    Pill,
    Plus,
    RadioTower,
    RotateCcw,
    Search,
    Send,
    Shield,
    ShieldAlert,
    Trash2,
    Truck,
    User,
    Users,
} from 'lucide-react';
import {
    useCallback,
    useEffect,
    useId,
    useRef,
    useState,
    type ComponentType,
    type FormEvent,
} from 'react';
import type { IncidentOwnership } from './incident-ownership-panel';
import { dateWithYear, HS_STATUS_LABELS } from './incident-stages';
import { JourneyGateList, type JourneyGateData } from './journey-gate-list';
import {
    LinkedOperationalEvidence,
    type LinkedOperationalEvidenceData,
} from './linked-operational-evidence';
import { RecordIdentity, RecordPerson, RecordTable } from './record-table';

/* ------------------------------------------------------------------ */
/*  Types — mirrors IncidentController::buildIncidentDetail()           */
/* ------------------------------------------------------------------ */

type JsonCause = { description?: string; category?: string };
type JsonRec = {
    description?: string;
    priority?: string;
    target_area?: string;
};

export type IncidentDetail = {
    ownership?: IncidentOwnership;
    id: number;
    ref: string | null;
    type: string;
    source: string;
    interactive: boolean;
    severity: string;
    status: string;
    occurred_at: string | null;
    description: string | null;
    immediate_action_taken: string | null;
    witnesses: string | null;
    is_notifiable: boolean | null;
    worksafe_notification_status: string | null;
    worksafe_notified_at: string | null;
    worksafe_reference: string | null;
    potential_severity: string | null;
    potential_consequence: string | null;
    investigation_status: string | null;
    submitted_at: string | null;
    reviewed_at: string | null;
    review_notes: string | null;
    closed_at: string | null;
    closed_outcome: string | null;
    closed_notes: string | null;
    reopened_at: string | null;
    reopened_reason: string | null;
    control_room_alert_id: number | null;
    client: {
        id: number;
        first_name: string;
        last_name: string;
        site: string | null;
    } | null;
    reporter: { name: string; email: string } | null;
    investigator: string | null;
    attachments: Array<{
        id: number;
        name: string;
        mime: string | null;
        size: number | null;
        portal_visible: boolean;
        notes: string | null;
        uploaded_by: string | null;
        created_at: string | null;
        download_url: string;
    }>;
    followups: Array<{
        id: number;
        notes: string | null;
        assigned_to: string | null;
        due_at: string | null;
        completed_at: string | null;
        created_by: string | null;
        overdue: boolean;
    }>;
    control_room_alert: {
        id: number;
        status: string;
        severity: string;
        alert_type: string;
        triggered_at: string | null;
        resolved_at: string | null;
        url: string | null;
    } | null;
    linked_operational_evidence: LinkedOperationalEvidenceData | null;
    linked_access?: {
        control_room: 'absent' | 'readable' | 'restricted';
        health_safety: 'absent' | 'readable' | 'restricted';
    };
    close_gate: JourneyGateData;
    journey_state: string;
    hs_event: {
        id: number;
        reference_number: string;
        status: string;
        url: string | null;
        corrective_actions_url: string | null;
        worksafe_notifiable: boolean;
        worksafe_status: string | null;
        worksafe_reference: string | null;
        worksafe_notified_at: string | null;
        worksafe_acknowledged_at: string | null;
        handover: {
            status: string;
            owner: { id: number; name: string } | null;
            accepted_by: { id: number; name: string } | null;
            accepted_at: string | null;
            notes: string | null;
            can_accept: boolean;
        };
        investigation_required: boolean;
        investigation: {
            reference_number: string;
            status: string;
            methodology: string | null;
            root_causes: JsonCause[] | null;
            contributing_factors: JsonCause[] | null;
            recommendations: JsonRec[] | null;
            lessons_learned: string | null;
        } | null;
        corrective_actions: Array<{
            id: number;
            reference_number: string;
            title: string;
            status: string;
            priority: string;
            assigned_to: string | null;
            due_date: string | null;
        }>;
    } | null;
    can: {
        update: boolean;
        submit: boolean;
        review: boolean;
        close: boolean;
        reopen: boolean;
        followupsManage: boolean;
        followupsComplete: boolean;
        portalManage: boolean;
        raiseCorrectiveAction: boolean;
        requestResponse?: boolean;
    };
    assignable_staff: Array<{ id: number; name: string }>;
    corrective_action_owners: Array<{ id: number; name: string }>;
    safeguarding_concerns?: Array<{
        id: number;
        reference_number: string | null;
        status: string | null;
        severity: string | null;
        can_view: boolean;
    }>;
    fleet_incident?: { id: number; reference: string; type: string } | null;
    medication_error?: {
        id: number;
        error_type: string;
        severity: string;
        status: string;
        medication: string | null;
        summary?: string;
        ready_to_close?: boolean;
        reported_at: string | null;
        url: string;
    } | null;
    restraint_events?: Array<{
        id: number;
        reference: string;
        restraint_type: string;
        severity: string;
        within_support_plan: boolean;
        injury_occurred: boolean;
    }>;
    first_aid_records?: Array<{
        id: number;
        reference: string;
        href: string;
        person: string;
        injury: string;
        treatment_date: string | null;
        ambulance_called: boolean;
    }>;
};

type SectionKey =
    | 'overview'
    | 'timeline'
    | 'photos'
    | 'followups'
    | 'investigation'
    | 'linked';

/* ------------------------------------------------------------------ */
/*  Tokens                                                             */
/* ------------------------------------------------------------------ */

const DOT: Record<string, string> = {
    neutral: 'bg-muted-foreground',
    info: 'bg-status-info',
    success: 'bg-status-success',
    warning: 'bg-status-warning',
    critical: 'bg-status-critical',
    primary: 'bg-primary',
};

const SEV_LABEL: Record<string, string> = {
    low: 'Low',
    medium: 'Medium',
    high: 'High',
    critical: 'Critical',
};
const SEV_TONE: Record<string, string> = {
    low: 'success',
    medium: 'warning',
    high: 'critical',
    critical: 'critical',
};
const STATUS_LABEL: Record<string, string> = {
    draft: 'Draft',
    submitted: 'Submitted',
    reviewed: 'Reviewed',
    closed: 'Closed',
};

function titleCase(s: string): string {
    return s.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());
}
function handoverStatusLabel(status: string): string {
    if (status === 'accepted') return 'Accepted into H&S';
    if (status === 'awaiting_acceptance' || status === 'awaiting_hs_acceptance')
        return 'Awaiting H&S acceptance';
    return titleCase(status).replace(/\bHs\b/g, 'H&S');
}
function fmtSize(bytes: number | null): string {
    if (!bytes) return '';
    if (bytes < 1024) return `${bytes} B`;
    if (bytes < 1024 * 1024) return `${Math.round(bytes / 1024)} KB`;
    return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}

/* ------------------------------------------------------------------ */
/*  Dialog                                                             */
/* ------------------------------------------------------------------ */

export type LifecycleAction = 'submit' | 'review' | 'close' | 'reopen';

export function IncidentDetailDialog({
    detail,
    open,
    onClose,
}: {
    detail: IncidentDetail;
    open: boolean;
    onClose: () => void;
}) {
    const [section, setSection] = useState<SectionKey>('overview');
    const [action, setAction] = useState<LifecycleAction | null>(null);
    const [editing, setEditing] = useState(false);

    const d = detail;
    const incidentRef = d.ref ?? `INC-${d.id}`;
    const clientName = d.client
        ? `${d.client.first_name} ${d.client.last_name}`.trim()
        : 'No client linked';
    const isNearMiss = d.type === 'near_miss';
    const openFollowups = d.followups.filter((f) => !f.completed_at).length;

    const SECTIONS: {
        key: SectionKey;
        label: string;
        blurb: string;
        icon: ComponentType<{ className?: string }>;
    }[] = [
        {
            key: 'overview',
            label: 'Overview',
            blurb: 'What happened',
            icon: FileText,
        },
        {
            key: 'timeline',
            label: 'Timeline',
            blurb: 'Audit trail',
            icon: Clock,
        },
        {
            key: 'photos',
            label: 'Official incident attachments',
            blurb: `${d.attachments.length} file${d.attachments.length === 1 ? '' : 's'}`,
            icon: Paperclip,
        },
        {
            key: 'followups',
            label: 'Incident follow-ups',
            blurb: openFollowups > 0 ? `${openFollowups} open` : 'all complete',
            icon: ListTodo,
        },
        {
            key: 'investigation',
            label: 'Investigation',
            blurb: d.hs_event ? d.hs_event.reference_number : 'no H&S event',
            icon: Search,
        },
        {
            key: 'linked',
            label: 'Linked records',
            blurb: 'CR · H&S · client',
            icon: LinkIcon,
        },
    ];
    const stepIndex = SECTIONS.findIndex((s) => s.key === section);

    // Endpoints return back() -> Inertia follows the redirect to the current
    // URL (which still carries ?incident=), so the dialog + list refresh together.
    const completeFollowup = (fid: number) =>
        router.post(
            `/incidents/${d.id}/followups/${fid}/complete`,
            {},
            { preserveScroll: true },
        );

    // While an action / edit pane is open it owns the body + its own buttons,
    // so the Options bar is suppressed.
    const footerEnd =
        action || editing ? null : (
            <div className="flex flex-wrap items-center gap-2">
                <Link
                    href={`/incidents/${d.id}`}
                    className="inline-flex items-center gap-1.5 rounded-lg px-3 py-2 text-sm font-medium text-muted-foreground transition-colors hover:bg-muted"
                >
                    <ExternalLink className="h-4 w-4" /> Open full page
                </Link>
                {d.can.update && d.status === 'draft' ? (
                    <Button
                        size="sm"
                        variant="outline"
                        onClick={() => setEditing(true)}
                    >
                        <Pencil className="mr-1.5 h-4 w-4" /> Edit
                    </Button>
                ) : null}
                {d.can.submit && d.status === 'draft' ? (
                    <Button size="sm" onClick={() => setAction('submit')}>
                        <Send className="mr-1.5 h-4 w-4" /> Submit for review
                    </Button>
                ) : null}
                {d.can.review && d.status === 'submitted' ? (
                    <Button size="sm" onClick={() => setAction('review')}>
                        <CheckCircle2 className="mr-1.5 h-4 w-4" /> Review
                    </Button>
                ) : null}
                {d.can.close && d.status === 'reviewed' ? (
                    <Button size="sm" onClick={() => setAction('close')}>
                        <CheckCircle2 className="mr-1.5 h-4 w-4" /> Close
                    </Button>
                ) : null}
                {d.can.reopen && d.status === 'closed' ? (
                    <Button
                        size="sm"
                        variant="outline"
                        onClick={() => setAction('reopen')}
                    >
                        <RotateCcw className="mr-1.5 h-4 w-4" /> Reopen
                    </Button>
                ) : null}
            </div>
        );

    const footerStart = (
        <div className="flex items-center gap-2 text-xs">
            <span
                className={`inline-flex items-center gap-1 rounded-full bg-muted px-2 py-0.5 font-medium`}
            >
                <span
                    className={`h-1.5 w-1.5 rounded-full ${DOT[SEV_TONE[d.severity] ?? 'neutral']}`}
                />
                {SEV_LABEL[d.severity] ?? d.severity}
            </span>
            <span className="text-muted-foreground">{d.journey_state}</span>
        </div>
    );

    return (
        <WizardShell
            open={open}
            onClose={onClose}
            title={`Incident ${incidentRef}`}
            description={`${titleCase(d.type)} — ${clientName}`}
            railIcon={isNearMiss ? ShieldAlert : AlertTriangle}
            railTitle={clientName}
            railSub={`${incidentRef} · ${titleCase(d.type)}`}
            steps={SECTIONS}
            stepIndex={stepIndex}
            onStepClick={(i) => setSection(SECTIONS[i].key)}
            headerLabel={
                editing
                    ? 'Edit incident'
                    : action
                      ? ACTION_META[action].title
                      : (SECTIONS[stepIndex]?.label ?? 'Overview')
            }
            footerStart={footerStart}
            footerEnd={footerEnd}
        >
            {editing ? (
                <EditPane d={d} onDone={() => setEditing(false)} />
            ) : action ? (
                <ActionPane
                    d={d}
                    action={action}
                    onDone={() => setAction(null)}
                />
            ) : (
                <>
                    {section === 'overview' ? (
                        <OverviewSection d={d} isNearMiss={isNearMiss} />
                    ) : null}
                    {section === 'timeline' ? <TimelineSection d={d} /> : null}
                    {section === 'photos' ? <PhotosSection d={d} /> : null}
                    {section === 'followups' ? (
                        <FollowupsSection d={d} onComplete={completeFollowup} />
                    ) : null}
                    {section === 'investigation' ? (
                        <InvestigationSection d={d} />
                    ) : null}
                    {section === 'linked' ? (
                        <LinkedSection d={d} clientName={clientName} />
                    ) : null}
                </>
            )}
        </WizardShell>
    );
}

/* ------------------------------------------------------------------ */
/*  Lifecycle action pane (review / close / reopen)                    */
/* ------------------------------------------------------------------ */

export const ACTION_META: Record<
    LifecycleAction,
    {
        title: string;
        blurb: string;
        icon: ComponentType<{ className?: string }>;
        cta: string;
    }
> = {
    submit: {
        title: 'Submit for review',
        blurb: 'Sends the incident to a reviewer and locks the draft for audit — editing and attachments close once submitted.',
        icon: Send,
        cta: 'Submit incident',
    },
    review: {
        title: 'Review incident',
        blurb: 'Mark this incident as reviewed and add any notes.',
        icon: CheckCircle2,
        cta: 'Mark reviewed',
    },
    close: {
        title: 'Close incident',
        blurb: 'Close is available only after review, follow-ups, required investigation, and linked H&S governance are complete.',
        icon: CheckCircle2,
        cta: 'Close incident',
    },
    reopen: {
        title: 'Reopen incident',
        blurb: 'Reopen a closed incident — a reason is required for the audit trail.',
        icon: RotateCcw,
        cta: 'Reopen incident',
    },
};

export function ActionPane({
    d,
    action,
    onDone,
    onCancel = onDone,
    onDraftStateChange,
    confirmCurrentIncident = false,
}: {
    d: IncidentDetail;
    action: LifecycleAction;
    onDone: () => void;
    onCancel?: () => void;
    onDraftStateChange?: (state: { dirty: boolean; busy: boolean }) => void;
    confirmCurrentIncident?: boolean;
}) {
    const [incidentId] = useState(d.id);
    const meta = ACTION_META[action];
    const currentActorId = (
        usePage().props.auth as { user?: { id?: number } } | undefined
    )?.user?.id;
    const [actorId] = useState(currentActorId);
    const [sending, setSending] = useState(false);
    const inFlight = useRef(false);
    const [unconfirmedWork, setUnconfirmedWork] = useState(false);
    const [confirmationError, setConfirmationError] = useState<string | null>(
        null,
    );
    const fieldId = useId();
    const form = useForm<{
        review_notes: string;
        closed_outcome: string;
        closed_notes: string;
        reopened_reason: string;
    }>({
        review_notes: '',
        closed_outcome: '',
        closed_notes: '',
        reopened_reason: '',
    });
    const busy = form.processing || sending;
    const requiredStatus: Record<LifecycleAction, string> = {
        submit: 'draft',
        review: 'submitted',
        close: 'reviewed',
        reopen: 'closed',
    };
    const resultingStatus: Record<LifecycleAction, string> = {
        submit: 'submitted',
        review: 'reviewed',
        close: 'closed',
        reopen: 'reviewed',
    };
    const canPerform =
        d.id === incidentId &&
        d.can[action] === true &&
        d.status === requiredStatus[action] &&
        typeof actorId === 'number' &&
        Number.isSafeInteger(actorId) &&
        actorId > 0 &&
        actorId === currentActorId;
    const ready =
        canPerform &&
        (action !== 'close' ||
            (d.close_gate.allowed &&
                Boolean(form.data.closed_outcome.trim()))) &&
        (action !== 'reopen' || Boolean(form.data.reopened_reason.trim()));
    useEffect(() => {
        // Successful redirects adopt posted form defaults even when their
        // application-level result is unavailable. Keep dismissal guarded.
        onDraftStateChange?.({ dirty: form.isDirty || unconfirmedWork, busy });
    }, [form.isDirty, unconfirmedWork, busy, onDraftStateChange]);

    const submit = (e: FormEvent) => {
        e.preventDefault();
        if (!ready || busy || inFlight.current) return;
        inFlight.current = true;
        setSending(true);
        setUnconfirmedWork(true);
        setConfirmationError(null);
        const url = `/incidents/${incidentId}/${action}`;
        try {
            form.post(url, {
                preserveScroll: true,
                // Guardrail failures come back as flash.error on a 302 (Inertia onSuccess),
                // not a 422 — keep the pane open in that case so the user can adjust.
                onSuccess: (page) => {
                    const props = page.props as {
                        flash?: { error?: unknown };
                        viewer_id?: number;
                        auth?: { user?: { id?: number } };
                        detail?: { id?: number; status?: string };
                    };
                    if (props.flash?.error) {
                        setConfirmationError(
                            typeof props.flash.error === 'string'
                                ? props.flash.error
                                : 'The incident change could not be confirmed. Your entries have been kept.',
                        );
                        return;
                    }
                    if (
                        confirmCurrentIncident &&
                        (props.viewer_id !== actorId ||
                            props.auth?.user?.id !== actorId ||
                            props.detail?.id !== incidentId ||
                            props.detail?.status !== resultingStatus[action])
                    ) {
                        setConfirmationError(
                            'The incident change could not be confirmed. Your entries have been kept. Check the current incident before trying again.',
                        );
                        return;
                    }
                    onDone();
                },
                onFinish: () => {
                    inFlight.current = false;
                    setSending(false);
                },
            });
        } catch {
            inFlight.current = false;
            setSending(false);
            setConfirmationError(
                'The incident change could not be confirmed. Your entries have been kept. Check the current incident before trying again.',
            );
        }
    };

    return (
        <form
            onSubmit={submit}
            aria-busy={busy}
            className="flex flex-col gap-4"
        >
            <StepHead icon={meta.icon} title={meta.title} blurb={meta.blurb} />

            {confirmationError ? (
                <InfoCard icon={AlertTriangle} tone="crit">
                    <p role="alert">{confirmationError}</p>
                </InfoCard>
            ) : null}
            {!canPerform ? (
                <p
                    role="status"
                    className="text-subtle text-status-warning-foreground"
                >
                    This action is no longer available for the current incident.
                    Your entries have been kept.
                </p>
            ) : null}
            {confirmCurrentIncident && (confirmationError || !canPerform) ? (
                <Button
                    type="button"
                    variant="outline"
                    className="frontline-tap self-start"
                    disabled={busy}
                    onClick={() =>
                        router.reload({
                            only: ['viewer_id', 'detail'],
                            preserveState: true,
                            preserveScroll: true,
                        })
                    }
                >
                    Check current incident
                </Button>
            ) : null}

            <fieldset
                disabled={busy || !canPerform}
                className="flex min-w-0 flex-col gap-4"
            >
                <legend className="sr-only">{meta.title}</legend>

                {action === 'submit' ? (
                    <>
                        <ReviewCard
                            icon={FileText}
                            title="What you're submitting"
                            span
                        >
                            <ReviewRow
                                label="Incident"
                                value={`${d.ref ?? `INC-${d.id}`} · ${titleCase(d.type)}`}
                            />
                            <ReviewRow
                                label="Client"
                                value={
                                    d.client
                                        ? `${d.client.first_name} ${d.client.last_name}`
                                        : undefined
                                }
                            />
                            <ReviewRow
                                label="Severity"
                                value={SEV_LABEL[d.severity] ?? d.severity}
                            />
                            <ReviewRow
                                label="What happened"
                                value={d.description}
                            />
                            <ReviewRow
                                label="Attachments"
                                value={`${d.attachments.length}`}
                            />
                        </ReviewCard>
                        <InfoCard icon={Send} tone="info">
                            Check the details above — after submitting, the
                            record locks and changes need a reviewer. Photos and
                            documents can no longer be added.
                        </InfoCard>
                    </>
                ) : null}

                {action === 'review' ? (
                    <Field
                        label="Review notes"
                        hint="Optional"
                        error={form.errors.review_notes}
                        errorId={`${fieldId}-review-error`}
                    >
                        <Textarea
                            rows={4}
                            value={form.data.review_notes}
                            aria-invalid={Boolean(form.errors.review_notes)}
                            aria-describedby={
                                form.errors.review_notes
                                    ? `${fieldId}-review-error`
                                    : undefined
                            }
                            onChange={(e) =>
                                form.setData('review_notes', e.target.value)
                            }
                            placeholder="Notes from your review…"
                        />
                    </Field>
                ) : null}

                {action === 'close' ? (
                    <>
                        <JourneyGateList gate={d.close_gate} />
                        <Field
                            label="Outcome"
                            required
                            error={form.errors.closed_outcome}
                            errorId={`${fieldId}-outcome-error`}
                        >
                            <Input
                                value={form.data.closed_outcome}
                                maxLength={120}
                                aria-required
                                className="frontline-tap"
                                aria-invalid={Boolean(
                                    form.errors.closed_outcome,
                                )}
                                aria-describedby={
                                    form.errors.closed_outcome
                                        ? `${fieldId}-outcome-error`
                                        : undefined
                                }
                                onChange={(e) =>
                                    form.setData(
                                        'closed_outcome',
                                        e.target.value,
                                    )
                                }
                                placeholder="e.g. Resolved — care plan updated"
                            />
                        </Field>
                        <Field
                            label="Closing notes"
                            hint="Optional"
                            error={form.errors.closed_notes}
                            errorId={`${fieldId}-closing-error`}
                        >
                            <Textarea
                                rows={3}
                                value={form.data.closed_notes}
                                aria-invalid={Boolean(form.errors.closed_notes)}
                                aria-describedby={
                                    form.errors.closed_notes
                                        ? `${fieldId}-closing-error`
                                        : undefined
                                }
                                onChange={(e) =>
                                    form.setData('closed_notes', e.target.value)
                                }
                            />
                        </Field>
                    </>
                ) : null}

                {action === 'reopen' ? (
                    <Field
                        label="Reason for reopening"
                        required
                        error={form.errors.reopened_reason}
                        errorId={`${fieldId}-reopen-error`}
                    >
                        <Textarea
                            rows={4}
                            value={form.data.reopened_reason}
                            maxLength={2000}
                            aria-required
                            aria-invalid={Boolean(form.errors.reopened_reason)}
                            aria-describedby={
                                form.errors.reopened_reason
                                    ? `${fieldId}-reopen-error`
                                    : undefined
                            }
                            onChange={(e) =>
                                form.setData('reopened_reason', e.target.value)
                            }
                            placeholder="Why is this incident being reopened?"
                        />
                    </Field>
                ) : null}
            </fieldset>

            <div className="flex justify-end gap-2">
                <Button
                    type="button"
                    variant="outline"
                    className="frontline-tap"
                    onClick={() => {
                        if (!busy) onCancel();
                    }}
                    disabled={busy}
                >
                    Cancel
                </Button>
                <Button
                    type="submit"
                    className="frontline-tap"
                    disabled={busy || !ready}
                >
                    {busy ? 'Saving…' : meta.cta}
                </Button>
            </div>
        </form>
    );
}

/* ------------------------------------------------------------------ */
/*  Edit pane (core fields — drafts only)                              */
/* ------------------------------------------------------------------ */

const INCIDENT_TYPES = [
    { value: 'injury', label: 'Injury' },
    { value: 'fall', label: 'Fall' },
    { value: 'behaviour', label: 'Behaviour' },
    { value: 'medication', label: 'Medication' },
    { value: 'safeguarding', label: 'Safeguarding' },
    { value: 'near_miss', label: 'Near miss' },
    { value: 'property_damage', label: 'Property damage' },
    { value: 'missing_person', label: 'Missing person' },
    { value: 'complaint', label: 'Complaint' },
    { value: 'other', label: 'Other' },
];
const SEVERITY_OPTIONS = [
    { value: 'low', label: 'Low' },
    { value: 'medium', label: 'Medium' },
    { value: 'high', label: 'High' },
    { value: 'critical', label: 'Critical' },
];
const POTENTIAL_OPTIONS = SEVERITY_OPTIONS;

export function EditPane({
    d,
    onDone,
    onCancel = onDone,
    onDraftStateChange,
    confirmCurrentIncident = false,
}: {
    d: IncidentDetail;
    onDone: () => void;
    onCancel?: () => void;
    onDraftStateChange?: (state: { dirty: boolean; busy: boolean }) => void;
    confirmCurrentIncident?: boolean;
}) {
    const [incidentId] = useState(d.id);
    const [originalType] = useState(d.type);
    const typeOptions =
        originalType &&
        !INCIDENT_TYPES.some(({ value }) => value === originalType)
            ? [
                  {
                      value: originalType,
                      label: `${titleCase(originalType)} (saved type)`,
                  },
                  ...INCIDENT_TYPES,
              ]
            : INCIDENT_TYPES;
    const currentActorId = (
        usePage().props.auth as { user?: { id?: number } } | undefined
    )?.user?.id;
    const [actorId] = useState(currentActorId);
    const [sending, setSending] = useState(false);
    const inFlight = useRef(false);
    const [unconfirmedWork, setUnconfirmedWork] = useState(false);
    const [confirmationError, setConfirmationError] = useState<string | null>(
        null,
    );
    const fieldId = useId();
    const form = useForm({
        type: d.type,
        severity: d.severity,
        occurred_at: toDatetimeLocal(d.occurred_at),
        description: d.description ?? '',
        immediate_action_taken: d.immediate_action_taken ?? '',
        witnesses: d.witnesses ?? '',
        potential_severity: d.potential_severity ?? '',
        potential_consequence: d.potential_consequence ?? '',
        // Unknown/restricted values are omitted from this JSON update, never
        // replaced with an unchecked false value from a source the user cannot read.
        is_notifiable: d.is_notifiable ?? undefined,
    });
    const isNearMiss = form.data.type === 'near_miss';
    const busy = form.processing || sending;
    const canEdit =
        d.id === incidentId &&
        d.status === 'draft' &&
        d.can.update === true &&
        typeof actorId === 'number' &&
        Number.isSafeInteger(actorId) &&
        actorId > 0 &&
        actorId === currentActorId;
    const validOccurredAt =
        form.data.occurred_at === '' ||
        validLocalDateTime(form.data.occurred_at);
    useEffect(() => {
        onDraftStateChange?.({ dirty: form.isDirty || unconfirmedWork, busy });
    }, [form.isDirty, unconfirmedWork, busy, onDraftStateChange]);

    const submit = (e: FormEvent) => {
        e.preventDefault();
        if (!canEdit || !validOccurredAt || busy || inFlight.current) return;
        inFlight.current = true;
        setSending(true);
        setUnconfirmedWork(true);
        setConfirmationError(null);
        try {
            form.put(`/incidents/${incidentId}`, {
                preserveScroll: true,
                onSuccess: (page) => {
                    const props = page.props as {
                        flash?: { error?: unknown };
                        viewer_id?: number;
                        auth?: { user?: { id?: number } };
                        detail?: { id?: number; status?: string };
                    };
                    if (props.flash?.error) {
                        setConfirmationError(
                            typeof props.flash.error === 'string'
                                ? props.flash.error
                                : 'The draft update could not be confirmed. Your entries have been kept.',
                        );
                        return;
                    }
                    if (
                        confirmCurrentIncident &&
                        (props.viewer_id !== actorId ||
                            props.auth?.user?.id !== actorId ||
                            props.detail?.id !== incidentId ||
                            props.detail?.status !== 'draft')
                    ) {
                        setConfirmationError(
                            'The draft update could not be confirmed. Your entries have been kept. Check the current incident before trying again.',
                        );
                        return;
                    }
                    onDone();
                },
                onFinish: () => {
                    inFlight.current = false;
                    setSending(false);
                },
            });
        } catch {
            inFlight.current = false;
            setSending(false);
            setConfirmationError(
                'The draft update could not be confirmed. Your entries have been kept. Check the current incident before trying again.',
            );
        }
    };

    return (
        <form
            onSubmit={submit}
            aria-busy={busy}
            className="flex flex-col gap-4"
        >
            <StepHead
                icon={Pencil}
                title="Edit incident"
                blurb="Update the incident details. Drafts only — once submitted, the record is locked for audit."
            />
            {confirmationError ? (
                <p
                    role="alert"
                    className="text-subtle text-status-warning-foreground"
                >
                    {confirmationError}
                </p>
            ) : null}
            {!canEdit ? (
                <p
                    role="status"
                    className="text-subtle text-status-warning-foreground"
                >
                    This incident draft is no longer available for editing. Your
                    entries have been kept.
                </p>
            ) : null}
            {confirmCurrentIncident && (confirmationError || !canEdit) ? (
                <Button
                    type="button"
                    variant="outline"
                    className="frontline-tap self-start"
                    disabled={busy}
                    onClick={() =>
                        router.reload({
                            only: ['viewer_id', 'detail'],
                            preserveState: true,
                            preserveScroll: true,
                        })
                    }
                >
                    Check current incident
                </Button>
            ) : null}
            <fieldset
                disabled={busy || !canEdit}
                className="flex min-w-0 flex-col gap-4"
            >
                <legend className="sr-only">Incident draft details</legend>
                <div className="grid gap-3 sm:grid-cols-2">
                    <Field label="Type" required error={form.errors.type}>
                        <SelectInput
                            value={form.data.type}
                            onChange={(v) => form.setData('type', v)}
                            placeholder="Select type"
                            options={typeOptions}
                            frontline
                            disabled={busy || !canEdit}
                        />
                    </Field>
                    <Field
                        label="Severity"
                        required
                        error={form.errors.severity}
                    >
                        <SelectInput
                            value={form.data.severity}
                            onChange={(v) => form.setData('severity', v)}
                            placeholder="Select severity"
                            options={SEVERITY_OPTIONS}
                            frontline
                            disabled={busy || !canEdit}
                        />
                    </Field>
                </div>
                <DateTimeField
                    key={busy || !canEdit ? 'locked' : 'available'}
                    compact
                    id={`${fieldId}-occurred`}
                    label="When it occurred"
                    value={form.data.occurred_at}
                    hint="Date and time in New Zealand. Clear both if the occurrence time is unknown."
                    error={form.errors.occurred_at}
                    onChange={(value) => {
                        if (canEdit && !busy)
                            form.setData('occurred_at', value);
                    }}
                />
                {!validOccurredAt ? (
                    <p
                        role="status"
                        className="text-subtle text-status-warning-foreground"
                    >
                        Choose both an occurrence date and time, or clear both
                        if unknown.
                    </p>
                ) : null}
                <Field
                    label="What happened"
                    error={form.errors.description}
                    errorId={`${fieldId}-description-error`}
                >
                    <Textarea
                        rows={4}
                        value={form.data.description}
                        aria-invalid={Boolean(form.errors.description)}
                        aria-describedby={
                            form.errors.description
                                ? `${fieldId}-description-error`
                                : undefined
                        }
                        onChange={(e) =>
                            form.setData('description', e.target.value)
                        }
                    />
                </Field>
                <Field
                    label="Immediate action taken"
                    error={form.errors.immediate_action_taken}
                    errorId={`${fieldId}-immediate-error`}
                >
                    <Textarea
                        rows={3}
                        value={form.data.immediate_action_taken}
                        aria-invalid={Boolean(
                            form.errors.immediate_action_taken,
                        )}
                        aria-describedby={
                            form.errors.immediate_action_taken
                                ? `${fieldId}-immediate-error`
                                : undefined
                        }
                        onChange={(e) =>
                            form.setData(
                                'immediate_action_taken',
                                e.target.value,
                            )
                        }
                    />
                </Field>
                <Field
                    label="Witnesses"
                    error={form.errors.witnesses}
                    errorId={`${fieldId}-witnesses-error`}
                >
                    <Input
                        value={form.data.witnesses}
                        className="frontline-tap"
                        aria-invalid={Boolean(form.errors.witnesses)}
                        aria-describedby={
                            form.errors.witnesses
                                ? `${fieldId}-witnesses-error`
                                : undefined
                        }
                        onChange={(e) =>
                            form.setData('witnesses', e.target.value)
                        }
                        placeholder="Names of any witnesses"
                    />
                </Field>
                {isNearMiss ? (
                    <div className="grid gap-3 sm:grid-cols-2">
                        <Field
                            label="Potential severity"
                            error={form.errors.potential_severity}
                        >
                            <SelectInput
                                value={form.data.potential_severity}
                                onChange={(v) =>
                                    form.setData('potential_severity', v)
                                }
                                placeholder="What could have happened"
                                options={POTENTIAL_OPTIONS}
                                frontline
                                disabled={busy || !canEdit}
                            />
                        </Field>
                        <Field
                            label="Could have caused"
                            error={form.errors.potential_consequence}
                        >
                            <Input
                                value={form.data.potential_consequence}
                                className="frontline-tap"
                                onChange={(e) =>
                                    form.setData(
                                        'potential_consequence',
                                        e.target.value,
                                    )
                                }
                            />
                        </Field>
                    </div>
                ) : null}
                {d.is_notifiable !== null ? (
                    <label className="frontline-tap flex items-center gap-2 text-sm text-foreground">
                        <input
                            type="checkbox"
                            checked={form.data.is_notifiable}
                            onChange={(e) =>
                                form.setData('is_notifiable', e.target.checked)
                            }
                            className="h-4 w-4 rounded border-border"
                        />
                        WorkSafe NZ notifiable event
                    </label>
                ) : null}
            </fieldset>
            <div className="flex justify-end gap-2">
                <Button
                    type="button"
                    variant="outline"
                    className="frontline-tap"
                    onClick={() => {
                        if (!busy) onCancel();
                    }}
                    disabled={busy}
                >
                    Cancel
                </Button>
                <Button
                    type="submit"
                    className="frontline-tap"
                    disabled={busy || !canEdit || !validOccurredAt}
                >
                    {busy ? 'Saving…' : 'Save changes'}
                </Button>
            </div>
        </form>
    );
}

/* ------------------------------------------------------------------ */
/*  Sections                                                           */
/* ------------------------------------------------------------------ */

/**
 * The incident's WorkSafe NZ duty from its canonical source (the linked H&S
 * event when there is one), with the duty sentence exactly as the incident
 * workflow words it.
 */
export function incidentWorksafe(d: IncidentDetail) {
    const worksafe = d.hs_event
        ? {
              notifiable: d.hs_event.worksafe_notifiable,
              status: d.hs_event.worksafe_status,
              reference: d.hs_event.worksafe_reference,
              notifiedAt: d.hs_event.worksafe_notified_at,
              acknowledgedAt: d.hs_event.worksafe_acknowledged_at,
          }
        : {
              notifiable: d.is_notifiable,
              status: d.worksafe_notification_status,
              reference: d.worksafe_reference,
              notifiedAt: d.worksafe_notified_at,
              acknowledgedAt: null,
          };
    const notified =
        worksafe.status === 'notified' || worksafe.status === 'acknowledged';
    const text =
        worksafe.status === 'acknowledged'
            ? `Acknowledged by WorkSafe${worksafe.acknowledgedAt ? ` ${formatDateTime(worksafe.acknowledgedAt)}` : ''}${worksafe.reference ? ` · ref ${worksafe.reference}` : ''}.`
            : notified
              ? `Notified${worksafe.notifiedAt ? ` ${formatDateTime(worksafe.notifiedAt)}` : ''}${worksafe.reference ? ` · ref ${worksafe.reference}` : ''} — awaiting acknowledgement.`
              : 'Notification to WorkSafe NZ is still pending with Health & Safety.';
    return { ...worksafe, notified, text };
}

/** The safeguarding concern raised from this incident — the readable one first. */
export function safeguardingEscalation(d: IncidentDetail) {
    const concerns = d.safeguarding_concerns ?? [];
    return concerns.length
        ? (concerns.find((c) => c.can_view) ?? concerns[0])
        : null;
}

/** The escalation sentence, word for word; need-to-know when the viewer can't open it. */
export function SafeguardingEscalationText({ d }: { d: IncidentDetail }) {
    const escalation = safeguardingEscalation(d);
    if (!escalation) return null;
    return (
        <>
            <span className="font-semibold">Escalated to safeguarding.</span>{' '}
            {escalation.can_view ? (
                <>
                    Concern {escalation.reference_number}
                    {escalation.status
                        ? ` · ${titleCase(escalation.status)}`
                        : ''}
                    .{' '}
                    <Link
                        href={`/safeguarding/${escalation.id}`}
                        className="font-medium text-primary hover:underline"
                    >
                        Open concern
                    </Link>
                </>
            ) : (
                'A safeguarding concern was raised from this incident (restricted — need-to-know).'
            )}
        </>
    );
}

export function OverviewSection({
    d,
    isNearMiss,
    part = 'all',
}: {
    d: IncidentDetail;
    isNearMiss: boolean;
    part?: 'all' | 'summary' | 'details';
}) {
    const escalation = safeguardingEscalation(d);
    const worksafe = incidentWorksafe(d);
    const handover = d.hs_event?.handover ?? null;
    return (
        <div className="grid gap-4 sm:grid-cols-2">
            {part !== 'details' ? (
                <>
                    <div className="flex justify-end sm:col-span-2">
                        <JourneyTermHelp
                            terms={['status', 'severity', 'priority']}
                            label="Explain incident status terms"
                        />
                    </div>
                    {escalation ? (
                        <div className="sm:col-span-2">
                            <InfoCard icon={ShieldAlert} tone="warn">
                                <SafeguardingEscalationText d={d} />
                            </InfoCard>
                        </div>
                    ) : null}

                    {worksafe.notifiable ? (
                        <div className="sm:col-span-2">
                            <InfoCard icon={ShieldAlert} tone="crit">
                                <span className="font-semibold">
                                    WorkSafe NZ notifiable event.
                                </span>{' '}
                                {worksafe.text}
                            </InfoCard>
                        </div>
                    ) : null}

                    <ReviewCard icon={FileText} title="What happened" span>
                        <p className="text-sm whitespace-pre-wrap text-foreground">
                            {d.description || '—'}
                        </p>
                    </ReviewCard>

                    <ReviewCard
                        icon={CheckCircle2}
                        title="Immediate actions"
                        span
                    >
                        <p className="text-sm whitespace-pre-wrap text-foreground">
                            {d.immediate_action_taken || '—'}
                        </p>
                    </ReviewCard>
                </>
            ) : null}
            {part !== 'summary' ? (
                <>
                    {handover ? (
                        <ReviewCard icon={RadioTower} title="H&S handover" span>
                            <ReviewRow
                                label="Status"
                                value={handoverStatusLabel(handover.status)}
                            />
                            <ReviewRow
                                label="H&S owner"
                                value={
                                    handover.owner?.name ??
                                    'No H&S owner assigned'
                                }
                            />
                            <ReviewRow
                                label="Accepted by"
                                value={handover.accepted_by?.name}
                            />
                            <ReviewRow
                                label="Accepted at"
                                value={
                                    handover.accepted_at
                                        ? formatDateTime(handover.accepted_at)
                                        : undefined
                                }
                            />
                            <ReviewRow
                                label="Acceptance notes"
                                value={handover.notes}
                            />
                        </ReviewCard>
                    ) : null}

                    <ReviewCard icon={Users} title="People">
                        <ReviewRow
                            label="Client"
                            value={
                                d.client
                                    ? `${d.client.first_name} ${d.client.last_name}`
                                    : undefined
                            }
                        />
                        <ReviewRow
                            label="Reported by"
                            value={d.reporter?.name}
                        />
                        <ReviewRow label="Witnesses" value={d.witnesses} />
                    </ReviewCard>

                    <ReviewCard icon={Search} title="Classification">
                        <ReviewRow label="Source" value={titleCase(d.source)} />
                        <ReviewRow
                            label={
                                isNearMiss ? 'Potential severity' : 'Severity'
                            }
                            value={
                                isNearMiss
                                    ? d.potential_severity
                                        ? SEV_LABEL[d.potential_severity]
                                        : undefined
                                    : SEV_LABEL[d.severity]
                            }
                        />
                        {isNearMiss ? (
                            <ReviewRow
                                label="Could have caused"
                                value={d.potential_consequence}
                            />
                        ) : null}
                        <ReviewRow
                            label="H&S event"
                            value={
                                d.linked_access?.health_safety === 'restricted'
                                    ? 'Access restricted'
                                    : d.hs_event?.reference_number
                            }
                        />
                    </ReviewCard>
                </>
            ) : null}
        </div>
    );
}

/* ------------------------------------------------------------------ */
/*  History                                                            */
/* ------------------------------------------------------------------ */

/** Every dated change to the incident, oldest first (drawing pg-hsn-2f). */
export function incidentHistoryItems(d: IncidentDetail): (LogItem & {
    at: string;
})[] {
    const events: (LogItem & { at: string })[] = [];
    const push = (
        at: string | null | undefined,
        title: string,
        tone?: SgTone,
        detail?: string | null,
    ) => {
        if (!at) return;
        events.push({
            at,
            time: formatTime(at),
            tone,
            title,
            sub: [detail, dateWithYear(at)].filter(Boolean).join(' · '),
        });
    };
    const alertRef = d.linked_operational_evidence?.source?.reference ?? null;
    push(d.occurred_at, 'Incident happened', 'none');
    push(
        d.control_room_alert?.triggered_at,
        'Control room alert raised',
        'critical',
        alertRef,
    );
    push(
        d.control_room_alert?.resolved_at,
        'Control room alert resolved',
        'success',
        alertRef,
    );
    push(d.submitted_at, 'Submitted for review', 'info', d.reporter?.name);
    for (const change of [...(d.ownership?.history ?? [])].reverse()) {
        const told = change.deliveries.some(
            (delivery) =>
                delivery.role === 'incoming' && delivery.status === 'delivered',
        );
        push(
            change.changed_at,
            change.previous_owner
                ? `Owner changed: ${change.previous_owner} → ${change.owner}`
                : `Owner assigned: ${change.owner}`,
            'info',
            `By ${change.changed_by}${told ? ' · told in the app' : ''}`,
        );
    }
    push(d.reviewed_at, 'Reviewed', 'success', d.review_notes);
    for (const attachment of d.attachments) {
        push(
            attachment.created_at,
            `File added: ${attachment.name}`,
            undefined,
            attachment.uploaded_by,
        );
    }
    for (const followup of d.followups) {
        const task = followup.notes || 'Follow-up task';
        if (followup.completed_at) {
            push(
                followup.completed_at,
                `Follow-up done: ${task}`,
                'success',
                followup.assigned_to,
            );
        } else if (followup.overdue) {
            push(
                followup.due_at,
                `Follow-up overdue: ${task}`,
                'critical',
                followup.assigned_to,
            );
        }
    }
    push(d.reopened_at, 'Reopened', 'warning', d.reopened_reason);
    push(d.closed_at, 'Closed', 'success', d.closed_outcome);
    return events.sort(
        (a, b) => new Date(a.at).getTime() - new Date(b.at).getTime(),
    );
}

export function TimelineSection({ d }: { d: IncidentDetail }) {
    const items = incidentHistoryItems(d);
    const first = items[0]?.at;
    const last = items.at(-1)?.at;
    if (!items.length)
        return (
            <QuietLine title="History" text="No timeline events yet." />
        );
    return (
        <Log
            title="History"
            sub={`Every change to this incident, newest last${first ? ` · ${dateWithYear(first)}${last && dateWithYear(last) !== dateWithYear(first) ? ` – ${dateWithYear(last)}` : ''}` : ''}`}
            items={items}
            limit={items.length}
        />
    );
}

/* ------------------------------------------------------------------ */
/*  Files                                                              */
/* ------------------------------------------------------------------ */

export function PhotosSection({ d }: { d: IncidentDetail }) {
    // Attachments are only mutable while the incident is a draft (server guardrail).
    const canEdit = d.status === 'draft' && d.can.update;
    const canPortal = d.can.portalManage;
    const [removing, setRemoving] = useState<
        IncidentDetail['attachments'][number] | null
    >(null);
    const togglePortal = (a: IncidentDetail['attachments'][number]) =>
        router.patch(
            `/incidents/${d.id}/attachments/${a.id}`,
            { portal_visible: !a.portal_visible },
            { preserveScroll: true },
        );
    return (
        <>
            <RecordTable
                title="Files"
                sub={
                    canPortal
                        ? 'Share puts a file on the family portal; Unshare takes it off · download from ⋮'
                        : 'Download from ⋮'
                }
                ariaLabel="Official incident attachments"
                rows={d.attachments}
                rowKey={(a) => a.id}
                menuTitle={(a) => a.name}
                menuIcon={FileText}
                actionsFor={(a) =>
                    compactMenu([
                        {
                            label: 'Download',
                            icon: Download,
                            onClick: () => window.location.assign(a.download_url),
                        },
                        canPortal && {
                            label: a.portal_visible ? 'Unshare' : 'Share',
                            icon: a.portal_visible ? EyeOff : Eye,
                            onClick: () => togglePortal(a),
                        },
                        canEdit && { separator: true },
                        canEdit && {
                            label: 'Remove',
                            icon: Trash2,
                            danger: true,
                            onClick: () => setRemoving(a),
                        },
                    ])
                }
                columns={[
                    {
                        key: 'file',
                        label: 'File',
                        cell: (a) => (
                            <RecordIdentity
                                leading={
                                    a.mime?.startsWith('image/') ? (
                                        <ImageIcon className="size-4" />
                                    ) : (
                                        <FileText className="size-4" />
                                    )
                                }
                                title={a.name}
                                sub={
                                    [fmtSize(a.size), a.notes]
                                        .filter(Boolean)
                                        .join(' · ') || undefined
                                }
                            />
                        ),
                    },
                    {
                        key: 'by',
                        label: 'Added by',
                        nowrap: true,
                        cell: (a) => a.uploaded_by ?? 'Not recorded',
                    },
                    {
                        key: 'added',
                        label: 'Added',
                        nowrap: true,
                        cell: (a) =>
                            a.created_at ? (
                                <RecordIdentity
                                    title={dateWithYear(a.created_at)}
                                    sub={formatTime(a.created_at)}
                                />
                            ) : (
                                'Not recorded'
                            ),
                    },
                    {
                        key: 'portal',
                        label: 'Family portal',
                        nowrap: true,
                        cell: (a) =>
                            a.portal_visible ? (
                                <StatusBadge variant="info">
                                    On the family portal
                                </StatusBadge>
                            ) : (
                                <StatusBadge variant="neutral">
                                    Not shared
                                </StatusBadge>
                            ),
                    },
                ]}
                empty={
                    <p className="sg-quiet">
                        <Ring tone="none" />
                        <span>
                            No photos or documents attached.
                            {!canEdit
                                ? ' Attachments can only be added while the incident is a draft.'
                                : ''}
                        </span>
                    </p>
                }
            >
                {canEdit ? (
                    <div className="border-b border-border p-4">
                        <AttachmentUploader
                            endpoint={`/incidents/${d.id}/attachments`}
                            hint="PDF, Word, images — up to 10 MB each"
                        />
                    </div>
                ) : null}
            </RecordTable>
            <ConfirmDialog
                open={removing !== null}
                onClose={() => setRemoving(null)}
                onConfirm={() => {
                    if (!removing) return;
                    router.delete(
                        `/incidents/${d.id}/attachments/${removing.id}`,
                        { preserveScroll: true },
                    );
                    setRemoving(null);
                }}
                title="Remove this file?"
                description={`${removing?.name ?? 'This file'} is removed from the incident draft. Evidence removal can't be undone.`}
                confirmText="Remove file"
            />
        </>
    );
}

/* ------------------------------------------------------------------ */
/*  Follow-ups                                                         */
/* ------------------------------------------------------------------ */

export function FollowupsSection({
    d,
    onComplete,
    onDraftStateChange,
}: {
    d: IncidentDetail;
    onComplete: (id: number) => void;
    onDraftStateChange?: (state: IncidentDraftState) => void;
}) {
    const [formOpen, setFormOpen] = useState(false);
    const [draft, setDraft] = useState({ dirty: false, busy: false });
    const closed = d.status === 'closed';
    const report = useCallback(
        (state: IncidentDraftState) => {
            setDraft({ dirty: state.dirty, busy: state.busy });
            onDraftStateChange?.(state);
        },
        [onDraftStateChange],
    );
    const statusOf = (f: IncidentDetail['followups'][number]) =>
        f.completed_at ? 'Completed' : f.overdue ? 'Overdue' : 'Open';
    return (
        <RecordTable
            title="Follow-ups"
            sub={
                d.can.followupsComplete
                    ? 'Each follow-up has a task, a person and a due date · mark one done from ⋮'
                    : 'Each follow-up has a task, a person and a due date'
            }
            ariaLabel="Incident follow-ups table"
            actions={
                d.can.followupsManage && !formOpen ? (
                    <Button
                        variant="outline"
                        size="sm"
                        className="frontline-hit"
                        disabled={closed || draft.busy}
                        onClick={() => setFormOpen(true)}
                    >
                        <Plus className="size-3.5" />
                        {draft.dirty ? 'Continue follow-up' : 'Add follow-up'}
                    </Button>
                ) : null
            }
            rows={d.followups}
            rowKey={(f) => f.id}
            menuTitle={(f) => f.notes || 'Follow-up task'}
            menuIcon={ListTodo}
            actionsFor={
                d.can.followupsComplete
                    ? (f) =>
                          f.completed_at
                              ? []
                              : [
                                    {
                                        label: 'Mark done',
                                        icon: CheckCircle2,
                                        onClick: () => onComplete(f.id),
                                    },
                                ]
                    : undefined
            }
            columns={[
                {
                    key: 'task',
                    label: 'Follow-up',
                    cell: (f) => (
                        <b className="font-semibold break-words whitespace-pre-wrap text-foreground">
                            {f.notes || 'Follow-up task'}
                        </b>
                    ),
                },
                {
                    key: 'assigned',
                    label: 'Assigned to',
                    nowrap: true,
                    cell: (f) =>
                        f.assigned_to ? (
                            <RecordPerson name={f.assigned_to} />
                        ) : (
                            <span className="text-muted-foreground">
                                Unassigned
                            </span>
                        ),
                },
                {
                    key: 'due',
                    label: 'Due',
                    nowrap: true,
                    cell: (f) =>
                        f.due_at ? (
                            <RecordIdentity
                                title={dateWithYear(f.due_at)}
                                sub={`${formatTime(f.due_at)}${f.completed_at ? ` · done at ${formatTime(f.completed_at)}` : ''}`}
                            />
                        ) : (
                            <span className="text-muted-foreground">
                                No due date
                                {f.completed_at
                                    ? ` · done ${formatDateTime(f.completed_at)}`
                                    : ''}
                            </span>
                        ),
                },
                {
                    key: 'status',
                    label: 'Status',
                    nowrap: true,
                    cell: (f) => (
                        <StatusBadge
                            variant={
                                f.completed_at
                                    ? 'success'
                                    : f.overdue
                                      ? 'critical'
                                      : 'warning'
                            }
                        >
                            {statusOf(f)}
                        </StatusBadge>
                    ),
                },
            ]}
            empty={
                <p className="sg-quiet">
                    <Ring tone="none" />
                    <span>No follow-ups on this incident.</span>
                </p>
            }
            footer={
                closed && d.can.followupsManage && !formOpen ? (
                    <p className="text-subtle">
                        Reopen the incident before adding a follow-up.
                    </p>
                ) : undefined
            }
        >
            {d.can.followupsManage ? (
                <AddFollowupForm
                    d={d}
                    open={formOpen}
                    onHide={() => setFormOpen(false)}
                    onDraftStateChange={report}
                />
            ) : null}
        </RecordTable>
    );
}

function AddFollowupForm({
    d,
    open,
    onHide,
    onDraftStateChange,
}: {
    d: IncidentDetail;
    open: boolean;
    onHide: () => void;
    onDraftStateChange?: (state: IncidentDraftState) => void;
}) {
    const assignmentId = useId();
    const assignmentLabelId = useId();
    const assignmentErrorId = useId();
    const notesErrorId = useId();
    const dueErrorId = useId();
    const dueId = useId();
    const form = useForm<{
        notes: string;
        assigned_to_user_id: string;
        due_at: string;
    }>({ notes: '', assigned_to_user_id: '', due_at: '' });
    const closed = d.status === 'closed';
    const unavailableSelection =
        Boolean(form.data.assigned_to_user_id) &&
        !d.assignable_staff.some(
            (staff) => String(staff.id) === form.data.assigned_to_user_id,
        );
    const incidentError = (form.errors as Record<string, string | undefined>)
        .incident;
    useEffect(() => {
        onDraftStateChange?.({
            dirty: Boolean(form.isDirty),
            busy: form.processing,
        });
    }, [form.isDirty, form.processing, onDraftStateChange]);
    useEffect(
        () => () => onDraftStateChange?.({ dirty: false, busy: false }),
        [onDraftStateChange],
    );

    // Hidden drafts stay mounted so their text survives section changes.
    if (!open) return null;

    const submit = (e: FormEvent) => {
        e.preventDefault();
        if (closed || form.processing) return;
        if (!form.data.notes.trim()) {
            form.setError('notes', 'Describe the follow-up task.');
            return;
        }
        form.post(`/incidents/${d.id}/followups`, {
            preserveScroll: true,
            onSuccess: (page) => {
                if ((page.props as { flash?: { error?: string } }).flash?.error)
                    return;
                form.reset();
                onHide();
            },
        });
    };

    return (
        <form
            onSubmit={submit}
            aria-label="Add a follow-up"
            className="flex flex-col gap-5 border-b border-border bg-muted/30 p-4"
        >
            {closed ? (
                <p
                    role="status"
                    className="text-subtle text-status-warning-foreground"
                >
                    This incident is closed. Your unsaved follow-up is retained
                    here; reopen the incident before adding it.
                </p>
            ) : null}
            {incidentError ? (
                <div role="alert">
                    <FieldErr>{incidentError}</FieldErr>
                </div>
            ) : null}
            <Field
                label="Task"
                required
                error={form.errors.notes}
                errorId={notesErrorId}
            >
                <Textarea
                    rows={2}
                    value={form.data.notes}
                    disabled={closed || form.processing}
                    aria-invalid={Boolean(form.errors.notes)}
                    aria-describedby={
                        form.errors.notes ? notesErrorId : undefined
                    }
                    onChange={(e) => form.setData('notes', e.target.value)}
                    placeholder="e.g. Update the care plan and notify the GP"
                />
            </Field>
            <div className="grid gap-3 sm:grid-cols-2">
                <Field
                    label="Assign to"
                    htmlFor={assignmentId}
                    labelId={assignmentLabelId}
                    error={form.errors.assigned_to_user_id}
                    errorId={assignmentErrorId}
                >
                    <div
                        role="group"
                        aria-labelledby={assignmentLabelId}
                        aria-invalid={Boolean(form.errors.assigned_to_user_id)}
                        aria-describedby={
                            form.errors.assigned_to_user_id
                                ? assignmentErrorId
                                : undefined
                        }
                        className="flex flex-col items-start gap-2"
                    >
                        <SelectInput
                            id={assignmentId}
                            frontline
                            ariaLabel="Assign to"
                            value={form.data.assigned_to_user_id}
                            disabled={closed || form.processing}
                            onChange={(v) =>
                                form.setData('assigned_to_user_id', v)
                            }
                            placeholder="Unassigned"
                            options={[
                                ...(unavailableSelection
                                    ? [
                                          {
                                              value: form.data
                                                  .assigned_to_user_id,
                                              label: 'Staff selection unavailable',
                                              disabled: true,
                                          },
                                      ]
                                    : []),
                                ...d.assignable_staff.map((s) => ({
                                    value: String(s.id),
                                    label: s.name,
                                })),
                            ]}
                        />
                        {form.data.assigned_to_user_id ? (
                            <Button
                                type="button"
                                variant="ghost"
                                size="sm"
                                className="frontline-tap"
                                disabled={closed || form.processing}
                                onClick={() =>
                                    form.setData('assigned_to_user_id', '')
                                }
                            >
                                Clear assignment
                            </Button>
                        ) : null}
                    </div>
                </Field>
                <Field
                    label="Due"
                    error={form.errors.due_at}
                    errorId={dueErrorId}
                >
                    <DatePicker
                        key={closed || form.processing ? 'locked' : 'available'}
                        compact
                        id={dueId}
                        label="Due"
                        value={form.data.due_at}
                        disabled={closed || form.processing}
                        invalid={Boolean(form.errors.due_at)}
                        describedBy={
                            form.errors.due_at ? dueErrorId : undefined
                        }
                        allowClear
                        onChange={(value) => {
                            if (!closed && !form.processing)
                                form.setData('due_at', value);
                        }}
                    />
                </Field>
            </div>
            <div className="flex flex-wrap justify-end gap-2">
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    className="frontline-tap"
                    onClick={onHide}
                >
                    Hide draft
                </Button>
                <Button
                    type="submit"
                    size="sm"
                    className="frontline-tap"
                    disabled={closed || form.processing}
                >
                    {form.processing ? 'Adding follow-up…' : 'Add follow-up'}
                </Button>
            </div>
        </form>
    );
}

/* ------------------------------------------------------------------ */
/*  Safety review                                                      */
/* ------------------------------------------------------------------ */

const METHODOLOGY_LABELS: Record<string, string> = {
    '5_whys': '5 Whys',
    fishbone: 'Fishbone (Ishikawa)',
    bow_tie: 'Bow-tie',
    icam: 'ICAM',
    taproot: 'TapRooT',
    other: 'Other',
};

const HS_STEPS = [
    ['open', 'Open'],
    ['investigating', 'Investigating'],
    ['corrective_action', 'Corrective action'],
    ['monitoring', 'Monitoring'],
    ['closed', 'Closed'],
] as const;

/** The handover from the incident to Health & safety, as label and value rows. */
export function HandoverFacts({ d }: { d: IncidentDetail }) {
    const handover = d.hs_event?.handover;
    if (!handover) return null;
    const rows: [string, string][] = [
        ['Status', handoverStatusLabel(handover.status)],
        ['H&S owner', handover.owner?.name ?? 'No H&S owner assigned'],
    ];
    if (handover.accepted_by) rows.push(['Accepted by', handover.accepted_by.name]);
    if (handover.accepted_at)
        rows.push(['Accepted at', formatDateTime(handover.accepted_at)]);
    if (handover.notes) rows.push(['Acceptance notes', handover.notes]);
    return <FactRows title="Handover to Health & safety" rows={rows} />;
}

export function InvestigationSection({
    d,
    onDraftStateChange,
}: {
    d: IncidentDetail;
    onDraftStateChange?: (state: IncidentDraftState) => void;
}) {
    const [formOpen, setFormOpen] = useState(false);
    const [draft, setDraft] = useState({ dirty: false, busy: false });
    const report = useCallback(
        (state: IncidentDraftState) => {
            setDraft({ dirty: state.dirty, busy: state.busy });
            onDraftStateChange?.(state);
        },
        [onDraftStateChange],
    );
    if (!d.hs_event) {
        return (
            <QuietLine
                title="Safety review"
                text={
                    d.linked_access?.health_safety === 'restricted'
                        ? 'This incident has a linked Health & Safety record. Your access does not include its details.'
                        : d.linked_access?.health_safety === 'absent'
                          ? 'No Health & Safety event recorded for this incident.'
                          : 'The safety review is unavailable. Reload the incident to check its current connection.'
                }
            />
        );
    }
    const ev = d.hs_event;
    const inv = ev.investigation;
    const index = HS_STEPS.findIndex(([key]) => key === ev.status);
    const steps: JourneyStep[] = HS_STEPS.map(([key, label], i) => ({
        label,
        state:
            ev.status === 'closed' || (index >= 0 && i < index)
                ? ('done' as const)
                : i === index
                  ? ('now' as const)
                  : ('todo' as const),
        meta:
            i === index && ev.status !== 'closed'
                ? handoverStatusLabel(ev.handover.status)
                : undefined,
    }));
    const owner = ev.handover.owner?.name;
    return (
        <div className="grid min-w-0 gap-5">
            <Journey
                title={`Safety review ${ev.reference_number}`}
                sub={[
                    HS_STATUS_LABELS[ev.status] ?? titleCase(ev.status),
                    owner ? `owner ${owner}` : null,
                    ev.investigation_required ? 'investigation required' : null,
                ]
                    .filter(Boolean)
                    .join(' · ')}
                action={
                    ev.url ? { label: 'Open safety review', href: ev.url } : null
                }
                steps={steps}
                caption={
                    ev.url ? undefined : (
                        <span className="text-caption">
                            H&amp;S access required to open
                        </span>
                    )
                }
            />
            <section
                aria-label="Corrective actions"
                className="sg-surface min-w-0"
            >
                <div className="sg-head">
                    <h3>Corrective actions</h3>
                    <span className="ml-auto flex flex-wrap items-center gap-3">
                        {ev.corrective_actions_url ? (
                            <Link
                                href={ev.corrective_actions_url}
                                className="sg-act"
                            >
                                Open register
                            </Link>
                        ) : null}
                        {d.can.raiseCorrectiveAction && !formOpen ? (
                            <Button
                                variant="outline"
                                size="sm"
                                className="frontline-hit"
                                disabled={draft.busy}
                                onClick={() => setFormOpen(true)}
                            >
                                <Plus className="size-3.5" />
                                {draft.dirty
                                    ? 'Continue corrective action'
                                    : 'Add corrective action'}
                            </Button>
                        ) : null}
                    </span>
                </div>
                {d.can.raiseCorrectiveAction ? (
                    <RaiseCorrectiveActionForm
                        d={d}
                        open={formOpen}
                        onHide={() => setFormOpen(false)}
                        onDraftStateChange={report}
                    />
                ) : null}
                {ev.corrective_actions.length ? (
                    <ul className="sg-list mt-1">
                        {ev.corrective_actions.map((ca) => (
                            <li key={ca.id} className="sg-li">
                                <span className="sg-li-t">
                                    <b>{ca.title}</b>
                                    <small>
                                        {[
                                            ca.reference_number,
                                            ca.assigned_to,
                                            ca.due_date
                                                ? `due ${formatDateOnly(ca.due_date)}`
                                                : null,
                                        ]
                                            .filter(Boolean)
                                            .join(' · ')}
                                    </small>
                                </span>
                                <StatusBadge status={ca.status} />
                            </li>
                        ))}
                    </ul>
                ) : (
                    <p className="sg-quiet">
                        <Ring tone="none" />
                        <span>
                            No corrective actions raised. Formal remediation is
                            raised and governed in the Health &amp; Safety
                            register.
                        </span>
                    </p>
                )}
            </section>
            <HandoverFacts d={d} />
            {inv ? (
                <FactRows
                    title={`Investigation ${inv.reference_number}`}
                    rows={[
                        ['Status', titleCase(inv.status)],
                        ...(inv.methodology
                            ? ([
                                  [
                                      'Methodology',
                                      METHODOLOGY_LABELS[inv.methodology] ??
                                          inv.methodology.replace(/_/g, ' '),
                                  ],
                              ] as [string, string][])
                            : []),
                        ...(inv.root_causes?.length
                            ? ([
                                  [
                                      'Root causes',
                                      inv.root_causes
                                          .map((c) => c.description)
                                          .filter(Boolean)
                                          .join('; '),
                                  ],
                              ] as [string, string][])
                            : []),
                        ...(inv.recommendations?.length
                            ? ([
                                  [
                                      'Recommendations',
                                      inv.recommendations
                                          .map((r) => r.description)
                                          .filter(Boolean)
                                          .join('; '),
                                  ],
                              ] as [string, string][])
                            : []),
                        ...(inv.lessons_learned
                            ? ([['Lessons learned', inv.lessons_learned]] as [
                                  string,
                                  string,
                              ][])
                            : []),
                    ]}
                />
            ) : (
                <QuietLine
                    title="Investigation"
                    text="No investigation has been opened yet."
                />
            )}
        </div>
    );
}

function RaiseCorrectiveActionForm({
    d,
    open,
    onHide,
    onDraftStateChange,
}: {
    d: IncidentDetail;
    open: boolean;
    onHide: () => void;
    onDraftStateChange?: (state: IncidentDraftState) => void;
}) {
    const form = useForm<{
        title: string;
        description: string;
        priority: string;
        due_date: string;
        assigned_to_user_id: string;
    }>({
        title: '',
        description: '',
        priority: 'medium',
        due_date: '',
        assigned_to_user_id: '',
    });
    useEffect(() => {
        onDraftStateChange?.({
            dirty: Boolean(form.isDirty),
            busy: form.processing,
        });
    }, [form.isDirty, form.processing, onDraftStateChange]);
    useEffect(
        () => () => onDraftStateChange?.({ dirty: false, busy: false }),
        [onDraftStateChange],
    );

    if (!d.hs_event || !open) {
        return null; // need an H&S event to attach the action to
    }

    const submit = (e: FormEvent) => {
        e.preventDefault();
        if (!form.data.title.trim()) {
            form.setError('title', 'Give the corrective action a title.');
            return;
        }
        if (!form.data.assigned_to_user_id) {
            form.setError(
                'assigned_to_user_id',
                'Choose the person responsible.',
            );
            return;
        }
        if (!form.data.due_date) {
            form.setError('due_date', 'Set the date this action is due.');
            return;
        }
        form.post(`/incidents/${d.id}/corrective-actions`, {
            preserveScroll: true,
            onSuccess: (page) => {
                if (
                    !(page.props as { flash?: { error?: string } }).flash?.error
                ) {
                    form.reset();
                    onHide();
                    // Stay in the incident — the refreshed detail shows the new
                    // action in the list; the register link is there for follow-up.
                }
            },
        });
    };

    return (
        <form
            onSubmit={submit}
            aria-label="Add a corrective action"
            className="mx-[18px] mt-2 mb-3 flex flex-col gap-3 rounded-xl border border-border bg-muted/30 p-3"
        >
            <p className="text-caption">
                Creates a row in the H&amp;S Corrective Actions register, linked
                to {d.hs_event.reference_number}.
            </p>
            <Field label="Action" required error={form.errors.title}>
                <Input
                    value={form.data.title}
                    onChange={(e) => form.setData('title', e.target.value)}
                    placeholder="e.g. Install a grab rail in the bathroom"
                />
            </Field>
            <Field label="Detail" hint="Optional">
                <Textarea
                    rows={2}
                    value={form.data.description}
                    onChange={(e) =>
                        form.setData('description', e.target.value)
                    }
                />
            </Field>
            <div className="grid gap-3 sm:grid-cols-3">
                <Field label="Priority">
                    <SelectInput
                        value={form.data.priority}
                        onChange={(v) => form.setData('priority', v)}
                        placeholder="Priority"
                        options={[
                            { value: 'low', label: 'Low' },
                            { value: 'medium', label: 'Medium' },
                            { value: 'high', label: 'High' },
                            { value: 'critical', label: 'Critical' },
                        ]}
                    />
                </Field>
                <Field
                    label="Owner"
                    required
                    error={form.errors.assigned_to_user_id}
                >
                    <SelectInput
                        value={form.data.assigned_to_user_id}
                        onChange={(v) => form.setData('assigned_to_user_id', v)}
                        placeholder="Choose owner"
                        options={d.corrective_action_owners.map((s) => ({
                            value: String(s.id),
                            label: s.name,
                        }))}
                    />
                </Field>
                <Field label="Due" required error={form.errors.due_date}>
                    <Input
                        type="date"
                        value={form.data.due_date}
                        onChange={(e) =>
                            form.setData('due_date', e.target.value)
                        }
                    />
                </Field>
            </div>
            <div className="flex justify-end gap-2">
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    onClick={onHide}
                >
                    Hide draft
                </Button>
                <Button
                    type="submit"
                    size="sm"
                    disabled={
                        form.processing ||
                        !form.data.title.trim() ||
                        !form.data.assigned_to_user_id ||
                        !form.data.due_date
                    }
                >
                    Raise in H&amp;S register
                </Button>
            </div>
        </form>
    );
}

/* ------------------------------------------------------------------ */
/*  Linked records                                                     */
/* ------------------------------------------------------------------ */

/**
 * Every record this incident links to, as list rows (drawing pg-hsn-2 side
 * column, pg-hsn-2h "Other linked records"). Restricted records keep their
 * need-to-know wording and never carry a link or a reference.
 */
export function linkedRecordItems(
    d: IncidentDetail,
    clientName: string,
    { includeControlRoom = true }: { includeControlRoom?: boolean } = {},
): RowItem[] {
    const lead = (Icon: ComponentType<{ className?: string }>) => (
        <Icon className="size-4" />
    );
    const items: RowItem[] = [];
    const source = d.linked_operational_evidence?.source;
    if (
        includeControlRoom &&
        source &&
        d.linked_operational_evidence?.access !== 'restricted'
    ) {
        items.push({
            key: 'cr',
            leading: lead(RadioTower),
            title: `Control room alert${source.reference ? ` ${source.reference}` : ''}`,
            sub: [
                titleCase(source.alert_type),
                source.resolved_at
                    ? `resolved ${formatTime(source.resolved_at)}`
                    : titleCase(source.status),
            ].join(' · '),
            href: source.href ?? undefined,
        });
    }
    for (const c of d.safeguarding_concerns ?? []) {
        items.push(
            c.can_view
                ? {
                      key: `sg-${c.id}`,
                      leading: lead(Shield),
                      title: `Safeguarding concern${c.reference_number ? ` ${c.reference_number}` : ''}`,
                      sub: c.status ? titleCase(c.status) : undefined,
                      href: `/safeguarding/${c.id}`,
                  }
                : {
                      key: `sg-${c.id}`,
                      leading: lead(Shield),
                      title: 'Safeguarding concern raised · restricted (need-to-know)',
                  },
        );
    }
    if (d.linked_access?.health_safety === 'restricted' && !d.hs_event) {
        items.push({
            key: 'hs-restricted',
            leading: lead(ShieldAlert),
            title: 'Health & safety record',
            sub: 'Linked record — access restricted',
        });
    }
    if (d.hs_event) {
        items.push({
            key: 'hs',
            leading: lead(ShieldAlert),
            title: `Safety review ${d.hs_event.reference_number}`,
            sub: [
                HS_STATUS_LABELS[d.hs_event.status] ??
                    titleCase(d.hs_event.status),
                d.hs_event.handover.owner?.name,
            ]
                .filter(Boolean)
                .join(' · '),
            href: d.hs_event.url ?? undefined,
        });
    }
    if (d.fleet_incident) {
        items.push({
            key: 'fleet',
            leading: lead(Truck),
            title: 'Fleet incident',
            sub: `${d.fleet_incident.reference} · ${titleCase(d.fleet_incident.type)}`,
            href: `/fleet-assets/incidents?incident=${d.fleet_incident.id}`,
        });
    }
    if (d.medication_error) {
        items.push({
            key: 'med',
            leading: lead(Pill),
            title: 'Medication error report',
            sub: `${d.medication_error.summary ?? 'Details are held in the medication error record.'}${d.medication_error.ready_to_close ? ' Ready to close — medication error closed.' : ''}`,
            href: d.medication_error.url,
        });
    }
    for (const r of d.restraint_events ?? []) {
        items.push({
            key: `re-${r.id}`,
            leading: lead(Hand),
            title: 'Restraint event',
            sub: `${r.reference} · ${titleCase(r.restraint_type)} · ${titleCase(r.severity)}${r.within_support_plan ? '' : ' · out of plan'}${r.injury_occurred ? ' · injury' : ''}`,
            href: `/health-safety/restraints?event=${r.id}`,
        });
    }
    for (const r of d.first_aid_records ?? []) {
        items.push({
            key: `fa-${r.id}`,
            leading: lead(HeartPulse),
            title: 'First-aid treatment',
            sub: `${r.reference} · ${r.person} · ${titleCase(r.injury)}${r.ambulance_called ? ' · ambulance' : ''}`,
            href: r.href,
        });
    }
    if (d.client) {
        items.push({
            key: 'client',
            leading: lead(User),
            title: 'Client record',
            sub: clientName,
            href: `/operations/clients/${d.client.id}/care`,
        });
    }
    return items;
}

export function LinkedSection({
    d,
    clientName,
}: {
    d: IncidentDetail;
    clientName: string;
}) {
    return (
        <div className="grid min-w-0 gap-5">
            <LinkedOperationalEvidence
                evidence={d.linked_operational_evidence}
            />
            <RowList
                title="Other linked records"
                items={linkedRecordItems(d, clientName, {
                    includeControlRoom: false,
                })}
                empty="No other linked records."
            />
        </div>
    );
}
