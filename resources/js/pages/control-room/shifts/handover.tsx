import { PageHero } from '@/components/page';
import PageShell from '@/components/page-shell';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { formatDateTime } from '@/lib/datetime';
import { Head, Link, router } from '@inertiajs/react';
import {
    AlertTriangle,
    ArrowLeft,
    ArrowRight,
    Check,
    CheckCircle2,
    Clock3,
    ExternalLink,
    RefreshCw,
    ShieldCheck,
    Users,
} from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';

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
}

interface HandoverDraft {
    handover_notes?: string;
    incoming_shift_name?: string;
    incoming_lead_user_id?: number | null;
    incoming_team_members?: number[];
    reviewed_alert_ids?: number[];
    priority_alert_ids?: number[];
}

interface HandoverSnapshot {
    prepared_by?: StaffMember;
    prepared_at?: string;
    handover_notes?: string;
    incoming_shift?: {
        id?: number;
        name: string;
        lead: StaffMember;
        team_members: StaffMember[];
    };
    reviewed_alert_ids?: number[];
    priority_alert_ids?: number[];
    alerts?: AlertSummary[];
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
    criticalAlertsCount: number;
    highAlertsCount: number;
    criticalAlerts: AlertSummary[];
    highAlerts: AlertSummary[];
    pinnedNotes: OperatorNote[];
    followupNotes: OperatorNote[];
    staff: StaffMember[];
    eligibleLeads: StaffMember[];
}

const STEPS = ['Urgent work', 'Context', 'Incoming team', 'Final review'];

function formatDuration(minutes: number | null): string {
    if (minutes === null) return 'Not available';
    const hours = Math.floor(minutes / 60);
    const remainder = minutes % 60;

    return hours > 0 ? `${hours}h ${remainder}m` : `${remainder}m`;
}

function alertReference(alert: AlertSummary): string {
    return alert.reference_number ?? `Alert ${alert.id}`;
}

