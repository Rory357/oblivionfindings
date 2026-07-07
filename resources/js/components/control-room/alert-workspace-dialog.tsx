import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { Field, InfoCard, SelectInput, StepHead } from '@/components/wizard/primitives';
import { ReviewCard, ReviewRow, WizardShell } from '@/components/wizard/shell';
import { formatDateTime } from '@/lib/datetime';
import { Link, router, useForm } from '@inertiajs/react';
import {
    Activity,
    AlertTriangle,
    ArrowUpCircle,
    BookOpen,
    Check,
    CheckCircle2,
    ClipboardList,
    Clock,
    Download,
    ExternalLink,
    Eye,
    FileText,
    LinkIcon,
    ListTodo,
    MapPin,
    MessageSquare,
    Package,
    Paperclip,
    Pencil,
    Play,
    Plus,
    Radar,
    RadioTower,
    Send,
    ShieldAlert,
    ShieldQuestion,
    SkipForward,
    Timer,
    Trash2,
    Truck,
    User,
    UserCheck,
    UserMinus,
    X,
} from 'lucide-react';
import { useRef, useState, type ComponentType, type FormEvent, type ReactNode } from 'react';

/* ------------------------------------------------------------------ */
/*  Types — mirrors AlertWorkspaceService::build()                      */
/* ------------------------------------------------------------------ */

type UserRef = { id: number; name: string };

export type WorkspaceAlert = {
    id: number;
    source: string;
    alert_type: string;
    severity: string;
    status: string;
    asset_id: number | null;
    asset: { id: number; name: string; asset_tag: string } | null;
    fleet_signal_id: number | null;
    fleet_signal: { id: number; signal_type: string; severity_hint: string; occurred_at: string | null; payload: Record<string, unknown> | null } | null;
    fleet_context: Record<string, unknown> | null;
    assigned_to_user_id: number | null;
    assigned_to: { id: number; name: string; email: string } | null;
    acknowledged_by: UserRef | null;
    resolved_by: UserRef | null;
    closed_by: UserRef | null;
    escalated_by: UserRef | null;
    assigned_by: UserRef | null;
    created_by: UserRef | null;
    triggered_at: string | null;
    acknowledged_at: string | null;
    resolved_at: string | null;
    closed_at: string | null;
    escalated_at: string | null;
    assigned_at: string | null;
    escalation_level: number;
    context: Record<string, unknown> | null;
    notes: string | null;
    priority: string | null;
    due_at: string | null;
    category: string | null;
    resolution_code: string | null;
    created_at: string | null;
    updated_at: string | null;
};

export type AlertWorkspaceDetail = {
    alert: WorkspaceAlert;
    playbook_run: {
        id: number;
        status: string;
        current_step: number;
        completed_steps: number;
        total_steps: number;
        playbook: { id: number; name: string; category: string };
        steps: Array<{ id: number; title: string; status: string; notes: string | null; completed_at: string | null }>;
    } | null;
    available_playbooks: Array<{ id: number; name: string; category: string; description: string | null }>;
    evidence_packs: Array<{
        id: number;
        title: string;
        status: string;
        item_count: number;
        items: Array<{ id: number; type: string; title: string; file_path: string | null; created_at: string | null }>;
    }>;
    communications: Array<{
        id: number;
        channel: string;
        direction: string;
        purpose: string | null;
        status: string;
        content: string | null;
        target_user_name: string | null;
        sent_at: string | null;
        created_at: string | null;
    }>;
    sla: {
        acknowledge_deadline: string | null;
        response_deadline: string | null;
        resolution_deadline: string | null;
        acknowledge_breached: boolean;
        response_breached: boolean;
        resolution_breached: boolean;
    } | null;
    client: { id: number; name: string } | null;
    location: { lat: number; lng: number; description: string | null } | null;
    audit_logs: Array<{ id: number; action: string; user: UserRef | null; meta: Record<string, unknown> | null; created_at: string }>;
    can: { manage: boolean; assign: boolean; escalate: boolean; create: boolean };
    staff: Array<{ id: number; name: string; email: string }>;
    tasks: Array<{
        id: number;
        title: string;
        description: string | null;
        status: string;
        priority: string;
        due_at: string | null;
        completed_at: string | null;
        assigned_to: UserRef | null;
        created_by_name: string | null;
        subtasks: Array<{ id: number; title: string; status: string; assigned_to: UserRef | null }>;
        created_at: string;
    }>;
    discussions: Array<{
        id: number;
        type: string;
        content: string;
        is_internal: boolean;
        user: UserRef;
        edited_at: string | null;
        created_at: string;
        replies: Array<{ id: number; type: string; content: string; is_internal: boolean; user: UserRef; edited_at: string | null; created_at: string }>;
    }>;
    watchers: Array<{ id: number; user_id: number; user_name: string }>;
    time_entries: Array<{
        id: number;
        user_name: string;
        user_id: number;
        started_at: string | null;
        ended_at: string | null;
        duration_minutes: number | null;
        description: string | null;
        is_running: boolean;
        created_at: string;
    }>;
    time_spent_minutes: number;
    is_watching: boolean;
    config_options: { categories: Array<{ value: string; label: string }>; resolution_codes: Array<{ value: string; label: string }> };
    linked_hs_event: {
        id: number;
        reference_number: string;
        status: string;
        investigation_required: boolean;
        investigation: { reference_number: string; status: string } | null;
    } | null;
};

type SectionKey = 'overview' | 'sla' | 'playbook' | 'evidence' | 'tasks' | 'activity' | 'linked';

type ActionKey =
    | 'acknowledge'
    | 'triage'
    | 'resolve'
    | 'close'
    | 'escalate'
    | 'assign'
    | 'confirm_sensor'
    | 'dismiss_sensor'
    | 'edit_meta'
    | 'start_playbook';

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

const SEV_LABEL: Record<string, string> = { low: 'Low', medium: 'Medium', high: 'High', critical: 'Critical' };
const SEV_TONE: Record<string, string> = { low: 'info', medium: 'warning', high: 'critical', critical: 'critical' };

const STATUS_META: Record<string, { label: string; tone: string }> = {
    open: { label: 'Open', tone: 'critical' },
    ack: { label: 'Acknowledged', tone: 'warning' },
    triaging: { label: 'Triaging', tone: 'info' },
    resolved: { label: 'Resolved', tone: 'success' },
    closed: { label: 'Closed', tone: 'neutral' },
    confirmed: { label: 'Confirmed → incident', tone: 'primary' },
    dismissed: { label: 'Dismissed (false positive)', tone: 'neutral' },
};

const LIFECYCLE: ReadonlyArray<{ key: string; label: string }> = [
    { key: 'open', label: 'Open' },
    { key: 'ack', label: 'Acknowledged' },
    { key: 'triaging', label: 'Triaging' },
    { key: 'resolved', label: 'Resolved' },
    { key: 'closed', label: 'Closed' },
];

const OPEN_STATES = ['open', 'ack', 'triaging'];

function titleCase(s: string | null | undefined): string {
    if (!s) return '';
    return s.replace(/[._]/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());
}

function summarise(alert: WorkspaceAlert): string {
    const ctx = (alert.context ?? {}) as Record<string, any>;
    const norm = (ctx.normalized_data ?? ctx) as Record<string, any>;
    const title = typeof norm.title === 'string' && norm.title.trim() ? norm.title.trim() : null;
    const desc = typeof norm.description === 'string' && norm.description.trim() ? norm.description.trim() : null;
    return title ?? desc ?? alert.notes ?? titleCase(alert.alert_type);
}

/* ------------------------------------------------------------------ */
/*  Shared pane chrome                                                 */
/* ------------------------------------------------------------------ */

/** Footer nav for a guided action pane: Cancel · Back ← → Next / primary CTA.
 *  Exported for the bulk-action and other control-room stepped dialogs. */
export function PaneNav({
    onCancel,
    onBack,
    onNext,
    onSubmit,
    step,
    stepCount,
    nextDisabled,
    submitDisabled,
    submitLabel,
    processing,
    destructive,
}: {
    onCancel: () => void;
    onBack?: () => void;
    onNext?: () => void;
    onSubmit?: () => void;
    step?: number;
    stepCount?: number;
    nextDisabled?: boolean;
    submitDisabled?: boolean;
    submitLabel?: string;
    processing?: boolean;
    destructive?: boolean;
}) {
    return (
        <div className="mt-2 flex items-center justify-between gap-3 border-t border-border pt-4">
            <div className="text-xs text-muted-foreground">
                {stepCount && stepCount > 1 ? `Step ${(step ?? 0) + 1} of ${stepCount}` : null}
            </div>
            <div className="flex items-center gap-2">
                <Button type="button" variant="outline" size="sm" onClick={onCancel}>
                    Cancel
                </Button>
                {onBack ? (
                    <Button type="button" variant="outline" size="sm" onClick={onBack}>
                        Back
                    </Button>
                ) : null}
                {onNext ? (
                    <Button type="button" size="sm" onClick={onNext} disabled={nextDisabled}>
                        Next
                    </Button>
                ) : null}
                {onSubmit ? (
                    <Button type="button" size="sm" variant={destructive ? 'destructive' : 'default'} onClick={onSubmit} disabled={submitDisabled || processing}>
                        {submitLabel ?? 'Confirm'}
                    </Button>
                ) : null}
            </div>
        </div>
    );
}

/** Two-phase row action: click → inline "Sure?" confirm — nothing fires on a single click. */
export function ConfirmChip({
    label,
    icon: Icon,
    onConfirm,
    destructive,
    title,
}: {
    label: string;
    icon: ComponentType<{ className?: string }>;
    onConfirm: () => void;
    destructive?: boolean;
    title?: string;
}) {
    const [arming, setArming] = useState(false);
    if (!arming) {
        return (
            <Button variant="outline" size="sm" title={title} onClick={() => setArming(true)}>
                <Icon className="mr-1.5 h-3.5 w-3.5" /> {label}
            </Button>
        );
    }
    return (
        <span className="inline-flex items-center gap-1">
            <Button variant={destructive ? 'destructive' : 'default'} size="sm" onClick={() => { onConfirm(); setArming(false); }}>
                <Check className="mr-1 h-3.5 w-3.5" /> {label}?
            </Button>
            <Button variant="ghost" size="sm" onClick={() => setArming(false)} aria-label="Cancel">
                <X className="h-3.5 w-3.5" />
            </Button>
        </span>
    );
}

