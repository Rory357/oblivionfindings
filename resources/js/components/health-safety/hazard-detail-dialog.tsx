/* HazardDetailDialog — the detail-as-modal hub for every Hazards surface.
 * Close twin of EventDetailDialog, built on the shared WizardShell chrome and
 * the hazard-kit. Rail sections: Overview · Risk · Corrective actions ·
 * Evidence · History. Every workflow (assign, start, mitigate, add/complete
 * action, review, close) is a pane inside the modal — nothing navigates away.
 * Read-only mode (client profile) shows the data with no actions. NZ-only. */
import { ApplicableProceduresPanel } from '@/components/health-safety/applicable-procedures-panel';
import {
    ACTION_TYPES,
    CONTROL_LEVELS,
    HazardRiskMatrix,
    LIKELIHOOD_LABELS,
    LIKELIHOOD_ORDER,
    RISK,
    SEV,
    SEVERITY_ORDER,
    STATUS,
    WORKSAFE_BANNER,
    controlLabel,
    fmtDay,
    fmtWhen,
    hazardLabelOf,
    requiresOfficer,
    riskOf,
    siteTypeLabel,
    storageUrl,
    type HazardAction,
    type HazardActionKey,
    type HazardDetail,
    type HazardSectionKey,
} from '@/components/health-safety/hazard-kit';
import { Button } from '@/components/ui/button';
import {
    AttachmentUploader,
    FileDropzone,
    StagedFileCard,
} from '@/components/ui/file-dropzone';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import {
    Field,
    InfoCard,
    SelectInput,
    StepHead,
} from '@/components/wizard/primitives';
import {
    ReviewCard,
    ReviewRow,
    WizardShell,
    type WizardStep,
} from '@/components/wizard/shell';
import { Link, useForm } from '@inertiajs/react';
import {
    Activity,
    AlertTriangle,
    Calendar,
    Camera,
    CheckCircle2,
    ChevronRight,
    ClipboardCheck,
    Clock,
    ExternalLink,
    Eye,
    FileText,
    Gauge,
    History as HistoryIcon,
    ListChecks,
    Loader2,
    MapPin,
    Play,
    Plus,
    ShieldAlert,
    ShieldCheck,
    User as UserIcon,
    UserPlus,
    Wrench,
    type LucideIcon,
} from 'lucide-react';
import { useEffect, useState, type ComponentType, type FormEvent } from 'react';

/* --------------------------------------------------------------- panes */

type ActivePane =
    | { kind: 'assign' }
    | { kind: 'start' }
    | { kind: 'mitigate' }
    | { kind: 'add_action' }
    | { kind: 'review' }
    | { kind: 'close' }
    | { kind: 'complete_action'; actionId: number };

function paneFromAction(action: HazardActionKey | null): ActivePane | null {
    switch (action) {
        case 'assign':
            return { kind: 'assign' };
        case 'start':
            return { kind: 'start' };
        case 'mitigate':
            return { kind: 'mitigate' };
        case 'add_action':
            return { kind: 'add_action' };
        case 'review':
            return { kind: 'review' };
        case 'close':
            return { kind: 'close' };
        default:
            return null;
    }
}

function todayInput(): string {
    const d = new Date();
    return new Date(d.getTime() - d.getTimezoneOffset() * 60000)
        .toISOString()
        .slice(0, 10);
}

function staffOptions(
    detail: HazardDetail,
): { value: string; label: string }[] {
    return detail.assignable_staff.map((s) => ({
        value: String(s.id),
        label: s.name,
    }));
}

const STAGE_ORDER: Array<{ key: string; label: string; icon: LucideIcon }> = [
    { key: 'open', label: 'Open', icon: AlertTriangle },
    { key: 'in_progress', label: 'In progress', icon: Clock },
    { key: 'mitigated', label: 'Mitigated', icon: ShieldCheck },
    { key: 'closed', label: 'Closed', icon: CheckCircle2 },
];

/* --------------------------------------------------------------- dialog */

