import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import WizardStepper, { type WizardStep } from '@/components/wizard-stepper';
import { useFormAutosave } from '@/hooks/use-form-autosave';
import AppLayout from '@/layouts/app-layout';
import { formatDateTime, formatTime } from '@/lib/datetime';
import { Head, Link, router, usePage } from '@inertiajs/react';
import {
    AlertTriangle,
    ArrowLeft,
    ArrowRight,
    Check,
    Loader2,
} from 'lucide-react';
import { useCallback, useEffect, useMemo, useState } from 'react';
import StepDescribe, { type StepTwoData } from './wizard/step-describe';
import StepOptionalDetail, {
    type StepThreeData,
} from './wizard/step-optional-detail';
import StepWhoWhat, {
    type IncidentSeverity,
    type IncidentType,
    type StepOneData,
} from './wizard/step-who-what';

type Client = { id: number; first_name: string; last_name: string };

type ResumeIncident = {
    id: number;
    client_id: number;
    type: string;
    severity: string;
    occurred_at: string | null;
    description: string | null;
    immediate_action_taken: string | null;
    witnesses: string | null;
    injured_person_name: string | null;
    injured_person_role: string | null;
    injury_body_part: string | null;
    injury_nature: string | null;
    medical_treatment_type: string | null;
};

type Props = {
    clients: Client[];
    templates: unknown[];
    resumeIncident?: ResumeIncident | null;
};

type WizardData = StepOneData & StepTwoData & StepThreeData;

const DEFAULT_DATA: WizardData = {
    client_id: '',
    type: 'injury',
    severity: 'low',
    occurred_at: '',
    description: '',
    immediate_action_taken: '',
    witnesses: '',
    injured_person_name: '',
    injured_person_role: '',
    injury_body_part: '',
    injury_nature: '',
    medical_treatment_type: '',
};

const STEPS: WizardStep[] = [
    { key: 'who-what', label: 'Who & what' },
    { key: 'describe', label: 'Describe' },
    { key: 'detail', label: 'Detail (optional)' },
];

const TYPE_LABELS: Record<IncidentType, string> = {
    injury: 'Injury',
    behaviour: 'Behaviour',
    medication: 'Medication',
    safeguarding: 'Safeguarding',
    near_miss: 'Near miss',
    other: 'Other',
};

const SEVERITY_PREVIEW: Record<
    IncidentSeverity,
    { label: string; className: string; dotClassName: string }
> = {
    low: {
        label: 'Low',
        className:
            'border-status-success/40 bg-status-success-bg text-foreground',
        dotClassName: 'bg-status-success',
    },
    medium: {
        label: 'Medium',
        className:
            'border-status-warning/40 bg-status-warning-bg text-foreground',
        dotClassName: 'bg-status-warning',
    },
    high: {
        label: 'High',
        className:
            'border-status-critical/40 bg-status-critical-bg text-foreground',
        dotClassName: 'bg-status-critical',
    },
};

const WORKFLOW_HELP = [
    'Draft saved now',
    'Submit when ready',
    'Manager review',
    'Close after follow-up',
];

