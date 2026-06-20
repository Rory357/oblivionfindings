/* Safe Work Procedure create/edit wizard — the "Add client" modal contract via WizardShell +
 * wizard primitives. Six steps (Basics / Hazards & PPE / Steps / Applies to / Review cycle /
 * Review & save) → success pane. Posts in-place (preserveScroll/State) so the register refreshes
 * without navigating. Edit mode pre-fills from detail.form and requires a change_summary.
 * Semantic tokens only. NZ-only, web-only. */
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import {
    ChipMulti,
    Field,
    InfoCard,
    Ring,
    SelectInput,
    StepHead,
    TilePicker,
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
    AlertTriangle,
    CalendarClock,
    Check,
    CheckCircle2,
    ChevronLeft,
    ChevronRight,
    Droplets,
    FilePlus2,
    FileText,
    Flame,
    GraduationCap,
    Hand,
    HardHat,
    Heart,
    ListChecks,
    MapPin,
    Pill,
    Plus,
    Radio,
    ShieldAlert,
    Trash2,
    Users,
    Wrench,
    X,
} from 'lucide-react';
import { useMemo, useState } from 'react';
import type { ProcedureFormData } from './procedure-detail-dialog';

/* ------------------------------------------------------------------ */
/*  Options + form shape                                               */
/* ------------------------------------------------------------------ */

export type ProcedureWizardOptions = {
    sites: { id: number; name: string }[];
    roles: { id: number; name: string; label: string }[];
    trainingCourses: { id: number; name: string; code: string | null }[];
    owners: { id: number; name: string }[];
    categories: { value: string; label: string }[];
};

type WizForm = ProcedureFormData & { change_summary: string };

const CATEGORY_ICON: Record<string, IconType> = {
    manual_handling: Hand,
    challenging_behaviour: ShieldAlert,
    lone_working: Radio,
    medication: Pill,
    infection_control: Droplets,
    fire_safety: Flame,
    emergency_procedures: AlertTriangle,
    equipment_use: Wrench,
    personal_care: Heart,
};

const CADENCE_OPTIONS = [
    { value: '3', label: 'Every 3 months' },
    { value: '6', label: 'Every 6 months' },
    { value: '12', label: 'Every 12 months' },
    { value: '24', label: 'Every 2 years' },
    { value: '36', label: 'Every 3 years' },
];

const PPE_SUGGESTIONS = ['Gloves', 'Apron', 'Face mask', 'Eye protection', 'Hi-vis vest', 'Safety footwear', 'Hoist sling', 'Slide sheet'];
const HAZARD_SUGGESTIONS = ['Musculoskeletal injury', 'Slips, trips & falls', 'Exposure to bodily fluids', 'Aggression / challenging behaviour', 'Working alone', 'Medication error', 'Burns / scalds', 'Sharps'];

const EMPTY: WizForm = {
    title: '',
    reference_number: '',
    category: '',
    purpose: '',
    scope: '',
    steps: [{ step_number: 1, description: '', safety_notes: '' }],
    ppe_required: [],
    hazards_addressed: [],
    emergency_procedures: '',
    applicable_roles: [],
    applicable_sites: [],
    related_training: [],
    review_date: '',
    review_frequency_months: 12,
    owner_id: null,
    change_summary: '',
};

const STEPS: WizardStep[] = [
    { key: 'basics', label: 'Basics', blurb: 'Identify & classify', icon: FileText },
    { key: 'hazards', label: 'Hazards & PPE', blurb: 'Risks & kit required', icon: HardHat },
    { key: 'steps', label: 'Steps', blurb: 'The sequence to follow', icon: ListChecks },
    { key: 'applies', label: 'Applies to', blurb: 'Roles, sites & training', icon: Users },
    { key: 'review_cycle', label: 'Review cycle', blurb: 'Cadence & ownership', icon: CalendarClock },
    { key: 'review', label: 'Review & save', blurb: 'Confirm and save', icon: CheckCircle2 },
];

const STEP_FOR_FIELD: Record<string, number> = {
    title: 0,
    reference_number: 0,
    category: 0,
    purpose: 0,
    scope: 0,
    hazards_addressed: 1,
    ppe_required: 1,
    emergency_procedures: 1,
    steps: 2,
    applicable_roles: 3,
    applicable_sites: 3,
    related_training: 3,
    review_date: 4,
    review_frequency_months: 4,
    owner_id: 4,
    change_summary: 5,
};

