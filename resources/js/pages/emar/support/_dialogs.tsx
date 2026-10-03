import { ConfirmDialog } from '@/components/confirm-dialog';
import { DateTimeField } from '@/components/fleet-assets/maintenance/date-time-field';
import InputError from '@/components/input-error';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { FileDropzone, StagedFileCard } from '@/components/ui/file-dropzone';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import {
    ReviewCard,
    ReviewRow,
    WizardShell,
    WizardStepPane,
    WizardSuccessPane,
} from '@/components/wizard/shell';
import { formatDateOnly, toDateInput, toDatetimeLocal } from '@/lib/datetime';
import { useForm } from '@inertiajs/react';
import {
    AlertCircle,
    Check,
    ClipboardList,
    FileSignature,
    Pill,
    ShieldCheck,
    User,
} from 'lucide-react';
import { useState, type ReactNode } from 'react';
import { supportTimeCandidates } from './time';
import {
    CHECKS,
    MODES,
    SCORES,
    SUPPORT,
    allowedModes,
    assessmentCap,
    rank,
    type Medicine,
    type SupportHistory,
    type SupportMode,
    type SupportPlan,
} from './types';

function Field({
    id,
    label,
    error,
    children,
}: {
    id: string;
    label: string;
    error?: string;
    children: ReactNode;
}) {
    return (
        <div className="space-y-1.5">
            <Label htmlFor={id}>{label}</Label>
            {children}
            <InputError message={error} />
        </div>
    );
}
function Choice({
    id,
    label,
    value,
    onChange,
    options,
    error,
}: {
    id: string;
    label: string;
    value: string;
    onChange: (v: string) => void;
    options: { value: string; label: string }[];
    error?: string;
}) {
    return (
        <Field id={id} label={label} error={error}>
            <Select value={value || undefined} onValueChange={onChange}>
                <SelectTrigger
                    id={id}
                    aria-invalid={!!error}
                    className="frontline-tap w-full"
                >
                    <SelectValue placeholder="Choose…" />
                </SelectTrigger>
                <SelectContent>
                    {options.map((x) => (
                        <SelectItem key={x.value} value={x.value}>
                            {x.label}
                        </SelectItem>
                    ))}
                </SelectContent>
            </Select>
        </Field>
    );
}
function Checks({
    id,
    label,
    checked,
    onChange,
}: {
    id: string;
    label: string;
    checked: boolean;
    onChange: (v: boolean) => void;
}) {
    return (
        <div className="flex items-center gap-3">
            <Checkbox
                id={id}
                checked={checked}
                onCheckedChange={(v) => onChange(v === true)}
            />
            <Label htmlFor={id} className="frontline-tap flex items-center">
                {label}
            </Label>
        </div>
    );
}
function Errors({ errors }: { errors: Record<string, string> }) {
    return Object.keys(errors).length ? (
        <Alert role="alert">
            <AlertCircle className="size-4" />
            <AlertTitle>Check these details</AlertTitle>
            <AlertDescription>
                {Object.values(errors).map((e, i) => (
                    <p key={i}>{e}</p>
                ))}
            </AlertDescription>
        </Alert>
    ) : null;
}
function focusError(errors: Record<string, string>) {
    const key = Object.keys(errors)[0];
    requestAnimationFrame(() => {
        const element =
            document.getElementById(key) ??
            document.querySelector<HTMLElement>('[aria-invalid="true"]');
        element?.focus();
    });
}
const PEOPLE = [
    'The person',
    'Whānau or family',
    'Welfare guardian or EPOA',
    'GP',
    'Pharmacist',
    'Key worker',
];
const STORAGE = [
    { value: 'own_drawer', label: 'Their own locked drawer' },
    { value: 'own_room', label: 'In their room (not locked)' },
    { value: 'office', label: 'Locked cupboard in the office' },
    { value: 'cd_cupboard', label: 'Controlled-drug cupboard' },
];
type AssessmentForm = Record<(typeof SCORES)[number]['key'], number | null> &
    Record<(typeof CHECKS)[number]['key'], boolean> & {
        client_request_uuid: string;
        client_id: number;
        supersedes_id: number | null;
        wishes_to_self_administer: boolean | null;
        people_involved: string[];
        storage_location: string;
        safe_storage_notes: string;
        assessor_notes: string;
        risk_factors: string;
        support_needed: string;
        support_adjustments: string[];
        reassessment_interval_months: number;
        reassessment_trigger: string;
        agreement_terms_changed: boolean;
        med_scope: { med_id: number; scope: SupportMode }[];
        confirmed_with_person: boolean;
        confirm_loosening: boolean;
    };

