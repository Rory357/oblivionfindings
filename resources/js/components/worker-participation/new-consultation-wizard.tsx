/* New consultation create wizard for the Worker Participation register.
 * Consumes the shared WizardShell chrome (the caller owns stepIndex + footer
 * buttons per the kit contract) and mirrors the Add Client wizard flow: gate
 * each step, jump to the first failing step on submit, premium single-file
 * upload staged onto the form, and a green-check success pane.
 * Every colour comes from semantic design tokens. */
import { Button } from '@/components/ui/button';
import { FileDropzone, StagedFileCard } from '@/components/ui/file-dropzone';
import {
    Field,
    InfoCard,
    Ring,
    SelectInput,
    StepHead,
    TilePicker,
} from '@/components/wizard/primitives';
import {
    ReviewCard,
    ReviewRow,
    WizardShell,
    WizardStepPane,
    WizardSuccessPane,
    type WizardStep,
} from '@/components/wizard/shell';
import {
    CONSULTATION_TYPES,
    WP_BASE,
    consultationTypeLabel,
    fmtDate,
} from '@/components/worker-participation/shared';
import { useForm } from '@inertiajs/react';
import {
    CheckCircle2,
    ChevronLeft,
    ChevronRight,
    FileUp,
    Info,
    MessageSquare,
    Target,
    Users,
} from 'lucide-react';
import { useMemo, useState } from 'react';

/* ------------------------------------------------------------------ */
/*  Props + form shape                                                 */
/* ------------------------------------------------------------------ */

type SiteOption = { id: number; name: string };
type StaffOption = { id: number; name: string };

type Props = {
    open: boolean;
    sites: SiteOption[];
    staff: StaffOption[];
    onClose: () => void;
};

type ConsultationForm = {
    title: string;
    consultation_type: string;
    description: string;
    site_id: string;
    consultation_date: string;
    workers_consulted: number[];
    document: File | null;
};

const STEPS: readonly WizardStep[] = [
    {
        key: 'topic',
        label: 'Topic & type',
        blurb: 'What is being consulted on',
        icon: MessageSquare,
    },
    {
        key: 'scope',
        label: 'Scope',
        blurb: 'Site, date & who is involved',
        icon: Target,
    },
    {
        key: 'documents',
        label: 'Documents',
        blurb: 'Supporting file (optional)',
        icon: FileUp,
    },
    {
        key: 'review',
        label: 'Review',
        blurb: 'Check & raise consultation',
        icon: CheckCircle2,
    },
] as const;

const textareaCls =
    'w-full rounded-lg border border-border bg-background p-2.5 text-sm focus-visible:ring-2 focus-visible:ring-primary focus-visible:outline-none';

/** Step indices for jumping (kept in sync with STEPS). */
const STEP_INDEX = {
    topic: STEPS.findIndex((s) => s.key === 'topic'),
    scope: STEPS.findIndex((s) => s.key === 'scope'),
    documents: STEPS.findIndex((s) => s.key === 'documents'),
    review: STEPS.findIndex((s) => s.key === 'review'),
} as const;

/* Non-gating server errors aren't covered by validateStep (which only checks the
 * required gating fields), so map them to the step that owns the field. Mirrors
 * the Add Client STEP_FOR_FIELD fallback. The longest matching prefix wins, so
 * nested keys like `workers_consulted.0` resolve to the Scope step. */
const STEP_FOR_FIELD: { prefix: string; step: number }[] = [
    { prefix: 'document', step: STEP_INDEX.documents },
    { prefix: 'workers_consulted', step: STEP_INDEX.scope },
];

/* Client-side file guard (mirrors the server upload rule). */
const MAX_UPLOAD_BYTES = 20 * 1024 * 1024;
const ACCEPT_EXTENSIONS = ['pdf', 'doc', 'docx', 'xlsx', 'jpg', 'jpeg', 'png'];
const ACCEPT_MIME = [
    'application/pdf',
    'application/msword',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'image/jpeg',
    'image/png',
];

