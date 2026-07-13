/* Safe Work Procedure detail modal — param-driven (?procedure=) like EventDetailDialog.
 * WizardShell section-rail (Overview / Steps / PPE & hazards / Applies to / Documents /
 * History) + footer Options bar; lifecycle actions (submit / approve / request-changes /
 * record-review / archive / restore) take over the body and post in-place. The Documents
 * section is the controlled-document library, reusing the shared AttachmentUploader.
 * Built on the Add-client modal chrome (WizardShell). Semantic tokens only. NZ-only, web-only. */
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { AttachmentUploader, formatFileSize } from '@/components/ui/file-dropzone';
import { Field, InfoCard, StepHead } from '@/components/wizard/primitives';
import { ReviewCard, WizardShell, type WizardStep } from '@/components/wizard/shell';
import {
    FlagBadge,
    titleCase,
    TONE_BG,
    TONE_DOT,
    type Tone,
} from '@/pages/health-safety/components/register-row-kit';
import { formatDateLong, formatDateTime } from '@/lib/datetime';
import { cn } from '@/lib/utils';
import { Link, router, useForm } from '@inertiajs/react';
import {
    AlertTriangle,
    Archive,
    ArchiveRestore,
    CalendarCheck,
    Check,
    CheckCircle2,
    Clock,
    Download,
    ExternalLink,
    FilePlus2,
    FileText,
    GraduationCap,
    HardHat,
    History,
    Info,
    ListChecks,
    MapPin,
    Pencil,
    RotateCcw,
    Send,
    ShieldAlert,
    Trash2,
    Users,
    type LucideIcon,
} from 'lucide-react';
import { type ReactNode, useEffect, useState } from 'react';
import { Card as GuardrailCard } from '@/components/ui/card';

/* ------------------------------------------------------------------ */
/*  Shared types + token maps (imported by the register + wizard)      */
/* ------------------------------------------------------------------ */

export type ProcedureSectionKey = 'overview' | 'steps' | 'ppe' | 'applies' | 'documents' | 'history';
export type ProcedureActionKey = 'submit_review' | 'approve' | 'request_changes' | 'record_review' | 'archive' | 'restore';

export type ProcedureFormData = {
    id?: number;
    title: string;
    reference_number: string;
    category: string;
    purpose: string;
    scope: string;
    steps: { step_number: number; description: string; safety_notes: string }[];
    ppe_required: string[];
    hazards_addressed: string[];
    emergency_procedures: string;
    applicable_roles: string[];
    applicable_sites: number[];
    related_training: number[];
    review_date: string | null;
    review_frequency_months: number | null;
    owner_id: number | null;
};

export type ProcedureDetail = {
    id: number;
    reference_number: string;
    title: string;
    category: string;
    status: string;
    previous_status: string | null;
    version: number;
    purpose: string | null;
    scope: string | null;
    steps: { step_number: number; description: string; safety_notes: string }[];
    ppe_required: string[];
    hazards_addressed: string[];
    emergency_procedures: string;
    review_date: string | null;
    review_frequency_months: number | null;
    approved_at: string | null;
    applies: {
        roles: { key: string; label: string }[];
        sites: { id: number | string; name: string }[];
        training: { id: number | string; name: string; code: string | null }[];
    };
    owner: { id: number; name: string } | null;
    approved_by: { id: number; name: string } | null;
    creator: { id: number; name: string } | null;
    updater: { id: number; name: string } | null;
    versions: { id: number; version: number; change_summary: string | null; changed_by: { id: number; name: string } | null; created_at: string | null }[];
    attachments: {
        id: number;
        original_name: string;
        mime: string | null;
        size: number | null;
        description: string | null;
        version: number | null;
        uploaded_by_name: string | null;
        created_at: string | null;
        url: string;
    }[];
    acknowledged: boolean;
    acknowledged_count: number;
    form: ProcedureFormData;
    can: { view: boolean; create: boolean; manage: boolean; approve: boolean };
};

