import {
    severityBadge,
    targetBadge,
} from '@/components/control-room/alert-record/alert-labels';
import { ControlRoomPage } from '@/components/control-room/control-room-page';
import { PeopleMultiPicker, PeoplePicker } from '@/components/hr/people-picker';
import {
    PageHeaderMeterBar,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderPrimaryButton,
    PageHeaderRail,
    PageHeaderStatusChip,
} from '@/components/page';
import {
    PopupCallout,
    PopupConfirm,
    PopupFacts,
    PopupField,
} from '@/components/popup';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { EmptyState } from '@/components/ui/empty-state';
import { Input } from '@/components/ui/input';
import { StatusBadge } from '@/components/ui/status-badge';
import { Textarea } from '@/components/ui/textarea';
import { formatDateTime, formatDurationMinutes, formatRelative } from '@/lib/datetime';
import { Link, router } from '@inertiajs/react';
import {
    AlertTriangle,
    ArrowLeft,
    ArrowRight,
    ArrowRightLeft,
    CheckCircle2,
    ClipboardCheck,
    ExternalLink,
    ListChecks,
    MessageSquareText,
    ShieldCheck,
    Users,
} from 'lucide-react';
import { useEffect, useMemo, useRef, useState, type ReactNode } from 'react';

interface StaffMember {
    id: number;
    name: string;
}

interface AlertTask {
    id: number;
    title: string;
    status: string;
    due_at: string | null;
}

interface AlertSummary {
    id: number;
    reference_number: string | null;
    summary: string;
    severity: 'critical' | 'high' | string;
    site: { id: number; name: string } | null;
    person: { id: number; name: string } | null;
    assignee: { id: number; name: string } | null;
    sla: { status: string | null; next_deadline_at: string | null };
    journey: {
        incident_reference: string | null;
        health_safety_reference: string | null;
        handover_status: string | null;
    };
    next_action: { label: string; href: string };
    href: string;
    tasks: AlertTask[];
    handover_reasons: Array<{ key: string; label: string }>;
}

interface HandoverDraft {
    handover_notes?: string;
    incoming_shift_name?: string;
    incoming_lead_user_id?: number | null;
    incoming_team_members?: number[];
    reviewed_alert_ids?: number[];
    priority_alert_ids?: number[];
    carry_forward_acknowledged?: boolean;
    carry_forward_signature?: string | null;
}

interface CarryForwardSummary {
    total: number;
    by_severity: Record<string, number>;
    by_queue: Array<{ id: number | null; name: string; total: number }>;
    oldest_created_at: string | null;
    breached_count: number;
    href: string;
    signature: string;
}

interface HandoverSnapshot {
    prepared_by?: StaffMember;
    prepared_at?: string;
    override?: {
        actor: StaffMember;
        reason: string;
        at: string;
    } | null;
    handover_notes?: string;
    incoming_shift?: {
        id?: number;
        name: string;
        lead: StaffMember;
        team_members: StaffMember[];
    };
    criteria_at?: string;
    criteria?: Array<{ key: string; label: string }>;
    required_alert_ids?: number[];
    reviewed_alert_ids?: number[];
    priority_alert_ids?: number[];
    alerts?: AlertSummary[];
    carry_forward?: CarryForwardSummary;
    carry_forward_acknowledged?: boolean;
    pinned_notes?: OperatorNote[];
    followup_notes?: OperatorNote[];
}

interface ShiftData {
    id: number;
    name: string;
    starts_at: string;
    ends_at: string | null;
    status: string;
    shift_lead: StaffMember | null;
    team_members: StaffMember[];
    open_alerts_at_start: number;
    alerts_created: number;
    alerts_resolved: number;
    alerts_escalated: number;
    duration_minutes: number | null;
    handover_status: 'none' | 'prepared' | 'accepted';
    handover_version: number;
    handover_prepared_at: string | null;
    handover_snapshot: HandoverSnapshot | null;
    draft: HandoverDraft;
    incoming_lead: StaffMember | null;
    is_stale: boolean;
    stale_after_hours: number;
    can_override: boolean;
    can_prepare: boolean;
    can_accept: boolean;
}

interface OperatorNote {
    id: number;
    type: string;
    content: string;
    is_pinned: boolean;
    requires_followup: boolean;
    followup_at: string | null;
    user: StaffMember | null;
    created_at: string;
}

interface Props {
    shift: ShiftData;
    openAlertsCount: number;
    requiredAlerts: AlertSummary[];
    handoverCriteriaAt: string;
    handoverCriteria: Array<{ key: string; label: string }>;
    carryForward: CarryForwardSummary;
    pinnedNotes: OperatorNote[];
    followupNotes: OperatorNote[];
    staff: StaffMember[];
    eligibleLeads: StaffMember[];
    snapshotIssue: string | null;
}

type StepKey = 'required' | 'context' | 'team' | 'review';

/** The preparation steps — the record page's own tab rail. */
const STEPS: ReadonlyArray<{ key: StepKey; label: string; icon: typeof ListChecks }> = [
    { key: 'required', label: 'Required work', icon: ListChecks },
    { key: 'context', label: 'Context', icon: MessageSquareText },
    { key: 'team', label: 'Incoming team', icon: Users },
    { key: 'review', label: 'Final review', icon: ShieldCheck },
];

const BACK_HREF = '/control-room/shifts';
const SEVERITIES = ['critical', 'high', 'medium', 'low'] as const;

/** "Due in 12m" / "Was due 3h ago" — a countdown, never a bare time. */
function dueLabel(dueAt: string): string {
    const relative = formatRelative(dueAt);
    return new Date(dueAt).getTime() >= Date.now() ? `Due ${relative}` : `Was due ${relative}`;
}

