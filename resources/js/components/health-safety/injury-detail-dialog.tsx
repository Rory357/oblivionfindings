/* eslint-disable no-restricted-syntax -- Custom detail-dialog chrome (KPI cards,
 * identity strip, lifecycle timeline, evidence gallery + inline link/icon/pill
 * controls), like wizard/shell.tsx and file-dropzone.tsx. Every colour is a
 * semantic design token. */
/* Injuries & RTW — detail-as-modal, built on the shared WizardShell (same family
 * as the create wizard + HsDetailDialog). Rail = sections; footer = contextual
 * lifecycle Options bar; in-body workflow panes host the feature-complete RTW /
 * capacity / modified-duty / ACC sub-modals; an Evidence section provides the
 * premium document upload. Supports a readOnly seam for embedding (HR profile). */
import { Button } from '@/components/ui/button';
import {
    FileDropzone,
    StagedFileCard,
    formatFileSize,
} from '@/components/ui/file-dropzone';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { Field, InfoCard, SelectInput } from '@/components/wizard/primitives';
import {
    ReviewCard,
    ReviewRow,
    WizardShell,
    type WizardStep,
} from '@/components/wizard/shell';
import { formatDateLong, formatRelative } from '@/lib/datetime';
import { TONE_BG } from '@/pages/health-safety/components/register-row-kit';
import { router, useForm } from '@inertiajs/react';
import {
    AlertTriangle,
    ArrowRightLeft,
    CheckCircle2,
    ClipboardList,
    Clock,
    Download,
    ExternalLink,
    FileText,
    HeartPulse,
    Paperclip,
    Pencil,
    Plus,
    ShieldAlert,
    Stethoscope,
    Trash2,
    X,
} from 'lucide-react';
import { useEffect, useState, type ReactNode } from 'react';
import {
    ATTACHMENT_KINDS,
    SEVERITY_TONE,
    STATUS_META,
    STATUS_ORDER,
    injuryTypeLabel,
    severityLabel,
    statusLabel,
    treatmentLabel,
} from './injury-constants';
import type {
    InjuryDetail,
    InjurySectionKey,
    RtwPlan,
    StaffOption,
} from './injury-types';

type Pane =
    | { kind: 'add_rtw' }
    | { kind: 'edit_rtw'; plan: RtwPlan }
    | { kind: 'add_duty'; planId: number }
    | { kind: 'add_capacity' }
    | { kind: 'acc' }
    | { kind: 'close' };

const SECTIONS: WizardStep[] = [
    {
        key: 'overview',
        label: 'Overview',
        blurb: 'Record summary',
        icon: FileText,
    },
    {
        key: 'rtw',
        label: 'RTW plans',
        blurb: 'Staged return',
        icon: ArrowRightLeft,
    },
    {
        key: 'capacity',
        label: 'Capacity',
        blurb: 'Assessments',
        icon: Stethoscope,
    },
    { key: 'evidence', label: 'Evidence', blurb: 'Documents', icon: Paperclip },
    { key: 'history', label: 'History', blurb: 'Audit log', icon: Clock },
];

const ACTION_TO_PANE: Record<string, Pane | undefined> = {
    add_rtw: { kind: 'add_rtw' },
    add_capacity: { kind: 'add_capacity' },
    acc: { kind: 'acc' },
};