export const CATEGORY_META: Record<string, { label: string; dot: string; chip: string }> = {
    manual_handling: { label: 'Manual handling', dot: 'bg-status-info', chip: 'bg-status-info-bg text-status-info' },
    challenging_behaviour: { label: 'Challenging behaviour', dot: 'bg-status-critical', chip: 'bg-status-critical-bg text-status-critical' },
    lone_working: { label: 'Lone working', dot: 'bg-status-warning', chip: 'bg-status-warning-bg text-status-warning' },
    medication: { label: 'Medication', dot: 'bg-primary', chip: 'bg-primary/10 text-primary' },
    infection_control: { label: 'Infection control', dot: 'bg-status-success', chip: 'bg-status-success-bg text-status-success' },
    fire_safety: { label: 'Fire safety', dot: 'bg-status-critical', chip: 'bg-status-critical-bg text-status-critical' },
    emergency_procedures: { label: 'Emergency procedures', dot: 'bg-status-warning', chip: 'bg-status-warning-bg text-status-warning' },
    equipment_use: { label: 'Equipment use', dot: 'bg-muted-foreground', chip: 'bg-muted text-muted-foreground' },
    personal_care: { label: 'Personal care', dot: 'bg-muted-foreground', chip: 'bg-muted text-muted-foreground' },
};

export function categoryMeta(key: string): { label: string; dot: string; chip: string } {
    return CATEGORY_META[key] ?? { label: titleCase(key), dot: 'bg-muted-foreground', chip: 'bg-muted text-muted-foreground' };
}

export const STATUS_META: Record<string, { label: string; tone: Tone; icon: LucideIcon }> = {
    draft: { label: 'Draft', tone: 'neutral', icon: Pencil },
    under_review: { label: 'Under review', tone: 'warning', icon: Clock },
    approved: { label: 'Approved', tone: 'success', icon: CheckCircle2 },
    archived: { label: 'Archived', tone: 'critical', icon: Archive },
};

export function statusMeta(key: string): { label: string; tone: Tone; icon: LucideIcon } {
    return STATUS_META[key] ?? { label: titleCase(key), tone: 'neutral', icon: Pencil };
}

/** Review-window flag for the register + detail footer. Null when comfortably current. */
export function reviewFlag(reviewDate: string | null): { tone: 'critical' | 'warning'; label: string } | null {
    if (!reviewDate) return null;
    const today = new Date();
    today.setHours(0, 0, 0, 0);
    const due = new Date(reviewDate);
    due.setHours(0, 0, 0, 0);
    if (Number.isNaN(due.getTime())) return null;
    const days = Math.round((due.getTime() - today.getTime()) / 86400000);
    if (days < 0) return { tone: 'critical', label: `Overdue ${Math.abs(days)}d` };
    if (days <= 30) return { tone: 'warning', label: `Due ${days}d` };
    return null;
}

const PROCEDURES_URL = '/health-safety/procedures';

/* ------------------------------------------------------------------ */
/*  Dialog                                                             */
/* ------------------------------------------------------------------ */