function stepForError(field: string): number {
    const base = field.split('.')[0];
    return STEP_FOR_FIELD[base] ?? 0;
}

function validateStep(key: string, d: WizForm, isEdit: boolean): Record<string, string> {
    const e: Record<string, string> = {};
    if (key === 'basics') {
        if (!d.title.trim()) e.title = 'Give the procedure a title.';
        if (!d.reference_number.trim()) e.reference_number = 'A unique reference number is required.';
        if (!d.category) e.category = 'Choose a category.';
    }
    if (key === 'review' && isEdit && !d.change_summary.trim()) {
        e.change_summary = 'Summarise what changed in this version.';
    }
    return e;
}

function completionPct(d: WizForm): number {
    const checks = [
        !!d.title.trim(),
        !!d.reference_number.trim(),
        !!d.category,
        !!d.purpose.trim(),
        !!d.scope.trim(),
        d.steps.some((s) => s.description.trim()),
        d.ppe_required.length > 0 || d.hazards_addressed.length > 0,
        d.applicable_roles.length > 0 || d.applicable_sites.length > 0,
        !!d.review_date,
        !!d.owner_id,
    ];
    return Math.round((checks.filter(Boolean).length / checks.length) * 100);
}

/* ------------------------------------------------------------------ */
/*  Wizard                                                             */
/* ------------------------------------------------------------------ */

