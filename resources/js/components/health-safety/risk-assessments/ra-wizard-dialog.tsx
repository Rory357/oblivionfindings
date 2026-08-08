/* eslint-disable no-restricted-syntax -- Risk Assessment workflow wizard. Mirrors the
 * Add-Client wizard chrome (via WizardShell + wizard/primitives) and intentionally uses
 * styled native controls (matrix cells, toggle, textareas, staged-file cards). Every
 * colour is a semantic design token. One component drives all seven workflows: the
 * multi-step New/Edit/Supersede and the single-step Approve/Mark-for-review/Record-
 * residual/Archive (each a one-step WizardShell so the chrome stays identical). */
import { Button } from '@/components/ui/button';
import { FileDropzone, StagedFileCard } from '@/components/ui/file-dropzone';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import {
    Field,
    InfoCard,
    Segmented,
    SelectInput,
    StepHead,
    type IconType,
} from '@/components/wizard/primitives';
import {
    ReviewCard,
    ReviewRow,
    WizardShell,
    WizardStepPane,
    WizardSuccessPane,
    type WizardStep,
} from '@/components/wizard/shell';
import { cn } from '@/lib/utils';
import { router, useForm } from '@inertiajs/react';
import {
    Archive,
    Check,
    ChevronLeft,
    ChevronRight,
    Clock,
    Info,
    Layers,
    Loader2,
    Paperclip,
    Pencil,
    Plus,
    RefreshCw,
    ShieldCheck,
    TriangleAlert,
} from 'lucide-react';
import { useMemo, useState, type ReactNode } from 'react';
import {
    cap,
    FREQ_OPTIONS,
    levelTone,
    RA_TONE_CHIP,
    scoreLevel,
} from './ra-kit';
import { RaMatrix } from './ra-matrix';
import type {
    AttachType,
    LockedAssessable,
    RaDetail,
    RaModalKind,
    RaPickers,
} from './types';

/* ------------------------------------------------------------------ */
/*  Step definitions                                                   */
/* ------------------------------------------------------------------ */

const MULTI_STEPS: readonly WizardStep[] = [
    {
        key: 'context',
        label: 'Context',
        blurb: 'Title & what it covers',
        icon: Pencil,
    },
    {
        key: 'inherent',
        label: 'Inherent risk',
        blurb: 'Likelihood × consequence',
        icon: TriangleAlert,
    },
    {
        key: 'controls',
        label: 'Controls',
        blurb: 'Existing & additional',
        icon: ShieldCheck,
    },
    {
        key: 'residual',
        label: 'Residual risk',
        blurb: 'Risk after controls',
        icon: RefreshCw,
    },
    {
        key: 'evidence',
        label: 'Evidence',
        blurb: 'Supporting documents',
        icon: Paperclip,
    },
    {
        key: 'ownership',
        label: 'Review & ownership',
        blurb: 'Cadence & review date',
        icon: Clock,
    },
    {
        key: 'review',
        label: 'Review & create',
        blurb: 'Confirm and save',
        icon: Check,
    },
] as const;

const SINGLE_STEP: Record<
    Exclude<RaModalKind, 'new' | 'edit' | 'supersede'>,
    WizardStep
> = {
    approve: {
        key: 'confirm',
        label: 'Approve & activate',
        blurb: 'Confirm and put in force',
        icon: ShieldCheck,
    },
    review: {
        key: 'confirm',
        label: 'Mark for review',
        blurb: 'Flag for revision',
        icon: Clock,
    },
    residual: {
        key: 'residual',
        label: 'Record residual',
        blurb: 'Re-score after controls',
        icon: RefreshCw,
    },
    archive: {
        key: 'confirm',
        label: 'Archive',
        blurb: 'Remove from the register',
        icon: Archive,
    },
};

const RAIL_ICON: Record<RaModalKind, IconType> = {
    new: TriangleAlert,
    edit: Pencil,
    supersede: Layers,
    approve: ShieldCheck,
    review: Clock,
    residual: RefreshCw,
    archive: Archive,
};

const KIND_OPTIONS = [
    { value: 'swms', label: 'SWMS' },
    { value: 'method_statement', label: 'Method statement' },
    { value: 'photo', label: 'Photo' },
    { value: 'sds', label: 'Safety data sheet' },
    { value: 'plan', label: 'Site plan' },
    { value: 'document', label: 'Other document' },
];

