/* Worker Participation — Consultation detail dialog.
 *
 * The reference detail dialog for the register (the representative + meeting
 * dialogs mirror it). Built on the shared WizardShell: the wizard "steps" are
 * reused as a left rail of SECTIONS (Overview / Lifecycle / Documents). The
 * lifecycle Options bar lives in footerEnd and is suppressed while an inline
 * action pane owns the body. Each action is an inline pane (StepHead + fields +
 * its own Cancel/Submit row) that REPLACES the section body — never a nested
 * Dialog. NZ English, semantic design tokens only. */
import { Button } from '@/components/ui/button';
import { FileDropzone, StagedFileCard } from '@/components/ui/file-dropzone';
import { Input } from '@/components/ui/input';
import {
    Field,
    InfoCard,
    Segmented,
    StepHead,
} from '@/components/wizard/primitives';
import { ReviewCard, ReviewRow, WizardShell } from '@/components/wizard/shell';
import {
    CONSULT_ORDER,
    CONSULT_STAGES,
    CONSULT_STATUS,
    CONSULTATION_TYPES,
    consultationTypeLabel,
    fmtDate,
    WP_BASE,
    type ConsultationDetail,
    type WpCan,
    type WpDetailAction,
} from '@/components/worker-participation/shared';
import type { Tone } from '@/pages/health-safety/components/register-row-kit';
import { useForm } from '@inertiajs/react';
import {
    CheckCircle2,
    ClipboardList,
    Download,
    ExternalLink,
    FileCheck2,
    FileText,
    ListChecks,
    MapPin,
    MessageSquare,
    Pencil,
    Upload,
    UserCircle2,
    XCircle,
    type LucideIcon,
} from 'lucide-react';
import {
    useState,
    type ComponentType,
    type FormEvent,
    type ReactNode,
} from 'react';

/* ------------------------------------------------------------------ */
/*  Local tokens (dialog uses shared CONSULT_STATUS + a tiny dot map)   */
/* ------------------------------------------------------------------ */

const DOT: Record<Tone, string> = {
    success: 'bg-status-success',
    warning: 'bg-status-warning',
    critical: 'bg-status-critical',
    neutral: 'bg-muted-foreground',
};

const TEXTAREA_CLASS =
    'w-full rounded-lg border border-border bg-background p-2.5 text-sm focus-visible:ring-2 focus-visible:ring-primary focus-visible:outline-none';

type SectionKey = 'overview' | 'lifecycle' | 'documents';

/* ------------------------------------------------------------------ */
/*  Dialog                                                             */
/* ------------------------------------------------------------------ */