export function HazardDetailDialog({
    detail,
    open,
    onClose,
    initialSection = 'overview',
    initialAction = null,
    intentKey = 0,
    readOnly = false,
    openedFrom = null,
    registerHref = '/compliance/hazards',
}: {
    detail: HazardDetail;
    open: boolean;
    onClose: () => void;
    initialSection?: HazardSectionKey;
    initialAction?: HazardActionKey | null;
    /** Bumped by the register on every open so re-selecting the same action reopens its pane. */
    intentKey?: number;
    readOnly?: boolean;
    openedFrom?: string | null;
    registerHref?: string;
}) {
    const d = detail;
    const [section, setSection] = useState<HazardSectionKey>(initialSection);
    const [pane, setPane] = useState<ActivePane | null>(() =>
        readOnly ? null : paneFromAction(initialAction),
    );

    // Re-sync when the register opens a different action on the same hazard
    // (the dialog is keyed by id, so it isn't remounted in that case).
    useEffect(() => {
        if (readOnly) return;
        setSection(initialSection);
        setPane(paneFromAction(initialAction));
        // eslint-disable-next-line react-hooks/exhaustive-deps -- sync only on incoming prop changes; intentKey forces re-sync when the same action is re-selected
    }, [initialSection, initialAction, intentKey]);

    const canManage = !readOnly && d.can.manage;
    const canAct = canManage && d.status !== 'closed';
    const sev = SEV[d.severity] ?? SEV.low;
    const risk = RISK[d.risk_rating] ?? RISK.low;
    const stage = STATUS[d.status] ?? STATUS.open;

    const openActions = d.actions.filter((a) => a.status !== 'completed');

    const SECTIONS: {
        key: HazardSectionKey;
        label: string;
        blurb: string;
        icon: ComponentType<{ className?: string }>;
    }[] = [
        {
            key: 'overview',
            label: 'Overview',
            blurb: 'Hazard & origin',
            icon: FileText,
        },
        {
            key: 'risk',
            label: 'Risk',
            blurb: `${risk.label} rating`,
            icon: Gauge,
        },
        {
            key: 'actions',
            label: 'Corrective actions',
            blurb: d.actions.length ? `${d.actions.length} logged` : 'none',
            icon: ListChecks,
        },
        {
            key: 'evidence',
            label: 'Evidence',
            blurb: (() => {
                const n =
                    d.photo_paths.length +
                    d.document_paths.length +
                    d.resolution_evidence.length;
                return n ? `${n} file${n === 1 ? '' : 's'}` : 'photos & docs';
            })(),
            icon: Camera,
        },
        {
            key: 'history',
            label: 'History',
            blurb: 'Audit trail',
            icon: HistoryIcon,
        },
    ];
    const stepIndex = Math.max(
        0,
        SECTIONS.findIndex((s) => s.key === section),
    );

    const footerStart = (
        <div className="flex flex-wrap items-center gap-2 text-xs">
            <span
                className={`inline-flex items-center gap-1 rounded-full px-2 py-0.5 font-medium ${sevChip(d.severity)}`}
            >
                {sev.label}
            </span>
            <span
                className={`inline-flex items-center gap-1 rounded-full px-2 py-0.5 font-medium ${riskChip(d.risk_rating)}`}
            >
                {risk.label} risk
            </span>
            <span
                className={`inline-flex items-center gap-1 rounded-full px-2 py-0.5 font-medium ${stage.chip}`}
            >
                <stage.icon className="h-3 w-3" /> {stage.label}
            </span>
            {d.worksafe_notifiable ? (
                <span className="inline-flex items-center gap-1 rounded-full bg-status-critical-bg px-2 py-0.5 font-medium text-status-critical">
                    <ShieldAlert className="h-3 w-3" /> WorkSafe-notifiable
                </span>
            ) : null}
        </div>
    );

    const blockers = d.close_gate?.blockers ?? [];

    const footerEnd = pane ? null : readOnly ? (
        <Link
            href={`${registerHref}?site_id=${d.site?.id ?? ''}&hazard=${d.id}`}
            className="inline-flex items-center gap-1.5 rounded-lg bg-primary px-3 py-2 text-sm font-medium text-primary-foreground transition-colors hover:bg-primary/90"
        >
            <ExternalLink className="h-4 w-4" /> Open in register
        </Link>
    ) : (
        <div className="flex flex-wrap items-center gap-2">
            <Link
                href={`/hazards/${d.id}`}
                className="inline-flex items-center gap-1.5 rounded-lg px-3 py-2 text-sm font-medium text-muted-foreground transition-colors hover:bg-muted"
            >
                <ExternalLink className="h-4 w-4" /> Open full page
            </Link>
            {d.can.assign && d.status !== 'closed' ? (
                <Button
                    size="sm"
                    variant="outline"
                    onClick={() => setPane({ kind: 'assign' })}
                >
                    <UserPlus className="mr-1.5 h-4 w-4" />{' '}
                    {d.assigned_to ? 'Reassign' : 'Assign'}
                </Button>
            ) : null}
            {canAct && d.status === 'open' ? (
                <Button
                    size="sm"
                    variant="outline"
                    onClick={() => setPane({ kind: 'start' })}
                >
                    <Play className="mr-1.5 h-4 w-4" /> Start progress
                </Button>
            ) : null}
            {canAct && d.status === 'in_progress' ? (
                <Button
                    size="sm"
                    variant="outline"
                    onClick={() => setPane({ kind: 'mitigate' })}
                >
                    <ShieldCheck className="mr-1.5 h-4 w-4" /> Mark mitigated
                </Button>
            ) : null}
            {canAct ? (
                <Button
                    size="sm"
                    variant="outline"
                    onClick={() => {
                        setSection('actions');
                        setPane({ kind: 'add_action' });
                    }}
                >
                    <ListChecks className="mr-1.5 h-4 w-4" /> Add action
                </Button>
            ) : null}
            {canAct ? (
                <Button
                    size="sm"
                    variant="outline"
                    onClick={() => setPane({ kind: 'review' })}
                >
                    <ClipboardCheck className="mr-1.5 h-4 w-4" /> Record review
                </Button>
            ) : null}
            {d.can.close && d.status !== 'closed' ? (
                <Button
                    size="sm"
                    variant="outline"
                    onClick={() => setPane({ kind: 'close' })}
                    title={
                        blockers.length
                            ? `Outstanding: ${blockers.join(' ')}`
                            : undefined
                    }
                    className="border-status-critical/40 text-status-critical hover:text-status-critical"
                >
                    <CheckCircle2 className="mr-1.5 h-4 w-4" /> Close hazard
                </Button>
            ) : null}
        </div>
    );

    return (
        <WizardShell
            open={open}
            onClose={onClose}
            title={`Hazard ${d.reference_number}`}
            description={`${hazardLabelOf(d)} — ${stage.label}`}
            railIcon={ShieldAlert}
            railTitle={d.reference_number}
            railSub={`${hazardLabelOf(d)} · ${sev.label}`}
            steps={SECTIONS as readonly WizardStep[]}
            stepIndex={stepIndex}
            onStepClick={(i) => {
                setPane(null);
                setSection(SECTIONS[i].key);
            }}
            pct={null}
            footerStart={footerStart}
            footerEnd={footerEnd}
        >
            {pane ? (
                <PaneRenderer pane={pane} d={d} onDone={() => setPane(null)} />
            ) : (
                <>
                    {readOnly ? (
                        <InfoCard icon={Eye} tone="info">
                            Read-only — opened from a client profile. Manage
                            this hazard from the register.
                        </InfoCard>
                    ) : null}
                    {openedFrom ? (
                        <InfoCard icon={ExternalLink} tone="info">
                            Opened from {openedFrom}.
                        </InfoCard>
                    ) : null}
                    {section === 'overview' ? <OverviewSection d={d} /> : null}
                    {section === 'risk' ? <RiskSection d={d} /> : null}
                    {section === 'actions' ? (
                        <ActionsSection
                            d={d}
                            canAct={canAct}
                            onPane={setPane}
                        />
                    ) : null}
                    {section === 'evidence' ? (
                        <EvidenceSection d={d} canAct={canAct} />
                    ) : null}
                    {section === 'history' ? <HistorySection d={d} /> : null}
                </>
            )}
        </WizardShell>
    );
}

export default HazardDetailDialog;

/* --------------------------------------------------------------- chips */