type StagedFile = { id: number; file: File; note: string; kind: string };
let stagedUid = 0;

type RaForm = {
    title: string;
    risk_description: string;
    attach_type: AttachType;
    attach_id: string;
    likelihood: number;
    consequence: number;
    existing_controls: string;
    additional_controls: string;
    residual_likelihood: number;
    residual_consequence: number;
    risk_acceptable: boolean;
    review_frequency_days: number;
    review_due_at: string;
    approver_note: string;
    review_note: string;
};

function initialForm(
    kind: RaModalKind,
    detail: RaDetail | null,
    locked: LockedAssessable | null,
    initialAttach: { type: AttachType; id: number } | null,
): RaForm {
    const f = detail?.form;
    const base: RaForm = {
        title: f?.title ?? '',
        risk_description: f?.risk_description ?? '',
        attach_type:
            locked?.type ??
            f?.attach_type ??
            initialAttach?.type ??
            'standalone',
        attach_id: locked
            ? String(locked.id)
            : f?.attach_id != null
              ? String(f.attach_id)
              : initialAttach
                ? String(initialAttach.id)
                : '',
        likelihood: f?.likelihood ?? 3,
        consequence: f?.consequence ?? 3,
        existing_controls: f?.existing_controls ?? '',
        additional_controls: f?.additional_controls ?? '',
        residual_likelihood: f?.residual_likelihood ?? 2,
        residual_consequence: f?.residual_consequence ?? 2,
        risk_acceptable: f?.risk_acceptable ?? true,
        review_frequency_days: f?.review_frequency_days ?? 90,
        review_due_at: f?.review_due_at ?? '',
        approver_note: '',
        review_note: '',
    };
    return base;
}

/* ------------------------------------------------------------------ */
/*  Component                                                          */
/* ------------------------------------------------------------------ */