export function ProcedureWizardDialog({
    open,
    onClose,
    options,
    initial = null,
    onOpenProcedure,
}: {
    open: boolean;
    onClose: () => void;
    options: ProcedureWizardOptions;
    /** Pre-filled form for edit mode (detail.form); null = create. */
    initial?: ProcedureFormData | null;
    onOpenProcedure: (id: number) => void;
}) {
    const isEdit = !!initial?.id;
    const form = useForm<WizForm>(initial ? { ...EMPTY, ...initial, change_summary: '' } : { ...EMPTY });
    const [stepIndex, setStepIndex] = useState(0);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [done, setDone] = useState(false);
    const [createdId, setCreatedId] = useState<number | null>(null);

    const d = form.data;
    const cur = STEPS[stepIndex];
    const isReview = cur.key === 'review';
    const pct = useMemo(() => completionPct(d), [d]);
    const fieldError = (n: string) => errors[n] ?? (form.errors as Record<string, string>)[n];

    const reset = () => {
        form.reset();
        form.clearErrors();
        setErrors({});
        setStepIndex(0);
    };

    const close = () => {
        if (!isEdit) reset();
        setDone(false);
        setCreatedId(null);
        onClose();
    };

    const next = () => {
        const e = validateStep(cur.key, d, isEdit);
        setErrors(e);
        if (Object.keys(e).length === 0) setStepIndex((i) => Math.min(i + 1, STEPS.length - 1));
    };
    const back = () => setStepIndex((i) => Math.max(i - 1, 0));

    const submit = (addAnother: boolean) => {
        // Re-validate every step; jump to the first failure.
        const all: Record<string, string> = {};
        for (const s of STEPS) Object.assign(all, validateStep(s.key, d, isEdit));
        if (Object.keys(all).length) {
            setErrors(all);
            setStepIndex(stepForError(Object.keys(all)[0]));
            return;
        }

        const url = isEdit ? `/health-safety/procedures/${initial!.id}` : '/health-safety/procedures';
        form.transform((data) => ({
            ...data,
            review_date: data.review_date || null,
            ...(isEdit ? { _method: 'put' as const } : {}),
        }));
        form.post(url, {
            preserveScroll: true,
            preserveState: true,
            onSuccess: (page) => {
                const flash = (page.props as { flash?: { created_procedure_id?: number; error?: string } }).flash;
                if (flash?.error) return;
                if (addAnother) {
                    reset();
                    return;
                }
                setCreatedId(flash?.created_procedure_id ?? initial?.id ?? null);
                setDone(true);
            },
            onError: (errs) => {
                const first = Object.keys(errs)[0];
                if (first) setStepIndex(stepForError(first));
            },
        });
    };

    /* ---- footer ---- */
    const footerEnd = isReview ? (
        <div className="flex items-center gap-2">
            <button type="button" onClick={close} className="rounded-lg px-3 py-2 text-sm font-medium text-muted-foreground hover:text-foreground">
                Cancel
            </button>
            {!isEdit ? (
                <button
                    type="button"
                    onClick={() => submit(true)}
                    disabled={form.processing}
                    className="rounded-lg border border-primary/40 px-3.5 py-2 text-sm font-semibold text-primary transition-colors hover:bg-primary/10 disabled:opacity-60"
                >
                    Save &amp; add another
                </button>
            ) : null}
            <button
                type="button"
                onClick={() => submit(false)}
                disabled={form.processing}
                className="inline-flex items-center gap-1.5 rounded-lg bg-primary px-3.5 py-2 text-sm font-semibold text-primary-foreground transition-colors hover:bg-primary/90 disabled:opacity-60"
            >
                <FilePlus2 className="h-4 w-4" /> {isEdit ? 'Save changes' : 'Create procedure'}
            </button>
        </div>
    ) : (
        <div className="flex items-center gap-2">
            <button type="button" onClick={close} className="rounded-lg px-3 py-2 text-sm font-medium text-muted-foreground hover:text-foreground">
                Cancel
            </button>
            <button
                type="button"
                onClick={next}
                className="inline-flex items-center gap-1.5 rounded-lg bg-primary px-3.5 py-2 text-sm font-semibold text-primary-foreground transition-colors hover:bg-primary/90"
            >
                Continue <ChevronRight className="h-4 w-4" />
            </button>
        </div>
    );

    return (
        <WizardShell
            open={open}
            onClose={close}
            title={isEdit ? `Edit ${initial?.reference_number ?? 'procedure'}` : 'New safe work procedure'}
            description="Capture a controlled safe-work procedure for the document library."
            railIcon={FileText}
            railTitle={isEdit ? 'Edit procedure' : 'New procedure'}
            railSub="Controlled document"
            steps={STEPS}
            stepIndex={stepIndex}
            onStepClick={(i) => setStepIndex(i)}
            pct={pct}
            pctLabel="Procedure completeness"
            footerStart={
                stepIndex > 0 && !done ? (
                    <button type="button" onClick={back} className="inline-flex items-center gap-1 rounded-lg px-3 py-2 text-sm font-medium text-muted-foreground hover:text-foreground">
                        <ChevronLeft className="h-4 w-4" /> Back
                    </button>
                ) : null
            }
            footerEnd={done ? null : footerEnd}
            success={
                done ? (
                    <WizardSuccessPane
                        title={isEdit ? 'Procedure updated' : 'Procedure created'}
                        blurb={
                            isEdit
                                ? `${d.reference_number} has been updated and a new version recorded.`
                                : `${d.reference_number} has been saved as a draft. Submit it for review when it's ready for approval.`
                        }
                        actions={
                            <>
                                {!isEdit ? (
                                    <button type="button" onClick={() => { setDone(false); reset(); }} className="rounded-lg border border-border px-4 py-2 text-sm font-medium text-foreground hover:bg-muted">
                                        Add another
                                    </button>
                                ) : null}
                                {createdId ? (
                                    <button
                                        type="button"
                                        onClick={() => { const id = createdId; close(); onOpenProcedure(id); }}
                                        className="rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-primary-foreground hover:bg-primary/90"
                                    >
                                        Open procedure
                                    </button>
                                ) : (
                                    <button type="button" onClick={close} className="rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-primary-foreground hover:bg-primary/90">
                                        Done
                                    </button>
                                )}
                            </>
                        }
                    />
                ) : undefined
            }
        >
            <WizardStepPane>
                {cur.key === 'basics' && (
                    <div className="flex flex-col gap-5">
                        <StepHead icon={FileText} title="Basics" blurb="Identify the procedure and classify it." />
                        <Field label="Title" required error={fieldError('title')}>
                            <Input value={d.title} onChange={(e) => form.setData('title', e.target.value)} placeholder="e.g. Safe Manual Handling & Client Transfers" />
                        </Field>
                        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <Field label="Reference number" required error={fieldError('reference_number')}>
                                <Input value={d.reference_number} onChange={(e) => form.setData('reference_number', e.target.value)} placeholder="SWP-010" />
                            </Field>
                            <Field label="Review cadence">
                                <SelectInput
                                    value={d.review_frequency_months ? String(d.review_frequency_months) : ''}
                                    onChange={(v) => form.setData('review_frequency_months', v ? Number(v) : null)}
                                    placeholder="Choose cadence"
                                    options={CADENCE_OPTIONS}
                                />
                            </Field>
                        </div>
                        <Field label="Category" required error={fieldError('category')}>
                            <TilePicker
                                value={d.category}
                                onChange={(v) => form.setData('category', v)}
                                cols={3}
                                options={options.categories.map((c) => ({ key: c.value, label: c.label, icon: CATEGORY_ICON[c.value] ?? FileText }))}
                            />
                        </Field>
                        <Field label="Purpose" hint="why this procedure exists">
                            <Textarea rows={2} value={d.purpose} onChange={(e) => form.setData('purpose', e.target.value)} placeholder="Protect support workers and clients from injury during…" />
                        </Field>
                        <Field label="Scope" hint="where it applies">
                            <Textarea rows={2} value={d.scope} onChange={(e) => form.setData('scope', e.target.value)} placeholder="All assisted transfers in residential and community settings…" />
                        </Field>
                    </div>
                )}

                {cur.key === 'hazards' && (
                    <div className="flex flex-col gap-5">
                        <StepHead icon={HardHat} title="Hazards & PPE" blurb="The risks this procedure controls and the kit it requires." />
                        <Field label="Hazards addressed" hint="tap a suggestion or add your own">
                            <FreeChips values={d.hazards_addressed} onChange={(v) => form.setData('hazards_addressed', v)} suggestions={HAZARD_SUGGESTIONS} placeholder="Add a hazard…" />
                        </Field>
                        <Field label="PPE required">
                            <FreeChips values={d.ppe_required} onChange={(v) => form.setData('ppe_required', v)} suggestions={PPE_SUGGESTIONS} placeholder="Add PPE…" />
                        </Field>
                        <Field label="Emergency response" hint="what to do if something goes wrong">
                            <Textarea rows={3} value={d.emergency_procedures} onChange={(e) => form.setData('emergency_procedures', e.target.value)} placeholder="If the client falls, do not lift — call for help, follow the post-fall protocol and record an incident…" />
                        </Field>
                    </div>
                )}

                {cur.key === 'steps' && (
                    <div className="flex flex-col gap-5">
                        <StepHead icon={ListChecks} title="Steps" blurb="The sequence to follow, with a safety note per step where it matters." />
                        <StepsEditor steps={d.steps} onChange={(v) => form.setData('steps', v)} />
                    </div>
                )}

                {cur.key === 'applies' && (
                    <div className="flex flex-col gap-5">
                        <StepHead icon={Users} title="Applies to" blurb="Who must follow this procedure, where, and the training that backs it." />
                        <Field label="Roles" hint="leave empty for all roles">
                            <EntityChecklist
                                options={options.roles.map((r) => ({ value: r.name, label: r.label }))}
                                selected={d.applicable_roles}
                                onChange={(v) => form.setData('applicable_roles', v as string[])}
                                icon={Users}
                            />
                        </Field>
                        <Field label="Sites" hint="leave empty for all sites (organisation-wide)">
                            <EntityChecklist
                                options={options.sites.map((s) => ({ value: s.id, label: s.name }))}
                                selected={d.applicable_sites}
                                onChange={(v) => form.setData('applicable_sites', v as number[])}
                                icon={MapPin}
                            />
                        </Field>
                        <Field label="Related training">
                            <EntityChecklist
                                options={options.trainingCourses.map((t) => ({ value: t.id, label: t.name, sub: t.code ?? undefined }))}
                                selected={d.related_training}
                                onChange={(v) => form.setData('related_training', v as number[])}
                                icon={GraduationCap}
                            />
                        </Field>
                    </div>
                )}

                {cur.key === 'review_cycle' && (
                    <div className="flex flex-col gap-5">
                        <StepHead icon={CalendarClock} title="Review cycle & ownership" blurb="When this procedure is next due for review and who owns it." />
                        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <Field label="Next review date" error={fieldError('review_date')}>
                                <Input type="date" value={d.review_date ?? ''} onChange={(e) => form.setData('review_date', e.target.value)} />
                            </Field>
                            <Field label="Review cadence">
                                <SelectInput
                                    value={d.review_frequency_months ? String(d.review_frequency_months) : ''}
                                    onChange={(v) => form.setData('review_frequency_months', v ? Number(v) : null)}
                                    placeholder="Choose cadence"
                                    options={CADENCE_OPTIONS}
                                />
                            </Field>
                        </div>
                        <Field label="Document owner" hint="responsible for keeping this current">
                            <SelectInput
                                value={d.owner_id ? String(d.owner_id) : ''}
                                onChange={(v) => form.setData('owner_id', v ? Number(v) : null)}
                                placeholder="Select an owner…"
                                options={options.owners.map((o) => ({ value: String(o.id), label: o.name }))}
                            />
                        </Field>
                        <InfoCard icon={CheckCircle2} tone="info">
                            New procedures save as a <strong>draft</strong>. Submit for review, then a manager approves it into force. The master document is attached from the procedure's Documents tab.
                        </InfoCard>
                    </div>
                )}

                {cur.key === 'review' && (
                    <div className="flex flex-col gap-5">
                        <div className="flex items-center gap-4">
                            <Ring pct={pct} />
                            <div>
                                <h3 className="text-base font-semibold text-foreground">Review &amp; save</h3>
                                <p className="text-sm text-muted-foreground">Confirm the details, then {isEdit ? 'save your changes' : 'save as a draft'}.</p>
                            </div>
                        </div>
                        <div className="grid grid-cols-1 gap-3.5 sm:grid-cols-2">
                            <ReviewCard icon={FileText} title="Basics" onEdit={() => setStepIndex(0)}>
                                <ReviewRow label="Title" value={d.title || '—'} />
                                <ReviewRow label="Reference" value={d.reference_number || '—'} />
                                <ReviewRow label="Category" value={options.categories.find((c) => c.value === d.category)?.label ?? '—'} />
                            </ReviewCard>
                            <ReviewCard icon={HardHat} title="Hazards & PPE" onEdit={() => setStepIndex(1)}>
                                <ReviewRow label="Hazards" value={d.hazards_addressed.length ? `${d.hazards_addressed.length} listed` : '—'} />
                                <ReviewRow label="PPE" value={d.ppe_required.length ? `${d.ppe_required.length} listed` : '—'} />
                                <ReviewRow label="Steps" value={`${d.steps.filter((s) => s.description.trim()).length} step(s)`} />
                            </ReviewCard>
                            <ReviewCard icon={Users} title="Applies to" onEdit={() => setStepIndex(3)}>
                                <ReviewRow label="Roles" value={d.applicable_roles.length ? `${d.applicable_roles.length} role(s)` : 'All roles'} />
                                <ReviewRow label="Sites" value={d.applicable_sites.length ? `${d.applicable_sites.length} site(s)` : 'All sites'} />
                                <ReviewRow label="Training" value={d.related_training.length ? `${d.related_training.length} course(s)` : '—'} />
                            </ReviewCard>
                            <ReviewCard icon={CalendarClock} title="Review cycle" onEdit={() => setStepIndex(4)}>
                                <ReviewRow label="Next review" value={d.review_date || '—'} />
                                <ReviewRow label="Cadence" value={d.review_frequency_months ? `Every ${d.review_frequency_months} months` : '—'} />
                                <ReviewRow label="Owner" value={options.owners.find((o) => o.id === d.owner_id)?.name ?? '—'} />
                            </ReviewCard>
                        </div>
                        {isEdit ? (
                            <Field label="Summary of changes" required hint="recorded in the version history" error={fieldError('change_summary')}>
                                <Textarea rows={2} value={d.change_summary} onChange={(e) => form.setData('change_summary', e.target.value)} placeholder="What changed in this version?" />
                            </Field>
                        ) : null}
                    </div>
                )}
            </WizardStepPane>
        </WizardShell>
    );
}

