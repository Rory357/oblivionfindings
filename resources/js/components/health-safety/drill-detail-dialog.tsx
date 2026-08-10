/* eslint-disable no-restricted-syntax -- bespoke detail-modal surfaces (stat tiles,
 * finding cards, dashed add-buttons, selector chips) are intentional custom layouts on
 * semantic tokens, mirroring event-detail-dialog.tsx + the wizard primitives. */
/* Emergency Drill detail — the over-the-list governance modal. Built on WizardShell
 * (read-only chrome: section rail = "steps", Step X of Y header, footer Options bar)
 * exactly like event-detail-dialog.tsx. Write workflows replace the body as panes
 * (Add-Client idiom via the wizard primitives). Premium evidence upload lives in the
 * Evidence section. Schedule/Complete are separate full wizards launched upward. */
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { AttachmentUploader } from '@/components/ui/file-dropzone';
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
import {
    CHIP,
    DOT,
    FINDING_STATUS_META,
    FINDING_TYPE_LABEL,
    ICON_TEXT,
    SEVERITY_TONE,
    fmtDateFull,
    fmtDateTime,
    fmtEvacTime,
    formatFileSize,
    localToUtcIso,
    outcomeMeta,
    statusMeta,
    titleCase,
    typeMeta,
    type DrillDetail,
    type DrillFinding,
} from '@/pages/health-safety/drills/shared';
import type { Page } from '@inertiajs/core';
import { Link, router, useForm } from '@inertiajs/react';
import {
    AlertTriangle,
    CalendarClock,
    Check,
    CheckCircle2,
    ClipboardList,
    Download,
    ExternalLink,
    EyeOff,
    FileText,
    History,
    Info,
    Paperclip,
    Pencil,
    Play,
    Plus,
    ShieldAlert,
    Timer,
    Trash2,
    UserPlus,
    Users,
    X,
    XCircle,
    type LucideIcon,
} from 'lucide-react';
import { useEffect, useState, type ComponentType, type FormEvent } from 'react';

export type DrillSectionKey =
    | 'overview'
    | 'run'
    | 'participants'
    | 'findings'
    | 'history'
    | 'evidence';
export type DrillActionKey =
    | 'edit'
    | 'cancel'
    | 'add_participant'
    | 'add_finding';

const TIMELINE_ICONS: Record<string, LucideIcon> = {
    plus: Plus,
    play: Play,
    'check-circle-2': CheckCircle2,
    'x-circle': XCircle,
    'clipboard-list': ClipboardList,
    'shield-alert': ShieldAlert,
};

type ActivePane =
    | { kind: 'edit' }
    | { kind: 'cancel' }
    | { kind: 'add_participant' }
    | { kind: 'add_finding' }
    | { kind: 'resolve_finding'; findingId: number };

function paneFromAction(action: DrillActionKey | null): ActivePane | null {
    switch (action) {
        case 'edit':
            return { kind: 'edit' };
        case 'cancel':
            return { kind: 'cancel' };
        case 'add_participant':
            return { kind: 'add_participant' };
        case 'add_finding':
            return { kind: 'add_finding' };
        default:
            return null;
    }
}