export function RaWizardDialog({
    kind,
    detail = null,
    pickers,
    lockedAssessable = null,
    initialAttach = null,
    onClose,
    onSuccess,
}: {
    kind: RaModalKind;
    detail?: RaDetail | null;
    pickers: RaPickers;
    lockedAssessable?: LockedAssessable | null;
    /** Pre-fill (but don't lock) the attach target — e.g. creating an RA from an H&S event. */
    initialAttach?: { type: AttachType; id: number } | null;
    onClose: () => void;
    onSuccess?: (id?: number) => void;
}) {
    const isMulti = kind === 'new' || kind === 'edit' || kind === 'supersede';
    const steps = useMemo<readonly WizardStep[]>(
        () =>
            isMulti
                ? MULTI_STEPS
                : [
                      SINGLE_STEP[
                          kind as Exclude<
                              RaModalKind,
                              'new' | 'edit' | 'supersede'
                          >
                      ],
                  ],
        [isMulti, kind],
    );

    const [stepIndex, setStepIndex] = useState(0);
    const [localErrors, setLocalErrors] = useState<Record<string, string>>({});
    const [done, setDone] = useState(false);
    const [newId, setNewId] = useState<number | null>(null);
    const [staged, setStaged] = useState<StagedFile[]>([]);
    const [uploading, setUploading] = useState(false);

    const form = useForm<RaForm>(
        initialForm(kind, detail, lockedAssessable, initialAttach),
    );
    const { data, setData, processing } = form;
    const busy = processing || uploading;

    const cur = steps[stepIndex];
    const err = (name: string) =>
        localErrors[name] ?? (form.errors as Record<string, string>)[name];

    /* -------- staged evidence -------- */
    const addStaged = (files: File[]) =>
        setStaged((p) => [
            ...p,
            ...files.map((file) => ({
                id: ++stagedUid,
                file,
                note: '',
                kind: '',
            })),
        ]);
    const patchStaged = (id: number, patch: Partial<StagedFile>) =>
        setStaged((p) =>
            p.map((it) => (it.id === id ? { ...it, ...patch } : it)),
        );
    const removeStaged = (id: number) =>
        setStaged((p) => p.filter((it) => it.id !== id));

    const uploadStaged = (id: number, finish: () => void) => {
        if (!staged.length) {
            finish();
            return;
        }
        setUploading(true);
        const queue = [...staged];
        const next = (i: number) => {
            if (i >= queue.length) {
                setUploading(false);
                setStaged([]);
                finish();
                return;
            }
            const it = queue[i];
            const fd = new FormData();
            fd.append('file', it.file);
            if (it.note) fd.append('notes', it.note);
            if (it.kind) fd.append('kind', it.kind);
            router.post(
                `/health-safety/risk-assessments/${id}/attachments`,
                fd,
                {
                    preserveScroll: true,
                    preserveState: true,
                    onFinish: () => next(i + 1),
                },
            );
        };
        next(0);
    };

    /* -------- validation -------- */
    const validateStep = (key: string, d: RaForm): Record<string, string> => {
        const e: Record<string, string> = {};
        if (key === 'context') {
            if (!d.title.trim()) e.title = 'Give the assessment a title.';
            if (d.attach_type !== 'standalone' && !d.attach_id)
                e.attach_id = 'Choose what this is attached to.';
        }
        return e;
    };

    const stepForError = (firstKey: string): number => {
        const stepKey =
            firstKey === 'likelihood' || firstKey === 'consequence'
                ? 'inherent'
                : firstKey === 'existing_controls' ||
                    firstKey === 'additional_controls'
                  ? 'controls'
                  : firstKey.startsWith('residual') ||
                      firstKey === 'risk_acceptable'
                    ? 'residual'
                    : firstKey.startsWith('review')
                      ? 'ownership'
                      : 'context';
        const idx = steps.findIndex((s) => s.key === stepKey);
        return idx >= 0 ? idx : 0;
    };

    const next = () => {
        const e = validateStep(cur.key, data);
        setLocalErrors(e);
        if (Object.keys(e).length) return;
        setStepIndex((i) => Math.min(i + 1, steps.length - 1));
    };
    const back = () => setStepIndex((i) => Math.max(0, i - 1));

    /* -------- submit -------- */
    const endpoint = (): { url: string } => {
        const id = detail?.id;
        return {
            new: { url: '/health-safety/risk-assessments' },
            edit: { url: `/health-safety/risk-assessments/${id}` },
            supersede: {
                url: `/health-safety/risk-assessments/${id}/supersede`,
            },
            approve: { url: `/health-safety/risk-assessments/${id}/activate` },
            review: { url: `/health-safety/risk-assessments/${id}/review` },
            residual: { url: `/health-safety/risk-assessments/${id}/residual` },
            archive: { url: `/health-safety/risk-assessments/${id}/archive` },
        }[kind];
    };

    const payload = (d: RaForm): Record<string, unknown> => {
        if (kind === 'approve')
            return { approver_note: d.approver_note || null };
        if (kind === 'review' || kind === 'archive') return {};
        if (kind === 'residual') {
            return {
                residual_likelihood: d.residual_likelihood,
                residual_consequence: d.residual_consequence,
                risk_acceptable: d.risk_acceptable,
                review_note: d.review_note || null,
            };
        }
        const base: Record<string, unknown> = {
            title: d.title,
            risk_description: d.risk_description || null,
            attach_type: d.attach_type,
            attach_id:
                d.attach_type === 'standalone'
                    ? null
                    : d.attach_id
                      ? Number(d.attach_id)
                      : null,
            likelihood: Number(d.likelihood),
            consequence: Number(d.consequence),
            existing_controls: d.existing_controls || null,
            additional_controls: d.additional_controls || null,
            residual_likelihood: Number(d.residual_likelihood),
            residual_consequence: Number(d.residual_consequence),
            risk_acceptable: d.risk_acceptable,
            review_frequency_days: Number(d.review_frequency_days),
            review_due_at: d.review_due_at || null,
        };
        if (kind === 'edit') base._method = 'put';
        return base;
    };

    const resetForAnother = () => {
        form.reset();
        form.clearErrors();
        setLocalErrors({});
        setStaged([]);
        setStepIndex(0);
        setDone(false);
        setNewId(null);
    };

    const submit = (addAnother = false) => {
        if (isMulti) {
            const all: Record<string, string> = {};
            for (const s of steps)
                Object.assign(all, validateStep(s.key, data));
            if (Object.keys(all).length) {
                setLocalErrors(all);
                setStepIndex(stepForError(Object.keys(all)[0]));
                return;
            }
        }
        form.transform(() => payload(data));
        form.post(endpoint().url, {
            preserveScroll: true,
            preserveState: true,
            onSuccess: (page) => {
                const flash = (
                    page.props as {
                        flash?: {
                            error?: string;
                            created_risk_assessment_id?: number;
                        };
                    }
                ).flash;
                if (flash?.error) return;
                const createdId =
                    flash?.created_risk_assessment_id ?? detail?.id ?? null;
                const finish = () => {
                    if (addAnother && kind === 'new') {
                        resetForAnother();
                        return;
                    }
                    if (kind === 'new' || kind === 'supersede') {
                        setNewId(createdId);
                        setDone(true);
                    } else {
                        onClose();
                    }
                };
                if (isMulti && staged.length && createdId) {
                    uploadStaged(createdId, finish);
                } else {
                    finish();
                }
            },
            onError: (errs) => {
                const first = Object.keys(errs)[0];
                if (first) setStepIndex(stepForError(first));
            },
        });
    };

    /* -------- completeness -------- */
    const pct = useMemo(() => {
        if (!isMulti) return null;
        const need = [
            'title',
            'risk_description',
            'existing_controls',
            'additional_controls',
        ] as const;
        let filled = need.filter((k) =>
            (data[k] || '').toString().trim(),
        ).length;
        if (data.attach_type === 'standalone' || data.attach_id) filled += 1;
        return Math.round((filled / (need.length + 1)) * 100);
    }, [data, isMulti]);

    /* -------- titles -------- */
    const TITLES: Record<RaModalKind, [string, string]> = {
        new: ['New risk assessment', 'ISO 31000 / SafePlus 5×5'],
        edit: [
            'Edit draft',
            detail ? `${detail.reference_number} · update fields` : '',
        ],
        supersede: [
            'Supersede — new version',
            detail ? `Successor to ${detail.reference_number}` : '',
        ],
        approve: [
            'Approve & activate',
            detail ? `${detail.reference_number} · draft → active` : '',
        ],
        review: [
            'Mark for review',
            detail ? `${detail.reference_number} · active → under review` : '',
        ],
        residual: [
            'Record review / residual',
            detail ? `${detail.reference_number}` : '',
        ],
        archive: [
            'Archive assessment',
            detail ? `${detail.reference_number}` : '',
        ],
    };
    const [title, subtitle] = TITLES[kind];

    /* -------- footer -------- */
    const isReview = cur.key === 'review';
    const createLabel =
        kind === 'supersede'
            ? 'Create successor'
            : kind === 'edit'
              ? 'Save draft'
              : 'Create assessment';
    const singleLabel = {
        approve: 'Approve & activate',
        review: 'Mark for review',
        residual: 'Save review',
        archive: 'Archive assessment',
    }[kind as Exclude<RaModalKind, 'new' | 'edit' | 'supersede'>];

    const footerStart =
        isMulti && stepIndex > 0 ? (
            <Button type="button" variant="ghost" onClick={back}>
                <ChevronLeft className="h-4 w-4" /> Back
            </Button>
        ) : null;

    const footerEnd = (
        <>
            <Button type="button" variant="outline" onClick={onClose}>
                Cancel
            </Button>
            {isMulti ? (
                isReview ? (
                    <>
                        {kind === 'new' ? (
                            <Button
                                type="button"
                                variant="secondary"
                                onClick={() => submit(true)}
                                disabled={busy}
                            >
                                <Plus className="h-4 w-4" /> Save &amp; add
                                another
                            </Button>
                        ) : null}
                        <Button
                            type="button"
                            onClick={() => submit(false)}
                            disabled={busy}
                        >
                            {busy ? (
                                <Loader2 className="h-4 w-4 animate-spin" />
                            ) : (
                                <Check className="h-4 w-4" />
                            )}{' '}
                            {createLabel}
                        </Button>
                    </>
                ) : (
                    <Button type="button" onClick={next}>
                        Continue <ChevronRight className="h-4 w-4" />
                    </Button>
                )
            ) : (
                <Button
                    type="button"
                    variant={kind === 'archive' ? 'destructive' : 'default'}
                    onClick={() => submit(false)}
                    disabled={busy}
                >
                    {busy ? <Loader2 className="h-4 w-4 animate-spin" /> : null}{' '}
                    {singleLabel}
                </Button>
            )}
        </>
    );

    const success = done ? (
        <WizardSuccessPane
            title={
                kind === 'supersede'
                    ? 'Successor created'
                    : 'Risk assessment created'
            }
            blurb={
                <>
                    Created in <strong>draft</strong> — the service forces this
                    status. Approve &amp; activate it to put the assessment in
                    force.{staged.length === 0 ? '' : ' Evidence uploaded.'}
                </>
            }
            actions={
                <>
                    {newId && onSuccess ? (
                        <Button type="button" onClick={() => onSuccess(newId)}>
                            Open assessment
                        </Button>
                    ) : null}
                    {kind === 'new' ? (
                        <Button
                            type="button"
                            variant="outline"
                            onClick={resetForAnother}
                        >
                            <Plus className="h-4 w-4" /> Add another
                        </Button>
                    ) : null}
                    <Button type="button" variant="ghost" onClick={onClose}>
                        Done
                    </Button>
                </>
            }
        />
    ) : undefined;

    return (
        <WizardShell
            open
            onClose={onClose}
            title={title}
            description={subtitle || title}
            railIcon={RAIL_ICON[kind]}
            railTitle={title}
            railSub={subtitle}
            steps={steps}
            stepIndex={stepIndex}
            onStepClick={(i) => setStepIndex(i)}
            pct={pct}
            footerStart={footerStart}
            footerEnd={footerEnd}
            success={success}
            maxWidth="min(94vw, 1080px)"
            maxHeight="min(92vh, 860px)"
        >
            <WizardStepPane>
                <StepHead icon={cur.icon} title={cur.label} blurb={cur.blurb} />

                {/* ---- CONTEXT ---- */}
                {cur.key === 'context' ? (
                    <div className="flex flex-col gap-5">
                        <Field label="Title" required error={err('title')}>
                            <Input
                                value={data.title}
                                onChange={(e) =>
                                    setData('title', e.target.value)
                                }
                                placeholder="e.g. Manual handling — hoist transfers"
                            />
                        </Field>
                        <Field
                            label="Risk description"
                            hint="(what is the hazard and who could be harmed?)"
                        >
                            <Textarea
                                value={data.risk_description}
                                onChange={(e) =>
                                    setData('risk_description', e.target.value)
                                }
                                rows={3}
                                placeholder="What is the hazard and who could be harmed?"
                            />
                        </Field>
                        {lockedAssessable ? (
                            <Field label="Attached to">
                                <div className="inline-flex items-center gap-2 rounded-lg border border-border bg-muted/40 px-3 py-2 text-sm font-medium">
                                    {lockedAssessable.name}
                                    <span className="text-xs text-muted-foreground">
                                        ({lockedAssessable.type})
                                    </span>
                                </div>
                            </Field>
                        ) : (
                            <div>
                                <div className="mb-2 text-[13px] font-semibold">
                                    Attach to
                                </div>
                                <Segmented<AttachType>
                                    value={data.attach_type}
                                    onChange={(v) => {
                                        setData('attach_type', v);
                                        setData('attach_id', '');
                                    }}
                                    options={[
                                        {
                                            value: 'standalone',
                                            label: 'Standalone',
                                        },
                                        { value: 'site', label: 'Site' },
                                        { value: 'client', label: 'Client' },
                                        { value: 'event', label: 'H&S event' },
                                    ]}
                                />
                                {data.attach_type !== 'standalone' ? (
                                    <div className="mt-3.5">
                                        <Field
                                            label={
                                                data.attach_type === 'site'
                                                    ? 'Which site?'
                                                    : data.attach_type ===
                                                        'client'
                                                      ? 'Which client?'
                                                      : 'Which H&S event?'
                                            }
                                            required
                                            error={err('attach_id')}
                                        >
                                            <SelectInput
                                                value={data.attach_id}
                                                onChange={(v) =>
                                                    setData('attach_id', v)
                                                }
                                                placeholder="Choose…"
                                                options={(data.attach_type ===
                                                'site'
                                                    ? pickers.sites
                                                    : data.attach_type ===
                                                        'client'
                                                      ? pickers.clients
                                                      : pickers.events
                                                ).map((o) => ({
                                                    value: String(o.id),
                                                    label: o.name,
                                                }))}
                                            />
                                        </Field>
                                    </div>
                                ) : null}
                            </div>
                        )}
                    </div>
                ) : null}

                {/* ---- INHERENT ---- */}
                {cur.key === 'inherent' ? (
                    <MatrixStep
                        likelihood={data.likelihood}
                        consequence={data.consequence}
                        onSelect={(l, c) => {
                            setData('likelihood', l);
                            setData('consequence', c);
                        }}
                        caption="Click a cell — likelihood (rows) × consequence (columns). The score and level update exactly as the server calculates them."
                    />
                ) : null}

                {/* ---- CONTROLS ---- */}
                {cur.key === 'controls' ? (
                    <div className="flex flex-col gap-5">
                        <Field label="Existing controls">
                            <Textarea
                                value={data.existing_controls}
                                onChange={(e) =>
                                    setData('existing_controls', e.target.value)
                                }
                                rows={4}
                                placeholder="What is already in place to manage this risk?"
                            />
                        </Field>
                        <Field label="Additional controls">
                            <Textarea
                                value={data.additional_controls}
                                onChange={(e) =>
                                    setData(
                                        'additional_controls',
                                        e.target.value,
                                    )
                                }
                                rows={4}
                                placeholder="What further controls will reduce the residual risk?"
                            />
                        </Field>
                    </div>
                ) : null}

                {/* ---- RESIDUAL (multi step) ---- */}
                {cur.key === 'residual' && isMulti ? (
                    <MatrixStep
                        likelihood={data.residual_likelihood}
                        consequence={data.residual_consequence}
                        onSelect={(l, c) => {
                            setData('residual_likelihood', l);
                            setData('residual_consequence', c);
                        }}
                        caption="Score the risk that remains once your controls are in place."
                    >
                        <AcceptableToggle
                            value={data.risk_acceptable}
                            onToggle={() =>
                                setData(
                                    'risk_acceptable',
                                    !data.risk_acceptable,
                                )
                            }
                        />
                    </MatrixStep>
                ) : null}

                {/* ---- RESIDUAL (single action: record review/residual) ---- */}
                {cur.key === 'residual' && !isMulti ? (
                    <MatrixStep
                        likelihood={data.residual_likelihood}
                        consequence={data.residual_consequence}
                        onSelect={(l, c) => {
                            setData('residual_likelihood', l);
                            setData('residual_consequence', c);
                        }}
                        caption="Re-score the residual risk after this review."
                    >
                        <AcceptableToggle
                            value={data.risk_acceptable}
                            onToggle={() =>
                                setData(
                                    'risk_acceptable',
                                    !data.risk_acceptable,
                                )
                            }
                        />
                        <Field label="Review notes">
                            <Textarea
                                value={data.review_note}
                                onChange={(e) =>
                                    setData('review_note', e.target.value)
                                }
                                rows={3}
                                placeholder="What changed, what was checked…"
                            />
                        </Field>
                    </MatrixStep>
                ) : null}

                {/* ---- EVIDENCE (premium upload) ---- */}
                {cur.key === 'evidence' ? (
                    <div className="flex flex-col gap-3">
                        <p className="text-sm text-muted-foreground">
                            Attach supporting evidence — SWMS, method
                            statements, hazard photos, safety data sheets or
                            site plans. Files upload to the assessment when you
                            save.
                        </p>
                        <FileDropzone
                            onFiles={addStaged}
                            accept="image/*,.pdf,.doc,.docx,.xls,.xlsx"
                            hint="Images, PDF, Word or Excel — up to 20 MB each"
                        />
                        {staged.length ? (
                            <div className="flex flex-col gap-2">
                                {staged.map((it) => (
                                    <StagedFileCard
                                        key={it.id}
                                        file={it.file}
                                        onRemove={() => removeStaged(it.id)}
                                    >
                                        <div className="flex flex-col gap-2 sm:flex-row">
                                            <Input
                                                value={it.note}
                                                onChange={(e) =>
                                                    patchStaged(it.id, {
                                                        note: e.target.value,
                                                    })
                                                }
                                                placeholder="Note (optional)"
                                                className="h-8"
                                            />
                                            <div className="sm:w-48">
                                                <SelectInput
                                                    value={it.kind}
                                                    onChange={(v) =>
                                                        patchStaged(it.id, {
                                                            kind: v,
                                                        })
                                                    }
                                                    placeholder="Type (optional)"
                                                    options={KIND_OPTIONS}
                                                />
                                            </div>
                                        </div>
                                    </StagedFileCard>
                                ))}
                            </div>
                        ) : null}
                    </div>
                ) : null}

                {/* ---- OWNERSHIP ---- */}
                {cur.key === 'ownership' ? (
                    <div className="flex flex-col gap-5">
                        <div>
                            <div className="mb-2 text-[13px] font-semibold">
                                Review frequency
                            </div>
                            <Segmented<string>
                                value={String(data.review_frequency_days)}
                                onChange={(v) =>
                                    setData('review_frequency_days', Number(v))
                                }
                                options={FREQ_OPTIONS.map((o) => ({
                                    value: String(o.value),
                                    label: o.label,
                                }))}
                            />
                        </div>
                        <Field label="Next review due" hint="(optional)">
                            <Input
                                type="date"
                                value={data.review_due_at}
                                onChange={(e) =>
                                    setData('review_due_at', e.target.value)
                                }
                                className="w-60"
                            />
                        </Field>
                        <InfoCard icon={Info}>
                            Left blank, the review date is scheduled from the
                            cadence when you approve &amp; activate. The
                            CheckRiskAssessmentReviewsJob flags it when due.
                        </InfoCard>
                    </div>
                ) : null}

                {/* ---- REVIEW ---- */}
                {cur.key === 'review' ? (
                    <ReviewStep
                        data={data}
                        pickers={pickers}
                        staged={staged.length}
                        onJump={setStepIndex}
                    />
                ) : null}

                {/* ---- CONFIRM (approve / review / archive) ---- */}
                {cur.key === 'confirm' ? (
                    <div className="flex flex-col gap-4">
                        <p className="text-sm leading-relaxed">
                            {kind === 'approve'
                                ? 'Approving records you as the approver, stamps the approval time, and moves the assessment from draft to active. If no review date is set, one is scheduled from the review cadence.'
                                : kind === 'review'
                                  ? 'This flags the assessment as under review so it surfaces in the “Due for review” worklist. The residual scoring and controls can then be updated.'
                                  : 'Archiving removes this assessment from the active register. It stays in the audit history and can be reopened by an administrator. This does not delete any records.'}
                        </p>
                        {kind === 'approve' ? (
                            <Field label="Approver note" hint="(optional)">
                                <Textarea
                                    value={data.approver_note}
                                    onChange={(e) =>
                                        setData('approver_note', e.target.value)
                                    }
                                    rows={3}
                                    placeholder="Any conditions or context for the approval…"
                                />
                            </Field>
                        ) : null}
                        {kind === 'archive' ? (
                            <InfoCard icon={TriangleAlert} tone="crit">
                                This assessment will be removed from the active
                                register.
                            </InfoCard>
                        ) : null}
                    </div>
                ) : null}
            </WizardStepPane>
        </WizardShell>
    );
}