export function ConsultationDetailDialog({
    detail,
    open,
    onClose,
    sites,
    staff,
    can,
    initialAction,
}: {
    detail: ConsultationDetail;
    open: boolean;
    onClose: () => void;
    sites: { id: number; name: string }[];
    staff: { id: number; name: string }[];
    can: WpCan;
    initialAction: WpDetailAction | null;
}) {
    // The dialog is keyed by consultation id in the list, so it mounts fresh on
    // each open — right-click → action lands on a specific pane via initialAction.
    const initialSection: SectionKey =
        initialAction === 'feedback' ||
        initialAction === 'outcome' ||
        initialAction === 'close'
            ? 'lifecycle'
            : initialAction === 'upload'
              ? 'documents'
              : 'overview';
    const [section, setSection] = useState<SectionKey>(initialSection);
    const [action, setAction] = useState<WpDetailAction | null>(initialAction);
    const d = detail;

    const st = CONSULT_STATUS[d.status] ?? CONSULT_STATUS.open;
    const closed = d.status === 'closed';
    const docs = (d.document_path ? 1 : 0) + (d.outcome_document_path ? 1 : 0);

    const SECTIONS: {
        key: SectionKey;
        label: string;
        blurb: string;
        icon: ComponentType<{ className?: string }>;
    }[] = [
        {
            key: 'overview',
            label: 'Overview',
            blurb: 'Topic, site & detail',
            icon: FileText,
        },
        {
            key: 'lifecycle',
            label: 'Lifecycle',
            blurb: `${st.label} · feedback → outcome`,
            icon: ListChecks,
        },
        {
            key: 'documents',
            label: 'Documents',
            blurb: `${docs} file${docs === 1 ? '' : 's'} attached`,
            icon: ClipboardList,
        },
    ];
    const stepIndex = Math.max(
        0,
        SECTIONS.findIndex((s) => s.key === section),
    );

    const footerStart = (
        <div className="flex flex-wrap items-center gap-2 text-xs">
            <span className="inline-flex items-center gap-1.5 rounded-md bg-muted px-2 py-0.5 font-medium text-foreground">
                <MessageSquare className="h-3 w-3" />{' '}
                {consultationTypeLabel(d.consultation_type)}
            </span>
            <span className="inline-flex items-center gap-1.5 rounded-full bg-muted px-2 py-0.5 font-medium">
                <span className={`h-1.5 w-1.5 rounded-full ${DOT[st.tone]}`} />
                {st.label}
            </span>
        </div>
    );

    // Gated Options bar — manage-only, hidden once a consultation is closed and
    // suppressed while an action pane owns the body (the pane renders its own row).
    const footerEnd =
        action || !can.manage || closed ? null : (
            <div className="flex flex-wrap items-center justify-end gap-2">
                <OptionBtn
                    icon={MessageSquare}
                    label="Record feedback"
                    onClick={() => setAction('feedback')}
                />
                <OptionBtn
                    icon={FileCheck2}
                    label="Record outcome"
                    onClick={() => setAction('outcome')}
                />
                <OptionBtn
                    icon={Upload}
                    label="Upload document"
                    onClick={() => setAction('upload')}
                />
                <OptionBtn
                    icon={XCircle}
                    label="Close consultation"
                    tone="critical"
                    onClick={() => setAction('close')}
                />
            </div>
        );

    return (
        <WizardShell
            open={open}
            onClose={onClose}
            title={`Consultation — ${d.title}`}
            description={`${consultationTypeLabel(d.consultation_type)} · ${st.label}`}
            railIcon={MessageSquare}
            railTitle={d.title}
            railSub={`${consultationTypeLabel(d.consultation_type)} · ${d.site?.name ?? 'All sites'}`}
            steps={SECTIONS}
            stepIndex={stepIndex}
            onStepClick={(i) => {
                setAction(null); // leaving an open pane returns to its section
                setSection(SECTIONS[i].key);
            }}
            footerStart={footerStart}
            footerEnd={footerEnd}
        >
            {action === 'edit' ? (
                <EditPane d={d} onDone={() => setAction(null)} />
            ) : action === 'feedback' ? (
                <FeedbackPane d={d} onDone={() => setAction(null)} />
            ) : action === 'outcome' ? (
                <OutcomePane d={d} onDone={() => setAction(null)} />
            ) : action === 'upload' ? (
                <UploadPane d={d} onDone={() => setAction(null)} />
            ) : action === 'close' ? (
                <ClosePane d={d} onDone={() => setAction(null)} />
            ) : (
                <>
                    {section === 'overview' ? (
                        <OverviewSection
                            d={d}
                            onEdit={
                                can.manage && !closed
                                    ? () => setAction('edit')
                                    : undefined
                            }
                        />
                    ) : null}
                    {section === 'lifecycle' ? (
                        <LifecycleSection d={d} />
                    ) : null}
                    {section === 'documents' ? (
                        <DocumentsSection d={d} />
                    ) : null}
                </>
            )}
        </WizardShell>
    );
}

/* ------------------------------------------------------------------ */
/*  Options bar button                                                  */
/* ------------------------------------------------------------------ */