/** Server guardrail errors come back under errors.alert / errors.pack (not a
 *  form field), so they're outside useForm's typed bag — read them loosely. */
function serverError(errors: object): string | undefined {
    const bag = errors as Record<string, string | undefined>;
    return bag.alert ?? bag.pack;
}

function PaneError({ message }: { message?: string }) {
    if (!message) return null;
    return (
        <InfoCard icon={AlertTriangle} tone="crit">
            {message}
        </InfoCard>
    );
}

const onPaneSuccess = (done: () => void) => (page: { props: Record<string, unknown> }) => {
    const flash = (page.props as { flash?: { error?: string } }).flash;
    if (!flash?.error) done();
};

/* ------------------------------------------------------------------ */
/*  Dialog                                                             */
/* ------------------------------------------------------------------ */

export function AlertWorkspaceDialog({ detail, open, onClose }: { detail: AlertWorkspaceDetail; open: boolean; onClose: () => void }) {
    const [section, setSection] = useState<SectionKey>('overview');
    const [action, setAction] = useState<ActionKey | null>(null);

    const d = detail;
    const a = d.alert;
    const alertRef = `CR-${a.id}`;
    const isSensor = a.source === 'sensor';
    const isActionable = OPEN_STATES.includes(a.status);
    const openTasks = d.tasks.filter((t) => t.status !== 'completed' && t.status !== 'cancelled').length;
    const statusMeta = STATUS_META[a.status] ?? { label: titleCase(a.status), tone: 'neutral' };

    const SECTIONS: { key: SectionKey; label: string; blurb: string; icon: ComponentType<{ className?: string }> }[] = [
        { key: 'overview', label: 'Overview', blurb: "What's happening", icon: FileText },
        { key: 'sla', label: 'SLA & timeline', blurb: d.sla ? 'deadlines & audit' : 'audit trail', icon: Clock },
        { key: 'playbook', label: 'Playbook', blurb: d.playbook_run ? `${d.playbook_run.completed_steps}/${d.playbook_run.total_steps} steps` : 'not started', icon: BookOpen },
        { key: 'evidence', label: 'Evidence', blurb: d.evidence_packs.length ? `${d.evidence_packs.length} pack${d.evidence_packs.length === 1 ? '' : 's'}` : 'no packs', icon: Package },
        { key: 'tasks', label: 'Tasks', blurb: openTasks > 0 ? `${openTasks} open` : 'none open', icon: ListTodo },
        { key: 'activity', label: 'Notes & comms', blurb: 'operator log', icon: MessageSquare },
        { key: 'linked', label: 'Linked records', blurb: 'incident · H&S · client', icon: LinkIcon },
    ];
    const stepIndex = SECTIONS.findIndex((s) => s.key === section);

    // Header line: the rail entries are sections (not sequential steps), so
    // never say "Step x of 7" — show the open pane's title, or the section.
    const ACTION_TITLES: Record<ActionKey, string> = {
        acknowledge: 'Acknowledge alert',
        triage: 'Start triage',
        resolve: 'Resolve alert',
        close: 'Close alert',
        escalate: 'Escalate alert',
        assign: a.assigned_to ? 'Reassign alert' : 'Assign alert',
        confirm_sensor: 'Confirm sensor detection',
        dismiss_sensor: 'Dismiss as false positive',
        edit_meta: 'Edit alert details',
        start_playbook: 'Start a playbook',
    };
    const headerLabel = action ? ACTION_TITLES[action] : (SECTIONS[stepIndex]?.label ?? 'Overview');

    const closePane = () => setAction(null);

    // While an action pane is open it owns the body + its own buttons.
    const footerEnd = action ? null : (
        <div className="flex flex-wrap items-center justify-end gap-2">
            <Link href={`/control-room/alerts/${a.id}`} className="inline-flex items-center gap-1.5 rounded-lg px-3 py-2 text-sm font-medium text-muted-foreground transition-colors hover:bg-muted">
                <ExternalLink className="h-4 w-4" /> Full page
            </Link>
            {d.can.manage && isSensor && isActionable ? (
                <>
                    <Button size="sm" variant="outline" onClick={() => setAction('dismiss_sensor')}>
                        <X className="mr-1.5 h-4 w-4" /> Dismiss
                    </Button>
                    <Button size="sm" onClick={() => setAction('confirm_sensor')}>
                        <Radar className="mr-1.5 h-4 w-4" /> Confirm detection
                    </Button>
                </>
            ) : null}
            {d.can.manage && a.status === 'open' ? (
                <Button size="sm" onClick={() => setAction('acknowledge')}>
                    <UserCheck className="mr-1.5 h-4 w-4" /> Acknowledge
                </Button>
            ) : null}
            {d.can.manage && (a.status === 'open' || a.status === 'ack') ? (
                <Button size="sm" variant={a.status === 'ack' ? 'default' : 'outline'} onClick={() => setAction('triage')}>
                    <Eye className="mr-1.5 h-4 w-4" /> Start triage
                </Button>
            ) : null}
            {d.can.manage && isActionable ? (
                <Button size="sm" variant={a.status === 'triaging' ? 'default' : 'outline'} onClick={() => setAction('resolve')}>
                    <CheckCircle2 className="mr-1.5 h-4 w-4" /> Resolve
                </Button>
            ) : null}
            {d.can.manage && a.status === 'resolved' ? (
                <Button size="sm" onClick={() => setAction('close')}>
                    <CheckCircle2 className="mr-1.5 h-4 w-4" /> Close
                </Button>
            ) : null}
            {d.can.escalate && isActionable ? (
                <Button size="sm" variant="outline" onClick={() => setAction('escalate')}>
                    <ArrowUpCircle className="mr-1.5 h-4 w-4" /> Escalate
                </Button>
            ) : null}
            {d.can.assign && isActionable ? (
                <Button size="sm" variant="outline" onClick={() => setAction('assign')}>
                    <User className="mr-1.5 h-4 w-4" /> {a.assigned_to ? 'Reassign' : 'Assign'}
                </Button>
            ) : null}
        </div>
    );

    const footerStart = (
        <div className="flex items-center gap-2 text-xs">
            <span className="inline-flex items-center gap-1 rounded-full bg-muted px-2 py-0.5 font-medium">
                <span className={`h-1.5 w-1.5 rounded-full ${DOT[SEV_TONE[a.severity] ?? 'neutral']}`} />
                {SEV_LABEL[a.severity] ?? titleCase(a.severity)}
            </span>
            <span className="text-muted-foreground">{statusMeta.label}</span>
            {a.escalation_level > 0 ? <span className="font-medium text-status-warning">L{a.escalation_level}</span> : null}
        </div>
    );

    const railExtra = (
        <WatchToggle alertId={a.id} watching={d.is_watching} watchers={d.watchers} />
    );

    return (
        <WizardShell
            open={open}
            onClose={onClose}
            title={`Alert ${alertRef}`}
            description={`${titleCase(a.alert_type)} — ${d.client?.name ?? d.alert.asset?.name ?? titleCase(a.source)}`}
            railIcon={RadioTower}
            railTitle={d.client?.name ?? a.asset?.name ?? titleCase(a.alert_type)}
            railSub={`${alertRef} · ${titleCase(a.source)}`}
            steps={SECTIONS}
            stepIndex={stepIndex}
            onStepClick={(i) => setSection(SECTIONS[i].key)}
            headerLabel={headerLabel}
            footerStart={footerStart}
            footerEnd={footerEnd}
            railExtra={railExtra}
        >
            {action === 'acknowledge' ? <AcknowledgePane d={d} onDone={closePane} /> : null}
            {action === 'triage' ? <StartTriagePane d={d} onDone={closePane} /> : null}
            {action === 'resolve' ? <ResolvePane d={d} onDone={closePane} /> : null}
            {action === 'close' ? <ClosePane d={d} onDone={closePane} /> : null}
            {action === 'escalate' ? <EscalatePane d={d} onDone={closePane} /> : null}
            {action === 'assign' ? <AssignPane d={d} onDone={closePane} /> : null}
            {action === 'confirm_sensor' ? <SensorConfirmPane d={d} onDone={closePane} /> : null}
            {action === 'dismiss_sensor' ? <SensorDismissPane d={d} onDone={closePane} /> : null}
            {action === 'edit_meta' ? <EditMetaPane d={d} onDone={closePane} /> : null}
            {action === 'start_playbook' ? <StartPlaybookPane d={d} onDone={() => { closePane(); setSection('playbook'); }} /> : null}
            {!action ? (
                <>
                    {section === 'overview' ? <OverviewSection d={d} onEditMeta={() => setAction('edit_meta')} /> : null}
                    {section === 'sla' ? <SlaTimelineSection d={d} /> : null}
                    {section === 'playbook' ? <PlaybookSection d={d} onStart={() => setAction('start_playbook')} /> : null}
                    {section === 'evidence' ? <EvidenceSection d={d} /> : null}
                    {section === 'tasks' ? <TasksSection d={d} /> : null}
                    {section === 'activity' ? <ActivitySection d={d} /> : null}
                    {section === 'linked' ? <LinkedSection d={d} /> : null}
                </>
            ) : null}
        </WizardShell>
    );
}

/* ------------------------------------------------------------------ */
/*  Watch toggle (rail)                                                */
/* ------------------------------------------------------------------ */

function WatchToggle({ alertId, watching, watchers }: { alertId: number; watching: boolean; watchers: AlertWorkspaceDetail['watchers'] }) {
    const [busy, setBusy] = useState(false);
    const toggle = () => {
        setBusy(true);
        router.post(`/control-room/alerts/${alertId}/watchers/toggle`, {}, {
            preserveScroll: true,
            preserveState: true,
            onFinish: () => setBusy(false),
        });
    };
    return (
        <div className="rounded-lg border border-sidebar-border bg-background/40 p-2.5">
            <button
                type="button"
                onClick={toggle}
                disabled={busy}
                className="flex w-full items-center gap-2 text-left text-[12px] font-semibold text-foreground hover:text-primary"
            >
                <Eye className={`h-3.5 w-3.5 ${watching ? 'text-primary' : 'text-muted-foreground'}`} />
                {watching ? 'Watching' : 'Watch this alert'}
            </button>
            {watchers.length ? (
                <p className="mt-1 truncate text-[11px] text-muted-foreground">{watchers.map((w) => w.user_name).join(', ')}</p>
            ) : null}
        </div>
    );
}