export function ProcedureDetailDialog({
    detail,
    open,
    onClose,
    onEdit,
    initialSection = 'overview',
    initialAction = null,
}: {
    detail: ProcedureDetail;
    open: boolean;
    onClose: () => void;
    onEdit: (form: ProcedureFormData) => void;
    initialSection?: ProcedureSectionKey;
    initialAction?: ProcedureActionKey | null;
}) {
    const [section, setSection] = useState<ProcedureSectionKey>(initialSection);
    const [pane, setPane] = useState<ProcedureActionKey | null>(initialAction);

    // Re-sync when a context-menu action re-opens the same dialog onto a new pane/section.
    useEffect(() => setSection(initialSection), [initialSection, detail.id]);
    useEffect(() => setPane(initialAction), [initialAction, detail.id]);

    const cat = categoryMeta(detail.category);
    const status = statusMeta(detail.status);
    const flag = reviewFlag(detail.review_date);
    const can = detail.can;

    const SECTIONS: WizardStep[] = [
        { key: 'overview', label: 'Overview', blurb: 'Purpose, scope & owner', icon: FileText },
        { key: 'steps', label: 'Steps', blurb: `${detail.steps.length} step${detail.steps.length === 1 ? '' : 's'}`, icon: ListChecks },
        { key: 'ppe', label: 'PPE & hazards', blurb: 'Kit & risks addressed', icon: HardHat },
        { key: 'applies', label: 'Applies to', blurb: 'Roles, sites & training', icon: Users },
        { key: 'documents', label: 'Documents', blurb: detail.attachments.length ? `${detail.attachments.length} file${detail.attachments.length === 1 ? '' : 's'}` : 'Controlled docs', icon: FilePlus2 },
        { key: 'history', label: 'History', blurb: `${detail.versions.length} version${detail.versions.length === 1 ? '' : 's'}`, icon: History },
    ];
    const stepIndex = Math.max(0, SECTIONS.findIndex((s) => s.key === section));

    const footerStart = (
        <div className="flex flex-wrap items-center gap-2">
            <span className={cn('inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-semibold', TONE_BG[status.tone])}>
                <span className={cn('h-1.5 w-1.5 rounded-full', TONE_DOT[status.tone])} />
                {status.label}
            </span>
            {flag ? (
                <FlagBadge icon={CalendarCheck} tone={flag.tone} title={`Next review ${detail.review_date ? formatDateLong(detail.review_date) : ''}`}>
                    {flag.label}
                </FlagBadge>
            ) : null}
        </div>
    );

    const footerEnd = pane ? null : (
        <div className="flex flex-wrap items-center justify-end gap-2">
            <OptionsBar detail={detail} can={can} onPane={setPane} onEdit={() => onEdit(detail.form)} />
            <Link
                href={`${PROCEDURES_URL}/${detail.id}`}
                className="inline-flex items-center gap-1.5 rounded-lg border border-border px-3 py-2 text-sm font-medium text-foreground transition-colors hover:bg-muted"
            >
                <ExternalLink className="h-4 w-4" /> Open full page
            </Link>
        </div>
    );

    return (
        <WizardShell
            open={open}
            onClose={onClose}
            title={`${detail.reference_number} — ${detail.title}`}
            description={`${cat.label} safe work procedure · v${detail.version}`}
            railIcon={FileText}
            railTitle={detail.reference_number}
            railSub="Safe work procedure"
            steps={SECTIONS}
            stepIndex={stepIndex}
            onStepClick={(i) => {
                setPane(null);
                setSection(SECTIONS[i].key as ProcedureSectionKey);
            }}
            footerStart={footerStart}
            footerEnd={footerEnd}
        >
            {/* Light header strip: category chip + version + title */}
            <div className="mb-5 flex flex-wrap items-center gap-2">
                <span className={cn('inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-semibold', cat.chip)}>
                    <span className={cn('h-1.5 w-1.5 rounded-full', cat.dot)} />
                    {cat.label}
                </span>
                <span className="rounded-full bg-muted px-2 py-0.5 text-[11px] font-bold text-muted-foreground">v{detail.version}</span>
                <h2 className="text-lg font-bold tracking-tight text-foreground">{detail.title}</h2>
            </div>

            {pane ? (
                <ActionPane kind={pane} detail={detail} onDone={() => setPane(null)} onCancel={() => setPane(null)} />
            ) : section === 'overview' ? (
                <OverviewSection detail={detail} />
            ) : section === 'steps' ? (
                <StepsSection detail={detail} />
            ) : section === 'ppe' ? (
                <PpeSection detail={detail} />
            ) : section === 'applies' ? (
                <AppliesSection detail={detail} />
            ) : section === 'documents' ? (
                <DocumentsSection detail={detail} />
            ) : (
                <HistorySection detail={detail} />
            )}
        </WizardShell>
    );
}

/* ------------------------------------------------------------------ */
/*  Options bar (lifecycle, gated on can + status)                     */
/* ------------------------------------------------------------------ */