function sevChip(severity: string): string {
    const tone = (SEV[severity] ?? SEV.low).tone;
    return tone === 'critical'
        ? 'bg-status-critical-bg text-status-critical'
        : tone === 'warning'
          ? 'bg-status-warning-bg text-status-warning'
          : 'bg-status-success-bg text-status-success';
}
function riskChip(rating: string): string {
    const tone = (RISK[rating] ?? RISK.low).tone;
    return tone === 'critical'
        ? 'bg-status-critical-bg text-status-critical'
        : tone === 'warning'
          ? 'bg-status-warning-bg text-status-warning'
          : 'bg-status-success-bg text-status-success';
}

/* --------------------------------------------------------------- overview */

function StageTracker({ status }: { status: string }) {
    // `reopened` (rare, back to active) maps onto the Open stage so the tracker
    // never renders an all-grey "nothing reached" state.
    const reached =
        status === 'reopened'
            ? 0
            : STAGE_ORDER.findIndex((s) => s.key === status);
    return (
        <div className="flex flex-wrap items-center gap-1.5">
            {STAGE_ORDER.map((s, i) => {
                const done = reached >= 0 && i <= reached;
                const current = i === reached;
                const Icon = s.icon;
                return (
                    <div key={s.key} className="flex items-center gap-1.5">
                        <span
                            aria-current={current ? 'step' : undefined}
                            className={`inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-medium ${
                                done
                                    ? 'bg-primary/10 text-primary'
                                    : 'bg-muted text-muted-foreground'
                            } ${current ? 'ring-1 ring-primary/40' : ''}`}
                        >
                            <Icon className="h-3.5 w-3.5" /> {s.label}
                        </span>
                        {i < STAGE_ORDER.length - 1 ? (
                            <ChevronRight className="h-3.5 w-3.5 text-muted-foreground/50" />
                        ) : null}
                    </div>
                );
            })}
        </div>
    );
}

function OverviewSection({ d }: { d: HazardDetail }) {
    const overdue =
        !!d.due_date &&
        (d.status === 'open' || d.status === 'in_progress') &&
        new Date(d.due_date) < new Date(new Date().toDateString());
    return (
        <div className="flex flex-col gap-4">
            <StageTracker status={d.status} />

            {d.worksafe_notifiable ? (
                <div className="flex gap-3 rounded-xl border border-status-critical/30 bg-status-critical-bg/60 p-3">
                    <ShieldAlert className="mt-0.5 h-5 w-5 shrink-0 text-status-critical" />
                    <div>
                        <p className="text-sm font-semibold text-status-critical">
                            WorkSafe-notifiable hazard
                        </p>
                        <p className="mt-0.5 text-xs text-muted-foreground">
                            {WORKSAFE_BANNER}
                        </p>
                    </div>
                </div>
            ) : null}

            <div>
                <SectionLabel icon={FileText}>Description</SectionLabel>
                <p className="mt-1 text-sm whitespace-pre-line text-foreground">
                    {d.description}
                </p>
            </div>

            {d.immediate_action_taken ? (
                <div>
                    <SectionLabel icon={Activity}>
                        Immediate action taken
                    </SectionLabel>
                    <p className="mt-1 text-sm whitespace-pre-line text-foreground">
                        {d.immediate_action_taken}
                    </p>
                    {d.immediate_action_applied ? (
                        <p className="mt-1 inline-flex items-center gap-1 text-xs font-medium text-status-success">
                            <CheckCircle2 className="h-3.5 w-3.5" /> Area made
                            safe
                        </p>
                    ) : null}
                </div>
            ) : null}

            {d.photo_paths.length ? (
                <div>
                    <SectionLabel icon={Camera}>
                        Photos ({d.photo_paths.length})
                    </SectionLabel>
                    <ImageGallery paths={d.photo_paths} />
                </div>
            ) : null}

            <div className="grid gap-3 sm:grid-cols-2">
                <ReviewCard icon={MapPin} title="Where & what">
                    <ReviewRow
                        label="Site"
                        value={
                            d.site
                                ? `${d.site.name} · ${siteTypeLabel(d.site.type)}`
                                : '—'
                        }
                    />
                    <ReviewRow label="Location" value={d.location} />
                    <ReviewRow label="Hazard type" value={hazardLabelOf(d)} />
                    <ReviewRow label="Witnesses" value={d.witnesses} />
                </ReviewCard>
                <ReviewCard icon={UserIcon} title="Ownership & dates">
                    <ReviewRow
                        label="Reported by"
                        value={d.reported_by?.name}
                    />
                    <ReviewRow
                        label="Assigned to"
                        value={d.assigned_to?.name ?? 'Unassigned'}
                    />
                    <ReviewRow label="Logged" value={fmtDay(d.created_at)} />
                    <ReviewRow
                        label="Due"
                        value={
                            d.due_date ? (
                                <span
                                    className={
                                        overdue
                                            ? 'font-semibold text-status-critical'
                                            : ''
                                    }
                                >
                                    {fmtDay(d.due_date)}
                                    {overdue ? ' · overdue' : ''}
                                </span>
                            ) : (
                                '—'
                            )
                        }
                    />
                </ReviewCard>
            </div>

            {d.status === 'closed' ? (
                <div className="rounded-xl border border-status-success/30 bg-status-success-bg/50 p-3">
                    <p className="inline-flex items-center gap-1.5 text-sm font-semibold text-status-success">
                        <CheckCircle2 className="h-4 w-4" /> Resolution
                    </p>
                    <p className="mt-1 text-sm whitespace-pre-line text-foreground">
                        {d.resolution_summary ?? '—'}
                    </p>
                    {d.resolution_evidence.length ? (
                        <FileList files={d.resolution_evidence} />
                    ) : null}
                    {d.closed_at ? (
                        <p className="mt-2 text-xs text-muted-foreground">
                            Closed {fmtDay(d.closed_at)}
                        </p>
                    ) : null}
                </div>
            ) : null}

            {d.related_procedures && d.related_procedures.length > 0 ? (
                <ApplicableProceduresPanel
                    procedures={d.related_procedures}
                    title="Mitigating safe work procedures"
                    subtitle="Approved procedures that control this hazard type"
                />
            ) : null}
        </div>
    );
}