/* ------------------------------------------------------------------ */
/*  Field helpers                                                      */
/* ------------------------------------------------------------------ */

/** Free-text tag input with toggleable suggestions — for ppe_required / hazards_addressed. */
function FreeChips({ values, onChange, suggestions, placeholder }: { values: string[]; onChange: (v: string[]) => void; suggestions: string[]; placeholder: string }) {
    const [text, setText] = useState('');
    const add = (raw: string) => {
        const v = raw.trim();
        if (v && !values.includes(v)) onChange([...values, v]);
        setText('');
    };
    const remove = (v: string) => onChange(values.filter((x) => x !== v));
    const extraSuggestions = suggestions.filter((s) => !values.includes(s));

    return (
        <div className="flex flex-col gap-2.5">
            {values.length ? (
                <div className="flex flex-wrap gap-1.5">
                    {values.map((v) => (
                        <span key={v} className="inline-flex items-center gap-1 rounded-full border border-primary bg-primary/10 px-2.5 py-1 text-[13px] font-medium text-primary">
                            {v}
                            <button type="button" onClick={() => remove(v)} aria-label={`Remove ${v}`} className="text-primary/70 hover:text-primary">
                                <X className="h-3 w-3" />
                            </button>
                        </span>
                    ))}
                </div>
            ) : null}
            <div className="flex gap-2">
                <Input
                    value={text}
                    onChange={(e) => setText(e.target.value)}
                    onKeyDown={(e) => {
                        if (e.key === 'Enter') {
                            e.preventDefault();
                            add(text);
                        }
                    }}
                    placeholder={placeholder}
                    className="h-9"
                />
                <button type="button" onClick={() => add(text)} className="inline-flex shrink-0 items-center gap-1 rounded-lg border border-border px-3 text-sm font-medium text-foreground hover:bg-muted">
                    <Plus className="h-4 w-4" /> Add
                </button>
            </div>
            {extraSuggestions.length ? <ChipMulti values={[]} onChange={(v) => v.length && add(v[v.length - 1])} options={extraSuggestions} /> : null}
        </div>
    );
}