export function DrillDetailDialog({
    detail,
    open,
    onClose,
    initialSection = 'overview',
    initialAction = null,
    onLaunchComplete,
}: {
    detail: DrillDetail;
    open: boolean;
    onClose: () => void;
    initialSection?: DrillSectionKey;
    initialAction?: DrillActionKey | null;
    /** Launch the standalone Complete wizard (in_progress → completed). */
    onLaunchComplete?: (id: number) => void;
}) {
    const d = detail;
    const [section, setSection] = useState<DrillSectionKey>(initialSection);
    const [pane, setPane] = useState<ActivePane | null>(
        paneFromAction(initialAction),
    );

    // Re-sync derived section/pane when the register re-targets the same open drill.
    useEffect(() => {
        setSection(initialSection);
        setPane(paneFromAction(initialAction));
        // eslint-disable-next-line react-hooks/exhaustive-deps -- sync only on incoming prop-value changes
    }, [initialSection, initialAction, d.id]);

    const type = typeMeta(d.drill_type);
    const status = statusMeta(d.status);
    const outcome = outcomeMeta(d.outcome);
    const openFindings = d.findings.filter(
        (f) => f.status !== 'resolved' && f.status !== 'closed',
    ).length;
    const canAct =
        d.can.manage && d.status !== 'completed' && d.status !== 'cancelled';

    const SECTIONS: {
        key: DrillSectionKey;
        label: string;
        blurb: string;
        icon: ComponentType<{ className?: string }>;
    }[] = [
        {
            key: 'overview',
            label: 'Overview',
            blurb: 'Scenario & origin',
            icon: FileText,
        },
        {
            key: 'run',
            label: 'Run & timings',
            blurb: 'Timings & roll-call',
            icon: Timer,
        },
        {
            key: 'participants',
            label: 'Participants',
            blurb: `${d.participants.length} ${d.participants.length === 1 ? 'person' : 'people'}`,
            icon: Users,
        },
        {
            key: 'findings',
            label: 'Findings',
            blurb: openFindings > 0 ? `${openFindings} open` : 'none open',
            icon: ClipboardList,
        },
        {
            key: 'evidence',
            label: 'Evidence',
            blurb: `${d.attachments.length} file${d.attachments.length === 1 ? '' : 's'}`,
            icon: Paperclip,
        },
        {
            key: 'history',
            label: 'History',
            blurb: 'Audit trail',
            icon: History,
        },
    ];
    const stepIndex = Math.max(
        0,
        SECTIONS.findIndex((s) => s.key === section),
    );

    const footerStart = (
        <div className="flex flex-wrap items-center gap-2 text-xs">
            <span
                className={`inline-flex items-center gap-1 rounded-full px-2 py-0.5 font-medium ${CHIP[type.tone]}`}
            >
                <span
                    className={`h-1.5 w-1.5 rounded-full ${DOT[type.tone]}`}
                />{' '}
                {type.label}
            </span>
            <span
                className={`inline-flex items-center gap-1 rounded-full px-2 py-0.5 font-medium ${CHIP[status.tone]}`}
            >
                <status.icon className="h-3 w-3" /> {status.label}
            </span>
            {d.is_unannounced ? (
                <span
                    className="inline-flex items-center gap-1 rounded-full bg-muted px-2 py-0.5 font-medium text-muted-foreground"
                    title="Unannounced drill"
                >
                    <EyeOff className="h-3 w-3" /> Unannounced
                </span>
            ) : null}
        </div>
    );

    const footerEnd = pane ? null : (
        <div className="flex flex-wrap items-center gap-2">
            <Link
                href={`/health-safety/drills/${d.id}`}
                className="inline-flex items-center gap-1.5 rounded-lg px-3 py-2 text-sm font-medium text-muted-foreground transition-colors hover:bg-muted"
            >
                <ExternalLink className="h-4 w-4" /> Open full page
            </Link>
            {canAct && d.status === 'scheduled' ? (
                <>
                    <Button
                        size="sm"
                        onClick={() =>
                            router.post(
                                `/health-safety/drills/${d.id}/start`,
                                {},
                                { preserveScroll: true },
                            )
                        }
                    >
                        <Play className="mr-1.5 h-4 w-4" /> Start drill
                    </Button>
                    <Button
                        size="sm"
                        variant="outline"
                        onClick={() => setPane({ kind: 'edit' })}
                    >
                        <Pencil className="mr-1.5 h-4 w-4" /> Edit
                    </Button>
                    <Button
                        size="sm"
                        variant="outline"
                        onClick={() => setPane({ kind: 'cancel' })}
                        className="border-status-critical/40 text-status-critical hover:text-status-critical"
                    >
                        <XCircle className="mr-1.5 h-4 w-4" /> Cancel
                    </Button>
                </>
            ) : null}
            {canAct && d.status === 'in_progress' ? (
                <>
                    <Button size="sm" onClick={() => onLaunchComplete?.(d.id)}>
                        <CheckCircle2 className="mr-1.5 h-4 w-4" /> Complete
                        drill
                    </Button>
                    <Button
                        size="sm"
                        variant="outline"
                        onClick={() => setPane({ kind: 'add_finding' })}
                    >
                        <ClipboardList className="mr-1.5 h-4 w-4" /> Add finding
                    </Button>
                </>
            ) : null}
            {d.can.manage &&
            (d.status === 'completed' || d.status === 'cancelled') ? (
                <Button
                    size="sm"
                    variant="outline"
                    onClick={() => setPane({ kind: 'add_finding' })}
                >
                    <Plus className="mr-1.5 h-4 w-4" /> Add finding
                </Button>
            ) : null}
        </div>
    );

    return (
        <WizardShell
            open={open}
            onClose={onClose}
            title={`Drill ${d.reference}`}
            description={`${type.label} — ${status.label}`}
            railIcon={type.icon}
            railTitle={d.reference}
            railSub={`${type.label} · ${status.label}`}
            steps={SECTIONS as readonly WizardStep[]}
            stepIndex={stepIndex}
            onStepClick={(i) => setSection(SECTIONS[i].key)}
            pct={null}
            footerStart={footerStart}
            footerEnd={footerEnd}
        >
            {pane ? (
                <PaneRenderer pane={pane} d={d} onDone={() => setPane(null)} />
            ) : (
                <>
                    {section === 'overview' ? <OverviewSection d={d} /> : null}
                    {section === 'run' ? <RunSection d={d} /> : null}
                    {section === 'participants' ? (
                        <ParticipantsSection
                            d={d}
                            onAdd={() => setPane({ kind: 'add_participant' })}
                        />
                    ) : null}
                    {section === 'findings' ? (
                        <FindingsSection
                            d={d}
                            onAdd={() => setPane({ kind: 'add_finding' })}
                            onResolve={(findingId) =>
                                setPane({ kind: 'resolve_finding', findingId })
                            }
                        />
                    ) : null}
                    {section === 'evidence' ? <EvidenceSection d={d} /> : null}
                    {section === 'history' ? <HistorySection d={d} /> : null}
                </>
            )}
        </WizardShell>
    );
}