/* ------------------------------------------------------------------ */
/*  Guided action panes                                                */
/* ------------------------------------------------------------------ */

function ContextCard({ d }: { d: AlertWorkspaceDetail }) {
    const a = d.alert;
    return (
        <ReviewCard icon={RadioTower} title={`CR-${a.id} · ${titleCase(a.alert_type)}`} span>
            <ReviewRow label="Summary" value={summarise(a)} />
            <ReviewRow label="Severity" value={SEV_LABEL[a.severity] ?? titleCase(a.severity)} />
            <ReviewRow label="Status" value={(STATUS_META[a.status] ?? { label: titleCase(a.status) }).label} />
            <ReviewRow label="Client" value={d.client?.name} />
            <ReviewRow label="Triggered" value={a.triggered_at ? formatDateTime(a.triggered_at) : undefined} />
        </ReviewCard>
    );
}

function AcknowledgePane({ d, onDone }: { d: AlertWorkspaceDetail; onDone: () => void }) {
    const form = useForm<{ notes: string }>({ notes: '' });
    const submit = () => {
        form.post(`/control-room/alerts/${d.alert.id}/acknowledge`, { preserveScroll: true, onSuccess: onPaneSuccess(onDone) });
    };
    return (
        <div className="flex flex-col gap-4">
            <StepHead icon={UserCheck} title="Acknowledge alert" blurb="Confirms an operator has seen this alert — it stops the acknowledge SLA clock and moves the alert to Acknowledged." />
            <PaneError message={serverError(form.errors)} />
            <ContextCard d={d} />
            <Field label="Note" hint="Optional — visible in the operator log">
                <Textarea rows={2} value={form.data.notes} onChange={(e) => form.setData('notes', e.target.value)} placeholder="e.g. On it — checking the camera feed now" />
            </Field>
            <PaneNav onCancel={onDone} onSubmit={submit} submitLabel="Acknowledge alert" processing={form.processing} />
        </div>
    );
}

function StartTriagePane({ d, onDone }: { d: AlertWorkspaceDetail; onDone: () => void }) {
    const form = useForm<{ notes: string }>({ notes: '' });
    const submit = () => {
        form.post(`/control-room/alerts/${d.alert.id}/triage`, { preserveScroll: true, onSuccess: onPaneSuccess(onDone) });
    };
    return (
        <div className="flex flex-col gap-4">
            <StepHead icon={Eye} title="Start triage" blurb="Marks the alert as actively being worked — it stops the response SLA clock. Resolve it when the situation is handled." />
            <PaneError message={serverError(form.errors)} />
            <ContextCard d={d} />
            <Field label="Triage note" hint="Optional — what are you doing?">
                <Textarea rows={2} value={form.data.notes} onChange={(e) => form.setData('notes', e.target.value)} placeholder="e.g. Calling the site lead to verify" />
            </Field>
            <PaneNav onCancel={onDone} onSubmit={submit} submitLabel="Start triage" processing={form.processing} />
        </div>
    );
}

function ResolvePane({ d, onDone }: { d: AlertWorkspaceDetail; onDone: () => void }) {
    const [step, setStep] = useState(0);
    const form = useForm<{ resolution_notes: string; resolution_code: string }>({ resolution_notes: '', resolution_code: d.alert.resolution_code ?? '' });
    const codes = d.config_options.resolution_codes ?? [];

    const submit = () => {
        // resolution_code travels via the meta endpoint pattern; resolve stores the notes.
        const code = form.data.resolution_code;
        form.post(`/control-room/alerts/${d.alert.id}/resolve`, {
            preserveScroll: true,
            onSuccess: (page) => {
                if ((page.props as { flash?: { error?: string } }).flash?.error) return;
                if (code) {
                    router.post(`/control-room/alerts/${d.alert.id}/meta`, { resolution_code: code }, { preserveScroll: true });
                }
                onDone();
            },
        });
    };

    return (
        <div className="flex flex-col gap-4">
            <StepHead icon={CheckCircle2} title="Resolve alert" blurb="Record what happened and how it was resolved — this stops the resolution SLA clock." />
            <PaneError message={serverError(form.errors)} />
            {step === 0 ? (
                <>
                    <ContextCard d={d} />
                    <Field label="Resolution notes" required error={form.errors.resolution_notes}>
                        <Textarea rows={4} value={form.data.resolution_notes} onChange={(e) => form.setData('resolution_notes', e.target.value)} placeholder="What was found and what was done…" />
                    </Field>
                    {codes.length ? (
                        <Field label="Resolution code" hint="Optional — for reporting">
                            <SelectInput value={form.data.resolution_code} onChange={(v) => form.setData('resolution_code', v)} placeholder="Select a code" options={codes} />
                        </Field>
                    ) : null}
                    <PaneNav onCancel={onDone} onNext={() => setStep(1)} nextDisabled={!form.data.resolution_notes.trim()} step={0} stepCount={2} />
                </>
            ) : (
                <>
                    <ReviewCard icon={CheckCircle2} title="Review & resolve" span>
                        <ReviewRow label="Alert" value={`CR-${d.alert.id} · ${titleCase(d.alert.alert_type)}`} />
                        <ReviewRow label="Resolution" value={form.data.resolution_notes} />
                        <ReviewRow label="Code" value={codes.find((c) => c.value === form.data.resolution_code)?.label ?? (form.data.resolution_code || undefined)} />
                    </ReviewCard>
                    {d.tasks.some((t) => t.status !== 'completed' && t.status !== 'cancelled') ? (
                        <InfoCard icon={ListTodo} tone="warn">
                            This alert still has open tasks — they stay open after resolving. Check the Tasks section if they should be completed first.
                        </InfoCard>
                    ) : null}
                    <PaneNav onCancel={onDone} onBack={() => setStep(0)} onSubmit={submit} submitLabel="Resolve alert" processing={form.processing} step={1} stepCount={2} />
                </>
            )}
        </div>
    );
}

function ClosePane({ d, onDone }: { d: AlertWorkspaceDetail; onDone: () => void }) {
    const form = useForm<{ closure_notes: string }>({ closure_notes: '' });
    const submit = () => {
        form.post(`/control-room/alerts/${d.alert.id}/close`, { preserveScroll: true, onSuccess: onPaneSuccess(onDone) });
    };
    return (
        <div className="flex flex-col gap-4">
            <StepHead icon={CheckCircle2} title="Close alert" blurb="Final state — a closed alert can't be reopened. Make sure evidence and follow-up tasks are wrapped up first." />
            <PaneError message={serverError(form.errors)} />
            <ReviewCard icon={CheckCircle2} title="Resolution on record" span>
                <ReviewRow label="Resolved" value={d.alert.resolved_at ? formatDateTime(d.alert.resolved_at) : undefined} />
                <ReviewRow label="By" value={d.alert.resolved_by?.name} />
                <ReviewRow label="Notes" value={d.alert.notes} />
            </ReviewCard>
            <Field label="Closing note" hint="Optional">
                <Textarea rows={2} value={form.data.closure_notes} onChange={(e) => form.setData('closure_notes', e.target.value)} />
            </Field>
            <PaneNav onCancel={onDone} onSubmit={submit} submitLabel="Close alert" processing={form.processing} />
        </div>
    );
}

function EscalatePane({ d, onDone }: { d: AlertWorkspaceDetail; onDone: () => void }) {
    const current = d.alert.escalation_level ?? 0;
    const next = Math.min(current + 1, 5);
    const form = useForm<{ escalation_reason: string }>({ escalation_reason: '' });
    const submit = () => {
        form.post(`/control-room/alerts/${d.alert.id}/escalate`, { preserveScroll: true, onSuccess: onPaneSuccess(onDone) });
    };
    return (
        <div className="flex flex-col gap-4">
            <StepHead icon={ArrowUpCircle} title="Escalate alert" blurb={`Raises the escalation level from L${current} to L${next} and flags it for senior attention. The reason is kept on the audit trail.`} />
            <PaneError message={serverError(form.errors)} />
            <ContextCard d={d} />
            <Field label="Reason for escalating" required error={form.errors.escalation_reason}>
                <Textarea rows={3} value={form.data.escalation_reason} onChange={(e) => form.setData('escalation_reason', e.target.value)} placeholder="Why does this need senior attention?" />
            </Field>
            <PaneNav onCancel={onDone} onSubmit={submit} submitLabel={`Escalate to L${next}`} processing={form.processing} submitDisabled={!form.data.escalation_reason.trim()} />
        </div>
    );
}

