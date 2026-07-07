import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Switch } from '@/components/ui/switch';
import { Textarea } from '@/components/ui/textarea';
import { Field, InfoCard, SelectInput, StepHead } from '@/components/wizard/primitives';
import { ReviewCard, ReviewRow, WizardShell, WizardStepPane, WizardSuccessPane } from '@/components/wizard/shell';
import { useForm } from '@inertiajs/react';
import {
    BookOpen,
    CheckCircle2,
    ChevronDown,
    ChevronUp,
    ListChecks,
    Plus,
    Timer,
    Trash2,
    Zap,
} from 'lucide-react';
import { useState } from 'react';

/**
 * Playbook builder — creates or edits a response playbook through guided
 * steps: basics → response steps → automation & SLA → review. Replaces the
 * old inline mega-forms on the playbook list and detail pages.
 */

export type EditablePlaybookStep = {
    id: number | null;
    title: string;
    type: string;
    instructions: string;
    is_required: boolean;
    is_blocking: boolean;
    time_limit_minutes: string;
};

export type EditablePlaybook = {
    id: number;
    name: string;
    description: string;
    category: string;
    auto_attach: boolean;
    requires_approval: boolean;
    sla_acknowledge_minutes: string;
    sla_response_minutes: string;
    sla_resolution_minutes: string;
    required_evidence: string[];
    steps: EditablePlaybookStep[];
};

const EVIDENCE_OPTIONS = ['photo', 'video', 'document', 'signature', 'witness_statement', 'incident_report'];

const STEPS = [
    { key: 'basics', label: 'Basics', blurb: 'Name & category', icon: BookOpen },
    { key: 'steps', label: 'Response steps', blurb: 'What operators do', icon: ListChecks },
    { key: 'automation', label: 'Automation & SLA', blurb: 'Attach rules & clocks', icon: Zap },
    { key: 'review', label: 'Review', blurb: 'Check & save', icon: CheckCircle2 },
] as const;

const emptyStep = (): EditablePlaybookStep => ({
    id: null,
    title: '',
    type: 'task',
    instructions: '',
    is_required: true,
    is_blocking: false,
    time_limit_minutes: '',
});

function titleCase(s: string): string {
    return s.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());
}

