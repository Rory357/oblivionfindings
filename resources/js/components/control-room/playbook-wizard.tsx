import {
    plainLabel,
    SEVERITY_OPTIONS,
} from '@/components/control-room/alert-record/alert-labels';
import { ChoiceChips, withSaved } from '@/components/control-room/choice-chips';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Switch } from '@/components/ui/switch';
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
    WizardStepPane,
    WizardSuccessPane,
} from '@/components/wizard/shell';
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
 * Response procedure builder (code name: playbook) — adds or edits a
 * procedure through guided steps: basics → response steps → when it starts
 * and time targets → review. The words say "procedure"; the fields sent to
 * the server keep their playbook names.
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
    /** The alert types and severities that start it on its own. */
    trigger_alert_types?: string[];
    trigger_severities?: string[];
    /**
     * A saved setting this wizard has no field for. It is sent back
     * unchanged on save, so editing a procedure never clears when it
     * escalates.
     */
    escalation_after_minutes?: number | null;
};

const SEVERITY_VALUES = SEVERITY_OPTIONS.map((option) => option.value);
const SEVERITY_LABEL: Record<string, string> = Object.fromEntries(
    SEVERITY_OPTIONS.map((option) => [option.value, option.label]),
);
const severityLabel = (value: string) =>
    SEVERITY_LABEL[value] ?? plainLabel(value);

/** Which wizard step shows each field the server can refuse. */
const ERROR_STEP: Record<string, number> = {
    name: 0,
    category: 0,
    description: 0,
    steps: 1,
    trigger_alert_types: 2,
    trigger_severities: 2,
};

function firstErrorStep(errors: Record<string, unknown>): number | null {
    const steps = Object.keys(errors).map(
        (key) => ERROR_STEP[key] ?? (key.startsWith('steps.') ? 1 : 2),
    );
    return steps.length ? Math.min(...steps) : null;
}

const EVIDENCE_OPTIONS = [
    'photo',
    'video',
    'document',
    'signature',
    'witness_statement',
    'incident_report',
];

const STEPS = [
    {
        key: 'basics',
        label: 'Basics',
        blurb: 'Name and category',
        icon: BookOpen,
    },
    {
        key: 'steps',
        label: 'Response steps',
        blurb: 'What operators do',
        icon: ListChecks,
    },
    {
        key: 'automation',
        label: 'Starting and targets',
        blurb: 'When it starts, time targets',
        icon: Zap,
    },
    {
        key: 'review',
        label: 'Review',
        blurb: 'Check and save',
        icon: CheckCircle2,
    },
] as const;

export type WizardStepKey = (typeof STEPS)[number]['key'];

const emptyStep = (): EditablePlaybookStep => ({
    id: null,
    title: '',
    type: 'task',
    instructions: '',
    is_required: true,
    is_blocking: false,
    time_limit_minutes: '',
});

/** "witness_statement" → "Witness statement" (sentence case). */
function sentenceCase(s: string): string {
    const words = s.replace(/_/g, ' ').trim().toLowerCase();
    return words.charAt(0).toUpperCase() + words.slice(1);
}

