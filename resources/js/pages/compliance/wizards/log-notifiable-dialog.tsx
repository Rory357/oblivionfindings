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
    AlertOctagon,
    Bell,
    Building2,
    Check,
    CheckCircle2,
    ChevronLeft,
    ChevronRight,
    HeartCrack,
    Loader2,
    Plus,
    Scale,
    ShieldAlert,
    Siren,
    Stethoscope,
} from 'lucide-react';
import { useMemo, useState } from 'react';
import { toast } from 'sonner';

type IncidentOption = { id: number; label: string };

const INCIDENT_TYPES = [
    { key: 'death', label: 'Death', icon: HeartCrack },
    { key: 'serious_harm', label: 'Serious harm', icon: ShieldAlert },
    { key: 'serious_injury', label: 'Serious injury', icon: AlertOctagon },
    { key: 'health_safety', label: 'Health & safety', icon: Siren },
    { key: 'privacy_breach', label: 'Privacy breach', icon: Scale },
];

const AUTHORITIES = [
    {
        key: 'worksafe',
        label: 'WorkSafe NZ',
        description: 'HSWA 2015 notifiable event',
        icon: Siren,
    },
    {
        key: 'health_nz',
        label: 'Health NZ',
        description: 'Te Whatu Ora / sector',
        icon: Stethoscope,
    },
    {
        key: 'privacy_commissioner',
        label: 'Privacy Commissioner',
        description: 'Privacy Act 2020 breach',
        icon: Scale,
    },
    {
        key: 'charities_services',
        label: 'Charities Services',
        description: 'Serious wrongdoing',
        icon: Building2,
    },
];

const SEVERITIES = [
    { value: 'critical', label: 'Critical' },
    { value: 'high', label: 'High' },
    { value: 'medium', label: 'Medium' },
];

export type LogNotifiableForm = {
    _modal: boolean;
    incident_type: string;
    notification_authority: string;
    title: string;
    description: string;
    severity: string;
    occurred_at: string;
    discovered_at: string;
    related_incident_id: string;
};

const STEPS: readonly WizardStep[] = [
    {
        key: 'event',
        label: 'Event',
        blurb: 'What happened & when',
        icon: AlertOctagon,
    },
    {
        key: 'notify',
        label: 'Notification',
        blurb: 'Authority & detail',
        icon: Bell,
    },
    {
        key: 'review',
        label: 'Review & log',
        blurb: 'Confirm and record',
        icon: CheckCircle2,
    },
];

const STEP_FOR_FIELD: Record<string, number> = {
    incident_type: 0,
    severity: 0,
    occurred_at: 0,
    discovered_at: 0,
    notification_authority: 1,
    title: 1,
    description: 1,
    related_incident_id: 1,
};

function emptyForm(): LogNotifiableForm {
    return {
        _modal: true,
        incident_type: '',
        notification_authority: '',
        title: '',
        description: '',
        severity: 'high',
        occurred_at: '',
        discovered_at: '',
        related_incident_id: '',
    };
}