/* ------------------------------------------------------------------ */
/*  Sections                                                           */
/* ------------------------------------------------------------------ */

function OverviewSection({ d }: { d: DrillDetail }) {
    return (
        <div className="flex flex-col gap-4">
            <div className="grid gap-4 sm:grid-cols-2">
                <div className="rounded-xl border border-border bg-card/70 p-4">
                    <div className="mb-2 text-[11px] font-bold tracking-wide text-muted-foreground uppercase">
                        Scenario
                    </div>
                    <p className="text-sm leading-relaxed whitespace-pre-wrap text-foreground">
                        {d.scenario_description ||
                            'No scenario brief recorded.'}
                    </p>
                </div>
                <div className="rounded-xl border border-border bg-card/70 p-4">
                    <ReviewRow
                        label="Scheduled"
                        value={fmtDateTime(d.scheduled_at)}
                    />
                    <ReviewRow
                        label="Drill coordinator"
                        value={d.coordinator_name}
                    />
                    <ReviewRow
                        label="Site"
                        value={d.site ? d.site.name : null}
                    />
                    <ReviewRow
                        label="Evacuation scheme"
                        value={d.evacuation_scheme}
                    />
                    <ReviewRow
                        label="Assembly point"
                        value={d.assembly_point}
                    />
                    <ReviewRow
                        label="Unannounced"
                        value={d.is_unannounced ? 'Yes' : 'No'}
                    />
                </div>
            </div>

            {d.hs_event ? (
                <Link href={d.hs_event.url} className="block">
                    <InfoCard icon={ShieldAlert} tone="warn">
                        A drill_failure safety event (
                        {d.hs_event.reference_number}) was raised from this
                        drill —{' '}
                        <span className="font-semibold underline">
                            view in Health &amp; Safety
                        </span>
                        .
                    </InfoCard>
                </Link>
            ) : (
                <InfoCard icon={Info} tone="info">
                    Completing this drill records the evacuation time and
                    roll-call, and fires the EmergencyDrillObserver — a
                    non-passing outcome raises a drill_failure safety event and
                    a Control Room signal automatically.
                </InfoCard>
            )}
        </div>
    );
}

function RunSection({ d }: { d: DrillDetail }) {
    const outcome = outcomeMeta(d.outcome);
    const checks = [
        {
            label: 'All residents evacuated',
            ok:
                d.residents_evacuated != null && d.total_participants != null
                    ? d.residents_evacuated >= (d.total_participants ?? 0)
                    : d.roll_call_completed,
        },
        { label: 'Assembly point reached', ok: d.assembly_point_reached },
        { label: 'Roll-call completed', ok: d.roll_call_completed },
        { label: 'All areas checked', ok: d.all_areas_checked },
    ];

    if (d.status !== 'completed') {
        return (
            <EmptyState
                icon={Timer}
                title="Not run yet"
                blurb="Timings and the roll-call appear here once the drill is completed."
            />
        );
    }

    return (
        <div className="flex flex-col gap-4">
            <div className="grid gap-3 sm:grid-cols-3">
                <StatTile
                    label="Evacuation time"
                    value={fmtEvacTime(d.evacuation_time_seconds)}
                    icon={Timer}
                />
                <StatTile
                    label="Total duration"
                    value={
                        d.duration_minutes != null
                            ? `${d.duration_minutes} min`
                            : '—'
                    }
                    icon={CalendarClock}
                />
                <StatTile
                    label="Outcome"
                    value={outcome?.label ?? '—'}
                    icon={CheckCircle2}
                    valueClass={outcome ? ICON_TEXT[outcome.tone] : undefined}
                />
            </div>
            <div className="rounded-xl border border-border bg-card/70 p-4">
                <div className="mb-3 text-[11px] font-bold tracking-wide text-muted-foreground uppercase">
                    Roll-call checklist
                </div>
                <div className="grid gap-2 sm:grid-cols-2">
                    {checks.map((c) => (
                        <div
                            key={c.label}
                            className="flex items-center gap-2 text-sm"
                        >
                            {c.ok ? (
                                <CheckCircle2 className="h-4 w-4 shrink-0 text-status-success" />
                            ) : (
                                <AlertTriangle className="h-4 w-4 shrink-0 text-status-warning" />
                            )}
                            {c.label}
                        </div>
                    ))}
                </div>
                {d.total_participants != null ||
                d.residents_evacuated != null ? (
                    <div className="mt-3 flex flex-wrap gap-4 border-t border-border pt-3 text-sm text-muted-foreground">
                        {d.total_participants != null ? (
                            <span>
                                Total participants:{' '}
                                <span className="font-medium text-foreground">
                                    {d.total_participants}
                                </span>
                            </span>
                        ) : null}
                        {d.residents_evacuated != null ? (
                            <span>
                                Residents evacuated:{' '}
                                <span className="font-medium text-foreground">
                                    {d.residents_evacuated}
                                </span>
                            </span>
                        ) : null}
                        {d.weather_conditions ? (
                            <span>
                                Weather:{' '}
                                <span className="font-medium text-foreground">
                                    {d.weather_conditions}
                                </span>
                            </span>
                        ) : null}
                    </div>
                ) : null}
            </div>
            {d.improvements_identified ? (
                <div className="rounded-xl border border-border bg-card/70 p-4">
                    <div className="mb-2 text-[11px] font-bold tracking-wide text-muted-foreground uppercase">
                        Improvements identified
                    </div>
                    <p className="text-sm leading-relaxed whitespace-pre-wrap">
                        {d.improvements_identified}
                    </p>
                </div>
            ) : null}
            {d.observer_notes ? (
                <div className="rounded-xl border border-border bg-card/70 p-4">
                    <div className="mb-2 text-[11px] font-bold tracking-wide text-muted-foreground uppercase">
                        Observer notes
                    </div>
                    <p className="text-sm leading-relaxed whitespace-pre-wrap">
                        {d.observer_notes}
                    </p>
                </div>
            ) : null}
        </div>
    );
}