export default function IncidentCreate({ clients, resumeIncident }: Props) {
    const page = usePage().props as {
        auth?: { user?: { id?: number } };
        labels?: Record<string, string>;
    };
    const userId = page.auth?.user?.id ?? 0;
    const clientSingular = page.labels?.['client.singular'] ?? 'Client';

    const draftKey = `oblivion:incident-draft:v1:u${userId}`;

    const [data, setData] = useState<WizardData>(DEFAULT_DATA);
    const [step, setStep] = useState(0);
    const [incidentId, setIncidentId] = useState<number | null>(null);
    const [errors, setErrors] = useState<
        Partial<Record<keyof WizardData, string>>
    >({});
    const [processing, setProcessing] = useState(false);
    const [resumePrompt, setResumePrompt] = useState<{
        data: WizardData;
        incidentId: number | null;
    } | null>(null);
    const [bootstrapped, setBootstrapped] = useState(false);

    const { savedAt, load, clear, flush } = useFormAutosave<WizardData>(
        data,
        { step, incidentId },
        { key: draftKey, enabled: bootstrapped },
    );

    // Pick up the persisted draft whenever the server redirects back with an
    // incident id. Inertia keeps this page mounted, so we need to react to
    // prop changes instead of only bootstrapping on first render.
    useEffect(() => {
        if (!resumeIncident) {
            return;
        }

        setData({
            client_id: String(resumeIncident.client_id ?? ''),
            type: (resumeIncident.type as IncidentType) || 'injury',
            severity: (resumeIncident.severity as IncidentSeverity) || 'low',
            occurred_at: resumeIncident.occurred_at
                ? toLocalInput(resumeIncident.occurred_at)
                : '',
            description: resumeIncident.description ?? '',
            immediate_action_taken: resumeIncident.immediate_action_taken ?? '',
            witnesses: resumeIncident.witnesses ?? '',
            injured_person_name: resumeIncident.injured_person_name ?? '',
            injured_person_role: resumeIncident.injured_person_role ?? '',
            injury_body_part: resumeIncident.injury_body_part ?? '',
            injury_nature: resumeIncident.injury_nature ?? '',
            medical_treatment_type: resumeIncident.medical_treatment_type ?? '',
        });
        setIncidentId(resumeIncident.id);
        setStep(2);
        setResumePrompt(null);
        setBootstrapped(true);
    }, [resumeIncident]);

    // Bootstrap: prompt for a local draft when there is no server-side draft to resume.
    useEffect(() => {
        if (resumeIncident) {
            return;
        }

        const existing = load();
        if (
            existing &&
            (existing.data.description?.trim() ||
                existing.data.client_id ||
                (existing.meta as { step?: number })?.step)
        ) {
            setResumePrompt({
                data: { ...DEFAULT_DATA, ...existing.data },
                incidentId:
                    (existing.meta as { incidentId?: number | null })
                        ?.incidentId ?? null,
            });
        } else {
            setBootstrapped(true);
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    const patch = useCallback((p: Partial<WizardData>) => {
        setData((prev) => ({ ...prev, ...p }));
    }, []);

    const stepTwoValid = useMemo(
        () => (data.description ?? '').trim().length >= 3,
        [data.description],
    );
    const selectedClient = useMemo(
        () => clients.find((client) => String(client.id) === data.client_id),
        [clients, data.client_id],
    );
    const severityPreview = SEVERITY_PREVIEW[data.severity];
    const occurredAtPreview = data.occurred_at
        ? formatDateTime(data.occurred_at)
        : 'Now';

    const goNext = () => {
        const e: typeof errors = {};
        if (step === 0) {
            if (!data.client_id)
                e.client_id = `Please choose a ${clientSingular.toLowerCase()}.`;
            setErrors(e);
            if (Object.keys(e).length > 0) return;
            flush();
            setStep(1);
        } else if (step === 1) {
            if (!stepTwoValid) {
                setErrors({
                    description:
                        'Write a short description so the record is useful.',
                });
                return;
            }
            setErrors({});
            submitStepTwo();
        }
    };

    const goBack = () => {
        setErrors({});
        if (step === 0) return;
        setStep((s) => Math.max(0, s - 1));
    };

    const submitStepTwo = () => {
        if (incidentId) {
            // Already created — just advance.
            setStep(2);
            return;
        }
        setProcessing(true);
        router.post(
            '/incidents',
            {
                client_id: data.client_id,
                type: data.type,
                severity: data.severity,
                occurred_at: data.occurred_at || null,
                description: data.description,
                continue_wizard: true,
            },
            {
                preserveScroll: true,
                onError: (errs) => {
                    setErrors(errs as typeof errors);
                },
                onFinish: () => setProcessing(false),
            },
        );
    };

    const submitStepThree = (mode: 'submit' | 'exit') => {
        if (!incidentId) return;
        setProcessing(true);
        router.put(
            `/incidents/${incidentId}`,
            {
                type: data.type,
                severity: data.severity,
                occurred_at: data.occurred_at || null,
                description: data.description,
                immediate_action_taken: data.immediate_action_taken,
                witnesses: data.witnesses,
                injured_person_name: data.injured_person_name,
                injured_person_role: data.injured_person_role,
                injury_body_part: data.injury_body_part,
                injury_nature: data.injury_nature,
                medical_treatment_type: data.medical_treatment_type,
            },
            {
                preserveScroll: true,
                onSuccess: () => {
                    clear();
                    if (mode === 'submit' || mode === 'exit') {
                        router.visit(`/incidents/${incidentId}`);
                    }
                },
                onFinish: () => setProcessing(false),
            },
        );
    };

    const skipToIncident = () => {
        if (!incidentId) return;
        clear();
        router.visit(`/incidents/${incidentId}`);
    };

    const discardDraft = () => {
        clear();
        setResumePrompt(null);
        setBootstrapped(true);
    };

    const resumeDraft = () => {
        if (!resumePrompt) return;
        setData(resumePrompt.data);
        setIncidentId(resumePrompt.incidentId);
        // If a server-side incident was created, pick up at Step 3; otherwise resume at Step 2.
        setStep(resumePrompt.incidentId ? 2 : 1);
        setResumePrompt(null);
        setBootstrapped(true);
    };

    if (resumePrompt) {
        return (
            <AppLayout
                breadcrumbs={[
                    { title: 'Incidents', href: '/incidents' },
                    { title: 'New', href: '/incidents/create' },
                ]}
            >
                <Head title="Resume draft incident" />
                <div
                    data-test="incident-wizard-resume-prompt"
                    className="mx-auto flex max-w-md flex-col gap-4 px-4 py-10"
                >
                    <div className="flex items-center gap-3">
                        <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-status-warning-bg text-status-warning">
                            <AlertTriangle className="h-5 w-5" />
                        </div>
                        <div>
                            <h1 className="text-lg font-semibold">
                                Resume your draft incident?
                            </h1>
                            <p className="text-sm text-muted-foreground">
                                We found an unfinished incident on this device.
                            </p>
                        </div>
                    </div>
                    <div className="flex flex-col gap-2 sm:flex-row sm:justify-end">
                        <Button variant="outline" onClick={discardDraft}>
                            Discard
                        </Button>
                        <Button onClick={resumeDraft}>Continue draft</Button>
                    </div>
                </div>
            </AppLayout>
        );
    }

    const showInjuryFields = data.type === 'injury';

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Incidents', href: '/incidents' },
                { title: 'New', href: '/incidents/create' },
            ]}
        >
            <Head title="Report incident" />
            <div
                data-test="incident-wizard-root"
                className="mx-auto w-full max-w-2xl space-y-6 px-4 pt-4 pb-[calc(7rem+env(safe-area-inset-bottom,0px))] lg:max-w-5xl lg:pb-8"
            >
                <div className="flex items-start justify-between gap-3">
                    <div>
                        <h1 className="text-xl font-semibold tracking-tight">
                            Report an incident
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            Three quick steps. You can add more detail later.
                        </p>
                    </div>
                    <Link
                        href="/incidents"
                        data-test="incident-wizard-exit"
                        aria-label="Cancel incident report and return to incidents list"
                        className="frontline-focus inline-flex min-h-11 shrink-0 items-center rounded-md border px-4 py-2 text-sm font-medium hover:bg-muted lg:min-h-10"
                    >
                        <span className="lg:hidden">Exit</span>
                        <span className="hidden lg:inline">Cancel</span>
                    </Link>
                </div>

                <div className="lg:grid lg:grid-cols-[minmax(0,1fr)_320px] lg:gap-8">
                    <div className="space-y-6 lg:space-y-8">
                        <WizardStepper steps={STEPS} current={step} />

                        <Card className="p-4 sm:p-6 lg:p-8">
                            <div data-test={`incident-wizard-step-${step}`}>
                                {step === 0 && (
                                    <StepWhoWhat
                                        data={data}
                                        onChange={patch}
                                        clients={clients}
                                        clientLabel={clientSingular}
                                        errors={errors}
                                    />
                                )}
                                {step === 1 && (
                                    <StepDescribe
                                        data={data}
                                        onChange={patch}
                                        errors={errors}
                                    />
                                )}
                                {step === 2 && (
                                    <StepOptionalDetail
                                        data={data}
                                        onChange={patch}
                                        showInjuryFields={showInjuryFields}
                                    />
                                )}
                            </div>
                        </Card>

                        {savedAt && (
                            <p className="text-xs text-muted-foreground lg:hidden">
                                Draft saved on this device ·{' '}
                                {formatTime(savedAt)}
                            </p>
                        )}

                        {step < 2 ? (
                            <div
                                data-test="incident-wizard-actions"
                                className="fixed inset-x-0 bottom-0 z-20 flex items-center gap-2 border-t bg-background/95 px-3 pt-3 pb-[max(env(safe-area-inset-bottom,0px),0.75rem)] backdrop-blur lg:static lg:border-0 lg:bg-transparent lg:p-0"
                            >
                                {step > 0 && (
                                    <Button
                                        data-test="incident-wizard-back"
                                        variant="outline"
                                        size="lg"
                                        onClick={goBack}
                                        className="flex-1 lg:flex-none"
                                    >
                                        <ArrowLeft className="mr-1.5 h-4 w-4" />
                                        Back
                                    </Button>
                                )}
                                <Button
                                    data-test="incident-wizard-next"
                                    size="lg"
                                    onClick={goNext}
                                    disabled={
                                        processing ||
                                        (step === 1 && !stepTwoValid)
                                    }
                                    className="flex-1 lg:min-w-[180px] lg:flex-none"
                                >
                                    {processing ? (
                                        <>
                                            <Loader2 className="mr-1.5 h-4 w-4 animate-spin" />
                                            Saving…
                                        </>
                                    ) : step === 0 ? (
                                        <>
                                            Next
                                            <ArrowRight className="ml-1.5 h-4 w-4" />
                                        </>
                                    ) : (
                                        <>
                                            Save and continue
                                            <ArrowRight className="ml-1.5 h-4 w-4" />
                                        </>
                                    )}
                                </Button>
                            </div>
                        ) : (
                            <div
                                data-test="incident-wizard-actions"
                                className="fixed inset-x-0 bottom-0 z-20 flex flex-col gap-2 border-t bg-background/95 px-3 pt-3 pb-[max(env(safe-area-inset-bottom,0px),0.75rem)] backdrop-blur lg:static lg:flex-row lg:items-center lg:justify-between lg:border-0 lg:bg-transparent lg:p-0"
                            >
                                <Button
                                    data-test="incident-wizard-skip"
                                    variant="outline"
                                    size="lg"
                                    onClick={skipToIncident}
                                    disabled={processing}
                                    className="w-full lg:w-auto"
                                >
                                    Skip extra detail
                                </Button>
                                <Button
                                    data-test="incident-wizard-finish"
                                    size="lg"
                                    onClick={() => submitStepThree('submit')}
                                    disabled={processing}
                                    className="w-full lg:w-auto lg:min-w-[180px]"
                                >
                                    {processing ? (
                                        <>
                                            <Loader2 className="mr-1.5 h-4 w-4 animate-spin" />
                                            Saving…
                                        </>
                                    ) : (
                                        <>
                                            <Check className="mr-1.5 h-4 w-4" />
                                            Save and finish
                                        </>
                                    )}
                                </Button>
                            </div>
                        )}
                    </div>

                    <aside
                        data-test="incident-wizard-summary"
                        aria-label="Incident report summary"
                        className="hidden lg:block"
                    >
                        <Card className="sticky top-4 space-y-6 p-5">
                            <div className="space-y-1">
                                <p className="text-xs font-medium text-muted-foreground">
                                    Step {step + 1} of {STEPS.length}
                                </p>
                                <h2 className="text-base font-semibold">
                                    Report summary
                                </h2>
                            </div>

                            <dl className="space-y-4 text-sm">
                                <div>
                                    <dt className="text-xs font-medium text-muted-foreground">
                                        {clientSingular}
                                    </dt>
                                    <dd className="mt-1 font-medium">
                                        {selectedClient
                                            ? `${selectedClient.first_name} ${selectedClient.last_name}`
                                            : `Choose a ${clientSingular.toLowerCase()}`}
                                    </dd>
                                </div>
                                <div>
                                    <dt className="text-xs font-medium text-muted-foreground">
                                        Incident type
                                    </dt>
                                    <dd className="mt-1 font-medium">
                                        {TYPE_LABELS[data.type]}
                                    </dd>
                                </div>
                                <div>
                                    <dt className="text-xs font-medium text-muted-foreground">
                                        Severity
                                    </dt>
                                    <dd className="mt-1">
                                        <span
                                            className={`inline-flex items-center gap-2 rounded-full border px-2.5 py-1 text-xs font-semibold ${severityPreview.className}`}
                                        >
                                            <span
                                                aria-hidden
                                                className={`h-2 w-2 rounded-full ${severityPreview.dotClassName}`}
                                            />
                                            {severityPreview.label}
                                        </span>
                                    </dd>
                                </div>
                                <div>
                                    <dt className="text-xs font-medium text-muted-foreground">
                                        Occurred
                                    </dt>
                                    <dd className="mt-1 font-medium">
                                        {occurredAtPreview}
                                    </dd>
                                </div>
                            </dl>

                            {savedAt && (
                                <div className="rounded-md border bg-muted/30 p-3">
                                    <p className="text-xs font-medium text-foreground">
                                        Saved on this device
                                    </p>
                                    <p className="mt-1 text-xs text-muted-foreground">
                                        Draft saved at {formatTime(savedAt)}
                                    </p>
                                </div>
                            )}

                            <div className="space-y-3">
                                <h3 className="text-sm font-semibold">
                                    What happens next
                                </h3>
                                <ol className="space-y-2">
                                    {WORKFLOW_HELP.map((item, index) => (
                                        <li
                                            key={item}
                                            className="flex items-center gap-2 text-sm text-muted-foreground"
                                        >
                                            <span className="flex h-6 w-6 shrink-0 items-center justify-center rounded-full border bg-background text-xs font-semibold text-foreground">
                                                {index + 1}
                                            </span>
                                            <span>{item}</span>
                                        </li>
                                    ))}
                                </ol>
                            </div>
                        </Card>
                    </aside>
                </div>
            </div>
        </AppLayout>
    );
}

function toLocalInput(value: string): string {
    // Backend returns ISO; datetime-local needs YYYY-MM-DDTHH:mm in local time.
    const d = new Date(value);
    if (Number.isNaN(d.getTime())) return '';
    const pad = (n: number) => n.toString().padStart(2, '0');
    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
}