export function AssessmentDialog({
    plan,
    onClose,
    onAgreement,
}: {
    plan: SupportPlan;
    onClose: () => void;
    onAgreement: () => void;
}) {
    const prior = plan.assessment;
    const form = useForm<AssessmentForm>({
        client_request_uuid: crypto.randomUUID(),
        client_id: plan.client_id,
        supersedes_id: prior?.id ?? null,
        wishes_to_self_administer: prior?.wishes_to_self_administer ?? null,
        people_involved: (prior?.people_involved ?? []).map((p) =>
            p.startsWith('Welfare guardian or EPOA: ')
                ? 'Welfare guardian or EPOA'
                : p,
        ),
        cognitive_capacity: prior?.cognitive_capacity ?? null,
        physical_dexterity: prior?.physical_dexterity ?? null,
        vision_ability: prior?.vision_ability ?? null,
        swallowing_ability: prior?.swallowing_ability ?? null,
        understanding_score: prior?.understanding_score ?? null,
        can_identify_medications: prior?.can_identify_medications ?? false,
        can_read_labels: prior?.can_read_labels ?? false,
        can_open_packaging: prior?.can_open_packaging ?? false,
        can_manage_timing: prior?.can_manage_timing ?? false,
        can_store_safely: prior?.can_store_safely ?? false,
        willing_to_self_admin: prior?.willing_to_self_admin ?? false,
        storage_location: prior?.storage_location ?? '',
        safe_storage_notes: prior?.safe_storage_notes ?? '',
        assessor_notes: prior?.assessor_notes ?? '',
        risk_factors: prior?.risk_factors ?? '',
        support_needed: prior?.support_needed ?? '',
        support_adjustments: prior?.support_adjustments ?? [],
        reassessment_interval_months: prior?.reassessment_interval_months ?? 12,
        reassessment_trigger: prior?.reassessment_trigger ?? '',
        agreement_terms_changed: false,
        med_scope: plan.medicines
            .filter((m) => !m.controlled || plan.can_set_controlled)
            .map((m) => ({ med_id: m.id, scope: m.requested_mode ?? m.mode })),
        confirmed_with_person: false,
        confirm_loosening: false,
    });
    const [step, setStep] = useState(0),
        [saved, setSaved] = useState(false),
        [discard, setDiscard] = useState(false),
        [confirm, setConfirm] = useState(false),
        [guardian, setGuardian] = useState(
            prior?.people_involved
                ?.find((p) => p.startsWith('Welfare guardian or EPOA: '))
                ?.slice('Welfare guardian or EPOA: '.length) ?? '',
        );
    const cap = assessmentCap(
        form.data.wishes_to_self_administer === true,
        form.data.willing_to_self_admin,
        SCORES.reduce((n, s) => n + (form.data[s.key] ?? 0), 0),
    );
    const effective = (m: Medicine): SupportMode => {
        const chosen =
            form.data.med_scope.find((s) => s.med_id === m.id)?.scope ??
            'staff_given';
        return MODES[Math.max(rank(chosen), rank(cap), m.controlled ? 2 : 0)];
    };
    const editable = plan.medicines.filter(
        (m) => !m.controlled || plan.can_set_controlled,
    );
    const loosens = editable.some((m) => rank(effective(m)) < rank(m.mode));
    const steps = [
        {
            key: 'person',
            label: 'Wishes & who took part',
            blurb: 'Start with the person',
            icon: User,
        },
        {
            key: 'ability',
            label: 'What they can do',
            blurb: 'Scores and capability checks',
            icon: ClipboardList,
        },
        {
            key: 'support',
            label: 'Support for each medicine',
            blurb: 'At or below the assessment',
            icon: Pill,
        },
        {
            key: 'storage',
            label: 'Storage & next review',
            blurb: 'How and when',
            icon: ShieldCheck,
        },
        {
            key: 'review',
            label: 'Review & save',
            blurb: 'Check the effect',
            icon: Check,
        },
    ];
    const validate = () => {
        const e: Record<string, string> = {};
        if (form.data.wishes_to_self_administer === null)
            e.wishes_to_self_administer = 'Choose what the person wants.';
        if (
            !form.data.people_involved.some(
                (p) =>
                    p === 'The person' ||
                    p.startsWith('Welfare guardian or EPOA'),
            )
        )
            e.people_involved =
                'Choose the person or someone who can speak for them.';
        if (
            form.data.people_involved.includes('Welfare guardian or EPOA') &&
            !guardian.trim()
        )
            e.people_involved = 'Enter the welfare guardian or EPOA name.';
        for (const s of SCORES)
            if (!form.data[s.key]) e[s.key] = 'Answer ' + s.label + '.';
        if (!form.data.storage_location)
            e.storage_location = 'Choose where the medicines are kept.';
        if (!form.data.confirmed_with_person)
            e.confirmed_with_person =
                'Confirm this assessment was completed with the person.';
        return e;
    };
    const close = () => {
        if (form.processing) return;
        if (form.isDirty && !saved) setDiscard(true);
        else onClose();
    };
    const send = (confirmed: boolean) =>
        form
            .transform((data) => ({
                ...data,
                confirm_loosening: confirmed,
                people_involved: data.people_involved.map((p) =>
                    p === 'Welfare guardian or EPOA'
                        ? 'Welfare guardian or EPOA: ' + guardian.trim()
                        : p,
                ),
                med_scope: editable.map((m) => ({
                    med_id: m.id,
                    scope: effective(m),
                })),
            }))
            .post('/emar/self-admin', {
                preserveScroll: true,
                onError: (e) => {
                    setStep(
                        Object.keys(e).some((k) =>
                            SCORES.some((s) => s.key === k),
                        )
                            ? 1
                            : Object.keys(e).some((k) =>
                                    k.startsWith('med_scope'),
                                )
                              ? 2
                              : 0,
                    );
                    focusError(e);
                },
                onSuccess: () => setSaved(true),
            });
    const save = () => {
        const e = validate();
        form.clearErrors();
        if (Object.keys(e).length) {
            for (const [k, v] of Object.entries(e))
                form.setError(k as keyof AssessmentForm, v);
            setStep(
                Object.keys(e).some((k) => SCORES.some((s) => s.key === k))
                    ? 1
                    : 0,
            );
            focusError(e);
            return;
        }
        if (loosens) setConfirm(true);
        else send(false);
    };
    return (
        <>
            <WizardShell
                open
                onClose={close}
                title={
                    (prior ? 'Reassess ' : 'Assess ') +
                    plan.client_name +
                    '’s support'
                }
                description="Wishes, what they can do, then support for each medicine."
                railIcon={ClipboardList}
                railTitle={
                    prior
                        ? 'Reassess support'
                        : 'Self-administration assessment'
                }
                railSub={
                    plan.client_name +
                    ' · ' +
                    (plan.site_name ?? 'House not recorded')
                }
                steps={steps}
                stepIndex={step}
                onStepClick={setStep}
                pct={Math.round(
                    ((SCORES.filter((s) => form.data[s.key]).length +
                        Number(!!form.data.storage_location) +
                        Number(!!form.data.people_involved.length) +
                        Number(form.data.confirmed_with_person)) /
                        8) *
                        100,
                )}
                railExtra={
                    <p className="text-caption">
                        Most independence allowed: {SUPPORT[cap].label}
                    </p>
                }
                footerStart={
                    <Button
                        variant="outline"
                        disabled={form.processing}
                        onClick={() => (step > 0 ? setStep(step - 1) : close())}
                    >
                        {step > 0 ? 'Back' : 'Cancel'}
                    </Button>
                }
                footerEnd={
                    <Button
                        disabled={form.processing}
                        onClick={() => (step < 4 ? setStep(step + 1) : save())}
                    >
                        {form.processing
                            ? 'Saving…'
                            : step < 4
                              ? 'Continue'
                              : prior
                                ? 'Save reassessment'
                                : 'Save assessment'}
                    </Button>
                }
                success={
                    saved ? (
                        <WizardSuccessPane
                            title="Support assessment saved"
                            blurb={
                                plan.agreement
                                    ? 'The current agreement carries over unless its terms changed.'
                                    : 'An agreement is needed before Self-managed or Prompt support takes effect.'
                            }
                            actions={
                                <>
                                    <Button variant="outline" onClick={onClose}>
                                        Done
                                    </Button>
                                    {!plan.agreement && (
                                        <Button onClick={onAgreement}>
                                            Record agreement
                                        </Button>
                                    )}
                                </>
                            }
                        />
                    ) : undefined
                }
            >
                <WizardStepPane>
                    <div className="space-y-5">
                        <Errors errors={form.errors} />
                        {step === 0 && (
                            <>
                                {plan.reviews.length > 0 && (
                                    <Alert>
                                        <AlertTitle>
                                            Why reassess now
                                        </AlertTitle>
                                        <AlertDescription>
                                            {plan.reviews.map((r) => (
                                                <p key={r.id}>{r.reason}</p>
                                            ))}
                                        </AlertDescription>
                                    </Alert>
                                )}
                                <Choice
                                    id="wishes_to_self_administer"
                                    label="Does the person want to manage any medicines?"
                                    value={
                                        form.data.wishes_to_self_administer ===
                                        null
                                            ? ''
                                            : form.data
                                                    .wishes_to_self_administer
                                              ? 'yes'
                                              : 'no'
                                    }
                                    onChange={(v) =>
                                        form.setData(
                                            'wishes_to_self_administer',
                                            v === 'yes',
                                        )
                                    }
                                    options={[
                                        {
                                            value: 'yes',
                                            label: 'Yes — they want to manage some medicines',
                                        },
                                        {
                                            value: 'no',
                                            label: 'No — they want staff to give them',
                                        },
                                    ]}
                                    error={
                                        form.errors.wishes_to_self_administer
                                    }
                                />
                                <fieldset
                                    id="people_involved"
                                    tabIndex={-1}
                                    className="space-y-2"
                                >
                                    <legend className="text-section-title">
                                        Who took part?
                                    </legend>
                                    {PEOPLE.map((p) => (
                                        <Checks
                                            key={p}
                                            id={'person-' + PEOPLE.indexOf(p)}
                                            label={p}
                                            checked={form.data.people_involved.includes(
                                                p,
                                            )}
                                            onChange={(v) =>
                                                form.setData(
                                                    'people_involved',
                                                    v
                                                        ? [
                                                              ...form.data
                                                                  .people_involved,
                                                              p,
                                                          ]
                                                        : form.data.people_involved.filter(
                                                              (x) => x !== p,
                                                          ),
                                                )
                                            }
                                        />
                                    ))}
                                </fieldset>
                                {form.data.people_involved.includes(
                                    'Welfare guardian or EPOA',
                                ) && (
                                    <Field id="guardian" label="Their name">
                                        <Input
                                            id="guardian"
                                            value={guardian}
                                            onChange={(e) =>
                                                setGuardian(e.target.value)
                                            }
                                        />
                                    </Field>
                                )}
                            </>
                        )}
                        {step === 1 && (
                            <>
                                {SCORES.map((s) => (
                                    <div key={s.key} className="space-y-2">
                                        <Choice
                                            id={s.key}
                                            label={s.label}
                                            value={
                                                form.data[s.key]
                                                    ? String(form.data[s.key])
                                                    : ''
                                            }
                                            onChange={(v) =>
                                                form.setData(s.key, Number(v))
                                            }
                                            options={[
                                                'Not at all',
                                                'With a lot of help',
                                                'With some help',
                                                'Mostly',
                                                'Fully',
                                            ].map((word, i) => ({
                                                value: String(i + 1),
                                                label: i + 1 + ' · ' + word,
                                            }))}
                                            error={form.errors[s.key]}
                                        />
                                        <p className="text-caption">{s.help}</p>
                                    </div>
                                ))}
                                <fieldset className="space-y-2">
                                    <legend className="text-section-title">
                                        Capability checks
                                    </legend>
                                    {CHECKS.map((c) => (
                                        <Checks
                                            key={c.key}
                                            id={c.key}
                                            label={c.label}
                                            checked={form.data[c.key]}
                                            onChange={(v) =>
                                                form.setData(c.key, v)
                                            }
                                        />
                                    ))}
                                </fieldset>
                                <p>
                                    Most independence allowed:{' '}
                                    <strong>{SUPPORT[cap].label}</strong>
                                </p>
                            </>
                        )}
                        {step === 2 && (
                            <>
                                <Alert>
                                    <AlertTitle>
                                        Most independence allowed:{' '}
                                        {SUPPORT[cap].label}
                                    </AlertTitle>
                                    <AlertDescription>
                                        Each medicine can have this much
                                        independence or more staff support.
                                        Controlled medicines stay Assist or
                                        Administer.
                                    </AlertDescription>
                                </Alert>
                                {editable.map((m) => (
                                    <div key={m.id} className="space-y-2">
                                        <Label id={'med-label-' + m.id}>
                                            {m.name} ·{' '}
                                            {m.dosage ??
                                                'Strength not recorded'}
                                        </Label>
                                        <ToggleGroup
                                            type="single"
                                            aria-labelledby={
                                                'med-label-' + m.id
                                            }
                                            value={effective(m)}
                                            onValueChange={(v) => {
                                                if (v)
                                                    form.setData(
                                                        'med_scope',
                                                        form.data.med_scope.map(
                                                            (s) =>
                                                                s.med_id ===
                                                                m.id
                                                                    ? {
                                                                          ...s,
                                                                          scope: v as SupportMode,
                                                                      }
                                                                    : s,
                                                        ),
                                                    );
                                            }}
                                            className="flex w-full flex-wrap justify-start"
                                        >
                                            {MODES.map((mode) => (
                                                <ToggleGroupItem
                                                    key={mode}
                                                    value={mode}
                                                    disabled={
                                                        !allowedModes(
                                                            cap,
                                                            m.controlled,
                                                        ).includes(mode)
                                                    }
                                                    className="frontline-tap"
                                                >
                                                    {SUPPORT[mode].label}
                                                </ToggleGroupItem>
                                            ))}
                                        </ToggleGroup>
                                        <p className="text-caption">
                                            {SUPPORT[effective(m)].recorded}
                                        </p>
                                    </div>
                                ))}
                                {plan.concealed_count > 0 && (
                                    <p className="text-subtle">
                                        {plan.concealed_count} controlled
                                        medicines are concealed and keep their
                                        support.
                                    </p>
                                )}
                            </>
                        )}
                        {step === 3 && (
                            <>
                                <Choice
                                    id="storage_location"
                                    label="Where are the medicines kept?"
                                    value={form.data.storage_location}
                                    onChange={(v) =>
                                        form.setData('storage_location', v)
                                    }
                                    options={STORAGE}
                                    error={form.errors.storage_location}
                                />
                                <Field
                                    id="safe_storage_notes"
                                    label="Storage notes"
                                >
                                    <Textarea
                                        id="safe_storage_notes"
                                        value={form.data.safe_storage_notes}
                                        onChange={(e) =>
                                            form.setData(
                                                'safe_storage_notes',
                                                e.target.value,
                                            )
                                        }
                                    />
                                </Field>
                                <Choice
                                    id="reassessment_interval_months"
                                    label="Reassess in"
                                    value={String(
                                        form.data.reassessment_interval_months,
                                    )}
                                    onChange={(v) =>
                                        form.setData(
                                            'reassessment_interval_months',
                                            Number(v),
                                        )
                                    }
                                    options={[3, 6, 12].map((n) => ({
                                        value: String(n),
                                        label: n + ' months',
                                    }))}
                                />
                                <Field
                                    id="risk_factors"
                                    label="Risks to consider"
                                >
                                    <Textarea
                                        id="risk_factors"
                                        value={form.data.risk_factors}
                                        onChange={(e) =>
                                            form.setData(
                                                'risk_factors',
                                                e.target.value,
                                            )
                                        }
                                    />
                                </Field>
                                <Field
                                    id="support_needed"
                                    label="Support or adjustments needed"
                                >
                                    <Textarea
                                        id="support_needed"
                                        value={form.data.support_needed}
                                        onChange={(e) =>
                                            form.setData(
                                                'support_needed',
                                                e.target.value,
                                            )
                                        }
                                    />
                                </Field>
                                <Field
                                    id="assessor_notes"
                                    label="Assessment notes"
                                >
                                    <Textarea
                                        id="assessor_notes"
                                        value={form.data.assessor_notes}
                                        onChange={(e) =>
                                            form.setData(
                                                'assessor_notes',
                                                e.target.value,
                                            )
                                        }
                                    />
                                </Field>
                                {plan.agreement && (
                                    <Checks
                                        id="agreement_terms_changed"
                                        label="The agreement’s terms have changed — record a new agreement"
                                        checked={
                                            form.data.agreement_terms_changed
                                        }
                                        onChange={(v) =>
                                            form.setData(
                                                'agreement_terms_changed',
                                                v,
                                            )
                                        }
                                    />
                                )}
                            </>
                        )}
                        {step === 4 && (
                            <>
                                <ReviewCard
                                    title="The assessment"
                                    icon={ClipboardList}
                                    onEdit={() => setStep(0)}
                                >
                                    <ReviewRow
                                        label="Person"
                                        value={plan.client_name}
                                    />
                                    <ReviewRow
                                        label="Most independence allowed"
                                        value={SUPPORT[cap].label}
                                    />
                                    <ReviewRow
                                        label="Who took part"
                                        value={form.data.people_involved.join(
                                            ' · ',
                                        )}
                                    />
                                </ReviewCard>
                                <ReviewCard
                                    title="Support for each medicine"
                                    icon={Pill}
                                    onEdit={() => setStep(2)}
                                >
                                    {editable.map((m) => (
                                        <ReviewRow
                                            key={m.id}
                                            label={m.name}
                                            value={SUPPORT[effective(m)].label}
                                        />
                                    ))}
                                </ReviewCard>
                                <ReviewCard
                                    title="Storage & review"
                                    icon={ShieldCheck}
                                    onEdit={() => setStep(3)}
                                >
                                    <ReviewRow
                                        label="Kept"
                                        value={
                                            STORAGE.find(
                                                (s) =>
                                                    s.value ===
                                                    form.data.storage_location,
                                            )?.label ??
                                            form.data.storage_location
                                        }
                                    />
                                    <ReviewRow
                                        label="Reassess in"
                                        value={
                                            form.data
                                                .reassessment_interval_months +
                                            ' months'
                                        }
                                    />
                                </ReviewCard>
                                <Alert>
                                    <AlertTitle>
                                        {loosens
                                            ? 'Loosens staff support'
                                            : 'When you save'}
                                    </AlertTitle>
                                    <AlertDescription>
                                        The earlier assessment is kept. Support
                                        changes take effect when saved.
                                        Self-managed and Prompt choices wait for
                                        a recorded agreement.
                                    </AlertDescription>
                                </Alert>
                                <Checks
                                    id="confirmed_with_person"
                                    label="I confirm this assessment was completed with the person."
                                    checked={form.data.confirmed_with_person}
                                    onChange={(v) =>
                                        form.setData('confirmed_with_person', v)
                                    }
                                />
                            </>
                        )}
                    </div>
                </WizardStepPane>
            </WizardShell>
            <ConfirmDialog
                open={discard}
                onClose={() => setDiscard(false)}
                onConfirm={onClose}
                title="Discard this assessment?"
                description="Nothing has been saved. The current support stays as it is."
                confirmText="Discard"
                cancelText="Keep going"
            />
            <ConfirmDialog
                open={confirm}
                onClose={() => setConfirm(false)}
                onConfirm={() => {
                    setConfirm(false);
                    send(true);
                }}
                title="Loosens staff support"
                description="This reassessment allows the person to do more themselves. The agreement must be recorded before Self-managed or Prompt takes effect."
                confirmText="Save reassessment"
            />
        </>
    );
}