function ParticipantsSection({
    d,
    onAdd,
}: {
    d: DrillDetail;
    onAdd: () => void;
}) {
    return (
        <div className="flex flex-col gap-3">
            {d.participants.length === 0 ? (
                <EmptyState
                    icon={Users}
                    title="No participants yet"
                    blurb="Add the coordinator, wardens and attendees to build the roll-call."
                />
            ) : (
                <div className="flex flex-col gap-2">
                    {d.participants.map((p) => (
                        <div
                            key={p.id}
                            className="flex items-center gap-3 rounded-xl border border-border bg-card/70 p-3"
                        >
                            <span className="grid h-8 w-8 shrink-0 place-items-center rounded-full bg-primary/10 text-xs font-bold text-primary">
                                {initials(p.name)}
                            </span>
                            <div className="min-w-0 flex-1">
                                <div className="truncate text-sm font-semibold">
                                    {p.name}
                                </div>
                                {p.role ? (
                                    <div className="text-xs text-muted-foreground">
                                        {titleCase(p.role)}
                                    </div>
                                ) : null}
                            </div>
                            <span
                                className={`inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium ${p.attended ? CHIP.success : CHIP.neutral}`}
                            >
                                {p.attended ? (
                                    <Check className="h-3 w-3" />
                                ) : (
                                    <X className="h-3 w-3" />
                                )}
                                {p.attended ? 'Present' : 'Not marked'}
                            </span>
                        </div>
                    ))}
                </div>
            )}
            {d.can.manage ? (
                <button
                    type="button"
                    onClick={onAdd}
                    className="flex items-center justify-center gap-1.5 rounded-xl border border-dashed border-border py-3 text-sm font-semibold text-primary transition-colors hover:bg-primary/5"
                >
                    <UserPlus className="h-4 w-4" /> Add participant
                </button>
            ) : null}
        </div>
    );
}

function FindingsSection({
    d,
    onAdd,
    onResolve,
}: {
    d: DrillDetail;
    onAdd: () => void;
    onResolve: (id: number) => void;
}) {
    return (
        <div className="flex flex-col gap-3">
            {d.findings.length === 0 ? (
                <EmptyState
                    icon={ClipboardList}
                    title="No findings logged"
                    blurb="Record observations, non-conformances and improvements from the drill."
                />
            ) : (
                d.findings.map((f) => (
                    <FindingCard
                        key={f.id}
                        f={f}
                        canManage={d.can.manage}
                        onResolve={() => onResolve(f.id)}
                    />
                ))
            )}
            {d.can.manage ? (
                <button
                    type="button"
                    onClick={onAdd}
                    className="flex items-center justify-center gap-1.5 rounded-xl border border-dashed border-border py-3 text-sm font-semibold text-primary transition-colors hover:bg-primary/5"
                >
                    <Plus className="h-4 w-4" /> Add finding
                </button>
            ) : null}
        </div>
    );
}

