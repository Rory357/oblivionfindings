import WizardStepper, { type WizardStep } from '@/components/wizard-stepper';
import { Button } from '@/components/ui/button';
import { useFormAutosave } from '@/hooks/use-form-autosave';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { AlertTriangle, ArrowLeft, ArrowRight, Check, Loader2 } from 'lucide-react';
import { useCallback, useEffect, useMemo, useState } from 'react';
import StepDescribe, { type StepTwoData } from './wizard/step-describe';
import StepOptionalDetail, { type StepThreeData } from './wizard/step-optional-detail';
import StepWhoWhat, { type IncidentSeverity, type IncidentType, type StepOneData } from './wizard/step-who-what';

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

export default function IncidentCreate({ clients, resumeIncident }: Props) {
    const page = usePage().props as { auth?: { user?: { id?: number } }; labels?: Record<string, string> };
    const userId = page.auth?.user?.id ?? 0;
    const clientSingular = page.labels?.['client.singular'] ?? 'Client';

    const draftKey = `oblivion:incident-draft:v1:u${userId}`;

    const [data, setData] = useState<WizardData>(DEFAULT_DATA);
    const [step, setStep] = useState(0);
    const [incidentId, setIncidentId] = useState<number | null>(null);
    const [errors, setErrors] = useState<Partial<Record<keyof WizardData, string>>>({});
    const [processing, setProcessing] = useState(false);
    const [resumePrompt, setResumePrompt] = useState<{ data: WizardData; incidentId: number | null } | null>(null);
    const [bootstrapped, setBootstrapped] = useState(false);

    const { savedAt, load, clear, flush } = useFormAutosave<WizardData>(
        data,
        { step, incidentId },
        { key: draftKey, enabled: bootstrapped },
    );

    // Bootstrap: resume from backend (after Step 2 round-trip) OR prompt for local draft.
    useEffect(() => {
        if (resumeIncident) {
            setData({
                client_id: String(resumeIncident.client_id ?? ''),
                type: (resumeIncident.type as IncidentType) || 'injury',
                severity: (resumeIncident.severity as IncidentSeverity) || 'low',
                occurred_at: resumeIncident.occurred_at ? toLocalInput(resumeIncident.occurred_at) : '',
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
            setBootstrapped(true);
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
                incidentId: (existing.meta as { incidentId?: number | null })?.incidentId ?? null,
            });
        } else {
            setBootstrapped(true);
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    const patch = useCallback((p: Partial<WizardData>) => {
        setData((prev) => ({ ...prev, ...p }));
    }, []);

    const stepOneValid = useMemo(() => !!data.client_id && !!data.type && !!data.severity, [data]);
    const stepTwoValid = useMemo(() => (data.description ?? '').trim().length >= 3, [data.description]);

    const goNext = () => {
        const e: typeof errors = {};
        if (step === 0) {
            if (!data.client_id) e.client_id = `Please choose a ${clientSingular.toLowerCase()}.`;
            setErrors(e);
            if (Object.keys(e).length > 0) return;
            flush();
            setStep(1);
        } else if (step === 1) {
            if (!stepTwoValid) {
                setErrors({ description: 'Write a short description so the record is useful.' });
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
            <AppLayout breadcrumbs={[{ title: 'Incidents', href: '/incidents' }, { title: 'New', href: '/incidents/create' }]}>
                <Head title="Resume draft incident" />
                <div className="mx-auto flex max-w-md flex-col gap-4 px-4 py-10">
                    <div className="flex items-center gap-3">
                        <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-amber-100 text-amber-700">
                            <AlertTriangle className="h-5 w-5" />
                        </div>
                        <div>
                            <h1 className="text-lg font-semibold">Resume your draft incident?</h1>
                            <p className="text-sm text-muted-foreground">We found an unfinished incident on this device.</p>
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
        <AppLayout breadcrumbs={[{ title: 'Incidents', href: '/incidents' }, { title: 'New', href: '/incidents/create' }]}>
            <Head title="Report incident" />
            <div className="mx-auto w-full max-w-2xl space-y-6 px-4 pb-28 pt-4 sm:pb-8">
                <div className="flex items-start justify-between gap-3">
                    <div>
                        <h1 className="text-xl font-semibold tracking-tight">Report incident</h1>
                        <p className="text-sm text-muted-foreground">Quick three-step capture. You can add detail later.</p>
                    </div>
                    <Link
                        href="/incidents"
                        className="rounded-md border px-3 py-2 text-xs font-medium hover:bg-muted"
                    >
                        Exit
                    </Link>
                </div>

                <WizardStepper steps={STEPS} current={step} />

                <div className="rounded-xl border bg-card p-4 sm:p-6">
                    {step === 0 && (
                        <StepWhoWhat
                            data={data}
                            onChange={patch}
                            clients={clients}
                            clientLabel={clientSingular}
                            errors={errors}
                        />
                    )}
                    {step === 1 && <StepDescribe data={data} onChange={patch} errors={errors} />}
                    {step === 2 && (
                        <StepOptionalDetail
                            data={data}
                            onChange={patch}
                            showInjuryFields={showInjuryFields}
                        />
                    )}
                </div>

                {savedAt && (
                    <p className="text-xs text-muted-foreground">
                        Draft saved on this device · {new Date(savedAt).toLocaleTimeString()}
                    </p>
                )}

                {step < 2 ? (
                    <div className="fixed inset-x-0 bottom-0 z-20 flex items-center gap-2 border-t bg-background/95 p-3 backdrop-blur sm:static sm:border-0 sm:bg-transparent sm:p-0">
                        {step > 0 && (
                            <Button variant="outline" size="lg" onClick={goBack} className="flex-1 sm:flex-none">
                                <ArrowLeft className="mr-1.5 h-4 w-4" />
                                Back
                            </Button>
                        )}
                        <Button
                            size="lg"
                            onClick={goNext}
                            disabled={processing || (step === 0 ? !stepOneValid : !stepTwoValid)}
                            className="flex-1 sm:flex-none sm:min-w-[180px]"
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
                    <div className="fixed inset-x-0 bottom-0 z-20 flex flex-col gap-2 border-t bg-background/95 p-3 backdrop-blur sm:static sm:flex-row sm:items-center sm:justify-between sm:border-0 sm:bg-transparent sm:p-0">
                        <Button
                            variant="outline"
                            size="lg"
                            onClick={skipToIncident}
                            disabled={processing}
                            className="w-full sm:w-auto"
                        >
                            Skip — I’m done
                        </Button>
                        <Button
                            size="lg"
                            onClick={() => submitStepThree('submit')}
                            disabled={processing}
                            className="w-full sm:w-auto sm:min-w-[180px]"
                        >
                            {processing ? (
                                <>
                                    <Loader2 className="mr-1.5 h-4 w-4 animate-spin" />
                                    Saving…
                                </>
                            ) : (
                                <>
                                    <Check className="mr-1.5 h-4 w-4" />
                                    Save detail
                                </>
                            )}
                        </Button>
                    </div>
                )}
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