export function AgreementDialog({
    plan,
    staff,
    onClose,
}: {
    plan: SupportPlan;
    staff: { id: number; name: string }[];
    onClose: () => void;
}) {
    const prev = plan.agreement;
    const form = useForm({
        client_request_uuid: crypto.randomUUID(),
        agreed_by_role: 'person' as 'person' | 'guardian' | 'epoa',
        agreed_by_name: '',
        method: '' as '' | 'signed' | 'verbal',
        witness_id: '' as string | number,
        attachment: null as File | null,
        ordering_responsibility: prev?.ordering_responsibility ?? '',
        person_responsibilities: prev?.person_responsibilities ?? '',
        staff_responsibilities: prev?.staff_responsibilities ?? '',
        storage_notes: plan.assessment?.safe_storage_notes ?? '',
        confirm_loosening: false,
    });
    const [step, setStep] = useState(0),
        [saved, setSaved] = useState(false),
        [discard, setDiscard] = useState(false),
        [confirm, setConfirm] = useState(false);
    const close = () => {
        if (form.processing) return;
        if (form.isDirty && !saved) setDiscard(true);
        else onClose();
    };
    const send = () =>
        form
            .transform((data) => ({
                ...data,
                witness_id:
                    data.method === 'verbal' ? Number(data.witness_id) : null,
                confirm_loosening: true,
            }))
            .post('/emar/self-admin/' + plan.assessment!.id + '/agreement', {
                forceFormData: true,
                preserveScroll: true,
                onSuccess: () => setSaved(true),
                onError: (e) => {
                    setStep(0);
                    focusError(e);
                },
            });
    return (
        <>
            <WizardShell
                open
                onClose={close}
                title="Record the agreement"
                description="Who agreed, how they agreed, and what each side does."
                railIcon={FileSignature}
                railTitle="Record the agreement"
                railSub={plan.client_name}
                steps={[
                    {
                        key: 'who',
                        label: 'Who agreed, and how',
                        blurb: 'The person or someone for them',
                        icon: User,
                    },
                    {
                        key: 'terms',
                        label: 'What’s agreed',
                        blurb: 'What each side does',
                        icon: FileSignature,
                    },
                    {
                        key: 'review',
                        label: 'Review & save',
                        blurb: 'Check before saving',
                        icon: Check,
                    },
                ]}
                stepIndex={step}
                onStepClick={setStep}
                pct={Math.round(
                    ((Number(!!form.data.method) +
                        Number(!!form.data.ordering_responsibility) +
                        Number(!!form.data.person_responsibilities) +
                        Number(!!form.data.staff_responsibilities)) /
                        4) *
                        100,
                )}
                footerStart={
                    <Button
                        variant="outline"
                        disabled={form.processing}
                        onClick={() => (step > 0 ? setStep(step - 1) : close())}
                    >
                        {step > 0 ? 'Back' : 'Cancel'}
                    </Button>
                }
                footerEnd={
                    <Button
                        disabled={form.processing}
                        onClick={() =>
                            step < 2 ? setStep(step + 1) : setConfirm(true)
                        }
                    >
                        {form.processing
                            ? 'Saving…'
                            : step < 2
                              ? 'Continue'
                              : 'Record agreement'}
                    </Button>
                }
                success={
                    saved ? (
                        <WizardSuccessPane
                            title="Agreement recorded"
                            blurb="The agreement is versioned and carries over on reassessment unless its terms change."
                            actions={<Button onClick={onClose}>Done</Button>}
                        />
                    ) : undefined
                }
            >
                <WizardStepPane>
                    <div className="space-y-5">
                        <Errors errors={form.errors} />
                        {step === 0 && (
                            <>
                                <Choice
                                    id="agreed_by_role"
                                    label="Who agreed?"
                                    value={form.data.agreed_by_role}
                                    onChange={(v) =>
                                        form.setData(
                                            'agreed_by_role',
                                            v as 'person' | 'guardian' | 'epoa',
                                        )
                                    }
                                    options={[
                                        {
                                            value: 'person',
                                            label:
                                                plan.client_name +
                                                ' — the person themselves',
                                        },
                                        {
                                            value: 'guardian',
                                            label: 'Welfare guardian — appointed by the Family Court',
                                        },
                                        {
                                            value: 'epoa',
                                            label: 'EPOA — activated personal care and welfare',
                                        },
                                    ]}
                                />
                                {form.data.agreed_by_role !== 'person' && (
                                    <Field
                                        id="agreed_by_name"
                                        label="Their name"
                                        error={form.errors.agreed_by_name}
                                    >
                                        <Input
                                            id="agreed_by_name"
                                            value={form.data.agreed_by_name}
                                            onChange={(e) =>
                                                form.setData(
                                                    'agreed_by_name',
                                                    e.target.value,
                                                )
                                            }
                                        />
                                    </Field>
                                )}
                                <Choice
                                    id="method"
                                    label="How did they agree?"
                                    value={form.data.method}
                                    onChange={(v) =>
                                        form.setData(
                                            'method',
                                            v as 'signed' | 'verbal',
                                        )
                                    }
                                    options={[
                                        {
                                            value: 'signed',
                                            label: 'Signed the form — attach the signed form',
                                        },
                                        {
                                            value: 'verbal',
                                            label: 'Agreed out loud — another staff member witnessed it',
                                        },
                                    ]}
                                    error={form.errors.method}
                                />
                                {form.data.method === 'signed' ? (
                                    <Field
                                        id="attachment"
                                        label="The signed form"
                                        error={form.errors.attachment}
                                    >
                                        {form.data.attachment ? (
                                            <StagedFileCard
                                                file={form.data.attachment}
                                                onRemove={() =>
                                                    form.setData(
                                                        'attachment',
                                                        null,
                                                    )
                                                }
                                            />
                                        ) : (
                                            <FileDropzone
                                                id="attachment"
                                                multiple={false}
                                                accept=".pdf,.jpg,.jpeg,.png,.webp"
                                                hint="PDF or a photo of the signed page · up to 10 MB"
                                                onFiles={(files) =>
                                                    form.setData(
                                                        'attachment',
                                                        files[0] ?? null,
                                                    )
                                                }
                                            />
                                        )}
                                    </Field>
                                ) : form.data.method === 'verbal' ? (
                                    <>
                                        <Choice
                                            id="witness_id"
                                            label="Witnessed by"
                                            value={String(form.data.witness_id)}
                                            onChange={(v) =>
                                                form.setData('witness_id', v)
                                            }
                                            options={staff.map((s) => ({
                                                value: String(s.id),
                                                label: s.name,
                                            }))}
                                            error={form.errors.witness_id}
                                        />
                                        <p className="text-caption">
                                            Another staff member who was there.
                                            You cannot witness your own record.
                                        </p>
                                    </>
                                ) : null}
                            </>
                        )}
                        {step === 1 && (
                            <>
                                <Choice
                                    id="ordering_responsibility"
                                    label="Who orders the medicines?"
                                    value={form.data.ordering_responsibility}
                                    onChange={(v) =>
                                        form.setData(
                                            'ordering_responsibility',
                                            v,
                                        )
                                    }
                                    options={[
                                        {
                                            value: 'person',
                                            label: 'The person orders their own',
                                        },
                                        {
                                            value: 'service',
                                            label: 'The service orders',
                                        },
                                        {
                                            value: 'pharmacy',
                                            label: 'The pharmacy supplies automatically',
                                        },
                                    ]}
                                    error={form.errors.ordering_responsibility}
                                />
                                <Field
                                    id="person_responsibilities"
                                    label="What the person does"
                                    error={form.errors.person_responsibilities}
                                >
                                    <Textarea
                                        id="person_responsibilities"
                                        value={
                                            form.data.person_responsibilities
                                        }
                                        onChange={(e) =>
                                            form.setData(
                                                'person_responsibilities',
                                                e.target.value,
                                            )
                                        }
                                    />
                                </Field>
                                <Field
                                    id="staff_responsibilities"
                                    label="What staff do"
                                    error={form.errors.staff_responsibilities}
                                >
                                    <Textarea
                                        id="staff_responsibilities"
                                        value={form.data.staff_responsibilities}
                                        onChange={(e) =>
                                            form.setData(
                                                'staff_responsibilities',
                                                e.target.value,
                                            )
                                        }
                                    />
                                </Field>
                                <Field id="agreement-storage" label="Storage">
                                    <Textarea
                                        id="agreement-storage"
                                        value={form.data.storage_notes}
                                        onChange={(e) =>
                                            form.setData(
                                                'storage_notes',
                                                e.target.value,
                                            )
                                        }
                                    />
                                </Field>
                            </>
                        )}
                        {step === 2 && (
                            <>
                                <ReviewCard
                                    title="Who agreed, and how"
                                    icon={User}
                                    onEdit={() => setStep(0)}
                                >
                                    <ReviewRow
                                        label="Agreed by"
                                        value={
                                            form.data.agreed_by_role ===
                                            'person'
                                                ? plan.client_name
                                                : form.data.agreed_by_name
                                        }
                                    />
                                    <ReviewRow
                                        label="How"
                                        value={
                                            form.data.method === 'signed'
                                                ? 'Signed form: ' +
                                                  (form.data.attachment?.name ??
                                                      'Missing')
                                                : 'Agreed out loud; witness ' +
                                                  (staff.find(
                                                      (s) =>
                                                          s.id ===
                                                          Number(
                                                              form.data
                                                                  .witness_id,
                                                          ),
                                                  )?.name ?? 'Missing')
                                        }
                                    />
                                </ReviewCard>
                                <ReviewCard
                                    title="What’s agreed"
                                    icon={FileSignature}
                                    onEdit={() => setStep(1)}
                                >
                                    <ReviewRow
                                        label="The person"
                                        value={
                                            form.data.person_responsibilities
                                        }
                                    />
                                    <ReviewRow
                                        label="Staff"
                                        value={form.data.staff_responsibilities}
                                    />
                                    <ReviewRow
                                        label="Ordering"
                                        value={
                                            form.data.ordering_responsibility
                                        }
                                    />
                                    <ReviewRow
                                        label="Storage"
                                        value={
                                            form.data.storage_notes ||
                                            'Not recorded'
                                        }
                                    />
                                </ReviewCard>
                                <Alert>
                                    <AlertTitle>When you save</AlertTitle>
                                    <AlertDescription>
                                        This becomes the current agreement.{' '}
                                        {prev
                                            ? 'The earlier version is kept. '
                                            : ''}
                                        Pending Self-managed or Prompt support
                                        takes effect now.
                                    </AlertDescription>
                                </Alert>
                            </>
                        )}
                    </div>
                </WizardStepPane>
            </WizardShell>
            <ConfirmDialog
                open={discard}
                onClose={() => setDiscard(false)}
                onConfirm={onClose}
                title="Discard this agreement?"
                description="The current agreement stays as it is."
                confirmText="Discard"
                cancelText="Keep going"
            />
            <ConfirmDialog
                open={confirm}
                onClose={() => setConfirm(false)}
                onConfirm={() => {
                    setConfirm(false);
                    send();
                }}
                title="Record this agreement?"
                description="The agreement activates any pending Self-managed or Prompt support approved by the assessment, which loosens staff support."
                confirmText="Record agreement"
            />
        </>
    );
}