function alertReference(alert: AlertSummary): string {
    return alert.reference_number ?? `Alert ${alert.id}`;
}

function severityLabel(severity: string): string {
    return severity.charAt(0).toUpperCase() + severity.slice(1);
}

const people = (list: StaffMember[]) =>
    list.map((person) => ({ value: String(person.id), label: person.name }));

/** A titled card section on the record page. */
function Panel({
    id,
    title,
    description,
    action,
    children,
}: {
    id?: string;
    title: string;
    description?: ReactNode;
    action?: ReactNode;
    children: ReactNode;
}) {
    return (
        <Card id={id} className="min-w-0 scroll-mt-5 gap-4 px-5 py-5">
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="min-w-0">
                    <h2 id={id ? `${id}-title` : undefined} className="text-section-title">
                        {title}
                    </h2>
                    {description ? <p className="text-subtle mt-0.5">{description}</p> : null}
                </div>
                {action}
            </div>
            {children}
        </Card>
    );
}

function CarryForwardPanel({
    summary,
    acknowledged,
    editable,
    onAcknowledgedChange,
    prepared = false,
}: {
    summary: CarryForwardSummary;
    acknowledged: boolean;
    editable: boolean;
    onAcknowledgedChange?: (checked: boolean) => void;
    prepared?: boolean;
}) {
    const copy =
        summary.total === 0
            ? 'No unchanged active alerts need to carry forward.'
            : prepared
              ? `${summary.total} unchanged active alerts carried forward as an acknowledged summary.`
              : `${summary.total} unchanged active alerts will carry forward as a summary. You do not need to open each one.`;

    return (
        <Panel
            id="carry-forward"
            title="Unchanged active work"
            action={
                summary.total > 0 ? (
                    <Button asChild variant="outline" size="sm">
                        <Link href={summary.href}>Open carry-forward list</Link>
                    </Button>
                ) : null
            }
        >
            <p className="font-medium">{copy}</p>
            {summary.total > 0 ? (
                <PopupFacts
                    rows={[
                        ...SEVERITIES.map(
                            (severity) =>
                                [
                                    severityLabel(severity),
                                    String(summary.by_severity[severity] ?? 0),
                                ] as const,
                        ),
                        ['Response target missed', String(summary.breached_count)] as const,
                        ...(summary.oldest_created_at
                            ? [
                                  [
                                      'Oldest active alert',
                                      <span
                                          key="oldest"
                                          title={formatDateTime(summary.oldest_created_at)}
                                      >
                                          Raised {formatRelative(summary.oldest_created_at)}
                                      </span>,
                                  ] as const,
                              ]
                            : []),
                    ]}
                />
            ) : null}
            {editable && summary.total > 0 ? (
                <div className="flex items-start gap-3 rounded-[10px] bg-status-warning-bg px-3.5 py-3 text-sm text-status-warning-foreground">
                    <Checkbox
                        id="carry-forward-acknowledged"
                        aria-label={`Acknowledge ${summary.total} unchanged active alerts`}
                        checked={acknowledged}
                        onCheckedChange={(value) => onAcknowledgedChange?.(value === true)}
                        className="mt-0.5 bg-card"
                    />
                    <label htmlFor="carry-forward-acknowledged">
                        <strong>Acknowledge carry-forward summary.</strong> I have reviewed
                        these counts and understand the unchanged active alerts will remain
                        available in the Control Room.
                    </label>
                </div>
            ) : null}
        </Panel>
    );
}