function AssignPane({ d, onDone }: { d: AlertWorkspaceDetail; onDone: () => void }) {
    const a = d.alert;
    const form = useForm<{ assigned_to_user_id: string; reason: string }>({ assigned_to_user_id: a.assigned_to ? String(a.assigned_to.id) : '', reason: '' });
    const [unassignArming, setUnassignArming] = useState(false);
    const selected = d.staff.find((s) => String(s.id) === form.data.assigned_to_user_id);

    const submit = () => {
        form.transform((data) => ({ ...data, assigned_to_user_id: Number(data.assigned_to_user_id) }));
        form.post(`/control-room/alerts/${a.id}/assign`, { preserveScroll: true, onSuccess: onPaneSuccess(onDone) });
    };
    const unassign = () => {
        router.post(`/control-room/alerts/${a.id}/unassign`, {}, { preserveScroll: true, onSuccess: () => onDone() });
    };

    return (
        <div className="flex flex-col gap-4">
            <StepHead icon={User} title={a.assigned_to ? 'Reassign alert' : 'Assign alert'} blurb="Choose who owns this alert. The change is recorded on the assignment history." />
            <PaneError message={serverError(form.errors)} />
            {a.assigned_to ? (
                <InfoCard icon={User} tone="info">
                    Currently assigned to <span className="font-semibold">{a.assigned_to.name}</span>
                    {a.assigned_at ? ` since ${formatDateTime(a.assigned_at)}` : ''}.
                </InfoCard>
            ) : null}
            <Field label="Assign to" required error={form.errors.assigned_to_user_id}>
                <SelectInput
                    value={form.data.assigned_to_user_id}
                    onChange={(v) => form.setData('assigned_to_user_id', v)}
                    placeholder="Select a staff member"
                    options={d.staff.map((s) => ({ value: String(s.id), label: s.name }))}
                />
            </Field>
            <Field label="Reason" hint="Optional — kept on the assignment history">
                <Input value={form.data.reason} onChange={(e) => form.setData('reason', e.target.value)} placeholder="e.g. On shift and closest to the site" />
            </Field>
            <div className="flex items-center justify-between gap-2 border-t border-border pt-4">
                <div>
                    {a.assigned_to ? (
                        !unassignArming ? (
                            <Button type="button" variant="ghost" size="sm" className="text-status-critical hover:text-status-critical" onClick={() => setUnassignArming(true)}>
                                <UserMinus className="mr-1.5 h-3.5 w-3.5" /> Unassign
                            </Button>
                        ) : (
                            <span className="inline-flex items-center gap-1">
                                <Button type="button" variant="destructive" size="sm" onClick={unassign}>
                                    <Check className="mr-1 h-3.5 w-3.5" /> Unassign {a.assigned_to.name}?
                                </Button>
                                <Button type="button" variant="ghost" size="sm" onClick={() => setUnassignArming(false)} aria-label="Cancel unassign">
                                    <X className="h-3.5 w-3.5" />
                                </Button>
                            </span>
                        )
                    ) : null}
                </div>
                <div className="flex items-center gap-2">
                    <Button type="button" variant="outline" size="sm" onClick={onDone}>
                        Cancel
                    </Button>
                    <Button type="button" size="sm" onClick={submit} disabled={!selected || form.processing}>
                        {selected ? `Assign to ${selected.name.split(' ')[0]}` : 'Assign'}
                    </Button>
                </div>
            </div>
        </div>
    );
}

const INCIDENT_TYPE_OPTIONS = [
    { value: 'fall', label: 'Fall' },
    { value: 'injury', label: 'Injury' },
    { value: 'behaviour', label: 'Behaviour' },
    { value: 'medication', label: 'Medication' },
    { value: 'safeguarding', label: 'Safeguarding' },
    { value: 'missing_person', label: 'Missing person' },
    { value: 'property_damage', label: 'Property damage' },
    { value: 'other', label: 'Other' },
];

function SensorEvidenceCard({ d }: { d: AlertWorkspaceDetail }) {
    const a = d.alert;
    const ctx = (a.context ?? {}) as Record<string, any>;
    const signal = (ctx.signal ?? ctx.normalized_data ?? {}) as Record<string, any>;
    const payload = (signal.payload ?? ctx.payload ?? {}) as Record<string, any>;
    return (
        <ReviewCard icon={Radar} title="Signal evidence" span>
            <ReviewRow label="Signal" value={titleCase(String(signal.signal_type_code ?? a.alert_type))} />
            <ReviewRow label="Device" value={signal.device ? String(signal.device) : undefined} />
            <ReviewRow label="Confidence" value={payload.confidence != null ? String(payload.confidence) : undefined} />
            <ReviewRow label="Location" value={payload.location ? String(payload.location) : (d.location?.description ?? undefined)} />
            <ReviewRow label="Detected" value={a.triggered_at ? formatDateTime(a.triggered_at) : undefined} />
            <ReviewRow label="Client" value={d.client?.name} />
        </ReviewCard>
    );
}

function SensorConfirmPane({ d, onDone }: { d: AlertWorkspaceDetail; onDone: () => void }) {
    const [step, setStep] = useState(0);
    const form = useForm<{ type: string; severity: string; note: string }>({ type: d.alert.alert_type === 'fall_detected' ? 'fall' : '', severity: 'high', note: '' });

    const submit = () => {
        form.post(`/control-room/alerts/${d.alert.id}/confirm`, { preserveScroll: true, onSuccess: onPaneSuccess(onDone) });
    };

    return (
        <div className="flex flex-col gap-4">
            <StepHead icon={Radar} title="Confirm sensor detection" blurb="Confirms this detection is real and creates the incident record (system of record) carrying the sensor evidence." />
            <PaneError message={serverError(form.errors)} />
            {step === 0 ? (
                <>
                    <SensorEvidenceCard d={d} />
                    <InfoCard icon={ShieldAlert} tone="info">
                        Confirming creates an incident linked to this alert. If this is a false positive, use <span className="font-semibold">Dismiss</span> instead — that logs a tuning reason and creates nothing.
                    </InfoCard>
                    <PaneNav onCancel={onDone} onNext={() => setStep(1)} step={0} stepCount={2} />
                </>
            ) : (
                <>
                    <div className="grid gap-3 sm:grid-cols-2">
                        <Field label="Incident type" hint="Defaults from the signal" error={form.errors.type}>
                            <SelectInput value={form.data.type} onChange={(v) => form.setData('type', v)} placeholder="Select type" options={INCIDENT_TYPE_OPTIONS} />
                        </Field>
                        <Field label="Severity" error={form.errors.severity}>
                            <SelectInput
                                value={form.data.severity}
                                onChange={(v) => form.setData('severity', v)}
                                placeholder="Severity"
                                options={[
                                    { value: 'low', label: 'Low' },
                                    { value: 'medium', label: 'Medium' },
                                    { value: 'high', label: 'High' },
                                ]}
                            />
                        </Field>
                    </div>
                    <Field label="Note" hint="Optional — added to the incident">
                        <Textarea rows={3} value={form.data.note} onChange={(e) => form.setData('note', e.target.value)} placeholder="What did you verify?" />
                    </Field>
                    <PaneNav onCancel={onDone} onBack={() => setStep(0)} onSubmit={submit} submitLabel="Confirm — create incident" processing={form.processing} step={1} stepCount={2} />
                </>
            )}
        </div>
    );
}

const DISMISS_REASONS = ['Resident sat down', 'Pet or animal', 'Object dropped', 'Staff present', 'Other'];

function SensorDismissPane({ d, onDone }: { d: AlertWorkspaceDetail; onDone: () => void }) {
    const [reason, setReason] = useState('');
    const [other, setOther] = useState('');
    const form = useForm<{ reason: string }>({ reason: '' });
    const finalReason = reason === 'Other' ? other.trim() : reason;

    const submit = () => {
        form.transform(() => ({ reason: finalReason }));
        form.post(`/control-room/alerts/${d.alert.id}/dismiss`, { preserveScroll: true, onSuccess: onPaneSuccess(onDone) });
    };

    return (
        <div className="flex flex-col gap-4">
            <StepHead icon={ShieldQuestion} title="Dismiss as false positive" blurb="No incident is created — the reason is logged so sensor rules can be tuned." />
            <PaneError message={serverError(form.errors) ?? form.errors.reason} />
            <SensorEvidenceCard d={d} />
            <Field label="Why is this a false positive?" required>
                <div className="flex flex-wrap gap-2">
                    {DISMISS_REASONS.map((r) => (
                        <button
                            key={r}
                            type="button"
                            onClick={() => setReason(r)}
                            className={`rounded-full border px-3 py-1.5 text-xs font-medium transition-colors ${reason === r ? 'border-primary bg-primary/10 text-primary' : 'border-border text-muted-foreground hover:bg-muted'}`}
                        >
                            {r}
                        </button>
                    ))}
                </div>
            </Field>
            {reason === 'Other' ? (
                <Field label="Describe it">
                    <Input value={other} onChange={(e) => setOther(e.target.value)} placeholder="Describe the false positive" />
                </Field>
            ) : null}
            <PaneNav onCancel={onDone} onSubmit={submit} submitLabel="Dismiss alert" destructive processing={form.processing} submitDisabled={!finalReason} />
        </div>
    );
}

function EditMetaPane({ d, onDone }: { d: AlertWorkspaceDetail; onDone: () => void }) {
    const a = d.alert;
    const form = useForm<{ priority: string; category: string; due_at: string; resolution_code: string }>({
        priority: a.priority ?? '',
        category: a.category ?? '',
        due_at: a.due_at ? a.due_at.slice(0, 10) : '',
        resolution_code: a.resolution_code ?? '',
    });
    const submit = () => {
        form.transform((data) => {
            const out: Record<string, string | null> = {};
            out.priority = data.priority || null;
            out.category = data.category || null;
            out.due_at = data.due_at || null;
            out.resolution_code = data.resolution_code || null;
            return out;
        });
        form.post(`/control-room/alerts/${a.id}/meta`, { preserveScroll: true, onSuccess: onPaneSuccess(onDone) });
    };
    return (
        <div className="flex flex-col gap-4">
            <StepHead icon={Pencil} title="Edit alert details" blurb="Working details for the operator desk — category, priority, internal due time and resolution code." />
            <PaneError message={serverError(form.errors)} />
            <div className="grid gap-3 sm:grid-cols-2">
                <Field label="Priority" error={form.errors.priority}>
                    <SelectInput
                        value={form.data.priority}
                        onChange={(v) => form.setData('priority', v)}
                        placeholder="No priority set"
                        options={[
                            { value: 'critical', label: 'Critical' },
                            { value: 'high', label: 'High' },
                            { value: 'medium', label: 'Medium' },
                            { value: 'low', label: 'Low' },
                        ]}
                    />
                </Field>
                <Field label="Category" error={form.errors.category}>
                    <SelectInput value={form.data.category} onChange={(v) => form.setData('category', v)} placeholder="No category" options={d.config_options.categories ?? []} />
                </Field>
                <Field label="Due" hint="Internal target" error={form.errors.due_at}>
                    <Input type="date" value={form.data.due_at} onChange={(e) => form.setData('due_at', e.target.value)} />
                </Field>
                <Field label="Resolution code" error={form.errors.resolution_code}>
                    <SelectInput value={form.data.resolution_code} onChange={(v) => form.setData('resolution_code', v)} placeholder="Not set" options={d.config_options.resolution_codes ?? []} />
                </Field>
            </div>
            <PaneNav onCancel={onDone} onSubmit={submit} submitLabel="Save details" processing={form.processing} />
        </div>
    );
}