export function LogNotifiableDialog({
    open,
    onClose,
    relatedIncidents,
}: {
    open: boolean;
    onClose: () => void;
    relatedIncidents: IncidentOption[];
}) {
    const form = useForm<LogNotifiableForm>(emptyForm());
    const { data, setData, processing } = form;
    const [stepIndex, setStepIndex] = useState(0);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [done, setDone] = useState(false);

    const set = <K extends keyof LogNotifiableForm>(
        k: K,
        v: LogNotifiableForm[K],
    ) =>
        // eslint-disable-next-line @typescript-eslint/no-explicit-any
        setData(k, v as any);
    const fieldErr = (name: string) =>
        errors[name] ?? (form.errors as Record<string, string>)[name];

    const authorityLabel = useMemo(
        () =>
            AUTHORITIES.find((a) => a.key === data.notification_authority)
                ?.label ?? '',
        [data.notification_authority],
    );

    const validateStep = (idx: number): Record<string, string> => {
        const e: Record<string, string> = {};
        if (idx === 0) {
            if (!data.incident_type) e.incident_type = 'Choose what happened';
            if (!data.severity) e.severity = 'Choose a severity';
            if (!data.occurred_at) e.occurred_at = 'When did it occur?';
        }
        if (idx === 1) {
            if (!data.notification_authority)
                e.notification_authority = 'Choose the authority';
            if (!data.title.trim()) e.title = 'A short title is required';
            if (!data.description.trim()) e.description = 'Describe the event';
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
        const all = { ...validateStep(0), ...validateStep(1) };
        if (Object.keys(all).length) {
            setErrors(all);
            goTo(STEP_FOR_FIELD[Object.keys(all)[0]] ?? 0);
            return;
        }
        setErrors({});
        form.post('/governance/compliance/notifiable-incident', {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                toast.success('Notifiable incident recorded');
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
                title="Log notifiable incident"
                description="Record a regulator-notifiable incident."
                railIcon={Siren}
                railTitle="Notifiable incident"
                railSub="Regulatory notification"
                steps={STEPS}
                stepIndex={STEPS.length - 1}
                onStepClick={() => {}}
                success={
                    <WizardSuccessPane
                        title="Notifiable incident recorded"
                        blurb={
                            <>
                                Recorded for notification to{' '}
                                <strong>{authorityLabel}</strong>. Ensure the
                                regulator is notified within the statutory
                                timeframe.
                            </>
                        }
                        actions={
                            <>
                                <Button variant="outline" onClick={reset}>
                                    <Plus className="h-4 w-4" /> Log another
                                </Button>
                                <Button asChild>
                                    <a href="/governance/compliance">
                                        <Bell className="h-4 w-4" /> Open
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
            title="Log notifiable incident"
            description="Record a regulator-notifiable incident."
            railIcon={Siren}
            railTitle="Notifiable incident"
            railSub="Regulatory notification"
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
                                        Recording…
                                    </>
                                ) : (
                                    <>
                                        <Check className="h-4 w-4" /> Record
                                        incident
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
            {cur.key === 'event' ? (
                <WizardStepPane>
                    <StepHead
                        icon={AlertOctagon}
                        title="What happened?"
                        blurb="Classify the event and capture when it occurred and was discovered."
                    />
                    <div className="grid gap-4">
                        <Field
                            label="Incident type"
                            required
                            error={fieldErr('incident_type')}
                        >
                            <TilePicker
                                value={data.incident_type}
                                onChange={(v) => set('incident_type', v)}
                                cols={3}
                                options={INCIDENT_TYPES}
                            />
                        </Field>
                        <div className="grid gap-4 sm:grid-cols-3">
                            <Field
                                label="Severity"
                                required
                                error={fieldErr('severity')}
                                span
                            >
                                <Segmented
                                    value={data.severity}
                                    onChange={(v) => set('severity', v)}
                                    options={SEVERITIES}
                                />
                            </Field>
                            <Field
                                label="Occurred"
                                required
                                error={fieldErr('occurred_at')}
                            >
                                <Input
                                    type="date"
                                    value={data.occurred_at}
                                    onChange={(e) =>
                                        set('occurred_at', e.target.value)
                                    }
                                    aria-invalid={!!fieldErr('occurred_at')}
                                />
                            </Field>
                            <Field
                                label="Discovered"
                                hint="if different"
                                error={fieldErr('discovered_at')}
                            >
                                <Input
                                    type="date"
                                    value={data.discovered_at}
                                    onChange={(e) =>
                                        set('discovered_at', e.target.value)
                                    }
                                />
                            </Field>
                        </div>
                    </div>
                </WizardStepPane>
            ) : null}

            {cur.key === 'notify' ? (
                <WizardStepPane>
                    <StepHead
                        icon={Bell}
                        title="Who must be notified?"
                        blurb="Pick the regulator and summarise the event for the notification record."
                    />
                    <div className="grid gap-4">
                        <Field
                            label="Notification authority"
                            required
                            error={fieldErr('notification_authority')}
                        >
                            <TilePicker
                                value={data.notification_authority}
                                onChange={(v) =>
                                    set('notification_authority', v)
                                }
                                cols={2}
                                options={AUTHORITIES}
                            />
                        </Field>
                        <Field label="Title" required error={fieldErr('title')}>
                            <Input
                                value={data.title}
                                onChange={(e) => set('title', e.target.value)}
                                placeholder="Short summary of the event"
                                aria-invalid={!!fieldErr('title')}
                            />
                        </Field>
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
                                placeholder="What happened, who was involved, immediate actions taken."
                                aria-invalid={!!fieldErr('description')}
                            />
                        </Field>
                        {relatedIncidents.length > 0 ? (
                            <div className="grid gap-4 sm:grid-cols-2">
                                <SubHead icon={ShieldAlert}>Linkage</SubHead>
                                <Field
                                    label="Related incident"
                                    span
                                    hint="link to an existing incident (optional)"
                                >
                                    <SelectInput
                                        value={data.related_incident_id}
                                        onChange={(v) =>
                                            set('related_incident_id', v)
                                        }
                                        placeholder="Link an incident"
                                        options={relatedIncidents.map((i) => ({
                                            value: String(i.id),
                                            label: i.label,
                                        }))}
                                    />
                                </Field>
                            </div>
                        ) : null}
                        <InfoCard icon={Siren} tone="warn">
                            Recording here does <strong>not</strong> notify the
                            regulator — it logs the obligation. Notify{' '}
                            {authorityLabel || 'the authority'} directly within
                            the statutory timeframe.
                        </InfoCard>
                    </div>
                </WizardStepPane>
            ) : null}

            {isReview ? (
                <WizardStepPane>
                    <StepHead
                        icon={CheckCircle2}
                        title="Review & log"
                        blurb="Confirm the notifiable incident record."
                    />
                    <div className="grid gap-3 sm:grid-cols-2">
                        <ReviewCard
                            icon={AlertOctagon}
                            title="Event"
                            onEdit={() => goTo(0)}
                        >
                            <ReviewRow
                                label="Type"
                                value={
                                    INCIDENT_TYPES.find(
                                        (t) => t.key === data.incident_type,
                                    )?.label
                                }
                            />
                            <ReviewRow
                                label="Severity"
                                value={
                                    SEVERITIES.find(
                                        (s) => s.value === data.severity,
                                    )?.label
                                }
                            />
                            <ReviewRow
                                label="Occurred"
                                value={data.occurred_at}
                            />
                            <ReviewRow
                                label="Discovered"
                                value={data.discovered_at}
                            />
                        </ReviewCard>
                        <ReviewCard
                            icon={Bell}
                            title="Notification"
                            onEdit={() => goTo(1)}
                        >
                            <ReviewRow
                                label="Authority"
                                value={authorityLabel}
                            />
                            <ReviewRow label="Title" value={data.title} />
                            <ReviewRow
                                label="Linked incident"
                                value={
                                    relatedIncidents.find(
                                        (i) =>
                                            String(i.id) ===
                                            data.related_incident_id,
                                    )?.label
                                }
                            />
                        </ReviewCard>
                        <ReviewCard
                            icon={ShieldAlert}
                            title="Description"
                            span
                            onEdit={() => goTo(1)}
                        >
                            <ReviewRow
                                label="Detail"
                                value={data.description}
                            />
                        </ReviewCard>
                    </div>
                </WizardStepPane>
            ) : null}
        </WizardShell>
    );
}

export default LogNotifiableDialog;