function OptionBtn({
    icon: Icon,
    label,
    onClick,
    tone,
}: {
    icon: ComponentType<{ className?: string }>;
    label: string;
    onClick: () => void;
    tone?: 'critical';
}) {
    return (
        <Button
            size="sm"
            variant="outline"
            onClick={onClick}
            className={
                tone === 'critical'
                    ? 'text-status-critical hover:text-status-critical'
                    : undefined
            }
        >
            <Icon className="mr-1.5 h-4 w-4" /> {label}
        </Button>
    );
}

/* ------------------------------------------------------------------ */
/*  Sections                                                            */
/* ------------------------------------------------------------------ */

function OverviewSection({
    d,
    onEdit,
}: {
    d: ConsultationDetail;
    onEdit?: () => void;
}) {
    return (
        <div className="grid gap-4 sm:grid-cols-2">
            <ReviewCard
                icon={MessageSquare}
                title="Consultation"
                span
                onEdit={onEdit}
            >
                <ReviewRow label="Topic" value={d.title} />
                <ReviewRow
                    label="Type"
                    value={consultationTypeLabel(d.consultation_type)}
                />
                <ReviewRow label="Site" value={d.site?.name} />
                <ReviewRow label="Date" value={fmtDate(d.consultation_date)} />
                <ReviewRow label="Initiated by" value={d.initiated_by_name} />
            </ReviewCard>

            <ReviewCard icon={FileText} title="What is being consulted on" span>
                <p className="text-sm whitespace-pre-wrap text-foreground">
                    {d.description || '—'}
                </p>
            </ReviewCard>

            {d.site?.name ? (
                <div className="sm:col-span-2">
                    <span className="inline-flex items-center gap-1.5 text-xs text-muted-foreground">
                        <MapPin className="h-3.5 w-3.5" /> {d.site.name}
                    </span>
                </div>
            ) : null}
        </div>
    );
}

function LifecycleSection({ d }: { d: ConsultationDetail }) {
    const currentIdx = CONSULT_STAGES.findIndex((s) => s.key === d.status);
    const idx = d.stage_index ?? (currentIdx >= 0 ? currentIdx : 0);

    return (
        <div className="flex flex-col gap-5">
            <p className="text-[11px] font-semibold tracking-wide text-muted-foreground uppercase">
                Consultation lifecycle
            </p>
            <ol className="relative ml-2 border-l border-border">
                {CONSULT_STAGES.map((stage, i) => {
                    const done = i < idx;
                    const current = i === idx;
                    const tone: Tone = done
                        ? 'success'
                        : current
                          ? 'warning'
                          : 'neutral';
                    return (
                        <li key={stage.key} className="mb-6 ml-5 last:mb-0">
                            <span
                                className={`absolute -left-[7px] flex h-3.5 w-3.5 items-center justify-center rounded-full ${
                                    done
                                        ? 'bg-status-success'
                                        : current
                                          ? 'bg-primary'
                                          : 'bg-muted-foreground/40'
                                }`}
                            >
                                {done ? (
                                    <CheckCircle2 className="h-3 w-3 text-primary-foreground" />
                                ) : null}
                            </span>
                            <div className="flex flex-wrap items-center gap-2">
                                <span
                                    className={`text-sm font-semibold ${current || done ? 'text-foreground' : 'text-muted-foreground'}`}
                                >
                                    {stage.label}
                                </span>
                                <StageChip tone={tone}>
                                    {done
                                        ? 'Done'
                                        : current
                                          ? 'Current'
                                          : 'Upcoming'}
                                </StageChip>
                            </div>
                            <p className="mt-0.5 text-xs text-muted-foreground">
                                {stage.blurb}
                            </p>

                            {stage.key === 'feedback_received' &&
                            (done || current) ? (
                                <DetailNote
                                    icon={MessageSquare}
                                    title="Worker feedback"
                                    body={d.worker_feedback_summary}
                                />
                            ) : null}
                            {stage.key === 'actioned' && (done || current) ? (
                                <>
                                    <DetailNote
                                        icon={FileCheck2}
                                        title="Outcome"
                                        body={d.outcome}
                                    />
                                    <DetailNote
                                        icon={ListChecks}
                                        title="Changes made"
                                        body={d.changes_made}
                                    />
                                </>
                            ) : null}
                        </li>
                    );
                })}
            </ol>
        </div>
    );
}