function StartPlaybookPane({ d, onDone }: { d: AlertWorkspaceDetail; onDone: () => void }) {
    const [step, setStep] = useState(0);
    const [playbookId, setPlaybookId] = useState<string>('');
    const form = useForm<{ playbook_id: number | null }>({ playbook_id: null });
    const chosen = d.available_playbooks.find((p) => String(p.id) === playbookId);

    const submit = () => {
        form.transform(() => ({ playbook_id: Number(playbookId) }));
        form.post(`/control-room/alerts/${d.alert.id}/playbook/start`, { preserveScroll: true, onSuccess: onPaneSuccess(onDone) });
    };

    return (
        <div className="flex flex-col gap-4">
            <StepHead icon={BookOpen} title="Start a playbook" blurb="Attach a step-by-step response procedure to this alert and work through it from the Playbook section." />
            <PaneError message={serverError(form.errors) ?? (form.errors as Record<string, string | undefined>).playbook_id} />
            {step === 0 ? (
                <>
                    <Field label="Playbook" required>
                        <SelectInput
                            value={playbookId}
                            onChange={setPlaybookId}
                            placeholder="Select a playbook"
                            options={d.available_playbooks.map((p) => ({ value: String(p.id), label: `${p.name} (${titleCase(p.category)})` }))}
                        />
                    </Field>
                    {chosen?.description ? (
                        <InfoCard icon={BookOpen} tone="info">
                            {chosen.description}
                        </InfoCard>
                    ) : null}
                    <PaneNav onCancel={onDone} onNext={() => setStep(1)} nextDisabled={!playbookId} step={0} stepCount={2} />
                </>
            ) : (
                <>
                    <ReviewCard icon={BookOpen} title="Review & start" span>
                        <ReviewRow label="Playbook" value={chosen?.name} />
                        <ReviewRow label="Category" value={chosen ? titleCase(chosen.category) : undefined} />
                        <ReviewRow label="Alert" value={`CR-${d.alert.id} · ${titleCase(d.alert.alert_type)}`} />
                    </ReviewCard>
                    <PaneNav onCancel={onDone} onBack={() => setStep(0)} onSubmit={submit} submitLabel="Start playbook" processing={form.processing} step={1} stepCount={2} />
                </>
            )}
        </div>
    );
}

/* ------------------------------------------------------------------ */
/*  Sections                                                           */
/* ------------------------------------------------------------------ */

function StatusFlow({ a }: { a: WorkspaceAlert }) {
    if (a.status === 'confirmed' || a.status === 'dismissed') {
        const meta = STATUS_META[a.status];
        return (
            <div className="rounded-lg border border-border bg-muted/30 px-3 py-2 text-sm font-medium text-foreground">
                Sensor triage outcome: {meta.label}
            </div>
        );
    }
    const idx = Math.max(0, LIFECYCLE.findIndex((s) => s.key === a.status));
    return (
        <div className="flex flex-wrap items-center gap-1.5">
            {LIFECYCLE.map((s, i) => {
                const isDone = i < idx;
                const isNow = i === idx;
                return (
                    <span key={s.key} className="flex items-center gap-1.5">
                        <span
                            className={`inline-flex items-center gap-1 rounded-full px-2.5 py-1 text-[11px] font-semibold ${
                                isNow ? 'bg-primary text-primary-foreground' : isDone ? 'bg-status-success-bg text-status-success' : 'bg-muted text-muted-foreground'
                            }`}
                        >
                            {isDone ? <Check className="h-3 w-3" /> : null}
                            {s.label}
                        </span>
                        {i < LIFECYCLE.length - 1 ? <span className="h-px w-3 bg-border" /> : null}
                    </span>
                );
            })}
        </div>
    );
}

function OverviewSection({ d, onEditMeta }: { d: AlertWorkspaceDetail; onEditMeta: () => void }) {
    const a = d.alert;
    const ctx = (a.context ?? {}) as Record<string, any>;
    const incidentId = ctx.incident_id ? Number(ctx.incident_id) : null;
    const fleet = (a.fleet_context ?? null) as Record<string, any> | null;
    return (
        <div className="grid gap-4 sm:grid-cols-2">
            <div className="sm:col-span-2">
                <StatusFlow a={a} />
            </div>

            {incidentId ? (
                <div className="sm:col-span-2">
                    <InfoCard icon={ShieldAlert} tone="info">
                        <span className="font-semibold">Linked incident INC-{incidentId}.</span>{' '}
                        <Link href={`/incidents?incident=${incidentId}`} className="font-medium text-primary hover:underline">
                            Open the incident record
                        </Link>{' '}
                        — it is the system of record for what happened.
                    </InfoCard>
                </div>
            ) : null}

            <ReviewCard icon={FileText} title="What's happening" span>
                <p className="text-sm whitespace-pre-wrap text-foreground">{summarise(a)}</p>
                {a.notes ? <p className="mt-2 border-t border-border pt-2 text-xs whitespace-pre-wrap text-muted-foreground">{a.notes}</p> : null}
            </ReviewCard>

            <ReviewCard icon={RadioTower} title="Alert">
                <ReviewRow label="Type" value={titleCase(a.alert_type)} />
                <ReviewRow label="Source" value={titleCase(a.source)} />
                <ReviewRow label="Severity" value={SEV_LABEL[a.severity] ?? titleCase(a.severity)} />
                <ReviewRow label="Escalation" value={a.escalation_level > 0 ? `L${a.escalation_level}` : 'None'} />
                <ReviewRow label="Triggered" value={a.triggered_at ? formatDateTime(a.triggered_at) : undefined} />
            </ReviewCard>

            <ReviewCard icon={User} title="People & place">
                <ReviewRow label="Client" value={d.client?.name} />
                <ReviewRow label="Asset" value={a.asset ? `${a.asset.name} (${a.asset.asset_tag})` : undefined} />
                <ReviewRow label="Assigned to" value={a.assigned_to?.name ?? 'Unassigned'} />
                <ReviewRow label="Location" value={d.location?.description ?? undefined} />
                {d.location ? (
                    <div className="pt-1.5">
                        <a
                            href={`https://www.google.com/maps?q=${d.location.lat},${d.location.lng}`}
                            target="_blank"
                            rel="noreferrer"
                            className="inline-flex items-center gap-1 text-xs font-medium text-primary hover:underline"
                        >
                            <MapPin className="h-3.5 w-3.5" /> Open in Google Maps
                        </a>
                    </div>
                ) : null}
            </ReviewCard>

            <ReviewCard icon={ClipboardList} title="Working details" onEdit={d.can.manage ? onEditMeta : undefined} span>
                <div className="grid gap-x-6 sm:grid-cols-2">
                    <ReviewRow label="Priority" value={a.priority ? titleCase(a.priority) : undefined} />
                    <ReviewRow label="Category" value={a.category ? titleCase(a.category) : undefined} />
                    <ReviewRow label="Due" value={a.due_at ? formatDateTime(a.due_at) : undefined} />
                    <ReviewRow label="Resolution code" value={a.resolution_code ? titleCase(a.resolution_code) : undefined} />
                </div>
            </ReviewCard>

            {fleet ? (
                <ReviewCard icon={Truck} title="Fleet context" span>
                    <ReviewRow label="Vehicle" value={fleet.vehicle?.name ? `${fleet.vehicle.name}${fleet.vehicle.registration ? ` · ${fleet.vehicle.registration}` : ''}` : undefined} />
                    <ReviewRow label="Geofence" value={fleet.geofence?.name} />
                    <ReviewRow label="Outing" value={fleet.outing?.title} />
                    <ReviewRow label="Residents aboard" value={fleet.affected_resident_count != null ? String(fleet.affected_resident_count) : undefined} />
                    <ReviewRow label="Speed" value={fleet.location?.speed_kph != null ? `${fleet.location.speed_kph} km/h` : undefined} />
                </ReviewCard>
            ) : null}
        </div>
    );
}

function SlaCountdownRow({ label, deadline, breached, doneAt }: { label: string; deadline: string | null; breached: boolean; doneAt: string | null }) {
    const met = Boolean(doneAt);
    let state: ReactNode;
    if (met) {
        state = (
            <span className="inline-flex items-center gap-1 text-xs font-semibold text-status-success">
                <Check className="h-3.5 w-3.5" /> met {doneAt ? formatDateTime(doneAt) : ''}
            </span>
        );
    } else if (breached) {
        state = <span className="text-xs font-bold text-status-critical">BREACHED</span>;
    } else if (deadline) {
        const remainingMs = new Date(deadline).getTime() - Date.now();
        state =
            remainingMs <= 0 ? (
                <span className="text-xs font-bold text-status-critical">BREACHED</span>
            ) : (
                <span className="text-xs font-semibold text-foreground">due {formatDateTime(deadline)}</span>
            );
    } else {
        state = <span className="text-xs text-muted-foreground">—</span>;
    }
    return (
        <div className="flex items-center justify-between rounded-lg border border-border px-3 py-2.5">
            <span className="flex items-center gap-2 text-sm font-medium text-foreground">
                <Timer className="h-4 w-4 text-muted-foreground" /> {label}
            </span>
            {state}
        </div>
    );
}