function FindingCard({
    f,
    canManage,
    onResolve,
}: {
    f: DrillFinding;
    canManage: boolean;
    onResolve: () => void;
}) {
    const sevTone = f.severity
        ? (SEVERITY_TONE[f.severity] ?? 'neutral')
        : 'neutral';
    const st = FINDING_STATUS_META[f.status] ?? FINDING_STATUS_META.open;
    const resolved = f.status === 'resolved' || f.status === 'closed';
    return (
        <div className="rounded-xl border border-border bg-card/70 p-4">
            <div className="flex flex-wrap items-center gap-2">
                {f.severity ? (
                    <span
                        className={`inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ${CHIP[sevTone]}`}
                    >
                        {titleCase(f.severity)}
                    </span>
                ) : null}
                <span className="rounded-full border border-border px-2 py-0.5 text-xs font-medium text-muted-foreground">
                    {FINDING_TYPE_LABEL[f.finding_type] ??
                        titleCase(f.finding_type)}
                </span>
                <span
                    className={`inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ${CHIP[st.tone]}`}
                >
                    {st.label}
                </span>
                {f.is_overdue ? (
                    <span
                        className={`inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium ${CHIP.critical}`}
                    >
                        <AlertTriangle className="h-3 w-3" /> Overdue
                    </span>
                ) : null}
            </div>
            <p className="mt-2 text-sm">{f.description}</p>
            {f.corrective_action ? (
                <div className="mt-2 text-xs">
                    <span className="text-muted-foreground">
                        Corrective action:{' '}
                    </span>
                    {f.corrective_action}
                </div>
            ) : null}
            <div className="mt-2 flex flex-wrap items-center gap-3 text-xs text-muted-foreground">
                {f.assignee_name ? (
                    <span>Assigned to {f.assignee_name}</span>
                ) : null}
                {f.due_date ? <span>Due {fmtDateFull(f.due_date)}</span> : null}
                {f.resolution_notes ? (
                    <span className="text-status-success">
                        Resolved: {f.resolution_notes}
                    </span>
                ) : null}
            </div>
            {canManage && !resolved ? (
                <div className="mt-3">
                    <Button size="sm" variant="outline" onClick={onResolve}>
                        <Check className="mr-1.5 h-3.5 w-3.5" /> Resolve
                    </Button>
                </div>
            ) : null}
        </div>
    );
}

function EvidenceSection({ d }: { d: DrillDetail }) {
    return (
        <div className="flex flex-col gap-4">
            <p className="text-sm text-muted-foreground">
                Sign-in sheets, assembly-point / roll-call photos and the FENZ
                evacuation-scheme report. Up to 20&nbsp;MB each.
            </p>
            {d.attachments.length === 0 ? (
                <EmptyState
                    icon={Paperclip}
                    title="No evidence yet"
                    blurb="Attach the drill's sign-in sheet, photos or report."
                />
            ) : (
                <div className="flex flex-col gap-2">
                    {d.attachments.map((a) => (
                        <div
                            key={a.id}
                            className="flex items-center gap-3 rounded-xl border border-border bg-card/70 p-3"
                        >
                            <span className="grid h-9 w-9 shrink-0 place-items-center rounded-lg bg-muted text-muted-foreground">
                                <Paperclip className="h-4 w-4" />
                            </span>
                            <div className="min-w-0 flex-1">
                                <div className="truncate text-sm font-medium">
                                    {a.original_name}
                                </div>
                                <div className="text-xs text-muted-foreground">
                                    {formatFileSize(a.size)}
                                    {a.uploaded_by_name
                                        ? ` · ${a.uploaded_by_name}`
                                        : ''}
                                    {a.notes ? ` · ${a.notes}` : ''}
                                </div>
                            </div>
                            <a
                                href={a.url}
                                className="grid h-8 w-8 place-items-center rounded-md text-muted-foreground hover:bg-muted"
                                title="Download"
                            >
                                <Download className="h-4 w-4" />
                            </a>
                            {d.can.manage ? (
                                <button
                                    type="button"
                                    onClick={() =>
                                        router.delete(
                                            `/health-safety/drills/${d.id}/attachments/${a.id}`,
                                            {
                                                preserveScroll: true,
                                                preserveState: true,
                                            },
                                        )
                                    }
                                    className="grid h-8 w-8 place-items-center rounded-md text-muted-foreground hover:bg-status-critical-bg hover:text-status-critical"
                                    title="Remove"
                                >
                                    <Trash2 className="h-4 w-4" />
                                </button>
                            ) : null}
                        </div>
                    ))}
                </div>
            )}
            {d.can.manage ? (
                <AttachmentUploader
                    endpoint={`/health-safety/drills/${d.id}/attachments`}
                    noteField="notes"
                    accept="image/*,.pdf,.doc,.docx"
                    hint="Images, PDF or Word — up to 20 MB each"
                />
            ) : null}
        </div>
    );
}