export function InjuryDetailDialog({
    detail,
    open,
    onClose,
    staff,
    initialSection = 'overview',
    initialAction = null,
    onEdit,
    readOnly = false,
}: {
    detail: InjuryDetail;
    open: boolean;
    onClose: () => void;
    staff: StaffOption[];
    initialSection?: InjurySectionKey;
    initialAction?: string | null;
    onEdit?: (id: number) => void;
    readOnly?: boolean;
}) {
    const d = detail;
    const manage = d.can.manage && !readOnly;
    const sectionForAction = (a: string | null): InjurySectionKey =>
        a === 'add_rtw'
            ? 'rtw'
            : a === 'add_capacity'
              ? 'capacity'
              : a === 'acc'
                ? 'overview'
                : initialSection;

    const [section, setSection] = useState<InjurySectionKey>(
        sectionForAction(initialAction),
    );
    const [pane, setPane] = useState<Pane | null>(
        manage
            ? initialAction
                ? (ACTION_TO_PANE[initialAction] ?? null)
                : null
            : null,
    );

    // Re-target section/pane when the register re-opens the same (keyed) dialog.
    useEffect(() => {
        setSection(sectionForAction(initialAction));
        setPane(
            manage && initialAction
                ? (ACTION_TO_PANE[initialAction] ?? null)
                : null,
        );
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [initialSection, initialAction]);

    const stageIndex = Math.max(
        0,
        STATUS_ORDER.indexOf(d.status as (typeof STATUS_ORDER)[number]),
    );
    const lifecyclePct = Math.round(
        ((stageIndex + 1) / STATUS_ORDER.length) * 100,
    );
    const sm = STATUS_META[d.status] ?? STATUS_META.reported;

    const transition = (status: string) => {
        router.post(
            `/health-safety/injuries/${d.id}/status`,
            { status },
            { preserveScroll: true, preserveState: true },
        );
    };

    const footerStart = (
        <div className="flex items-center gap-2">
            <span
                className={`inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-[11px] font-semibold ${TONE_BG[SEVERITY_TONE[d.severity] ?? 'neutral']}`}
            >
                {severityLabel(d.severity)}
            </span>
            <span
                className={`inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-[11px] font-semibold ${sm.chip}`}
            >
                <span className={`h-1.5 w-1.5 rounded-full ${sm.dot}`} />{' '}
                {sm.label}
            </span>
        </div>
    );

    const footerEnd = pane ? null : (
        <div className="flex flex-wrap items-center justify-end gap-2">
            {manage ? (
                <>
                    {d.status === 'reported' ? (
                        <Button
                            size="sm"
                            variant="outline"
                            onClick={() => transition('under_treatment')}
                        >
                            <HeartPulse className="mr-1.5 h-3.5 w-3.5" /> Start
                            treatment
                        </Button>
                    ) : null}
                    {d.status === 'under_treatment' ? (
                        <Button
                            size="sm"
                            variant="outline"
                            onClick={() => transition('return_to_work')}
                        >
                            <ArrowRightLeft className="mr-1.5 h-3.5 w-3.5" />{' '}
                            Begin RTW
                        </Button>
                    ) : null}
                    {d.status === 'return_to_work' ||
                    d.status === 'under_treatment' ? (
                        <Button
                            size="sm"
                            variant="outline"
                            onClick={() => transition('recovered')}
                        >
                            <CheckCircle2 className="mr-1.5 h-3.5 w-3.5" /> Mark
                            recovered
                        </Button>
                    ) : null}
                    <Button
                        size="sm"
                        variant="outline"
                        onClick={() => setPane({ kind: 'acc' })}
                    >
                        {d.acc_claim_lodged ? 'Update ACC' : 'Lodge ACC'}
                    </Button>
                    {d.status !== 'closed' ? (
                        <Button
                            size="sm"
                            variant="outline"
                            className="text-status-critical"
                            onClick={() => setPane({ kind: 'close' })}
                        >
                            <X className="mr-1.5 h-3.5 w-3.5" /> Close
                        </Button>
                    ) : null}
                </>
            ) : null}
            <a
                href={`/health-safety/injuries/${d.id}`}
                className="inline-flex items-center gap-1.5 rounded-md border border-border px-2.5 py-1.5 text-[13px] font-medium hover:bg-muted"
            >
                <ExternalLink className="h-3.5 w-3.5" /> Open full page
            </a>
        </div>
    );

    return (
        <WizardShell
            open={open}
            onClose={onClose}
            title={`${d.reference} · ${d.worker?.name ?? 'Injury'}`}
            description={`${injuryTypeLabel(d.injury_type)} — ${statusLabel(d.status)}`}
            railIcon={HeartPulse}
            railTitle={d.reference}
            railSub={d.worker?.name ?? 'Injury record'}
            steps={SECTIONS}
            stepIndex={Math.max(
                0,
                SECTIONS.findIndex((s) => s.key === section),
            )}
            onStepClick={(i) => {
                setPane(null);
                setSection(SECTIONS[i].key as InjurySectionKey);
            }}
            pct={lifecyclePct}
            pctLabel={`Lifecycle · ${sm.label}`}
            footerStart={footerStart}
            footerEnd={footerEnd}
        >
            {/* Identity strip */}
            <div className="mb-5 flex flex-wrap items-center gap-3 rounded-xl border border-border bg-muted/30 p-4">
                <span className="grid h-11 w-11 shrink-0 place-items-center rounded-xl bg-primary/10 text-sm font-bold text-primary">
                    {(d.worker?.name ?? 'NA')
                        .split(' ')
                        .map((w) => w[0])
                        .slice(0, 2)
                        .join('')
                        .toUpperCase()}
                </span>
                <div className="min-w-0">
                    <div className="text-base font-bold">
                        {d.worker?.name ?? 'Unknown worker'}
                    </div>
                    <div className="truncate text-[13px] text-muted-foreground">
                        {injuryTypeLabel(d.injury_type)} ·{' '}
                        {d.body_part_affected ?? '—'} ·{' '}
                        {d.site?.name ?? 'No site'}
                    </div>
                </div>
            </div>

            {pane ? (
                <PaneRenderer
                    pane={pane}
                    detail={d}
                    staff={staff}
                    onDone={() => setPane(null)}
                />
            ) : (
                <>
                    {section === 'overview' ? (
                        <Overview d={d} manage={manage} onEdit={onEdit} />
                    ) : null}
                    {section === 'rtw' ? (
                        <RtwSection
                            d={d}
                            manage={manage}
                            onAddPlan={() => setPane({ kind: 'add_rtw' })}
                            onEditPlan={(plan) =>
                                setPane({ kind: 'edit_rtw', plan })
                            }
                            onAddDuty={(planId) =>
                                setPane({ kind: 'add_duty', planId })
                            }
                        />
                    ) : null}
                    {section === 'capacity' ? (
                        <CapacitySection
                            d={d}
                            manage={manage}
                            onAdd={() => setPane({ kind: 'add_capacity' })}
                        />
                    ) : null}
                    {section === 'evidence' ? (
                        <EvidenceSection d={d} manage={manage} />
                    ) : null}
                    {section === 'history' ? <HistorySection d={d} /> : null}
                </>
            )}
        </WizardShell>
    );
}

/* ================================================================== */
/*  Sections                                                          */
/* ================================================================== */

function Kpi({
    label,
    value,
    sub,
    critical,
}: {
    label: string;
    value: ReactNode;
    sub: string;
    critical?: boolean;
}) {
    return (
        <div className="rounded-xl border border-border bg-card/70 p-3.5">
            <div className="text-[11px] font-semibold tracking-wide text-muted-foreground uppercase">
                {label}
            </div>
            <div
                className={`mt-1 text-xl font-bold ${critical ? 'text-status-critical' : 'text-foreground'}`}
            >
                {value}
            </div>
            <div className="text-[11px] text-muted-foreground">{sub}</div>
        </div>
    );
}

function Overview({
    d,
    manage,
    onEdit,
}: {
    d: InjuryDetail;
    manage: boolean;
    onEdit?: (id: number) => void;
}) {
    return (
        <div className="flex flex-col gap-4">
            <div className="grid gap-3 sm:grid-cols-4">
                <Kpi
                    label="Lost days"
                    value={d.lost_time_days}
                    sub={d.lost_time_days > 0 ? 'accruing' : 'none'}
                    critical={d.lost_time_days > 0}
                />
                <Kpi
                    label="ACC claim"
                    value={d.acc_claim_lodged ? 'Open' : '—'}
                    sub={d.acc_claim_number || 'not lodged'}
                />
                <Kpi
                    label="WorkSafe"
                    value={d.worksafe_notifiable ? 'Yes' : 'No'}
                    sub={
                        d.worksafe_notifiable ? 'notifiable' : 'not notifiable'
                    }
                    critical={d.worksafe_notifiable}
                />
                <Kpi
                    label="Status"
                    value={statusLabel(d.status).split(' ')[0]}
                    sub="current stage"
                />
            </div>

            <div className="grid gap-3 sm:grid-cols-2">
                <ReviewCard
                    icon={ClipboardList}
                    title="Injury detail"
                    onEdit={manage && onEdit ? () => onEdit(d.id) : undefined}
                >
                    <ReviewRow label="Worker" value={d.worker?.name} />
                    <ReviewRow label="Site" value={d.site?.name} />
                    <ReviewRow
                        label="Date of injury"
                        value={
                            d.injury_date
                                ? formatDateLong(d.injury_date)
                                : undefined
                        }
                    />
                    <ReviewRow
                        label="Injury type"
                        value={injuryTypeLabel(d.injury_type)}
                    />
                    <ReviewRow label="Body part" value={d.body_part_affected} />
                    <ReviewRow
                        label="Treatment"
                        value={treatmentLabel(d.medical_treatment_type)}
                    />
                    <ReviewRow
                        label="ACC claim"
                        value={d.acc_claim_number || 'Not lodged'}
                    />
                </ReviewCard>
                <div className="flex flex-col gap-3">
                    <div className="rounded-xl border border-border bg-card/70 p-4">
                        <div className="mb-1.5 text-sm font-bold">
                            Description
                        </div>
                        <p className="text-[13px] leading-relaxed text-muted-foreground">
                            {d.description || 'No description recorded.'}
                        </p>
                        {d.immediate_treatment ? (
                            <p className="mt-2 text-[13px] leading-relaxed text-muted-foreground">
                                <span className="font-semibold text-foreground">
                                    Immediate treatment:{' '}
                                </span>
                                {d.immediate_treatment}
                            </p>
                        ) : null}
                    </div>
                    {d.related_incident ? (
                        <a
                            href={`/incidents?incident=${d.related_incident.id}`}
                            className="flex items-start gap-3 rounded-xl border border-status-critical/30 bg-status-critical-bg p-4 transition-colors hover:border-status-critical/60"
                        >
                            <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0 text-status-critical" />
                            <div className="min-w-0 flex-1">
                                <div className="text-sm font-bold text-status-critical">
                                    Linked incident · {d.related_incident.label}
                                </div>
                                <div className="truncate text-[13px] text-muted-foreground">
                                    {d.related_incident.title}
                                </div>
                                <div className="text-[11px] text-muted-foreground">
                                    This injury arose from a reported client
                                    incident
                                </div>
                            </div>
                            <ExternalLink className="h-4 w-4 shrink-0 text-status-critical" />
                        </a>
                    ) : null}
                </div>
            </div>
        </div>
    );
}

function RtwSection({
    d,
    manage,
    onAddPlan,
    onEditPlan,
    onAddDuty,
}: {
    d: InjuryDetail;
    manage: boolean;
    onAddPlan: () => void;
    onEditPlan: (p: RtwPlan) => void;
    onAddDuty: (planId: number) => void;
}) {
    return (
        <div className="flex flex-col gap-3">
            <div className="flex items-center justify-between">
                <h3 className="text-sm font-bold">Return-to-work plans</h3>
                {manage ? (
                    <Button size="sm" variant="outline" onClick={onAddPlan}>
                        <Plus className="mr-1.5 h-3.5 w-3.5" /> Add RTW plan
                    </Button>
                ) : null}
            </div>
            {d.rtw_plans.length === 0 ? (
                <div className="rounded-xl border border-dashed border-border p-6 text-center">
                    <div className="text-sm font-semibold">No RTW plan yet</div>
                    <p className="mt-0.5 text-[13px] text-muted-foreground">
                        Add a staged return plan once a capacity assessment is
                        on file.
                    </p>
                </div>
            ) : (
                d.rtw_plans.map((p) => {
                    const firstStage = p.stages?.[0];
                    return (
                        <div
                            key={p.id}
                            className="rounded-xl border border-border bg-card/70 p-4"
                        >
                            <div className="mb-2 flex items-center justify-between gap-2">
                                <span className="inline-flex items-center gap-1.5 rounded-full bg-live-bg px-2.5 py-1 text-[11px] font-semibold text-live capitalize">
                                    {p.status.replace(/_/g, ' ')}
                                </span>
                                {manage ? (
                                    <button
                                        type="button"
                                        onClick={() => onEditPlan(p)}
                                        className="inline-flex items-center gap-1 text-[13px] font-semibold text-primary hover:underline"
                                    >
                                        <Pencil className="h-3 w-3" /> Update
                                        plan
                                    </button>
                                ) : null}
                            </div>
                            <div className="grid gap-2 sm:grid-cols-3">
                                <MiniStat
                                    label="Hours / week"
                                    value={
                                        firstStage?.hours_per_week != null
                                            ? String(firstStage.hours_per_week)
                                            : '—'
                                    }
                                />
                                <MiniStat
                                    label="Target return"
                                    value={
                                        p.plan_end_date
                                            ? formatDateLong(p.plan_end_date)
                                            : '—'
                                    }
                                />
                                <MiniStat
                                    label="Medical clearance"
                                    value={
                                        p.medical_clearance_provider ||
                                        (p.medical_clearance_notes ||
                                        p.medical_clearance_date
                                            ? 'On file'
                                            : 'Pending')
                                    }
                                    tone={
                                        p.medical_clearance_provider ||
                                        p.medical_clearance_notes ||
                                        p.medical_clearance_date
                                            ? 'success'
                                            : 'warning'
                                    }
                                />
                            </div>
                            {p.goals?.length ? (
                                <ul className="mt-3 list-inside list-disc text-[13px] text-muted-foreground">
                                    {p.goals.map((g, i) => (
                                        <li key={i}>{g}</li>
                                    ))}
                                </ul>
                            ) : null}
                            <div className="mt-3">
                                <div className="mb-1.5 text-[11px] font-semibold tracking-wide text-muted-foreground uppercase">
                                    Modified duties
                                </div>
                                <div className="flex flex-wrap items-center gap-1.5">
                                    {p.modified_duties.map((md) => (
                                        <span
                                            key={md.id}
                                            className="inline-flex items-center gap-1.5 rounded-full border border-border bg-card px-2.5 py-1 text-[12px]"
                                        >
                                            <CheckCircle2 className="h-3 w-3 text-status-success" />{' '}
                                            {md.modified_duties_description}
                                            {md.hours_per_day != null
                                                ? ` · ${md.hours_per_day}h/day`
                                                : ''}
                                        </span>
                                    ))}
                                    {manage ? (
                                        <button
                                            type="button"
                                            onClick={() => onAddDuty(p.id)}
                                            className="inline-flex items-center gap-1 rounded-full border border-dashed border-border px-2.5 py-1 text-[12px] text-muted-foreground hover:border-primary/50 hover:text-primary"
                                        >
                                            <Plus className="h-3 w-3" /> Add
                                            duty
                                        </button>
                                    ) : null}
                                </div>
                            </div>
                        </div>
                    );
                })
            )}
        </div>
    );
}

function CapacitySection({
    d,
    manage,
    onAdd,
}: {
    d: InjuryDetail;
    manage: boolean;
    onAdd: () => void;
}) {
    return (
        <div className="flex flex-col gap-3">
            <div className="flex items-center justify-between">
                <h3 className="text-sm font-bold">Work capacity assessments</h3>
                {manage ? (
                    <Button size="sm" variant="outline" onClick={onAdd}>
                        <Plus className="mr-1.5 h-3.5 w-3.5" /> Record
                        assessment
                    </Button>
                ) : null}
            </div>
            {d.capacity_assessments.length === 0 ? (
                <div className="rounded-xl border border-dashed border-border p-6 text-center text-[13px] text-muted-foreground">
                    No capacity assessments recorded.
                </div>
            ) : (
                d.capacity_assessments.map((a) => (
                    <div
                        key={a.id}
                        className="flex gap-3 rounded-xl border border-border bg-card/70 p-4"
                    >
                        <span className="grid h-9 w-9 shrink-0 place-items-center rounded-lg bg-primary/10 text-primary">
                            <Stethoscope className="h-4 w-4" />
                        </span>
                        <div className="min-w-0 flex-1">
                            <div className="flex items-center justify-between gap-2">
                                <div className="text-sm font-semibold">
                                    {a.assessor_name ||
                                        a.assessor?.name ||
                                        'Assessor'}
                                    {a.assessor_type ? (
                                        <span className="font-normal text-muted-foreground">
                                            {' '}
                                            ·{' '}
                                            {a.assessor_type.replace(/_/g, ' ')}
                                        </span>
                                    ) : null}
                                </div>
                                <span className="rounded-full bg-muted px-2 py-0.5 text-[11px] font-semibold text-muted-foreground capitalize">
                                    {a.capacity_status.replace(/_/g, ' ')}
                                </span>
                            </div>
                            <div className="text-[12px] text-muted-foreground">
                                Assessed{' '}
                                {a.assessment_date
                                    ? formatDateLong(a.assessment_date)
                                    : '—'}
                                {a.next_assessment_date
                                    ? ` · next review ${formatDateLong(a.next_assessment_date)}`
                                    : ''}
                            </div>
                            {a.restrictions ? (
                                <p className="mt-1.5 text-[13px] text-muted-foreground">
                                    {a.restrictions}
                                </p>
                            ) : null}
                        </div>
                    </div>
                ))
            )}
        </div>
    );
}

function HistorySection({ d }: { d: InjuryDetail }) {
    if (!d.audits.length)
        return (
            <div className="rounded-xl border border-dashed border-border p-6 text-center text-[13px] text-muted-foreground">
                No history yet.
            </div>
        );
    const label = (a: string) =>
        a.endsWith('.create')
            ? 'Injury recorded'
            : a.endsWith('.update')
              ? 'Record updated'
              : a.endsWith('.delete')
                ? 'Record removed'
                : a;
    return (
        <div className="flex flex-col gap-0">
            {d.audits.map((log, i) => (
                <div key={log.id} className="flex gap-3">
                    <div className="flex flex-col items-center">
                        <span
                            className={`mt-1 h-2 w-2 shrink-0 rounded-full ${log.action.endsWith('.create') ? 'bg-status-warning' : log.action.endsWith('.delete') ? 'bg-status-critical' : 'bg-primary'}`}
                        />
                        {i < d.audits.length - 1 ? (
                            <span className="w-px flex-1 bg-border" />
                        ) : null}
                    </div>
                    <div className="pb-4">
                        <div className="text-[13px] font-semibold">
                            {label(log.action)}
                            {log.fields?.length ? (
                                <span className="font-normal text-muted-foreground">
                                    {' '}
                                    ·{' '}
                                    {log.fields
                                        .filter(
                                            (f) =>
                                                ![
                                                    'updated_by',
                                                    'updated_at',
                                                ].includes(f),
                                        )
                                        .join(', ')}
                                </span>
                            ) : null}
                        </div>
                        <div className="text-[11px] text-muted-foreground">
                            {log.at ? formatRelative(log.at) : ''}
                            {log.actor ? ` · ${log.actor}` : ''}
                        </div>
                    </div>
                </div>
            ))}
        </div>
    );
}

function MiniStat({
    label,
    value,
    tone,
}: {
    label: string;
    value: string;
    tone?: 'success' | 'warning';
}) {
    const cls =
        tone === 'success'
            ? 'text-status-success'
            : tone === 'warning'
              ? 'text-status-warning'
              : '';
    return (
        <div className="rounded-lg bg-muted/50 p-2.5">
            <div className="text-[11px] font-semibold tracking-wide text-muted-foreground uppercase">
                {label}
            </div>
            <div className={`mt-0.5 text-sm font-bold ${cls}`}>{value}</div>
        </div>
    );
}

/* ================================================================== */
/*  Evidence (premium document upload — composes the shared chrome)   */
/* ================================================================== */

type Staged = { id: number; file: File; kind: string; note: string };
let uid = 0;

function EvidenceSection({ d, manage }: { d: InjuryDetail; manage: boolean }) {
    const [items, setItems] = useState<Staged[]>([]);
    const [uploading, setUploading] = useState(false);
    const [error, setError] = useState<string | null>(null);

    const add = (files: File[]) => {
        setError(null);
        setItems((p) => [
            ...p,
            ...files.map((file) => ({
                id: ++uid,
                file,
                kind: 'document',
                note: '',
            })),
        ]);
    };
    const patch = (id: number, p: Partial<Staged>) =>
        setItems((prev) =>
            prev.map((it) => (it.id === id ? { ...it, ...p } : it)),
        );
    const remove = (id: number) =>
        setItems((prev) => prev.filter((it) => it.id !== id));

    const upload = () => {
        if (!items.length || uploading) return;
        setUploading(true);
        setError(null);
        const queue = [...items];
        const next = (i: number) => {
            if (i >= queue.length) {
                setUploading(false);
                return;
            }
            const it = queue[i];
            const fd = new FormData();
            fd.append('file', it.file);
            fd.append('kind', it.kind);
            if (it.note) fd.append('notes', it.note);
            router.post(`/health-safety/injuries/${d.id}/attachments`, fd, {
                preserveScroll: true,
                preserveState: true,
                onSuccess: () => {
                    remove(it.id);
                    next(i + 1);
                },
                onError: () => {
                    setError(
                        'Upload failed — check the file size (max 10 MB) and type, then try again.',
                    );
                    setUploading(false);
                },
            });
        };
        next(0);
    };

    return (
        <div className="flex flex-col gap-4">
            <h3 className="text-sm font-bold">Evidence &amp; documents</h3>

            {d.attachments.length === 0 ? (
                <div className="rounded-xl border border-dashed border-border p-6 text-center text-[13px] text-muted-foreground">
                    No documents attached yet.
                </div>
            ) : (
                <div className="grid gap-2 sm:grid-cols-2">
                    {d.attachments.map((a) => (
                        <div
                            key={a.id}
                            className="flex items-center gap-3 rounded-xl border border-border bg-card/70 p-3"
                        >
                            {a.is_image ? (
                                <img
                                    src={a.url}
                                    alt={a.alt_text || a.original_name}
                                    className="h-10 w-10 shrink-0 rounded-lg object-cover"
                                />
                            ) : (
                                <span className="grid h-10 w-10 shrink-0 place-items-center rounded-lg bg-primary/10 text-primary">
                                    <FileText className="h-5 w-5" />
                                </span>
                            )}
                            <div className="min-w-0 flex-1">
                                <div className="truncate text-[13px] font-semibold">
                                    {a.original_name}
                                </div>
                                <div className="text-[11px] text-muted-foreground">
                                    {ATTACHMENT_KINDS.find(
                                        (k) => k.value === a.kind,
                                    )?.label ?? 'Document'}
                                    {a.size
                                        ? ` · ${formatFileSize(a.size)}`
                                        : ''}
                                </div>
                            </div>
                            <a
                                href={`/health-safety/injuries/${d.id}/attachments/${a.id}/download`}
                                className="shrink-0 text-muted-foreground hover:text-primary"
                                aria-label={`Download ${a.original_name}`}
                            >
                                <Download className="h-4 w-4" />
                            </a>
                            {manage ? (
                                <button
                                    type="button"
                                    aria-label="Remove document"
                                    onClick={() =>
                                        router.delete(
                                            `/health-safety/injuries/${d.id}/attachments/${a.id}`,
                                            {
                                                preserveScroll: true,
                                                preserveState: true,
                                            },
                                        )
                                    }
                                    className="shrink-0 text-muted-foreground hover:text-status-critical"
                                >
                                    <Trash2 className="h-4 w-4" />
                                </button>
                            ) : null}
                        </div>
                    ))}
                </div>
            )}

            {manage ? (
                <div className="flex flex-col gap-3">
                    <FileDropzone
                        onFiles={add}
                        accept="image/*,.pdf,.doc,.docx"
                        hint="Medical certificates, ACC forms, RTW clearance, photos — up to 10 MB each"
                        disabled={uploading}
                    />
                    {items.length ? (
                        <div className="flex flex-col gap-2">
                            {items.map((it) => (
                                <StagedFileCard
                                    key={it.id}
                                    file={it.file}
                                    onRemove={() => remove(it.id)}
                                >
                                    <div className="grid gap-2 sm:grid-cols-2">
                                        <SelectInput
                                            value={it.kind}
                                            onChange={(v) =>
                                                patch(it.id, { kind: v })
                                            }
                                            placeholder="Document type"
                                            options={ATTACHMENT_KINDS}
                                        />
                                        <Input
                                            value={it.note}
                                            onChange={(e) =>
                                                patch(it.id, {
                                                    note: e.target.value,
                                                })
                                            }
                                            placeholder="Note (optional)"
                                            className="h-9"
                                        />
                                    </div>
                                </StagedFileCard>
                            ))}
                            <div className="flex items-center justify-between gap-2">
                                {error ? (
                                    <span className="text-xs text-status-critical">
                                        {error}
                                    </span>
                                ) : (
                                    <span />
                                )}
                                <Button
                                    type="button"
                                    size="sm"
                                    onClick={upload}
                                    disabled={uploading}
                                >
                                    <Paperclip className="mr-1.5 h-3.5 w-3.5" />{' '}
                                    {uploading
                                        ? 'Uploading…'
                                        : `Upload ${items.length} file${items.length === 1 ? '' : 's'}`}
                                </Button>
                            </div>
                        </div>
                    ) : null}
                </div>
            ) : null}
        </div>
    );
}

/* ================================================================== */
/*  Workflow panes (feature-complete sub-modals)                      */
/* ================================================================== */

function PaneRenderer({
    pane,
    detail,
    staff,
    onDone,
}: {
    pane: Pane;
    detail: InjuryDetail;
    staff: StaffOption[];
    onDone: () => void;
}) {
    if (pane.kind === 'acc') return <AccPane d={detail} onDone={onDone} />;
    if (pane.kind === 'close') return <ClosePane d={detail} onDone={onDone} />;
    if (pane.kind === 'add_rtw')
        return <RtwPlanPane d={detail} staff={staff} onDone={onDone} />;
    if (pane.kind === 'edit_rtw')
        return (
            <RtwPlanPane
                d={detail}
                staff={staff}
                plan={pane.plan}
                onDone={onDone}
            />
        );
    if (pane.kind === 'add_duty')
        return <DutyPane planId={pane.planId} onDone={onDone} />;
    if (pane.kind === 'add_capacity')
        return <CapacityPane d={detail} staff={staff} onDone={onDone} />;
    return null;
}

function PaneHead({
    icon: Icon,
    title,
    blurb,
}: {
    icon: typeof HeartPulse;
    title: string;
    blurb: string;
}) {
    return (
        <div className="mb-4 flex items-start gap-3">
            <span className="shrink-0 rounded-xl bg-primary/10 p-2.5 text-primary">
                <Icon className="h-5 w-5" />
            </span>
            <div>
                <h3 className="text-base font-bold">{title}</h3>
                <p className="text-[13px] text-muted-foreground">{blurb}</p>
            </div>
        </div>
    );
}

function PaneFooter({
    onCancel,
    onSubmit,
    processing,
    label,
}: {
    onCancel: () => void;
    onSubmit: () => void;
    processing: boolean;
    label: string;
}) {
    return (
        <div className="mt-5 flex items-center justify-end gap-2">
            <Button variant="outline" size="sm" onClick={onCancel}>
                Cancel
            </Button>
            <Button size="sm" onClick={onSubmit} disabled={processing}>
                {label}
            </Button>
        </div>
    );
}

function AccPane({ d, onDone }: { d: InjuryDetail; onDone: () => void }) {
    const form = useForm({
        acc_claim_number: d.acc_claim_number ?? '',
        acc_claim_lodged: d.acc_claim_lodged,
    });
    const submit = () =>
        form.put(`/health-safety/injuries/${d.id}`, {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => onDone(),
        });
    return (
        <div>
            <PaneHead
                icon={ShieldAlert}
                title={
                    d.acc_claim_lodged ? 'Update ACC claim' : 'Lodge ACC claim'
                }
                blurb="Record the ACC claim number once the claim is lodged."
            />
            <div className="grid gap-4">
                <Field
                    label="ACC claim number"
                    error={form.errors.acc_claim_number}
                >
                    <Input
                        value={form.data.acc_claim_number}
                        onChange={(e) =>
                            form.setData('acc_claim_number', e.target.value)
                        }
                        placeholder="26/123456"
                    />
                </Field>
                <label className="flex cursor-pointer items-center gap-2 text-[13px] font-semibold">
                    <input
                        type="checkbox"
                        checked={form.data.acc_claim_lodged}
                        onChange={(e) =>
                            form.setData('acc_claim_lodged', e.target.checked)
                        }
                        className="h-4 w-4 rounded border-border"
                    />{' '}
                    Claim lodged with ACC
                </label>
            </div>
            <PaneFooter
                onCancel={onDone}
                onSubmit={submit}
                processing={form.processing}
                label="Save ACC claim"
            />
        </div>
    );
}

function ClosePane({ d, onDone }: { d: InjuryDetail; onDone: () => void }) {
    const close = () =>
        router.post(
            `/health-safety/injuries/${d.id}/status`,
            { status: 'closed' },
            {
                preserveScroll: true,
                preserveState: true,
                onSuccess: () => onDone(),
            },
        );
    return (
        <div>
            <PaneHead
                icon={X}
                title="Close injury record"
                blurb="Closing marks the injury fully resolved. You can still view it in the Closed tab."
            />
            <InfoCard icon={AlertTriangle} tone="warn">
                Confirm the worker has recovered and all RTW / ACC actions are
                complete before closing.
            </InfoCard>
            <PaneFooter
                onCancel={onDone}
                onSubmit={close}
                processing={false}
                label="Close record"
            />
        </div>
    );
}

function RtwPlanPane({
    d,
    staff,
    plan,
    onDone,
}: {
    d: InjuryDetail;
    staff: StaffOption[];
    plan?: RtwPlan;
    onDone: () => void;
}) {
    const editing = Boolean(plan);
    const form = useForm({
        plan_start_date:
            plan?.plan_start_date?.slice(0, 10) ??
            d.injury_date?.slice(0, 10) ??
            '',
        plan_end_date: plan?.plan_end_date?.slice(0, 10) ?? '',
        goals: (plan?.goals ?? ['Return to full pre-injury duties']).join('\n'),
        stage_name: plan?.stages?.[0]?.name ?? 'Graduated return',
        stage_hours:
            plan?.stages?.[0]?.hours_per_week != null
                ? String(plan.stages[0].hours_per_week)
                : '',
        stage_duties: plan?.stages?.[0]?.duties_description ?? '',
        medical_clearance_provider: plan?.medical_clearance_provider ?? '',
        medical_clearance_notes: plan?.medical_clearance_notes ?? '',
        next_review_date: plan?.next_review_date?.slice(0, 10) ?? '',
        manager_id: plan?.manager ? String(plan.manager.id) : '',
        status: plan?.status ?? 'active',
    });

    const submit = () => {
        form.transform((data) => ({
            plan_start_date: data.plan_start_date,
            plan_end_date: data.plan_end_date || null,
            goals: data.goals
                .split('\n')
                .map((g) => g.trim())
                .filter(Boolean),
            stages: [
                {
                    name: data.stage_name,
                    start_date: data.plan_start_date,
                    end_date: data.plan_end_date || null,
                    hours_per_week: data.stage_hours
                        ? Number(data.stage_hours)
                        : null,
                    duties_description: data.stage_duties || null,
                },
            ],
            medical_clearance_provider: data.medical_clearance_provider || null,
            medical_clearance_notes: data.medical_clearance_notes || null,
            next_review_date: data.next_review_date || null,
            ...(editing
                ? { status: data.status }
                : { manager_id: data.manager_id || null }),
        }));
        const opts = {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => onDone(),
        };
        if (editing && plan)
            form.put(`/health-safety/injuries/rtw-plans/${plan.id}`, opts);
        else form.post(`/health-safety/injuries/${d.id}/rtw-plans`, opts);
    };

    return (
        <div>
            <PaneHead
                icon={ArrowRightLeft}
                title={
                    editing
                        ? 'Update return-to-work plan'
                        : 'Add return-to-work plan'
                }
                blurb="A staged plan to safely return the worker to full duties."
            />
            <div className="grid gap-4 sm:grid-cols-2">
                <Field label="Plan start" error={form.errors.plan_start_date}>
                    <Input
                        type="date"
                        value={form.data.plan_start_date}
                        onChange={(e) =>
                            form.setData('plan_start_date', e.target.value)
                        }
                    />
                </Field>
                <Field label="Target return date">
                    <Input
                        type="date"
                        value={form.data.plan_end_date}
                        onChange={(e) =>
                            form.setData('plan_end_date', e.target.value)
                        }
                    />
                </Field>
                <Field label="Goals" hint="one per line" span>
                    <Textarea
                        rows={2}
                        value={form.data.goals}
                        onChange={(e) => form.setData('goals', e.target.value)}
                        placeholder="Return to full pre-injury duties"
                    />
                </Field>
                <Field label="Stage name">
                    <Input
                        value={form.data.stage_name}
                        onChange={(e) =>
                            form.setData('stage_name', e.target.value)
                        }
                        placeholder="Graduated return"
                    />
                </Field>
                <Field label="Hours / week">
                    <Input
                        type="number"
                        min={0}
                        max={60}
                        value={form.data.stage_hours}
                        onChange={(e) =>
                            form.setData('stage_hours', e.target.value)
                        }
                        placeholder="20"
                    />
                </Field>
                <Field label="Stage duties" span>
                    <Input
                        value={form.data.stage_duties}
                        onChange={(e) =>
                            form.setData('stage_duties', e.target.value)
                        }
                        placeholder="Light administrative duties, no manual handling"
                    />
                </Field>
                <Field label="Medical clearance provider">
                    <Input
                        value={form.data.medical_clearance_provider}
                        onChange={(e) =>
                            form.setData(
                                'medical_clearance_provider',
                                e.target.value,
                            )
                        }
                        placeholder="e.g. GP / physiotherapist"
                    />
                </Field>
                <Field label="Next review date">
                    <Input
                        type="date"
                        value={form.data.next_review_date}
                        onChange={(e) =>
                            form.setData('next_review_date', e.target.value)
                        }
                    />
                </Field>
                <Field label="Medical clearance notes" span>
                    <Textarea
                        rows={2}
                        value={form.data.medical_clearance_notes}
                        onChange={(e) =>
                            form.setData(
                                'medical_clearance_notes',
                                e.target.value,
                            )
                        }
                    />
                </Field>
                {editing ? (
                    <Field label="Plan status">
                        <SelectInput
                            value={form.data.status}
                            onChange={(v) => form.setData('status', v)}
                            placeholder="Status"
                            options={[
                                { value: 'active', label: 'Active' },
                                { value: 'in_progress', label: 'In progress' },
                                { value: 'completed', label: 'Completed' },
                                { value: 'cancelled', label: 'Cancelled' },
                            ]}
                        />
                    </Field>
                ) : (
                    <Field label="Managing supervisor">
                        <SelectInput
                            value={form.data.manager_id}
                            onChange={(v) => form.setData('manager_id', v)}
                            placeholder="Select manager…"
                            options={staff.map((s) => ({
                                value: String(s.id),
                                label: s.name,
                            }))}
                        />
                    </Field>
                )}
            </div>
            <PaneFooter
                onCancel={onDone}
                onSubmit={submit}
                processing={form.processing}
                label={editing ? 'Save plan' : 'Add plan'}
            />
        </div>
    );
}

function DutyPane({ planId, onDone }: { planId: number; onDone: () => void }) {
    const form = useForm({
        start_date: '',
        end_date: '',
        modified_duties_description: '',
        hours_per_day: '',
        restrictions: '',
        accommodations: '',
    });
    const submit = () => {
        form.transform((data) => ({
            ...data,
            end_date: data.end_date || null,
            hours_per_day: data.hours_per_day ? Number(data.hours_per_day) : 0,
            restrictions: data.restrictions || null,
            accommodations: data.accommodations || null,
        }));
        form.post(
            `/health-safety/injuries/rtw-plans/${planId}/modified-duties`,
            {
                preserveScroll: true,
                preserveState: true,
                onSuccess: () => onDone(),
            },
        );
    };
    return (
        <div>
            <PaneHead
                icon={ClipboardList}
                title="Add modified duty"
                blurb="Lighter or restricted duties while the worker recovers."
            />
            <div className="grid gap-4 sm:grid-cols-2">
                <Field label="Start date" error={form.errors.start_date}>
                    <Input
                        type="date"
                        value={form.data.start_date}
                        onChange={(e) =>
                            form.setData('start_date', e.target.value)
                        }
                    />
                </Field>
                <Field label="End date">
                    <Input
                        type="date"
                        value={form.data.end_date}
                        onChange={(e) =>
                            form.setData('end_date', e.target.value)
                        }
                    />
                </Field>
                <Field
                    label="Modified duties"
                    required
                    error={form.errors.modified_duties_description}
                    span
                >
                    <Textarea
                        rows={2}
                        value={form.data.modified_duties_description}
                        onChange={(e) =>
                            form.setData(
                                'modified_duties_description',
                                e.target.value,
                            )
                        }
                        placeholder="e.g. Desk-based tasks only, no lifting over 5 kg"
                    />
                </Field>
                <Field
                    label="Hours / day"
                    required
                    error={form.errors.hours_per_day}
                >
                    <Input
                        type="number"
                        min={0}
                        max={24}
                        step={0.5}
                        value={form.data.hours_per_day}
                        onChange={(e) =>
                            form.setData('hours_per_day', e.target.value)
                        }
                        placeholder="6"
                    />
                </Field>
                <Field label="Restrictions">
                    <Input
                        value={form.data.restrictions}
                        onChange={(e) =>
                            form.setData('restrictions', e.target.value)
                        }
                        placeholder="e.g. No driving"
                    />
                </Field>
                <Field label="Accommodations" span>
                    <Input
                        value={form.data.accommodations}
                        onChange={(e) =>
                            form.setData('accommodations', e.target.value)
                        }
                        placeholder="e.g. Sit-stand desk provided"
                    />
                </Field>
            </div>
            <PaneFooter
                onCancel={onDone}
                onSubmit={submit}
                processing={form.processing}
                label="Add duty"
            />
        </div>
    );
}

function CapacityPane({
    d,
    staff,
    onDone,
}: {
    d: InjuryDetail;
    staff: StaffOption[];
    onDone: () => void;
}) {
    const form = useForm({
        assessment_date: '',
        user_id: '',
        assessor_name: '',
        assessor_type: '',
        capacity_status: '',
        restrictions: '',
        recommendations: '',
        next_assessment_date: '',
        assessment_summary: '',
    });
    const submit = () => {
        form.transform((data) => ({
            ...data,
            user_id: data.user_id || null,
            assessor_name: data.assessor_name || null,
            restrictions: data.restrictions || null,
            recommendations: data.recommendations || null,
            next_assessment_date: data.next_assessment_date || null,
            assessment_summary: data.assessment_summary || null,
        }));
        form.post(`/health-safety/injuries/${d.id}/capacity-assessments`, {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => onDone(),
        });
    };
    return (
        <div>
            <PaneHead
                icon={Stethoscope}
                title="Record capacity assessment"
                blurb="A clinician's assessment of the worker's fitness for duties."
            />
            <div className="grid gap-4 sm:grid-cols-2">
                <Field
                    label="Assessment date"
                    required
                    error={form.errors.assessment_date}
                >
                    <Input
                        type="date"
                        value={form.data.assessment_date}
                        onChange={(e) =>
                            form.setData('assessment_date', e.target.value)
                        }
                    />
                </Field>
                <Field
                    label="Assessor type"
                    required
                    error={form.errors.assessor_type}
                >
                    <SelectInput
                        value={form.data.assessor_type}
                        onChange={(v) => form.setData('assessor_type', v)}
                        placeholder="Select…"
                        options={[
                            { value: 'gp', label: 'GP' },
                            { value: 'specialist', label: 'Specialist' },
                            {
                                value: 'physiotherapist',
                                label: 'Physiotherapist',
                            },
                            {
                                value: 'occupational_therapist',
                                label: 'Occupational therapist',
                            },
                            { value: 'employer', label: 'Employer' },
                        ]}
                    />
                </Field>
                <Field
                    label="Capacity status"
                    required
                    error={form.errors.capacity_status}
                >
                    <SelectInput
                        value={form.data.capacity_status}
                        onChange={(v) => form.setData('capacity_status', v)}
                        placeholder="Select…"
                        options={[
                            {
                                value: 'fit_for_full_duties',
                                label: 'Fit for full duties',
                            },
                            {
                                value: 'fit_for_modified_duties',
                                label: 'Fit for modified duties',
                            },
                            {
                                value: 'unfit_for_work',
                                label: 'Unfit for work',
                            },
                            {
                                value: 'requires_review',
                                label: 'Requires review',
                            },
                        ]}
                    />
                </Field>
                <Field label="Next review date">
                    <Input
                        type="date"
                        value={form.data.next_assessment_date}
                        onChange={(e) =>
                            form.setData('next_assessment_date', e.target.value)
                        }
                    />
                </Field>
                <Field label="Assessor name" hint="if external">
                    <Input
                        value={form.data.assessor_name}
                        onChange={(e) =>
                            form.setData('assessor_name', e.target.value)
                        }
                        placeholder="e.g. Dr Aroha Kahu"
                    />
                </Field>
                <Field label="Internal assessor" hint="if staff">
                    <SelectInput
                        value={form.data.user_id}
                        onChange={(v) => form.setData('user_id', v)}
                        placeholder="Select staff…"
                        options={staff.map((s) => ({
                            value: String(s.id),
                            label: s.name,
                        }))}
                    />
                </Field>
                <Field label="Restrictions" span>
                    <Textarea
                        rows={2}
                        value={form.data.restrictions}
                        onChange={(e) =>
                            form.setData('restrictions', e.target.value)
                        }
                        placeholder="e.g. No lifting over 10 kg for 4 weeks"
                    />
                </Field>
                <Field label="Recommendations" span>
                    <Textarea
                        rows={2}
                        value={form.data.recommendations}
                        onChange={(e) =>
                            form.setData('recommendations', e.target.value)
                        }
                    />
                </Field>
            </div>
            <PaneFooter
                onCancel={onDone}
                onSubmit={submit}
                processing={form.processing}
                label="Record assessment"
            />
        </div>
    );
}
