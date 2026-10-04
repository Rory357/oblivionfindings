import { ConfirmDialog } from '@/components/confirm-dialog';
import { DatePicker } from '@/components/fleet-assets/maintenance/date-picker';
import { DateTimeField } from '@/components/fleet-assets/maintenance/date-time-field';
import { TimePicker } from '@/components/fleet-assets/maintenance/time-picker';
import { WitnessPinInput } from '@/components/medications/witness-pin-input';
import { RecordPicker } from '@/components/people-locations/record-picker';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { FileDropzone, StagedFileCard } from '@/components/ui/file-dropzone';
import { Input } from '@/components/ui/input';
import { StatusBadge } from '@/components/ui/status-badge';
import { Textarea } from '@/components/ui/textarea';
import { TilePicker } from '@/components/wizard/primitives';
import {
    ReviewCard,
    ReviewRow,
    WizardShell,
    WizardStepPane,
    WizardSuccessPane,
} from '@/components/wizard/shell';
import { toDateInput, toDatetimeLocal } from '@/lib/datetime';
import { useForm } from '@inertiajs/react';
import axios from 'axios';
import {
    ClipboardCheck,
    FileText,
    Phone,
    Pill,
    ShieldCheck,
    User,
} from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { Errors, Field, Note, useDraftClose } from './_parts';
import type {
    AllergyInspection,
    ClientChoice,
    Detail,
    Order,
    Prescription,
    ReviewHandoff,
} from './_types';

const emptyPrescription = (): Prescription => ({
    name: '',
    dosage: '',
    dose_amount: null,
    dose_unit: '',
    frequency: '',
    frequency_code: null,
    dose_times: [],
    is_prn: false,
    route: '',
    form: '',
    instructions: '',
    indication: '',
    prn_reason: '',
    max_per_day: null,
    min_hours_between_doses: null,
    start_date: toDateInput(new Date()),
    end_date: null,
    prescriber: '',
    pharmacy: '',
    controlled_drug: false,
    high_risk: false,
    witness_required: false,
});
type Props = {
    clients: ClientChoice[];
    me: number;
    canControlled: boolean;
    onClose: () => void;
    order?: Order;
    detail?: Detail;
    existing: Order[];
    prefillClientId?: number;
    onUseExisting?: (order: Order) => void;
    review?: ReviewHandoff;
};