function HistorySection({ d }: { d: DrillDetail }) {
    if (d.timeline.length === 0) {
        return (
            <EmptyState
                icon={History}
                title="No history yet"
                blurb="Lifecycle events appear here as the drill progresses."
            />
        );
    }
    return (
        <div className="rounded-xl border border-border bg-card/70 p-4">
            <ol className="flex flex-col gap-4">
                {d.timeline.map((t) => {
                    const Icon = TIMELINE_ICONS[t.icon] ?? Info;
                    return (
                        <li key={t.key} className="flex gap-3">
                            <span className="grid h-7 w-7 shrink-0 place-items-center rounded-full bg-primary/10 text-primary">
                                <Icon className="h-3.5 w-3.5" />
                            </span>
                            <div className="min-w-0">
                                <div className="text-sm font-semibold">
                                    {t.label}
                                </div>
                                <div className="text-xs text-muted-foreground">
                                    {[t.at ? fmtDateTime(t.at) : null, t.meta]
                                        .filter(Boolean)
                                        .join(' · ') || '—'}
                                </div>
                            </div>
                        </li>
                    );
                })}
            </ol>
        </div>
    );
}

/* ------------------------------------------------------------------ */
/*  Write panes (Add-Client idiom)                                     */
/* ------------------------------------------------------------------ */

function PaneRenderer({
    pane,
    d,
    onDone,
}: {
    pane: ActivePane;
    d: DrillDetail;
    onDone: () => void;
}) {
    switch (pane.kind) {
        case 'edit':
            return <EditPane d={d} onDone={onDone} />;
        case 'cancel':
            return <CancelPane d={d} onDone={onDone} />;
        case 'add_participant':
            return <AddParticipantPane d={d} onDone={onDone} />;
        case 'add_finding':
            return <AddFindingPane d={d} onDone={onDone} />;
        case 'resolve_finding':
            return (
                <ResolveFindingPane
                    d={d}
                    findingId={pane.findingId}
                    onDone={onDone}
                />
            );
    }
}

function paneSuccess(onDone: () => void) {
    return (page: Page) => {
        if (!(page.props as { flash?: { error?: string } }).flash?.error)
            onDone();
    };
}

function toLocalInput(iso: string | null): string {
    if (!iso) return '';
    const d = new Date(iso);
    const pad = (n: number) => String(n).padStart(2, '0');
    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
}

function EditPane({ d, onDone }: { d: DrillDetail; onDone: () => void }) {
    const form = useForm({
        title: d.title ?? '',
        drill_type: d.drill_type ?? 'fire_evacuation',
        scheduled_at: toLocalInput(d.scheduled_at),
        scenario_description: d.scenario_description ?? '',
        assembly_point: d.assembly_point ?? '',
        evacuation_scheme: d.evacuation_scheme ?? '',
        is_unannounced: d.is_unannounced,
    });

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.transform((data) => ({
            ...data,
            scheduled_at: localToUtcIso(data.scheduled_at),
        }));
        form.put(`/health-safety/drills/${d.id}`, {
            preserveScroll: true,
            onSuccess: paneSuccess(onDone),
        });
    };

    return (
        <form onSubmit={submit} className="flex flex-col gap-4">
            <StepHead
                icon={Pencil}
                title="Edit / reschedule"
                blurb="Update the drill's scenario, type or schedule."
            />
            <div className="grid gap-4 sm:grid-cols-2">
                <Field
                    label="Drill title"
                    required
                    error={form.errors.title}
                    span
                >
                    <Input
                        value={form.data.title}
                        onChange={(e) => form.setData('title', e.target.value)}
                    />
                </Field>
                <Field
                    label="Drill type"
                    required
                    error={form.errors.drill_type}
                >
                    <SelectInput
                        value={form.data.drill_type}
                        onChange={(v) => form.setData('drill_type', v)}
                        placeholder="Select type"
                        options={[
                            {
                                value: 'fire_evacuation',
                                label: 'Fire evacuation',
                            },
                            { value: 'earthquake', label: 'Earthquake' },
                            { value: 'lockdown', label: 'Lockdown' },
                            { value: 'tsunami', label: 'Tsunami' },
                            {
                                value: 'chemical_spill',
                                label: 'Chemical spill',
                            },
                            {
                                value: 'medical_emergency',
                                label: 'Medical emergency',
                            },
                            { value: 'other', label: 'Other' },
                        ]}
                    />
                </Field>
                <Field
                    label="Scheduled date & time"
                    required
                    error={form.errors.scheduled_at}
                >
                    <Input
                        type="datetime-local"
                        value={form.data.scheduled_at}
                        onChange={(e) =>
                            form.setData('scheduled_at', e.target.value)
                        }
                    />
                </Field>
                <Field
                    label="Assembly point"
                    error={form.errors.assembly_point}
                >
                    <Input
                        value={form.data.assembly_point}
                        onChange={(e) =>
                            form.setData('assembly_point', e.target.value)
                        }
                        placeholder="e.g. Front car park"
                    />
                </Field>
                <Field
                    label="Evacuation scheme"
                    error={form.errors.evacuation_scheme}
                >
                    <Input
                        value={form.data.evacuation_scheme}
                        onChange={(e) =>
                            form.setData('evacuation_scheme', e.target.value)
                        }
                        placeholder="e.g. FENZ · Type 4"
                    />
                </Field>
                <Field
                    label="Scenario brief"
                    error={form.errors.scenario_description}
                    span
                >
                    <Textarea
                        rows={3}
                        value={form.data.scenario_description}
                        onChange={(e) =>
                            form.setData('scenario_description', e.target.value)
                        }
                    />
                </Field>
                <label className="col-span-full flex items-center gap-2 text-sm">
                    <Checkbox
                        checked={form.data.is_unannounced}
                        onCheckedChange={(v) =>
                            form.setData('is_unannounced', !!v)
                        }
                    />
                    Unannounced drill (do not notify site staff in advance)
                </label>
            </div>
            <PaneFooter
                onDone={onDone}
                processing={form.processing}
                submitLabel="Save changes"
            />
        </form>
    );
}