export function MedicineSupportDialog({
    plan,
    medicine,
    onClose,
}: {
    plan: SupportPlan;
    medicine: Medicine;
    onClose: () => void;
}) {
    const [mode, setMode] = useState<SupportMode>(medicine.mode),
        [confirm, setConfirm] = useState(false),
        [discard, setDiscard] = useState(false);
    const form = useForm({
        client_request_uuid: crypto.randomUUID(),
        med_scope: [{ med_id: medicine.id, scope: medicine.mode }],
        confirm_loosening: false,
    });
    const close = () => {
        if (form.processing) return;
        if (mode !== medicine.mode) setDiscard(true);
        else onClose();
    };
    const allowed = allowedModes(plan.cap, medicine.controlled);
    const canEdit =
        plan.can_assess &&
        (!medicine.controlled || plan.can_set_controlled) &&
        !!plan.assessment;
    const send = (confirmed: boolean) =>
        form
            .transform((data) => ({
                ...data,
                med_scope: [{ med_id: medicine.id, scope: mode }],
                confirm_loosening: confirmed,
            }))
            .put('/emar/self-admin/' + plan.assessment!.id, {
                preserveScroll: true,
                onSuccess: onClose,
                onError: focusError,
            });
    return (
        <>
            <Dialog open onOpenChange={(open) => !open && close()}>
                <DialogContent
                    style={{
                        width: 'min(92vw, 720px)',
                        maxWidth: 'min(92vw, 720px)',
                    }}
                >
                    <DialogHeader>
                        <DialogTitle>
                            {medicine.requested_mode
                                ? 'Give more staff support'
                                : 'Set support'}{' '}
                            · {medicine.name}
                        </DialogTitle>
                        <DialogDescription>
                            {medicine.dosage ?? 'Strength not recorded'} · most
                            independence allowed: {SUPPORT[plan.cap].label}
                        </DialogDescription>
                    </DialogHeader>
                    <div className="space-y-4">
                        <Errors errors={form.errors} />
                        {MODES.map((m) => (
                            <Button
                                key={m}
                                variant={mode === m ? 'default' : 'outline'}
                                className="frontline-tap h-auto w-full justify-start text-left whitespace-normal"
                                disabled={
                                    !canEdit ||
                                    !allowed.includes(m) ||
                                    (medicine.requested_mode !== null &&
                                        rank(m) < rank(medicine.mode))
                                }
                                onClick={() => setMode(m)}
                            >
                                <span>
                                    <strong className="block">
                                        {SUPPORT[m].label}
                                    </strong>
                                    {SUPPORT[m].description}
                                </span>
                            </Button>
                        ))}
                        <p className="text-subtle">
                            {medicine.controlled
                                ? 'Controlled medicines are Assist or Administer. '
                                : ''}
                            More independence needs a reassessment. Self-managed
                            and Prompt require an agreement.
                        </p>
                        <p>{SUPPORT[mode].recorded}</p>
                    </div>
                    <DialogFooter>
                        <Button
                            variant="outline"
                            onClick={close}
                            disabled={form.processing}
                        >
                            Close
                        </Button>
                        {canEdit && (
                            <Button
                                disabled={
                                    form.processing || mode === medicine.mode
                                }
                                onClick={() =>
                                    rank(mode) < rank(medicine.mode)
                                        ? setConfirm(true)
                                        : send(false)
                                }
                            >
                                {form.processing ? 'Saving…' : 'Save support'}
                            </Button>
                        )}
                    </DialogFooter>
                </DialogContent>
            </Dialog>
            <ConfirmDialog
                open={confirm}
                onClose={() => setConfirm(false)}
                onConfirm={() => {
                    setConfirm(false);
                    send(true);
                }}
                title="Loosens staff support"
                description="This new medicine will use the selected support. The assessment cap and agreement still apply."
                confirmText="Set support"
            />
            <ConfirmDialog
                open={discard}
                onClose={() => setDiscard(false)}
                onConfirm={onClose}
                title="Discard this support change?"
                description="This choice has not been saved."
                confirmText="Discard"
                cancelText="Keep going"
            />
        </>
    );
}