function SlaTimelineSection({ d }: { d: AlertWorkspaceDetail }) {
    const a = d.alert;
    type TLEvent = { at: string; label: string; tone: string; icon: ComponentType<{ className?: string }> };
    const events: TLEvent[] = [];
    if (a.triggered_at) events.push({ at: a.triggered_at, label: 'Alert triggered', tone: 'critical', icon: RadioTower });
    if (a.acknowledged_at) events.push({ at: a.acknowledged_at, label: `Acknowledged${a.acknowledged_by ? ` · ${a.acknowledged_by.name}` : ''}`, tone: 'warning', icon: UserCheck });
    if (a.assigned_at) events.push({ at: a.assigned_at, label: `Assigned${a.assigned_to ? ` · ${a.assigned_to.name}` : ''}`, tone: 'info', icon: User });
    if (a.escalated_at) events.push({ at: a.escalated_at, label: `Escalated to L${a.escalation_level}${a.escalated_by ? ` · ${a.escalated_by.name}` : ''}`, tone: 'warning', icon: ArrowUpCircle });
    if (a.resolved_at) events.push({ at: a.resolved_at, label: `Resolved${a.resolved_by ? ` · ${a.resolved_by.name}` : ''}`, tone: 'success', icon: CheckCircle2 });
    if (a.closed_at) events.push({ at: a.closed_at, label: `Closed${a.closed_by ? ` · ${a.closed_by.name}` : ''}`, tone: 'neutral', icon: CheckCircle2 });
    events.sort((x, y) => new Date(x.at).getTime() - new Date(y.at).getTime());

    return (
        <div className="flex flex-col gap-5">
            {d.sla ? (
                <div>
                    <p className="mb-2 text-sm font-semibold text-foreground">SLA deadlines</p>
                    <div className="grid gap-2 sm:grid-cols-3">
                        <SlaCountdownRow label="Acknowledge" deadline={d.sla.acknowledge_deadline} breached={d.sla.acknowledge_breached} doneAt={a.acknowledged_at} />
                        <SlaCountdownRow label="Respond" deadline={d.sla.response_deadline} breached={d.sla.response_breached} doneAt={a.status === 'triaging' || a.resolved_at ? (a.resolved_at ?? a.updated_at) : null} />
                        <SlaCountdownRow label="Resolve" deadline={d.sla.resolution_deadline} breached={d.sla.resolution_breached} doneAt={a.resolved_at} />
                    </div>
                </div>
            ) : (
                <InfoCard icon={Timer} tone="info">
                    No SLA is attached to this alert.
                </InfoCard>
            )}

            <div>
                <p className="mb-2 text-sm font-semibold text-foreground">Timeline</p>
                {events.length ? (
                    <ol className="relative ml-2 border-l border-border">
                        {events.map((e, i) => {
                            const Icon = e.icon;
                            return (
                                <li key={i} className="mb-5 ml-5">
                                    <span className={`absolute -left-[7px] flex h-3.5 w-3.5 items-center justify-center rounded-full ${DOT[e.tone] ?? DOT.neutral}`} />
                                    <div className="flex items-center gap-2">
                                        <Icon className="h-4 w-4 text-muted-foreground" />
                                        <span className="text-sm font-medium text-foreground">{e.label}</span>
                                    </div>
                                    <p className="mt-0.5 text-xs text-muted-foreground">{formatDateTime(e.at)}</p>
                                </li>
                            );
                        })}
                    </ol>
                ) : (
                    <p className="text-sm text-muted-foreground">No timeline events yet.</p>
                )}
            </div>

            <div>
                <p className="mb-2 text-sm font-semibold text-foreground">Audit trail</p>
                {d.audit_logs.length ? (
                    <div className="flex flex-col gap-1.5">
                        {d.audit_logs.slice(0, 15).map((log) => (
                            <div key={log.id} className="flex items-baseline justify-between gap-3 rounded-md border border-border/60 px-2.5 py-1.5 text-xs">
                                <span className="min-w-0 truncate text-foreground">
                                    {titleCase(log.action.replace('controlRoom.', '').replace('alert.', ''))}
                                    {log.user ? <span className="text-muted-foreground"> · {log.user.name}</span> : null}
                                </span>
                                <span className="shrink-0 text-muted-foreground">{formatDateTime(log.created_at)}</span>
                            </div>
                        ))}
                    </div>
                ) : (
                    <p className="text-sm text-muted-foreground">No audit entries.</p>
                )}
            </div>
        </div>
    );
}

function PlaybookSection({ d, onStart }: { d: AlertWorkspaceDetail; onStart: () => void }) {
    const run = d.playbook_run;
    const alertId = d.alert.id;
    const advance = () => router.post(`/control-room/alerts/${alertId}/playbook/advance`, {}, { preserveScroll: true });
    const skip = () => router.post(`/control-room/alerts/${alertId}/playbook/skip`, {}, { preserveScroll: true });

    if (!run) {
        return (
            <div className="flex flex-col gap-4">
                <div className="rounded-xl border border-dashed border-border py-10 text-center">
                    <BookOpen className="mx-auto mb-2 h-8 w-8 text-muted-foreground/40" />
                    <p className="text-sm text-muted-foreground">No playbook attached to this alert.</p>
                    {d.can.manage && d.available_playbooks.length && OPEN_STATES.includes(d.alert.status) ? (
                        <Button size="sm" className="mt-3" onClick={onStart}>
                            <Play className="mr-1.5 h-4 w-4" /> Start a playbook
                        </Button>
                    ) : null}
                </div>
            </div>
        );
    }

    return (
        <div className="flex flex-col gap-4">
            <div className="flex items-center justify-between rounded-lg border border-border bg-muted/30 p-3">
                <div>
                    <p className="text-sm font-semibold text-foreground">{run.playbook.name}</p>
                    <p className="text-xs text-muted-foreground">
                        {titleCase(run.playbook.category)} · {run.completed_steps}/{run.total_steps} steps · {titleCase(run.status)}
                    </p>
                </div>
                <div className="h-1.5 w-28 overflow-hidden rounded-full bg-muted">
                    <div className="h-full rounded-full bg-primary transition-[width]" style={{ width: `${run.total_steps ? Math.round((run.completed_steps / run.total_steps) * 100) : 0}%` }} />
                </div>
            </div>

            <ol className="flex flex-col gap-2">
                {run.steps.map((s, i) => {
                    const active = s.status === 'in_progress';
                    return (
                        <li key={s.id} className={`flex items-start gap-3 rounded-lg border p-3 ${active ? 'border-primary/50 bg-primary/5' : 'border-border'}`}>
                            <span
                                className={`mt-0.5 grid h-6 w-6 shrink-0 place-items-center rounded-full text-[11px] font-bold ${
                                    s.status === 'completed'
                                        ? 'bg-status-success-bg text-status-success'
                                        : s.status === 'skipped'
                                          ? 'bg-muted text-muted-foreground'
                                          : active
                                            ? 'bg-primary text-primary-foreground'
                                            : 'bg-muted text-muted-foreground'
                                }`}
                            >
                                {s.status === 'completed' ? <Check className="h-3.5 w-3.5" /> : s.status === 'skipped' ? <SkipForward className="h-3 w-3" /> : i + 1}
                            </span>
                            <div className="min-w-0 flex-1">
                                <p className={`text-sm ${active ? 'font-semibold text-foreground' : 'font-medium text-foreground'}`}>{s.title}</p>
                                <p className="text-xs text-muted-foreground">
                                    {titleCase(s.status)}
                                    {s.completed_at ? ` · ${formatDateTime(s.completed_at)}` : ''}
                                    {s.notes ? ` · ${s.notes}` : ''}
                                </p>
                            </div>
                            {active && d.can.manage ? (
                                <div className="flex shrink-0 items-center gap-1.5">
                                    <ConfirmChip label="Complete step" icon={Check} onConfirm={advance} />
                                    <ConfirmChip label="Skip" icon={SkipForward} onConfirm={skip} />
                                </div>
                            ) : null}
                        </li>
                    );
                })}
            </ol>
        </div>
    );
}

/* --- Evidence ------------------------------------------------------ */

function EvidenceSection({ d }: { d: AlertWorkspaceDetail }) {
    const [creating, setCreating] = useState(false);
    const canManage = d.can.manage;
    return (
        <div className="flex flex-col gap-4">
            {canManage ? (
                creating ? (
                    <CreatePackForm alertId={d.alert.id} onDone={() => setCreating(false)} />
                ) : (
                    <Button variant="outline" size="sm" className="self-start" onClick={() => setCreating(true)}>
                        <Plus className="mr-1.5 h-3.5 w-3.5" /> New evidence pack
                    </Button>
                )
            ) : null}

            {d.evidence_packs.length ? (
                d.evidence_packs.map((pack) => <EvidencePackCard key={pack.id} pack={pack} canManage={canManage} />)
            ) : (
                <div className="rounded-xl border border-dashed border-border py-10 text-center">
                    <Package className="mx-auto mb-2 h-8 w-8 text-muted-foreground/40" />
                    <p className="text-sm text-muted-foreground">No evidence packs on this alert.</p>
                    <p className="mt-1 text-xs text-muted-foreground/70">Create a pack to collect files, notes and CCTV bookmarks for the record.</p>
                </div>
            )}
        </div>
    );
}

function CreatePackForm({ alertId, onDone }: { alertId: number; onDone: () => void }) {
    const form = useForm<{ title: string }>({ title: '' });
    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post(`/control-room/alerts/${alertId}/evidence`, {
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                onDone();
            },
        });
    };
    return (
        <form onSubmit={submit} className="flex flex-col gap-3 rounded-xl border border-border bg-muted/30 p-3">
            <Field label="Pack title" required error={form.errors.title}>
                <Input value={form.data.title} onChange={(e) => form.setData('title', e.target.value)} placeholder="e.g. Fall in the lounge — 14 Jun evidence" />
            </Field>
            <div className="flex justify-end gap-2">
                <Button type="button" variant="outline" size="sm" onClick={onDone}>
                    Cancel
                </Button>
                <Button type="submit" size="sm" disabled={form.processing || !form.data.title.trim()}>
                    Create pack
                </Button>
            </div>
        </form>
    );
}