/* --------------------------------------------------------------- risk */

function RiskSection({ d }: { d: HazardDetail }) {
    const hasResidual = !!(d.residual_risk_rating && d.residual_severity);
    return (
        <div className="flex flex-col gap-4">
            <div className="grid grid-cols-2 gap-2 sm:grid-cols-4">
                <RiskTile
                    label="Severity"
                    value={SEV[d.severity]?.label ?? d.severity}
                    tone={SEV[d.severity]?.tone}
                />
                <RiskTile
                    label="Likelihood"
                    value={LIKELIHOOD_LABELS[d.likelihood] ?? d.likelihood}
                />
                <RiskTile
                    label="Risk rating"
                    value={RISK[d.risk_rating]?.label ?? d.risk_rating}
                    tone={RISK[d.risk_rating]?.tone}
                />
                {hasResidual ? (
                    <RiskTile
                        label="Residual"
                        value={
                            RISK[d.residual_risk_rating!]?.label ??
                            d.residual_risk_rating!
                        }
                        tone={RISK[d.residual_risk_rating!]?.tone}
                    />
                ) : null}
            </div>

            <div>
                <SectionLabel icon={Gauge}>WorkSafe risk matrix</SectionLabel>
                <p className="mt-0.5 mb-2 text-xs text-muted-foreground">
                    Severity × Likelihood. The current rating is highlighted.
                </p>
                <HazardRiskMatrix
                    severity={d.severity}
                    likelihood={d.likelihood}
                    residualSeverity={d.residual_severity}
                    residualLikelihood={d.residual_likelihood}
                />
            </div>

            {d.control_hierarchy.length ? (
                <div>
                    <SectionLabel icon={ShieldCheck}>
                        Controls applied
                    </SectionLabel>
                    <div className="mt-1.5 flex flex-wrap gap-1.5">
                        {d.control_hierarchy.map((c) => (
                            <span
                                key={c}
                                className="inline-flex items-center gap-1 rounded-full bg-primary/10 px-2 py-0.5 text-[11px] font-medium text-primary"
                            >
                                <CheckCircle2 className="h-3 w-3" />{' '}
                                {controlLabel(c)}
                            </span>
                        ))}
                    </div>
                </div>
            ) : null}

            {hasResidual ? (
                <div className="rounded-xl border border-border bg-muted/40 p-3">
                    <p className="inline-flex items-center gap-1.5 text-sm font-medium">
                        <Gauge className="h-4 w-4 text-muted-foreground" />{' '}
                        Residual risk after controls:{' '}
                        <span
                            className={`inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-medium ${riskChip(d.residual_risk_rating!)}`}
                        >
                            {RISK[d.residual_risk_rating!]?.label}
                        </span>
                    </p>
                    <p className="mt-1 text-xs text-muted-foreground">
                        {SEV[d.residual_severity!]?.label} ×{' '}
                        {LIKELIHOOD_LABELS[d.residual_likelihood!] ??
                            d.residual_likelihood}
                    </p>
                </div>
            ) : null}

            {requiresOfficer(d.risk_rating) ? (
                <InfoCard icon={AlertTriangle} tone="warn">
                    <span className="font-semibold">
                        H&amp;S Officer assignment required.
                    </span>{' '}
                    High and extreme-risk hazards must be owned by a nominated
                    H&amp;S officer and resolved within{' '}
                    {d.risk_rating === 'extreme' ? '1 day' : '7 days'}.
                </InfoCard>
            ) : null}
        </div>
    );
}

function RiskTile({
    label,
    value,
    tone,
}: {
    label: string;
    value: string;
    tone?: string;
}) {
    const dot =
        tone === 'critical'
            ? 'bg-status-critical'
            : tone === 'warning'
              ? 'bg-status-warning'
              : tone === 'success'
                ? 'bg-status-success'
                : 'bg-muted-foreground';
    return (
        // eslint-disable-next-line no-restricted-syntax -- compact risk stat tile, not a content Card
        <div className="rounded-xl border border-border bg-card p-3">
            <p className="text-[11px] font-medium tracking-wide text-muted-foreground uppercase">
                {label}
            </p>
            <p className="mt-1 inline-flex items-center gap-1.5 text-sm font-semibold">
                <span className={`h-2 w-2 rounded-full ${dot}`} /> {value}
            </p>
        </div>
    );
}

/* --------------------------------------------------------------- actions */

function ActionsSection({
    d,
    canAct,
    onPane,
}: {
    d: HazardDetail;
    canAct: boolean;
    onPane: (p: ActivePane) => void;
}) {
    return (
        <div className="flex flex-col gap-3">
            <div className="flex items-center justify-between">
                <SectionLabel icon={ListChecks}>
                    Corrective actions
                </SectionLabel>
                {canAct ? (
                    <Button
                        size="sm"
                        onClick={() => onPane({ kind: 'add_action' })}
                    >
                        <Plus className="mr-1.5 h-4 w-4" /> Add action
                    </Button>
                ) : null}
            </div>

            {d.actions.length === 0 ? (
                <EmptyState
                    icon={ListChecks}
                    text="No corrective actions yet."
                />
            ) : (
                d.actions.map((a) => (
                    <ActionCard
                        key={a.id}
                        a={a}
                        canAct={canAct}
                        onComplete={() =>
                            onPane({ kind: 'complete_action', actionId: a.id })
                        }
                    />
                ))
            )}
        </div>
    );
}