export function ConsentDialog({
    plan,
    onClose,
}: {
    plan: SupportPlan;
    onClose: () => void;
}) {
    const form = useForm({
        client_request_uuid: crypto.randomUUID(),
        client_medication_id: 'all',
        direction: 'less',
        said: '',
        occurred_at: toDatetimeLocal(new Date()),
    });
    const [discard, setDiscard] = useState(false),
        [occurrence, setOccurrence] = useState('');
    const candidates = supportTimeCandidates(form.data.occurred_at);
    const close = () => {
        if (form.processing) return;
        if (form.isDirty) setDiscard(true);
        else onClose();
    };
    const send = () => {
        if (!navigator.onLine) {
            form.setError(
                'said',
                'Not saved — your draft is kept. Connect and retry. Tell the house lead about this change now.',
            );
            return;
        }
        form.transform((data) => ({
            ...data,
            client_medication_id:
                data.client_medication_id === 'all'
                    ? null
                    : Number(data.client_medication_id),
            occurred_at:
                candidates.length === 1
                    ? candidates[0]
                    : occurrence || data.occurred_at,
        })).post('/emar/self-admin/' + plan.assessment!.id + '/consent', {
            preserveScroll: true,
            onSuccess: onClose,
            onError: focusError,
        });
    };
    return (
        <>
            <Dialog open onOpenChange={(open) => !open && close()}>
                <DialogContent
                    style={{
                        width: 'min(92vw, 720px)',
                        maxWidth: 'min(92vw, 720px)',
                    }}
                    className="max-h-[90vh] overflow-y-auto"
                >
                    <DialogHeader>
                        <DialogTitle>
                            Record a change the person asked for
                        </DialogTitle>
                        <DialogDescription>
                            {plan.client_name} · a refusal of one dose stays a
                            refusal.
                        </DialogDescription>
                    </DialogHeader>
                    <div className="space-y-4">
                        <Errors errors={form.errors} />
                        <Choice
                            id="client_medication_id"
                            label="Which medicine?"
                            value={form.data.client_medication_id}
                            onChange={(v) =>
                                form.setData('client_medication_id', v)
                            }
                            options={[
                                { value: 'all', label: 'All medicines' },
                                ...plan.medicines.map((m) => ({
                                    value: String(m.id),
                                    label: m.name,
                                })),
                            ]}
                        />
                        <Choice
                            id="direction"
                            label="What did they ask?"
                            value={form.data.direction}
                            onChange={(v) => form.setData('direction', v)}
                            options={[
                                {
                                    value: 'less',
                                    label: 'Staff to do more — withdraw consent to managing it',
                                },
                                {
                                    value: 'more',
                                    label: 'To do more themselves — reassessment needed',
                                },
                            ]}
                        />
                        <Field
                            id="said"
                            label="What they said"
                            error={form.errors.said}
                        >
                            <Textarea
                                id="said"
                                value={form.data.said}
                                onChange={(e) =>
                                    form.setData('said', e.target.value)
                                }
                            />
                        </Field>
                        <DateTimeField
                            id="occurred_at"
                            label="When"
                            value={form.data.occurred_at}
                            onChange={(v) => {
                                form.setData('occurred_at', v);
                                setOccurrence('');
                            }}
                            error={form.errors.occurred_at}
                            clearable={false}
                        />
                        {candidates.length === 2 && (
                            <Choice
                                id="occurrence"
                                label="This time occurs twice — which occurrence?"
                                value={occurrence}
                                onChange={setOccurrence}
                                options={candidates.map((value, i) => ({
                                    value,
                                    label:
                                        i === 0
                                            ? 'First occurrence (NZDT +13:00)'
                                            : 'Second occurrence (NZST +12:00)',
                                }))}
                            />
                        )}
                        <Alert>
                            <AlertTitle>When you save</AlertTitle>
                            <AlertDescription>
                                {form.data.direction === 'less'
                                    ? 'The medicine moves to Administer straight away, and a reassessment follow-up starts.'
                                    : 'Support stays as it is. More independence waits for a reassessment.'}
                            </AlertDescription>
                        </Alert>
                    </div>
                    <DialogFooter>
                        <Button
                            variant="outline"
                            onClick={close}
                            disabled={form.processing}
                        >
                            Cancel
                        </Button>
                        <Button onClick={send} disabled={form.processing}>
                            {form.processing ? 'Saving…' : 'Record change'}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
            <ConfirmDialog
                open={discard}
                onClose={() => setDiscard(false)}
                onConfirm={onClose}
                title="Discard this change?"
                description="This change has not been saved."
                confirmText="Discard"
                cancelText="Keep going"
            />
        </>
    );
}