/* ------------------------------------------------------------------ */
/*  Sub-components                                                     */
/* ------------------------------------------------------------------ */

function MatrixStep({
    likelihood,
    consequence,
    onSelect,
    caption,
    children,
}: {
    likelihood: number;
    consequence: number;
    onSelect: (l: number, c: number) => void;
    caption: string;
    children?: ReactNode;
}) {
    const score = likelihood * consequence;
    const level = scoreLevel(score);
    const tone = levelTone(level);
    return (
        <div className="flex flex-wrap items-start gap-7">
            <RaMatrix
                likelihood={likelihood}
                consequence={consequence}
                onSelect={onSelect}
            />
            <div className="flex min-w-[220px] flex-1 flex-col gap-4">
                <div className="flex items-center gap-3">
                    <span
                        className={cn(
                            'inline-flex h-12 w-12 items-center justify-center rounded-xl text-lg font-bold',
                            RA_TONE_CHIP[tone],
                        )}
                    >
                        {score}
                    </span>
                    <div>
                        <div className="text-[11px] font-semibold tracking-wide text-muted-foreground uppercase">
                            Calculated level
                        </div>
                        <div className="text-lg font-bold">{cap(level)}</div>
                    </div>
                </div>
                <p className="text-[13px] leading-relaxed text-muted-foreground">
                    {caption}
                </p>
                {children}
            </div>
        </div>
    );
}