function OptionsBar({
    detail,
    can,
    onPane,
    onEdit,
}: {
    detail: ProcedureDetail;
    can: ProcedureDetail['can'];
    onPane: (p: ProcedureActionKey) => void;
    onEdit: () => void;
}) {
    const s = detail.status;
    const btns: ReactNode[] = [];

    if (can.manage && s !== 'archived') {
        btns.push(
            <OptBtn key="edit" icon={s === 'approved' ? FilePlus2 : Pencil} onClick={onEdit}>
                {s === 'approved' ? 'New version' : 'Edit'}
            </OptBtn>,
        );
    }
    if (can.manage && s === 'draft') {
        btns.push(
            <OptBtn key="submit" icon={Send} onClick={() => onPane('submit_review')}>
                Submit for review
            </OptBtn>,
        );
    }
    if (can.approve && s === 'under_review') {
        btns.push(
            <OptBtn key="approve" icon={CheckCircle2} tone="primary" onClick={() => onPane('approve')}>
                Approve
            </OptBtn>,
        );
    }
    if (can.manage && (s === 'under_review' || s === 'approved')) {
        btns.push(
            <OptBtn key="request" icon={RotateCcw} onClick={() => onPane('request_changes')}>
                Request changes
            </OptBtn>,
        );
    }
    if (can.manage && s === 'approved') {
        btns.push(
            <OptBtn key="review" icon={CalendarCheck} onClick={() => onPane('record_review')}>
                Record review
            </OptBtn>,
        );
    }
    if (can.manage && s !== 'archived') {
        btns.push(
            <OptBtn key="archive" icon={Archive} tone="critical" onClick={() => onPane('archive')}>
                Archive
            </OptBtn>,
        );
    }
    if (can.manage && s === 'archived') {
        btns.push(
            <OptBtn key="restore" icon={ArchiveRestore} tone="primary" onClick={() => onPane('restore')}>
                Restore
            </OptBtn>,
        );
    }

    return <>{btns}</>;
}

function OptBtn({
    icon: Icon,
    children,
    onClick,
    tone = 'neutral',
}: {
    icon: LucideIcon;
    children: ReactNode;
    onClick: () => void;
    tone?: 'neutral' | 'primary' | 'critical';
}) {
    const cls =
        tone === 'primary'
            ? 'border-primary/40 text-primary hover:bg-primary/10'
            : tone === 'critical'
              ? 'border-status-critical/40 text-status-critical hover:bg-status-critical-bg'
              : 'border-border text-foreground hover:bg-muted';
    return (
        <Button unstyled type="button" onClick={onClick} className={cn('inline-flex items-center gap-1.5 rounded-lg border px-3 py-2 text-sm font-medium transition-colors', cls)}>
            <Icon className="h-4 w-4" />
            {children}
        </Button>
    );
}

/* ------------------------------------------------------------------ */
/*  Sections                                                           */
/* ------------------------------------------------------------------ */

function OverviewSection({ detail }: { detail: ProcedureDetail }) {
    return (
        <div className="flex flex-col gap-4">
            <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
                <ReviewCard icon={Info} title="Purpose">
                    <p className="text-sm leading-relaxed text-foreground">{detail.purpose || 'No purpose recorded.'}</p>
                </ReviewCard>
                <ReviewCard icon={MapPin} title="Scope">
                    <p className="text-sm leading-relaxed text-foreground">{detail.scope || 'No scope recorded.'}</p>
                </ReviewCard>
            </div>
            <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                <MetaTile label="Owner" value={detail.owner?.name ?? detail.creator?.name ?? '—'} />
                <MetaTile label="Approved by" value={detail.approved_by?.name ?? 'Not yet approved'} />
                <MetaTile label="Next review" value={detail.review_date ? formatDateLong(detail.review_date) : '—'} />
                <MetaTile label="Current version" value={`v${detail.version}`} />
            </div>
            {detail.review_frequency_months ? (
                <p className="text-xs text-muted-foreground">
                    Review cadence: every {detail.review_frequency_months} month{detail.review_frequency_months === 1 ? '' : 's'}.
                </p>
            ) : null}
            {detail.status === 'approved' ? <AcknowledgeBar detail={detail} /> : null}
        </div>
    );
}