/* ------------------------------------------------------------------ */
/*  Per-step validation (mirrors StoreConsultationRequest)             */
/* ------------------------------------------------------------------ */

function validateStep(
    key: string,
    data: ConsultationForm,
): Record<string, string> {
    const e: Record<string, string> = {};
    if (key === 'topic') {
        if (!data.title.trim())
            e.title = 'Give the consultation a clear title.';
        if (!data.consultation_type)
            e.consultation_type = 'Choose a consultation type.';
        if (!data.description.trim())
            e.description = 'Describe what kaimahi are being consulted on.';
    }
    if (key === 'scope') {
        if (!data.site_id) e.site_id = 'Select the site this affects.';
        if (!data.consultation_date)
            e.consultation_date = 'Set the consultation date.';
    }
    return e;
}

/* ------------------------------------------------------------------ */
/*  Wizard                                                             */
/* ------------------------------------------------------------------ */

export function NewConsultationWizard({ open, sites, staff, onClose }: Props) {
    const [stepIndex, setStepIndex] = useState(0);
    const [done, setDone] = useState(false);
    const [localErrors, setLocalErrors] = useState<Record<string, string>>({});
    const [fileError, setFileError] = useState<string | null>(null);

    const {
        data,
        setData,
        post,
        processing,
        errors,
        reset,
        clearErrors,
        transform,
    } = useForm<ConsultationForm>({
        title: '',
        consultation_type: 'hazard_review',
        description: '',
        site_id: '',
        consultation_date: '',
        workers_consulted: [],
        document: null,
    });

    const err = (name: keyof ConsultationForm): string | undefined =>
        localErrors[name] ?? (errors as Record<string, string>)[name];

    const cur = STEPS[stepIndex];
    const isReview = cur.key === 'review';

    /* rough completeness for the rail/review ring */
    const pct = useMemo(() => {
        const checks = [
            !!data.title.trim(),
            !!data.consultation_type,
            !!data.description.trim(),
            !!data.site_id,
            !!data.consultation_date,
            data.workers_consulted.length > 0,
            !!data.document,
        ];
        return Math.round(
            (checks.filter(Boolean).length / checks.length) * 100,
        );
    }, [data]);

    const goToStep = (key: string) => {
        const idx = STEPS.findIndex((s) => s.key === key);
        if (idx >= 0) setStepIndex(idx);
    };
    const jumpToFailingStep = (errs: Record<string, string>) => {
        const first = Object.keys(errs)[0];
        if (!first) return;
        const owner = STEPS.find((s) =>
            Object.keys(validateStep(s.key, data)).includes(first),
        );
        if (owner) {
            goToStep(owner.key);
            return;
        }
        // Non-gating server keys (e.g. document, workers_consulted.0): route via the
        // static field→step fallback, longest matching prefix wins.
        const fallback = [...STEP_FOR_FIELD]
            .filter(
                (m) => first === m.prefix || first.startsWith(`${m.prefix}.`),
            )
            .sort((a, b) => b.prefix.length - a.prefix.length)[0];
        if (fallback) setStepIndex(fallback.step);
    };

    const next = () => {
        const e = validateStep(cur.key, data);
        setLocalErrors(e);
        if (Object.keys(e).length) return;
        setStepIndex((i) => Math.min(i + 1, STEPS.length - 1));
    };
    const back = () => setStepIndex((i) => Math.max(i - 1, 0));

    const onStepClick = (i: number) => {
        setLocalErrors({});
        setStepIndex(i);
    };

    const resetAll = () => {
        reset();
        clearErrors();
        setLocalErrors({});
        setFileError(null);
        setStepIndex(0);
        setDone(false);
    };

    const toggleWorker = (id: number) =>
        setData(
            'workers_consulted',
            data.workers_consulted.includes(id)
                ? data.workers_consulted.filter((w) => w !== id)
                : [...data.workers_consulted, id],
        );

    /* Stage the dropped file after a client-side size + type check, so bad files
     * surface an error before submit rather than 422-ing on the server. */
    const onDocumentFiles = (files: File[]) => {
        const file = files[0];
        if (!file) return;
        if (file.size > MAX_UPLOAD_BYTES) {
            setFileError('That file is over 20 MB — choose a smaller file.');
            return;
        }
        const ext = file.name.split('.').pop()?.toLowerCase() ?? '';
        const okType =
            (ext && ACCEPT_EXTENSIONS.includes(ext)) ||
            (file.type && ACCEPT_MIME.includes(file.type));
        if (!okType) {
            setFileError(
                'Unsupported file type — use PDF, Word, Excel or an image.',
            );
            return;
        }
        setFileError(null);
        setData('document', file);
    };

    const submit = () => {
        // Re-validate every gating step; jump to the first that fails.
        const all: Record<string, string> = {};
        for (const s of STEPS) Object.assign(all, validateStep(s.key, data));
        if (Object.keys(all).length) {
            setLocalErrors(all);
            jumpToFailingStep(all);
            return;
        }
        setLocalErrors({});
        transform((payload) => payload);
        post(`${WP_BASE}/consultations`, {
            forceFormData: true,
            preserveScroll: true,
            preserveState: true,
            onError: (errs) =>
                jumpToFailingStep(errs as Record<string, string>),
            onSuccess: () => setDone(true),
        });
    };

    const siteName =
        sites.find((s) => s.id === Number(data.site_id))?.name ?? null;
    const selectedWorkers = staff.filter((s) =>
        data.workers_consulted.includes(s.id),
    );

    /* ---- success pane ---- */
    const success = done ? (
        <WizardSuccessPane
            title="Consultation raised"
            blurb={
                <>
                    <span className="font-medium text-foreground">
                        {data.title || 'The consultation'}
                    </span>{' '}
                    is now open on the Worker Participation register. Record
                    kaimahi feedback and the outcome as the consultation
                    progresses.
                </>
            }
            actions={
                <>
                    <Button variant="outline" onClick={resetAll}>
                        <MessageSquare className="mr-1.5 h-4 w-4" /> Add another
                    </Button>
                    <Button onClick={onClose}>Done</Button>
                </>
            }
        />
    ) : undefined;

    /* ---- footer (caller owns the buttons) ---- */
    const footerStart =
        !done && stepIndex > 0 ? (
            <Button variant="ghost" size="sm" onClick={back}>
                <ChevronLeft className="mr-1 h-4 w-4" /> Back
            </Button>
        ) : null;

    const footerEnd = !done ? (
        <>
            <Button variant="outline" size="sm" onClick={onClose}>
                Cancel
            </Button>
            {isReview ? (
                <Button size="sm" onClick={submit} disabled={processing}>
                    <CheckCircle2 className="mr-1.5 h-4 w-4" />{' '}
                    {processing ? 'Raising…' : 'Raise consultation'}
                </Button>
            ) : (
                <Button size="sm" onClick={next}>
                    Continue <ChevronRight className="ml-1 h-4 w-4" />
                </Button>
            )}
        </>
    ) : null;

    return (
        <WizardShell
            open={open}
            onClose={onClose}
            title="New consultation"
            description="Raise a worker consultation on a matter affecting health & safety."
            railIcon={MessageSquare}
            railTitle="New consultation"
            railSub="Consult kaimahi"
            steps={STEPS}
            stepIndex={stepIndex}
            onStepClick={onStepClick}
            pct={done ? null : pct}
            footerStart={footerStart}
            footerEnd={footerEnd}
            success={success}
        >
            {/* ── Step 1: Topic & type ── */}
            {cur.key === 'topic' && (
                <WizardStepPane>
                    <StepHead
                        icon={MessageSquare}
                        title="Topic & type"
                        blurb="What are kaimahi being consulted on, and what kind of consultation is it?"
                    />
                    <div className="grid gap-5">
                        <Field
                            label="Consultation title"
                            required
                            error={err('title')}
                        >
                            <input
                                type="text"
                                value={data.title}
                                onChange={(e) =>
                                    setData('title', e.target.value)
                                }
                                placeholder="e.g. Review of manual handling procedure"
                                className="w-full rounded-lg border border-border bg-background px-3 py-2 text-sm focus-visible:ring-2 focus-visible:ring-primary focus-visible:outline-none"
                            />
                        </Field>

                        <Field
                            label="Consultation type"
                            required
                            error={err('consultation_type')}
                        >
                            <TilePicker
                                value={data.consultation_type}
                                onChange={(v) =>
                                    setData('consultation_type', v)
                                }
                                cols={2}
                                options={CONSULTATION_TYPES.map((t) => ({
                                    key: t.key,
                                    label: t.label,
                                    description: t.description,
                                    icon: t.icon,
                                }))}
                            />
                        </Field>

                        <Field
                            label="What's being consulted on"
                            required
                            hint="the change, hazard or matter and why kaimahi input is needed"
                            error={err('description')}
                        >
                            <textarea
                                value={data.description}
                                onChange={(e) =>
                                    setData('description', e.target.value)
                                }
                                rows={4}
                                placeholder="Describe the matter affecting health & safety…"
                                className={textareaCls}
                            />
                        </Field>
                    </div>
                </WizardStepPane>
            )}

            {/* ── Step 2: Scope ── */}
            {cur.key === 'scope' && (
                <WizardStepPane>
                    <StepHead
                        icon={Target}
                        title="Scope"
                        blurb="Where does this apply, when is the consultation, and who is being consulted?"
                    />
                    <div className="grid gap-5">
                        <div className="grid gap-5 sm:grid-cols-2">
                            <Field label="Site" required error={err('site_id')}>
                                <SelectInput
                                    value={data.site_id}
                                    onChange={(v) => setData('site_id', v)}
                                    placeholder="Select site…"
                                    options={sites.map((s) => ({
                                        value: String(s.id),
                                        label: s.name,
                                    }))}
                                />
                            </Field>

                            <Field
                                label="Consultation date"
                                required
                                error={err('consultation_date')}
                            >
                                <input
                                    type="date"
                                    value={data.consultation_date}
                                    onChange={(e) =>
                                        setData(
                                            'consultation_date',
                                            e.target.value,
                                        )
                                    }
                                    className="w-full rounded-lg border border-border bg-background px-3 py-2 text-sm focus-visible:ring-2 focus-visible:ring-primary focus-visible:outline-none"
                                />
                            </Field>
                        </div>

                        <Field
                            label="Kaimahi consulted"
                            hint="optional — you can record who responded later"
                            error={err('workers_consulted')}
                        >
                            {staff.length ? (
                                // eslint-disable-next-line no-restricted-syntax -- bespoke scrollable multi-select surface (worker checklist), tokens only
                                <div className="flex max-h-56 flex-col gap-1.5 overflow-y-auto rounded-lg border border-border bg-card/40 p-2">
                                    {staff.map((s) => {
                                        const checked =
                                            data.workers_consulted.includes(
                                                s.id,
                                            );
                                        return (
                                            // eslint-disable-next-line no-restricted-syntax -- selectable worker row (custom checkbox tile), tokens only
                                            <button
                                                key={s.id}
                                                type="button"
                                                aria-pressed={checked}
                                                onClick={() =>
                                                    toggleWorker(s.id)
                                                }
                                                className={
                                                    'flex items-center gap-2.5 rounded-md px-2.5 py-2 text-left text-sm transition-colors ' +
                                                    (checked
                                                        ? 'bg-primary/10 text-foreground'
                                                        : 'hover:bg-muted')
                                                }
                                            >
                                                <span
                                                    className={
                                                        'grid h-5 w-5 shrink-0 place-items-center rounded-md border transition-colors ' +
                                                        (checked
                                                            ? 'border-primary bg-primary text-primary-foreground'
                                                            : 'border-border bg-background')
                                                    }
                                                >
                                                    {checked ? (
                                                        <CheckCircle2 className="h-3.5 w-3.5" />
                                                    ) : null}
                                                </span>
                                                <span className="font-medium">
                                                    {s.name}
                                                </span>
                                            </button>
                                        );
                                    })}
                                </div>
                            ) : (
                                <p className="rounded-lg border border-dashed border-border bg-muted/30 px-3 py-4 text-center text-sm text-muted-foreground">
                                    No kaimahi available to select.
                                </p>
                            )}
                            {selectedWorkers.length ? (
                                <p className="mt-1.5 inline-flex items-center gap-1.5 text-xs text-muted-foreground">
                                    <Users className="h-3.5 w-3.5" />{' '}
                                    {selectedWorkers.length} selected
                                </p>
                            ) : null}
                        </Field>
                    </div>
                </WizardStepPane>
            )}

            {/* ── Step 3: Documents (optional) ── */}
            {cur.key === 'documents' && (
                <WizardStepPane>
                    <StepHead
                        icon={FileUp}
                        title="Supporting document"
                        blurb="Attach a procedure draft, risk assessment or briefing — optional."
                    />
                    <div className="grid gap-4">
                        <InfoCard icon={Info}>
                            Optional — you can also add documents later from the
                            consultation.
                        </InfoCard>

                        <Field error={fileError ?? err('document')}>
                            {data.document ? (
                                <StagedFileCard
                                    file={data.document}
                                    onRemove={() => {
                                        setFileError(null);
                                        setData('document', null);
                                    }}
                                >
                                    <p className="text-[11px] text-muted-foreground">
                                        This file will be attached when you
                                        raise the consultation.
                                    </p>
                                </StagedFileCard>
                            ) : (
                                <FileDropzone
                                    multiple={false}
                                    accept=".pdf,.doc,.docx,.xlsx,.jpg,.png"
                                    hint="PDF, Word, Excel or image — up to 20 MB"
                                    onFiles={onDocumentFiles}
                                />
                            )}
                        </Field>
                    </div>
                </WizardStepPane>
            )}

            {/* ── Step 4: Review ── */}
            {cur.key === 'review' && (
                <WizardStepPane>
                    <StepHead
                        icon={CheckCircle2}
                        title="Review & raise"
                        blurb="Check the details below, then raise the consultation."
                    />
                    {/* eslint-disable-next-line no-restricted-syntax -- bespoke review summary banner (ring + blurb), tokens only */}
                    <div className="mb-5 flex items-center gap-4 rounded-xl border border-border bg-card/60 p-4">
                        <Ring pct={pct} />
                        <div>
                            <div className="text-sm font-bold">
                                Ready to raise
                            </div>
                            <p className="text-[13px] text-muted-foreground">
                                The consultation opens on the register and can
                                progress through feedback and outcome.
                            </p>
                        </div>
                    </div>

                    <div className="grid gap-3 sm:grid-cols-2">
                        <ReviewCard
                            icon={MessageSquare}
                            title="Topic"
                            onEdit={() => goToStep('topic')}
                        >
                            <ReviewRow label="Title" value={data.title} />
                            <ReviewRow
                                label="Type"
                                value={consultationTypeLabel(
                                    data.consultation_type,
                                )}
                            />
                            <ReviewRow
                                label="Description"
                                value={data.description}
                            />
                        </ReviewCard>

                        <ReviewCard
                            icon={Target}
                            title="Scope"
                            onEdit={() => goToStep('scope')}
                        >
                            <ReviewRow label="Site" value={siteName} />
                            <ReviewRow
                                label="Date"
                                value={fmtDate(data.consultation_date)}
                            />
                            <ReviewRow
                                label="Kaimahi consulted"
                                value={
                                    selectedWorkers.length
                                        ? selectedWorkers
                                              .map((w) => w.name)
                                              .join(', ')
                                        : undefined
                                }
                            />
                        </ReviewCard>

                        <ReviewCard
                            icon={FileUp}
                            title="Documents"
                            span
                            onEdit={() => goToStep('documents')}
                        >
                            <ReviewRow
                                label="Supporting file"
                                value={data.document?.name ?? 'None'}
                            />
                        </ReviewCard>
                    </div>
                </WizardStepPane>
            )}
        </WizardShell>
    );
}
