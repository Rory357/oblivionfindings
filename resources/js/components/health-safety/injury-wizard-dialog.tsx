/* Injuries & RTW — create / edit wizard. Built on the shared WizardShell +
 * primitives so it is the same design & workflow as the Add Client modal:
 * 248px stepper rail, completeness meter, per-step validation, jump-to-first-error,
 * Save & add another, and a green-check success pane. */
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import {
    Field,
    InfoCard,
    Ring,
    Segmented,
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
import { formatDateLong } from '@/lib/datetime';
import { useForm, usePage } from '@inertiajs/react';
import {
    Activity,
    ArrowRight,
    ChevronLeft,
    ChevronRight,
    ClipboardCheck,
    HeartPulse,
    Loader2,
    Plus,
    ShieldAlert,
    User,
} from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import {
    INJURY_TYPES,
    SEVERITY_OPTIONS,
    TREATMENT_OPTIONS,
    injuryReference,
    injuryTypeLabel,
    severityLabel,
    treatmentLabel,
} from './injury-constants';
import type {
    IncidentOption,
    InjuryDetail,
    SiteOption,
    StaffOption,
} from './injury-types';

type FormShape = {
    user_id: string;
    site_id: string;
    related_incident_id: string;
    injury_date: string;
    injury_type: string;
    body_part_affected: string;
    severity: string;
    description: string;
    medical_treatment_type: string;
    immediate_treatment: string;
    worksafe_notifiable: boolean;
    acc_claim_number: string;
    notes: string;
};

const NONE = '__none__';

const STEPS: WizardStep[] = [
    {
        key: 'worker',
        label: 'Worker & site',
        blurb: 'Who, where & when',
        icon: User,
    },
    {
        key: 'injury',
        label: 'Injury',
        blurb: 'Type, severity & body part',
        icon: Activity,
    },
    {
        key: 'treatment',
        label: 'Treatment & ACC',
        blurb: 'Care, WorkSafe & claim',
        icon: HeartPulse,
    },
    {
        key: 'review',
        label: 'Review & record',
        blurb: 'Confirm and save',
        icon: ClipboardCheck,
    },
];

const STEP_FOR_FIELD: Record<string, string> = {
    user_id: 'worker',
    site_id: 'worker',
    injury_date: 'worker',
    related_incident_id: 'worker',
    injury_type: 'injury',
    body_part_affected: 'injury',
    severity: 'injury',
    description: 'injury',
    medical_treatment_type: 'treatment',
    immediate_treatment: 'treatment',
    worksafe_notifiable: 'treatment',
    acc_claim_number: 'treatment',
    notes: 'treatment',
};

const COMPLETION_FIELDS: (keyof FormShape)[] = [
    'user_id',
    'site_id',
    'injury_date',
    'injury_type',
    'body_part_affected',
    'severity',
    'description',
    'medical_treatment_type',
    'immediate_treatment',
    'acc_claim_number',
    'notes',
];

function initialForm(injury?: InjuryDetail | null): FormShape {
    return {
        user_id: injury?.worker ? String(injury.worker.id) : '',
        site_id: injury?.site ? String(injury.site.id) : '',
        related_incident_id: injury?.related_incident
            ? String(injury.related_incident.id)
            : NONE,
        injury_date: injury?.injury_date ? injury.injury_date.slice(0, 10) : '',
        injury_type: injury?.injury_type ?? '',
        body_part_affected: injury?.body_part_affected ?? '',
        severity: injury?.severity ?? 'moderate',
        description: injury?.description ?? '',
        medical_treatment_type: injury?.medical_treatment_type ?? '',
        immediate_treatment: injury?.immediate_treatment ?? '',
        worksafe_notifiable: injury?.worksafe_notifiable ?? false,
        acc_claim_number: injury?.acc_claim_number ?? '',
        notes: injury?.notes ?? '',
    };
}

function validateStep(key: string, d: FormShape): Record<string, string> {
    const e: Record<string, string> = {};
    if (key === 'worker') {
        if (!d.user_id) e.user_id = 'Select the injured worker';
        if (!d.site_id) e.site_id = 'Choose a site';
        if (!d.injury_date) e.injury_date = 'Date of injury is required';
    }
    if (key === 'injury') {
        if (!d.injury_type) e.injury_type = 'Choose an injury type';
        if (!d.body_part_affected.trim())
            e.body_part_affected = 'Body part is required';
        if (!d.description.trim()) e.description = 'Describe what happened';
    }
    if (key === 'treatment') {
        if (!d.medical_treatment_type)
            e.medical_treatment_type = 'Select a treatment level';
    }
    return e;
}

export function InjuryWizardDialog({
    open,
    onClose,
    mode,
    injury = null,
    staff,
    sites,
    incidents,
    onSaved,
}: {
    open: boolean;
    onClose: () => void;
    mode: 'create' | 'edit';
    injury?: InjuryDetail | null;
    staff: StaffOption[];
    sites: SiteOption[];
    incidents: IncidentOption[];
    /** Called after a successful save with the injury id (e.g. to open its detail). */
    onSaved?: (id: number, section?: string) => void;
}) {
    const page = usePage<{
        flash?: { error?: string; created_injury_id?: number };
    }>();
    const form = useForm<FormShape>(initialForm(injury));
    const { data, setData, processing, errors: serverErrors } = form;
    const [stepIndex, setStepIndex] = useState(0);
    const [localErrors, setLocalErrors] = useState<Record<string, string>>({});
    const [done, setDone] = useState(false);

    // Reset on (re)open so each launch is a clean wizard for the right record.
    useEffect(() => {
        if (open) {
            form.setData(initialForm(injury));
            form.clearErrors();
            setLocalErrors({});
            setStepIndex(0);
            setDone(false);
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, injury?.id]);

    const pct = useMemo(() => {
        const filled = COMPLETION_FIELDS.filter((k) => {
            const v = data[k];
            return typeof v === 'string' ? v.trim() !== '' : Boolean(v);
        }).length;
        return Math.round((filled / COMPLETION_FIELDS.length) * 100);
    }, [data]);

    const set = <K extends keyof FormShape>(k: K, v: FormShape[K]) =>
        setData(k, v as never);
    const fieldError = (name: string) =>
        localErrors[name] ?? (serverErrors as Record<string, string>)[name];
    const goToStep = (key: string) =>
        setStepIndex(
            Math.max(
                0,
                STEPS.findIndex((s) => s.key === key),
            ),
        );
    const stepForError = (errs: Record<string, string>) =>
        STEP_FOR_FIELD[Object.keys(errs)[0]] ?? 'worker';

    const next = () => {
        const e = validateStep(STEPS[stepIndex].key, data);
        setLocalErrors(e);
        if (Object.keys(e).length === 0)
            setStepIndex((i) => Math.min(STEPS.length - 1, i + 1));
    };
    const back = () => setStepIndex((i) => Math.max(0, i - 1));

    const resetAll = () => {
        form.reset();
        form.clearErrors();
        form.setData(initialForm(null));
        setLocalErrors({});
        setStepIndex(0);
        setDone(false);
    };

    const submit = (addAnother: boolean) => {
        const all: Record<string, string> = {};
        for (const s of STEPS) Object.assign(all, validateStep(s.key, data));
        if (Object.keys(all).length) {
            setLocalErrors(all);
            goToStep(stepForError(all));
            return;
        }
        setLocalErrors({});

        const opts = {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                if (page.props.flash?.error) return;
                if (mode === 'edit') {
                    setDone(true);
                    onSaved?.(injury!.id);
                } else if (addAnother) {
                    resetAll();
                } else {
                    setDone(true);
                }
            },
            onError: (errs: Record<string, string>) =>
                goToStep(stepForError(errs)),
        };

        if (mode === 'edit' && injury) {
            form.transform((d) => ({
                ...d,
                related_incident_id:
                    d.related_incident_id === NONE
                        ? null
                        : d.related_incident_id,
            }));
            form.put(`/health-safety/injuries/${injury.id}`, opts);
        } else {
            form.transform((d) => ({
                ...d,
                related_incident_id:
                    d.related_incident_id === NONE
                        ? null
                        : d.related_incident_id,
                stay: addAnother,
            }));
            form.post('/health-safety/injuries', opts);
        }
    };

    // ── Success pane ──
    if (done) {
        const newId =
            mode === 'create'
                ? page.props.flash?.created_injury_id
                : injury?.id;
        return (
            <WizardShell
                open={open}
                onClose={onClose}
                title="Injury recorded"
                description="The workplace injury has been saved."
                railIcon={HeartPulse}
                railTitle={mode === 'edit' ? 'Edit injury' : 'Record injury'}
                railSub=""
                steps={STEPS}
                stepIndex={STEPS.length - 1}
                onStepClick={() => {}}
                success={
                    <WizardSuccessPane
                        title={
                            mode === 'edit'
                                ? 'Changes saved'
                                : 'Injury recorded'
                        }
                        blurb={
                            mode === 'edit'
                                ? 'The injury record has been updated.'
                                : 'The worker has been added to the register as Reported. Add a return-to-work plan from its record once a capacity assessment is on file.'
                        }
                        actions={
                            <>
                                {mode === 'create' ? (
                                    <Button
                                        variant="outline"
                                        onClick={resetAll}
                                    >
                                        <Plus className="mr-1.5 h-4 w-4" />{' '}
                                        Record another
                                    </Button>
                                ) : null}
                                {newId ? (
                                    <Button
                                        onClick={() => {
                                            onClose();
                                            onSaved?.(newId, 'rtw');
                                        }}
                                    >
                                        Open record &amp; add RTW plan{' '}
                                        <ArrowRight className="ml-1.5 h-4 w-4" />
                                    </Button>
                                ) : (
                                    <Button onClick={onClose}>Done</Button>
                                )}
                            </>
                        }
                    />
                }
            />
        );
    }

    const stepKey = STEPS[stepIndex].key;
    const isReview = stepKey === 'review';

    const footerStart =
        stepIndex > 0 ? (
            <Button variant="ghost" onClick={back}>
                <ChevronLeft className="mr-1 h-4 w-4" /> Back
            </Button>
        ) : null;

    const footerEnd = (
        <>
            <Button variant="outline" onClick={onClose}>
                Cancel
            </Button>
            {isReview ? (
                <>
                    {mode === 'create' ? (
                        <Button
                            variant="secondary"
                            onClick={() => submit(true)}
                            disabled={processing}
                        >
                            Save &amp; add another
                        </Button>
                    ) : null}
                    <Button onClick={() => submit(false)} disabled={processing}>
                        {processing ? (
                            <Loader2 className="mr-1.5 h-4 w-4 animate-spin" />
                        ) : null}
                        {mode === 'edit' ? 'Save changes' : 'Record injury'}
                    </Button>
                </>
            ) : (
                <Button onClick={next}>
                    Continue <ChevronRight className="ml-1 h-4 w-4" />
                </Button>
            )}
        </>
    );

    return (
        <WizardShell
            open={open}
            onClose={onClose}
            title={
                mode === 'edit'
                    ? 'Edit workplace injury'
                    : 'Record workplace injury'
            }
            description="Capture a staff workplace injury for the register."
            railIcon={HeartPulse}
            railTitle={
                mode === 'edit' && injury ? 'Edit injury' : 'Record injury'
            }
            railSub={
                mode === 'edit' && injury
                    ? injuryReference(injury)
                    : 'New workplace injury'
            }
            steps={STEPS}
            stepIndex={stepIndex}
            onStepClick={setStepIndex}
            pct={pct}
            pctLabel="Record completeness"
            footerStart={footerStart}
            footerEnd={footerEnd}
        >
            {stepKey === 'worker' ? (
                <WizardStepPane>
                    <StepHead
                        icon={User}
                        title="Worker & site"
                        blurb="Identify the injured staff member and where it happened."
                    />
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field
                            label="Injured worker"
                            required
                            error={fieldError('user_id')}
                            span
                        >
                            <SelectInput
                                value={data.user_id}
                                onChange={(v) => set('user_id', v)}
                                placeholder="Select a staff member…"
                                options={staff.map((s) => ({
                                    value: String(s.id),
                                    label: s.name,
                                }))}
                            />
                        </Field>
                        <Field
                            label="Site"
                            required
                            error={fieldError('site_id')}
                        >
                            <SelectInput
                                value={data.site_id}
                                onChange={(v) => set('site_id', v)}
                                placeholder="Select site…"
                                options={sites.map((s) => ({
                                    value: String(s.id),
                                    label: s.name,
                                }))}
                            />
                        </Field>
                        <Field
                            label="Date of injury"
                            required
                            error={fieldError('injury_date')}
                        >
                            <Input
                                type="date"
                                value={data.injury_date}
                                onChange={(e) =>
                                    set('injury_date', e.target.value)
                                }
                            />
                        </Field>
                        <Field
                            label="Link to incident"
                            hint="optional"
                            error={fieldError('related_incident_id')}
                            span
                        >
                            <SelectInput
                                value={data.related_incident_id}
                                onChange={(v) => set('related_incident_id', v)}
                                placeholder="No linked incident"
                                options={[
                                    {
                                        value: NONE,
                                        label: 'No linked incident',
                                    },
                                    ...incidents.map((i) => ({
                                        value: String(i.id),
                                        label: `${i.label} · ${i.title}`,
                                    })),
                                ]}
                            />
                        </Field>
                    </div>
                </WizardStepPane>
            ) : null}

            {stepKey === 'injury' ? (
                <WizardStepPane>
                    <StepHead
                        icon={Activity}
                        title="The injury"
                        blurb="Classify the injury so it reports correctly to ACC and WorkSafe."
                    />
                    <div className="grid gap-4">
                        <Field
                            label="Injury type"
                            required
                            error={fieldError('injury_type')}
                        >
                            <TilePicker
                                value={data.injury_type}
                                onChange={(v) => set('injury_type', v)}
                                cols={3}
                                options={INJURY_TYPES.map((t) => ({
                                    key: t.key,
                                    label: t.label,
                                    description: t.description,
                                    icon: t.icon,
                                }))}
                            />
                        </Field>
                        <div className="grid gap-4 sm:grid-cols-2">
                            <Field
                                label="Body part affected"
                                required
                                error={fieldError('body_part_affected')}
                            >
                                <Input
                                    value={data.body_part_affected}
                                    onChange={(e) =>
                                        set(
                                            'body_part_affected',
                                            e.target.value,
                                        )
                                    }
                                    placeholder="e.g. Lower back"
                                />
                            </Field>
                            <Field
                                label="Severity"
                                required
                                error={fieldError('severity')}
                            >
                                <Segmented
                                    value={data.severity}
                                    onChange={(v) => set('severity', v)}
                                    options={SEVERITY_OPTIONS}
                                />
                            </Field>
                        </div>
                        <Field
                            label="What happened?"
                            required
                            error={fieldError('description')}
                        >
                            <Textarea
                                rows={4}
                                value={data.description}
                                onChange={(e) =>
                                    set('description', e.target.value)
                                }
                                placeholder="Describe the mechanism of injury…"
                            />
                        </Field>
                    </div>
                </WizardStepPane>
            ) : null}

            {stepKey === 'treatment' ? (
                <WizardStepPane>
                    <StepHead
                        icon={HeartPulse}
                        title="Treatment & ACC"
                        blurb="Record treatment, WorkSafe notifiability and any ACC claim."
                    />
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field
                            label="Medical treatment"
                            required
                            error={fieldError('medical_treatment_type')}
                        >
                            <SelectInput
                                value={data.medical_treatment_type}
                                onChange={(v) =>
                                    set('medical_treatment_type', v)
                                }
                                placeholder="Select treatment level…"
                                options={TREATMENT_OPTIONS}
                            />
                        </Field>
                        <Field label="Immediate treatment given">
                            <Input
                                value={data.immediate_treatment}
                                onChange={(e) =>
                                    set('immediate_treatment', e.target.value)
                                }
                                placeholder="e.g. First aid on site, GP referral"
                            />
                        </Field>
                        <InfoCard icon={ShieldAlert} tone="warn">
                            <div className="flex items-start justify-between gap-3">
                                <div>
                                    <div className="font-semibold">
                                        WorkSafe-notifiable event
                                    </div>
                                    <p className="mt-0.5">
                                        Notify WorkSafe NZ for any injury
                                        requiring hospitalisation &gt; 48h,
                                        amputation, serious head/eye injury, or
                                        other notifiable events under the Health
                                        and Safety at Work Act 2015.
                                    </p>
                                </div>
                                <label className="flex shrink-0 cursor-pointer items-center gap-2 text-[13px] font-semibold">
                                    <input
                                        type="checkbox"
                                        checked={data.worksafe_notifiable}
                                        onChange={(e) =>
                                            set(
                                                'worksafe_notifiable',
                                                e.target.checked,
                                            )
                                        }
                                        className="h-4 w-4 rounded border-border"
                                    />
                                    Notifiable
                                </label>
                            </div>
                        </InfoCard>
                        <Field label="ACC claim number" hint="if lodged">
                            <Input
                                value={data.acc_claim_number}
                                onChange={(e) =>
                                    set('acc_claim_number', e.target.value)
                                }
                                placeholder="26/123456"
                            />
                        </Field>
                        <Field label="Notes">
                            <Input
                                value={data.notes}
                                onChange={(e) => set('notes', e.target.value)}
                                placeholder="Anything else relevant"
                            />
                        </Field>
                    </div>
                </WizardStepPane>
            ) : null}

            {isReview ? (
                <WizardStepPane>
                    <StepHead
                        icon={ClipboardCheck}
                        title="Review & record"
                        blurb="Check the details, then add this injury to the register."
                    />
                    <div className="mb-5 flex items-center gap-4 rounded-xl border border-border bg-muted/30 p-4">
                        <Ring pct={pct} />
                        <div>
                            <div className="text-sm font-semibold">
                                {pct}% complete
                            </div>
                            <p className="text-[13px] text-muted-foreground">
                                Confirm the details below, then record the
                                injury.
                            </p>
                        </div>
                    </div>
                    <div className="grid gap-3 sm:grid-cols-2">
                        <ReviewCard
                            icon={User}
                            title="Worker & site"
                            onEdit={() => goToStep('worker')}
                        >
                            <ReviewRow
                                label="Worker"
                                value={
                                    staff.find(
                                        (s) => String(s.id) === data.user_id,
                                    )?.name
                                }
                            />
                            <ReviewRow
                                label="Site"
                                value={
                                    sites.find(
                                        (s) => String(s.id) === data.site_id,
                                    )?.name
                                }
                            />
                            <ReviewRow
                                label="Date"
                                value={
                                    data.injury_date
                                        ? formatDateLong(data.injury_date)
                                        : undefined
                                }
                            />
                            <ReviewRow
                                label="Linked incident"
                                value={
                                    data.related_incident_id === NONE
                                        ? 'None'
                                        : incidents.find(
                                              (i) =>
                                                  String(i.id) ===
                                                  data.related_incident_id,
                                          )?.label
                                }
                            />
                        </ReviewCard>
                        <ReviewCard
                            icon={Activity}
                            title="Injury"
                            onEdit={() => goToStep('injury')}
                        >
                            <ReviewRow
                                label="Type"
                                value={injuryTypeLabel(data.injury_type)}
                            />
                            <ReviewRow
                                label="Body part"
                                value={data.body_part_affected}
                            />
                            <ReviewRow
                                label="Severity"
                                value={severityLabel(data.severity)}
                            />
                        </ReviewCard>
                        <ReviewCard
                            icon={HeartPulse}
                            title="Treatment & ACC"
                            onEdit={() => goToStep('treatment')}
                            span
                        >
                            <ReviewRow
                                label="Treatment"
                                value={treatmentLabel(
                                    data.medical_treatment_type,
                                )}
                            />
                            <ReviewRow
                                label="WorkSafe-notifiable"
                                value={data.worksafe_notifiable ? 'Yes' : 'No'}
                            />
                            <ReviewRow
                                label="ACC claim"
                                value={data.acc_claim_number || 'Not lodged'}
                            />
                        </ReviewCard>
                    </div>
                </WizardStepPane>
            ) : null}
        </WizardShell>
    );
}