function ActionCard({
    a,
    canAct,
    onComplete,
}: {
    a: HazardAction;
    canAct: boolean;
    onComplete: () => void;
}) {
    const done = a.status === 'completed';
    const tone = done
        ? 'text-status-success'
        : a.status === 'in_progress'
          ? 'text-live'
          : 'text-status-info';
    const chip = done
        ? 'bg-status-success-bg text-status-success'
        : a.status === 'in_progress'
          ? 'bg-live-bg text-live'
          : 'bg-status-info-bg text-status-info';
    return (
        // eslint-disable-next-line no-restricted-syntax -- corrective-action list card surface
        <div className="rounded-xl border border-border bg-card p-3">
            <div className="flex items-start gap-3">
                {done ? (
                    <CheckCircle2
                        className={`mt-0.5 h-4 w-4 shrink-0 ${tone}`}
                    />
                ) : (
                    <Wrench className={`mt-0.5 h-4 w-4 shrink-0 ${tone}`} />
                )}
                <div className="min-w-0 flex-1">
                    <div className="flex flex-wrap items-center gap-2">
                        <span className="text-sm font-semibold text-foreground">
                            {a.title}
                        </span>
                        <span
                            className={`inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-medium ${chip}`}
                        >
                            {done
                                ? 'Completed'
                                : a.status === 'in_progress'
                                  ? 'In progress'
                                  : 'Open'}
                        </span>
                    </div>
                    <p className="mt-0.5 text-xs text-muted-foreground">
                        {[
                            a.reference_number,
                            a.action_type ? controlLabel(a.action_type) : null,
                        ]
                            .filter(Boolean)
                            .join(' · ') || '—'}
                    </p>
                    <div className="mt-1 flex flex-wrap gap-3 text-xs text-muted-foreground">
                        {a.assigned_to ? (
                            <span className="inline-flex items-center gap-1">
                                <UserIcon className="h-3 w-3" />{' '}
                                {a.assigned_to.name}
                            </span>
                        ) : null}
                        {a.due_date ? (
                            <span className="inline-flex items-center gap-1">
                                <Calendar className="h-3 w-3" /> Due{' '}
                                {fmtDay(a.due_date)}
                            </span>
                        ) : null}
                    </div>
                    {done && a.completion_notes ? (
                        <div className="mt-2 rounded-lg bg-status-success-bg/60 p-2 text-xs text-foreground">
                            <span className="font-medium">
                                Completed
                                {a.completed_by
                                    ? ` by ${a.completed_by.name}`
                                    : ''}
                                :
                            </span>{' '}
                            {a.completion_notes}
                        </div>
                    ) : null}
                </div>
                {canAct && !done ? (
                    <Button size="sm" variant="outline" onClick={onComplete}>
                        <CheckCircle2 className="mr-1.5 h-4 w-4" /> Mark
                        complete
                    </Button>
                ) : null}
            </div>
        </div>
    );
}

/* --------------------------------------------------------------- evidence */

function EvidenceSection({ d, canAct }: { d: HazardDetail; canAct: boolean }) {
    return (
        <div className="flex flex-col gap-5">
            <div>
                <SectionLabel icon={Camera}>Photos</SectionLabel>
                {canAct ? (
                    <div className="mt-2">
                        <AttachmentUploader
                            endpoint={`/hazards/${d.id}/media`}
                            accept="image/*"
                            hint="JPG, PNG — up to 10 MB each"
                        />
                    </div>
                ) : null}
                {d.photo_paths.length ? (
                    <ImageGallery paths={d.photo_paths} />
                ) : !canAct ? (
                    <EmptyState
                        icon={Camera}
                        text="No photos on this hazard."
                    />
                ) : null}
            </div>

            <div>
                <SectionLabel icon={FileText}>
                    Supporting documents
                </SectionLabel>
                {canAct ? (
                    <div className="mt-2">
                        <AttachmentUploader
                            endpoint={`/hazards/${d.id}/media`}
                            accept=".pdf,.doc,.docx,.xls,.xlsx"
                            hint="PDF, DOC — reports, SDS, certificates"
                        />
                    </div>
                ) : null}
                {d.document_paths.length ? (
                    <FileList files={d.document_paths} />
                ) : !canAct ? (
                    <EmptyState
                        icon={FileText}
                        text="No documents on this hazard."
                    />
                ) : null}
            </div>

            {d.resolution_evidence.length ? (
                <div>
                    <SectionLabel icon={CheckCircle2}>
                        Resolution evidence
                    </SectionLabel>
                    <p className="mt-0.5 mb-1 text-xs text-muted-foreground">
                        Captured at closure.
                    </p>
                    <FileList files={d.resolution_evidence} />
                </div>
            ) : null}
        </div>
    );
}

function ImageGallery({ paths }: { paths: string[] }) {
    return (
        <div className="mt-2 flex flex-wrap gap-2">
            {paths.map((p) => (
                <a
                    key={p}
                    href={storageUrl(p)}
                    target="_blank"
                    rel="noreferrer"
                    className="block"
                >
                    <img
                        src={storageUrl(p)}
                        alt="Hazard photo"
                        className="h-20 w-20 rounded-lg border border-border object-cover"
                    />
                </a>
            ))}
        </div>
    );
}

function FileList({
    files,
}: {
    files: { name: string; path: string; size?: number | null }[];
}) {
    return (
        <div className="mt-2 flex flex-col gap-1.5">
            {files.map((f, i) => (
                <a
                    key={i}
                    href={storageUrl(f.path)}
                    target="_blank"
                    rel="noreferrer"
                    className="inline-flex items-center gap-2 rounded-lg border border-border bg-card px-3 py-2 text-sm transition-colors hover:bg-muted"
                >
                    <FileText className="h-4 w-4 shrink-0 text-muted-foreground" />
                    <span className="min-w-0 flex-1 truncate">{f.name}</span>
                    {f.size ? (
                        <span className="text-xs text-muted-foreground">
                            {fmtSize(f.size)}
                        </span>
                    ) : null}
                </a>
            ))}
        </div>
    );
}