/** Toggle-chip multi-select over real entities (roles / sites / training). */
function EntityChecklist<T extends string | number>({
    options,
    selected,
    onChange,
    icon: Icon,
}: {
    options: { value: T; label: string; sub?: string }[];
    selected: T[];
    onChange: (v: T[]) => void;
    icon: IconType;
}) {
    if (!options.length) {
        return <p className="text-sm text-muted-foreground">No options available.</p>;
    }
    const toggle = (v: T) => onChange(selected.includes(v) ? selected.filter((x) => x !== v) : [...selected, v]);
    return (
        <div className="flex max-h-56 flex-wrap gap-1.5 overflow-y-auto rounded-lg border border-border bg-muted/20 p-2.5">
            {options.map((o) => {
                const active = selected.includes(o.value);
                return (
                    <button
                        key={String(o.value)}
                        type="button"
                        aria-pressed={active}
                        onClick={() => toggle(o.value)}
                        className={cn(
                            'inline-flex items-center gap-1.5 rounded-full border px-3 py-1.5 text-[13px] font-medium transition-colors',
                            active ? 'border-primary bg-primary/10 text-primary' : 'border-border bg-card text-foreground hover:border-primary/50',
                        )}
                    >
                        {active ? <Check className="h-3 w-3" /> : <Icon className="h-3 w-3 text-muted-foreground" />}
                        {o.label}
                        {o.sub ? <span className="text-[10px] font-bold text-muted-foreground">{o.sub}</span> : null}
                    </button>
                );
            })}
        </div>
    );
}