function DocumentsSection({ d }: { d: ConsultationDetail }) {
    return (
        <div className="flex flex-col gap-3">
            <DocRow
                icon={FileText}
                label="Supporting document"
                name={d.document_name}
                present={!!d.document_path}
                href={`${WP_BASE}/consultations/${d.id}/documents/document`}
            />
            <DocRow
                icon={FileCheck2}
                label="Outcome document"
                name={d.outcome_document_name}
                present={!!d.outcome_document_path}
                href={`${WP_BASE}/consultations/${d.id}/documents/outcome`}
            />
        </div>
    );
}

/* ------------------------------------------------------------------ */
/*  Section helpers                                                     */
/* ------------------------------------------------------------------ */

function StageChip({ tone, children }: { tone: Tone; children: ReactNode }) {
    const cls: Record<Tone, string> = {
        success: 'bg-status-success-bg text-status-success',
        warning: 'bg-primary/10 text-primary',
        critical: 'bg-status-critical-bg text-status-critical',
        neutral: 'bg-muted text-muted-foreground',
    };
    return (
        <span
            className={`inline-flex rounded-full px-2 py-0.5 text-[11px] font-medium ${cls[tone]}`}
        >
            {children}
        </span>
    );
}

function DetailNote({
    icon: Icon,
    title,
    body,
}: {
    icon: LucideIcon;
    title: string;
    body: string | null | undefined;
}) {
    return (
        <div className="mt-2 rounded-lg border border-border bg-muted/30 p-3">
            <p className="mb-1 flex items-center gap-1.5 text-[11px] font-semibold tracking-wide text-muted-foreground uppercase">
                <Icon className="h-3 w-3" /> {title}
            </p>
            <p className="text-[13px] whitespace-pre-wrap text-foreground">
                {body || '—'}
            </p>
        </div>
    );
}

function DocRow({
    icon: Icon,
    label,
    name,
    present,
    href,
}: {
    icon: LucideIcon;
    label: string;
    name: string | null | undefined;
    present: boolean;
    href: string;
}) {
    return (
        // eslint-disable-next-line no-restricted-syntax -- document row: file glyph + name + download affordance on one bespoke surface
        <div className="flex items-center justify-between gap-3 rounded-xl border border-border bg-card/70 p-3.5">
            <div className="flex min-w-0 items-center gap-3">
                <span
                    className={`grid h-10 w-10 shrink-0 place-items-center rounded-lg ${
                        present
                            ? 'bg-primary/10 text-primary'
                            : 'bg-muted text-muted-foreground/50'
                    }`}
                >
                    <Icon className="h-5 w-5" />
                </span>
                <div className="min-w-0">
                    <p className="text-[13px] font-semibold text-foreground">
                        {label}
                    </p>
                    <p className="truncate text-xs text-muted-foreground">
                        {present
                            ? (name ?? 'Document attached')
                            : 'None attached'}
                    </p>
                </div>
            </div>
            {present ? (
                <a
                    href={href}
                    className="inline-flex shrink-0 items-center gap-1.5 rounded-lg px-3 py-2 text-sm font-medium text-primary transition-colors hover:bg-muted"
                >
                    <Download className="h-4 w-4" /> Download
                </a>
            ) : (
                <span className="shrink-0 text-xs text-muted-foreground/60">
                    —
                </span>
            )}
        </div>
    );
}

/* ------------------------------------------------------------------ */
/*  Action panes                                                        */
/* ------------------------------------------------------------------ */