function CancelPane({ d, onDone }: { d: DrillDetail; onDone: () => void }) {
    const form = useForm({ reason: '' });
    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post(`/health-safety/drills/${d.id}/cancel`, {
            preserveScroll: true,
            onSuccess: paneSuccess(onDone),
        });
    };
    return (
        <form onSubmit={submit} className="flex flex-col gap-4">
            <StepHead
                icon={XCircle}
                title="Cancel drill"
                blurb="Mark this drill as cancelled. You can record why for the audit trail."
            />
            <InfoCard icon={AlertTriangle} tone="warn">
                Cancelling stops this drill from appearing as scheduled or
                overdue. This cannot be undone.
            </InfoCard>
            <Field label="Reason" error={form.errors.reason}>
                <Textarea
                    rows={3}
                    value={form.data.reason}
                    onChange={(e) => form.setData('reason', e.target.value)}
                    placeholder="Why is this drill being cancelled?"
                />
            </Field>
            <PaneFooter
                onDone={onDone}
                processing={form.processing}
                submitLabel="Cancel drill"
                destructive
            />
        </form>
    );
}

function AddParticipantPane({
    d,
    onDone,
}: {
    d: DrillDetail;
    onDone: () => void;
}) {
    const form = useForm({
        user_id: '',
        role: 'participant',
        attended: false,
        notes: '',
    });
    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post(`/health-safety/drills/${d.id}/participants`, {
            preserveScroll: true,
            onSuccess: paneSuccess(onDone),
        });
    };
    return (
        <form onSubmit={submit} className="flex flex-col gap-4">
            <StepHead
                icon={UserPlus}
                title="Add participant"
                blurb="Add a person to the drill roll-call."
            />
            <div className="grid gap-4 sm:grid-cols-2">
                <Field
                    label="Staff member"
                    required
                    error={form.errors.user_id}
                >
                    <SelectInput
                        value={form.data.user_id}
                        onChange={(v) => form.setData('user_id', v)}
                        placeholder="Select staff"
                        options={d.assignable_staff.map((s) => ({
                            value: String(s.id),
                            label: s.name,
                        }))}
                    />
                </Field>
                <Field label="Role" error={form.errors.role}>
                    <SelectInput
                        value={form.data.role}
                        onChange={(v) => form.setData('role', v)}
                        placeholder="Role"
                        options={[
                            { value: 'participant', label: 'Participant' },
                            { value: 'observer', label: 'Observer' },
                            { value: 'warden', label: 'Fire warden' },
                            { value: 'first_aider', label: 'First aider' },
                            { value: 'coordinator', label: 'Coordinator' },
                        ]}
                    />
                </Field>
                <label className="col-span-full flex items-center gap-2 text-sm">
                    <Checkbox
                        checked={form.data.attended}
                        onCheckedChange={(v) => form.setData('attended', !!v)}
                    />
                    Attended / present
                </label>
            </div>
            <PaneFooter
                onDone={onDone}
                processing={form.processing}
                submitLabel="Add participant"
            />
        </form>
    );
}