function EvidencePackCard({ pack, canManage }: { pack: AlertWorkspaceDetail['evidence_packs'][number]; canManage: boolean }) {
    const [adding, setAdding] = useState<'file' | 'note' | 'cctv' | null>(null);
    const [completing, setCompleting] = useState(false);
    const fileInput = useRef<HTMLInputElement | null>(null);
    const collecting = pack.status === 'collecting';

    const uploadFile = (file: File) => {
        router.post(
            `/control-room/evidence/${pack.id}/items`,
            { item_type: 'file', file },
            { preserveScroll: true, forceFormData: true, onSuccess: () => setAdding(null) },
        );
    };

    const removeItem = (itemId: number) => {
        router.delete(`/control-room/evidence/items/${itemId}`, { preserveScroll: true });
    };

    return (
        <div className="rounded-xl border border-border">
            <div className="flex items-center justify-between gap-3 border-b border-border bg-muted/30 px-3 py-2.5">
                <div className="min-w-0">
                    <p className="truncate text-sm font-semibold text-foreground">{pack.title}</p>
                    <p className="text-xs text-muted-foreground">
                        {titleCase(pack.status)} · {pack.items.length} item{pack.items.length === 1 ? '' : 's'}
                    </p>
                </div>
                <div className="flex shrink-0 items-center gap-1.5">
                    {pack.status === 'complete' ? (
                        <a
                            href={`/control-room/evidence/${pack.id}/export`}
                            className="inline-flex items-center gap-1 rounded-md px-2 py-1 text-xs font-medium text-primary transition-colors hover:bg-muted"
                        >
                            <Download className="h-3.5 w-3.5" /> Export ZIP
                        </a>
                    ) : null}
                    {canManage && collecting ? (
                        <Button variant="outline" size="sm" onClick={() => setCompleting(true)}>
                            <Check className="mr-1 h-3.5 w-3.5" /> Complete pack
                        </Button>
                    ) : null}
                </div>
            </div>

            {completing ? (
                <CompletePackReview pack={pack} onCancel={() => setCompleting(false)} />
            ) : (
                <div className="flex flex-col gap-2 p-3">
                    {pack.items.length ? (
                        pack.items.map((item) => (
                            <div key={item.id} className="flex items-center gap-2.5 rounded-lg border border-border/70 px-2.5 py-2">
                                {item.type === 'note' ? (
                                    <FileText className="h-4 w-4 shrink-0 text-muted-foreground" />
                                ) : item.type === 'cctv_bookmark' ? (
                                    <Eye className="h-4 w-4 shrink-0 text-muted-foreground" />
                                ) : (
                                    <Paperclip className="h-4 w-4 shrink-0 text-muted-foreground" />
                                )}
                                <div className="min-w-0 flex-1">
                                    <p className="truncate text-sm text-foreground">{item.title}</p>
                                    <p className="text-xs text-muted-foreground">
                                        {titleCase(item.type)}
                                        {item.created_at ? ` · ${formatDateTime(item.created_at)}` : ''}
                                    </p>
                                </div>
                                {canManage && collecting ? (
                                    <ConfirmChip label="Remove" icon={Trash2} destructive onConfirm={() => removeItem(item.id)} />
                                ) : null}
                            </div>
                        ))
                    ) : (
                        <p className="py-2 text-center text-xs text-muted-foreground">Empty pack — add files, notes or CCTV bookmarks.</p>
                    )}

                    {canManage && collecting ? (
                        <div className="mt-1 border-t border-border pt-2.5">
                            {adding === null ? (
                                <div className="flex flex-wrap gap-2">
                                    <Button variant="outline" size="sm" onClick={() => fileInput.current?.click()}>
                                        <Paperclip className="mr-1.5 h-3.5 w-3.5" /> Upload file
                                    </Button>
                                    <Button variant="outline" size="sm" onClick={() => setAdding('note')}>
                                        <FileText className="mr-1.5 h-3.5 w-3.5" /> Add note
                                    </Button>
                                    <Button variant="outline" size="sm" onClick={() => setAdding('cctv')}>
                                        <Eye className="mr-1.5 h-3.5 w-3.5" /> CCTV bookmark
                                    </Button>
                                    <input
                                        ref={fileInput}
                                        type="file"
                                        className="hidden"
                                        accept=".jpg,.jpeg,.png,.gif,.pdf,.doc,.docx"
                                        onChange={(e) => {
                                            const f = e.target.files?.[0];
                                            if (f) uploadFile(f);
                                            e.target.value = '';
                                        }}
                                    />
                                </div>
                            ) : adding === 'note' ? (
                                <EvidenceNoteForm packId={pack.id} onDone={() => setAdding(null)} />
                            ) : (
                                <EvidenceCctvForm packId={pack.id} onDone={() => setAdding(null)} />
                            )}
                        </div>
                    ) : null}
                </div>
            )}
        </div>
    );
}

function CompletePackReview({ pack, onCancel }: { pack: AlertWorkspaceDetail['evidence_packs'][number]; onCancel: () => void }) {
    const form = useForm({});
    const submit = () => {
        form.post(`/control-room/evidence/${pack.id}/complete`, { preserveScroll: true, onSuccess: onPaneSuccess(onCancel) });
    };
    return (
        <div className="flex flex-col gap-3 p-3">
            <InfoCard icon={Package} tone="warn">
                Completing locks the pack — no more items can be added or removed. Check everything is here first.
            </InfoCard>
            <ReviewCard icon={Package} title={`${pack.title} — ${pack.items.length} item${pack.items.length === 1 ? '' : 's'}`} span>
                {pack.items.length ? (
                    pack.items.map((i) => <ReviewRow key={i.id} label={titleCase(i.type)} value={i.title} />)
                ) : (
                    <p className="text-sm text-status-critical">This pack is empty — completing an empty pack is rarely intended.</p>
                )}
            </ReviewCard>
            <PaneNav onCancel={onCancel} onSubmit={submit} submitLabel="Complete pack" processing={form.processing} />
        </div>
    );
}

function EvidenceNoteForm({ packId, onDone }: { packId: number; onDone: () => void }) {
    const form = useForm<{ item_type: string; content: string }>({ item_type: 'note', content: '' });
    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post(`/control-room/evidence/${packId}/items`, { preserveScroll: true, onSuccess: () => { form.reset(); onDone(); } });
    };
    return (
        <form onSubmit={submit} className="flex flex-col gap-2.5">
            <Field label="Note" required error={form.errors.content}>
                <Textarea rows={2} value={form.data.content} onChange={(e) => form.setData('content', e.target.value)} placeholder="What did you observe?" />
            </Field>
            <div className="flex justify-end gap-2">
                <Button type="button" variant="outline" size="sm" onClick={onDone}>Cancel</Button>
                <Button type="submit" size="sm" disabled={form.processing || !form.data.content.trim()}>Add note</Button>
            </div>
        </form>
    );
}

function EvidenceCctvForm({ packId, onDone }: { packId: number; onDone: () => void }) {
    const form = useForm<{ item_type: string; camera_id: string; timestamp: string }>({ item_type: 'cctv_bookmark', camera_id: '', timestamp: '' });
    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post(`/control-room/evidence/${packId}/items`, { preserveScroll: true, onSuccess: () => { form.reset(); onDone(); } });
    };
    return (
        <form onSubmit={submit} className="flex flex-col gap-2.5">
            <div className="grid gap-2.5 sm:grid-cols-2">
                <Field label="Camera" required error={form.errors.camera_id}>
                    <Input value={form.data.camera_id} onChange={(e) => form.setData('camera_id', e.target.value)} placeholder="e.g. CAM-LOUNGE-2" />
                </Field>
                <Field label="Timestamp" required error={form.errors.timestamp}>
                    <Input type="datetime-local" value={form.data.timestamp} onChange={(e) => form.setData('timestamp', e.target.value)} />
                </Field>
            </div>
            <div className="flex justify-end gap-2">
                <Button type="button" variant="outline" size="sm" onClick={onDone}>Cancel</Button>
                <Button type="submit" size="sm" disabled={form.processing || !form.data.camera_id.trim() || !form.data.timestamp}>Add bookmark</Button>
            </div>
        </form>
    );
}

/* --- Tasks ---------------------------------------------------------- */

const TASK_STATUS_TONE: Record<string, string> = {
    open: 'text-muted-foreground',
    in_progress: 'text-status-info',
    blocked: 'text-status-critical',
    completed: 'text-status-success',
    cancelled: 'text-muted-foreground',
};

function TasksSection({ d }: { d: AlertWorkspaceDetail }) {
    const [adding, setAdding] = useState(false);
    return (
        <div className="flex flex-col gap-3">
            {d.can.manage ? (
                adding ? (
                    <AddTaskForm d={d} onDone={() => setAdding(false)} />
                ) : (
                    <Button variant="outline" size="sm" className="self-start" onClick={() => setAdding(true)}>
                        <Plus className="mr-1.5 h-3.5 w-3.5" /> Add task
                    </Button>
                )
            ) : null}

            {d.tasks.length ? (
                <div className="flex flex-col gap-2">
                    {d.tasks.map((t) => (
                        <div key={t.id} className="flex items-start gap-3 rounded-lg border border-border p-3">
                            <ListTodo className={`mt-0.5 h-4 w-4 shrink-0 ${TASK_STATUS_TONE[t.status] ?? 'text-muted-foreground'}`} />
                            <div className="min-w-0 flex-1">
                                <p className={`text-sm text-foreground ${t.status === 'completed' ? 'line-through opacity-70' : ''}`}>{t.title}</p>
                                {t.description ? <p className="text-xs whitespace-pre-wrap text-muted-foreground">{t.description}</p> : null}
                                <p className="text-xs text-muted-foreground">
                                    {titleCase(t.status)} · {titleCase(t.priority)}
                                    {t.assigned_to ? ` · ${t.assigned_to.name}` : ' · unassigned'}
                                    {t.due_at ? ` · due ${formatDateTime(t.due_at)}` : ''}
                                </p>
                            </div>
                            {d.can.manage && t.status !== 'completed' && t.status !== 'cancelled' ? (
                                <div className="flex shrink-0 items-center gap-1.5">
                                    <ConfirmChip label="Done" icon={Check} onConfirm={() => router.post(`/control-room/tasks/${t.id}/status`, { status: 'completed' }, { preserveScroll: true })} />
                                    <ConfirmChip label="Remove" icon={Trash2} destructive onConfirm={() => router.delete(`/control-room/tasks/${t.id}`, { preserveScroll: true })} />
                                </div>
                            ) : null}
                        </div>
                    ))}
                </div>
            ) : (
                <div className="rounded-xl border border-dashed border-border py-10 text-center">
                    <ListTodo className="mx-auto mb-2 h-8 w-8 text-muted-foreground/40" />
                    <p className="text-sm text-muted-foreground">No tasks on this alert.</p>
                </div>
            )}
        </div>
    );
}