/** Shared submit chrome: a Cancel + Submit row beneath the pane's fields. */
function PaneShell({
    children,
    onCancel,
    onSubmit,
    cta,
    ctaIcon: CtaIcon,
    ctaTone,
    processing,
}: {
    children: ReactNode;
    onCancel: () => void;
    onSubmit: (e: FormEvent) => void;
    cta: string;
    ctaIcon?: ComponentType<{ className?: string }>;
    ctaTone?: 'critical';
    processing: boolean;
}) {
    return (
        <form onSubmit={onSubmit} className="flex flex-col gap-4">
            {children}
            <div className="flex justify-end gap-2">
                <Button type="button" variant="outline" onClick={onCancel}>
                    Cancel
                </Button>
                <Button
                    type="submit"
                    disabled={processing}
                    className={
                        ctaTone === 'critical'
                            ? 'bg-status-critical text-primary-foreground hover:bg-status-critical/90'
                            : undefined
                    }
                >
                    {CtaIcon ? <CtaIcon className="mr-1.5 h-4 w-4" /> : null}
                    {cta}
                </Button>
            </div>
        </form>
    );
}

function EditPane({
    d,
    onDone,
}: {
    d: ConsultationDetail;
    onDone: () => void;
}) {
    const form = useForm<{
        title: string;
        consultation_type: string;
        description: string;
        consultation_date: string;
    }>({
        title: d.title ?? '',
        consultation_type: d.consultation_type ?? 'general',
        description: d.description ?? '',
        consultation_date: (d.consultation_date ?? '').slice(0, 10),
    });
    const submit = (e: FormEvent) => {
        e.preventDefault();
        if (!form.data.title.trim()) {
            form.setError('title', 'Give the consultation a topic.');
            return;
        }
        if (!form.data.description.trim()) {
            form.setError(
                'description',
                'Describe what is being consulted on.',
            );
            return;
        }
        form.put(`${WP_BASE}/consultations/${d.id}`, {
            preserveScroll: true,
            onSuccess: (page) => {
                const flash = page.props.flash as
                    | { error?: string }
                    | undefined;
                if (!flash?.error) onDone();
            },
        });
    };
    return (
        <>
            <StepHead
                icon={Pencil}
                title="Edit consultation"
                blurb="Update the topic, type, detail or date kaimahi were consulted."
            />
            <PaneShell
                onCancel={onDone}
                onSubmit={submit}
                cta="Save changes"
                processing={form.processing}
            >
                <Field label="Topic" required error={form.errors.title}>
                    <Input
                        value={form.data.title}
                        onChange={(e) => form.setData('title', e.target.value)}
                        placeholder="e.g. New manual-handling procedure"
                    />
                </Field>
                <Field
                    label="Consultation type"
                    required
                    error={form.errors.consultation_type}
                >
                    <Segmented
                        value={form.data.consultation_type}
                        onChange={(v) => form.setData('consultation_type', v)}
                        options={CONSULTATION_TYPES.map((t) => ({
                            value: t.key,
                            label: t.label,
                            icon: t.icon,
                        }))}
                    />
                </Field>
                <Field
                    label="What is being consulted on"
                    required
                    error={form.errors.description}
                >
                    <textarea
                        className={TEXTAREA_CLASS}
                        rows={4}
                        value={form.data.description}
                        onChange={(e) =>
                            form.setData('description', e.target.value)
                        }
                        placeholder="Describe the work, change or matter affecting kaimahi."
                    />
                </Field>
                <Field
                    label="Consultation date"
                    error={form.errors.consultation_date}
                >
                    <Input
                        type="date"
                        value={form.data.consultation_date}
                        onChange={(e) =>
                            form.setData('consultation_date', e.target.value)
                        }
                    />
                </Field>
            </PaneShell>
        </>
    );
}