function StepIndicator({ current }: { current: number }) {
    return (
        <nav aria-label="Shift handover progress" className="mb-6">
            <ol className="grid grid-cols-4 gap-3">
                {STEPS.map((step, index) => (
                    <li
                        key={step}
                        aria-current={index === current ? 'step' : undefined}
                        className={`rounded-lg border px-3 py-2 text-sm ${
                            index === current
                                ? 'border-primary bg-primary/5 font-semibold text-foreground'
                                : index < current
                                  ? 'border-status-success/30 bg-status-success-bg text-status-success'
                                  : 'text-muted-foreground'
                        }`}
                    >
                        <span className="mr-2 inline-flex h-5 w-5 items-center justify-center rounded-full border text-xs">
                            {index < current ? (
                                <Check className="h-3 w-3" />
                            ) : (
                                index + 1
                            )}
                        </span>
                        {step}
                    </li>
                ))}
            </ol>
        </nav>
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
    return (
        <article className="rounded-xl border bg-card p-4">
            <div className="flex items-start justify-between gap-6">
                <div className="min-w-0 flex-1">
                    <div className="flex flex-wrap items-center gap-2">
                        <Badge
                            variant="outline"
                            className={
                                alert.severity === 'critical'
                                    ? 'border-status-critical/30 bg-status-critical-bg text-status-critical'
                                    : 'border-status-warning/30 bg-status-warning-bg text-status-warning'
                            }
                        >
                            {alert.severity === 'critical'
                                ? 'Critical'
                                : 'High'}
                        </Badge>
                        <Link
                            href={alert.href}
                            className="font-semibold text-primary hover:underline"
                        >
                            {alertReference(alert)}
                            <ExternalLink className="ml-1 inline h-3.5 w-3.5" />
                        </Link>
                        {priority && <Badge>Carry-forward priority</Badge>}
                    </div>
                    <p className="mt-2 font-medium text-foreground">
                        {alert.summary}
                    </p>
                    <p className="mt-1 text-sm text-muted-foreground">
                        {[
                            alert.person?.name,
                            alert.site?.name,
                            alert.assignee?.name,
                        ]
                            .filter(Boolean)
                            .join(' · ') ||
                            'No person, site, or owner recorded'}
                    </p>
                    <p className="mt-1 text-sm text-muted-foreground">
                        SLA: {alert.sla.status ?? 'No SLA state'} ·{' '}
                        {alert.tasks.length} open{' '}
                        {alert.tasks.length === 1 ? 'task' : 'tasks'}
                        {alert.journey.incident_reference
                            ? ` · ${alert.journey.incident_reference}`
                            : ''}
                        {alert.journey.health_safety_reference
                            ? ` · ${alert.journey.health_safety_reference}`
                            : ''}
                    </p>
                    {alert.tasks.length > 0 && (
                        <ul className="mt-3 space-y-1 text-sm">
                            {alert.tasks.map((task) => (
                                <li
                                    key={task.id}
                                    className="flex items-center gap-2"
                                >
                                    <span className="h-1.5 w-1.5 rounded-full bg-primary" />
                                    {task.title}
                                    <span className="text-muted-foreground">
                                        {task.due_at
                                            ? `Due ${formatDateTime(task.due_at)}`
                                            : 'No due time'}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    )}
                </div>
                {editable && (
                    <div className="w-48 shrink-0 space-y-3 border-l pl-4">
                        <label className="flex items-center gap-2 text-sm font-medium">
                            <Checkbox
                                aria-label={`Reviewed ${alertReference(alert)}`}
                                checked={reviewed}
                                onCheckedChange={(value) =>
                                    onReviewedChange?.(value === true)
                                }
                            />
                            Reviewed
                        </label>
                        <label className="flex items-center gap-2 text-sm">
                            <Checkbox
                                aria-label={`Carry ${alertReference(alert)} as a priority`}
                                checked={priority}
                                disabled={!reviewed}
                                onCheckedChange={(value) =>
                                    onPriorityChange?.(value === true)
                                }
                            />
                            Carry as priority
                        </label>
                    </div>
                )}
            </div>
        </article>
    );
}

export default function ShiftHandover(props: Props) {
    const {
        shift,
        openAlertsCount,
        criticalAlertsCount,
        highAlertsCount,
        criticalAlerts,
        highAlerts,
        pinnedNotes,
        followupNotes,
        staff,
        eligibleLeads,
    } = props;
    const urgentAlerts = useMemo(
        () => [...criticalAlerts, ...highAlerts],
        [criticalAlerts, highAlerts],
    );
    const [currentStep, setCurrentStep] = useState(0);
    const [handoverNotes, setHandoverNotes] = useState(
        shift.draft.handover_notes ?? '',
    );
    const [incomingShiftName, setIncomingShiftName] = useState(
        shift.draft.incoming_shift_name ?? '',
    );
    const [incomingLeadUserId, setIncomingLeadUserId] = useState(
        shift.draft.incoming_lead_user_id
            ? String(shift.draft.incoming_lead_user_id)
            : '',
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
    const [version, setVersion] = useState(shift.handover_version);
    const versionRef = useRef(shift.handover_version);
    const [saveState, setSaveState] = useState<'saved' | 'unsaved' | 'saving'>(
        'saved',
    );
    const [submitting, setSubmitting] = useState(false);
    const [conflictMessage, setConflictMessage] = useState<string | null>(null);
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
                    incoming_lead_user_id: incomingLeadUserId
                        ? Number(incomingLeadUserId)
                        : null,
                    incoming_team_members: incomingTeamMembers,
                    reviewed_alert_ids: reviewedAlertIds,
                    priority_alert_ids: priorityAlertIds,
                    expected_version: versionRef.current,
                },
                {
                    preserveScroll: true,
                    preserveState: true,
                    only: ['shift'],
                    onSuccess: (page) => {
                        const updated = (
                            page.props as unknown as { shift: ShiftData }
                        ).shift;
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
        incomingLeadUserId,
        incomingShiftName,
        incomingTeamMembers,
        priorityAlertIds,
        reviewedAlertIds,
        shift.can_prepare,
        shift.handover_status,
        shift.id,
    ]);

    const setReviewed = (alertId: number, checked: boolean) => {
        setReviewedAlertIds((current) =>
            checked
                ? [...new Set([...current, alertId])]
                : current.filter((id) => id !== alertId),
        );
        if (!checked) {
            setPriorityAlertIds((current) =>
                current.filter((id) => id !== alertId),
            );
        }
    };

    const setPriority = (alertId: number, checked: boolean) => {
        setPriorityAlertIds((current) =>
            checked
                ? [...new Set([...current, alertId])]
                : current.filter((id) => id !== alertId),
        );
    };

    const toggleTeamMember = (userId: number) => {
        setIncomingTeamMembers((current) =>
            current.includes(userId)
                ? current.filter((id) => id !== userId)
                : [...current, userId],
        );
    };

    const allUrgentReviewed = urgentAlerts.every((alert) =>
        reviewedAlertIds.includes(alert.id),
    );
    const readyToPrepare =
        Boolean(incomingLeadUserId) &&
        allUrgentReviewed &&
        saveState === 'saved';

    const prepare = () => {
        if (!readyToPrepare || submitting) return;
        setSubmitting(true);
        router.post(
            `/control-room/shifts/${shift.id}/handover`,
            {
                incoming_lead_user_id: Number(incomingLeadUserId),
                reviewed_alert_ids: reviewedAlertIds,
                expected_version: version,
            },
            {
                preserveScroll: true,
                onError: (errors) => {
                    setConflictMessage(
                        String(
                            errors.handover_version ??
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
        router.post(
            `/control-room/shifts/${shift.id}/accept-handover`,
            { expected_version: shift.handover_version },
            {
                onError: (errors) =>
                    setConflictMessage(
                        String(
                            errors.handover_version ??
                                errors.handover ??
                                'The handover could not be accepted.',
                        ),
                    ),
                onFinish: () => setSubmitting(false),
            },
        );
    };

    if (shift.handover_status === 'prepared' && shift.handover_snapshot) {
        const snapshot = shift.handover_snapshot;
        const alerts = snapshot.alerts ?? [];
        const priorities = new Set(snapshot.priority_alert_ids ?? []);

        return (
            <AppLayout
                breadcrumbs={[
                    { title: 'Control Room', href: '/control-room' },
                    { title: 'Shifts', href: '/control-room/shifts' },
                    { title: 'Prepared handover', href: '#' },
                ]}
            >
                <Head title={`Prepared handover - ${shift.name}`} />
                <PageShell>
                    <PageHero
                        variant="compact"
                        title="Prepared shift handover"
                        description={`${shift.name} remains active until ${shift.incoming_lead?.name ?? 'the incoming lead'} accepts this snapshot.`}
                        backHref="/control-room/shifts"
                        backLabel="Back to Control Room shifts"
                    />

                    {conflictMessage && (
                        <div
                            role="alert"
                            className="mb-5 rounded-lg border border-status-critical/30 bg-status-critical-bg p-4 text-sm text-status-critical"
                        >
                            {conflictMessage} Reload this page before trying
                            again.
                        </div>
                    )}

                    <Card className="mb-6 border-status-warning/30 bg-status-warning-bg/30">
                        <CardContent className="flex items-center justify-between gap-8 py-5">
                            <div>
                                <div className="flex items-center gap-2">
                                    <ShieldCheck className="h-5 w-5 text-status-warning" />
                                    <h2 className="font-semibold">
                                        Prepared, not yet transferred
                                    </h2>
                                    <Badge variant="outline">Prepared</Badge>
                                </div>
                                <p className="mt-2 text-sm text-muted-foreground">
                                    Prepared by{' '}
                                    {snapshot.prepared_by?.name ??
                                        'the outgoing lead'}{' '}
                                    on {formatDateTime(snapshot.prepared_at)}.
                                    Incoming lead:{' '}
                                    <strong>
                                        {snapshot.incoming_shift?.lead.name}
                                    </strong>
                                    .
                                </p>
                            </div>
                            {shift.can_accept ? (
                                <Button
                                    size="lg"
                                    onClick={accept}
                                    disabled={submitting}
                                >
                                    <CheckCircle2 className="mr-2 h-4 w-4" />
                                    {submitting
                                        ? 'Accepting…'
                                        : 'Accept and start my shift'}
                                </Button>
                            ) : (
                                <p className="max-w-xs text-right text-sm text-muted-foreground">
                                    Waiting for{' '}
                                    {snapshot.incoming_shift?.lead.name} to
                                    review and accept.
                                </p>
                            )}
                        </CardContent>
                    </Card>

                    <div className="mb-6 grid grid-cols-3 gap-4">
                        <Card>
                            <CardContent className="py-4">
                                <p className="text-sm text-muted-foreground">
                                    Incoming shift
                                </p>
                                <p className="font-semibold">
                                    {snapshot.incoming_shift?.name}
                                </p>
                            </CardContent>
                        </Card>
                        <Card>
                            <CardContent className="py-4">
                                <p className="text-sm text-muted-foreground">
                                    Urgent alerts reviewed
                                </p>
                                <p className="font-semibold">{alerts.length}</p>
                            </CardContent>
                        </Card>
                        <Card>
                            <CardContent className="py-4">
                                <p className="text-sm text-muted-foreground">
                                    Carry-forward priorities
                                </p>
                                <p className="font-semibold">
                                    {priorities.size}
                                </p>
                            </CardContent>
                        </Card>
                    </div>

                    <Card className="mb-6">
                        <CardHeader>
                            <CardTitle>Outgoing context</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <p className="text-sm whitespace-pre-wrap">
                                {snapshot.handover_notes ||
                                    'No additional handover notes.'}
                            </p>
                        </CardContent>
                    </Card>

                    <section aria-labelledby="snapshot-alerts-title">
                        <div className="mb-3 flex items-center justify-between">
                            <div>
                                <h2
                                    id="snapshot-alerts-title"
                                    className="text-lg font-semibold"
                                >
                                    Frozen urgent-work snapshot
                                </h2>
                                <p className="text-sm text-muted-foreground">
                                    Open a row to continue in the canonical
                                    alert workspace.
                                </p>
                            </div>
                            <Button asChild variant="outline">
                                <Link href="/tasks">Open universal tasks</Link>
                            </Button>
                        </div>
                        <div className="space-y-3">
                            {alerts.map((alert) => (
                                <AlertReviewRow
                                    key={alert.id}
                                    alert={alert}
                                    reviewed
                                    priority={priorities.has(alert.id)}
                                    editable={false}
                                />
                            ))}
                            {alerts.length === 0 && (
                                <Card>
                                    <CardContent className="py-8 text-center text-sm text-muted-foreground">
                                        No critical or high alerts were open
                                        when this handover was prepared.
                                    </CardContent>
                                </Card>
                            )}
                        </div>
                    </section>
                </PageShell>
            </AppLayout>
        );
    }

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Control Room', href: '/control-room' },
                { title: 'Shifts', href: '/control-room/shifts' },
                { title: 'Prepare handover', href: '#' },
            ]}
        >
            <Head title={`Prepare handover - ${shift.name}`} />
            <PageShell>
                <PageHero
                    variant="compact"
                    title="Prepare shift handover"
                    description="Review live urgent work, name the incoming lead, and save a clear handover. The outgoing shift remains active until acceptance."
                    backHref="/control-room/shifts"
                    backLabel="Back to Control Room shifts"
                />

                {!shift.can_prepare && (
                    <div className="mb-5 rounded-lg border border-status-warning/30 bg-status-warning-bg p-4 text-sm">
                        Only the outgoing shift lead,{' '}
                        {shift.shift_lead?.name ?? 'currently unassigned'}, can
                        prepare this handover.
                    </div>
                )}
                {conflictMessage && (
                    <div
                        role="alert"
                        className="mb-5 rounded-lg border border-status-critical/30 bg-status-critical-bg p-4 text-sm text-status-critical"
                    >
                        {conflictMessage}
                    </div>
                )}

                <div className="mb-5 flex items-center justify-between rounded-lg border bg-muted/30 px-4 py-3">
                    <div className="flex items-center gap-3 text-sm">
                        <Clock3 className="h-4 w-4 text-muted-foreground" />
                        <span>
                            {shift.name} ·{' '}
                            {formatDuration(shift.duration_minutes)} ·{' '}
                            {openAlertsCount} active alerts
                        </span>
                    </div>
                    <div
                        className="flex items-center gap-2 text-sm"
                        aria-live="polite"
                    >
                        {saveState === 'saving' && (
                            <RefreshCw className="h-4 w-4 animate-spin" />
                        )}
                        {saveState === 'saved' && (
                            <Check className="h-4 w-4 text-status-success" />
                        )}
                        <span>
                            {saveState === 'saving'
                                ? 'Saving…'
                                : saveState === 'saved'
                                  ? 'Saved'
                                  : 'Unsaved changes'}
                        </span>
                    </div>
                </div>

                <StepIndicator current={currentStep} />

                {currentStep === 0 && (
                    <div className="space-y-5">
                        <Card>
                            <CardHeader>
                                <CardTitle className="flex items-center gap-2">
                                    <AlertTriangle className="h-5 w-5" />
                                    Review every critical and high alert
                                </CardTitle>
                            </CardHeader>
                            <CardContent>
                                <div className="mb-4 grid grid-cols-3 gap-4 text-sm">
                                    <div>
                                        <span className="text-muted-foreground">
                                            Critical
                                        </span>
                                        <p className="text-xl font-semibold">
                                            {criticalAlertsCount}
                                        </p>
                                    </div>
                                    <div>
                                        <span className="text-muted-foreground">
                                            High
                                        </span>
                                        <p className="text-xl font-semibold">
                                            {highAlertsCount}
                                        </p>
                                    </div>
                                    <div>
                                        <span className="text-muted-foreground">
                                            Reviewed
                                        </span>
                                        <p className="text-xl font-semibold">
                                            {
                                                reviewedAlertIds.filter((id) =>
                                                    urgentAlerts.some(
                                                        (alert) =>
                                                            alert.id === id,
                                                    ),
                                                ).length
                                            }
                                            /{urgentAlerts.length}
                                        </p>
                                    </div>
                                </div>
                                <div className="space-y-3">
                                    {urgentAlerts.map((alert) => (
                                        <AlertReviewRow
                                            key={alert.id}
                                            alert={alert}
                                            reviewed={reviewedAlertIds.includes(
                                                alert.id,
                                            )}
                                            priority={priorityAlertIds.includes(
                                                alert.id,
                                            )}
                                            editable={shift.can_prepare}
                                            onReviewedChange={(checked) =>
                                                setReviewed(alert.id, checked)
                                            }
                                            onPriorityChange={(checked) =>
                                                setPriority(alert.id, checked)
                                            }
                                        />
                                    ))}
                                    {urgentAlerts.length === 0 && (
                                        <div className="rounded-lg border border-status-success/30 bg-status-success-bg p-5 text-sm text-status-success">
                                            No critical or high alerts are
                                            waiting for handover.
                                        </div>
                                    )}
                                </div>
                            </CardContent>
                        </Card>
                        <div className="flex justify-end">
                            <Button onClick={() => setCurrentStep(1)}>
                                Continue to context
                                <ArrowRight className="ml-2 h-4 w-4" />
                            </Button>
                        </div>
                    </div>
                )}

                {currentStep === 1 && (
                    <div className="space-y-5">
                        <Card>
                            <CardHeader>
                                <CardTitle>
                                    What must the incoming lead know?
                                </CardTitle>
                            </CardHeader>
                            <CardContent className="space-y-5">
                                <div>
                                    <Label htmlFor="handover-notes">
                                        Operational context
                                    </Label>
                                    <p className="mb-2 text-sm text-muted-foreground">
                                        Record decisions, dependencies, or a
                                        change of plan. Linked alert rows carry
                                        the actual work.
                                    </p>
                                    <Textarea
                                        id="handover-notes"
                                        rows={8}
                                        value={handoverNotes}
                                        disabled={!shift.can_prepare}
                                        onChange={(event) =>
                                            setHandoverNotes(event.target.value)
                                        }
                                        placeholder="Example: Family has been contacted. Continue 15-minute welfare updates until the on-call manager arrives."
                                    />
                                </div>

                                {(pinnedNotes.length > 0 ||
                                    followupNotes.length > 0) && (
                                    <div className="grid grid-cols-2 gap-4">
                                        <div className="rounded-lg border p-4">
                                            <h3 className="font-medium">
                                                Pinned notes
                                            </h3>
                                            {pinnedNotes.map((note) => (
                                                <p
                                                    key={note.id}
                                                    className="mt-2 text-sm"
                                                >
                                                    {note.content}
                                                </p>
                                            ))}
                                            {pinnedNotes.length === 0 && (
                                                <p className="mt-2 text-sm text-muted-foreground">
                                                    None
                                                </p>
                                            )}
                                        </div>
                                        <div className="rounded-lg border p-4">
                                            <h3 className="font-medium">
                                                Follow-up notes
                                            </h3>
                                            {followupNotes.map((note) => (
                                                <p
                                                    key={note.id}
                                                    className="mt-2 text-sm"
                                                >
                                                    {note.content}
                                                </p>
                                            ))}
                                            {followupNotes.length === 0 && (
                                                <p className="mt-2 text-sm text-muted-foreground">
                                                    None
                                                </p>
                                            )}
                                        </div>
                                    </div>
                                )}
                            </CardContent>
                        </Card>
                        <div className="flex justify-between">
                            <Button
                                variant="outline"
                                onClick={() => setCurrentStep(0)}
                            >
                                <ArrowLeft className="mr-2 h-4 w-4" /> Back
                            </Button>
                            <Button onClick={() => setCurrentStep(2)}>
                                Continue to incoming team
                                <ArrowRight className="ml-2 h-4 w-4" />
                            </Button>
                        </div>
                    </div>
                )}

                {currentStep === 2 && (
                    <div className="space-y-5">
                        <Card>
                            <CardHeader>
                                <CardTitle className="flex items-center gap-2">
                                    <Users className="h-5 w-5" /> Incoming
                                    ownership
                                </CardTitle>
                            </CardHeader>
                            <CardContent className="space-y-5">
                                <div className="grid grid-cols-2 gap-5">
                                    <div>
                                        <Label htmlFor="incoming-shift-name">
                                            Incoming shift name
                                        </Label>
                                        <Input
                                            id="incoming-shift-name"
                                            className="mt-2"
                                            value={incomingShiftName}
                                            disabled={!shift.can_prepare}
                                            onChange={(event) =>
                                                setIncomingShiftName(
                                                    event.target.value,
                                                )
                                            }
                                            placeholder="Night response desk"
                                        />
                                    </div>
                                    <div>
                                        <Label htmlFor="incoming-lead">
                                            Incoming lead
                                        </Label>
                                        <Select
                                            value={incomingLeadUserId}
                                            disabled={!shift.can_prepare}
                                            onValueChange={
                                                setIncomingLeadUserId
                                            }
                                        >
                                            <SelectTrigger
                                                id="incoming-lead"
                                                className="mt-2"
                                            >
                                                <SelectValue placeholder="Select the person who must accept" />
                                            </SelectTrigger>
                                            <SelectContent>
                                                {eligibleLeads.map((person) => (
                                                    <SelectItem
                                                        key={person.id}
                                                        value={String(
                                                            person.id,
                                                        )}
                                                    >
                                                        {person.name}
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                        <p className="mt-2 text-sm text-muted-foreground">
                                            Only this person can accept and
                                            activate the incoming shift.
                                        </p>
                                    </div>
                                </div>
                                <div>
                                    <Label>Incoming team members</Label>
                                    <div className="mt-2 grid grid-cols-3 gap-2">
                                        {staff.map((person) => (
                                            <label
                                                key={person.id}
                                                className="flex items-center gap-2 rounded-lg border p-3 text-sm"
                                            >
                                                <Checkbox
                                                    checked={incomingTeamMembers.includes(
                                                        person.id,
                                                    )}
                                                    disabled={
                                                        !shift.can_prepare
                                                    }
                                                    onCheckedChange={() =>
                                                        toggleTeamMember(
                                                            person.id,
                                                        )
                                                    }
                                                />
                                                {person.name}
                                            </label>
                                        ))}
                                    </div>
                                </div>
                            </CardContent>
                        </Card>
                        <div className="flex justify-between">
                            <Button
                                variant="outline"
                                onClick={() => setCurrentStep(1)}
                            >
                                <ArrowLeft className="mr-2 h-4 w-4" /> Back
                            </Button>
                            <Button
                                disabled={!incomingLeadUserId}
                                onClick={() => setCurrentStep(3)}
                            >
                                Continue to final review
                                <ArrowRight className="ml-2 h-4 w-4" />
                            </Button>
                        </div>
                    </div>
                )}

                {currentStep === 3 && (
                    <div className="space-y-5">
                        <Card>
                            <CardHeader>
                                <CardTitle>
                                    Prepare, then wait for acceptance
                                </CardTitle>
                            </CardHeader>
                            <CardContent className="space-y-5">
                                <div className="grid grid-cols-3 gap-4">
                                    <div className="rounded-lg border p-4">
                                        <p className="text-sm text-muted-foreground">
                                            Incoming lead
                                        </p>
                                        <p className="font-semibold">
                                            {eligibleLeads.find(
                                                (person) =>
                                                    String(person.id) ===
                                                    incomingLeadUserId,
                                            )?.name ?? 'Not selected'}
                                        </p>
                                    </div>
                                    <div className="rounded-lg border p-4">
                                        <p className="text-sm text-muted-foreground">
                                            Urgent work reviewed
                                        </p>
                                        <p className="font-semibold">
                                            {reviewedAlertIds.length}/
                                            {urgentAlerts.length}
                                        </p>
                                    </div>
                                    <div className="rounded-lg border p-4">
                                        <p className="text-sm text-muted-foreground">
                                            Linked priorities
                                        </p>
                                        <p className="font-semibold">
                                            {priorityAlertIds.length}
                                        </p>
                                    </div>
                                </div>
                                <div className="rounded-lg border bg-muted/30 p-4 text-sm">
                                    <p className="font-medium">
                                        What happens next
                                    </p>
                                    <ol className="mt-2 list-decimal space-y-1 pl-5 text-muted-foreground">
                                        <li>
                                            This reviewed snapshot is frozen.
                                        </li>
                                        <li>
                                            The outgoing shift stays active.
                                        </li>
                                        <li>
                                            The selected incoming lead reviews
                                            and accepts.
                                        </li>
                                        <li>
                                            Acceptance completes this shift and
                                            starts the next one once.
                                        </li>
                                    </ol>
                                </div>
                                {!allUrgentReviewed && (
                                    <div className="rounded-lg border border-status-critical/30 bg-status-critical-bg p-4 text-sm text-status-critical">
                                        Return to Urgent work and review every
                                        critical and high alert.
                                    </div>
                                )}
                            </CardContent>
                        </Card>
                        <div className="flex justify-between">
                            <Button
                                variant="outline"
                                onClick={() => setCurrentStep(2)}
                            >
                                <ArrowLeft className="mr-2 h-4 w-4" /> Back
                            </Button>
                            <Button
                                size="lg"
                                disabled={
                                    !readyToPrepare ||
                                    submitting ||
                                    !shift.can_prepare
                                }
                                onClick={prepare}
                            >
                                {submitting
                                    ? 'Preparing…'
                                    : saveState !== 'saved'
                                      ? 'Waiting for draft to save…'
                                      : 'Prepare for incoming acceptance'}
                            </Button>
                        </div>
                    </div>
                )}
            </PageShell>
        </AppLayout>
    );
}