export function OrderEntry(props: Props) {
    const [step, setStep] = useState(0);
    const [saved, setSaved] = useState(false);
    const [confirmSwap, setConfirmSwap] = useState(false);
    const [errorTarget, setErrorTarget] = useState<string | null>(null);
    const pane = useRef<HTMLDivElement>(null);
    const prescription = props.detail?.order
        ? {
              ...props.detail.order,
              start_date: props.detail.order.start_date?.slice(0, 10),
              end_date: props.detail.order.end_date?.slice(0, 10) ?? null,
          }
        : emptyPrescription();
    const form = useForm({
        client_id: String(
            props.order?.client_id ?? props.prefillClientId ?? '',
        ),
        medication_id: props.order?.id ?? null,
        expected_version: props.order?.version ?? null,
        request_key: crypto.randomUUID(),
        review_item: props.review?.id ?? null,
        stop_reason: '',
        change_reason: '',
        prescription,
        source: {
            type: 'written',
            prescriber: prescription.prescriber ?? '',
            received_at: toDatetimeLocal(new Date()),
            description: '',
            read_back_confirmed: false,
            witness_id: '',
            witness_pin: '',
        },
        source_file: null as File | null,
    });
    const close = useDraftClose(
        !saved && form.isDirty,
        form.processing,
        props.onClose,
    );
    const [allergy, setAllergy] = useState<AllergyInspection | null>(null);
    const [allergyError, setAllergyError] = useState(false);
    const [witnesses, setWitnesses] = useState<
        { id: number; name: string; witness_pin: string }[]
    >([]);
    const spoken = form.data.source.type !== 'written';
    const steps = [
        {
            key: 'source',
            label: 'Where it came from',
            blurb: 'Prescription and prescriber',
            icon: FileText,
        },
        {
            key: 'medicine',
            label: props.order ? 'What’s changing' : 'The medicine',
            blurb: 'Dose, route and times',
            icon: Pill,
        },
        ...(spoken
            ? [
                  {
                      key: 'read-back',
                      label: 'Read it back',
                      blurb: 'Colleague’s witness PIN',
                      icon: Phone,
                  },
              ]
            : []),
        {
            key: 'review',
            label: 'Review & save',
            blurb: 'Version waiting to be checked',
            icon: ClipboardCheck,
        },
    ];
    const p = form.data.prescription;
    const setP = <K extends keyof Prescription>(
        key: K,
        value: Prescription[K],
    ) => form.setData('prescription', { ...p, [key]: value });
    const setSource = <K extends keyof typeof form.data.source>(
        key: K,
        value: (typeof form.data.source)[K],
    ) => form.setData('source', { ...form.data.source, [key]: value });
    useEffect(() => {
        if (!errorTarget) return;
        const frame = window.requestAnimationFrame(() => {
            const target =
                pane.current?.querySelector<HTMLElement>(`#${errorTarget}`) ??
                pane.current?.querySelector<HTMLElement>(
                    `#${errorTarget}-field input, #${errorTarget}-field textarea, #${errorTarget}-field button`,
                ) ??
                pane.current?.querySelector<HTMLElement>(
                    'input, textarea, button[role="combobox"]',
                );
            target?.focus();
            setErrorTarget(null);
        });
        return () => window.cancelAnimationFrame(frame);
    }, [errorTarget, step]);
    useEffect(() => {
        if (!form.data.client_id || !p.name.trim()) {
            setAllergy(null);
            return;
        }
        const cancel = new AbortController();
        const timeout = window.setTimeout(() => {
            setAllergy(null);
            setAllergyError(false);
            axios
                .get<AllergyInspection>(
                    `/emar/orders/allergies/${form.data.client_id}`,
                    { params: { medicine: p.name }, signal: cancel.signal },
                )
                .then((response) => setAllergy(response.data))
                .catch(() => {
                    if (!cancel.signal.aborted) setAllergyError(true);
                });
        }, 250);
        return () => {
            window.clearTimeout(timeout);
            cancel.abort();
        };
    }, [form.data.client_id, p.name]);
    useEffect(() => {
        if (!form.data.client_id || !spoken) return;
        const cancel = new AbortController();
        axios
            .get<{ people: typeof witnesses }>(
                `/emar/orders/witnesses/${form.data.client_id}`,
                { signal: cancel.signal },
            )
            .then((response) => setWitnesses(response.data.people))
            .catch(() => {
                if (!cancel.signal.aborted) setWitnesses([]);
            });
        return () => cancel.abort();
    }, [form.data.client_id, spoken]);
    const duplicate =
        !props.order &&
        props.existing.find(
            (order) =>
                String(order.client_id) === form.data.client_id &&
                order.name.toLowerCase().trim() ===
                    p.name.toLowerCase().trim() &&
                order.state !== 'ceased',
        );
    const save = (swapConfirmed = false) => {
        form.transform((data) => ({
            ...data,
            confirm_swap: swapConfirmed,
            prescription: {
                ...data.prescription,
                prescriber: data.source.prescriber,
            },
            source: spoken
                ? data.source
                : {
                      type: data.source.type,
                      prescriber: data.source.prescriber,
                      received_at: data.source.received_at,
                      description: data.source.description,
                  },
        }));
        form.post('/emar/orders', {
            preserveScroll: true,
            onSuccess: () => setSaved(true),
            onError: (errors) => {
                const key = Object.keys(errors)[0] ?? 'client_id';
                const field = key
                    .replace(/^prescription\./, '')
                    .replace(/^source\./, '')
                    .split('.')[0];
                const readback = [
                    'witness_pin',
                    'witness_id',
                    'read_back_confirmed',
                ].includes(field);
                const source = [
                    'client_id',
                    'type',
                    'prescriber',
                    'received_at',
                    'description',
                    'source_file',
                ].includes(field);
                const swap = ['stop_reason', 'confirm_swap'].includes(field);
                setStep(
                    swap
                        ? steps.length - 1
                        : readback && spoken
                          ? 2
                          : source
                            ? 0
                            : 1,
                );
                const ids: Record<string, string> = {
                    client_id: 'order-person',
                    prescriber: 'order-prescriber',
                    received_at: 'order-received',
                    description: 'order-source-notes',
                    name: 'order-medicine',
                    dosage: 'order-dose',
                    dose_amount: 'order-dose-amount',
                    dose_unit: 'order-dose-unit',
                    route: 'order-route',
                    frequency: 'order-frequency',
                    indication: 'order-indication',
                    instructions: 'order-instructions',
                    start_date: 'order-start',
                    end_date: 'order-end',
                    prn_reason: 'order-prn-reason',
                    max_per_day: 'order-prn-max',
                    min_hours_between_doses: 'order-prn-interval',
                    type: 'order-source',
                    source_file: 'order-source-file',
                    witness_pin: 'order-witness-pin',
                    witness_id: 'order-witness',
                    read_back_confirmed: 'order-readback-confirmed',
                    stop_reason: 'order-swap-stop',
                    confirm_swap: 'order-swap-stop',
                };
                setErrorTarget(ids[field] ?? 'order-error-control');
            },
        });
    };
    return (
        <>
            <WizardShell
                frontline
                open
                onClose={close.close}
                title={props.order ? 'Enter an order change' : 'Enter an order'}
                description="Enter the prescriber’s instructions from their source. A separate check is required before the new version can be given."
                railIcon={Pill}
                railTitle={props.order ? 'Enter a change' : 'Enter an order'}
                railSub={props.order?.person ?? 'Prescriber’s instructions'}
                success={
                    saved ? (
                        <WizardSuccessPane
                            title="Order saved — waiting to be checked"
                            blurb={
                                props.review?.outcome === 'swap'
                                    ? `${props.review.name_snapshot} has stopped. The replacement is waiting for a colleague who did not enter or witness it to check the source and prescription before the first dose.`
                                    : props.order
                                      ? `Staff keep giving version ${props.order.version} until this change is checked. A colleague who did not enter or witness it checks the saved source and prescription.`
                                      : 'A colleague who did not enter or witness this order checks the saved source and prescription before the first dose.'
                            }
                            actions={
                                <Button onClick={props.onClose}>
                                    Back to orders to arrange the check
                                </Button>
                            }
                        />
                    ) : undefined
                }
                steps={steps}
                stepIndex={Math.min(step, steps.length - 1)}
                onStepClick={setStep}
                pct={Math.round(
                    ([
                        form.data.client_id,
                        p.name,
                        p.dosage,
                        p.frequency,
                        p.indication,
                        form.data.source.prescriber,
                        form.data.source.received_at,
                        spoken
                            ? form.data.source.witness_pin
                            : form.data.source_file,
                    ].filter(Boolean).length /
                        8) *
                        100,
                )}
                footerStart={
                    <Button
                        variant="outline"
                        onClick={step ? () => setStep(step - 1) : close.close}
                    >
                        {step ? 'Back' : 'Cancel'}
                    </Button>
                }
                footerEnd={
                    <Button
                        disabled={form.processing}
                        onClick={
                            step < steps.length - 1
                                ? () => setStep(step + 1)
                                : () =>
                                      props.review?.outcome === 'swap'
                                          ? setConfirmSwap(true)
                                          : save()
                        }
                    >
                        {form.processing
                            ? 'Saving…'
                            : step < steps.length - 1
                              ? 'Continue'
                              : props.review?.outcome === 'swap'
                                ? 'Stop old order and enter replacement'
                                : 'Save — waiting to be checked'}
                    </Button>
                }
            >
                <WizardStepPane
                    key={steps[Math.min(step, steps.length - 1)].key}
                >
                    <div ref={pane} className="grid gap-5">
                        <Errors errors={form.errors} />
                        {props.review && (
                            <Note>
                                <p className="font-semibold">
                                    Agreed review recommendation:{' '}
                                    {props.review.name_snapshot}
                                </p>
                                <p>{props.review.recommendation}</p>
                                <p>
                                    Enter the prescribing source and the full
                                    instructions. This recommendation does not
                                    authorise a dose.
                                </p>
                            </Note>
                        )}
                        {step === 0 && (
                            <>
                                <Field id="order-person" label="Person">
                                    <RecordPicker
                                        label="Person"
                                        value={form.data.client_id}
                                        disabled={
                                            !!props.order || !!props.review
                                        }
                                        options={props.clients
                                            .filter(
                                                (client) =>
                                                    client.can_enter ||
                                                    client.id ===
                                                        props.order?.client_id,
                                            )
                                            .map((client) => ({
                                                value: String(client.id),
                                                label: client.name,
                                            }))}
                                        onChange={(value) =>
                                            form.setData('client_id', value)
                                        }
                                    />
                                </Field>
                                <Field
                                    id="order-source"
                                    label="Where did it come from?"
                                >
                                    <TilePicker
                                        value={form.data.source.type}
                                        onChange={(value) => {
                                            setSource('type', value);
                                            setStep(0);
                                        }}
                                        options={[
                                            {
                                                key: 'written',
                                                label: 'Written prescription',
                                                description:
                                                    'Attach the source',
                                                icon: FileText,
                                            },
                                            {
                                                key: 'phone',
                                                label: 'Phone order',
                                                description:
                                                    'Read back with a colleague',
                                                icon: Phone,
                                            },
                                            {
                                                key: 'verbal',
                                                label: 'Verbal order',
                                                description:
                                                    'Read back with a colleague',
                                                icon: User,
                                            },
                                        ]}
                                    />
                                </Field>
                                <Field id="order-prescriber" label="Prescriber">
                                    <Input
                                        id="order-prescriber"
                                        value={form.data.source.prescriber}
                                        onChange={(event) =>
                                            setSource(
                                                'prescriber',
                                                event.target.value,
                                            )
                                        }
                                    />
                                </Field>
                                <DateTimeField
                                    compact
                                    id="order-received"
                                    label="Received"
                                    value={form.data.source.received_at}
                                    onChange={(value) =>
                                        setSource('received_at', value)
                                    }
                                    clearable={false}
                                />
                                <Field
                                    id="order-source-notes"
                                    label="Source details"
                                >
                                    <Textarea
                                        id="order-source-notes"
                                        value={form.data.source.description}
                                        onChange={(event) =>
                                            setSource(
                                                'description',
                                                event.target.value,
                                            )
                                        }
                                        placeholder="Which prescription or instruction did you use?"
                                    />
                                </Field>
                                {!spoken && (
                                    <>
                                        <div id="order-source-file-field">
                                            <FileDropzone
                                                multiple={false}
                                                accept=".pdf,.jpg,.jpeg,.png"
                                                title="Attach the prescription"
                                                hint="One PDF, JPG or PNG · up to 10 MB"
                                                onFiles={(files) =>
                                                    form.setData(
                                                        'source_file',
                                                        files[0] ?? null,
                                                    )
                                                }
                                            />
                                        </div>
                                        {form.data.source_file && (
                                            <StagedFileCard
                                                file={form.data.source_file}
                                                onRemove={() =>
                                                    form.setData(
                                                        'source_file',
                                                        null,
                                                    )
                                                }
                                            >
                                                <p className="text-caption">
                                                    Staged — saved with the
                                                    order.
                                                </p>
                                            </StagedFileCard>
                                        )}
                                    </>
                                )}
                                {spoken && (
                                    <Note>
                                        The prescriber’s written confirmation is
                                        due by the end of the next NZ day. A
                                        colleague who heard the read-back
                                        confirms with their witness PIN.
                                    </Note>
                                )}
                            </>
                        )}
                        {step === 1 && (
                            <>
                                <div className="grid gap-3 sm:grid-cols-2">
                                    <Field id="order-medicine" label="Medicine">
                                        <Input
                                            id="order-medicine"
                                            value={p.name}
                                            disabled={!!props.order}
                                            onChange={(event) =>
                                                setP('name', event.target.value)
                                            }
                                        />
                                    </Field>
                                    <Field
                                        id="order-form"
                                        label="Strength and form"
                                    >
                                        <Input
                                            id="order-form"
                                            value={p.form ?? ''}
                                            onChange={(event) =>
                                                setP('form', event.target.value)
                                            }
                                        />
                                    </Field>
                                    <Field id="order-dose" label="Ordered dose">
                                        <Input
                                            id="order-dose"
                                            value={p.dosage}
                                            onChange={(event) =>
                                                setP(
                                                    'dosage',
                                                    event.target.value,
                                                )
                                            }
                                        />
                                    </Field>
                                    <Field
                                        id="order-dose-amount"
                                        label="Dose amount"
                                    >
                                        <Input
                                            id="order-dose-amount"
                                            inputMode="decimal"
                                            value={p.dose_amount ?? ''}
                                            onChange={(event) =>
                                                setP(
                                                    'dose_amount',
                                                    event.target.value || null,
                                                )
                                            }
                                        />
                                    </Field>
                                    <Field
                                        id="order-dose-unit"
                                        label="Dose unit"
                                    >
                                        <Input
                                            id="order-dose-unit"
                                            value={p.dose_unit ?? ''}
                                            onChange={(event) =>
                                                setP(
                                                    'dose_unit',
                                                    event.target.value,
                                                )
                                            }
                                        />
                                    </Field>
                                    <Field id="order-route" label="Route">
                                        <Input
                                            id="order-route"
                                            value={p.route}
                                            onChange={(event) =>
                                                setP(
                                                    'route',
                                                    event.target.value,
                                                )
                                            }
                                        />
                                    </Field>
                                </div>
                                {allergyError ? (
                                    <Note>
                                        Allergy record couldn’t be loaded. Check
                                        the health profile before entering or
                                        checking this order.
                                    </Note>
                                ) : allergy ? (
                                    <Note>
                                        <p className="font-semibold">
                                            Recorded allergies:{' '}
                                            {allergy.recorded
                                                .map((entry) => entry.allergen)
                                                .join(', ') ||
                                                'Nothing recorded — this does not mean no known allergies.'}
                                        </p>
                                        {allergy.matches.length > 0 && (
                                            <>
                                                <StatusBadge variant="critical">
                                                    Prescriber must confirm
                                                </StatusBadge>
                                                <p>
                                                    This version matches{' '}
                                                    {allergy.matches
                                                        .map(
                                                            (entry) =>
                                                                entry.allergen,
                                                        )
                                                        .join(', ')}
                                                    . It cannot be checked until
                                                    the prescriber confirms it
                                                    is safe.
                                                </p>
                                            </>
                                        )}
                                        {allergy.class_matching ===
                                            'not_configured' && (
                                            <p>
                                                Drug-class matching: Not
                                                configured. Check the
                                                prescription against all
                                                recorded allergies.
                                            </p>
                                        )}
                                    </Note>
                                ) : p.name && form.data.client_id ? (
                                    <Note>Loading the recorded allergies…</Note>
                                ) : null}
                                {duplicate && (
                                    <Note>
                                        This person already has {duplicate.name}{' '}
                                        on their chart. A separate regular and
                                        as-needed order can both be appropriate.
                                        {props.onUseExisting && (
                                            <Button
                                                variant="outline"
                                                onClick={() =>
                                                    props.onUseExisting?.(
                                                        duplicate,
                                                    )
                                                }
                                            >
                                                Enter a change instead
                                            </Button>
                                        )}
                                    </Note>
                                )}
                                <Field id="order-type" label="When it is given">
                                    <TilePicker
                                        value={p.is_prn ? 'prn' : 'scheduled'}
                                        onChange={(value) =>
                                            setP('is_prn', value === 'prn')
                                        }
                                        options={[
                                            {
                                                key: 'scheduled',
                                                label: 'Scheduled',
                                                description:
                                                    'At prescribed times',
                                                icon: Pill,
                                            },
                                            {
                                                key: 'prn',
                                                label: 'As needed',
                                                description:
                                                    'Within prescribed limits',
                                                icon: ShieldCheck,
                                            },
                                        ]}
                                    />
                                </Field>
                                <Field
                                    id="order-frequency"
                                    label="Frequency from the prescription"
                                >
                                    <Input
                                        id="order-frequency"
                                        value={p.frequency}
                                        onChange={(event) =>
                                            setP(
                                                'frequency',
                                                event.target.value,
                                            )
                                        }
                                    />
                                </Field>
                                {!p.is_prn ? (
                                    <div className="grid gap-3">
                                        <p className="font-medium">
                                            Scheduled NZ times from the
                                            prescription
                                        </p>
                                        {p.dose_times.map((time, index) => (
                                            <div
                                                key={index}
                                                className="flex items-center gap-2"
                                            >
                                                <TimePicker
                                                    compact
                                                    id={`order-time-${index}`}
                                                    label={`Dose time ${index + 1}`}
                                                    value={time}
                                                    onChange={(value) =>
                                                        setP(
                                                            'dose_times',
                                                            p.dose_times.map(
                                                                (entry, at) =>
                                                                    at === index
                                                                        ? value
                                                                        : entry,
                                                            ),
                                                        )
                                                    }
                                                />
                                                <Button
                                                    variant="ghost"
                                                    onClick={() =>
                                                        setP(
                                                            'dose_times',
                                                            p.dose_times.filter(
                                                                (_, at) =>
                                                                    at !==
                                                                    index,
                                                            ),
                                                        )
                                                    }
                                                >
                                                    Remove time {index + 1}
                                                </Button>
                                            </div>
                                        ))}
                                        <Button
                                            variant="outline"
                                            onClick={() =>
                                                setP('dose_times', [
                                                    ...p.dose_times,
                                                    '',
                                                ])
                                            }
                                        >
                                            Add a dose time
                                        </Button>
                                    </div>
                                ) : (
                                    <div className="grid gap-3 sm:grid-cols-2">
                                        <Field
                                            id="order-prn-reason"
                                            label="When it may be used"
                                        >
                                            <Input
                                                id="order-prn-reason"
                                                value={p.prn_reason ?? ''}
                                                onChange={(event) =>
                                                    setP(
                                                        'prn_reason',
                                                        event.target.value,
                                                    )
                                                }
                                            />
                                        </Field>
                                        <Field
                                            id="order-prn-max"
                                            label="Maximum doses in 24 hours"
                                        >
                                            <Input
                                                id="order-prn-max"
                                                type="number"
                                                value={p.max_per_day ?? ''}
                                                onChange={(event) =>
                                                    setP(
                                                        'max_per_day',
                                                        event.target.value ||
                                                            null,
                                                    )
                                                }
                                            />
                                        </Field>
                                        <Field
                                            id="order-prn-interval"
                                            label="Minimum hours between doses"
                                        >
                                            <Input
                                                id="order-prn-interval"
                                                inputMode="decimal"
                                                value={
                                                    p.min_hours_between_doses ??
                                                    ''
                                                }
                                                onChange={(event) =>
                                                    setP(
                                                        'min_hours_between_doses',
                                                        event.target.value ||
                                                            null,
                                                    )
                                                }
                                            />
                                        </Field>
                                    </div>
                                )}
                                <Field
                                    id="order-indication"
                                    label="What it is for"
                                >
                                    <Input
                                        id="order-indication"
                                        value={p.indication}
                                        onChange={(event) =>
                                            setP(
                                                'indication',
                                                event.target.value,
                                            )
                                        }
                                    />
                                </Field>
                                <Field
                                    id="order-instructions"
                                    label="Prescriber’s instructions and monitoring"
                                >
                                    <Textarea
                                        id="order-instructions"
                                        value={p.instructions ?? ''}
                                        onChange={(event) =>
                                            setP(
                                                'instructions',
                                                event.target.value,
                                            )
                                        }
                                    />
                                </Field>
                                <div className="grid gap-3 sm:grid-cols-2">
                                    <Field id="order-start" label="Starts">
                                        <DatePicker
                                            compact
                                            id="order-start"
                                            label="Starts"
                                            value={p.start_date}
                                            onChange={(value) =>
                                                setP('start_date', value)
                                            }
                                        />
                                    </Field>
                                    <Field
                                        id="order-end"
                                        label="Optional last day"
                                    >
                                        <DatePicker
                                            compact
                                            id="order-end"
                                            label="Last day"
                                            value={p.end_date ?? ''}
                                            allowClear
                                            onChange={(value) =>
                                                setP('end_date', value || null)
                                            }
                                        />
                                    </Field>
                                </div>
                                <div className="flex flex-wrap gap-3">
                                    {[
                                        ['high_risk', 'High risk'],
                                        [
                                            'witness_required',
                                            'Witness required',
                                        ],
                                        ...(props.canControlled && !props.order
                                            ? [
                                                  [
                                                      'controlled_drug',
                                                      'Controlled medicine',
                                                  ],
                                              ]
                                            : []),
                                    ].map(([key, label]) => (
                                        <label
                                            key={key}
                                            className="frontline-tap flex items-center gap-2"
                                        >
                                            <Checkbox
                                                checked={
                                                    !!p[key as 'high_risk']
                                                }
                                                onCheckedChange={(value) =>
                                                    setP(
                                                        key as 'high_risk',
                                                        value === true,
                                                    )
                                                }
                                            />
                                            {label}
                                        </label>
                                    ))}
                                </div>
                                {props.order && (
                                    <Field
                                        id="order-change"
                                        label="What the prescriber changed and why"
                                    >
                                        <Textarea
                                            id="order-change"
                                            value={form.data.change_reason}
                                            onChange={(event) =>
                                                form.setData(
                                                    'change_reason',
                                                    event.target.value,
                                                )
                                            }
                                        />
                                    </Field>
                                )}
                            </>
                        )}
                        {spoken && step === 2 && (
                            <>
                                <ReviewCard
                                    icon={Pill}
                                    title="Read back the instruction"
                                >
                                    <ReviewRow
                                        label="Prescriber"
                                        value={form.data.source.prescriber}
                                    />
                                    <ReviewRow
                                        label="Medicine"
                                        value={`${p.name} · ${p.dosage} · ${p.route}`}
                                    />
                                    <ReviewRow
                                        label="When"
                                        value={p.frequency}
                                    />
                                    <ReviewRow
                                        label="Instructions"
                                        value={p.instructions || 'None entered'}
                                    />
                                </ReviewCard>
                                <label className="frontline-tap flex items-center gap-2">
                                    <Checkbox
                                        id="order-readback-confirmed"
                                        checked={
                                            form.data.source.read_back_confirmed
                                        }
                                        onCheckedChange={(value) =>
                                            setSource(
                                                'read_back_confirmed',
                                                value === true,
                                            )
                                        }
                                    />
                                    I read this back to the prescriber and they
                                    agreed.
                                </label>
                                <Field
                                    id="order-witness"
                                    label="Colleague who heard the read-back"
                                >
                                    <RecordPicker
                                        label="Read-back witness"
                                        value={form.data.source.witness_id}
                                        options={witnesses
                                            .filter(
                                                (person) =>
                                                    person.witness_pin ===
                                                    'set',
                                            )
                                            .map((person) => ({
                                                value: String(person.id),
                                                label: person.name,
                                            }))}
                                        onChange={(value) =>
                                            setSource('witness_id', value)
                                        }
                                    />
                                </Field>
                                <WitnessPinInput
                                    id="order-witness-pin"
                                    value={form.data.source.witness_pin}
                                    onChange={(value) =>
                                        setSource('witness_pin', value)
                                    }
                                    error={
                                        form.errors[
                                            'source.witness_pin' as keyof typeof form.errors
                                        ]
                                    }
                                />
                            </>
                        )}
                        {step === steps.length - 1 && (
                            <>
                                <ReviewCard
                                    icon={Pill}
                                    title="Where it came from"
                                    onEdit={() => setStep(0)}
                                >
                                    <ReviewRow
                                        label="Source"
                                        value={form.data.source.type}
                                    />
                                    <ReviewRow
                                        label="Prescriber"
                                        value={form.data.source.prescriber}
                                    />
                                    <ReviewRow
                                        label="Received"
                                        value={
                                            form.data.source.received_at.replace(
                                                'T',
                                                ' · ',
                                            ) + ' · Pacific/Auckland'
                                        }
                                    />
                                    <ReviewRow
                                        label="Attachment"
                                        value={
                                            form.data.source_file?.name ??
                                            (spoken
                                                ? 'Written confirmation follows'
                                                : 'Required')
                                        }
                                    />
                                </ReviewCard>
                                <ReviewCard
                                    icon={Pill}
                                    title="The prescription"
                                    onEdit={() => setStep(1)}
                                >
                                    <ReviewRow
                                        label="Medicine"
                                        value={p.name}
                                    />
                                    <ReviewRow
                                        label="Dose and route"
                                        value={`${p.dosage} · ${p.route}`}
                                    />
                                    <ReviewRow
                                        label="When"
                                        value={`${p.frequency} · ${p.dose_times.join(', ')}`}
                                    />
                                    <ReviewRow
                                        label="What it is for"
                                        value={p.indication}
                                    />
                                    <ReviewRow
                                        label="Instructions"
                                        value={p.instructions || 'None entered'}
                                    />
                                </ReviewCard>
                                <Note>
                                    {props.order
                                        ? `Staff keep giving version ${props.order.version} until this change is independently checked.`
                                        : 'This new order cannot be given until it is checked.'}{' '}
                                    Source evidence and every version are kept.
                                </Note>
                                {props.review?.outcome === 'swap' && (
                                    <>
                                        <Field
                                            id="order-swap-stop"
                                            label={`Prescriber source reason for stopping ${props.review.name_snapshot}`}
                                        >
                                            <Textarea
                                                id="order-swap-stop"
                                                maxLength={255}
                                                value={form.data.stop_reason}
                                                onChange={(event) =>
                                                    form.setData(
                                                        'stop_reason',
                                                        event.target.value,
                                                    )
                                                }
                                            />
                                        </Field>
                                        <Note>
                                            The old order stops immediately when
                                            saved. This separate replacement
                                            remains waiting for its independent
                                            check. Arrange that check before
                                            giving the replacement.
                                        </Note>
                                    </>
                                )}
                            </>
                        )}
                    </div>
                </WizardStepPane>
            </WizardShell>
            {close.confirm}
            <ConfirmDialog
                frontline
                open={confirmSwap}
                onClose={() => setConfirmSwap(false)}
                title={`Stop ${props.review?.name_snapshot} and enter its replacement?`}
                description="The stop takes effect immediately. The replacement cannot be given until its saved version is checked. Both pieces of evidence are linked to the review."
                confirmText="Stop and enter replacement"
                processing={form.processing}
                onConfirm={() => save(true)}
            />
        </>
    );
}