function AlertReviewRow({
    alert,
    reviewed,
    priority,
    editable,
    onReviewedChange,
    onPriorityChange,
}: {
    alert: AlertSummary;
    reviewed: boolean;
    priority: boolean;
    editable: boolean;
    onReviewedChange?: (checked: boolean) => void;
    onPriorityChange?: (checked: boolean) => void;
}) {
    const reference = alertReference(alert);
    const severity = severityBadge(alert.severity);
    const target =
        alert.sla.status && alert.sla.status !== 'not_applicable'
            ? targetBadge(alert.sla.status)
            : null;
    // Who it is about first (person · house), then the owner; empty parts are left out.
    const who = [alert.person?.name, alert.site?.name].filter(Boolean).join(' · ');
    const facts = [
        alert.assignee ? `Owner: ${alert.assignee.name}` : null,
        alert.tasks.length
            ? `${alert.tasks.length} open ${alert.tasks.length === 1 ? 'task' : 'tasks'}`
            : null,
        alert.journey.incident_reference,
        alert.journey.health_safety_reference,
    ].filter(Boolean);
    return (
        <li className="flex flex-col gap-4 py-4 first:pt-0 last:pb-0 sm:flex-row sm:items-start sm:justify-between">
            <div className="min-w-0 flex-1">
                <p className="font-semibold text-foreground">{alert.summary}</p>
                {who ? <p className="text-subtle mt-0.5">{who}</p> : null}
                <div className="mt-2 flex flex-wrap items-center gap-1.5">
                    <StatusBadge variant={severity.tone} size="sm">
                        {severity.label}
                    </StatusBadge>
                    {target ? (
                        <StatusBadge variant={target.tone} size="sm">
                            {target.label}
                        </StatusBadge>
                    ) : null}
                    {priority ? (
                        <StatusBadge variant="info" size="sm">
                            Carry-forward priority
                        </StatusBadge>
                    ) : null}
                </div>
                {alert.handover_reasons.length > 0 ? (
                    <div
                        className="mt-1.5 flex flex-wrap gap-1.5"
                        aria-label={`Why ${reference} requires review`}
                    >
                        {alert.handover_reasons.map((reason) => (
                            <StatusBadge key={reason.key} variant="neutral" size="sm">
                                {reason.label}
                            </StatusBadge>
                        ))}
                    </div>
                ) : null}
                {alert.tasks.length > 0 ? (
                    <ul className="mt-2 grid gap-1 text-sm">
                        {alert.tasks.map((task) => (
                            <li key={task.id} className="flex flex-wrap items-center gap-2">
                                <span aria-hidden="true" className="size-1.5 rounded-full bg-primary" />
                                {task.title}
                                {task.due_at ? (
                                    <span
                                        className="text-muted-foreground"
                                        title={formatDateTime(task.due_at)}
                                    >
                                        {dueLabel(task.due_at)}
                                    </span>
                                ) : null}
                            </li>
                        ))}
                    </ul>
                ) : null}
                <p className="text-caption mt-1.5 flex flex-wrap items-center gap-x-1.5">
                    <Link
                        href={alert.href}
                        className="inline-flex items-center gap-1 font-medium text-primary hover:underline"
                    >
                        {reference}
                        <ExternalLink aria-hidden="true" className="size-3" />
                    </Link>
                    {facts.length ? <span>· {facts.join(' · ')}</span> : null}
                </p>
            </div>
            {editable ? (
                <div className="grid shrink-0 gap-3 border-border sm:w-48 sm:border-l sm:pl-4">
                    <label className="flex items-center gap-2 text-sm font-medium">
                        <Checkbox
                            aria-label={`Reviewed ${reference}`}
                            checked={reviewed}
                            onCheckedChange={(value) => onReviewedChange?.(value === true)}
                        />
                        Reviewed
                    </label>
                    <label className="flex items-center gap-2 text-sm">
                        <Checkbox
                            aria-label={`Carry ${reference} as a priority`}
                            checked={priority}
                            disabled={!reviewed}
                            onCheckedChange={(value) => onPriorityChange?.(value === true)}
                        />
                        Carry as priority
                    </label>
                </div>
            ) : null}
        </li>
    );
}

/** A short list of operator notes; an empty list is left out, not shown as "None". */
function NoteList({ title, notes }: { title: string; notes: OperatorNote[] }) {
    if (notes.length === 0) return null;
    return (
        <div className="grid content-start gap-2 rounded-[12px] border border-border p-4">
            <h3 className="text-sm font-semibold">{title}</h3>
            {notes.map((note) => (
                <p key={note.id} className="text-sm whitespace-pre-wrap">
                    {note.content}
                </p>
            ))}
        </div>
    );
}

/**
 * Duty handover › prepare / accept — a full page by design: preparing is a
 * recoverable, version-checked draft that saves as you go and never moves
 * ownership; the named incoming lead then reviews and accepts in a separate
 * step. The record header carries the state, the steps are its tab rail.
 */