function AddFindingPane({ d, onDone }: { d: DrillDetail; onDone: () => void }) {
    const form = useForm({
        finding_type: 'observation',
        severity: 'medium',
        description: '',
        corrective_action: '',
        assigned_to: '',
        due_date: '',
    });
    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post(`/health-safety/drills/${d.id}/findings`, {
            preserveScroll: true,
            onSuccess: paneSuccess(onDone),
        });
    };
    return (
        <form onSubmit={submit} className="flex flex-col gap-4">
            <StepHead
                icon={ClipboardList}
                title="Add finding"
                blurb="Record an observation, non-conformance or improvement."
            />
            <div className="grid gap-4 sm:grid-cols-2">
                <Field
                    label="Finding type"
                    required
                    error={form.errors.finding_type}
                >
                    <SelectInput
                        value={form.data.finding_type}
                        onChange={(v) => form.setData('finding_type', v)}
                        placeholder="Type"
                        options={[
                            { value: 'observation', label: 'Observation' },
                            {
                                value: 'non_conformance',
                                label: 'Non-conformance',
                            },
                            { value: 'improvement', label: 'Improvement' },
                            { value: 'positive', label: 'Positive' },
                        ]}
                    />
                </Field>
                <Field label="Severity" required error={form.errors.severity}>
                    <SelectInput
                        value={form.data.severity}
                        onChange={(v) => form.setData('severity', v)}
                        placeholder="Severity"
                        options={[
                            { value: 'low', label: 'Low' },
                            { value: 'medium', label: 'Medium' },
                            { value: 'high', label: 'High' },
                            { value: 'critical', label: 'Critical' },
                        ]}
                    />
                </Field>
                <Field
                    label="Description"
                    required
                    error={form.errors.description}
                    span
                >
                    <Textarea
                        rows={3}
                        value={form.data.description}
                        onChange={(e) =>
                            form.setData('description', e.target.value)
                        }
                    />
                </Field>
                <Field
                    label="Corrective action"
                    error={form.errors.corrective_action}
                    span
                >
                    <Textarea
                        rows={2}
                        value={form.data.corrective_action}
                        onChange={(e) =>
                            form.setData('corrective_action', e.target.value)
                        }
                    />
                </Field>
                <Field label="Assign to" error={form.errors.assigned_to}>
                    <SelectInput
                        value={form.data.assigned_to}
                        onChange={(v) => form.setData('assigned_to', v)}
                        placeholder="Unassigned"
                        options={d.assignable_staff.map((s) => ({
                            value: String(s.id),
                            label: s.name,
                        }))}
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
            <PaneFooter
                onDone={onDone}
                processing={form.processing}
                submitLabel="Record finding"
            />
        </form>
    );
}

function ResolveFindingPane({
    d,
    findingId,
    onDone,
}: {
    d: DrillDetail;
    findingId: number;
    onDone: () => void;
}) {
    const finding = d.findings.find((f) => f.id === findingId);
    const form = useForm({ resolution_notes: '' });
    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post(
            `/health-safety/drills/${d.id}/findings/${findingId}/resolve`,
            { preserveScroll: true, onSuccess: paneSuccess(onDone) },
        );
    };
    return (
        <form onSubmit={submit} className="flex flex-col gap-4">
            <StepHead
                icon={Check}
                title="Resolve finding"
                blurb="Mark this finding resolved and record how it was closed out."
            />
            {finding ? (
                <ReviewCard
                    icon={ClipboardList}
                    title={
                        FINDING_TYPE_LABEL[finding.finding_type] ?? 'Finding'
                    }
                >
                    <p className="text-sm">{finding.description}</p>
                </ReviewCard>
            ) : null}
            <Field
                label="Resolution notes"
                error={form.errors.resolution_notes}
            >
                <Textarea
                    rows={3}
                    value={form.data.resolution_notes}
                    onChange={(e) =>
                        form.setData('resolution_notes', e.target.value)
                    }
                    placeholder="How was this finding resolved?"
                />
            </Field>
            <PaneFooter
                onDone={onDone}
                processing={form.processing}
                submitLabel="Resolve finding"
            />
        </form>
    );
}

/* ------------------------------------------------------------------ */
/*  Small shared bits                                                  */
/* ------------------------------------------------------------------ */

function PaneFooter({
    onDone,
    processing,
    submitLabel,
    destructive,
}: {
    onDone: () => void;
    processing: boolean;
    submitLabel: string;
    destructive?: boolean;
}) {
    return (
        <div className="flex justify-end gap-2">
            <Button type="button" variant="outline" onClick={onDone}>
                Cancel
            </Button>
            <Button
                type="submit"
                disabled={processing}
                variant={destructive ? 'destructive' : 'default'}
            >
                {submitLabel}
            </Button>
        </div>
    );
}

function StatTile({
    label,
    value,
    icon: Icon,
    valueClass,
}: {
    label: string;
    value: string;
    icon: LucideIcon;
    valueClass?: string;
}) {
    return (
        <div className="rounded-xl border border-border bg-card/70 p-4">
            <div className="mb-1 flex items-center gap-1.5 text-[11px] font-bold tracking-wide text-muted-foreground uppercase">
                <Icon className="h-3.5 w-3.5" /> {label}
            </div>
            <div className={`text-xl font-bold ${valueClass ?? ''}`}>
                {value}
            </div>
        </div>
    );
}

function EmptyState({
    icon: Icon,
    title,
    blurb,
}: {
    icon: LucideIcon;
    title: string;
    blurb: string;
}) {
    return (
        <div className="flex flex-col items-center justify-center gap-2 rounded-xl border border-dashed border-border px-6 py-10 text-center">
            <Icon className="h-8 w-8 text-muted-foreground" />
            <div className="text-sm font-semibold">{title}</div>
            <p className="max-w-sm text-xs text-muted-foreground">{blurb}</p>
        </div>
    );
}

function initials(name: string): string {
    const parts = name.trim().split(/\s+/).slice(0, 2);
    return parts.map((p) => p[0]?.toUpperCase() ?? '').join('') || 'NA';
}

export type { DrillDetail };