function FeedbackPane({
    d,
    onDone,
}: {
    d: ConsultationDetail;
    onDone: () => void;
}) {
    // Non-regressing: never downgrade the lifecycle — send the LATER of the
    // current status and this pane's milestone.
    const target =
        CONSULT_ORDER[d.status] >= CONSULT_ORDER['feedback_received']
            ? d.status
            : 'feedback_received';
    const form = useForm<{ status: string; worker_feedback_summary: string }>({
        status: target,
        worker_feedback_summary: d.worker_feedback_summary ?? '',
    });
    const submit = (e: FormEvent) => {
        e.preventDefault();
        if (!form.data.worker_feedback_summary.trim()) {
            form.setError(
                'worker_feedback_summary',
                'Summarise the feedback kaimahi gave.',
            );
            return;
        }
        form.put(`${WP_BASE}/consultations/${d.id}/status`, {
            preserveScroll: true,
            onSuccess: (page) => {
                const flash = page.props.flash as
                    | { error?: string }
                    | undefined;
                if (!flash?.error) onDone();
            },
        });
    };
    return (
        <>
            <StepHead
                icon={MessageSquare}
                title="Record worker feedback"
                blurb="Capture what kaimahi said — this moves the consultation to Feedback received."
            />
            <PaneShell
                onCancel={onDone}
                onSubmit={submit}
                cta="Record feedback"
                ctaIcon={MessageSquare}
                processing={form.processing}
            >
                <Field
                    label="Worker feedback summary"
                    required
                    error={form.errors.worker_feedback_summary}
                >
                    <textarea
                        className={TEXTAREA_CLASS}
                        rows={5}
                        value={form.data.worker_feedback_summary}
                        onChange={(e) =>
                            form.setData(
                                'worker_feedback_summary',
                                e.target.value,
                            )
                        }
                        placeholder="Summarise the views, concerns and suggestions raised by kaimahi."
                    />
                </Field>
            </PaneShell>
        </>
    );
}

function OutcomePane({
    d,
    onDone,
}: {
    d: ConsultationDetail;
    onDone: () => void;
}) {
    // Non-regressing: never downgrade the lifecycle — send the LATER of the
    // current status and this pane's milestone.
    const target =
        CONSULT_ORDER[d.status] >= CONSULT_ORDER['actioned']
            ? d.status
            : 'actioned';
    const form = useForm<{
        status: string;
        outcome: string;
        changes_made: string;
    }>({
        status: target,
        outcome: d.outcome ?? '',
        changes_made: d.changes_made ?? '',
    });
    const submit = (e: FormEvent) => {
        e.preventDefault();
        if (!form.data.outcome.trim()) {
            form.setError('outcome', 'Record the outcome of the consultation.');
            return;
        }
        form.put(`${WP_BASE}/consultations/${d.id}/status`, {
            preserveScroll: true,
            onSuccess: (page) => {
                const flash = page.props.flash as
                    | { error?: string }
                    | undefined;
                if (!flash?.error) onDone();
            },
        });
    };
    return (
        <>
            <StepHead
                icon={FileCheck2}
                title="Record outcome"
                blurb="Document the decision and what changed as a result — this moves the consultation to Actioned."
            />
            <PaneShell
                onCancel={onDone}
                onSubmit={submit}
                cta="Record outcome"
                ctaIcon={FileCheck2}
                processing={form.processing}
            >
                <Field label="Outcome" required error={form.errors.outcome}>
                    <textarea
                        className={TEXTAREA_CLASS}
                        rows={4}
                        value={form.data.outcome}
                        onChange={(e) =>
                            form.setData('outcome', e.target.value)
                        }
                        placeholder="What was decided after considering the feedback?"
                    />
                </Field>
                <Field
                    label="Changes made"
                    hint="Optional"
                    error={form.errors.changes_made}
                >
                    <textarea
                        className={TEXTAREA_CLASS}
                        rows={3}
                        value={form.data.changes_made}
                        onChange={(e) =>
                            form.setData('changes_made', e.target.value)
                        }
                        placeholder="What changed to the work, procedure or equipment as a result?"
                    />
                </Field>
            </PaneShell>
        </>
    );
}