/** "I have read & understood" — version-stamped acknowledgement for in-force procedures. */
function AcknowledgeBar({ detail }: { detail: ProcedureDetail }) {
    const acknowledge = () =>
        router.post(`${PROCEDURES_URL}/${detail.id}/acknowledge`, {}, { preserveScroll: true, preserveState: true });

    return (
        <div className="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-border bg-muted/30 px-4 py-3">
            <div className="flex items-center gap-2 text-sm">
                <Users className="h-4 w-4 text-muted-foreground" />
                <span className="text-muted-foreground">
                    Acknowledged by <span className="font-semibold text-foreground">{detail.acknowledged_count}</span>{' '}
                    {detail.acknowledged_count === 1 ? 'person' : 'people'}
                </span>
            </div>
            {detail.acknowledged ? (
                <span className="inline-flex items-center gap-1.5 rounded-full bg-status-success-bg px-3 py-1.5 text-xs font-semibold text-status-success">
                    <Check className="h-3.5 w-3.5" /> You've acknowledged v{detail.version}
                </span>
            ) : (
                <Button type="button" size="sm" onClick={acknowledge}>
                    <Check className="mr-1.5 h-4 w-4" /> I've read &amp; understood this
                </Button>
            )}
        </div>
    );
}

function MetaTile({ label, value }: { label: string; value: string }) {
    return (
        <div className="rounded-xl border border-border bg-muted/30 px-3 py-2.5">
            <div className="text-[10.5px] font-semibold tracking-wide text-muted-foreground uppercase">{label}</div>
            <div className="mt-0.5 truncate text-sm font-semibold text-foreground" title={value}>
                {value}
            </div>
        </div>
    );
}

function StepsSection({ detail }: { detail: ProcedureDetail }) {
    if (!detail.steps.length) {
        return <EmptyNote icon={ListChecks}>No procedure steps recorded yet.</EmptyNote>;
    }
    return (
        <ol className="flex flex-col gap-3">
            {detail.steps.map((step, i) => (
                <li key={i} className="flex gap-3 rounded-xl border border-border bg-card/60 p-3.5">
                    <span className="grid h-7 w-7 shrink-0 place-items-center rounded-full bg-primary/10 text-xs font-bold text-primary">{step.step_number || i + 1}</span>
                    <div className="min-w-0 flex-1">
                        <p className="text-sm leading-relaxed text-foreground">{step.description}</p>
                        {step.safety_notes ? (
                            <div className="mt-2 flex items-start gap-1.5 rounded-lg bg-status-warning-bg px-2.5 py-1.5 text-xs text-status-warning">
                                <ShieldAlert className="mt-0.5 h-3.5 w-3.5 shrink-0" />
                                <span>{step.safety_notes}</span>
                            </div>
                        ) : null}
                    </div>
                </li>
            ))}
        </ol>
    );
}

function PpeSection({ detail }: { detail: ProcedureDetail }) {
    const hasEmergency = detail.emergency_procedures && detail.emergency_procedures.trim() !== '';
    return (
        <div className="flex flex-col gap-4">
            <ChipBlock icon={HardHat} title="PPE required" items={detail.ppe_required} empty="No PPE recorded." tone="primary" />
            <ChipBlock icon={ShieldAlert} title="Hazards addressed" items={detail.hazards_addressed} empty="No hazards recorded." tone="warning" />
            {hasEmergency ? (
                <div className="rounded-xl border border-status-critical/35 bg-status-critical-bg p-3.5">
                    <div className="mb-1.5 flex items-center gap-1.5 text-xs font-bold tracking-wide text-status-critical uppercase">
                        <AlertTriangle className="h-3.5 w-3.5" /> Emergency response
                    </div>
                    <p className="text-sm leading-relaxed whitespace-pre-line text-foreground">{detail.emergency_procedures}</p>
                </div>
            ) : null}
        </div>
    );
}

function ChipBlock({ icon: Icon, title, items, empty, tone }: { icon: LucideIcon; title: string; items: string[]; empty: string; tone: 'primary' | 'warning' }) {
    const chip = tone === 'primary' ? 'border-primary/30 bg-primary/10 text-primary' : 'border-status-warning/30 bg-status-warning-bg text-status-warning';
    return (
        <div>
            <div className="mb-2 flex items-center gap-1.5 text-xs font-bold tracking-wide text-muted-foreground uppercase">
                <Icon className="h-3.5 w-3.5" /> {title}
            </div>
            {items.length ? (
                <div className="flex flex-wrap gap-1.5">
                    {items.map((it, i) => (
                        <span key={i} className={cn('inline-flex items-center rounded-full border px-2.5 py-1 text-xs font-medium', chip)}>
                            {it}
                        </span>
                    ))}
                </div>
            ) : (
                <p className="text-sm text-muted-foreground">{empty}</p>
            )}
        </div>
    );
}