export default function ShiftHandover(props: Props) {
    const {
        shift,
        openAlertsCount,
        requiredAlerts,
        handoverCriteriaAt,
        carryForward,
        pinnedNotes,
        followupNotes,
        staff,
        eligibleLeads,
        snapshotIssue,
    } = props;
    const requiredCriticalCount = useMemo(
        () => requiredAlerts.filter((alert) => alert.severity === 'critical').length,
        [requiredAlerts],
    );
    const [currentStep, setCurrentStep] = useState(0);
    const [handoverNotes, setHandoverNotes] = useState(shift.draft.handover_notes ?? '');
    const [incomingShiftName, setIncomingShiftName] = useState(
        shift.draft.incoming_shift_name ?? '',
    );
    const [incomingLeadUserId, setIncomingLeadUserId] = useState(
        shift.draft.incoming_lead_user_id ? String(shift.draft.incoming_lead_user_id) : '',
    );
    const [incomingTeamMembers, setIncomingTeamMembers] = useState<number[]>(
        shift.draft.incoming_team_members ?? [],
    );
    const [reviewedAlertIds, setReviewedAlertIds] = useState<number[]>(
        shift.draft.reviewed_alert_ids ?? [],
    );
    const [priorityAlertIds, setPriorityAlertIds] = useState<number[]>(
        shift.draft.priority_alert_ids ?? [],
    );
    const [carryForwardAcknowledged, setCarryForwardAcknowledged] = useState(
        Boolean(
            shift.draft.carry_forward_acknowledged &&
            shift.draft.carry_forward_signature === carryForward.signature,
        ),
    );
    const [overrideReason, setOverrideReason] = useState('');
    const [version, setVersion] = useState(shift.handover_version);
    const versionRef = useRef(shift.handover_version);
    const [saveState, setSaveState] = useState<'saved' | 'unsaved' | 'saving'>('saved');
    const [submitting, setSubmitting] = useState(false);
    const [conflictMessage, setConflictMessage] = useState<string | null>(null);
    const [acceptOpen, setAcceptOpen] = useState(false);
    const [acceptUnconfirmed, setAcceptUnconfirmed] = useState(false);
    const acceptSettled = useRef(false);
    const initialRender = useRef(true);

    useEffect(() => {
        if (initialRender.current) {
            initialRender.current = false;
            return;
        }
        if (!shift.can_prepare || shift.handover_status !== 'none') return;

        setSaveState('unsaved');
        const timer = window.setTimeout(() => {
            setSaveState('saving');
            router.patch(
                `/control-room/shifts/${shift.id}/handover/draft`,
                {
                    handover_notes: handoverNotes,
                    incoming_shift_name: incomingShiftName,
                    incoming_lead_user_id: incomingLeadUserId ? Number(incomingLeadUserId) : null,
                    incoming_team_members: incomingTeamMembers,
                    reviewed_alert_ids: reviewedAlertIds,
                    priority_alert_ids: priorityAlertIds,
                    carry_forward_acknowledged: carryForwardAcknowledged,
                    carry_forward_signature: carryForwardAcknowledged
                        ? carryForward.signature
                        : null,
                    override_reason: shift.can_override ? overrideReason : null,
                    expected_version: versionRef.current,
                },
                {
                    preserveScroll: true,
                    preserveState: true,
                    only: ['shift'],
                    onSuccess: (page) => {
                        const updated = (page.props as unknown as { shift: ShiftData }).shift;
                        versionRef.current = updated.handover_version;
                        setVersion(updated.handover_version);
                        setSaveState('saved');
                        setConflictMessage(null);
                    },
                    onError: (errors) => {
                        setSaveState('unsaved');
                        setConflictMessage(
                            String(
                                errors.handover_version ??
                                    errors.override_reason ??
                                    errors.handover ??
                                    'The draft could not be saved. Review the fields and try again.',
                            ),
                        );
                    },
                },
            );
        }, 700);

        return () => window.clearTimeout(timer);
    }, [
        handoverNotes,
        carryForward.signature,
        carryForwardAcknowledged,
        incomingLeadUserId,
        incomingShiftName,
        incomingTeamMembers,
        overrideReason,
        priorityAlertIds,
        reviewedAlertIds,
        shift.can_override,
        shift.can_prepare,
        shift.handover_status,
        shift.id,
    ]);

    const setReviewed = (alertId: number, checked: boolean) => {
        setReviewedAlertIds((current) =>
            checked ? [...new Set([...current, alertId])] : current.filter((id) => id !== alertId),
        );
        if (!checked) {
            setPriorityAlertIds((current) => current.filter((id) => id !== alertId));
        }
    };

    const setPriority = (alertId: number, checked: boolean) => {
        setPriorityAlertIds((current) =>
            checked ? [...new Set([...current, alertId])] : current.filter((id) => id !== alertId),
        );
    };

    const allRequiredReviewed = requiredAlerts.every((alert) =>
        reviewedAlertIds.includes(alert.id),
    );
    const reviewedRequiredCount = reviewedAlertIds.filter((id) =>
        requiredAlerts.some((alert) => alert.id === id),
    ).length;
    const readyToPrepare =
        Boolean(incomingLeadUserId) &&
        allRequiredReviewed &&
        (carryForward.total === 0 || carryForwardAcknowledged) &&
        (!shift.can_override || overrideReason.trim().length >= 10) &&
        saveState === 'saved';

    const prepare = () => {
        if (!readyToPrepare || submitting) return;
        setSubmitting(true);
        router.post(
            `/control-room/shifts/${shift.id}/handover`,
            {
                incoming_lead_user_id: Number(incomingLeadUserId),
                reviewed_alert_ids: reviewedAlertIds,
                override_reason: shift.can_override ? overrideReason.trim() : null,
                expected_version: version,
            },
            {
                preserveScroll: true,
                onError: (errors) => {
                    setConflictMessage(
                        String(
                            errors.handover_version ??
                                errors.override_reason ??
                                errors.reviewed_alert_ids ??
                                errors.handover ??
                                'The handover could not be prepared.',
                        ),
                    );
                },
                onFinish: () => setSubmitting(false),
            },
        );
    };

    const accept = () => {
        if (!shift.can_accept || submitting) return;
        setSubmitting(true);
        acceptSettled.current = false;
        setAcceptUnconfirmed(false);
        router.post(
            `/control-room/shifts/${shift.id}/accept-handover`,
            { expected_version: shift.handover_version },
            {
                onSuccess: () => {
                    acceptSettled.current = true;
                    setAcceptOpen(false);
                },
                onError: (errors) => {
                    acceptSettled.current = true;
                    setConflictMessage(
                        String(
                            errors.handover_version ??
                                errors.handover ??
                                'The handover could not be accepted.',
                        ),
                    );
                },
                onCancel: () => {
                    acceptSettled.current = true;
                },
                onFinish: () => {
                    setSubmitting(false);
                    if (!acceptSettled.current) setAcceptUnconfirmed(true);
                },
            },
        );
    };

    if (snapshotIssue) {
        return (
            <ControlRoomPage
                headTitle={`Unusable handover - ${shift.name}`}
                recordCrumb={{ title: 'Unusable handover' }}
                rail={null}
                header={{
                    variant: 'profile',
                    backHref: BACK_HREF,
                    icon: AlertTriangle,
                    title: 'Prepared handover cannot be used',
                    wrapTitle: true,
                    titleChip: (
                        <PageHeaderStatusChip variant="critical">
                            Acceptance blocked
                        </PageHeaderStatusChip>
                    ),
                    subline: `${shift.name} · The saved handover did not pass its checks`,
                }}
            >
                <PopupCallout tone="critical">{snapshotIssue}</PopupCallout>
                <Panel title="Acceptance is blocked">
                    <div className="grid gap-2 text-sm">
                        <p>
                            The outgoing shift remains active. No incoming shift has been started
                            and no ownership has changed.
                        </p>
                        <p className="text-muted-foreground">
                            Ask a Control Room administrator to inspect and replace this handover
                            before the shift can be transferred.
                        </p>
                    </div>
                    <Button asChild variant="outline" className="justify-self-start">
                        <Link href={BACK_HREF}>Back to Duty handover</Link>
                    </Button>
                </Panel>
            </ControlRoomPage>
        );
    }

    if (shift.handover_status === 'prepared' && shift.handover_snapshot) {
        const snapshot = shift.handover_snapshot;
        const alerts = snapshot.alerts ?? [];
        const priorities = new Set(snapshot.priority_alert_ids ?? []);
        const snapshotCarryForward = snapshot.carry_forward ?? carryForward;
        const incoming = snapshot.incoming_shift;
        const incomingLeadName = incoming?.lead.name ?? shift.incoming_lead?.name ?? 'the incoming lead';
        const preparedBy = snapshot.prepared_by?.name ?? 'the outgoing lead';
        const show = (id: string) =>
            document.getElementById(id)?.scrollIntoView({ block: 'start' });

        return (
            <ControlRoomPage
                headTitle={`Prepared handover - ${shift.name}`}
                recordCrumb={{ title: 'Prepared handover' }}
                rail={null}
                header={{
                    variant: 'profile',
                    backHref: BACK_HREF,
                    icon: ArrowRightLeft,
                    title: 'Prepared duty handover',
                    wrapTitle: true,
                    titleChip: (
                        <PageHeaderStatusChip variant="warning">
                            Prepared, not yet transferred
                        </PageHeaderStatusChip>
                    ),
                    subline: `Prepared by ${preparedBy} ${formatRelative(snapshot.prepared_at)} · Incoming lead: ${incomingLeadName}`,
                    actions: shift.can_accept ? (
                        <PageHeaderPrimaryButton
                            icon={CheckCircle2}
                            onClick={() => setAcceptOpen(true)}
                            disabled={submitting}
                        >
                            Accept duty handover
                        </PageHeaderPrimaryButton>
                    ) : undefined,
                    meters: (
                        <>
                            <PageHeaderMeterBlock
                                label="Incoming duty"
                                onClick={() => show('outgoing-context')}
                                ariaLabel="Show the outgoing context"
                            >
                                <PageHeaderMeterBig>{incoming?.name || shift.name}</PageHeaderMeterBig>
                                <PageHeaderMeterCaption>
                                    lead {incomingLeadName}
                                    {incoming?.team_members.length
                                        ? ` · ${incoming.team_members.length} in the team`
                                        : ''}
                                </PageHeaderMeterCaption>
                            </PageHeaderMeterBlock>
                            <PageHeaderMeterBlock
                                label="Required alerts reviewed"
                                onClick={() => show('snapshot-alerts')}
                                ariaLabel="Show the reviewed alerts"
                            >
                                <PageHeaderMeterBig>{alerts.length}</PageHeaderMeterBig>
                                <PageHeaderMeterCaption>
                                    {priorities.size} carried as a priority
                                </PageHeaderMeterCaption>
                            </PageHeaderMeterBlock>
                            <PageHeaderMeterBlock
                                label="Unchanged carried forward"
                                onClick={() => show('carry-forward')}
                                ariaLabel="Show the unchanged active work"
                            >
                                <PageHeaderMeterBig>{snapshotCarryForward.total}</PageHeaderMeterBig>
                                <PageHeaderMeterCaption>
                                    {snapshotCarryForward.breached_count} past a response target
                                </PageHeaderMeterCaption>
                            </PageHeaderMeterBlock>
                        </>
                    ),
                }}
            >
                {conflictMessage ? (
                    <PopupCallout tone="critical">
                        {conflictMessage} Reload this page before trying again.
                    </PopupCallout>
                ) : null}
                <PopupCallout tone={shift.can_accept ? 'info' : 'warning'}>
                    {shift.can_accept
                        ? `Review what is handed over, then choose Accept duty handover. Until you accept, ${shift.shift_lead?.name ?? 'the outgoing lead'} stays responsible.`
                        : `${incomingLeadName} still needs to review and accept. The outgoing duty stays responsible until then.`}
                </PopupCallout>
                {snapshot.override ? (
                    <PopupCallout tone="warning">
                        <b className="font-semibold">Audited stale-shift override.</b>{' '}
                        {snapshot.override.actor.name} on {formatDateTime(snapshot.override.at)}:{' '}
                        {snapshot.override.reason}
                    </PopupCallout>
                ) : null}

                <div className="grid min-w-0 items-start gap-5 xl:grid-cols-[minmax(0,1fr)_minmax(320px,0.6fr)]">
                    <div className="flex min-w-0 flex-col gap-5">
                        <Panel
                            id="snapshot-alerts"
                            title="Frozen required-work snapshot"
                            description={`Scope checked ${formatDateTime(snapshot.criteria_at)}. Open an alert to continue in its own record.`}
                            action={
                                <Button asChild variant="outline" size="sm">
                                    <Link href="/tasks">Open universal tasks</Link>
                                </Button>
                            }
                        >
                            {alerts.length > 0 ? (
                                <ul className="divide-y divide-border">
                                    {alerts.map((alert) => (
                                        <AlertReviewRow
                                            key={alert.id}
                                            alert={alert}
                                            reviewed
                                            priority={priorities.has(alert.id)}
                                            editable={false}
                                        />
                                    ))}
                                </ul>
                            ) : (
                                <EmptyState
                                    variant="compact"
                                    icon={ClipboardCheck}
                                    title="No alerts needed an individual review"
                                    description="No alerts matched the individual-review criteria when this handover was prepared."
                                />
                            )}
                        </Panel>
                        <CarryForwardPanel
                            summary={snapshotCarryForward}
                            acknowledged={snapshot.carry_forward_acknowledged === true}
                            editable={false}
                            prepared
                        />
                    </div>
                    <div className="flex min-w-0 flex-col gap-5">
                        <Panel id="outgoing-context" title="Outgoing context">
                            <p className="text-sm whitespace-pre-wrap">
                                {snapshot.handover_notes || 'No additional handover notes.'}
                            </p>
                        </Panel>
                        {(snapshot.pinned_notes?.length ?? 0) > 0 ||
                        (snapshot.followup_notes?.length ?? 0) > 0 ? (
                            <Panel title="Operator notes">
                                <div className="grid gap-3">
                                    <NoteList title="Pinned notes" notes={snapshot.pinned_notes ?? []} />
                                    <NoteList
                                        title="Follow-up notes"
                                        notes={snapshot.followup_notes ?? []}
                                    />
                                </div>
                            </Panel>
                        ) : null}
                    </div>
                </div>

                {shift.can_accept ? (
                    <PopupConfirm
                        open={acceptOpen}
                        onClose={() => {
                            setAcceptOpen(false);
                            setAcceptUnconfirmed(false);
                        }}
                        onConfirm={() =>
                            // An accepted handover ends this duty, so its result shows on
                            // Duty handover — this page only exists while the duty is active.
                            acceptUnconfirmed ? router.visit(BACK_HREF) : accept()
                        }
                        variant="warning"
                        icon={ArrowRightLeft}
                        title="Accept duty handover?"
                        description={
                            <>
                                You become responsible for the Control Room from now.{' '}
                                <b className="font-semibold text-foreground">{shift.name}</b>{' '}
                                ends and{' '}
                                <b className="font-semibold text-foreground">
                                    {incoming?.name || 'the incoming duty'}
                                </b>{' '}
                                starts with you as lead.
                            </>
                        }
                        context={{
                            icon: ArrowRightLeft,
                            title: shift.name,
                            tag: `From ${preparedBy}`,
                            note: `Prepared ${formatDateTime(snapshot.prepared_at)}`,
                        }}
                        error={conflictMessage ?? undefined}
                        processing={submitting}
                        processingLabel="Accepting…"
                        confirmLabel={acceptUnconfirmed ? 'Check result' : 'Accept handover'}
                    >
                        {acceptUnconfirmed ? (
                            <PopupCallout tone="warning">
                                We couldn&apos;t confirm the result — the connection may have
                                dropped. Check before trying again, so the handover isn&apos;t
                                accepted twice.
                            </PopupCallout>
                        ) : null}
                        <PopupFacts
                            rows={[
                                ['Incoming lead', incomingLeadName],
                                [
                                    'Team',
                                    incoming?.team_members.length
                                        ? incoming.team_members.map((m) => m.name).join(' · ')
                                        : 'No team members named',
                                ],
                                ['Required alerts reviewed', String(alerts.length)],
                                ['Carried as a priority', String(priorities.size)],
                                [
                                    'Unchanged carried forward',
                                    String(snapshotCarryForward.total),
                                ],
                            ]}
                        />
                    </PopupConfirm>
                ) : null}
            </ControlRoomPage>
        );
    }

    const step = STEPS[currentStep];
    const leadName =
        eligibleLeads.find((person) => String(person.id) === incomingLeadUserId)?.name ?? null;
    const remaining = requiredAlerts.length - reviewedRequiredCount;
    const reviewedPercent = requiredAlerts.length
        ? Math.round((reviewedRequiredCount / requiredAlerts.length) * 100)
        : 100;
    const goTo = (index: number) => setCurrentStep(index);

    const primary =
        currentStep < 3 ? (
            <PageHeaderPrimaryButton
                disabled={currentStep === 2 && !incomingLeadUserId}
                onClick={() => goTo(currentStep + 1)}
            >
                Continue to {STEPS[currentStep + 1].label.toLowerCase()}
            </PageHeaderPrimaryButton>
        ) : (
            <PageHeaderPrimaryButton
                icon={ShieldCheck}
                disabled={!readyToPrepare || submitting || !shift.can_prepare}
                onClick={prepare}
            >
                {submitting
                    ? 'Preparing…'
                    : saveState !== 'saved'
                      ? 'Draft still saving…'
                      : 'Prepare for incoming acceptance'}
            </PageHeaderPrimaryButton>
        );

    const saveChip = !shift.can_prepare ? (
        <PageHeaderStatusChip variant="neutral">Read only</PageHeaderStatusChip>
    ) : saveState === 'saving' ? (
        <PageHeaderStatusChip variant="info">Saving…</PageHeaderStatusChip>
    ) : saveState === 'saved' ? (
        <PageHeaderStatusChip variant="success">Draft saved</PageHeaderStatusChip>
    ) : (
        <PageHeaderStatusChip variant="warning">Unsaved changes</PageHeaderStatusChip>
    );

    const nav = (
        <div className="flex flex-wrap justify-between gap-3">
            {currentStep > 0 ? (
                <Button variant="outline" onClick={() => goTo(currentStep - 1)}>
                    <ArrowLeft aria-hidden="true" /> Back
                </Button>
            ) : (
                <span />
            )}
            {currentStep < 3 ? (
                <Button
                    disabled={currentStep === 2 && !incomingLeadUserId}
                    onClick={() => goTo(currentStep + 1)}
                >
                    Continue to {STEPS[currentStep + 1].label.toLowerCase()}
                    <ArrowRight aria-hidden="true" />
                </Button>
            ) : (
                <Button
                    disabled={!readyToPrepare || submitting || !shift.can_prepare}
                    onClick={prepare}
                >
                    {submitting
                        ? 'Preparing…'
                        : saveState !== 'saved'
                          ? 'Draft still saving…'
                          : 'Prepare for incoming acceptance'}
                </Button>
            )}
        </div>
    );

    return (
        <ControlRoomPage
            headTitle={`Prepare handover - ${shift.name}`}
            recordCrumb={{ title: 'Prepare handover' }}
            header={{
                variant: 'profile',
                backHref: BACK_HREF,
                icon: ArrowRightLeft,
                title: 'Prepare duty handover',
                wrapTitle: true,
                titleChip: <span aria-live="polite">{saveChip}</span>,
                subline: [
                    shift.name,
                    shift.duration_minutes === null
                        ? null
                        : `on duty ${formatDurationMinutes(shift.duration_minutes)}`,
                    `${openAlertsCount} active alerts`,
                    `scope checked ${formatRelative(handoverCriteriaAt)}`,
                ]
                    .filter(Boolean)
                    .join(' · '),
                actions: primary,
                meters: (
                    <>
                        <PageHeaderMeterBlock
                            label="Required"
                            tone={requiredCriticalCount > 0 ? 'critical' : 'brand'}
                            onClick={() => goTo(0)}
                            pressed={currentStep === 0}
                            ariaLabel="Show the required work"
                        >
                            <PageHeaderMeterBig>{requiredAlerts.length}</PageHeaderMeterBig>
                            <PageHeaderMeterCaption>
                                {requiredCriticalCount} critical
                            </PageHeaderMeterCaption>
                        </PageHeaderMeterBlock>
                        <PageHeaderMeterBlock
                            label="Reviewed"
                            tone={allRequiredReviewed ? 'success' : 'warning'}
                            onClick={() => goTo(0)}
                            ariaLabel="Show the alerts to review"
                        >
                            <PageHeaderMeterBig>
                                {reviewedRequiredCount}/{requiredAlerts.length}
                            </PageHeaderMeterBig>
                            <PageHeaderMeterBar percent={reviewedPercent} />
                            <PageHeaderMeterCaption>
                                {allRequiredReviewed ? 'all required work reviewed' : `${remaining} still to review`}
                            </PageHeaderMeterCaption>
                        </PageHeaderMeterBlock>
                        <PageHeaderMeterBlock
                            label="Carry forward"
                            tone={
                                carryForward.total > 0 && !carryForwardAcknowledged
                                    ? 'warning'
                                    : 'brand'
                            }
                            onClick={() => {
                                goTo(0);
                                window.requestAnimationFrame(() =>
                                    document
                                        .getElementById('carry-forward')
                                        ?.scrollIntoView({ block: 'start' }),
                                );
                            }}
                            ariaLabel="Show the unchanged active work"
                        >
                            <PageHeaderMeterBig>{carryForward.total}</PageHeaderMeterBig>
                            <PageHeaderMeterCaption>
                                {carryForward.total === 0
                                    ? 'nothing unchanged'
                                    : carryForwardAcknowledged
                                      ? 'unchanged · acknowledged'
                                      : 'unchanged · not acknowledged'}
                            </PageHeaderMeterCaption>
                        </PageHeaderMeterBlock>
                        <PageHeaderMeterBlock
                            label="Incoming lead"
                            tone={leadName ? 'brand' : 'warning'}
                            onClick={() => goTo(2)}
                            pressed={currentStep === 2}
                            ariaLabel="Choose the incoming lead"
                        >
                            <PageHeaderMeterBig>{leadName ?? 'Not chosen'}</PageHeaderMeterBig>
                            <PageHeaderMeterCaption>accepts to take over</PageHeaderMeterCaption>
                        </PageHeaderMeterBlock>
                    </>
                ),
            }}
            rail={
                <PageHeaderRail
                    ariaLabel="Handover steps"
                    value={step.key}
                    onSelect={(key) => goTo(STEPS.findIndex((s) => s.key === key))}
                    items={STEPS.map((s) => ({
                        key: s.key,
                        label: s.label,
                        icon: s.icon,
                        count: s.key === 'required' && remaining > 0 ? remaining : undefined,
                    }))}
                />
            }
        >
            <PopupCallout tone="info">
                Your current duty stays active until the incoming lead accepts. Your draft saves
                as you go — if a save fails or a result isn&apos;t confirmed, what you entered
                stays here.
            </PopupCallout>
            {shift.is_stale ? (
                <Card className="min-w-0 gap-4 px-5 py-5">
                    <PopupCallout tone="warning">
                        <p>
                            This shift is stale. The named outgoing lead has not completed
                            handover. An authorised manager may prepare it with an audited reason;
                            the incoming lead must still accept it.
                        </p>
                    </PopupCallout>
                    {shift.can_override ? (
                        <PopupField
                            label="Audited override reason"
                            htmlFor="override-reason"
                            hint="At least 10 characters. Your name, reason and preparation time are kept in the handover record."
                        >
                            <Textarea
                                id="override-reason"
                                rows={3}
                                required
                                minLength={10}
                                maxLength={2000}
                                value={overrideReason}
                                onChange={(event) => setOverrideReason(event.target.value)}
                                placeholder="Explain why the named outgoing lead is unavailable."
                            />
                        </PopupField>
                    ) : null}
                </Card>
            ) : null}
            {!shift.can_prepare ? (
                <PopupCallout tone="warning">
                    Only the outgoing shift lead, {shift.shift_lead?.name ?? 'currently unassigned'},
                    can prepare this handover.
                </PopupCallout>
            ) : null}
            {conflictMessage ? <PopupCallout tone="critical">{conflictMessage}</PopupCallout> : null}

            {currentStep === 0 ? (
                <>
                    <Panel
                        id="required-work"
                        title="Review changed and decision-relevant work"
                        description="Alerts that changed on this duty or need a decision. Tick each one once you have reviewed it."
                    >
                        {requiredAlerts.length > 0 ? (
                            <ul className="divide-y divide-border">
                                {requiredAlerts.map((alert) => (
                                    <AlertReviewRow
                                        key={alert.id}
                                        alert={alert}
                                        reviewed={reviewedAlertIds.includes(alert.id)}
                                        priority={priorityAlertIds.includes(alert.id)}
                                        editable={shift.can_prepare}
                                        onReviewedChange={(checked) => setReviewed(alert.id, checked)}
                                        onPriorityChange={(checked) => setPriority(alert.id, checked)}
                                    />
                                ))}
                            </ul>
                        ) : (
                            <PopupCallout tone="success">
                                No alerts currently match the individual-review criteria.
                            </PopupCallout>
                        )}
                    </Panel>
                    <CarryForwardPanel
                        summary={carryForward}
                        acknowledged={carryForwardAcknowledged}
                        editable={shift.can_prepare}
                        onAcknowledgedChange={setCarryForwardAcknowledged}
                    />
                    {nav}
                </>
            ) : null}

            {currentStep === 1 ? (
                <>
                    <Panel title="What must the incoming lead know?">
                        <PopupField
                            label="Operational context"
                            htmlFor="handover-notes"
                            hint="Record decisions, dependencies or a change of plan. Linked alert rows carry the actual work."
                        >
                            <Textarea
                                id="handover-notes"
                                rows={8}
                                value={handoverNotes}
                                disabled={!shift.can_prepare}
                                onChange={(event) => setHandoverNotes(event.target.value)}
                                placeholder="For example: Family has been contacted. Continue 15-minute welfare updates until the on-call manager arrives."
                            />
                        </PopupField>
                        {pinnedNotes.length > 0 || followupNotes.length > 0 ? (
                            <div className="grid gap-3 md:grid-cols-2">
                                <NoteList title="Pinned notes" notes={pinnedNotes} />
                                <NoteList title="Follow-up notes" notes={followupNotes} />
                            </div>
                        ) : null}
                    </Panel>
                    {nav}
                </>
            ) : null}

            {currentStep === 2 ? (
                <>
                    <Panel
                        title="Incoming ownership"
                        description="Name the person who takes over. Only they can accept and start the incoming duty."
                    >
                        <div className="grid gap-x-[18px] gap-y-4 md:grid-cols-2">
                            <PopupField label="Incoming shift name" htmlFor="incoming-shift-name">
                                <Input
                                    id="incoming-shift-name"
                                    value={incomingShiftName}
                                    disabled={!shift.can_prepare}
                                    onChange={(event) => setIncomingShiftName(event.target.value)}
                                    placeholder="Night response desk"
                                />
                            </PopupField>
                            <PopupField
                                label="Incoming lead"
                                hint="Only this person can accept and activate the incoming shift."
                            >
                                <PeoplePicker
                                    value={incomingLeadUserId}
                                    onChange={setIncomingLeadUserId}
                                    people={people(eligibleLeads)}
                                    disabled={!shift.can_prepare}
                                    placeholder="Choose the person who must accept"
                                />
                            </PopupField>
                            <div className="md:col-span-2">
                                <PopupField
                                    label="Incoming team members"
                                    hint="Optional — the people working the desk with the incoming lead."
                                >
                                    <PeopleMultiPicker
                                        value={incomingTeamMembers.map(String)}
                                        onChange={(ids) => setIncomingTeamMembers(ids.map(Number))}
                                        people={people(staff)}
                                        disabled={!shift.can_prepare}
                                        placeholder="Choose team members"
                                    />
                                </PopupField>
                            </div>
                        </div>
                    </Panel>
                    {nav}
                </>
            ) : null}

            {currentStep === 3 ? (
                <>
                    <Panel title="Prepare, then wait for acceptance">
                        <PopupFacts
                            rows={[
                                ['Incoming lead', leadName ?? 'Not selected'],
                                [
                                    'Required work reviewed',
                                    `${reviewedAlertIds.length}/${requiredAlerts.length}`,
                                ],
                                [
                                    'Unchanged carry-forward',
                                    carryForward.total === 0
                                        ? 'Nothing to carry forward'
                                        : carryForwardAcknowledged
                                          ? `${carryForward.total} acknowledged`
                                          : 'Not acknowledged',
                                ],
                                ['Linked priorities', String(priorityAlertIds.length)],
                            ]}
                        />
                        <div className="rounded-[12px] border border-border bg-muted/30 p-4 text-sm">
                            <p className="font-medium">What happens next</p>
                            <ol className="mt-2 list-decimal space-y-1 pl-5 text-muted-foreground">
                                <li>This reviewed snapshot is frozen.</li>
                                <li>The outgoing shift stays active.</li>
                                <li>The selected incoming lead reviews and accepts.</li>
                                <li>Acceptance completes this shift and starts the next one once.</li>
                            </ol>
                        </div>
                        {!allRequiredReviewed ? (
                            <PopupCallout tone="critical">
                                Return to Required work and review every changed or
                                decision-relevant alert.
                            </PopupCallout>
                        ) : null}
                        {carryForward.total > 0 && !carryForwardAcknowledged ? (
                            <PopupCallout tone="critical">
                                Return to Required work and acknowledge the unchanged active alert
                                summary.
                            </PopupCallout>
                        ) : null}
                        {shift.can_override && overrideReason.trim().length < 10 ? (
                            <PopupCallout tone="critical">
                                Record an audited override reason of at least 10 characters.
                            </PopupCallout>
                        ) : null}
                    </Panel>
                    {nav}
                </>
            ) : null}
        </ControlRoomPage>
    );
}