function UploadPane({
    d,
    onDone,
}: {
    d: ConsultationDetail;
    onDone: () => void;
}) {
    const { data, setData, post, processing, errors } = useForm<{
        document: File | null;
        type: 'document' | 'outcome';
    }>({
        document: null,
        type: 'document',
    });
    const submit = () => {
        if (!data.document) return;
        post(`${WP_BASE}/consultations/${d.id}/documents`, {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: (page) => {
                const flash = page.props.flash as
                    | { error?: string }
                    | undefined;
                setData('document', null);
                if (!flash?.error) onDone();
            },
        });
    };
    return (
        <>
            <StepHead
                icon={Upload}
                title="Upload a document"
                blurb="Attach the supporting material or the signed-off outcome for this consultation."
            />
            <form
                onSubmit={(e) => {
                    e.preventDefault();
                    submit();
                }}
                className="flex flex-col gap-4"
            >
                <Field label="Document type" required>
                    <Segmented
                        value={data.type}
                        onChange={(v) =>
                            setData('type', v as 'document' | 'outcome')
                        }
                        options={[
                            {
                                value: 'document',
                                label: 'Supporting document',
                                icon: FileText,
                            },
                            {
                                value: 'outcome',
                                label: 'Outcome document',
                                icon: FileCheck2,
                            },
                        ]}
                    />
                </Field>
                <Field label="File" required error={errors.document}>
                    {data.document ? (
                        <StagedFileCard
                            file={data.document}
                            onRemove={() => setData('document', null)}
                        />
                    ) : (
                        <FileDropzone
                            multiple={false}
                            accept=".pdf,.doc,.docx,.xlsx,.jpg,.jpeg,.png"
                            hint="PDF, Word, Excel or image — up to 20 MB"
                            onFiles={(f) => setData('document', f[0] ?? null)}
                        />
                    )}
                </Field>
                <div className="flex justify-end gap-2">
                    <Button type="button" variant="outline" onClick={onDone}>
                        Cancel
                    </Button>
                    <Button
                        type="submit"
                        disabled={processing || !data.document}
                    >
                        <Upload className="mr-1.5 h-4 w-4" /> Upload document
                    </Button>
                </div>
            </form>
        </>
    );
}

function ClosePane({
    d,
    onDone,
}: {
    d: ConsultationDetail;
    onDone: () => void;
}) {
    const form = useForm<{ status: 'closed' }>({ status: 'closed' });
    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.put(`${WP_BASE}/consultations/${d.id}/status`, {
            preserveScroll: true,
            onSuccess: (page) => {
                const flash = page.props.flash as
                    | { error?: string }
                    | undefined;
                if (!flash?.error) onDone();
            },
        });
    };
    return (
        <>
            <StepHead
                icon={XCircle}
                title="Close consultation"
                blurb="Mark this consultation as complete."
            />
            <PaneShell
                onCancel={onDone}
                onSubmit={submit}
                cta="Close consultation"
                ctaIcon={CheckCircle2}
                ctaTone="critical"
                processing={form.processing}
            >
                <InfoCard icon={CheckCircle2} tone="warn">
                    Closing records the consultation as complete. Make sure
                    worker feedback and the outcome have been captured first — a
                    closed consultation can no longer be edited from this
                    dialog.
                </InfoCard>
                <div className="rounded-xl border border-border bg-muted/20 p-3.5">
                    <p className="flex items-center gap-1.5 text-[13px] font-semibold text-foreground">
                        <UserCircle2 className="h-4 w-4 text-muted-foreground" />{' '}
                        {d.title}
                    </p>
                    <p className="mt-1 text-xs text-muted-foreground">
                        {consultationTypeLabel(d.consultation_type)} ·{' '}
                        {d.site?.name ?? 'All sites'} ·{' '}
                        {fmtDate(d.consultation_date)}
                    </p>
                </div>
                {!d.worker_feedback_summary || !d.outcome ? (
                    <p className="flex items-center gap-1.5 text-xs text-status-warning">
                        <ExternalLink className="h-3.5 w-3.5" />
                        {!d.worker_feedback_summary && !d.outcome
                            ? 'No feedback or outcome recorded yet.'
                            : !d.worker_feedback_summary
                              ? 'No worker feedback recorded yet.'
                              : 'No outcome recorded yet.'}
                    </p>
                ) : null}
            </PaneShell>
        </>
    );
}