function AppliesSection({ detail }: { detail: ProcedureDetail }) {
    const a = detail.applies;
    return (
        <div className="flex flex-col gap-4">
            <div>
                <div className="mb-2 flex items-center gap-1.5 text-xs font-bold tracking-wide text-muted-foreground uppercase">
                    <Users className="h-3.5 w-3.5" /> Roles
                </div>
                {a.roles.length ? (
                    <div className="flex flex-wrap gap-1.5">
                        {a.roles.map((r) => (
                            <span key={r.key} className="inline-flex items-center rounded-full border border-border bg-card px-2.5 py-1 text-xs font-medium text-foreground">
                                {r.label}
                            </span>
                        ))}
                    </div>
                ) : (
                    <p className="text-sm text-muted-foreground">Applies to all roles.</p>
                )}
            </div>
            <div>
                <div className="mb-2 flex items-center gap-1.5 text-xs font-bold tracking-wide text-muted-foreground uppercase">
                    <MapPin className="h-3.5 w-3.5" /> Sites
                </div>
                {a.sites.length ? (
                    <div className="flex flex-wrap gap-1.5">
                        {a.sites.map((s) => (
                            <span key={String(s.id)} className="inline-flex items-center rounded-full border border-border bg-card px-2.5 py-1 text-xs font-medium text-foreground">
                                {s.name}
                            </span>
                        ))}
                    </div>
                ) : (
                    <p className="text-sm text-muted-foreground">Applies organisation-wide (all sites).</p>
                )}
            </div>
            <div>
                <div className="mb-2 flex items-center gap-1.5 text-xs font-bold tracking-wide text-muted-foreground uppercase">
                    <GraduationCap className="h-3.5 w-3.5" /> Related training
                </div>
                {a.training.length ? (
                    <div className="flex flex-wrap gap-1.5">
                        {a.training.map((t) => (
                            <span key={String(t.id)} className="inline-flex items-center gap-1.5 rounded-full border border-border bg-card px-2.5 py-1 text-xs font-medium text-foreground">
                                {t.name}
                                {t.code ? <span className="text-[10px] font-bold text-muted-foreground">{t.code}</span> : null}
                            </span>
                        ))}
                    </div>
                ) : (
                    <p className="text-sm text-muted-foreground">No related training linked.</p>
                )}
            </div>
        </div>
    );
}

function DocumentsSection({ detail }: { detail: ProcedureDetail }) {
    const canManage = detail.can.manage;
    return (
        <div className="flex flex-col gap-4">
            <p className="text-sm text-muted-foreground">
                The controlled master document (PDF / Word) plus any supporting files. Each file is stamped with the procedure version in force when it was attached.
            </p>

            {detail.attachments.length ? (
                <div className="flex flex-col gap-2">
                    {detail.attachments.map((a) => (
                        <GuardrailCard unstyled key={a.id} className="flex items-center gap-3 rounded-xl border border-border bg-card/70 p-3">
                            <span className="grid h-10 w-10 shrink-0 place-items-center rounded-lg bg-primary/10 text-primary">
                                <FileText className="h-5 w-5" />
                            </span>
                            <div className="min-w-0 flex-1">
                                <div className="flex items-center gap-2">
                                    <span className="truncate text-[13px] font-semibold text-foreground">{a.original_name}</span>
                                    {a.version ? <span className="shrink-0 rounded-full bg-muted px-1.5 py-0.5 text-[10px] font-bold text-muted-foreground">v{a.version}</span> : null}
                                </div>
                                <div className="truncate text-[11px] text-muted-foreground">
                                    {formatFileSize(a.size ?? 0)}
                                    {a.uploaded_by_name ? ` · ${a.uploaded_by_name}` : ''}
                                    {a.created_at ? ` · ${formatDateTime(a.created_at)}` : ''}
                                    {a.description ? ` · ${a.description}` : ''}
                                </div>
                            </div>
                            <a href={a.url} className="shrink-0 rounded-md p-1.5 text-muted-foreground transition-colors hover:bg-muted hover:text-foreground" title="Download" aria-label={`Download ${a.original_name}`}>
                                <Download className="h-4 w-4" />
                            </a>
                            {canManage ? (
                                <Button unstyled
                                    type="button"
                                    onClick={() => router.delete(`${PROCEDURES_URL}/${detail.id}/attachments/${a.id}`, { preserveScroll: true, preserveState: true })}
                                    className="shrink-0 rounded-md p-1.5 text-muted-foreground transition-colors hover:bg-status-critical-bg hover:text-status-critical"
                                    title="Remove"
                                    aria-label={`Remove ${a.original_name}`}
                                >
                                    <Trash2 className="h-4 w-4" />
                                </Button>
                            ) : null}
                        </GuardrailCard>
                    ))}
                </div>
            ) : (
                <EmptyNote icon={FilePlus2}>No documents attached yet.</EmptyNote>
            )}

            {canManage ? (
                <div className="rounded-xl border border-dashed border-border p-1">
                    <AttachmentUploader
                        endpoint={`${PROCEDURES_URL}/${detail.id}/attachments`}
                        noteField="description"
                        accept="application/pdf,.doc,.docx,.xls,.xlsx,image/*"
                        hint="Master PDF / Word doc + supporting files — up to 20 MB each"
                    />
                </div>
            ) : null}
        </div>
    );
}