export function PlaybookWizard({
    open,
    onClose,
    categories,
    stepTypes,
    alertTypes,
    playbook = null,
    openAt = 'basics',
}: {
    open: boolean;
    onClose: () => void;
    categories: Record<string, string>;
    stepTypes: Record<string, string>;
    /** The alert types a procedure can start on (signal types and types already raised). */
    alertTypes: string[];
    /** Present = edit that procedure; absent = add a new one. */
    playbook?: EditablePlaybook | null;
    /**
     * Editing only: the step to open on — "automation" takes an editor
     * straight to choosing which alerts start it. Adding always opens on
     * the basics.
     */
    openAt?: WizardStepKey;
}) {
    const editing = playbook !== null;
    const [step, setStep] = useState(() =>
        editing ? Math.max(STEPS.findIndex((s) => s.key === openAt), 0) : 0,
    );
    const [submitted, setSubmitted] = useState(false);

    const form = useForm({
        name: playbook?.name ?? '',
        description: playbook?.description ?? '',
        category: playbook?.category ?? 'emergency',
        auto_attach: playbook?.auto_attach ?? false,
        trigger_alert_types: playbook?.trigger_alert_types ?? ([] as string[]),
        trigger_severities: playbook?.trigger_severities ?? ([] as string[]),
        requires_approval: playbook?.requires_approval ?? false,
        sla_acknowledge_minutes: playbook?.sla_acknowledge_minutes ?? '',
        sla_response_minutes: playbook?.sla_response_minutes ?? '',
        sla_resolution_minutes: playbook?.sla_resolution_minutes ?? '',
        required_evidence: playbook?.required_evidence ?? ([] as string[]),
        steps: playbook?.steps?.length ? playbook.steps : [emptyStep()],
    });

    // Options include anything already saved, so a save never drops it.
    const alertTypeOptions = withSaved(alertTypes, form.data.trigger_alert_types);
    const severityOptions = withSaved(SEVERITY_VALUES, form.data.trigger_severities);
    // "Start on its own" needs at least one alert type and one severity: an
    // empty list never matches an alert, so the procedure would never start.
    const triggersMissing =
        form.data.auto_attach &&
        (form.data.trigger_alert_types.length === 0 ||
            form.data.trigger_severities.length === 0);

    const close = () => {
        form.reset();
        form.clearErrors();
        setStep(0);
        setSubmitted(false);
        onClose();
    };

    const setSteps = (steps: EditablePlaybookStep[]) =>
        form.setData('steps', steps);
    const updateStep = (i: number, patch: Partial<EditablePlaybookStep>) =>
        setSteps(
            form.data.steps.map((s, idx) =>
                idx === i ? { ...s, ...patch } : s,
            ),
        );
    const moveStep = (i: number, dir: -1 | 1) => {
        const next = i + dir;
        if (next < 0 || next >= form.data.steps.length) return;
        const copy = [...form.data.steps];
        [copy[i], copy[next]] = [copy[next], copy[i]];
        setSteps(copy);
    };

    const validSteps = form.data.steps.filter((s) => s.title.trim());
    const stepValid =
        step === 0
            ? Boolean(form.data.name.trim() && form.data.category)
            : step === 1
              ? validSteps.length > 0
              : step === 2
                ? !triggersMissing
                : true;

    const submit = () => {
        form.transform((data) => ({
            name: data.name,
            description: data.description || null,
            category: data.category,
            auto_attach: data.auto_attach,
            // Always the form's own lists; the server requires both while
            // "Start on its own" is on.
            trigger_alert_types: data.trigger_alert_types,
            trigger_severities: data.trigger_severities,
            requires_approval: data.requires_approval,
            sla_acknowledge_minutes: data.sla_acknowledge_minutes
                ? Number(data.sla_acknowledge_minutes)
                : null,
            sla_response_minutes: data.sla_response_minutes
                ? Number(data.sla_response_minutes)
                : null,
            sla_resolution_minutes: data.sla_resolution_minutes
                ? Number(data.sla_resolution_minutes)
                : null,
            required_evidence: data.required_evidence,
            ...(editing &&
            playbook &&
            playbook.escalation_after_minutes !== undefined
                ? {
                      escalation_after_minutes:
                          playbook.escalation_after_minutes,
                  }
                : {}),
            steps: data.steps
                .filter((s) => s.title.trim())
                .map((s) => ({
                    id: s.id,
                    title: s.title,
                    type: s.type,
                    instructions: s.instructions || null,
                    is_required: s.is_required,
                    is_blocking: s.is_blocking,
                    time_limit_minutes: s.time_limit_minutes
                        ? Number(s.time_limit_minutes)
                        : null,
                })),
        }));
        const opts = {
            preserveScroll: true,
            onSuccess: (pg: { props: Record<string, unknown> }) => {
                if (!(pg.props as { flash?: { error?: string } }).flash?.error)
                    setSubmitted(true);
            },
            // Show a refused field on the step that has it, not under Review.
            onError: (errors: Record<string, unknown>) => {
                const errorStep = firstErrorStep(errors);
                if (errorStep !== null) setStep(errorStep);
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
            title={
                editing ? 'Edit response procedure' : 'Add response procedure'
            }
            description="Build a step-by-step response procedure for alerts"
            railIcon={BookOpen}
            railTitle={
                editing ? 'Edit response procedure' : 'Add response procedure'
            }
            railSub={
                editing
                    ? form.data.name || 'Response procedure'
                    : 'Response procedure'
            }
            steps={STEPS}
            stepIndex={step}
            onStepClick={(i) => (i <= step ? setStep(i) : undefined)}
            success={
                submitted ? (
                    <WizardSuccessPane
                        title={editing ? 'Procedure saved' : 'Procedure added'}
                        blurb={
                            form.data.auto_attach
                                ? 'It starts on its own for matching new alerts, and operators can also start it from any alert.'
                                : 'Operators can start it from any alert (Procedure tab → Start a response procedure).'
                        }
                        actions={<Button onClick={close}>Done</Button>}
                    />
                ) : undefined
            }
            footerStart={
                <span className="text-xs text-muted-foreground">
                    {validSteps.length} step{validSteps.length === 1 ? '' : 's'}{' '}
                    added
                </span>
            }
            footerEnd={
                <>
                    {step > 0 ? (
                        <Button
                            variant="outline"
                            size="sm"
                            onClick={() => setStep(step - 1)}
                        >
                            Back
                        </Button>
                    ) : null}
                    {step < STEPS.length - 1 ? (
                        <Button
                            size="sm"
                            onClick={() => setStep(step + 1)}
                            disabled={!stepValid}
                        >
                            Next
                        </Button>
                    ) : (
                        <Button
                            size="sm"
                            onClick={submit}
                            disabled={
                                form.processing ||
                                !form.data.name.trim() ||
                                validSteps.length === 0 ||
                                triggersMissing
                            }
                        >
                            Save procedure
                        </Button>
                    )}
                </>
            }
        >
            {step === 0 ? (
                <WizardStepPane>
                    <div className="flex flex-col gap-4">
                        <StepHead
                            icon={BookOpen}
                            title="What's this procedure for?"
                            blurb="A procedure is the list of steps an operator follows for a type of alert."
                        />
                        <Field label="Name" required error={err.name}>
                            <Input
                                value={form.data.name}
                                onChange={(e) =>
                                    form.setData('name', e.target.value)
                                }
                                placeholder="e.g. Missing person response"
                            />
                        </Field>
                        <Field label="Category" required error={err.category}>
                            <SelectInput
                                value={form.data.category}
                                onChange={(v) => form.setData('category', v)}
                                placeholder="Select category"
                                options={Object.entries(categories).map(
                                    ([value, label]) => ({ value, label }),
                                )}
                            />
                        </Field>
                        <Field
                            label="Description"
                            hint="Optional — when should this procedure be used?"
                        >
                            <Textarea
                                rows={3}
                                value={form.data.description}
                                onChange={(e) =>
                                    form.setData('description', e.target.value)
                                }
                            />
                        </Field>
                    </div>
                </WizardStepPane>
            ) : null}

            {step === 1 ? (
                <WizardStepPane>
                    <div className="flex flex-col gap-4">
                        <StepHead
                            icon={ListChecks}
                            title="What should the operator do, in order?"
                            blurb="Each step becomes a tick-box on the alert. A step marked “Must finish first” has to be done before the next one starts."
                        />
                        {err.steps ? (
                            <InfoCard icon={ListChecks} tone="crit">
                                {err.steps}
                            </InfoCard>
                        ) : null}
                        <div className="flex flex-col gap-3">
                            {form.data.steps.map((s, i) => (
                                <div
                                    key={i}
                                    className="rounded-xl border border-border p-3"
                                >
                                    <div className="mb-2 flex items-center justify-between gap-2">
                                        <span className="grid h-6 w-6 place-items-center rounded-full bg-muted text-[11px] font-bold text-muted-foreground">
                                            {i + 1}
                                        </span>
                                        <div className="flex items-center gap-1">
                                            <Button
                                                variant="ghost"
                                                size="sm"
                                                onClick={() => moveStep(i, -1)}
                                                disabled={i === 0}
                                                aria-label="Move step up"
                                            >
                                                <ChevronUp className="h-3.5 w-3.5" />
                                            </Button>
                                            <Button
                                                variant="ghost"
                                                size="sm"
                                                onClick={() => moveStep(i, 1)}
                                                disabled={
                                                    i ===
                                                    form.data.steps.length - 1
                                                }
                                                aria-label="Move step down"
                                            >
                                                <ChevronDown className="h-3.5 w-3.5" />
                                            </Button>
                                            <Button
                                                variant="ghost"
                                                size="sm"
                                                className="text-status-critical hover:text-status-critical"
                                                onClick={() =>
                                                    form.data.steps.length >
                                                        1 &&
                                                    setSteps(
                                                        form.data.steps.filter(
                                                            (_, idx) =>
                                                                idx !== i,
                                                        ),
                                                    )
                                                }
                                                disabled={
                                                    form.data.steps.length <= 1
                                                }
                                                aria-label="Remove step"
                                            >
                                                <Trash2 className="h-3.5 w-3.5" />
                                            </Button>
                                        </div>
                                    </div>
                                    <div className="grid gap-2.5 sm:grid-cols-2">
                                        <Field label="Step" required>
                                            <Input
                                                value={s.title}
                                                onChange={(e) =>
                                                    updateStep(i, {
                                                        title: e.target.value,
                                                    })
                                                }
                                                placeholder="e.g. Call the site lead"
                                            />
                                        </Field>
                                        <Field label="Type">
                                            <SelectInput
                                                value={s.type}
                                                onChange={(v) =>
                                                    updateStep(i, { type: v })
                                                }
                                                placeholder="Type"
                                                options={Object.entries(
                                                    stepTypes,
                                                ).map(([value, label]) => ({
                                                    value,
                                                    label,
                                                }))}
                                            />
                                        </Field>
                                    </div>
                                    <Field
                                        label="Instructions"
                                        hint="Optional — shown to the operator on this step"
                                    >
                                        <Textarea
                                            rows={2}
                                            value={s.instructions}
                                            onChange={(e) =>
                                                updateStep(i, {
                                                    instructions:
                                                        e.target.value,
                                                })
                                            }
                                        />
                                    </Field>
                                    <div className="mt-2 flex flex-wrap items-center gap-4 text-sm">
                                        <label className="flex cursor-pointer items-center gap-2">
                                            <Checkbox
                                                checked={s.is_required}
                                                onCheckedChange={(v) =>
                                                    updateStep(i, {
                                                        is_required: Boolean(v),
                                                    })
                                                }
                                            />
                                            Required
                                        </label>
                                        <label className="flex cursor-pointer items-center gap-2">
                                            <Checkbox
                                                checked={s.is_blocking}
                                                onCheckedChange={(v) =>
                                                    updateStep(i, {
                                                        is_blocking: Boolean(v),
                                                    })
                                                }
                                            />
                                            Must finish first
                                        </label>
                                        <label className="flex items-center gap-2 text-muted-foreground">
                                            <Timer className="h-3.5 w-3.5" />
                                            <Input
                                                type="number"
                                                min={1}
                                                className="h-8 w-24"
                                                value={s.time_limit_minutes}
                                                onChange={(e) =>
                                                    updateStep(i, {
                                                        time_limit_minutes:
                                                            e.target.value,
                                                    })
                                                }
                                                placeholder="min"
                                                aria-label="Minutes to do this step"
                                            />
                                            minutes to do it
                                        </label>
                                    </div>
                                </div>
                            ))}
                        </div>
                        <Button
                            variant="outline"
                            size="sm"
                            className="self-start"
                            onClick={() =>
                                setSteps([...form.data.steps, emptyStep()])
                            }
                        >
                            <Plus className="mr-1.5 h-3.5 w-3.5" /> Add step
                        </Button>
                    </div>
                </WizardStepPane>
            ) : null}

            {step === 2 ? (
                <WizardStepPane>
                    <div className="flex flex-col gap-4">
                        <StepHead
                            icon={Zap}
                            title="When it starts and time targets"
                            blurb="Optional — start it on its own, ask for sign-off, and set time targets."
                        />
                        <label className="flex items-center gap-3 rounded-xl border border-border p-3">
                            <Switch
                                checked={form.data.auto_attach}
                                onCheckedChange={(v) =>
                                    form.setData('auto_attach', v)
                                }
                            />
                            <span className="text-sm">
                                <span className="font-medium text-foreground">
                                    Start on its own
                                </span>{' '}
                                <span className="text-muted-foreground">
                                    — matching new alerts start this procedure
                                    automatically
                                </span>
                            </span>
                        </label>
                        {form.data.auto_attach ? (
                            <div className="flex flex-col gap-4 rounded-xl border border-border p-3">
                                <Field
                                    label="Alerts that start it"
                                    required
                                    hint="Choose one or more"
                                    labelId="playbook-trigger-types-label"
                                    errorId="playbook-trigger-types-error"
                                    error={err.trigger_alert_types}
                                >
                                    {alertTypeOptions.length ? (
                                        <ChoiceChips
                                            label="Alerts that start it"
                                            labelledBy="playbook-trigger-types-label"
                                            describedBy={
                                                err.trigger_alert_types
                                                    ? 'playbook-trigger-types-error'
                                                    : undefined
                                            }
                                            invalid={Boolean(
                                                err.trigger_alert_types,
                                            )}
                                            options={alertTypeOptions}
                                            value={form.data.trigger_alert_types}
                                            onChange={(next) =>
                                                form.setData(
                                                    'trigger_alert_types',
                                                    next,
                                                )
                                            }
                                            format={plainLabel}
                                        />
                                    ) : (
                                        <p className="text-subtle">
                                            No alert types to choose from yet.
                                            They appear here once Control Room
                                            has signal types or has raised an
                                            alert.
                                        </p>
                                    )}
                                </Field>
                                <Field
                                    label="Severities that start it"
                                    required
                                    hint="Choose one or more"
                                    labelId="playbook-trigger-severities-label"
                                    errorId="playbook-trigger-severities-error"
                                    error={err.trigger_severities}
                                >
                                    <ChoiceChips
                                        label="Severities that start it"
                                        labelledBy="playbook-trigger-severities-label"
                                        describedBy={
                                            err.trigger_severities
                                                ? 'playbook-trigger-severities-error'
                                                : undefined
                                        }
                                        invalid={Boolean(err.trigger_severities)}
                                        options={severityOptions}
                                        value={form.data.trigger_severities}
                                        onChange={(next) =>
                                            form.setData(
                                                'trigger_severities',
                                                next,
                                            )
                                        }
                                        format={severityLabel}
                                    />
                                </Field>
                                {triggersMissing ? (
                                    <p className="text-caption">
                                        It starts only for an alert that
                                        matches both. Choose at least one of
                                        each to go on, or switch “Start on its
                                        own” off.
                                    </p>
                                ) : null}
                            </div>
                        ) : null}
                        <label className="flex items-center gap-3 rounded-xl border border-border p-3">
                            <Switch
                                checked={form.data.requires_approval}
                                onCheckedChange={(v) =>
                                    form.setData('requires_approval', v)
                                }
                            />
                            <span className="text-sm">
                                <span className="font-medium text-foreground">
                                    Needs sign-off
                                </span>{' '}
                                <span className="text-muted-foreground">
                                    — a manager signs off before it&apos;s
                                    finished
                                </span>
                            </span>
                        </label>
                        <div className="grid gap-2.5 sm:grid-cols-3">
                            <Field
                                label="Acknowledge within"
                                hint="Minutes — optional"
                            >
                                <Input
                                    type="number"
                                    min={1}
                                    value={form.data.sla_acknowledge_minutes}
                                    onChange={(e) =>
                                        form.setData(
                                            'sla_acknowledge_minutes',
                                            e.target.value,
                                        )
                                    }
                                />
                            </Field>
                            <Field
                                label="Respond within"
                                hint="Minutes — optional"
                            >
                                <Input
                                    type="number"
                                    min={1}
                                    value={form.data.sla_response_minutes}
                                    onChange={(e) =>
                                        form.setData(
                                            'sla_response_minutes',
                                            e.target.value,
                                        )
                                    }
                                />
                            </Field>
                            <Field
                                label="Resolve within"
                                hint="Minutes — optional"
                            >
                                <Input
                                    type="number"
                                    min={1}
                                    value={form.data.sla_resolution_minutes}
                                    onChange={(e) =>
                                        form.setData(
                                            'sla_resolution_minutes',
                                            e.target.value,
                                        )
                                    }
                                />
                            </Field>
                        </div>
                        <Field
                            label="Evidence that must be collected"
                            hint="Optional"
                            labelId="playbook-evidence-label"
                        >
                            <ChoiceChips
                                label="Evidence that must be collected"
                                labelledBy="playbook-evidence-label"
                                options={withSaved(
                                    EVIDENCE_OPTIONS,
                                    form.data.required_evidence,
                                )}
                                value={form.data.required_evidence}
                                onChange={(next) =>
                                    form.setData('required_evidence', next)
                                }
                                format={sentenceCase}
                            />
                        </Field>
                    </div>
                </WizardStepPane>
            ) : null}

            {step === 3 ? (
                <WizardStepPane>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <ReviewCard
                            icon={BookOpen}
                            title="Basics"
                            onEdit={() => setStep(0)}
                        >
                            <ReviewRow label="Name" value={form.data.name} />
                            <ReviewRow
                                label="Category"
                                value={
                                    categories[form.data.category] ??
                                    form.data.category
                                }
                            />
                            <ReviewRow
                                label="Description"
                                value={form.data.description || undefined}
                            />
                        </ReviewCard>
                        <ReviewCard
                            icon={Zap}
                            title="Starting and targets"
                            onEdit={() => setStep(2)}
                        >
                            <ReviewRow
                                label="Starts on its own"
                                value={form.data.auto_attach ? 'Yes' : 'No'}
                            />
                            {form.data.auto_attach ? (
                                <>
                                    <ReviewRow
                                        label="Alerts that start it"
                                        value={
                                            form.data.trigger_alert_types
                                                .map(plainLabel)
                                                .join(', ') || undefined
                                        }
                                    />
                                    <ReviewRow
                                        label="Severities that start it"
                                        value={
                                            form.data.trigger_severities
                                                .map(severityLabel)
                                                .join(', ') || undefined
                                        }
                                    />
                                </>
                            ) : null}
                            <ReviewRow
                                label="Sign-off"
                                value={
                                    form.data.requires_approval
                                        ? 'Needed'
                                        : 'Not needed'
                                }
                            />
                            <ReviewRow
                                label="Time targets"
                                value={
                                    [
                                        form.data.sla_acknowledge_minutes
                                            ? `acknowledge ${form.data.sla_acknowledge_minutes} min`
                                            : null,
                                        form.data.sla_response_minutes
                                            ? `respond ${form.data.sla_response_minutes} min`
                                            : null,
                                        form.data.sla_resolution_minutes
                                            ? `resolve ${form.data.sla_resolution_minutes} min`
                                            : null,
                                    ]
                                        .filter(Boolean)
                                        .join(' · ') || undefined
                                }
                            />
                            <ReviewRow
                                label="Evidence"
                                value={
                                    form.data.required_evidence.length
                                        ? form.data.required_evidence
                                              .map(sentenceCase)
                                              .join(', ')
                                        : undefined
                                }
                            />
                        </ReviewCard>
                        <ReviewCard
                            icon={ListChecks}
                            title={`Response steps (${validSteps.length})`}
                            onEdit={() => setStep(1)}
                            span
                        >
                            {validSteps.map((s, i) => (
                                <ReviewRow
                                    key={i}
                                    label={`${i + 1}. ${stepTypes[s.type] ?? sentenceCase(s.type)}`}
                                    value={s.title}
                                />
                            ))}
                        </ReviewCard>
                    </div>
                </WizardStepPane>
            ) : null}
        </WizardShell>
    );
}