function fmtSize(bytes: number): string {
    if (bytes < 1024) return `${bytes} B`;
    if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(0)} KB`;
    return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}

/* --------------------------------------------------------------- history */

function HistorySection({ d }: { d: HazardDetail }) {
    if (!d.history.length)
        return (
            <EmptyState icon={HistoryIcon} text="No history recorded yet." />
        );
    return (
        <div className="relative space-y-0 pl-6">
            <div className="absolute top-2 bottom-2 left-[11px] w-px bg-border" />
            {d.history.map((h) => {
                const meta = HISTORY_ICON[h.type] ?? {
                    icon: Activity,
                    color: 'bg-status-info',
                };
                const Icon = meta.icon;
                return (
                    <div key={h.id} className="relative flex gap-3 pb-4">
                        <div
                            className={`absolute top-0.5 -left-6 flex h-[22px] w-[22px] shrink-0 items-center justify-center rounded-full ${meta.color} text-primary-foreground`}
                        >
                            <Icon className="h-3 w-3" />
                        </div>
                        <div className="min-w-0 pt-0.5">
                            <div className="flex flex-wrap items-center gap-2">
                                <span className="text-sm font-medium">
                                    {h.title}
                                </span>
                                <span className="text-xs text-muted-foreground">
                                    {fmtWhen(h.at).main}
                                </span>
                            </div>
                            {h.note ? (
                                <p className="mt-0.5 max-w-md text-xs whitespace-pre-line text-muted-foreground">
                                    {h.note}
                                </p>
                            ) : null}
                            {h.actor ? (
                                <p className="mt-0.5 text-xs text-muted-foreground/80">
                                    by {h.actor}
                                </p>
                            ) : null}
                        </div>
                    </div>
                );
            })}
        </div>
    );
}

const HISTORY_ICON: Record<string, { icon: LucideIcon; color: string }> = {
    reported: { icon: ShieldAlert, color: 'bg-muted-foreground' },
    assigned: { icon: UserPlus, color: 'bg-status-info' },
    in_progress: { icon: Play, color: 'bg-live' },
    mitigated: { icon: ShieldCheck, color: 'bg-primary' },
    closed: { icon: CheckCircle2, color: 'bg-status-success' },
    reopened: { icon: AlertTriangle, color: 'bg-status-warning' },
    reviewed: { icon: Activity, color: 'bg-status-info' },
    risk: { icon: Gauge, color: 'bg-status-warning' },
    action_added: { icon: ListChecks, color: 'bg-status-warning' },
    action_completed: { icon: CheckCircle2, color: 'bg-status-success' },
};

/* --------------------------------------------------------------- shared bits */

function SectionLabel({
    icon: Icon,
    children,
}: {
    icon: LucideIcon;
    children: React.ReactNode;
}) {
    return (
        <p className="inline-flex items-center gap-1.5 text-xs font-semibold tracking-wide text-muted-foreground uppercase">
            <Icon className="h-3.5 w-3.5" /> {children}
        </p>
    );
}

function EmptyState({ icon: Icon, text }: { icon: LucideIcon; text: string }) {
    return (
        <div className="rounded-xl border border-dashed border-border px-4 py-8 text-center">
            <Icon className="mx-auto mb-2 h-8 w-8 text-muted-foreground/40" />
            <p className="text-sm text-muted-foreground">{text}</p>
        </div>
    );
}

function GateRow({ ok, label }: { ok: boolean; label: string }) {
    return (
        <div className="flex items-center gap-2 text-sm">
            {ok ? (
                <CheckCircle2 className="h-4 w-4 text-status-success" />
            ) : (
                <AlertTriangle className="h-4 w-4 text-status-warning" />
            )}
            <span className={ok ? 'text-foreground' : 'text-muted-foreground'}>
                {label}
            </span>
        </div>
    );
}

function PaneFooter({
    onDone,
    submitLabel,
    processing,
    danger = false,
}: {
    onDone: () => void;
    submitLabel: string;
    processing: boolean;
    danger?: boolean;
}) {
    return (
        <div className="flex justify-end gap-2">
            <Button type="button" variant="outline" onClick={onDone}>
                Cancel
            </Button>
            <Button
                type="submit"
                disabled={processing}
                className={
                    danger
                        ? 'bg-status-critical text-primary-foreground hover:bg-status-critical/90'
                        : ''
                }
            >
                {processing ? (
                    <Loader2 className="mr-1.5 h-4 w-4 animate-spin" />
                ) : null}
                {submitLabel}
            </Button>
        </div>
    );
}

/** Only close the pane when the server didn't flash an error (gate/validation). */
function onPaneSuccess(onDone: () => void) {
    return (page: { props: Record<string, unknown> }) => {
        const flash = page.props.flash as { error?: string } | undefined;
        if (!flash?.error) onDone();
    };
}

/* --------------------------------------------------------------- panes */

function PaneRenderer({
    pane,
    d,
    onDone,
}: {
    pane: ActivePane;
    d: HazardDetail;
    onDone: () => void;
}) {
    switch (pane.kind) {
        case 'assign':
            return <AssignPane d={d} onDone={onDone} />;
        case 'start':
            return <StartPane d={d} onDone={onDone} />;
        case 'mitigate':
            return <MitigatePane d={d} onDone={onDone} />;
        case 'add_action':
            return <AddActionPane d={d} onDone={onDone} />;
        case 'review':
            return <ReviewPane d={d} onDone={onDone} />;
        case 'close':
            return <ClosePane d={d} onDone={onDone} />;
        case 'complete_action': {
            const a = d.actions.find((x) => x.id === pane.actionId);
            return a ? (
                <CompleteActionPane d={d} a={a} onDone={onDone} />
            ) : null;
        }
    }
}

function AssignPane({ d, onDone }: { d: HazardDetail; onDone: () => void }) {
    const form = useForm<{ assigned_to_user_id: string; due_date: string }>({
        assigned_to_user_id: d.assigned_to ? String(d.assigned_to.id) : '',
        due_date: d.due_date ?? '',
    });
    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post(`/hazards/${d.id}/assign`, {
            preserveScroll: true,
            onSuccess: onPaneSuccess(onDone),
        });
    };
    return (
        <form onSubmit={submit} className="flex flex-col gap-4">
            <StepHead
                icon={UserPlus}
                title="Assign hazard"
                blurb="Nominate an owner and confirm the resolution due date. High and extreme-risk hazards must have an owner."
            />
            <Field
                label="Owner"
                required
                error={form.errors.assigned_to_user_id}
            >
                <SelectInput
                    value={form.data.assigned_to_user_id}
                    onChange={(v) => form.setData('assigned_to_user_id', v)}
                    placeholder="Select a staff member"
                    options={staffOptions(d)}
                />
            </Field>
            <Field label="Due date" error={form.errors.due_date}>
                <Input
                    type="date"
                    value={form.data.due_date}
                    onChange={(e) => form.setData('due_date', e.target.value)}
                />
            </Field>
            <PaneFooter
                onDone={onDone}
                submitLabel="Save assignment"
                processing={form.processing}
            />
        </form>
    );
}

function StartPane({ d, onDone }: { d: HazardDetail; onDone: () => void }) {
    const form = useForm<{ status: string; note: string }>({
        status: 'in_progress',
        note: '',
    });
    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post(`/hazards/${d.id}/status`, {
            preserveScroll: true,
            onSuccess: onPaneSuccess(onDone),
        });
    };
    return (
        <form onSubmit={submit} className="flex flex-col gap-4">
            <StepHead
                icon={Play}
                title="Start progress"
                blurb="Move this hazard from Open to In progress to show controls are being implemented."
            />
            <InfoCard icon={Clock} tone="info">
                The status changes to In progress and is recorded in the audit
                trail with your note.
            </InfoCard>
            <Field label="Note" error={form.errors.note}>
                <Textarea
                    rows={3}
                    value={form.data.note}
                    onChange={(e) => form.setData('note', e.target.value)}
                    placeholder="What controls are being put in place?"
                />
            </Field>
            <PaneFooter
                onDone={onDone}
                submitLabel="Move to In progress"
                processing={form.processing}
            />
        </form>
    );
}

function MitigatePane({ d, onDone }: { d: HazardDetail; onDone: () => void }) {
    const form = useForm<{
        status: string;
        control_hierarchy: string[];
        residual_severity: string;
        residual_likelihood: string;
    }>({
        status: 'mitigated',
        control_hierarchy: [],
        residual_severity: '',
        residual_likelihood: '',
    });
    const residual = riskOf(
        form.data.residual_severity,
        form.data.residual_likelihood,
    );
    const toggle = (key: string) =>
        form.setData(
            'control_hierarchy',
            form.data.control_hierarchy.includes(key)
                ? form.data.control_hierarchy.filter((c) => c !== key)
                : [...form.data.control_hierarchy, key],
        );
    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post(`/hazards/${d.id}/status`, {
            preserveScroll: true,
            onSuccess: onPaneSuccess(onDone),
        });
    };
    return (
        <form onSubmit={submit} className="flex flex-col gap-4">
            <StepHead
                icon={ShieldCheck}
                title="Mark mitigated"
                blurb="Record the controls applied (hierarchy of controls) and the residual risk after them. The hazard moves to Mitigated, awaiting a closure review."
            />
            <Field
                label="Controls applied — hierarchy of controls"
                required
                error={form.errors.control_hierarchy}
            >
                <div className="flex flex-col gap-1.5">
                    {CONTROL_LEVELS.map((c, i) => {
                        const on = form.data.control_hierarchy.includes(c.key);
                        return (
                            // eslint-disable-next-line no-restricted-syntax -- multi-select control-hierarchy toggle row, not a shadcn Button
                            <button
                                key={c.key}
                                type="button"
                                aria-pressed={on}
                                onClick={() => toggle(c.key)}
                                className={`flex items-center gap-3 rounded-lg border p-2.5 text-left transition-colors ${on ? 'border-primary/40 bg-primary/5' : 'border-border hover:bg-muted'}`}
                            >
                                <span
                                    className={`flex h-6 w-6 shrink-0 items-center justify-center rounded-full text-xs font-bold ${on ? 'bg-primary text-primary-foreground' : 'bg-muted text-muted-foreground'}`}
                                >
                                    {on ? (
                                        <CheckCircle2 className="h-3.5 w-3.5" />
                                    ) : (
                                        i + 1
                                    )}
                                </span>
                                <span className="min-w-0">
                                    <span className="block text-sm font-medium text-foreground">
                                        {c.label}
                                    </span>
                                    <span className="block text-xs text-muted-foreground">
                                        {c.desc}
                                    </span>
                                </span>
                            </button>
                        );
                    })}
                </div>
            </Field>
            <div className="grid gap-3 sm:grid-cols-2">
                <Field
                    label="Residual severity"
                    required
                    error={form.errors.residual_severity}
                >
                    <SelectInput
                        value={form.data.residual_severity}
                        onChange={(v) => form.setData('residual_severity', v)}
                        placeholder="Severity after controls"
                        options={SEVERITY_ORDER.map((s) => ({
                            value: s,
                            label: SEV[s].label,
                        }))}
                    />
                </Field>
                <Field
                    label="Residual likelihood"
                    required
                    error={form.errors.residual_likelihood}
                >
                    <SelectInput
                        value={form.data.residual_likelihood}
                        onChange={(v) => form.setData('residual_likelihood', v)}
                        placeholder="Likelihood after controls"
                        options={LIKELIHOOD_ORDER.map((l) => ({
                            value: l,
                            label: LIKELIHOOD_LABELS[l],
                        }))}
                    />
                </Field>
            </div>
            {residual ? (
                <div className="rounded-xl border border-border bg-muted/40 p-3 text-sm">
                    Residual risk after controls:{' '}
                    <span
                        className={`inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-medium ${riskChip(residual)}`}
                    >
                        {RISK[residual].label}
                    </span>
                </div>
            ) : (
                <InfoCard icon={Gauge} tone="info">
                    Set residual severity and likelihood to calculate the
                    residual risk after controls.
                </InfoCard>
            )}
            <PaneFooter
                onDone={onDone}
                submitLabel="Mark mitigated"
                processing={form.processing}
            />
        </form>
    );
}

function AddActionPane({ d, onDone }: { d: HazardDetail; onDone: () => void }) {
    const form = useForm<{
        title: string;
        action_type: string;
        assigned_to_user_id: string;
        due_date: string;
    }>({
        title: '',
        action_type: 'engineering',
        assigned_to_user_id: '',
        due_date: '',
    });
    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post(`/hazards/${d.id}/actions`, {
            preserveScroll: true,
            preserveState: true,
            onSuccess: onPaneSuccess(onDone),
        });
    };
    return (
        <form onSubmit={submit} className="flex flex-col gap-4">
            <StepHead
                icon={ListChecks}
                title="Add corrective action"
                blurb="Log a corrective action against this hazard. It becomes trackable in the corrective-action register."
            />
            <Field label="Action title" required error={form.errors.title}>
                <Input
                    value={form.data.title}
                    onChange={(e) => form.setData('title', e.target.value)}
                    placeholder="e.g. Replace threshold strip and re-seal vinyl"
                />
            </Field>
            <div className="grid gap-3 sm:grid-cols-2">
                <Field label="Control type" error={form.errors.action_type}>
                    <SelectInput
                        value={form.data.action_type}
                        onChange={(v) => form.setData('action_type', v)}
                        placeholder="Control type"
                        options={ACTION_TYPES}
                    />
                </Field>
                <Field label="Due date" error={form.errors.due_date}>
                    <Input
                        type="date"
                        value={form.data.due_date}
                        onChange={(e) =>
                            form.setData('due_date', e.target.value)
                        }
                    />
                </Field>
            </div>
            <Field label="Owner" error={form.errors.assigned_to_user_id}>
                <SelectInput
                    value={form.data.assigned_to_user_id}
                    onChange={(v) => form.setData('assigned_to_user_id', v)}
                    placeholder="Assign to"
                    options={staffOptions(d)}
                />
            </Field>
            <PaneFooter
                onDone={onDone}
                submitLabel="Add action"
                processing={form.processing}
            />
        </form>
    );
}

function CompleteActionPane({
    d,
    a,
    onDone,
}: {
    d: HazardDetail;
    a: HazardAction;
    onDone: () => void;
}) {
    const form = useForm<{ completion_notes: string }>({
        completion_notes: '',
    });
    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post(`/hazard-actions/${a.id}/complete`, {
            preserveScroll: true,
            preserveState: true,
            onSuccess: onPaneSuccess(onDone),
        });
    };
    return (
        <form onSubmit={submit} className="flex flex-col gap-4">
            <StepHead
                icon={CheckCircle2}
                title="Complete corrective action"
                blurb={`${a.title} — record completion notes and mark it done.`}
            />
            <InfoCard icon={CheckCircle2} tone="info">
                Marks this corrective action completed, recorded against you in
                the audit trail. Once every action is complete the hazard can be
                closed.
            </InfoCard>
            <Field
                label="Completion notes"
                error={form.errors.completion_notes}
            >
                <Textarea
                    rows={3}
                    value={form.data.completion_notes}
                    onChange={(e) =>
                        form.setData('completion_notes', e.target.value)
                    }
                    placeholder="What was done to complete this action?"
                />
            </Field>
            <PaneFooter
                onDone={onDone}
                submitLabel="Mark complete"
                processing={form.processing}
            />
        </form>
    );
}

function ReviewPane({ d, onDone }: { d: HazardDetail; onDone: () => void }) {
    const form = useForm<{ note: string }>({ note: '' });
    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post(`/hazards/${d.id}/review`, {
            preserveScroll: true,
            onSuccess: onPaneSuccess(onDone),
        });
    };
    return (
        <form onSubmit={submit} className="flex flex-col gap-4">
            <StepHead
                icon={ClipboardCheck}
                title="Record review"
                blurb="Capture a review note — a periodic check, control-effectiveness review, or sign-off. It is logged to the audit trail."
            />
            <Field label="Review notes" required error={form.errors.note}>
                <Textarea
                    rows={4}
                    value={form.data.note}
                    onChange={(e) => form.setData('note', e.target.value)}
                    placeholder="What was reviewed and what did you find?"
                />
            </Field>
            <PaneFooter
                onDone={onDone}
                submitLabel="Record review"
                processing={form.processing}
            />
        </form>
    );
}

function ClosePane({ d, onDone }: { d: HazardDetail; onDone: () => void }) {
    const gate = d.close_gate;
    const blocked = (gate?.blockers.length ?? 0) > 0;
    const form = useForm<{
        resolution_summary: string;
        resolution_evidence: File[];
    }>({ resolution_summary: '', resolution_evidence: [] });
    const addFiles = (files: File[]) =>
        form.setData('resolution_evidence', [
            ...form.data.resolution_evidence,
            ...files,
        ]);
    const removeFile = (i: number) =>
        form.setData(
            'resolution_evidence',
            form.data.resolution_evidence.filter((_, idx) => idx !== i),
        );
    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post(`/hazards/${d.id}/close`, {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: onPaneSuccess(onDone),
        });
    };
    return (
        <form onSubmit={submit} className="flex flex-col gap-4">
            <StepHead
                icon={CheckCircle2}
                title="Close hazard"
                blurb="Closing requires a resolution summary. Outstanding corrective actions should be completed first."
            />
            {/* eslint-disable-next-line no-restricted-syntax -- closure gate checklist surface */}
            <div className="flex flex-col gap-2 rounded-xl border border-border bg-card/70 p-3">
                <GateRow ok label="Hazard reviewed" />
                <GateRow
                    ok={gate?.actions_ok ?? true}
                    label={
                        (gate?.actions_ok ?? true)
                            ? 'All corrective actions completed'
                            : (gate?.blockers[0] ??
                              'Corrective actions outstanding')
                    }
                />
            </div>
            {blocked ? (
                <InfoCard icon={AlertTriangle} tone="warn">
                    You can still close this hazard, but outstanding corrective
                    actions remain open in the register.
                </InfoCard>
            ) : null}
            <Field
                label="Resolution summary"
                required
                error={form.errors.resolution_summary}
            >
                <Textarea
                    rows={4}
                    value={form.data.resolution_summary}
                    onChange={(e) =>
                        form.setData('resolution_summary', e.target.value)
                    }
                    placeholder="How was the hazard resolved? What controls are now in place and verified?"
                />
            </Field>
            <Field
                label="Resolution evidence"
                hint="optional"
                error={form.errors.resolution_evidence as unknown as string}
            >
                <FileDropzone
                    onFiles={addFiles}
                    hint="Proof the hazard is resolved — photos or documents"
                />
                {form.data.resolution_evidence.length ? (
                    <div className="mt-2 grid gap-2">
                        {form.data.resolution_evidence.map((f, i) => (
                            <StagedFileCard
                                key={i}
                                file={f}
                                onRemove={() => removeFile(i)}
                            />
                        ))}
                    </div>
                ) : null}
            </Field>
            <PaneFooter
                onDone={onDone}
                submitLabel="Close hazard"
                processing={form.processing}
                danger
            />
        </form>
    );
}