function AddTaskForm({ d, onDone }: { d: AlertWorkspaceDetail; onDone: () => void }) {
    const form = useForm<{ title: string; description: string; priority: string; assigned_to_user_id: string; due_at: string }>({
        title: '',
        description: '',
        priority: 'medium',
        assigned_to_user_id: '',
        due_at: '',
    });
    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.transform((data) => ({
            ...data,
            assigned_to_user_id: data.assigned_to_user_id ? Number(data.assigned_to_user_id) : null,
            due_at: data.due_at || null,
            description: data.description || null,
        }));
        form.post(`/control-room/alerts/${d.alert.id}/tasks`, {
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                onDone();
            },
        });
    };
    return (
        <form onSubmit={submit} className="flex flex-col gap-3 rounded-xl border border-border bg-muted/30 p-3">
            <Field label="Task" required error={form.errors.title}>
                <Input value={form.data.title} onChange={(e) => form.setData('title', e.target.value)} placeholder="e.g. Call the family before 5pm" />
            </Field>
            <Field label="Detail" hint="Optional">
                <Textarea rows={2} value={form.data.description} onChange={(e) => form.setData('description', e.target.value)} />
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
                <Field label="Assign to">
                    <SelectInput
                        value={form.data.assigned_to_user_id}
                        onChange={(v) => form.setData('assigned_to_user_id', v)}
                        placeholder="Unassigned"
                        options={d.staff.map((s) => ({ value: String(s.id), label: s.name }))}
                    />
                </Field>
                <Field label="Due">
                    <Input type="date" value={form.data.due_at} onChange={(e) => form.setData('due_at', e.target.value)} />
                </Field>
            </div>
            <div className="flex justify-end gap-2">
                <Button type="button" variant="outline" size="sm" onClick={onDone}>
                    Cancel
                </Button>
                <Button type="submit" size="sm" disabled={form.processing || !form.data.title.trim()}>
                    Add task
                </Button>
            </div>
        </form>
    );
}

/* --- Activity (notes, discussion, communications) -------------------- */

function ActivitySection({ d }: { d: AlertWorkspaceDetail }) {
    const ctx = (d.alert.context ?? {}) as Record<string, any>;
    const notes = Array.isArray(ctx.activity_log) ? (ctx.activity_log as Array<{ type?: string; content?: string; user_name?: string; created_at?: string }>) : [];
    return (
        <div className="flex flex-col gap-5">
            <div>
                <p className="mb-2 text-sm font-semibold text-foreground">Operator notes</p>
                {d.can.manage ? <AddNoteForm alertId={d.alert.id} /> : null}
                {notes.length ? (
                    <div className="mt-2 flex flex-col gap-2">
                        {[...notes].reverse().map((n, i) => (
                            <div key={i} className="rounded-lg border border-border p-2.5">
                                <p className="text-sm whitespace-pre-wrap text-foreground">{n.content}</p>
                                <p className="mt-1 text-xs text-muted-foreground">
                                    {n.user_name ?? 'System'}
                                    {n.created_at ? ` · ${formatDateTime(n.created_at)}` : ''}
                                </p>
                            </div>
                        ))}
                    </div>
                ) : (
                    <p className="mt-2 text-xs text-muted-foreground">No notes yet.</p>
                )}
            </div>

            <div>
                <p className="mb-2 text-sm font-semibold text-foreground">Discussion</p>
                {d.can.manage ? <DiscussionComposer alertId={d.alert.id} /> : null}
                {d.discussions.length ? (
                    <div className="mt-2 flex flex-col gap-2">
                        {d.discussions.map((disc) => (
                            <DiscussionThread key={disc.id} d={d} thread={disc} />
                        ))}
                    </div>
                ) : (
                    <p className="mt-2 text-xs text-muted-foreground">No discussion yet.</p>
                )}
            </div>

            <div>
                <p className="mb-2 text-sm font-semibold text-foreground">Communications</p>
                {d.communications.length ? (
                    <div className="flex flex-col gap-2">
                        {d.communications.map((c) => (
                            <div key={c.id} className="flex items-start gap-2.5 rounded-lg border border-border p-2.5">
                                <Send className="mt-0.5 h-3.5 w-3.5 shrink-0 text-muted-foreground" />
                                <div className="min-w-0 flex-1">
                                    <p className="text-sm text-foreground">{c.content ?? titleCase(c.purpose ?? c.channel)}</p>
                                    <p className="text-xs text-muted-foreground">
                                        {titleCase(c.channel)} · {c.direction}
                                        {c.target_user_name ? ` · ${c.target_user_name}` : ''}
                                        {c.sent_at ? ` · ${formatDateTime(c.sent_at)}` : ''}
                                    </p>
                                </div>
                            </div>
                        ))}
                    </div>
                ) : (
                    <p className="text-xs text-muted-foreground">No communications logged.</p>
                )}
            </div>

            {d.time_entries.length || d.time_spent_minutes ? (
                <div>
                    <p className="mb-2 text-sm font-semibold text-foreground">Time on this alert</p>
                    <p className="text-xs text-muted-foreground">
                        {d.time_spent_minutes} minute{d.time_spent_minutes === 1 ? '' : 's'} logged
                        {d.time_entries.some((t) => t.is_running) ? ' · timer running' : ''}
                    </p>
                </div>
            ) : null}
        </div>
    );
}

function AddNoteForm({ alertId }: { alertId: number }) {
    const form = useForm<{ note: string }>({ note: '' });
    const submit = (e: FormEvent) => {
        e.preventDefault();
        if (!form.data.note.trim()) return;
        form.post(`/control-room/alerts/${alertId}/note`, { preserveScroll: true, onSuccess: () => form.reset() });
    };
    return (
        <form onSubmit={submit} className="flex items-start gap-2">
            <Textarea rows={2} className="flex-1" value={form.data.note} onChange={(e) => form.setData('note', e.target.value)} placeholder="Add an operator note…" />
            <Button type="submit" size="sm" disabled={form.processing || !form.data.note.trim()}>
                <Send className="mr-1.5 h-3.5 w-3.5" /> Note
            </Button>
        </form>
    );
}

function DiscussionComposer({ alertId, parentId, onDone }: { alertId: number; parentId?: number; onDone?: () => void }) {
    const form = useForm<{ content: string; parent_id: number | null }>({ content: '', parent_id: parentId ?? null });
    const submit = (e: FormEvent) => {
        e.preventDefault();
        if (!form.data.content.trim()) return;
        form.post(`/control-room/alerts/${alertId}/discussions`, {
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                onDone?.();
            },
        });
    };
    return (
        <form onSubmit={submit} className="flex items-start gap-2">
            <Textarea rows={parentId ? 1 : 2} className="flex-1" value={form.data.content} onChange={(e) => form.setData('content', e.target.value)} placeholder={parentId ? 'Reply…' : 'Start a discussion…'} />
            <Button type="submit" size="sm" variant={parentId ? 'outline' : 'default'} disabled={form.processing || !form.data.content.trim()}>
                {parentId ? 'Reply' : 'Post'}
            </Button>
        </form>
    );
}

function DiscussionThread({ d, thread }: { d: AlertWorkspaceDetail; thread: AlertWorkspaceDetail['discussions'][number] }) {
    const [replying, setReplying] = useState(false);
    return (
        <div className="rounded-lg border border-border p-2.5">
            <p className="text-sm whitespace-pre-wrap text-foreground">{thread.content}</p>
            <p className="mt-1 text-xs text-muted-foreground">
                {thread.user.name} · {formatDateTime(thread.created_at)}
                {thread.edited_at ? ' · edited' : ''}
            </p>
            {thread.replies.length ? (
                <div className="mt-2 flex flex-col gap-1.5 border-l-2 border-border pl-3">
                    {thread.replies.map((r) => (
                        <div key={r.id}>
                            <p className="text-sm whitespace-pre-wrap text-foreground">{r.content}</p>
                            <p className="text-xs text-muted-foreground">
                                {r.user.name} · {formatDateTime(r.created_at)}
                            </p>
                        </div>
                    ))}
                </div>
            ) : null}
            {d.can.manage ? (
                replying ? (
                    <div className="mt-2">
                        <DiscussionComposer alertId={d.alert.id} parentId={thread.id} onDone={() => setReplying(false)} />
                    </div>
                ) : (
                    <button type="button" onClick={() => setReplying(true)} className="mt-1.5 text-xs font-medium text-primary hover:underline">
                        Reply
                    </button>
                )
            ) : null}
        </div>
    );
}

/* --- Linked records --------------------------------------------------- */

function LinkedSection({ d }: { d: AlertWorkspaceDetail }) {
    const a = d.alert;
    const ctx = (a.context ?? {}) as Record<string, any>;
    const incidentId = ctx.incident_id ? Number(ctx.incident_id) : null;
    const hs = d.linked_hs_event;
    const rows: ReactNode[] = [];

    if (incidentId) {
        rows.push(<LinkedRow key="inc" icon={ShieldAlert} title="Incident record" sub={`INC-${incidentId} · system of record`} href={`/incidents?incident=${incidentId}`} />);
    }
    if (hs) {
        rows.push(
            <LinkedRow
                key="hs"
                icon={Activity}
                title="Health & Safety event"
                sub={`${hs.reference_number} · ${titleCase(hs.status)}${hs.investigation ? ` · investigation ${titleCase(hs.investigation.status)}` : ''}`}
                href={`/health-safety/events/${hs.id}`}
            />,
        );
    }
    if (d.client) {
        rows.push(<LinkedRow key="client" icon={User} title="Client record" sub={d.client.name} href={`/operations/clients/${d.client.id}/care`} />);
    }
    if (a.asset) {
        rows.push(<LinkedRow key="asset" icon={Truck} title="Asset" sub={`${a.asset.name} · ${a.asset.asset_tag}`} href={`/fleet-assets/assets/${a.asset.id}`} />);
    }

    return (
        <div className="flex flex-col gap-2">
            {rows.length ? rows : <p className="text-sm text-muted-foreground">No linked records.</p>}
        </div>
    );
}

function LinkedRow({ icon: Icon, title, sub, href }: { icon: ComponentType<{ className?: string }>; title: string; sub: string; href: string }) {
    return (
        <Link href={href} className="flex items-center gap-3 rounded-lg border border-border p-3 transition-colors hover:bg-muted/50">
            <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-muted">
                <Icon className="h-4 w-4 text-muted-foreground" />
            </span>
            <div className="min-w-0 flex-1">
                <p className="text-sm font-medium text-foreground">{title}</p>
                <p className="truncate text-xs text-muted-foreground">{sub}</p>
            </div>
            <ExternalLink className="h-4 w-4 text-muted-foreground" />
        </Link>
    );
}
