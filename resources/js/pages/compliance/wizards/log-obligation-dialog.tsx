import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import {
    Field,
    InfoCard,
    Segmented,
    SelectInput,
    StepHead,
    SubHead,
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
import { useForm } from '@inertiajs/react';
import {
    Banknote,
    CalendarClock,
    Check,
    CheckCircle2,
    ChevronLeft,
    ChevronRight,
    ClipboardList,
    FileText,
    Gavel,
    HeartPulse,
    Loader2,
    Plus,
    Scale,
    ShieldCheck,
    ShieldPlus,
    Users,
} from 'lucide-react';
import { useMemo, useState } from 'react';
import { toast } from 'sonner';

type FrameworkOption = { value: string; label: string };
type OwnerOption = { id: number; name: string };

export type LogObligationForm = {
    _modal: boolean;
    framework: string;
    obligation_reference: string;
    title: string;
    description: string;
    requirements: string;
    frequency: string;
    due_date: string;
    priority: string;
    owner_id: string;
};

const STEPS: readonly WizardStep[] = [
    {
        key: 'obligation',
        label: 'Obligation',
        blurb: 'Framework & title',
        icon: ShieldCheck,
    },
    {
        key: 'details',
        label: 'Details',
        blurb: 'What it requires',
        icon: ClipboardList,
    },
    {
        key: 'schedule',
        label: 'Schedule',
        blurb: 'Cadence, owner & priority',
        icon: CalendarClock,
    },
    {
        key: 'review',
        label: 'Review & create',
        blurb: 'Confirm and save',
        icon: CheckCircle2,
    },
];

const FREQUENCIES = [
    { value: 'monthly', label: 'Monthly' },
    { value: 'quarterly', label: 'Quarterly' },
    { value: 'annual', label: 'Annual' },
    { value: 'ad_hoc', label: 'Ad hoc' },
    { value: 'event_driven', label: 'Event' },
];

const PRIORITIES = [
    { value: 'low', label: 'Low' },
    { value: 'medium', label: 'Medium' },
    { value: 'high', label: 'High' },
    { value: 'critical', label: 'Critical' },
];

// Framework → icon for the tile picker (purely cosmetic grouping).
function frameworkIcon(value: string) {
    if (value.startsWith('funding')) return Banknote;
    if (value === 'hswa' || value === 'hdsa_safety') return HeartPulse;
    if (value === 'privacy_act' || value === 'hip_code') return ShieldCheck;
    if (value === 'employment') return Users;
    if (value === 'charities') return Scale;
    return Gavel;
}

const STEP_FOR_FIELD: Record<string, number> = {
    framework: 0,
    obligation_reference: 0,
    title: 0,
    description: 1,
    requirements: 1,
    frequency: 2,
    due_date: 2,
    priority: 2,
    owner_id: 2,
};

function emptyForm(): LogObligationForm {
    return {
        _modal: true,
        framework: '',
        obligation_reference: '',
        title: '',
        description: '',
        requirements: '',
        frequency: 'annual',
        due_date: '',
        priority: 'medium',
        owner_id: '',
    };
}

export function LogObligationDialog({
    open,
    onClose,
    frameworks,
    owners,
}: {
    open: boolean;
    onClose: () => void;
    frameworks: FrameworkOption[];
    owners: OwnerOption[];
}) {
    const form = useForm<LogObligationForm>(emptyForm());
    const { data, setData, processing } = form;
    const [stepIndex, setStepIndex] = useState(0);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [done, setDone] = useState(false);

    const frameworkLabel = useMemo(
        () => frameworks.find((f) => f.value === data.framework)?.label ?? '',
        [frameworks, data.framework],
    );

    const set = <K extends keyof LogObligationForm>(
        k: K,
        v: LogObligationForm[K],
    ) =>
        // eslint-disable-next-line @typescript-eslint/no-explicit-any
        setData(k, v as any);

    const fieldErr = (name: string) =>
        errors[name] ?? (form.errors as Record<string, string>)[name];

    const validateStep = (idx: number): Record<string, string> => {
        const e: Record<string, string> = {};
        if (idx === 0) {
            if (!data.framework) e.framework = 'Choose a framework';
            if (!data.title.trim()) e.title = 'A title is required';
            if (data.obligation_reference.length > 50)
                e.obligation_reference =
                    'Reference must be 50 characters or fewer';
        }
        if (idx === 1) {
            if (!data.description.trim())
                e.description = 'Describe the obligation';
        }
        return e;
    };

    const goTo = (idx: number) =>
        setStepIndex(Math.max(0, Math.min(idx, STEPS.length - 1)));

    const next = () => {
        const e = validateStep(stepIndex);
        setErrors(e);
        if (Object.keys(e).length === 0) goTo(stepIndex + 1);
    };

    const reset = () => {
        form.reset();
        form.clearErrors();
        setData(emptyForm());
        setErrors({});
        setStepIndex(0);
        setDone(false);
    };

    const submit = (addAnother: boolean) => {
        const all: Record<string, string> = {};
        Object.assign(all, validateStep(0), validateStep(1));
        if (Object.keys(all).length) {
            setErrors(all);
            goTo(STEP_FOR_FIELD[Object.keys(all)[0]] ?? 0);
            return;
        }
        setErrors({});
        form.post('/governance/compliance', {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                toast.success('Compliance obligation logged');
                if (addAnother) reset();
                else setDone(true);
            },
            onError: (errs) => {
                const first = Object.keys(errs)[0];
                if (first) goTo(STEP_FOR_FIELD[first] ?? 0);
            },
        });
    };

    const cur = STEPS[stepIndex];
    const isReview = cur.key === 'review';

    if (done) {
        return (
            <WizardShell
                open={open}
                onClose={onClose}
                title="Log compliance obligation"
                description="Register a new governance compliance obligation."
                railIcon={ShieldPlus}
                railTitle="Log obligation"
                railSub="Governance register"
                steps={STEPS}
                stepIndex={STEPS.length - 1}
                onStepClick={() => {}}
                success={
                    <WizardSuccessPane
                        title="Obligation added to the register"
                        blurb={
                            <>
                                <strong>{data.title}</strong> ({frameworkLabel})
                                is now tracked. Reminders have been scheduled
                                for its owner.
                            </>
                        }
                        actions={
                            <>
                                <Button variant="outline" onClick={reset}>
                                    <Plus className="h-4 w-4" /> Add another
                                </Button>
                                <Button asChild>
                                    <a href="/governance/compliance">
                                        <ShieldCheck className="h-4 w-4" /> Open
                                        register
                                    </a>
                                </Button>
                            </>
                        }
                    />
                }
            />
        );
    }

    return (
        <WizardShell
            open={open}
            onClose={onClose}
            title="Log compliance obligation"
            description="Register a new governance compliance obligation."
            railIcon={ShieldPlus}
            railTitle="Log obligation"
            railSub="Governance register"
            steps={STEPS}
            stepIndex={stepIndex}
            onStepClick={goTo}
            footerStart={
                stepIndex > 0 ? (
                    <Button
                        type="button"
                        variant="ghost"
                        onClick={() => goTo(stepIndex - 1)}
                    >
                        <ChevronLeft className="h-4 w-4" /> Back
                    </Button>
                ) : null
            }
            footerEnd={
                <>
                    <Button type="button" variant="outline" onClick={onClose}>
                        Cancel
                    </Button>
                    {isReview ? (
                        <>
                            <Button
                                type="button"
                                variant="secondary"
                                onClick={() => submit(true)}
                                disabled={processing}
                            >
                                {processing ? (
                                    <Loader2 className="h-4 w-4 animate-spin" />
                                ) : (
                                    <Plus className="h-4 w-4" />
                                )}
                                Save & add another
                            </Button>
                            <Button
                                type="button"
                                onClick={() => submit(false)}
                                disabled={processing}
                            >
                                {processing ? (
                                    <>
                                        <Loader2 className="h-4 w-4 animate-spin" />{' '}
                                        Creating…
                                    </>
                                ) : (
                                    <>
                                        <Check className="h-4 w-4" /> Create
                                        obligation
                                    </>
                                )}
                            </Button>
                        </>
                    ) : (
                        <Button type="button" onClick={next}>
                            Continue <ChevronRight className="h-4 w-4" />
                        </Button>
                    )}
                </>
            }
        >
            {cur.key === 'obligation' ? (
                <WizardStepPane>
                    <StepHead
                        icon={ShieldCheck}
                        title="Which obligation?"
                        blurb="Pick the regulatory framework and name the obligation you're tracking."
                    />
                    <div className="grid gap-4">
                        <Field
                            label="Framework"
                            required
                            error={fieldErr('framework')}
                        >
                            <TilePicker
                                value={data.framework}
                                onChange={(v) => set('framework', v)}
                                cols={2}
                                options={frameworks.map((f) => ({
                                    key: f.value,
                                    label: f.label,
                                    icon: frameworkIcon(f.value),
                                }))}
                            />
                        </Field>
                        <div className="grid gap-4 sm:grid-cols-2">
                            <Field
                                label="Title"
                                required
                                error={fieldErr('title')}
                                span
                            >
                                <Input
                                    value={data.title}
                                    onChange={(e) =>
                                        set('title', e.target.value)
                                    }
                                    placeholder="e.g. Annual Ngā Paerewa self-assessment"
                                    aria-invalid={!!fieldErr('title')}
                                />
                            </Field>
                            <Field
                                label="Reference"
                                hint="clause / code (optional)"
                                error={fieldErr('obligation_reference')}
                            >
                                <Input
                                    value={data.obligation_reference}
                                    onChange={(e) =>
                                        set(
                                            'obligation_reference',
                                            e.target.value.slice(0, 50),
                                        )
                                    }
                                    placeholder="e.g. NP-4.1.1"
                                />
                            </Field>
                        </div>
                    </div>
                </WizardStepPane>
            ) : null}

            {cur.key === 'details' ? (
                <WizardStepPane>
                    <StepHead
                        icon={ClipboardList}
                        title="What does it require?"
                        blurb="Describe the obligation and the evidence needed to satisfy it."
                    />
                    <div className="grid gap-4">
                        <Field
                            label="Description"
                            required
                            error={fieldErr('description')}
                        >
                            <Textarea
                                rows={3}
                                value={data.description}
                                onChange={(e) =>
                                    set('description', e.target.value)
                                }
                                placeholder="What this obligation covers and why it matters."
                                aria-invalid={!!fieldErr('description')}
                            />
                        </Field>
                        <Field
                            label="Requirements"
                            hint="what must be done / submitted (optional)"
                            error={fieldErr('requirements')}
                        >
                            <Textarea
                                rows={3}
                                value={data.requirements}
                                onChange={(e) =>
                                    set('requirements', e.target.value)
                                }
                                placeholder="e.g. Board-approved self-assessment uploaded as evidence each year."
                            />
                        </Field>
                        <InfoCard icon={FileText}>
                            Evidence (documents, audit reports, attestations) is
                            attached after creation via{' '}
                            <strong>Record evidence</strong> — each obligation
                            keeps its own evidence trail for audits.
                        </InfoCard>
                    </div>
                </WizardStepPane>
            ) : null}

            {cur.key === 'schedule' ? (
                <WizardStepPane>
                    <StepHead
                        icon={CalendarClock}
                        title="Cadence, owner & priority"
                        blurb="When it's due, who owns it, and how much it matters."
                    />
                    <div className="grid gap-4">
                        <div className="grid gap-4 sm:grid-cols-2">
                            <SubHead icon={CalendarClock}>Schedule</SubHead>
                            <Field label="Frequency" span>
                                <Segmented
                                    value={data.frequency}
                                    onChange={(v) => set('frequency', v)}
                                    options={FREQUENCIES}
                                />
                            </Field>
                            <Field
                                label="Next due date"
                                error={fieldErr('due_date')}
                            >
                                <Input
                                    type="date"
                                    value={data.due_date}
                                    onChange={(e) =>
                                        set('due_date', e.target.value)
                                    }
                                />
                            </Field>
                            <Field label="Priority">
                                <Segmented
                                    value={data.priority}
                                    onChange={(v) => set('priority', v)}
                                    options={PRIORITIES}
                                />
                            </Field>
                        </div>
                        <div className="grid gap-4 sm:grid-cols-2">
                            <SubHead icon={Users}>Ownership</SubHead>
                            <Field
                                label="Owner"
                                span
                                hint="accountable person — defaults to you"
                                error={fieldErr('owner_id')}
                            >
                                <SelectInput
                                    value={data.owner_id}
                                    onChange={(v) => set('owner_id', v)}
                                    placeholder="Assign an owner"
                                    options={owners.map((o) => ({
                                        value: String(o.id),
                                        label: o.name,
                                    }))}
                                />
                            </Field>
                        </div>
                    </div>
                </WizardStepPane>
            ) : null}

            {isReview ? (
                <WizardStepPane>
                    <StepHead
                        icon={CheckCircle2}
                        title="Review & create"
                        blurb="Confirm the obligation before adding it to the register."
                    />
                    <div className="grid gap-3 sm:grid-cols-2">
                        <ReviewCard
                            icon={ShieldCheck}
                            title="Obligation"
                            onEdit={() => goTo(0)}
                        >
                            <ReviewRow
                                label="Framework"
                                value={frameworkLabel}
                            />
                            <ReviewRow label="Title" value={data.title} />
                            <ReviewRow
                                label="Reference"
                                value={data.obligation_reference}
                            />
                        </ReviewCard>
                        <ReviewCard
                            icon={CalendarClock}
                            title="Schedule"
                            onEdit={() => goTo(2)}
                        >
                            <ReviewRow
                                label="Frequency"
                                value={
                                    FREQUENCIES.find(
                                        (f) => f.value === data.frequency,
                                    )?.label
                                }
                            />
                            <ReviewRow label="Due" value={data.due_date} />
                            <ReviewRow
                                label="Priority"
                                value={
                                    PRIORITIES.find(
                                        (p) => p.value === data.priority,
                                    )?.label
                                }
                            />
                            <ReviewRow
                                label="Owner"
                                value={
                                    owners.find(
                                        (o) => String(o.id) === data.owner_id,
                                    )?.name ?? 'You'
                                }
                            />
                        </ReviewCard>
                        <ReviewCard
                            icon={ClipboardList}
                            title="Details"
                            span
                            onEdit={() => goTo(1)}
                        >
                            <ReviewRow
                                label="Description"
                                value={data.description}
                            />
                            <ReviewRow
                                label="Requirements"
                                value={data.requirements}
                            />
                        </ReviewCard>
                    </div>
                </WizardStepPane>
            ) : null}
        </WizardShell>
    );
}

export default LogObligationDialog;
