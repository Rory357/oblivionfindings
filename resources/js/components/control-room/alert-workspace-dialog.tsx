import { LinkedJourney } from '@/components/control-room/alert-workspace/linked-journey';
import {
    JourneyGateList,
    type JourneyGateData,
} from '@/components/incidents/journey-gate-list';
import { Button } from '@/components/ui/button';
import { Card as GuardrailCard } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import {
    Field,
    InfoCard,
    SelectInput,
    StepHead,
} from '@/components/wizard/primitives';
import { ReviewCard, ReviewRow, WizardShell } from '@/components/wizard/shell';
import { formatDateTime, toDatetimeLocal } from '@/lib/datetime';
import {
    DndContext,
    KeyboardSensor,
    PointerSensor,
    closestCenter,
    useSensor,
    useSensors,
    type DragEndEvent,
} from '@dnd-kit/core';
import {
    SortableContext,
    arrayMove,
    sortableKeyboardCoordinates,
    useSortable,
    verticalListSortingStrategy,
} from '@dnd-kit/sortable';
import { CSS } from '@dnd-kit/utilities';
import { Link, router, useForm, usePage } from '@inertiajs/react';
import {
    Activity,
    AlertTriangle,
    ArrowUpCircle,
    Bell,
    BellOff,
    BookOpen,
    Check,
    CheckCircle2,
    ClipboardList,
    Clock,
    Download,
    ExternalLink,
    Eye,
    FileText,
    GripVertical,
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
    UserPlus,
    X,
} from 'lucide-react';
import {
    useRef,
    useState,
    type ComponentType,
    type FormEvent,
    type ReactNode,
} from 'react';

/* ------------------------------------------------------------------ */
/*  Types — mirrors AlertWorkspaceService::build()                      */
/* ------------------------------------------------------------------ */

type UserRef = { id: number; name: string };

export type WorkspaceAlert = {
    id: number;
    reference_number: string | null;
    source: string;
    alert_type: string;
    severity: string;
    status: string;
    asset_id: number | null;
    asset: { id: number; name: string; asset_tag: string } | null;
    fleet_signal_id: number | null;
    fleet_signal: {
        id: number;
        signal_type: string;
        severity_hint: string;
        occurred_at: string | null;
        payload: Record<string, unknown> | null;
    } | null;
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
    is_snoozed: boolean;
    snoozed_until: string | null;
    snoozed_by: UserRef | null;
    created_at: string | null;
    updated_at: string | null;
};

export type AlertWorkspaceDetail = {
    return_to?: string;
    alert: WorkspaceAlert;
    playbook_run: {
        id: number;
        status: string;
        current_step: number;
        completed_steps: number;
        total_steps: number;
        playbook: { id: number; name: string; category: string };
        steps: Array<{
            id: number;
            title: string;
            instructions: string | null;
            status: string;
            notes: string | null;
            completed_at: string | null;
        }>;
    } | null;
    available_playbooks: Array<{
        id: number;
        name: string;
        category: string;
        description: string | null;
    }>;
    evidence_packs: Array<{
        id: number;
        title: string;
        status: string;
        item_count: number;
        items: Array<{
            id: number;
            type: string;
            title: string;
            description: string | null;
            download_url: string | null;
            created_at: string | null;
        }>;
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
    audit_logs: Array<{
        id: number;
        action: string;
        user: UserRef | null;
        meta: Record<string, unknown> | null;
        created_at: string;
    }>;
    can: {
        view: boolean;
        manage: boolean;
        watch: boolean;
        assign: boolean;
        escalate: boolean;
        create: boolean;
        create_incident: boolean;
        view_incident: boolean;
        view_health_safety: boolean;
    };
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
        subtasks: Array<{
            id: number;
            title: string;
            status: string;
            assigned_to: UserRef | null;
        }>;
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
        replies: Array<{
            id: number;
            type: string;
            content: string;
            is_internal: boolean;
            user: UserRef;
            edited_at: string | null;
            created_at: string;
        }>;
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
    config_options: {
        categories: Array<{ value: string; label: string }>;
        resolution_codes: Array<{ value: string; label: string }>;
    };
    incident_defaults: {
        immediate_action_taken: string;
        source_note: {
            id: number;
            user_name: string | null;
            created_at: string | null;
        } | null;
    };
    linked_incident: {
        id: number;
        reference_number: string;
        status: string;
        severity: string;
        title: string | null;
        href: string | null;
    } | null;
    linked_hs_event: {
        id: number;
        reference_number: string;
        status: string;
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
        };
        investigation_required: boolean;
        investigation: { reference_number: string; status: string } | null;
        href: string | null;
    } | null;
    resolve_gate: JourneyGateData;
    close_gate: JourneyGateData;
    journey_state: string;
};

type SectionKey =
    | 'overview'
    | 'sla'
    | 'playbook'
    | 'evidence'
    | 'tasks'
    | 'activity'
    | 'linked';

type ActionKey =
    | 'acknowledge'
    | 'triage'
    | 'resolve'
    | 'close'
    | 'escalate'
    | 'assign'
    | 'snooze'
    | 'create_incident'
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

const SEV_LABEL: Record<string, string> = {
    low: 'Low',
    medium: 'Medium',
    high: 'High',
    critical: 'Critical',
};
const SEV_TONE: Record<string, string> = {
    low: 'info',
    medium: 'warning',
    high: 'critical',
    critical: 'critical',
};