function AcceptableToggle({
    value,
    onToggle,
}: {
    value: boolean;
    onToggle: () => void;
}) {
    return (
        <div className="flex items-center justify-between gap-3 rounded-lg border border-border p-3.5">
            <div>
                <div className="text-[13px] font-semibold">
                    Residual risk acceptable?
                </div>
                <div className="mt-0.5 text-xs text-muted-foreground">
                    Tolerable with these controls in place?
                </div>
            </div>
            <button
                type="button"
                role="switch"
                aria-checked={value}
                aria-label="Residual risk acceptable"
                onClick={onToggle}
                className={cn(
                    'relative h-6 w-11 shrink-0 rounded-full transition-colors',
                    value ? 'bg-primary' : 'bg-muted-foreground/50',
                )}
            >
                <span
                    className={cn(
                        'absolute top-0.5 h-5 w-5 rounded-full bg-white shadow transition-all',
                        value ? 'left-[22px]' : 'left-0.5',
                    )}
                />
            </button>
        </div>
    );
}

function ReviewStep({
    data,
    pickers,
    staged,
    onJump,
}: {
    data: RaForm;
    pickers: RaPickers;
    staged: number;
    onJump: (i: number) => void;
}) {
    const inh = data.likelihood * data.consequence;
    const res = data.residual_likelihood * data.residual_consequence;
    const inhLv = scoreLevel(inh);
    const resLv = scoreLevel(res);
    const attachName =
        data.attach_type === 'standalone'
            ? 'Standalone'
            : ((data.attach_type === 'site'
                  ? pickers.sites
                  : data.attach_type === 'client'
                    ? pickers.clients
                    : pickers.events
              ).find((o) => String(o.id) === data.attach_id)?.name ??
              cap(data.attach_type));
    const freq =
        FREQ_OPTIONS.find((o) => o.value === Number(data.review_frequency_days))
            ?.label ?? '—';
    const chip = (lv: string) => (
        <span
            className={cn(
                'rounded-md px-2 py-0.5 text-xs font-bold',
                RA_TONE_CHIP[levelTone(lv as never)],
            )}
        >
            {cap(lv)}
        </span>
    );

    return (
        <div>
            <div className="grid gap-3.5 sm:grid-cols-2">
                <ReviewCard
                    icon={Pencil}
                    title="Context"
                    onEdit={() => onJump(0)}
                >
                    <ReviewRow label="Title" value={data.title || '—'} />
                    <ReviewRow
                        label="Description"
                        value={data.risk_description || '—'}
                    />
                    <ReviewRow label="Attached to" value={attachName} />
                </ReviewCard>
                <ReviewCard
                    icon={TriangleAlert}
                    title="Inherent risk"
                    onEdit={() => onJump(1)}
                >
                    <ReviewRow
                        label="Likelihood × consequence"
                        value={`${data.likelihood} × ${data.consequence}`}
                    />
                    <ReviewRow label="Score" value={`${inh} · ${cap(inhLv)}`} />
                    <ReviewRow label="Level" value={chip(inhLv)} />
                </ReviewCard>
                <ReviewCard
                    icon={ShieldCheck}
                    title="Controls"
                    onEdit={() => onJump(2)}
                >
                    <ReviewRow
                        label="Existing"
                        value={data.existing_controls || '—'}
                    />
                    <ReviewRow
                        label="Additional"
                        value={data.additional_controls || '—'}
                    />
                </ReviewCard>
                <ReviewCard
                    icon={RefreshCw}
                    title="Residual risk"
                    onEdit={() => onJump(3)}
                >
                    <ReviewRow
                        label="Likelihood × consequence"
                        value={`${data.residual_likelihood} × ${data.residual_consequence}`}
                    />
                    <ReviewRow label="Score" value={`${res} · ${cap(resLv)}`} />
                    <ReviewRow
                        label="Acceptable"
                        value={data.risk_acceptable ? 'Yes' : 'No'}
                    />
                </ReviewCard>
                <ReviewCard
                    icon={Paperclip}
                    title="Evidence"
                    onEdit={() => onJump(4)}
                >
                    <ReviewRow
                        label="Staged documents"
                        value={
                            staged
                                ? `${staged} file${staged === 1 ? '' : 's'}`
                                : 'None'
                        }
                    />
                </ReviewCard>
                <ReviewCard
                    icon={Clock}
                    title="Review & ownership"
                    onEdit={() => onJump(5)}
                >
                    <ReviewRow label="Cadence" value={freq} />
                    <ReviewRow
                        label="Next review due"
                        value={data.review_due_at || 'Set on approval'}
                    />
                </ReviewCard>
            </div>
            <p className="mt-3.5 px-0.5 text-[12.5px] text-muted-foreground">
                Validated per step, mirroring the server request — submit jumps
                to the first failing step. Created in <strong>draft</strong>.
            </p>
        </div>
    );
}