export function PlaybookWizard({
    open,
    onClose,
    categories,
    stepTypes,
    playbook = null,
}: {
    open: boolean;
    onClose: () => void;
    categories: Record<string, string>;
    stepTypes: Record<string, string>;
    /** Present = edit that playbook; absent = create a new one. */
    playbook?: EditablePlaybook | null;
}) {
    const editing = playbook !== null;
    const [step, setStep] = useState(0);
    const [submitted, setSubmitted] = useState(false);

    const form = useForm({
        name: playbook?.name ?? '',
        description: playbook?.description ?? '',
        category: playbook?.category ?? 'emergency',
        auto_attach: playbook?.auto_attach ?? false,
        requires_approval: playbook?.requires_approval ?? false,
        sla_acknowledge_minutes: playbook?.sla_acknowledge_minutes ?? '',
        sla_response_minutes: playbook?.sla_response_minutes ?? '',
        sla_resolution_minutes: playbook?.sla_resolution_minutes ?? '',
        required_evidence: playbook?.required_evidence ?? ([] as string[]),
        steps: playbook?.steps?.length ? playbook.steps : [emptyStep()],
    });

    const close = () => {
        form.reset();
        form.clearErrors();
        setStep(0);
        setSubmitted(false);
        onClose();
    };

    const setSteps = (steps: EditablePlaybookStep[]) => form.setData('steps', steps);
    const updateStep = (i: number, patch: Partial<EditablePlaybookStep>) =>
        setSteps(form.data.steps.map((s, idx) => (idx === i ? { ...s, ...patch } : s)));
    const moveStep = (i: number, dir: -1 | 1) => {
        const next = i + dir;
        if (next < 0 || next >= form.data.steps.length) return;
        const copy = [...form.data.steps];
        [copy[i], copy[next]] = [copy[next], copy[i]];
        setSteps(copy);
    };

    const validSteps = form.data.steps.filter((s) => s.title.trim());
    const stepValid =
        step === 0 ? Boolean(form.data.name.trim() && form.data.category) : step === 1 ? validSteps.length > 0 : true;

    const submit = () => {
        form.transform((data) => ({
            name: data.name,
            description: data.description || null,
            category: data.category,
            auto_attach: data.auto_attach,
            requires_approval: data.requires_approval,
            sla_acknowledge_minutes: data.sla_acknowledge_minutes ? Number(data.sla_acknowledge_minutes) : null,
            sla_response_minutes: data.sla_response_minutes ? Number(data.sla_response_minutes) : null,
            sla_resolution_minutes: data.sla_resolution_minutes ? Number(data.sla_resolution_minutes) : null,
            required_evidence: data.required_evidence,
            steps: data.steps
                .filter((s) => s.title.trim())
                .map((s) => ({
                    id: s.id,
                    title: s.title,
                    type: s.type,
                    instructions: s.instructions || null,
                    is_required: s.is_required,
                    is_blocking: s.is_blocking,
                    time_limit_minutes: s.time_limit_minutes ? Number(s.time_limit_minutes) : null,
                })),
        }));
        const opts = {
            preserveScroll: true,
            onSuccess: (pg: { props: Record<string, unknown> }) => {
                if (!(pg.props as { flash?: { error?: string } }).flash?.error) setSubmitted(true);
            },
        };
        if (editing && playbook) {
            form.put(`/control-room/playbooks/${playbook.id}`, opts);
        } else {
            form.post('/control-room/playbooks', opts);
        }
    };

    const err = form.errors as Record<string, string | undefined>;

    return (
        <WizardShell
            open={open}
            onClose={close}
            title={editing ? 'Edit playbook' : 'New playbook'}
            description="Build a step-by-step response procedure for alerts"
            railIcon={BookOpen}
            railTitle={editing ? 'Edit playbook' : 'New playbook'}
            railSub={editing ? form.data.name || 'Response procedure' : 'Response procedure'}
            steps={STEPS}
            stepIndex={step}
            onStepClick={(i) => (i <= step ? setStep(i) : undefined)}
            success={
                submitted ? (
                    <WizardSuccessPane
                        title={editing ? 'Playbook updated' : 'Playbook created'}
                        blurb={
                            form.data.auto_attach
                                ? 'It will attach automatically to matching new alerts, and operators can start it manually from any alert workspace.'
                                : 'Operators can start it from any alert workspace (Playbook section → Start a playbook).'
                        }
                        actions={
                            <Button onClick={close}>Done</Button>
                        }
                    />
                ) : undefined
            }
            footerStart={
                <span className="text-xs text-muted-foreground">
                    {validSteps.length} step{validSteps.length === 1 ? '' : 's'} defined
                </span>
            }
            footerEnd={
                <>
                    {step > 0 ? (
                        <Button variant="outline" size="sm" onClick={() => setStep(step - 1)}>
                            Back
                        </Button>
                    ) : null}
                    {step < STEPS.length - 1 ? (
                        <Button size="sm" onClick={() => setStep(step + 1)} disabled={!stepValid}>
                            Next
                        </Button>
                    ) : (
                        <Button size="sm" onClick={submit} disabled={form.processing || !form.data.name.trim() || validSteps.length === 0}>
                            {editing ? 'Save playbook' : 'Create playbook'}
                        </Button>
                    )}
                </>
            }
        >
            {step === 0 ? (
                <WizardStepPane>
                    <div className="flex flex-col gap-4">
                        <StepHead icon={BookOpen} title="What's this playbook for?" blurb="A playbook is the step-by-step procedure an operator follows for a type of alert." />
                        <Field label="Name" required error={err.name}>
                            <Input value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} placeholder="e.g. Missing person response" />
                        </Field>
                        <Field label="Category" required error={err.category}>
                            <SelectInput
                                value={form.data.category}
                                onChange={(v) => form.setData('category', v)}
                                placeholder="Select category"
                                options={Object.entries(categories).map(([value, label]) => ({ value, label }))}
                            />
                        </Field>
                        <Field label="Description" hint="Optional — when should this playbook be used?">
                            <Textarea rows={3} value={form.data.description} onChange={(e) => form.setData('description', e.target.value)} />
                        </Field>
                    </div>
                </WizardStepPane>
            ) : null}

            {step === 1 ? (
                <WizardStepPane>
                    <div className="flex flex-col gap-4">
                        <StepHead icon={ListChecks} title="What should the operator do, in order?" blurb="Each step becomes a tick-box on the alert. Blocking steps must finish before the next one starts." />
                        {err.steps ? (
                            <InfoCard icon={ListChecks} tone="crit">
                                {err.steps}
                            </InfoCard>
                        ) : null}
                        <div className="flex flex-col gap-3">
                            {form.data.steps.map((s, i) => (
                                <div key={i} className="rounded-xl border border-border p-3">
                                    <div className="mb-2 flex items-center justify-between gap-2">
                                        <span className="grid h-6 w-6 place-items-center rounded-full bg-muted text-[11px] font-bold text-muted-foreground">{i + 1}</span>
                                        <div className="flex items-center gap-1">
                                            <Button variant="ghost" size="sm" onClick={() => moveStep(i, -1)} disabled={i === 0} aria-label="Move up">
                                                <ChevronUp className="h-3.5 w-3.5" />
                                            </Button>
                                            <Button variant="ghost" size="sm" onClick={() => moveStep(i, 1)} disabled={i === form.data.steps.length - 1} aria-label="Move down">
                                                <ChevronDown className="h-3.5 w-3.5" />
                                            </Button>
                                            <Button
                                                variant="ghost"
                                                size="sm"
                                                className="text-status-critical hover:text-status-critical"
                                                onClick={() => form.data.steps.length > 1 && setSteps(form.data.steps.filter((_, idx) => idx !== i))}
                                                disabled={form.data.steps.length <= 1}
                                                aria-label="Remove step"
                                            >
                                                <Trash2 className="h-3.5 w-3.5" />
                                            </Button>
                                        </div>
                                    </div>
                                    <div className="grid gap-2.5 sm:grid-cols-2">
                                        <Field label="Step" required>
                                            <Input value={s.title} onChange={(e) => updateStep(i, { title: e.target.value })} placeholder="e.g. Call the site lead" />
                                        </Field>
                                        <Field label="Type">
                                            <SelectInput
                                                value={s.type}
                                                onChange={(v) => updateStep(i, { type: v })}
                                                placeholder="Type"
                                                options={Object.entries(stepTypes).map(([value, label]) => ({ value, label }))}
                                            />
                                        </Field>
                                    </div>
                                    <Field label="Instructions" hint="Optional — shown to the operator on this step">
                                        <Textarea rows={2} value={s.instructions} onChange={(e) => updateStep(i, { instructions: e.target.value })} />
                                    </Field>
                                    <div className="mt-2 flex flex-wrap items-center gap-4 text-sm">
                                        <label className="flex cursor-pointer items-center gap-2">
                                            <Checkbox checked={s.is_required} onCheckedChange={(v) => updateStep(i, { is_required: Boolean(v) })} />
                                            Required
                                        </label>
                                        <label className="flex cursor-pointer items-center gap-2">
                                            <Checkbox checked={s.is_blocking} onCheckedChange={(v) => updateStep(i, { is_blocking: Boolean(v) })} />
                                            Blocking
                                        </label>
                                        <label className="flex items-center gap-2 text-muted-foreground">
                                            <Timer className="h-3.5 w-3.5" />
                                            <Input
                                                type="number"
                                                min={1}
                                                className="h-8 w-24"
                                                value={s.time_limit_minutes}
                                                onChange={(e) => updateStep(i, { time_limit_minutes: e.target.value })}
                                                placeholder="mins"
                                            />
                                            time limit
                                        </label>
                                    </div>
                                </div>
                            ))}
                        </div>
                        <Button variant="outline" size="sm" className="self-start" onClick={() => setSteps([...form.data.steps, emptyStep()])}>
                            <Plus className="mr-1.5 h-3.5 w-3.5" /> Add step
                        </Button>
                    </div>
                </WizardStepPane>
            ) : null}

            {step === 2 ? (
                <WizardStepPane>
                    <div className="flex flex-col gap-4">
                        <StepHead icon={Zap} title="Automation & clocks" blurb="Optional — attach automatically, require sign-off, and set response-time targets." />
                        <label className="flex items-center gap-3 rounded-xl border border-border p-3">
                            <Switch checked={form.data.auto_attach} onCheckedChange={(v) => form.setData('auto_attach', v)} />
                            <span className="text-sm">
                                <span className="font-medium text-foreground">Attach automatically</span>{' '}
                                <span className="text-muted-foreground">— new matching alerts start this playbook on their own</span>
                            </span>
                        </label>
                        <label className="flex items-center gap-3 rounded-xl border border-border p-3">
                            <Switch checked={form.data.requires_approval} onCheckedChange={(v) => form.setData('requires_approval', v)} />
                            <span className="text-sm">
                                <span className="font-medium text-foreground">Requires approval</span>{' '}
                                <span className="text-muted-foreground">— a manager signs off before the run completes</span>
                            </span>
                        </label>
                        <div className="grid gap-2.5 sm:grid-cols-3">
                            <Field label="Acknowledge within" hint="Minutes — optional">
                                <Input type="number" min={1} value={form.data.sla_acknowledge_minutes} onChange={(e) => form.setData('sla_acknowledge_minutes', e.target.value)} />
                            </Field>
                            <Field label="Respond within" hint="Minutes — optional">
                                <Input type="number" min={1} value={form.data.sla_response_minutes} onChange={(e) => form.setData('sla_response_minutes', e.target.value)} />
                            </Field>
                            <Field label="Resolve within" hint="Minutes — optional">
                                <Input type="number" min={1} value={form.data.sla_resolution_minutes} onChange={(e) => form.setData('sla_resolution_minutes', e.target.value)} />
                            </Field>
                        </div>
                        <Field label="Evidence that must be collected" hint="Optional">
                            <div className="flex flex-wrap gap-2">
                                {EVIDENCE_OPTIONS.map((opt) => {
                                    const on = form.data.required_evidence.includes(opt);
                                    return (
                                        <button
                                            key={opt}
                                            type="button"
                                            onClick={() =>
                                                form.setData(
                                                    'required_evidence',
                                                    on ? form.data.required_evidence.filter((e) => e !== opt) : [...form.data.required_evidence, opt],
                                                )
                                            }
                                            className={`rounded-full border px-3 py-1.5 text-xs font-medium transition-colors ${on ? 'border-primary bg-primary/10 text-primary' : 'border-border text-muted-foreground hover:bg-muted'}`}
                                        >
                                            {titleCase(opt)}
                                        </button>
                                    );
                                })}
                            </div>
                        </Field>
                    </div>
                </WizardStepPane>
            ) : null}

            {step === 3 ? (
                <WizardStepPane>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <ReviewCard icon={BookOpen} title="Basics" onEdit={() => setStep(0)}>
                            <ReviewRow label="Name" value={form.data.name} />
                            <ReviewRow label="Category" value={categories[form.data.category] ?? form.data.category} />
                            <ReviewRow label="Description" value={form.data.description || undefined} />
                        </ReviewCard>
                        <ReviewCard icon={Zap} title="Automation & SLA" onEdit={() => setStep(2)}>
                            <ReviewRow label="Auto-attach" value={form.data.auto_attach ? 'Yes' : 'No'} />
                            <ReviewRow label="Approval" value={form.data.requires_approval ? 'Required' : 'Not required'} />
                            <ReviewRow label="Ack / Respond / Resolve" value={`${form.data.sla_acknowledge_minutes || '—'} / ${form.data.sla_response_minutes || '—'} / ${form.data.sla_resolution_minutes || '—'} min`} />
                            <ReviewRow label="Evidence" value={form.data.required_evidence.length ? form.data.required_evidence.map(titleCase).join(', ') : undefined} />
                        </ReviewCard>
                        <ReviewCard icon={ListChecks} title={`Response steps (${validSteps.length})`} onEdit={() => setStep(1)} span>
                            {validSteps.map((s, i) => (
                                <ReviewRow key={i} label={`${i + 1}. ${stepTypes[s.type] ?? titleCase(s.type)}`} value={s.title} />
                            ))}
                        </ReviewCard>
                    </div>
                </WizardStepPane>
            ) : null}
        </WizardShell>
    );
}