function isOperationalTaskOpen(status: string): boolean {
    return !['completed', 'cancelled', 'transferred'].includes(status);
}

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
    const title =
        typeof norm.title === 'string' && norm.title.trim()
            ? norm.title.trim()
            : null;
    const desc =
        typeof norm.description === 'string' && norm.description.trim()
            ? norm.description.trim()
            : null;
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
                {stepCount && stepCount > 1
                    ? `Step ${(step ?? 0) + 1} of ${stepCount}`
                    : null}
            </div>
            <div className="flex items-center gap-2">
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    onClick={onCancel}
                >
                    Cancel
                </Button>
                {onBack ? (
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        onClick={onBack}
                    >
                        Back
                    </Button>
                ) : null}
                {onNext ? (
                    <Button
                        type="button"
                        size="sm"
                        onClick={onNext}
                        disabled={nextDisabled}
                    >
                        Next
                    </Button>
                ) : null}
                {onSubmit ? (
                    <Button
                        type="button"
                        size="sm"
                        variant={destructive ? 'destructive' : 'default'}
                        onClick={onSubmit}
                        disabled={submitDisabled || processing}
                    >
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
            <Button
                variant="outline"
                size="sm"
                title={title}
                onClick={() => setArming(true)}
            >
                <Icon className="mr-1.5 h-3.5 w-3.5" /> {label}
            </Button>
        );
    }
    return (
        <span className="inline-flex items-center gap-1">
            <Button
                variant={destructive ? 'destructive' : 'default'}
                size="sm"
                onClick={() => {
                    onConfirm();
                    setArming(false);
                }}
            >
                <Check className="mr-1 h-3.5 w-3.5" /> {label}?
            </Button>
            <Button
                variant="ghost"
                size="sm"
                onClick={() => setArming(false)}
                aria-label="Cancel"
            >
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

const onPaneSuccess =
    (done: () => void) => (page: { props: Record<string, unknown> }) => {
        const flash = (page.props as { flash?: { error?: string } }).flash;
        if (!flash?.error) done();
    };

/* ------------------------------------------------------------------ */
/*  Dialog                                                             */
/* ------------------------------------------------------------------ */

export function AlertWorkspaceDialog({
    detail,
    open,
    onClose,
}: {
    detail: AlertWorkspaceDetail;
    open: boolean;
    onClose: () => void;
}) {
    const [section, setSection] = useState<SectionKey>('overview');
    const [action, setAction] = useState<ActionKey | null>(null);

    const d = detail;
    const a = d.alert;
    const alertRef = a.reference_number ?? `Alert ${a.id}`;
    const isSensor = a.source === 'sensor';
    const isActionable = OPEN_STATES.includes(a.status);
    const openTasks = d.tasks.filter((t) =>
        isOperationalTaskOpen(t.status),
    ).length;
    const statusMeta = STATUS_META[a.status] ?? {
        label: titleCase(a.status),
        tone: 'neutral',
    };

    const SECTIONS: {
        key: SectionKey;
        label: string;
        blurb: string;
        icon: ComponentType<{ className?: string }>;
    }[] = [
        {
            key: 'overview',
            label: 'Overview',
            blurb: "What's happening",
            icon: FileText,
        },
        {
            key: 'sla',
            label: 'SLA & timeline',
            blurb: d.sla ? 'deadlines & audit' : 'audit trail',
            icon: Clock,
        },
        {
            key: 'playbook',
            label: 'Playbook',
            blurb: d.playbook_run
                ? `${d.playbook_run.completed_steps}/${d.playbook_run.total_steps} steps`
                : 'not started',
            icon: BookOpen,
        },
        {
            key: 'evidence',
            label: 'Evidence',
            blurb: d.evidence_packs.length
                ? `${d.evidence_packs.length} pack${d.evidence_packs.length === 1 ? '' : 's'}`
                : 'no packs',
            icon: Package,
        },
        {
            key: 'tasks',
            label: 'Tasks',
            blurb: openTasks > 0 ? `${openTasks} open` : 'none open',
            icon: ListTodo,
        },
        {
            key: 'activity',
            label: 'Notes & comms',
            blurb: 'operator log',
            icon: MessageSquare,
        },
        {
            key: 'linked',
            label: 'Linked records',
            blurb: 'incident · H&S · client',
            icon: LinkIcon,
        },
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
        snooze: 'Snooze alert',
        create_incident: 'Create incident and hand over',
        confirm_sensor: 'Confirm sensor detection',
        dismiss_sensor: 'Dismiss as false positive',
        edit_meta: 'Edit alert details',
        start_playbook: 'Start a playbook',
    };
    const headerLabel = action
        ? ACTION_TITLES[action]
        : (SECTIONS[stepIndex]?.label ?? 'Overview');

    const closePane = () => setAction(null);

    // While an action pane is open it owns the body + its own buttons.
    const footerEnd = action ? null : (
        <div className="flex flex-wrap items-center justify-end gap-2">
            <Link
                href={
                    d.return_to
                        ? `/control-room/alerts/${a.id}?return_to=${encodeURIComponent(d.return_to)}`
                        : `/control-room/alerts/${a.id}`
                }
                className="inline-flex items-center gap-1.5 rounded-lg px-3 py-2 text-sm font-medium text-muted-foreground transition-colors hover:bg-muted"
            >
                <ExternalLink className="h-4 w-4" /> Full page
            </Link>
            {d.can.manage && isSensor && isActionable ? (
                <>
                    <Button
                        size="sm"
                        variant="outline"
                        onClick={() => setAction('dismiss_sensor')}
                    >
                        <X className="mr-1.5 h-4 w-4" /> Dismiss
                    </Button>
                    <Button
                        size="sm"
                        onClick={() => setAction('confirm_sensor')}
                    >
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
                <Button
                    size="sm"
                    variant={a.status === 'ack' ? 'default' : 'outline'}
                    onClick={() => setAction('triage')}
                >
                    <Eye className="mr-1.5 h-4 w-4" /> Start triage
                </Button>
            ) : null}
            {d.can.manage && isActionable ? (
                <Button
                    size="sm"
                    variant={a.status === 'triaging' ? 'default' : 'outline'}
                    onClick={() => setAction('resolve')}
                >
                    <CheckCircle2 className="mr-1.5 h-4 w-4" /> Resolve
                </Button>
            ) : null}
            {d.can.manage && a.status === 'resolved' ? (
                <Button size="sm" onClick={() => setAction('close')}>
                    <CheckCircle2 className="mr-1.5 h-4 w-4" /> Close
                </Button>
            ) : null}
            {d.can.escalate && isActionable ? (
                <Button
                    size="sm"
                    variant="outline"
                    onClick={() => setAction('escalate')}
                >
                    <ArrowUpCircle className="mr-1.5 h-4 w-4" /> Escalate
                </Button>
            ) : null}
            {d.can.assign && isActionable ? (
                <Button
                    size="sm"
                    variant="outline"
                    onClick={() => setAction('assign')}
                >
                    <User className="mr-1.5 h-4 w-4" />{' '}
                    {a.assigned_to ? 'Reassign' : 'Assign'}
                </Button>
            ) : null}
            {d.can.manage &&
            isActionable &&
            a.severity !== 'critical' &&
            !a.is_snoozed ? (
                <Button
                    size="sm"
                    variant="outline"
                    onClick={() => setAction('snooze')}
                >
                    <BellOff className="mr-1.5 h-4 w-4" /> Snooze
                </Button>
            ) : null}
        </div>
    );

    const footerStart = (
        <div className="flex items-center gap-2 text-xs">
            <span className="inline-flex items-center gap-1 rounded-full bg-muted px-2 py-0.5 font-medium">
                <span
                    className={`h-1.5 w-1.5 rounded-full ${DOT[SEV_TONE[a.severity] ?? 'neutral']}`}
                />
                {SEV_LABEL[a.severity] ?? titleCase(a.severity)}
            </span>
            <span className="text-muted-foreground">{d.journey_state}</span>
            {a.escalation_level > 0 ? (
                <span className="font-medium text-status-warning">
                    L{a.escalation_level}
                </span>
            ) : null}
        </div>
    );

    const railExtra = d.can.watch ? <WatchToggle d={d} /> : null;

    return (
        <WizardShell
            open={open}
            onClose={onClose}
            title={`Alert ${alertRef}`}
            description={`${titleCase(a.alert_type)} — ${d.client?.name ?? d.alert.asset?.name ?? titleCase(a.source)}`}
            railIcon={RadioTower}
            railTitle={
                d.client?.name ?? a.asset?.name ?? titleCase(a.alert_type)
            }
            railSub={`${alertRef} · ${titleCase(a.source)}`}
            steps={SECTIONS}
            stepIndex={stepIndex}
            onStepClick={(i) => setSection(SECTIONS[i].key)}
            headerLabel={headerLabel}
            footerStart={footerStart}
            footerEnd={footerEnd}
            railExtra={railExtra}
        >
            {action === 'acknowledge' ? (
                <AcknowledgePane d={d} onDone={closePane} />
            ) : null}
            {action === 'triage' ? (
                <StartTriagePane d={d} onDone={closePane} />
            ) : null}
            {action === 'resolve' ? (
                <ResolvePane d={d} onDone={closePane} />
            ) : null}
            {action === 'close' ? <ClosePane d={d} onDone={closePane} /> : null}
            {action === 'escalate' ? (
                <EscalatePane d={d} onDone={closePane} />
            ) : null}
            {action === 'assign' ? (
                <AssignPane d={d} onDone={closePane} />
            ) : null}
            {action === 'snooze' ? (
                <SnoozePane d={d} onDone={closePane} />
            ) : null}
            {action === 'create_incident' ? (
                <CreateIncidentPane d={d} onDone={closePane} />
            ) : null}
            {action === 'confirm_sensor' ? (
                <SensorConfirmPane d={d} onDone={closePane} />
            ) : null}
            {action === 'dismiss_sensor' ? (
                <SensorDismissPane d={d} onDone={closePane} />
            ) : null}
            {action === 'edit_meta' ? (
                <EditMetaPane d={d} onDone={closePane} />
            ) : null}
            {action === 'start_playbook' ? (
                <StartPlaybookPane
                    d={d}
                    onDone={() => {
                        closePane();
                        setSection('playbook');
                    }}
                />
            ) : null}
            {!action ? (
                <>
                    {section === 'overview' ? (
                        <OverviewSection
                            d={d}
                            onEditMeta={() => setAction('edit_meta')}
                            onConfirmSensor={() => setAction('confirm_sensor')}
                            onCreateIncident={() =>
                                setAction('create_incident')
                            }
                        />
                    ) : null}
                    {section === 'sla' ? <SlaTimelineSection d={d} /> : null}
                    {section === 'playbook' ? (
                        <PlaybookSection
                            d={d}
                            onStart={() => setAction('start_playbook')}
                        />
                    ) : null}
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

export function WatchToggle({ d }: { d: AlertWorkspaceDetail }) {
    const a = d.alert;
    const [busy, setBusy] = useState(false);
    const [adding, setAdding] = useState(false);
    const [pick, setPick] = useState('');

    const watcherIds = new Set(d.watchers.map((w) => w.user_id));
    const addable = d.staff.filter((s) => !watcherIds.has(s.id));

    if (!d.can.watch) {
        return null;
    }

    const toggle = () => {
        setBusy(true);
        router.post(
            `/control-room/alerts/${a.id}/watchers/toggle`,
            {},
            {
                preserveScroll: true,
                preserveState: true,
                onFinish: () => setBusy(false),
            },
        );
    };
    const addWatcher = () => {
        if (!pick) return;
        router.post(
            `/control-room/alerts/${a.id}/watchers`,
            { user_id: Number(pick) },
            {
                preserveScroll: true,
                preserveState: true,
                onSuccess: () => {
                    setPick('');
                    setAdding(false);
                },
            },
        );
    };
    const removeWatcher = (userId: number) => {
        router.delete(`/control-room/alerts/${a.id}/watchers/${userId}`, {
            preserveScroll: true,
            preserveState: true,
        });
    };

    return (
        <GuardrailCard
            unstyled
            className="rounded-lg border border-sidebar-border bg-background/40 p-2.5"
        >
            <Button
                unstyled
                type="button"
                onClick={toggle}
                disabled={busy}
                className="flex w-full items-center gap-2 text-left text-[12px] font-semibold text-foreground hover:text-primary"
            >
                <Eye
                    className={`h-3.5 w-3.5 ${d.is_watching ? 'text-primary' : 'text-muted-foreground'}`}
                />
                {d.is_watching ? 'Watching' : 'Watch this alert'}
            </Button>

            {d.watchers.length ? (
                <ul className="mt-2 flex flex-col gap-1">
                    {d.watchers.map((w) => (
                        <li
                            key={w.id}
                            className="flex items-center gap-1.5 text-[11px] text-muted-foreground"
                        >
                            <span className="min-w-0 flex-1 truncate">
                                {w.user_name}
                            </span>
                            {d.can.manage ? (
                                <Button
                                    unstyled
                                    type="button"
                                    onClick={() => removeWatcher(w.user_id)}
                                    className="text-muted-foreground/60 hover:text-status-critical"
                                    aria-label={`Remove ${w.user_name}`}
                                >
                                    <X className="h-3 w-3" />
                                </Button>
                            ) : null}
                        </li>
                    ))}
                </ul>
            ) : (
                <p className="mt-2 text-[11px] text-muted-foreground">
                    No watchers yet.
                </p>
            )}

            {d.can.manage ? (
                adding ? (
                    <div className="mt-2 flex flex-col gap-1.5">
                        <SelectInput
                            value={pick}
                            onChange={setPick}
                            placeholder="Add a colleague…"
                            options={addable.map((s) => ({
                                value: String(s.id),
                                label: s.name,
                            }))}
                        />
                        <div className="flex items-center gap-1.5">
                            <Button
                                type="button"
                                size="sm"
                                className="h-7 flex-1 text-xs"
                                onClick={addWatcher}
                                disabled={!pick}
                            >
                                Add watcher
                            </Button>
                            <Button
                                type="button"
                                size="sm"
                                variant="ghost"
                                className="h-7 text-xs"
                                onClick={() => {
                                    setAdding(false);
                                    setPick('');
                                }}
                            >
                                Cancel
                            </Button>
                        </div>
                    </div>
                ) : addable.length ? (
                    <Button
                        unstyled
                        type="button"
                        onClick={() => setAdding(true)}
                        className="mt-2 inline-flex items-center gap-1 text-[11px] font-medium text-primary hover:underline"
                    >
                        <UserPlus className="h-3 w-3" /> Add watcher
                    </Button>
                ) : null
            ) : null}
        </GuardrailCard>
    );
}

/* ------------------------------------------------------------------ */
/*  Guided action panes                                                */
/* ------------------------------------------------------------------ */

function ContextCard({ d }: { d: AlertWorkspaceDetail }) {
    const a = d.alert;
    return (
        <ReviewCard
            icon={RadioTower}
            title={`${a.reference_number ?? `Alert ${a.id}`} · ${titleCase(a.alert_type)}`}
            span
        >
            <ReviewRow label="Summary" value={summarise(a)} />
            <ReviewRow
                label="Severity"
                value={SEV_LABEL[a.severity] ?? titleCase(a.severity)}
            />
            <ReviewRow
                label="Status"
                value={
                    (STATUS_META[a.status] ?? { label: titleCase(a.status) })
                        .label
                }
            />
            <ReviewRow label="Client" value={d.client?.name} />
            <ReviewRow
                label="Triggered"
                value={
                    a.triggered_at ? formatDateTime(a.triggered_at) : undefined
                }
            />
        </ReviewCard>
    );
}

function AcknowledgePane({
    d,
    onDone,
}: {
    d: AlertWorkspaceDetail;
    onDone: () => void;
}) {
    const form = useForm<{ notes: string }>({ notes: '' });
    const submit = () => {
        form.post(`/control-room/alerts/${d.alert.id}/acknowledge`, {
            preserveScroll: true,
            onSuccess: onPaneSuccess(onDone),
        });
    };
    return (
        <div className="flex flex-col gap-4">
            <StepHead
                icon={UserCheck}
                title="Acknowledge alert"
                blurb="Confirms an operator has seen this alert — it stops the acknowledge SLA clock and moves the alert to Acknowledged."
            />
            <PaneError message={serverError(form.errors)} />
            <ContextCard d={d} />
            <Field label="Note" hint="Optional — visible in the operator log">
                <Textarea
                    rows={2}
                    value={form.data.notes}
                    onChange={(e) => form.setData('notes', e.target.value)}
                    placeholder="e.g. On it — checking the camera feed now"
                />
            </Field>
            <PaneNav
                onCancel={onDone}
                onSubmit={submit}
                submitLabel="Acknowledge alert"
                processing={form.processing}
            />
        </div>
    );
}

function StartTriagePane({
    d,
    onDone,
}: {
    d: AlertWorkspaceDetail;
    onDone: () => void;
}) {
    const form = useForm<{ notes: string }>({ notes: '' });
    const submit = () => {
        form.post(`/control-room/alerts/${d.alert.id}/triage`, {
            preserveScroll: true,
            onSuccess: onPaneSuccess(onDone),
        });
    };
    return (
        <div className="flex flex-col gap-4">
            <StepHead
                icon={Eye}
                title="Start triage"
                blurb="Marks the alert as actively being worked — it stops the response SLA clock. Resolve it when the situation is handled."
            />
            <PaneError message={serverError(form.errors)} />
            <ContextCard d={d} />
            <Field label="Triage note" hint="Optional — what are you doing?">
                <Textarea
                    rows={2}
                    value={form.data.notes}
                    onChange={(e) => form.setData('notes', e.target.value)}
                    placeholder="e.g. Calling the site lead to verify"
                />
            </Field>
            <PaneNav
                onCancel={onDone}
                onSubmit={submit}
                submitLabel="Start triage"
                processing={form.processing}
            />
        </div>
    );
}

export function ResolvePane({
    d,
    onDone,
}: {
    d: AlertWorkspaceDetail;
    onDone: () => void;
}) {
    const [step, setStep] = useState(0);
    const form = useForm<{ resolution_notes: string; resolution_code: string }>(
        {
            resolution_notes: '',
            resolution_code: d.alert.resolution_code ?? '',
        },
    );
    const codes = d.config_options.resolution_codes ?? [];
    const gate = d.resolve_gate;

    const submit = () => {
        // resolution_code travels via the meta endpoint pattern; resolve stores the notes.
        const code = form.data.resolution_code;
        form.post(`/control-room/alerts/${d.alert.id}/resolve`, {
            preserveScroll: true,
            onSuccess: (page) => {
                if ((page.props as { flash?: { error?: string } }).flash?.error)
                    return;
                if (code) {
                    router.post(
                        `/control-room/alerts/${d.alert.id}/meta`,
                        { resolution_code: code },
                        { preserveScroll: true },
                    );
                }
                onDone();
            },
        });
    };

    return (
        <div className="flex flex-col gap-4">
            <StepHead
                icon={CheckCircle2}
                title="Resolve alert"
                blurb="Resolve ends the live operational response. It does not close the linked incident or H&S governance."
            />
            <PaneError message={serverError(form.errors)} />
            <JourneyGateList gate={gate} />
            {step === 0 ? (
                <>
                    <ContextCard d={d} />
                    <Field
                        label="Resolution notes"
                        required
                        error={form.errors.resolution_notes}
                    >
                        <Textarea
                            rows={4}
                            value={form.data.resolution_notes}
                            onChange={(e) =>
                                form.setData('resolution_notes', e.target.value)
                            }
                            placeholder="What was found and what was done…"
                        />
                    </Field>
                    {codes.length ? (
                        <Field
                            label="Resolution code"
                            hint="Optional — for reporting"
                        >
                            <SelectInput
                                value={form.data.resolution_code}
                                onChange={(v) =>
                                    form.setData('resolution_code', v)
                                }
                                placeholder="Select a code"
                                options={codes}
                            />
                        </Field>
                    ) : null}
                    <PaneNav
                        onCancel={onDone}
                        onNext={() => setStep(1)}
                        nextDisabled={
                            !form.data.resolution_notes.trim() || !gate.allowed
                        }
                        step={0}
                        stepCount={2}
                    />
                </>
            ) : (
                <>
                    <ReviewCard
                        icon={CheckCircle2}
                        title="Review & resolve"
                        span
                    >
                        <ReviewRow
                            label="Alert"
                            value={`${d.alert.reference_number ?? `Alert ${d.alert.id}`} · ${titleCase(d.alert.alert_type)}`}
                        />
                        <ReviewRow
                            label="Resolution"
                            value={form.data.resolution_notes}
                        />
                        <ReviewRow
                            label="Code"
                            value={
                                codes.find(
                                    (c) =>
                                        c.value === form.data.resolution_code,
                                )?.label ??
                                (form.data.resolution_code || undefined)
                            }
                        />
                    </ReviewCard>
                    <PaneNav
                        onCancel={onDone}
                        onBack={() => setStep(0)}
                        onSubmit={submit}
                        submitLabel="Resolve alert"
                        submitDisabled={!gate.allowed}
                        processing={form.processing}
                        step={1}
                        stepCount={2}
                    />
                </>
            )}
        </div>
    );
}

export function ClosePane({
    d,
    onDone,
}: {
    d: AlertWorkspaceDetail;
    onDone: () => void;
}) {
    const form = useForm<{ closure_notes: string }>({ closure_notes: '' });
    const submit = () => {
        form.post(`/control-room/alerts/${d.alert.id}/close`, {
            preserveScroll: true,
            onSuccess: onPaneSuccess(onDone),
        });
    };
    return (
        <div className="flex flex-col gap-4">
            <StepHead
                icon={CheckCircle2}
                title="Close alert"
                blurb="Close is available only when the incident and H&S governance are closed."
            />
            <PaneError message={serverError(form.errors)} />
            <JourneyGateList gate={d.close_gate} />
            <ReviewCard icon={CheckCircle2} title="Resolution on record" span>
                <ReviewRow
                    label="Resolved"
                    value={
                        d.alert.resolved_at
                            ? formatDateTime(d.alert.resolved_at)
                            : undefined
                    }
                />
                <ReviewRow label="By" value={d.alert.resolved_by?.name} />
                <ReviewRow label="Notes" value={d.alert.notes} />
            </ReviewCard>
            <Field label="Closing note" hint="Optional">
                <Textarea
                    rows={2}
                    value={form.data.closure_notes}
                    onChange={(e) =>
                        form.setData('closure_notes', e.target.value)
                    }
                />
            </Field>
            <PaneNav
                onCancel={onDone}
                onSubmit={submit}
                submitLabel="Close alert"
                submitDisabled={!d.close_gate.allowed}
                processing={form.processing}
            />
        </div>
    );
}

function EscalatePane({
    d,
    onDone,
}: {
    d: AlertWorkspaceDetail;
    onDone: () => void;
}) {
    const current = d.alert.escalation_level ?? 0;
    const next = Math.min(current + 1, 5);
    const form = useForm<{ escalation_reason: string }>({
        escalation_reason: '',
    });
    const submit = () => {
        form.post(`/control-room/alerts/${d.alert.id}/escalate`, {
            preserveScroll: true,
            onSuccess: onPaneSuccess(onDone),
        });
    };
    return (
        <div className="flex flex-col gap-4">
            <StepHead
                icon={ArrowUpCircle}
                title="Escalate alert"
                blurb={`Raises the escalation level from L${current} to L${next} and flags it for senior attention. The reason is kept on the audit trail.`}
            />
            <PaneError message={serverError(form.errors)} />
            <ContextCard d={d} />
            <Field
                label="Reason for escalating"
                required
                error={form.errors.escalation_reason}
            >
                <Textarea
                    rows={3}
                    value={form.data.escalation_reason}
                    onChange={(e) =>
                        form.setData('escalation_reason', e.target.value)
                    }
                    placeholder="Why does this need senior attention?"
                />
            </Field>
            <PaneNav
                onCancel={onDone}
                onSubmit={submit}
                submitLabel={`Escalate to L${next}`}
                processing={form.processing}
                submitDisabled={!form.data.escalation_reason.trim()}
            />
        </div>
    );
}

function AssignPane({
    d,
    onDone,
}: {
    d: AlertWorkspaceDetail;
    onDone: () => void;
}) {
    const a = d.alert;
    const form = useForm<{ assigned_to_user_id: string; reason: string }>({
        assigned_to_user_id: a.assigned_to ? String(a.assigned_to.id) : '',
        reason: '',
    });
    const [unassignArming, setUnassignArming] = useState(false);
    const selected = d.staff.find(
        (s) => String(s.id) === form.data.assigned_to_user_id,
    );

    const submit = () => {
        form.transform((data) => ({
            ...data,
            assigned_to_user_id: Number(data.assigned_to_user_id),
        }));
        form.post(`/control-room/alerts/${a.id}/assign`, {
            preserveScroll: true,
            onSuccess: onPaneSuccess(onDone),
        });
    };
    const unassign = () => {
        router.post(
            `/control-room/alerts/${a.id}/unassign`,
            {},
            { preserveScroll: true, onSuccess: () => onDone() },
        );
    };

    return (
        <div className="flex flex-col gap-4">
            <StepHead
                icon={User}
                title={a.assigned_to ? 'Reassign alert' : 'Assign alert'}
                blurb="Choose who owns this alert. The change is recorded on the assignment history."
            />
            <PaneError message={serverError(form.errors)} />
            {a.assigned_to ? (
                <InfoCard icon={User} tone="info">
                    Currently assigned to{' '}
                    <span className="font-semibold">{a.assigned_to.name}</span>
                    {a.assigned_at
                        ? ` since ${formatDateTime(a.assigned_at)}`
                        : ''}
                    .
                </InfoCard>
            ) : null}
            <Field
                label="Assign to"
                required
                error={form.errors.assigned_to_user_id}
            >
                <SelectInput
                    value={form.data.assigned_to_user_id}
                    onChange={(v) => form.setData('assigned_to_user_id', v)}
                    placeholder="Select a staff member"
                    options={d.staff.map((s) => ({
                        value: String(s.id),
                        label: s.name,
                    }))}
                />
            </Field>
            <Field
                label="Reason"
                hint="Optional — kept on the assignment history"
            >
                <Input
                    value={form.data.reason}
                    onChange={(e) => form.setData('reason', e.target.value)}
                    placeholder="e.g. On shift and closest to the site"
                />
            </Field>
            <div className="flex items-center justify-between gap-2 border-t border-border pt-4">
                <div>
                    {a.assigned_to ? (
                        !unassignArming ? (
                            <Button
                                type="button"
                                variant="ghost"
                                size="sm"
                                className="text-status-critical hover:text-status-critical"
                                onClick={() => setUnassignArming(true)}
                            >
                                <UserMinus className="mr-1.5 h-3.5 w-3.5" />{' '}
                                Unassign
                            </Button>
                        ) : (
                            <span className="inline-flex items-center gap-1">
                                <Button
                                    type="button"
                                    variant="destructive"
                                    size="sm"
                                    onClick={unassign}
                                >
                                    <Check className="mr-1 h-3.5 w-3.5" />{' '}
                                    Unassign {a.assigned_to.name}?
                                </Button>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="sm"
                                    onClick={() => setUnassignArming(false)}
                                    aria-label="Cancel unassign"
                                >
                                    <X className="h-3.5 w-3.5" />
                                </Button>
                            </span>
                        )
                    ) : null}
                </div>
                <div className="flex items-center gap-2">
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        onClick={onDone}
                    >
                        Cancel
                    </Button>
                    <Button
                        type="button"
                        size="sm"
                        onClick={submit}
                        disabled={!selected || form.processing}
                    >
                        {selected
                            ? `Assign to ${selected.name.split(' ')[0]}`
                            : 'Assign'}
                    </Button>
                </div>
            </div>
        </div>
    );
}

const SNOOZE_WINDOWS: ReadonlyArray<{
    value: string;
    label: string;
    hint: string;
}> = [
    { value: '15m', label: '15 minutes', hint: 'Quick set-aside' },
    { value: '1h', label: '1 hour', hint: 'Come back this shift' },
    { value: 'shift', label: 'End of day', hint: 'Until tonight' },
    { value: 'custom', label: 'Custom time', hint: 'Pick a date & time' },
];

function SnoozePane({
    d,
    onDone,
}: {
    d: AlertWorkspaceDetail;
    onDone: () => void;
}) {
    const a = d.alert;
    const form = useForm<{
        window: string;
        snoozed_until: string;
        note: string;
    }>({ window: '15m', snoozed_until: '', note: '' });
    const needsCustom = form.data.window === 'custom';
    const submit = () => {
        form.transform((data) => ({
            window: data.window,
            snoozed_until:
                data.window === 'custom' ? data.snoozed_until || null : null,
            note: data.note || null,
        }));
        form.post(`/control-room/alerts/${a.id}/snooze`, {
            preserveScroll: true,
            onSuccess: onPaneSuccess(onDone),
        });
    };
    return (
        <div className="flex flex-col gap-4">
            <StepHead
                icon={BellOff}
                title="Snooze alert"
                blurb="Set this alert aside for a while. It stays open and its SLA keeps running — it just drops off the worklist until the time's up, then returns on its own. Find it anytime under the Snoozed tab."
            />
            <PaneError message={serverError(form.errors)} />
            <ContextCard d={d} />
            <Field label="Snooze for" required error={form.errors.window}>
                <div className="grid grid-cols-2 gap-2">
                    {SNOOZE_WINDOWS.map((w) => {
                        const active = form.data.window === w.value;
                        return (
                            <Button
                                unstyled
                                key={w.value}
                                type="button"
                                onClick={() => form.setData('window', w.value)}
                                className={`rounded-lg border p-3 text-left transition-colors ${active ? 'border-primary bg-primary/5 ring-1 ring-primary' : 'border-border hover:bg-muted/50'}`}
                            >
                                <p className="text-sm font-medium text-foreground">
                                    {w.label}
                                </p>
                                <p className="text-xs text-muted-foreground">
                                    {w.hint}
                                </p>
                            </Button>
                        );
                    })}
                </div>
            </Field>
            {needsCustom ? (
                <Field
                    label="Snooze until"
                    required
                    error={form.errors.snoozed_until}
                >
                    <Input
                        type="datetime-local"
                        value={form.data.snoozed_until}
                        onChange={(e) =>
                            form.setData('snoozed_until', e.target.value)
                        }
                    />
                </Field>
            ) : null}
            <Field
                label="Note"
                hint="Optional — why you're snoozing (kept on the audit trail)"
            >
                <Input
                    value={form.data.note}
                    onChange={(e) => form.setData('note', e.target.value)}
                    placeholder="e.g. Waiting on the family to call back"
                />
            </Field>
            <PaneNav
                onCancel={onDone}
                onSubmit={submit}
                submitLabel="Snooze alert"
                processing={form.processing}
                submitDisabled={needsCustom && !form.data.snoozed_until}
            />
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

function incidentTypeFromAlert(alertType: string): string {
    const inferred = alertType.startsWith('incident.')
        ? alertType.slice('incident.'.length)
        : alertType === 'fall_detected'
          ? 'fall'
          : alertType;

    return INCIDENT_TYPE_OPTIONS.some((option) => option.value === inferred)
        ? inferred
        : 'other';
}

function ImmediateControlsPrefillNotice({
    d,
    required,
}: {
    d: AlertWorkspaceDetail;
    required: boolean;
}) {
    const source = d.incident_defaults?.source_note;
    if (!source) {
        return required ? (
            <InfoCard icon={AlertTriangle} tone="warn">
                No marked Immediate controls note was found. Record or confirm
                the immediate action below before creating the incident.
            </InfoCard>
        ) : null;
    }

    return (
        <InfoCard icon={ShieldAlert} tone="info">
            Prefilled from an Immediate controls note
            {source.user_name ? ` by ${source.user_name}` : ''}
            {source.created_at
                ? ` on ${formatDateTime(source.created_at)}`
                : ''}
            . Check and edit it before creating the incident.
        </InfoCard>
    );
}

export function CreateIncidentPane({
    d,
    onDone,
}: {
    d: AlertWorkspaceDetail;
    onDone: () => void;
}) {
    const form = useForm<{
        type: string;
        severity: string;
        description: string;
        immediate_action_taken: string;
    }>({
        type: incidentTypeFromAlert(d.alert.alert_type),
        severity: d.alert.severity,
        description: d.alert.notes ?? '',
        immediate_action_taken:
            d.incident_defaults?.immediate_action_taken ?? '',
    });
    const serious =
        d.alert.severity === 'critical' ||
        ['high', 'critical'].includes(form.data.severity);

    const submit = () => {
        form.post(`/control-room/alerts/${d.alert.id}/create-incident`, {
            preserveScroll: true,
            onSuccess: onPaneSuccess(onDone),
        });
    };

    return (
        <div className="flex flex-col gap-4">
            <StepHead
                icon={ShieldAlert}
                title="Create incident and hand over"
                blurb="Create the official incident record and hand governance ownership to Health & Safety."
            />
            <PaneError message={serverError(form.errors)} />
            <ContextCard d={d} />
            <ImmediateControlsPrefillNotice d={d} required={serious} />
            <div className="grid gap-3 sm:grid-cols-2">
                <Field label="Incident type" error={form.errors.type}>
                    <SelectInput
                        value={form.data.type}
                        onChange={(value) => form.setData('type', value)}
                        placeholder="Select incident type"
                        options={INCIDENT_TYPE_OPTIONS}
                    />
                </Field>
                <Field label="Severity" error={form.errors.severity}>
                    <SelectInput
                        value={form.data.severity}
                        onChange={(value) => form.setData('severity', value)}
                        placeholder="Select severity"
                        options={[
                            { value: 'low', label: 'Low' },
                            { value: 'medium', label: 'Medium' },
                            { value: 'high', label: 'High' },
                            { value: 'critical', label: 'Critical' },
                        ]}
                    />
                </Field>
            </div>
            <Field
                label="Immediate action taken"
                required={serious}
                error={form.errors.immediate_action_taken}
                hint={
                    serious
                        ? 'Required for high and critical alerts'
                        : 'Optional'
                }
            >
                <Textarea
                    rows={4}
                    aria-label={
                        serious
                            ? 'Immediate action taken *'
                            : 'Immediate action taken'
                    }
                    value={form.data.immediate_action_taken}
                    onChange={(event) =>
                        form.setData(
                            'immediate_action_taken',
                            event.target.value,
                        )
                    }
                    placeholder="What was done immediately to keep people safe?"
                />
            </Field>
            {serious && !form.data.immediate_action_taken.trim() ? (
                <InfoCard icon={AlertTriangle} tone="warn">
                    Record the controls taken. If none could be taken, enter “No
                    immediate control was possible”.
                </InfoCard>
            ) : null}
            <Field label="Incident description" hint="Optional">
                <Textarea
                    rows={3}
                    value={form.data.description}
                    onChange={(event) =>
                        form.setData('description', event.target.value)
                    }
                    placeholder="What happened?"
                />
            </Field>
            <PaneNav
                onCancel={onDone}
                onSubmit={submit}
                submitLabel="Create incident and hand over"
                processing={form.processing}
                submitDisabled={
                    serious && !form.data.immediate_action_taken.trim()
                }
            />
        </div>
    );
}

function SensorEvidenceCard({ d }: { d: AlertWorkspaceDetail }) {
    const a = d.alert;
    const ctx = (a.context ?? {}) as Record<string, any>;
    const signal = (ctx.signal ?? ctx.normalized_data ?? {}) as Record<
        string,
        any
    >;
    const payload = (signal.payload ?? ctx.payload ?? {}) as Record<
        string,
        any
    >;
    return (
        <ReviewCard icon={Radar} title="Signal evidence" span>
            <ReviewRow
                label="Signal"
                value={titleCase(
                    String(signal.signal_type_code ?? a.alert_type),
                )}
            />
            <ReviewRow
                label="Device"
                value={signal.device ? String(signal.device) : undefined}
            />
            <ReviewRow
                label="Confidence"
                value={
                    payload.confidence != null
                        ? String(payload.confidence)
                        : undefined
                }
            />
            <ReviewRow
                label="Location"
                value={
                    payload.location
                        ? String(payload.location)
                        : (d.location?.description ?? undefined)
                }
            />
            <ReviewRow
                label="Detected"
                value={
                    a.triggered_at ? formatDateTime(a.triggered_at) : undefined
                }
            />
            <ReviewRow label="Client" value={d.client?.name} />
        </ReviewCard>
    );
}

export function SensorConfirmPane({
    d,
    onDone,
}: {
    d: AlertWorkspaceDetail;
    onDone: () => void;
}) {
    const [step, setStep] = useState(0);
    const form = useForm<{
        type: string;
        severity: string;
        note: string;
        immediate_action_taken: string;
    }>({
        type: d.alert.alert_type === 'fall_detected' ? 'fall' : '',
        severity: 'high',
        note: '',
        immediate_action_taken:
            d.incident_defaults?.immediate_action_taken ?? '',
    });
    const serious =
        d.alert.severity === 'critical' ||
        ['high', 'critical'].includes(form.data.severity);

    const submit = () => {
        form.post(`/control-room/alerts/${d.alert.id}/confirm`, {
            preserveScroll: true,
            onSuccess: onPaneSuccess(onDone),
        });
    };

    return (
        <div className="flex flex-col gap-4">
            <StepHead
                icon={Radar}
                title="Confirm sensor detection"
                blurb="Confirms this detection is real and creates the incident record (system of record) carrying the sensor evidence."
            />
            <PaneError message={serverError(form.errors)} />
            {step === 0 ? (
                <>
                    <SensorEvidenceCard d={d} />
                    <InfoCard icon={ShieldAlert} tone="info">
                        Confirming creates an incident linked to this alert. If
                        this is a false positive, use{' '}
                        <span className="font-semibold">Dismiss</span> instead —
                        that logs a tuning reason and creates nothing.
                    </InfoCard>
                    <PaneNav
                        onCancel={onDone}
                        onNext={() => setStep(1)}
                        step={0}
                        stepCount={2}
                    />
                </>
            ) : (
                <>
                    <div className="grid gap-3 sm:grid-cols-2">
                        <Field
                            label="Incident type"
                            hint="Defaults from the signal"
                            error={form.errors.type}
                        >
                            <SelectInput
                                value={form.data.type}
                                onChange={(v) => form.setData('type', v)}
                                placeholder="Select type"
                                options={INCIDENT_TYPE_OPTIONS}
                            />
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
                    <ImmediateControlsPrefillNotice d={d} required={serious} />
                    <Field
                        label="Immediate action taken"
                        required={serious}
                        error={form.errors.immediate_action_taken}
                    >
                        <Textarea
                            rows={3}
                            aria-label={
                                serious
                                    ? 'Immediate action taken *'
                                    : 'Immediate action taken'
                            }
                            value={form.data.immediate_action_taken}
                            onChange={(e) =>
                                form.setData(
                                    'immediate_action_taken',
                                    e.target.value,
                                )
                            }
                            placeholder="What was done immediately to keep people safe?"
                        />
                    </Field>
                    {serious && !form.data.immediate_action_taken.trim() ? (
                        <InfoCard icon={AlertTriangle} tone="warn">
                            Record the controls taken. If none could be taken,
                            enter “No immediate control was possible”.
                        </InfoCard>
                    ) : null}
                    <Field label="Note" hint="Optional — added to the incident">
                        <Textarea
                            rows={3}
                            value={form.data.note}
                            onChange={(e) =>
                                form.setData('note', e.target.value)
                            }
                            placeholder="What did you verify?"
                        />
                    </Field>
                    <PaneNav
                        onCancel={onDone}
                        onBack={() => setStep(0)}
                        onSubmit={submit}
                        submitLabel="Confirm — create incident"
                        processing={form.processing}
                        submitDisabled={
                            serious && !form.data.immediate_action_taken.trim()
                        }
                        step={1}
                        stepCount={2}
                    />
                </>
            )}
        </div>
    );
}

const DISMISS_REASONS = [
    'Resident sat down',
    'Pet or animal',
    'Object dropped',
    'Staff present',
    'Other',
];

function SensorDismissPane({
    d,
    onDone,
}: {
    d: AlertWorkspaceDetail;
    onDone: () => void;
}) {
    const [reason, setReason] = useState('');
    const [other, setOther] = useState('');
    const form = useForm<{ reason: string }>({ reason: '' });
    const finalReason = reason === 'Other' ? other.trim() : reason;

    const submit = () => {
        form.transform(() => ({ reason: finalReason }));
        form.post(`/control-room/alerts/${d.alert.id}/dismiss`, {
            preserveScroll: true,
            onSuccess: onPaneSuccess(onDone),
        });
    };

    return (
        <div className="flex flex-col gap-4">
            <StepHead
                icon={ShieldQuestion}
                title="Dismiss as false positive"
                blurb="No incident is created — the reason is logged so sensor rules can be tuned."
            />
            <PaneError
                message={serverError(form.errors) ?? form.errors.reason}
            />
            <SensorEvidenceCard d={d} />
            <Field label="Why is this a false positive?" required>
                <div className="flex flex-wrap gap-2">
                    {DISMISS_REASONS.map((r) => (
                        <Button
                            unstyled
                            key={r}
                            type="button"
                            onClick={() => setReason(r)}
                            className={`rounded-full border px-3 py-1.5 text-xs font-medium transition-colors ${reason === r ? 'border-primary bg-primary/10 text-primary' : 'border-border text-muted-foreground hover:bg-muted'}`}
                        >
                            {r}
                        </Button>
                    ))}
                </div>
            </Field>
            {reason === 'Other' ? (
                <Field label="Describe it">
                    <Input
                        value={other}
                        onChange={(e) => setOther(e.target.value)}
                        placeholder="Describe the false positive"
                    />
                </Field>
            ) : null}
            <PaneNav
                onCancel={onDone}
                onSubmit={submit}
                submitLabel="Dismiss alert"
                destructive
                processing={form.processing}
                submitDisabled={!finalReason}
            />
        </div>
    );
}

function EditMetaPane({
    d,
    onDone,
}: {
    d: AlertWorkspaceDetail;
    onDone: () => void;
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
    const submit = () => {
        form.transform((data) => {
            const out: Record<string, string | null> = {};
            out.priority = data.priority || null;
            out.category = data.category || null;
            out.due_at = data.due_at || null;
            out.resolution_code = data.resolution_code || null;
            return out;
        });
        form.post(`/control-room/alerts/${a.id}/meta`, {
            preserveScroll: true,
            onSuccess: onPaneSuccess(onDone),
        });
    };
    return (
        <div className="flex flex-col gap-4">
            <StepHead
                icon={Pencil}
                title="Edit alert details"
                blurb="Working details for the operator desk — category, priority, internal due time and resolution code."
            />
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
                    {(d.config_options.categories ?? []).length ? (
                        <SelectInput
                            value={form.data.category}
                            onChange={(v) => form.setData('category', v)}
                            placeholder="No category"
                            options={d.config_options.categories ?? []}
                        />
                    ) : (
                        <p className="rounded-md border border-dashed border-border px-3 py-2 text-xs text-muted-foreground">
                            No categories configured yet — add them under
                            Control Room settings → Ticket options.
                        </p>
                    )}
                </Field>
                <Field
                    label="Due"
                    hint="Internal target"
                    error={form.errors.due_at}
                >
                    <Input
                        type="datetime-local"
                        value={form.data.due_at}
                        onChange={(e) => form.setData('due_at', e.target.value)}
                    />
                </Field>
                <Field
                    label="Resolution code"
                    error={form.errors.resolution_code}
                >
                    {(d.config_options.resolution_codes ?? []).length ? (
                        <SelectInput
                            value={form.data.resolution_code}
                            onChange={(v) => form.setData('resolution_code', v)}
                            placeholder="Not set"
                            options={d.config_options.resolution_codes ?? []}
                        />
                    ) : (
                        <p className="rounded-md border border-dashed border-border px-3 py-2 text-xs text-muted-foreground">
                            No resolution codes configured yet — add them under
                            Control Room settings → Ticket options.
                        </p>
                    )}
                </Field>
            </div>
            <PaneNav
                onCancel={onDone}
                onSubmit={submit}
                submitLabel="Save details"
                processing={form.processing}
            />
        </div>
    );
}

function StartPlaybookPane({
    d,
    onDone,
}: {
    d: AlertWorkspaceDetail;
    onDone: () => void;
}) {
    const [step, setStep] = useState(0);
    const [playbookId, setPlaybookId] = useState<string>('');
    const form = useForm<{ playbook_id: number | null }>({ playbook_id: null });
    const chosen = d.available_playbooks.find(
        (p) => String(p.id) === playbookId,
    );

    const submit = () => {
        form.transform(() => ({ playbook_id: Number(playbookId) }));
        form.post(`/control-room/alerts/${d.alert.id}/playbook/start`, {
            preserveScroll: true,
            onSuccess: onPaneSuccess(onDone),
        });
    };

    return (
        <div className="flex flex-col gap-4">
            <StepHead
                icon={BookOpen}
                title="Start a playbook"
                blurb="Attach a step-by-step response procedure to this alert and work through it from the Playbook section."
            />
            <PaneError
                message={
                    serverError(form.errors) ??
                    (form.errors as Record<string, string | undefined>)
                        .playbook_id
                }
            />
            {step === 0 ? (
                <>
                    <Field label="Playbook" required>
                        <SelectInput
                            value={playbookId}
                            onChange={setPlaybookId}
                            placeholder="Select a playbook"
                            options={d.available_playbooks.map((p) => ({
                                value: String(p.id),
                                label: `${p.name} (${titleCase(p.category)})`,
                            }))}
                        />
                    </Field>
                    {chosen?.description ? (
                        <InfoCard icon={BookOpen} tone="info">
                            {chosen.description}
                        </InfoCard>
                    ) : null}
                    <PaneNav
                        onCancel={onDone}
                        onNext={() => setStep(1)}
                        nextDisabled={!playbookId}
                        step={0}
                        stepCount={2}
                    />
                </>
            ) : (
                <>
                    <ReviewCard icon={BookOpen} title="Review & start" span>
                        <ReviewRow label="Playbook" value={chosen?.name} />
                        <ReviewRow
                            label="Category"
                            value={
                                chosen ? titleCase(chosen.category) : undefined
                            }
                        />
                        <ReviewRow
                            label="Alert"
                            value={`${d.alert.reference_number ?? `Alert ${d.alert.id}`} · ${titleCase(d.alert.alert_type)}`}
                        />
                    </ReviewCard>
                    <PaneNav
                        onCancel={onDone}
                        onBack={() => setStep(0)}
                        onSubmit={submit}
                        submitLabel="Start playbook"
                        processing={form.processing}
                        step={1}
                        stepCount={2}
                    />
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
    const idx = Math.max(
        0,
        LIFECYCLE.findIndex((s) => s.key === a.status),
    );
    return (
        <div className="flex flex-wrap items-center gap-1.5">
            {LIFECYCLE.map((s, i) => {
                const isDone = i < idx;
                const isNow = i === idx;
                return (
                    <span key={s.key} className="flex items-center gap-1.5">
                        <span
                            className={`inline-flex items-center gap-1 rounded-full px-2.5 py-1 text-[11px] font-semibold ${
                                isNow
                                    ? 'bg-primary text-primary-foreground'
                                    : isDone
                                      ? 'bg-status-success-bg text-status-success'
                                      : 'bg-muted text-muted-foreground'
                            }`}
                        >
                            {isDone ? <Check className="h-3 w-3" /> : null}
                            {s.label}
                        </span>
                        {i < LIFECYCLE.length - 1 ? (
                            <span className="h-px w-3 bg-border" />
                        ) : null}
                    </span>
                );
            })}
        </div>
    );
}

function SnoozeBanner({ d }: { d: AlertWorkspaceDetail }) {
    const a = d.alert;
    const [busy, setBusy] = useState(false);
    const unsnooze = () => {
        setBusy(true);
        router.post(
            `/control-room/alerts/${a.id}/unsnooze`,
            {},
            { preserveScroll: true, onFinish: () => setBusy(false) },
        );
    };
    return (
        <div className="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-status-warning/30 bg-status-warning-bg px-4 py-3">
            <div className="flex items-start gap-2.5">
                <BellOff className="mt-0.5 h-4 w-4 shrink-0 text-status-warning" />
                <div>
                    <p className="text-sm font-medium text-foreground">
                        Snoozed until{' '}
                        {a.snoozed_until
                            ? formatDateTime(a.snoozed_until)
                            : '—'}
                    </p>
                    <p className="text-xs text-muted-foreground">
                        Off the worklist
                        {a.snoozed_by
                            ? ` · snoozed by ${a.snoozed_by.name}`
                            : ''}{' '}
                        — it returns automatically, or bring it back now.
                    </p>
                </div>
            </div>
            {d.can.manage ? (
                <Button
                    size="sm"
                    variant="outline"
                    onClick={unsnooze}
                    disabled={busy}
                >
                    <Bell className="mr-1.5 h-3.5 w-3.5" /> Unsnooze
                </Button>
            ) : null}
        </div>
    );
}

function OverviewSection({
    d,
    onEditMeta,
    onConfirmSensor,
    onCreateIncident,
}: {
    d: AlertWorkspaceDetail;
    onEditMeta: () => void;
    onConfirmSensor: () => void;
    onCreateIncident: () => void;
}) {
    const a = d.alert;
    const fleet = (a.fleet_context ?? null) as Record<string, any> | null;
    return (
        <div className="grid gap-4 sm:grid-cols-2">
            <div className="sm:col-span-2">
                <StatusFlow a={a} />
            </div>

            <div className="sm:col-span-2">
                <LinkedJourney
                    alertReference={a.reference_number ?? `Alert ${a.id}`}
                    alertStatus={a.status}
                    sensorConfirmationRequired={
                        a.source === 'sensor' &&
                        OPEN_STATES.includes(a.status) &&
                        !d.linked_incident
                    }
                    incident={
                        d.linked_incident
                            ? {
                                  referenceNumber:
                                      d.linked_incident.reference_number,
                                  href: d.linked_incident.href,
                              }
                            : null
                    }
                    healthSafety={
                        d.linked_hs_event
                            ? {
                                  referenceNumber:
                                      d.linked_hs_event.reference_number,
                                  handoverStatus:
                                      d.linked_hs_event.handover.status,
                                  href: d.linked_hs_event.href,
                              }
                            : null
                    }
                    can={{
                        manage: d.can.manage,
                        createIncident: d.can.create_incident,
                        viewIncident: d.can.view_incident,
                        viewHealthSafety: d.can.view_health_safety,
                    }}
                    onConfirmSensor={onConfirmSensor}
                    onCreateIncident={onCreateIncident}
                />
            </div>

            {a.is_snoozed && a.snoozed_until ? (
                <div className="sm:col-span-2">
                    <SnoozeBanner d={d} />
                </div>
            ) : null}

            <ReviewCard icon={FileText} title="What's happening" span>
                {(() => {
                    const summary = summarise(a);
                    return (
                        <>
                            <p className="text-sm whitespace-pre-wrap text-foreground">
                                {summary}
                            </p>
                            {a.notes && a.notes.trim() !== summary.trim() ? (
                                <p className="mt-2 border-t border-border pt-2 text-xs whitespace-pre-wrap text-muted-foreground">
                                    {a.notes}
                                </p>
                            ) : null}
                        </>
                    );
                })()}
            </ReviewCard>

            <ReviewCard icon={RadioTower} title="Alert">
                <ReviewRow label="Type" value={titleCase(a.alert_type)} />
                <ReviewRow label="Source" value={titleCase(a.source)} />
                <ReviewRow
                    label="Severity"
                    value={SEV_LABEL[a.severity] ?? titleCase(a.severity)}
                />
                <ReviewRow
                    label="Escalation"
                    value={
                        a.escalation_level > 0
                            ? `L${a.escalation_level}`
                            : 'None'
                    }
                />
                <ReviewRow
                    label="Triggered"
                    value={
                        a.triggered_at
                            ? formatDateTime(a.triggered_at)
                            : undefined
                    }
                />
            </ReviewCard>

            <ReviewCard icon={User} title="People & place">
                <ReviewRow label="Client" value={d.client?.name} />
                <ReviewRow
                    label="Asset"
                    value={
                        a.asset
                            ? `${a.asset.name} (${a.asset.asset_tag})`
                            : undefined
                    }
                />
                <ReviewRow
                    label="Assigned to"
                    value={a.assigned_to?.name ?? 'Unassigned'}
                />
                <ReviewRow
                    label="Location"
                    value={d.location?.description ?? undefined}
                />
                {d.location ? (
                    <div className="pt-1.5">
                        <a
                            href={`https://www.google.com/maps?q=${d.location.lat},${d.location.lng}`}
                            target="_blank"
                            rel="noreferrer"
                            className="inline-flex items-center gap-1 text-xs font-medium text-primary hover:underline"
                        >
                            <MapPin className="h-3.5 w-3.5" /> Open in Google
                            Maps
                        </a>
                    </div>
                ) : null}
            </ReviewCard>

            <ReviewCard
                icon={ClipboardList}
                title="Working details"
                onEdit={d.can.manage ? onEditMeta : undefined}
                span
            >
                <div className="grid gap-x-6 sm:grid-cols-2">
                    <ReviewRow
                        label="Priority"
                        value={a.priority ? titleCase(a.priority) : undefined}
                    />
                    <ReviewRow
                        label="Category"
                        value={a.category ? titleCase(a.category) : undefined}
                    />
                    <ReviewRow
                        label="Due"
                        value={a.due_at ? formatDateTime(a.due_at) : undefined}
                    />
                    <ReviewRow
                        label="Resolution code"
                        value={
                            a.resolution_code
                                ? titleCase(a.resolution_code)
                                : undefined
                        }
                    />
                </div>
            </ReviewCard>

            {fleet ? (
                <ReviewCard icon={Truck} title="Fleet context" span>
                    <ReviewRow
                        label="Vehicle"
                        value={
                            fleet.vehicle?.name
                                ? `${fleet.vehicle.name}${fleet.vehicle.registration ? ` · ${fleet.vehicle.registration}` : ''}`
                                : undefined
                        }
                    />
                    <ReviewRow label="Geofence" value={fleet.geofence?.name} />
                    <ReviewRow label="Outing" value={fleet.outing?.title} />
                    <ReviewRow
                        label="Residents aboard"
                        value={
                            fleet.affected_resident_count != null
                                ? String(fleet.affected_resident_count)
                                : undefined
                        }
                    />
                    <ReviewRow
                        label="Speed"
                        value={
                            fleet.location?.speed_kph != null
                                ? `${fleet.location.speed_kph} km/h`
                                : undefined
                        }
                    />
                </ReviewCard>
            ) : null}
        </div>
    );
}

function SlaCountdownRow({
    label,
    deadline,
    breached,
    doneAt,
}: {
    label: string;
    deadline: string | null;
    breached: boolean;
    doneAt: string | null;
}) {
    const met = Boolean(doneAt);
    let state: ReactNode;
    if (met) {
        state = (
            <span className="inline-flex items-center gap-1 text-xs font-semibold text-status-success">
                <Check className="h-3.5 w-3.5" /> met{' '}
                {doneAt ? formatDateTime(doneAt) : ''}
            </span>
        );
    } else if (breached) {
        state = (
            <span className="text-xs font-bold text-status-critical">
                BREACHED
            </span>
        );
    } else if (deadline) {
        const remainingMs = new Date(deadline).getTime() - Date.now();
        state =
            remainingMs <= 0 ? (
                <span className="text-xs font-bold text-status-critical">
                    BREACHED
                </span>
            ) : (
                <span className="text-xs font-semibold text-foreground">
                    due {formatDateTime(deadline)}
                </span>
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
    type TLEvent = {
        at: string;
        label: string;
        tone: string;
        icon: ComponentType<{ className?: string }>;
    };
    const events: TLEvent[] = [];
    if (a.triggered_at)
        events.push({
            at: a.triggered_at,
            label: 'Alert triggered',
            tone: 'critical',
            icon: RadioTower,
        });
    if (a.acknowledged_at)
        events.push({
            at: a.acknowledged_at,
            label: `Acknowledged${a.acknowledged_by ? ` · ${a.acknowledged_by.name}` : ''}`,
            tone: 'warning',
            icon: UserCheck,
        });
    if (a.assigned_at)
        events.push({
            at: a.assigned_at,
            label: `Assigned${a.assigned_to ? ` · ${a.assigned_to.name}` : ''}`,
            tone: 'info',
            icon: User,
        });
    if (a.escalated_at)
        events.push({
            at: a.escalated_at,
            label: `Escalated to L${a.escalation_level}${a.escalated_by ? ` · ${a.escalated_by.name}` : ''}`,
            tone: 'warning',
            icon: ArrowUpCircle,
        });
    if (a.resolved_at)
        events.push({
            at: a.resolved_at,
            label: `Resolved${a.resolved_by ? ` · ${a.resolved_by.name}` : ''}`,
            tone: 'success',
            icon: CheckCircle2,
        });
    if (a.closed_at)
        events.push({
            at: a.closed_at,
            label: `Closed${a.closed_by ? ` · ${a.closed_by.name}` : ''}`,
            tone: 'neutral',
            icon: CheckCircle2,
        });
    events.sort((x, y) => new Date(x.at).getTime() - new Date(y.at).getTime());

    return (
        <div className="flex flex-col gap-5">
            {d.sla ? (
                <div>
                    <p className="mb-2 text-sm font-semibold text-foreground">
                        SLA deadlines
                    </p>
                    <div className="grid gap-2 sm:grid-cols-3">
                        <SlaCountdownRow
                            label="Acknowledge"
                            deadline={d.sla.acknowledge_deadline}
                            breached={d.sla.acknowledge_breached}
                            doneAt={a.acknowledged_at}
                        />
                        <SlaCountdownRow
                            label="Respond"
                            deadline={d.sla.response_deadline}
                            breached={d.sla.response_breached}
                            doneAt={
                                a.status === 'triaging' || a.resolved_at
                                    ? (a.resolved_at ?? a.updated_at)
                                    : null
                            }
                        />
                        <SlaCountdownRow
                            label="Resolve"
                            deadline={d.sla.resolution_deadline}
                            breached={d.sla.resolution_breached}
                            doneAt={a.resolved_at}
                        />
                    </div>
                </div>
            ) : (
                <InfoCard icon={Timer} tone="info">
                    No SLA is attached to this alert.
                </InfoCard>
            )}

            <div>
                <p className="mb-2 text-sm font-semibold text-foreground">
                    Timeline
                </p>
                {events.length ? (
                    <ol className="relative ml-2 border-l border-border">
                        {events.map((e, i) => {
                            const Icon = e.icon;
                            return (
                                <li key={i} className="mb-5 ml-5">
                                    <span
                                        className={`absolute -left-[7px] flex h-3.5 w-3.5 items-center justify-center rounded-full ${DOT[e.tone] ?? DOT.neutral}`}
                                    />
                                    <div className="flex items-center gap-2">
                                        <Icon className="h-4 w-4 text-muted-foreground" />
                                        <span className="text-sm font-medium text-foreground">
                                            {e.label}
                                        </span>
                                    </div>
                                    <p className="mt-0.5 text-xs text-muted-foreground">
                                        {formatDateTime(e.at)}
                                    </p>
                                </li>
                            );
                        })}
                    </ol>
                ) : (
                    <p className="text-sm text-muted-foreground">
                        No timeline events yet.
                    </p>
                )}
            </div>

            <div>
                <p className="mb-2 text-sm font-semibold text-foreground">
                    Audit trail
                </p>
                {d.audit_logs.length ? (
                    <div className="flex flex-col gap-1.5">
                        {d.audit_logs.slice(0, 15).map((log) => (
                            <div
                                key={log.id}
                                className="flex items-baseline justify-between gap-3 rounded-md border border-border/60 px-2.5 py-1.5 text-xs"
                            >
                                <span className="min-w-0 truncate text-foreground">
                                    {titleCase(
                                        log.action
                                            .replace('controlRoom.', '')
                                            .replace('alert.', ''),
                                    )}
                                    {log.user ? (
                                        <span className="text-muted-foreground">
                                            {' '}
                                            · {log.user.name}
                                        </span>
                                    ) : null}
                                </span>
                                <span className="shrink-0 text-muted-foreground">
                                    {formatDateTime(log.created_at)}
                                </span>
                            </div>
                        ))}
                    </div>
                ) : (
                    <p className="text-sm text-muted-foreground">
                        No audit entries.
                    </p>
                )}
            </div>
        </div>
    );
}

function PlaybookSection({
    d,
    onStart,
}: {
    d: AlertWorkspaceDetail;
    onStart: () => void;
}) {
    const run = d.playbook_run;
    const alertId = d.alert.id;
    const advance = () =>
        router.post(
            `/control-room/alerts/${alertId}/playbook/advance`,
            {},
            { preserveScroll: true },
        );
    const skip = () =>
        router.post(
            `/control-room/alerts/${alertId}/playbook/skip`,
            {},
            { preserveScroll: true },
        );

    if (!run) {
        return (
            <div className="flex flex-col gap-4">
                <div className="rounded-xl border border-dashed border-border py-10 text-center">
                    <BookOpen className="mx-auto mb-2 h-8 w-8 text-muted-foreground/40" />
                    <p className="text-sm text-muted-foreground">
                        No playbook attached to this alert.
                    </p>
                    {d.can.manage &&
                    d.available_playbooks.length &&
                    OPEN_STATES.includes(d.alert.status) ? (
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
                    <p className="text-sm font-semibold text-foreground">
                        {run.playbook.name}
                    </p>
                    <p className="text-xs text-muted-foreground">
                        {titleCase(run.playbook.category)} ·{' '}
                        {run.completed_steps}/{run.total_steps} steps ·{' '}
                        {titleCase(run.status)}
                    </p>
                </div>
                <div className="h-1.5 w-28 overflow-hidden rounded-full bg-muted">
                    <div
                        className="h-full rounded-full bg-primary transition-[width]"
                        style={{
                            width: `${run.total_steps ? Math.round((run.completed_steps / run.total_steps) * 100) : 0}%`,
                        }}
                    />
                </div>
            </div>

            <ol className="flex flex-col gap-2">
                {run.steps.map((s, i) => {
                    const active = s.status === 'in_progress';
                    return (
                        <li
                            key={s.id}
                            className={`flex items-start gap-3 rounded-lg border p-3 ${active ? 'border-primary/50 bg-primary/5' : 'border-border'}`}
                        >
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
                                {s.status === 'completed' ? (
                                    <Check className="h-3.5 w-3.5" />
                                ) : s.status === 'skipped' ? (
                                    <SkipForward className="h-3 w-3" />
                                ) : (
                                    i + 1
                                )}
                            </span>
                            <div className="min-w-0 flex-1">
                                <p
                                    className={`text-sm ${active ? 'font-semibold text-foreground' : 'font-medium text-foreground'}`}
                                >
                                    {s.title}
                                </p>
                                {s.instructions ? (
                                    <p className="mt-0.5 text-xs text-muted-foreground">
                                        {s.instructions}
                                    </p>
                                ) : null}
                                <p className="text-xs text-muted-foreground">
                                    {titleCase(s.status)}
                                    {s.completed_at
                                        ? ` · ${formatDateTime(s.completed_at)}`
                                        : ''}
                                    {s.notes ? ` · ${s.notes}` : ''}
                                </p>
                            </div>
                            {active && d.can.manage ? (
                                <div className="flex shrink-0 items-center gap-1.5">
                                    <ConfirmChip
                                        label="Complete step"
                                        icon={Check}
                                        onConfirm={advance}
                                    />
                                    <ConfirmChip
                                        label="Skip"
                                        icon={SkipForward}
                                        onConfirm={skip}
                                    />
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
                    <CreatePackForm
                        alertId={d.alert.id}
                        onDone={() => setCreating(false)}
                    />
                ) : (
                    <Button
                        variant="outline"
                        size="sm"
                        className="self-start"
                        onClick={() => setCreating(true)}
                    >
                        <Plus className="mr-1.5 h-3.5 w-3.5" /> New evidence
                        pack
                    </Button>
                )
            ) : null}

            {d.evidence_packs.length ? (
                d.evidence_packs.map((pack) => (
                    <EvidencePackCard
                        key={pack.id}
                        pack={pack}
                        canManage={canManage}
                    />
                ))
            ) : (
                <div className="rounded-xl border border-dashed border-border py-10 text-center">
                    <Package className="mx-auto mb-2 h-8 w-8 text-muted-foreground/40" />
                    <p className="text-sm text-muted-foreground">
                        No evidence packs on this alert.
                    </p>
                    <p className="mt-1 text-xs text-muted-foreground/70">
                        Create a pack to collect files, notes and CCTV bookmarks
                        for the record.
                    </p>
                </div>
            )}
        </div>
    );
}

function CreatePackForm({
    alertId,
    onDone,
}: {
    alertId: number;
    onDone: () => void;
}) {
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
        <form
            onSubmit={submit}
            className="flex flex-col gap-3 rounded-xl border border-border bg-muted/30 p-3"
        >
            <Field label="Pack title" required error={form.errors.title}>
                <Input
                    value={form.data.title}
                    onChange={(e) => form.setData('title', e.target.value)}
                    placeholder="e.g. Fall in the lounge — 14 Jun evidence"
                />
            </Field>
            <div className="flex justify-end gap-2">
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    onClick={onDone}
                >
                    Cancel
                </Button>
                <Button
                    type="submit"
                    size="sm"
                    disabled={form.processing || !form.data.title.trim()}
                >
                    Create pack
                </Button>
            </div>
        </form>
    );
}

const EVIDENCE_TYPE_LABELS: Record<string, string> = {
    note: 'Note',
    photo: 'Photo',
    document: 'Document',
    cctv_bookmark: 'CCTV bookmark',
};

function EvidencePackCard({
    pack,
    canManage,
}: {
    pack: AlertWorkspaceDetail['evidence_packs'][number];
    canManage: boolean;
}) {
    const [adding, setAdding] = useState<'file' | 'note' | 'cctv' | null>(null);
    const [completing, setCompleting] = useState(false);
    const fileInput = useRef<HTMLInputElement | null>(null);
    const collecting = pack.status === 'collecting';

    const uploadFile = (file: File) => {
        router.post(
            `/control-room/evidence/${pack.id}/items`,
            { item_type: 'file', file },
            {
                preserveScroll: true,
                forceFormData: true,
                onSuccess: () => setAdding(null),
            },
        );
    };

    const removeItem = (itemId: number) => {
        router.delete(`/control-room/evidence/items/${itemId}`, {
            preserveScroll: true,
        });
    };

    return (
        <div className="rounded-xl border border-border">
            <div className="flex items-center justify-between gap-3 border-b border-border bg-muted/30 px-3 py-2.5">
                <div className="min-w-0">
                    <p className="truncate text-sm font-semibold text-foreground">
                        {pack.title}
                    </p>
                    <p className="text-xs text-muted-foreground">
                        {titleCase(pack.status)} · {pack.items.length} item
                        {pack.items.length === 1 ? '' : 's'}
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
                        <Button
                            variant="outline"
                            size="sm"
                            onClick={() => setCompleting(true)}
                        >
                            <Check className="mr-1 h-3.5 w-3.5" /> Complete pack
                        </Button>
                    ) : null}
                </div>
            </div>

            {completing ? (
                <CompletePackReview
                    pack={pack}
                    onCancel={() => setCompleting(false)}
                />
            ) : (
                <div className="flex flex-col gap-2 p-3">
                    {pack.items.length ? (
                        pack.items.map((item) => (
                            <div
                                key={item.id}
                                className="flex items-start gap-2.5 rounded-lg border border-border/70 px-2.5 py-2"
                            >
                                {item.type === 'note' ? (
                                    <FileText className="mt-0.5 h-4 w-4 shrink-0 text-muted-foreground" />
                                ) : item.type === 'cctv_bookmark' ? (
                                    <Eye className="mt-0.5 h-4 w-4 shrink-0 text-muted-foreground" />
                                ) : (
                                    <Paperclip className="mt-0.5 h-4 w-4 shrink-0 text-muted-foreground" />
                                )}
                                <div className="min-w-0 flex-1">
                                    {item.download_url ? (
                                        <a
                                            href={item.download_url}
                                            className="block truncate text-sm font-medium text-primary hover:underline"
                                            title="Download this file"
                                        >
                                            {item.title}
                                        </a>
                                    ) : (
                                        <p className="truncate text-sm text-foreground">
                                            {item.title}
                                        </p>
                                    )}
                                    {item.description ? (
                                        <p className="mt-0.5 text-xs whitespace-pre-wrap text-foreground/80">
                                            {item.description}
                                        </p>
                                    ) : null}
                                    <p className="text-xs text-muted-foreground">
                                        {EVIDENCE_TYPE_LABELS[item.type] ??
                                            titleCase(item.type)}
                                        {item.created_at
                                            ? ` · ${formatDateTime(item.created_at)}`
                                            : ''}
                                    </p>
                                </div>
                                {canManage && collecting ? (
                                    <ConfirmChip
                                        label="Remove"
                                        icon={Trash2}
                                        destructive
                                        onConfirm={() => removeItem(item.id)}
                                    />
                                ) : null}
                            </div>
                        ))
                    ) : (
                        <p className="py-2 text-center text-xs text-muted-foreground">
                            Empty pack — add files, notes or CCTV bookmarks.
                        </p>
                    )}

                    {canManage && collecting ? (
                        <div className="mt-1 border-t border-border pt-2.5">
                            {adding === null ? (
                                <div className="flex flex-wrap gap-2">
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        onClick={() =>
                                            fileInput.current?.click()
                                        }
                                    >
                                        <Paperclip className="mr-1.5 h-3.5 w-3.5" />{' '}
                                        Upload file
                                    </Button>
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        onClick={() => setAdding('note')}
                                    >
                                        <FileText className="mr-1.5 h-3.5 w-3.5" />{' '}
                                        Add note
                                    </Button>
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        onClick={() => setAdding('cctv')}
                                    >
                                        <Eye className="mr-1.5 h-3.5 w-3.5" />{' '}
                                        CCTV bookmark
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
                                <EvidenceNoteForm
                                    packId={pack.id}
                                    onDone={() => setAdding(null)}
                                />
                            ) : (
                                <EvidenceCctvForm
                                    packId={pack.id}
                                    onDone={() => setAdding(null)}
                                />
                            )}
                        </div>
                    ) : null}
                </div>
            )}
        </div>
    );
}

function CompletePackReview({
    pack,
    onCancel,
}: {
    pack: AlertWorkspaceDetail['evidence_packs'][number];
    onCancel: () => void;
}) {
    const form = useForm({});
    const submit = () => {
        form.post(`/control-room/evidence/${pack.id}/complete`, {
            preserveScroll: true,
            onSuccess: onPaneSuccess(onCancel),
        });
    };
    return (
        <div className="flex flex-col gap-3 p-3">
            <InfoCard icon={Package} tone="warn">
                Completing locks the pack — no more items can be added or
                removed. Check everything is here first.
            </InfoCard>
            <ReviewCard
                icon={Package}
                title={`${pack.title} — ${pack.items.length} item${pack.items.length === 1 ? '' : 's'}`}
                span
            >
                {pack.items.length ? (
                    pack.items.map((i) => (
                        <ReviewRow
                            key={i.id}
                            label={titleCase(i.type)}
                            value={i.title}
                        />
                    ))
                ) : (
                    <p className="text-sm text-status-critical">
                        This pack is empty — completing an empty pack is rarely
                        intended.
                    </p>
                )}
            </ReviewCard>
            <PaneNav
                onCancel={onCancel}
                onSubmit={submit}
                submitLabel="Complete pack"
                processing={form.processing}
            />
        </div>
    );
}

function EvidenceNoteForm({
    packId,
    onDone,
}: {
    packId: number;
    onDone: () => void;
}) {
    const form = useForm<{ item_type: string; content: string }>({
        item_type: 'note',
        content: '',
    });
    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post(`/control-room/evidence/${packId}/items`, {
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                onDone();
            },
        });
    };
    return (
        <form onSubmit={submit} className="flex flex-col gap-2.5">
            <Field label="Note" required error={form.errors.content}>
                <Textarea
                    rows={2}
                    value={form.data.content}
                    onChange={(e) => form.setData('content', e.target.value)}
                    placeholder="What did you observe?"
                />
            </Field>
            <div className="flex justify-end gap-2">
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    onClick={onDone}
                >
                    Cancel
                </Button>
                <Button
                    type="submit"
                    size="sm"
                    disabled={form.processing || !form.data.content.trim()}
                >
                    Add note
                </Button>
            </div>
        </form>
    );
}

function EvidenceCctvForm({
    packId,
    onDone,
}: {
    packId: number;
    onDone: () => void;
}) {
    const form = useForm<{
        item_type: string;
        camera_id: string;
        timestamp: string;
    }>({ item_type: 'cctv_bookmark', camera_id: '', timestamp: '' });
    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post(`/control-room/evidence/${packId}/items`, {
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                onDone();
            },
        });
    };
    return (
        <form onSubmit={submit} className="flex flex-col gap-2.5">
            <div className="grid gap-2.5 sm:grid-cols-2">
                <Field label="Camera" required error={form.errors.camera_id}>
                    <Input
                        value={form.data.camera_id}
                        onChange={(e) =>
                            form.setData('camera_id', e.target.value)
                        }
                        placeholder="e.g. CAM-LOUNGE-2"
                    />
                </Field>
                <Field label="Timestamp" required error={form.errors.timestamp}>
                    <Input
                        type="datetime-local"
                        value={form.data.timestamp}
                        onChange={(e) =>
                            form.setData('timestamp', e.target.value)
                        }
                    />
                </Field>
            </div>
            <div className="flex justify-end gap-2">
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    onClick={onDone}
                >
                    Cancel
                </Button>
                <Button
                    type="submit"
                    size="sm"
                    disabled={
                        form.processing ||
                        !form.data.camera_id.trim() ||
                        !form.data.timestamp
                    }
                >
                    Add bookmark
                </Button>
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
    // Optimistic order while a reorder POST is in flight; cleared once the
    // reloaded `detail` prop reflects the saved sort_order.
    const [localOrder, setLocalOrder] = useState<number[] | null>(null);

    const sensors = useSensors(
        useSensor(PointerSensor, { activationConstraint: { distance: 4 } }),
        useSensor(KeyboardSensor, {
            coordinateGetter: sortableKeyboardCoordinates,
        }),
    );

    const baseIds = d.tasks.map((t) => t.id);
    const orderedIds = localOrder ?? baseIds;
    const orderedTasks = orderedIds
        .map((id) => d.tasks.find((t) => t.id === id))
        .filter((t): t is AlertWorkspaceDetail['tasks'][number] => Boolean(t));
    const canReorder = d.can.manage && d.tasks.length > 1;

    const handleDragEnd = (e: DragEndEvent) => {
        const { active, over } = e;
        if (!over || active.id === over.id) return;
        const oldIndex = orderedIds.indexOf(Number(active.id));
        const newIndex = orderedIds.indexOf(Number(over.id));
        if (oldIndex < 0 || newIndex < 0) return;
        const next = arrayMove(orderedIds, oldIndex, newIndex);
        setLocalOrder(next);
        router.post(
            `/control-room/alerts/${d.alert.id}/tasks/reorder`,
            { task_ids: next },
            {
                preserveScroll: true,
                preserveState: true,
                onSuccess: () => setLocalOrder(null),
                onError: () => setLocalOrder(null),
            },
        );
    };

    return (
        <div className="flex flex-col gap-3">
            {d.can.manage ? (
                adding ? (
                    <AddTaskForm d={d} onDone={() => setAdding(false)} />
                ) : (
                    <Button
                        variant="outline"
                        size="sm"
                        className="self-start"
                        onClick={() => setAdding(true)}
                    >
                        <Plus className="mr-1.5 h-3.5 w-3.5" /> Add task
                    </Button>
                )
            ) : null}

            {canReorder ? (
                <p className="text-xs text-muted-foreground">
                    Drag the handle to reorder how tasks are worked.
                </p>
            ) : null}

            {d.tasks.length ? (
                canReorder ? (
                    <DndContext
                        sensors={sensors}
                        collisionDetection={closestCenter}
                        onDragEnd={handleDragEnd}
                    >
                        <SortableContext
                            items={orderedIds}
                            strategy={verticalListSortingStrategy}
                        >
                            <div className="flex flex-col gap-2">
                                {orderedTasks.map((t) => (
                                    <SortableTaskRow key={t.id} d={d} t={t} />
                                ))}
                            </div>
                        </SortableContext>
                    </DndContext>
                ) : (
                    <div className="flex flex-col gap-2">
                        {orderedTasks.map((t) => (
                            <TaskRow key={t.id} d={d} t={t} />
                        ))}
                    </div>
                )
            ) : (
                <div className="rounded-xl border border-dashed border-border py-10 text-center">
                    <ListTodo className="mx-auto mb-2 h-8 w-8 text-muted-foreground/40" />
                    <p className="text-sm text-muted-foreground">
                        No tasks on this alert.
                    </p>
                </div>
            )}
        </div>
    );
}

function SortableTaskRow({
    d,
    t,
}: {
    d: AlertWorkspaceDetail;
    t: AlertWorkspaceDetail['tasks'][number];
}) {
    const {
        attributes,
        listeners,
        setNodeRef,
        transform,
        transition,
        isDragging,
    } = useSortable({ id: t.id });
    const style = {
        transform: CSS.Transform.toString(transform),
        transition,
        opacity: isDragging ? 0.6 : 1,
    };
    const handle = (
        <button
            type="button"
            className="mt-0.5 shrink-0 cursor-grab text-muted-foreground/50 hover:text-muted-foreground active:cursor-grabbing"
            aria-label="Drag to reorder"
            {...attributes}
            {...listeners}
        >
            <GripVertical className="h-4 w-4" />
        </button>
    );
    return (
        <div ref={setNodeRef} style={style}>
            <TaskRow d={d} t={t} dragHandle={handle} />
        </div>
    );
}

function TaskRow({
    d,
    t,
    dragHandle,
}: {
    d: AlertWorkspaceDetail;
    t: AlertWorkspaceDetail['tasks'][number];
    dragHandle?: ReactNode;
}) {
    const [editing, setEditing] = useState(false);
    const [addingSub, setAddingSub] = useState(false);
    const live = isOperationalTaskOpen(t.status);

    return (
        <div className="rounded-lg border border-border p-3">
            <div className="flex items-start gap-2">
                {dragHandle ?? null}
                <ListTodo
                    className={`mt-0.5 h-4 w-4 shrink-0 ${TASK_STATUS_TONE[t.status] ?? 'text-muted-foreground'}`}
                />
                <div className="min-w-0 flex-1">
                    <p
                        className={`text-sm text-foreground ${t.status === 'completed' ? 'line-through opacity-70' : ''}`}
                    >
                        {t.title}
                    </p>
                    {t.description ? (
                        <p className="text-xs whitespace-pre-wrap text-muted-foreground">
                            {t.description}
                        </p>
                    ) : null}
                    <p className="text-xs text-muted-foreground">
                        {titleCase(t.status)} · {titleCase(t.priority)}
                        {t.assigned_to
                            ? ` · ${t.assigned_to.name}`
                            : ' · unassigned'}
                        {t.due_at ? ` · due ${formatDateTime(t.due_at)}` : ''}
                    </p>
                </div>
                {d.can.manage && live ? (
                    <div className="flex shrink-0 items-center gap-1.5">
                        <Button
                            variant="ghost"
                            size="sm"
                            onClick={() => setEditing((v) => !v)}
                            title="Edit task"
                        >
                            <Pencil className="h-3.5 w-3.5" />
                        </Button>
                        <ConfirmChip
                            label="Done"
                            icon={Check}
                            onConfirm={() =>
                                router.post(
                                    `/control-room/tasks/${t.id}/status`,
                                    { status: 'completed' },
                                    { preserveScroll: true },
                                )
                            }
                        />
                        <ConfirmChip
                            label="Remove"
                            icon={Trash2}
                            destructive
                            onConfirm={() =>
                                router.delete(`/control-room/tasks/${t.id}`, {
                                    preserveScroll: true,
                                })
                            }
                        />
                    </div>
                ) : null}
            </div>

            {editing ? (
                <EditTaskForm d={d} t={t} onDone={() => setEditing(false)} />
            ) : null}

            {/* Subtasks */}
            {t.subtasks.length || (d.can.manage && live) ? (
                <div className="mt-2 flex flex-col gap-1.5 border-l-2 border-border/60 pl-3">
                    {t.subtasks.map((st) => (
                        <div
                            key={st.id}
                            className="flex items-center gap-2 text-xs"
                        >
                            <Check
                                className={`h-3 w-3 shrink-0 ${st.status === 'completed' ? 'text-status-success' : 'text-muted-foreground/40'}`}
                            />
                            <span
                                className={`min-w-0 flex-1 truncate ${st.status === 'completed' ? 'text-muted-foreground line-through' : 'text-foreground'}`}
                            >
                                {st.title}
                                {st.assigned_to
                                    ? ` · ${st.assigned_to.name}`
                                    : ''}
                            </span>
                            {d.can.manage &&
                            st.status !== 'completed' &&
                            st.status !== 'cancelled' ? (
                                <ConfirmChip
                                    label="Done"
                                    icon={Check}
                                    onConfirm={() =>
                                        router.post(
                                            `/control-room/tasks/${st.id}/status`,
                                            { status: 'completed' },
                                            { preserveScroll: true },
                                        )
                                    }
                                />
                            ) : null}
                        </div>
                    ))}
                    {d.can.manage && live ? (
                        addingSub ? (
                            <AddSubtaskForm
                                alertId={d.alert.id}
                                parentId={t.id}
                                onDone={() => setAddingSub(false)}
                            />
                        ) : (
                            <Button
                                unstyled
                                type="button"
                                onClick={() => setAddingSub(true)}
                                className="self-start text-xs font-medium text-primary hover:underline"
                            >
                                + Add subtask
                            </Button>
                        )
                    ) : null}
                </div>
            ) : null}
        </div>
    );
}

function EditTaskForm({
    d,
    t,
    onDone,
}: {
    d: AlertWorkspaceDetail;
    t: AlertWorkspaceDetail['tasks'][number];
    onDone: () => void;
}) {
    const form = useForm<{
        title: string;
        description: string;
        priority: string;
        assigned_to_user_id: string;
        due_at: string;
    }>({
        title: t.title,
        description: t.description ?? '',
        priority: t.priority,
        assigned_to_user_id: t.assigned_to ? String(t.assigned_to.id) : '',
        due_at: t.due_at ? t.due_at.slice(0, 10) : '',
    });
    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.transform((data) => ({
            title: data.title,
            description: data.description || null,
            priority: data.priority,
            assigned_to_user_id: data.assigned_to_user_id
                ? Number(data.assigned_to_user_id)
                : null,
            due_at: data.due_at || null,
        }));
        form.put(`/control-room/tasks/${t.id}`, {
            preserveScroll: true,
            onSuccess: onDone,
        });
    };
    return (
        <form
            onSubmit={submit}
            className="mt-2 flex flex-col gap-2.5 rounded-xl border border-border bg-muted/30 p-3"
        >
            <Field label="Task" required>
                <Input
                    value={form.data.title}
                    onChange={(e) => form.setData('title', e.target.value)}
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
            <div className="grid gap-2.5 sm:grid-cols-3">
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
                        options={d.staff.map((s) => ({
                            value: String(s.id),
                            label: s.name,
                        }))}
                    />
                </Field>
                <Field label="Due">
                    <Input
                        type="date"
                        value={form.data.due_at}
                        onChange={(e) => form.setData('due_at', e.target.value)}
                    />
                </Field>
            </div>
            <div className="flex justify-end gap-2">
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    onClick={onDone}
                >
                    Cancel
                </Button>
                <Button
                    type="submit"
                    size="sm"
                    disabled={form.processing || !form.data.title.trim()}
                >
                    Save task
                </Button>
            </div>
        </form>
    );
}

function AddSubtaskForm({
    alertId,
    parentId,
    onDone,
}: {
    alertId: number;
    parentId: number;
    onDone: () => void;
}) {
    const form = useForm<{
        title: string;
        priority: string;
        parent_task_id: number;
    }>({ title: '', priority: 'medium', parent_task_id: parentId });
    const submit = (e: FormEvent) => {
        e.preventDefault();
        if (!form.data.title.trim()) return;
        form.post(`/control-room/alerts/${alertId}/tasks`, {
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                onDone();
            },
        });
    };
    return (
        <form onSubmit={submit} className="flex items-center gap-2">
            <Input
                className="h-8 flex-1 text-xs"
                value={form.data.title}
                onChange={(e) => form.setData('title', e.target.value)}
                placeholder="Subtask…"
            />
            <Button
                type="submit"
                size="sm"
                disabled={form.processing || !form.data.title.trim()}
            >
                Add
            </Button>
            <Button
                type="button"
                variant="ghost"
                size="sm"
                onClick={onDone}
                aria-label="Cancel subtask"
            >
                <X className="h-3.5 w-3.5" />
            </Button>
        </form>
    );
}

function AddTaskForm({
    d,
    onDone,
}: {
    d: AlertWorkspaceDetail;
    onDone: () => void;
}) {
    const form = useForm<{
        title: string;
        description: string;
        priority: string;
        assigned_to_user_id: string;
        due_at: string;
    }>({
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
            assigned_to_user_id: data.assigned_to_user_id
                ? Number(data.assigned_to_user_id)
                : null,
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
        <form
            onSubmit={submit}
            className="flex flex-col gap-3 rounded-xl border border-border bg-muted/30 p-3"
        >
            <Field label="Task" required error={form.errors.title}>
                <Input
                    value={form.data.title}
                    onChange={(e) => form.setData('title', e.target.value)}
                    placeholder="e.g. Call the family before 5pm"
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
                <Field label="Assign to">
                    <SelectInput
                        value={form.data.assigned_to_user_id}
                        onChange={(v) => form.setData('assigned_to_user_id', v)}
                        placeholder="Unassigned"
                        options={d.staff.map((s) => ({
                            value: String(s.id),
                            label: s.name,
                        }))}
                    />
                </Field>
                <Field label="Due">
                    <Input
                        type="date"
                        value={form.data.due_at}
                        onChange={(e) => form.setData('due_at', e.target.value)}
                    />
                </Field>
            </div>
            <div className="flex justify-end gap-2">
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    onClick={onDone}
                >
                    Cancel
                </Button>
                <Button
                    type="submit"
                    size="sm"
                    disabled={form.processing || !form.data.title.trim()}
                >
                    Add task
                </Button>
            </div>
        </form>
    );
}

/* --- Activity (notes, discussion, communications) -------------------- */

function ActivitySection({ d }: { d: AlertWorkspaceDetail }) {
    const ctx = (d.alert.context ?? {}) as Record<string, any>;
    const notes = Array.isArray(ctx.activity_log)
        ? (ctx.activity_log as Array<{
              type?: string;
              content?: string;
              user_name?: string;
              created_at?: string;
          }>)
        : [];
    return (
        <div className="flex flex-col gap-5">
            <div>
                <p className="mb-2 text-sm font-semibold text-foreground">
                    Operator notes
                </p>
                {d.can.manage ? <AddNoteForm alertId={d.alert.id} /> : null}
                {notes.length ? (
                    <div className="mt-2 flex flex-col gap-2">
                        {[...notes].reverse().map((n, i) => (
                            <div
                                key={i}
                                className="rounded-lg border border-border p-2.5"
                            >
                                <p className="text-sm whitespace-pre-wrap text-foreground">
                                    {n.content}
                                </p>
                                <p className="mt-1 text-xs text-muted-foreground">
                                    {n.user_name ?? 'System'}
                                    {n.created_at
                                        ? ` · ${formatDateTime(n.created_at)}`
                                        : ''}
                                </p>
                            </div>
                        ))}
                    </div>
                ) : (
                    <p className="mt-2 text-xs text-muted-foreground">
                        No notes yet.
                    </p>
                )}
            </div>

            <div>
                <p className="mb-2 text-sm font-semibold text-foreground">
                    Discussion
                </p>
                {d.can.manage ? (
                    <DiscussionComposer alertId={d.alert.id} />
                ) : null}
                {d.discussions.length ? (
                    <div className="mt-2 flex flex-col gap-2">
                        {d.discussions.map((disc) => (
                            <DiscussionThread
                                key={disc.id}
                                d={d}
                                thread={disc}
                            />
                        ))}
                    </div>
                ) : (
                    <p className="mt-2 text-xs text-muted-foreground">
                        No discussion yet.
                    </p>
                )}
            </div>

            <div>
                <p className="mb-2 text-sm font-semibold text-foreground">
                    Communications
                </p>
                {d.communications.length ? (
                    <div className="flex flex-col gap-2">
                        {d.communications.map((c) => (
                            <div
                                key={c.id}
                                className="flex items-start gap-2.5 rounded-lg border border-border p-2.5"
                            >
                                <Send className="mt-0.5 h-3.5 w-3.5 shrink-0 text-muted-foreground" />
                                <div className="min-w-0 flex-1">
                                    <p className="text-sm text-foreground">
                                        {c.content ??
                                            titleCase(c.purpose ?? c.channel)}
                                    </p>
                                    <p className="text-xs text-muted-foreground">
                                        {titleCase(c.channel)} · {c.direction}
                                        {c.target_user_name
                                            ? ` · ${c.target_user_name}`
                                            : ''}
                                        {c.sent_at
                                            ? ` · ${formatDateTime(c.sent_at)}`
                                            : ''}
                                    </p>
                                </div>
                            </div>
                        ))}
                    </div>
                ) : (
                    <p className="text-xs text-muted-foreground">
                        No communications logged.
                    </p>
                )}
            </div>

            <TimeTracking d={d} />
        </div>
    );
}

/* --- Time tracking ---------------------------------------------------- */

function TimeTracking({ d }: { d: AlertWorkspaceDetail }) {
    const authUserId = (
        usePage().props as { auth?: { user?: { id?: number } } }
    ).auth?.user?.id;
    const [logging, setLogging] = useState(false);
    const myRunning = d.time_entries.find(
        (t) => t.is_running && t.user_id === authUserId,
    );
    const alertId = d.alert.id;

    if (!d.can.manage) {
        return d.time_spent_minutes ? (
            <p className="text-xs text-muted-foreground">
                {d.time_spent_minutes} min logged on this alert.
            </p>
        ) : null;
    }

    return (
        <div>
            <div className="mb-2 flex flex-wrap items-center justify-between gap-2">
                <p className="text-sm font-semibold text-foreground">
                    Time on this alert
                    <span className="ml-2 font-normal text-muted-foreground">
                        {d.time_spent_minutes} min logged
                    </span>
                </p>
                <div className="flex items-center gap-1.5">
                    {myRunning ? (
                        <ConfirmChip
                            label="Stop timer"
                            icon={Timer}
                            onConfirm={() =>
                                router.post(
                                    `/control-room/time-entries/${myRunning.id}/stop`,
                                    {},
                                    { preserveScroll: true },
                                )
                            }
                            title={`Running since ${myRunning.started_at ? formatDateTime(myRunning.started_at) : '—'}`}
                        />
                    ) : (
                        <ConfirmChip
                            label="Start timer"
                            icon={Timer}
                            onConfirm={() =>
                                router.post(
                                    `/control-room/alerts/${alertId}/time-entries/start`,
                                    {},
                                    { preserveScroll: true },
                                )
                            }
                            title="Starts a live timer for you on this alert"
                        />
                    )}
                    <Button
                        variant="outline"
                        size="sm"
                        onClick={() => setLogging((v) => !v)}
                    >
                        <Plus className="mr-1.5 h-3.5 w-3.5" /> Log time
                    </Button>
                </div>
            </div>

            {myRunning ? (
                <p className="mb-2 flex items-center gap-1.5 text-xs font-medium text-primary">
                    <Timer className="h-3.5 w-3.5" /> Your timer is running
                    (started{' '}
                    {myRunning.started_at
                        ? formatDateTime(myRunning.started_at)
                        : '—'}
                    ).
                </p>
            ) : null}

            {logging ? (
                <LogTimeForm
                    alertId={alertId}
                    onDone={() => setLogging(false)}
                />
            ) : null}

            {d.time_entries.length ? (
                <div className="mt-2 flex flex-col gap-1.5">
                    {d.time_entries.map((t) => (
                        <div
                            key={t.id}
                            className="flex items-center gap-2.5 rounded-md border border-border/60 px-2.5 py-1.5 text-xs"
                        >
                            <Timer
                                className={`h-3.5 w-3.5 shrink-0 ${t.is_running ? 'text-primary' : 'text-muted-foreground'}`}
                            />
                            <span className="min-w-0 flex-1 truncate text-foreground">
                                {t.is_running
                                    ? 'Running'
                                    : `${t.duration_minutes ?? 0} min`}{' '}
                                · {t.user_name}
                                {t.description ? ` — ${t.description}` : ''}
                            </span>
                            <span className="shrink-0 text-muted-foreground">
                                {formatDateTime(t.created_at)}
                            </span>
                            {!t.is_running ? (
                                <ConfirmChip
                                    label="Remove"
                                    icon={Trash2}
                                    destructive
                                    onConfirm={() =>
                                        router.delete(
                                            `/control-room/time-entries/${t.id}`,
                                            { preserveScroll: true },
                                        )
                                    }
                                />
                            ) : null}
                        </div>
                    ))}
                </div>
            ) : (
                <p className="text-xs text-muted-foreground">
                    No time logged yet — start the timer while you work, or log
                    minutes afterwards.
                </p>
            )}
        </div>
    );
}

function LogTimeForm({
    alertId,
    onDone,
}: {
    alertId: number;
    onDone: () => void;
}) {
    const form = useForm<{ duration_minutes: string; description: string }>({
        duration_minutes: '',
        description: '',
    });
    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.transform((data) => ({
            duration_minutes: Number(data.duration_minutes),
            description: data.description || null,
        }));
        form.post(`/control-room/alerts/${alertId}/time-entries`, {
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                onDone();
            },
        });
    };
    return (
        <form
            onSubmit={submit}
            className="mb-2 flex flex-col gap-2.5 rounded-xl border border-border bg-muted/30 p-3"
        >
            <div className="grid gap-2.5 sm:grid-cols-2">
                <Field
                    label="Minutes"
                    required
                    error={
                        (form.errors as Record<string, string | undefined>)
                            .duration_minutes
                    }
                >
                    <Input
                        type="number"
                        min={1}
                        value={form.data.duration_minutes}
                        onChange={(e) =>
                            form.setData('duration_minutes', e.target.value)
                        }
                        placeholder="e.g. 15"
                    />
                </Field>
                <Field label="What was it spent on?" hint="Optional">
                    <Input
                        value={form.data.description}
                        onChange={(e) =>
                            form.setData('description', e.target.value)
                        }
                        placeholder="e.g. Phone call with the family"
                    />
                </Field>
            </div>
            <div className="flex justify-end gap-2">
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    onClick={onDone}
                >
                    Cancel
                </Button>
                <Button
                    type="submit"
                    size="sm"
                    disabled={
                        form.processing ||
                        !form.data.duration_minutes ||
                        Number(form.data.duration_minutes) < 1
                    }
                >
                    Log time
                </Button>
            </div>
        </form>
    );
}

export function AddNoteForm({ alertId }: { alertId: number }) {
    const form = useForm<{ note: string; purpose: string }>({
        note: '',
        purpose: 'general',
    });
    const submit = (e: FormEvent) => {
        e.preventDefault();
        if (!form.data.note.trim()) return;
        form.post(`/control-room/alerts/${alertId}/note`, {
            preserveScroll: true,
            onSuccess: () => form.reset(),
        });
    };
    return (
        <form
            onSubmit={submit}
            className="grid gap-2 sm:grid-cols-[13rem_1fr_auto] sm:items-start"
        >
            <Field label="Note purpose">
                <SelectInput
                    value={form.data.purpose}
                    onChange={(value) => form.setData('purpose', value)}
                    placeholder="Select note purpose"
                    ariaLabel="Note purpose"
                    options={[
                        { value: 'general', label: 'General update' },
                        {
                            value: 'immediate_controls',
                            label: 'Immediate controls',
                        },
                        {
                            value: 'escalation_handover',
                            label: 'Escalation or handover',
                        },
                    ]}
                />
            </Field>
            <Field label="Operator note">
                <Textarea
                    rows={2}
                    className="flex-1"
                    value={form.data.note}
                    onChange={(e) => form.setData('note', e.target.value)}
                    placeholder="Add an operator note…"
                />
            </Field>
            <Button
                type="submit"
                size="sm"
                className="sm:mt-7"
                disabled={form.processing || !form.data.note.trim()}
            >
                <Send className="mr-1.5 h-3.5 w-3.5" /> Add note
            </Button>
        </form>
    );
}

function DiscussionComposer({
    alertId,
    parentId,
    onDone,
}: {
    alertId: number;
    parentId?: number;
    onDone?: () => void;
}) {
    const form = useForm<{ content: string; parent_id: number | null }>({
        content: '',
        parent_id: parentId ?? null,
    });
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
            <Textarea
                rows={parentId ? 1 : 2}
                className="flex-1"
                value={form.data.content}
                onChange={(e) => form.setData('content', e.target.value)}
                placeholder={parentId ? 'Reply…' : 'Start a discussion…'}
            />
            <Button
                type="submit"
                size="sm"
                variant={parentId ? 'outline' : 'default'}
                disabled={form.processing || !form.data.content.trim()}
            >
                {parentId ? 'Reply' : 'Post'}
            </Button>
        </form>
    );
}

function DiscussionThread({
    d,
    thread,
}: {
    d: AlertWorkspaceDetail;
    thread: AlertWorkspaceDetail['discussions'][number];
}) {
    const [replying, setReplying] = useState(false);
    return (
        <div className="rounded-lg border border-border p-2.5">
            <DiscussionEntry entry={thread} canManage={d.can.manage} />
            {thread.replies.length ? (
                <div className="mt-2 flex flex-col gap-1.5 border-l-2 border-border pl-3">
                    {thread.replies.map((r) => (
                        <DiscussionEntry
                            key={r.id}
                            entry={r}
                            canManage={d.can.manage}
                        />
                    ))}
                </div>
            ) : null}
            {d.can.manage ? (
                replying ? (
                    <div className="mt-2">
                        <DiscussionComposer
                            alertId={d.alert.id}
                            parentId={thread.id}
                            onDone={() => setReplying(false)}
                        />
                    </div>
                ) : (
                    <Button
                        unstyled
                        type="button"
                        onClick={() => setReplying(true)}
                        className="mt-1.5 text-xs font-medium text-primary hover:underline"
                    >
                        Reply
                    </Button>
                )
            ) : null}
        </div>
    );
}

/** One comment or reply — the author can edit it in place; author or a manager can delete. */
function DiscussionEntry({
    entry,
    canManage,
}: {
    entry: {
        id: number;
        content: string;
        user: UserRef;
        edited_at: string | null;
        created_at: string;
    };
    canManage: boolean;
}) {
    const authUserId = (
        usePage().props as { auth?: { user?: { id?: number } } }
    ).auth?.user?.id;
    const isOwner = entry.user.id === authUserId;
    const deleted = entry.content === '[deleted]';
    const [editing, setEditing] = useState(false);
    const form = useForm<{ content: string }>({ content: entry.content });

    const save = (e: FormEvent) => {
        e.preventDefault();
        if (!form.data.content.trim()) return;
        form.put(`/control-room/discussions/${entry.id}`, {
            preserveScroll: true,
            onSuccess: () => setEditing(false),
        });
    };

    if (editing) {
        return (
            <form onSubmit={save} className="flex items-start gap-2">
                <Textarea
                    rows={2}
                    className="flex-1"
                    value={form.data.content}
                    onChange={(e) => form.setData('content', e.target.value)}
                />
                <div className="flex flex-col gap-1">
                    <Button
                        type="submit"
                        size="sm"
                        disabled={form.processing || !form.data.content.trim()}
                    >
                        Save
                    </Button>
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        onClick={() => {
                            form.setData('content', entry.content);
                            setEditing(false);
                        }}
                    >
                        Cancel
                    </Button>
                </div>
            </form>
        );
    }

    return (
        <div>
            <p
                className={`text-sm whitespace-pre-wrap ${deleted ? 'text-muted-foreground italic' : 'text-foreground'}`}
            >
                {entry.content}
            </p>
            <p className="mt-0.5 flex items-center gap-2 text-xs text-muted-foreground">
                <span>
                    {entry.user.name} · {formatDateTime(entry.created_at)}
                    {entry.edited_at ? ' · edited' : ''}
                </span>
                {!deleted && isOwner ? (
                    <Button
                        unstyled
                        type="button"
                        onClick={() => setEditing(true)}
                        className="font-medium text-primary hover:underline"
                    >
                        Edit
                    </Button>
                ) : null}
                {!deleted && (isOwner || canManage) ? (
                    <ConfirmChip
                        label="Delete"
                        icon={Trash2}
                        destructive
                        onConfirm={() =>
                            router.delete(
                                `/control-room/discussions/${entry.id}`,
                                { preserveScroll: true },
                            )
                        }
                    />
                ) : null}
            </p>
        </div>
    );
}

/* --- Linked records --------------------------------------------------- */

export function LinkedSection({ d }: { d: AlertWorkspaceDetail }) {
    const a = d.alert;
    const can = d.can ?? {
        manage: false,
        create_incident: false,
        view_incident: false,
        view_health_safety: false,
    };
    const rows: ReactNode[] = [];

    rows.push(
        <LinkedJourney
            key="journey"
            alertReference={
                a.reference_number ??
                (a.id ? `Alert ${a.id}` : 'Control Room alert')
            }
            alertStatus={a.status ?? 'open'}
            sensorConfirmationRequired={false}
            incident={
                d.linked_incident
                    ? {
                          referenceNumber: d.linked_incident.reference_number,
                          href: d.linked_incident.href,
                      }
                    : null
            }
            healthSafety={
                d.linked_hs_event
                    ? {
                          referenceNumber: d.linked_hs_event.reference_number,
                          handoverStatus: d.linked_hs_event.handover.status,
                          href: d.linked_hs_event.href,
                      }
                    : null
            }
            can={{
                manage: can.manage,
                createIncident: can.create_incident,
                viewIncident: can.view_incident,
                viewHealthSafety: can.view_health_safety,
            }}
            showAction={false}
        />,
    );
    if (d.linked_incident) {
        rows.push(
            <LinkedRow
                key="incident"
                icon={ShieldAlert}
                title="Incident record"
                sub={`${d.linked_incident.reference_number} · ${titleCase(d.linked_incident.status)} · system of record`}
                href={d.linked_incident.href}
            />,
        );
    }
    if (d.linked_hs_event) {
        const hs = d.linked_hs_event;
        const handover =
            hs.handover.status === 'accepted'
                ? [
                      'Accepted into H&S',
                      hs.handover.owner
                          ? `owner ${hs.handover.owner.name}`
                          : null,
                      hs.handover.accepted_by
                          ? `accepted by ${hs.handover.accepted_by.name}`
                          : null,
                      hs.handover.accepted_at
                          ? formatDateTime(hs.handover.accepted_at)
                          : null,
                  ]
                      .filter(Boolean)
                      .join(' · ')
                : 'Awaiting H&S acceptance';
        rows.push(
            <LinkedRow
                key="health-safety"
                icon={Activity}
                title="Health & Safety event"
                sub={[
                    hs.reference_number,
                    titleCase(hs.status),
                    handover,
                    hs.worksafe_notifiable
                        ? `WorkSafe ${titleCase(hs.worksafe_status ?? 'pending')}`
                        : null,
                ]
                    .filter(Boolean)
                    .join(' · ')}
                href={hs.href}
            />,
        );
    }
    if (d.client) {
        rows.push(
            <LinkedRow
                key="client"
                icon={User}
                title="Client record"
                sub={d.client.name}
                href={`/operations/clients/${d.client.id}/care`}
            />,
        );
    }
    if (a.asset) {
        rows.push(
            <LinkedRow
                key="asset"
                icon={Truck}
                title="Asset"
                sub={`${a.asset.name} · ${a.asset.asset_tag}`}
                href={`/fleet-assets/assets/${a.asset.id}`}
            />,
        );
    }

    return (
        <div className="flex flex-col gap-2">
            {rows.length ? (
                rows
            ) : (
                <p className="text-sm text-muted-foreground">
                    No linked records.
                </p>
            )}
        </div>
    );
}

function EmptyLinkedRow({
    icon: Icon,
    title,
    children,
}: {
    icon: ComponentType<{ className?: string }>;
    title: string;
    children: ReactNode;
}) {
    return (
        <div className="flex items-center gap-3 rounded-lg border border-dashed border-border p-3">
            <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-muted/60">
                <Icon className="h-4 w-4 text-muted-foreground/70" />
            </span>
            <div className="min-w-0 flex-1">
                <p className="text-sm font-medium text-muted-foreground">
                    {title}
                </p>
                <p className="text-xs text-muted-foreground/80">{children}</p>
            </div>
        </div>
    );
}

function LinkedRow({
    icon: Icon,
    title,
    sub,
    href,
}: {
    icon: ComponentType<{ className?: string }>;
    title: string;
    sub: string;
    href: string | null;
}) {
    const content = (
        <>
            <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-muted">
                <Icon className="h-4 w-4 text-muted-foreground" />
            </span>
            <div className="min-w-0 flex-1">
                <p className="text-sm font-medium text-foreground">{title}</p>
                <p className="truncate text-xs text-muted-foreground">{sub}</p>
            </div>
            {href ? (
                <ExternalLink className="h-4 w-4 text-muted-foreground" />
            ) : null}
        </>
    );

    return href ? (
        <Link
            href={href}
            className="flex items-center gap-3 rounded-lg border border-border p-3 transition-colors hover:bg-muted/50"
        >
            {content}
        </Link>
    ) : (
        <div className="flex items-center gap-3 rounded-lg border border-border p-3">
            {content}
        </div>
    );
}