function HistorySection({ detail }: { detail: ProcedureDetail }) {
    if (!detail.versions.length) {
        return <EmptyNote icon={History}>No version history yet.</EmptyNote>;
    }
    return (
        <ol className="relative ml-1 flex flex-col gap-4 border-l border-border pl-5">
            {detail.versions.map((v) => (
                <li key={v.id} className="relative">
                    <span className="absolute -left-[26px] grid h-4 w-4 place-items-center rounded-full bg-primary text-primary-foreground ring-4 ring-background">
                        <span className="text-[8px] font-bold">{v.version}</span>
                    </span>
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <span className="text-sm font-semibold text-foreground">Version {v.version}</span>
                        <span className="text-xs text-muted-foreground">{v.created_at ? formatDateTime(v.created_at) : ''}</span>
                    </div>
                    {v.change_summary ? <p className="mt-0.5 text-xs text-muted-foreground">{v.change_summary}</p> : null}
                    {v.changed_by ? <p className="mt-0.5 text-[11px] text-muted-foreground">by {v.changed_by.name}</p> : null}
                </li>
            ))}
        </ol>
    );
}

function EmptyNote({ icon: Icon, children }: { icon: LucideIcon; children: ReactNode }) {
    return (
        <div className="flex flex-col items-center gap-2 rounded-xl border border-dashed border-border px-4 py-12 text-center">
            <Icon className="h-8 w-8 text-muted-foreground/40" />
            <p className="text-sm text-muted-foreground">{children}</p>
        </div>
    );
}

/* ------------------------------------------------------------------ */
/*  Lifecycle action panes (post in-place)                             */
/* ------------------------------------------------------------------ */

const ACTION_META: Record<ProcedureActionKey, { title: string; blurb: string; icon: LucideIcon; verb: string; endpoint: (id: number) => string; tone: 'primary' | 'critical' }> = {
    submit_review: { title: 'Submit for review', blurb: 'Send this draft to the H&S lead for approval.', icon: Send, verb: 'Submit for review', endpoint: (id) => `${PROCEDURES_URL}/${id}/submit-for-review`, tone: 'primary' },
    approve: { title: 'Approve procedure', blurb: 'Sign off this procedure as approved and in force.', icon: CheckCircle2, verb: 'Approve', endpoint: (id) => `${PROCEDURES_URL}/${id}/approve`, tone: 'primary' },
    request_changes: { title: 'Request changes', blurb: 'Return this procedure to draft with a note for the author.', icon: RotateCcw, verb: 'Return to draft', endpoint: (id) => `${PROCEDURES_URL}/${id}/request-changes`, tone: 'primary' },
    record_review: { title: 'Record review', blurb: 'Log that this procedure was reviewed and set the next review date.', icon: CalendarCheck, verb: 'Record review', endpoint: (id) => `${PROCEDURES_URL}/${id}/record-review`, tone: 'primary' },
    archive: { title: 'Archive procedure', blurb: 'Retire this procedure from the live library. It can be restored later.', icon: Archive, verb: 'Archive', endpoint: (id) => `${PROCEDURES_URL}/${id}/archive`, tone: 'critical' },
    restore: { title: 'Restore procedure', blurb: 'Bring this procedure back to its previous status.', icon: ArchiveRestore, verb: 'Restore', endpoint: (id) => `${PROCEDURES_URL}/${id}/restore`, tone: 'primary' },
};