/** Repeatable step rows (description + optional safety note). */
function StepsEditor({ steps, onChange }: { steps: WizForm['steps']; onChange: (v: WizForm['steps']) => void }) {
    const update = (i: number, patch: Partial<WizForm['steps'][number]>) => onChange(steps.map((s, idx) => (idx === i ? { ...s, ...patch } : s)));
    const remove = (i: number) => onChange(steps.filter((_, idx) => idx !== i).map((s, idx) => ({ ...s, step_number: idx + 1 })));
    const add = () => onChange([...steps, { step_number: steps.length + 1, description: '', safety_notes: '' }]);

    return (
        <div className="flex flex-col gap-3">
            {steps.map((s, i) => (
                <div key={i} className="rounded-xl border border-border bg-card/60 p-3">
                    <div className="flex items-start gap-2.5">
                        <span className="mt-1 grid h-7 w-7 shrink-0 place-items-center rounded-full bg-primary/10 text-xs font-bold text-primary">{i + 1}</span>
                        <div className="flex min-w-0 flex-1 flex-col gap-2">
                            <Textarea rows={2} value={s.description} onChange={(e) => update(i, { description: e.target.value })} placeholder={`Step ${i + 1} — what to do`} />
                            <Input value={s.safety_notes} onChange={(e) => update(i, { safety_notes: e.target.value })} placeholder="Safety note (optional)" className="h-9" />
                        </div>
                        {steps.length > 1 ? (
                            <button type="button" onClick={() => remove(i)} aria-label={`Remove step ${i + 1}`} className="mt-1 shrink-0 text-muted-foreground hover:text-status-critical">
                                <Trash2 className="h-4 w-4" />
                            </button>
                        ) : null}
                    </div>
                </div>
            ))}
            <button type="button" onClick={add} className="inline-flex w-fit items-center gap-1.5 rounded-lg border border-dashed border-border px-3 py-2 text-sm font-medium text-muted-foreground transition-colors hover:border-primary/50 hover:text-primary">
                <Plus className="h-4 w-4" /> Add step
            </button>
        </div>
    );
}