export function AssessmentHistoryDialog({
    assessment,
    onClose,
}: {
    assessment: SupportHistory;
    onClose: () => void;
}) {
    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent
                style={{
                    width: 'min(92vw, 720px)',
                    maxWidth: 'min(92vw, 720px)',
                }}
                className="max-h-[90vh] overflow-y-auto"
            >
                <DialogHeader>
                    <DialogTitle>
                        Assessment ·{' '}
                        {formatDateOnly(
                            toDateInput(assessment.assessment_date),
                        )}
                    </DialogTitle>
                    <DialogDescription>
                        {assessment.assessor_name ?? 'Assessor not recorded'} ·
                        earlier assessments are kept
                    </DialogDescription>
                </DialogHeader>
                <div className="space-y-4">
                    <ReviewCard title="What they can do" icon={ClipboardList}>
                        {SCORES.map((s) => (
                            <ReviewRow
                                key={s.key}
                                label={s.label}
                                value={
                                    assessment[s.key] === null
                                        ? 'Not assessed'
                                        : String(assessment[s.key]) + ' / 5'
                                }
                            />
                        ))}
                    </ReviewCard>
                    <ReviewCard title="Capability checks" icon={ShieldCheck}>
                        {CHECKS.map((c) => (
                            <ReviewRow
                                key={c.key}
                                label={c.label}
                                value={assessment[c.key] ? 'Yes' : 'No'}
                            />
                        ))}
                    </ReviewCard>
                    <p>{assessment.assessor_notes ?? 'No assessment notes'}</p>
                    <p className="text-caption">
                        Next review:{' '}
                        {formatDateOnly(
                            toDateInput(assessment.reassessment_date),
                            'Not recorded',
                        )}
                    </p>
                </div>
                <DialogFooter>
                    <Button onClick={onClose}>Close</Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