function defaultNextReview(detail: ProcedureDetail): string {
    const months = detail.review_frequency_months ?? 12;
    const d = new Date();
    d.setMonth(d.getMonth() + months);
    return d.toISOString().slice(0, 10);
}

function ActionPane({ kind, detail, onDone, onCancel }: { kind: ProcedureActionKey; detail: ProcedureDetail; onDone: () => void; onCancel: () => void }) {
    const meta = ACTION_META[kind];
    const form = useForm<{ note: string; review_date: string }>({
        note: '',
        review_date: kind === 'record_review' || kind === 'approve' ? defaultNextReview(detail) : '',
    });

    const submit = () => {
        const payload: Record<string, string> = {};
        if (kind === 'request_changes' || kind === 'approve') payload.note = form.data.note;
        if (kind === 'record_review' || kind === 'approve') payload.review_date = form.data.review_date;
        form.transform(() => payload);
        form.post(meta.endpoint(detail.id), {
            preserveScroll: true,
            preserveState: true,
            onSuccess: (page) => {
                const flash = (page.props as { flash?: { error?: string } }).flash;
                if (!flash?.error) onDone();
            },
        });
    };

    const showReview = kind === 'record_review' || kind === 'approve';
    const showNote = kind === 'request_changes' || kind === 'approve' || kind === 'record_review';
    const reviewRequired = kind === 'record_review';

    return (
        <div className="flex flex-col gap-5">
            <StepHead icon={meta.icon} title={meta.title} blurb={meta.blurb} />

            {kind === 'approve' ? (
                <InfoCard icon={Info} tone="info">
                    Approving signs the procedure off as <strong>in force</strong> — recorded against you and dated now. A content edit later returns it to under-review.
                </InfoCard>
            ) : null}
            {kind === 'archive' ? (
                <InfoCard icon={AlertTriangle} tone="warn">
                    Archiving removes <strong>{detail.reference_number}</strong> from the live library. Linked records keep their reference; you can restore it from the Archived tab.
                </InfoCard>
            ) : null}

            {showReview ? (
                <Field label={kind === 'approve' ? 'Next review date' : 'Next review date'} required={reviewRequired} hint={detail.review_frequency_months ? `cadence: every ${detail.review_frequency_months} months` : undefined}>
                    <Input type="date" value={form.data.review_date} onChange={(e) => form.setData('review_date', e.target.value)} />
                </Field>
            ) : null}

            {showNote ? (
                <Field label={kind === 'request_changes' ? 'Reason / note for the author' : 'Note'} hint="optional · added to the version history">
                    <Textarea rows={3} value={form.data.note} onChange={(e) => form.setData('note', e.target.value)} placeholder={kind === 'request_changes' ? 'What needs to change before this can be approved?' : 'Add a note…'} />
                </Field>
            ) : null}

            {!showReview && !showNote ? (
                <p className="text-sm text-muted-foreground">
                    {kind === 'submit_review'
                        ? `Submit ${detail.reference_number} for review?`
                        : kind === 'restore'
                          ? `Restore ${detail.reference_number} to ${detail.previous_status ? titleCase(detail.previous_status) : 'draft'}?`
                          : `Confirm: ${meta.verb} ${detail.reference_number}?`}
                </p>
            ) : null}

            <div className="flex items-center justify-end gap-2 border-t border-border pt-4">
                <Button type="button" variant="ghost" onClick={onCancel} disabled={form.processing}>
                    Cancel
                </Button>
                <Button
                    type="button"
                    onClick={submit}
                    disabled={form.processing || (reviewRequired && !form.data.review_date)}
                    className={meta.tone === 'critical' ? 'bg-status-critical text-white hover:bg-status-critical/90' : undefined}
                >
                    <meta.icon className="mr-1.5 h-4 w-4" /> {meta.verb}
                </Button>
            </div>
        </div>
    );
}
