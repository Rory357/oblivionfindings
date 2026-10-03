import ConfirmDialog from '@/components/confirm-dialog';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { FileDropzone, StagedFileCard } from '@/components/ui/file-dropzone';
import { Label } from '@/components/ui/label';
import {
    ReviewCard,
    ReviewRow,
    WizardShell,
    WizardStepPane,
    WizardSuccessPane,
} from '@/components/wizard/shell';
import { toDatetimeLocal, WORKER_TIMEZONE } from '@/lib/datetime';
import {
    Check,
    ClipboardList,
    FileText,
    Loader2,
    ShieldCheck,
    Users,
} from 'lucide-react';
import { useRef, useState, type ReactNode } from 'react';
import { buildControlledActionValues } from './product-actions';
import { ControlledApiError, type ControlledWorkspace } from './product-client';
import type {
    ControlledAction,
    ControlledActionValues,
    ControlledProductPayload,
    ResolutionOutcome,
} from './product-types';
import {
    emptyWitness,
    Field,
    focusErrors,
    LocalDateTimeField,
    MedicineContext,
    medicineFor,
    medicineLabel,
    Notice,
    Picker,
    quantity,
    Tiles,
    witnessErrors,
    WitnessField,
} from './product-ui';

export interface ActionSpec {
    action: ControlledAction;
    medicineId?: number;
    targetId?: number;
    siteId?: number;
}
const TITLES: Record<ControlledAction, string> = {
    count: 'Count medicine',
    movement: 'Record controlled movement',
    void: 'Void and correct an entry',
    resolve: 'Resolve a discrepancy',
    loss_report: 'Report a loss',
    loss_note: 'Add to the investigation',
    loss_notify: 'Record who has been told',
    loss_close: 'Close a loss',
    destruction: 'Return for destruction',
    destruction_receipt: 'Record the pharmacist’s receipt',
    destruction_void: 'Void a destruction',
    class_review: 'Review the NZ class',
    witness_request: 'Ask someone to witness',
    witness_answer: 'Answer a witness request',
    witness_cancel: 'Cancel a witness request',
    override_request: 'Request a witness override',
    override_decide: 'Decide a witness override',
    override_signoff: 'Sign off follow-up',
};
const OUTCOMES: {
    key: ResolutionOutcome;
    label: string;
    description: string;
}[] = [
    {
        key: 'recount',
        label: 'Recount matched',
        description: 'A counting slip — restore the witnessed balance',
    },
    {
        key: 'recording',
        label: 'Recording error',
        description: 'Void the wrong entry and record a correction',
    },
    {
        key: 'found',
        label: 'Stock found',
        description: 'Record the witnessed stock found',
    },
    {
        key: 'loss',
        label: 'Unexplained loss',
        description: 'Create a linked loss report',
    },
    {
        key: 'escalate',
        label: 'Escalate to a manager',
        description: 'Leave the discrepancy under review',
    },
];
const DESTRUCTION_REASONS = [
    'expired',
    'ceased',
    'contaminated',
    'damaged',
    'deceased',
    'discharged',
    'surplus',
].map((key) => ({
    key,
    label: (
        {
            expired: 'Expired',
            ceased: 'Stopped by the prescriber',
            contaminated: 'Contaminated',
            damaged: 'Damaged',
            deceased: 'Person has died',
            discharged: 'Person has left',
            surplus: 'Surplus stock',
        } as Record<string, string>
    )[key],
    description: 'Record the reason for returning or destroying this stock',
}));
const LABELS: Record<string, string> = {
    authority: 'Who was told',
    reference: 'Notification reference',
    notified_at: 'Told at',
    quantity: 'Amount',
    notes: 'Notes',
    reason: 'Reason',
    actual_balance: 'Physically left',
    recount_balance: 'Second count',
    outcome: 'Outcome',
    immediate_action_taken: 'Immediate action',
    circumstances: 'What happened',
    discovered_at: 'Discovered at',
    police_reference: 'Police event number',
    source: 'Reviewed source',
    review_notes: 'Review notes',
    nz_class: 'NZ class',
    correction_quantity: 'Correct amount',
    correction_direction: 'Correction direction',
    movement_type: 'Movement',
    direction: 'Direction',
    method: 'Method',
    pharmacist_name: 'Pharmacist',
    pharmacist_registration: 'Registration number',
    received_at: 'Received at',
    starts_at: 'Starts at',
    expires_at: 'Ends at',
    decision: 'Decision',
    response: 'Response',
    resolution_notes: 'Finding',
};
export function ActionDialog({
    spec,
    payload,
    act,
    onClose,
}: {
    spec: ActionSpec;
    payload: ControlledProductPayload;
    act: ControlledWorkspace['act'];
    onClose: () => void;
}) {
    const action = spec.action;
    const targetEntry = payload.entries.find(
        (entry) => entry.id === spec.targetId,
    );
    const targetDiscrepancy = payload.discrepancies.find(
        (record) => record.id === spec.targetId,
    );
    const targetLoss = payload.losses.find(
        (record) => record.id === spec.targetId,
    );
    const targetDestruction = payload.destructions.find(
        (record) => record.id === spec.targetId,
    );
    const targetRequest = payload.requests.find(
        (record) => record.id === spec.targetId,
    );
    const targetOverride = payload.overrides.find(
        (record) => record.id === spec.targetId,
    );
    const initialMedicineId =
        spec.medicineId ??
        (action === 'void'
            ? targetEntry?.client_medication_id
            : action === 'resolve'
              ? targetDiscrepancy?.client_medication_id
              : action.startsWith('loss_')
                ? targetLoss?.client_medication_id
                : action.startsWith('destruction_')
                  ? targetDestruction?.client_medication_id
                  : action.startsWith('witness_')
                    ? targetRequest?.client_medication_id
                    : action.startsWith('override_')
                      ? targetOverride?.medicine_ids[0]
                      : undefined);
    const [medicineId, setMedicineId] = useState(
        String(initialMedicineId ?? ''),
    );
    const [snapshot, setSnapshot] = useState(() =>
        initialMedicineId ? medicineFor(payload, initialMedicineId) : undefined,
    );
    const medicine = snapshot ?? medicineFor(payload, Number(medicineId));
    const siteId =
        medicine?.site_id ??
        spec.siteId ??
        targetRequest?.site_id ??
        targetOverride?.site_id ??
        payload.sites[0]?.id;
    const [values, setValues] = useState<Record<string, string>>({
        direction: 'out',
        method: 'pharmacy_return',
        discovered_at: toDatetimeLocal(new Date()),
        received_at: toDatetimeLocal(new Date()),
        starts_at: toDatetimeLocal(new Date()),
        notified_at: toDatetimeLocal(new Date()),
        expires_at: '',
        outcome: '',
        decision: '',
        response: '',
        nz_class: medicine?.nz_class ?? '',
    });
    const [flags, setFlags] = useState({
        police: false,
        regulator: false,
        ready: false,
        checked: false,
        correct: false,
    });
    const [medicineIds, setMedicineIds] = useState<number[]>(
        targetOverride?.medicine_ids ??
            (initialMedicineId ? [initialMedicineId] : []),
    );
    const [witness, setWitness] = useState(emptyWitness);
    const [secondWitness, setSecondWitness] = useState(emptyWitness);
    const [photo, setPhoto] = useState<File | null>(null);
    const [step, setStep] = useState(0);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [message, setMessage] = useState('');
    const [saving, setSaving] = useState(false);
    const [uncertain, setUncertain] = useState(false);
    const [saved, setSaved] = useState(false);
    const [confirm, setConfirm] = useState(false);
    const [discard, setDiscard] = useState(false);
    const uuid = useRef(crypto.randomUUID());
    const retryRequest = useRef<ControlledActionValues | null>(null);
    const title = TITLES[action];
    const needsMedicine = [
        'movement',
        'loss_report',
        'destruction',
        'class_review',
    ].includes(action);
    const needsWitness =
        [
            'movement',
            'void',
            'loss_report',
            'destruction',
            'destruction_void',
        ].includes(action) ||
        (action === 'resolve' && values.outcome !== 'escalate');
    const candidates = (payload.witnesses_by_site[String(siteId)] ?? []).filter(
        (record) => record.id !== payload.current_user_id,
    );
    const stepKeys = [
        ...(action === 'loss_report'
            ? ['details', 'notifications']
            : action === 'resolve'
              ? ['outcome', 'details']
              : ['details']),
        ...(needsWitness ? ['witness'] : []),
        'review',
    ];
    const steps = stepKeys.map((key) => ({
        key,
        label:
            key === 'details'
                ? 'Details'
                : key === 'outcome'
                  ? 'What you found'
                  : key === 'notifications'
                    ? 'Who has been told'
                    : key === 'witness'
                      ? 'Witness'
                      : 'Review & record',
        blurb:
            key === 'review'
                ? 'Check before recording'
                : key === 'witness'
                  ? 'At the medicine cupboard'
                  : 'Required details',
        icon:
            key === 'witness'
                ? Users
                : key === 'review'
                  ? ClipboardList
                  : FileText,
    }));
    const key = stepKeys[step];
    const update = (name: string, value: string) => {
        setValues((previous) => ({ ...previous, [name]: value }));
        setErrors({});
    };
    const field = (
        name: string,
        label: string,
        options: {
            required?: boolean;
            multiline?: boolean;
            numeric?: boolean;
            hint?: string;
        } = {},
    ) => (
        <Field
            name={name}
            label={label}
            value={values[name] ?? ''}
            onChange={(value) => update(name, value)}
            error={
                errors[name] ??
                (name === 'circumstances' ||
                name === 'resolution_notes' ||
                name === 'review_notes' ||
                (name === 'reason' &&
                    [
                        'witness_request',
                        'witness_answer',
                        'override_request',
                    ].includes(action))
                    ? errors.notes
                    : undefined)
            }
            {...options}
            disabled={saving}
        />
    );
    const check = (name: keyof typeof flags, label: string) => (
        <div className="flex min-h-11 items-start gap-3 rounded-lg border p-3">
            <Checkbox
                id={`controlled-${name}`}
                checked={flags[name]}
                onCheckedChange={(value) => {
                    setFlags((previous) => ({
                        ...previous,
                        [name]: value === true,
                    }));
                    setErrors({});
                }}
            />
            <Label
                htmlFor={`controlled-${name}`}
                className="text-sm font-normal"
            >
                {label}
            </Label>
        </div>
    );
    const positive = (name: string, result: Record<string, string>) => {
        if (
            !(Number(values[name]) > 0) ||
            !Number.isFinite(Number(values[name]))
        )
            result[name] = 'Enter an amount greater than 0.';
    };
    const required = (
        name: string,
        result: Record<string, string>,
        hint: string,
    ) => {
        if (!values[name]?.trim()) result[name] = hint;
    };
    const validate = (section: string): Record<string, string> => {
        const result: Record<string, string> = {};
        if (section === 'witness') {
            Object.assign(result, witnessErrors(witness, candidates));
            if (action === 'destruction' && values.method === 'denaturing')
                Object.assign(
                    result,
                    witnessErrors(
                        secondWitness,
                        candidates.filter(
                            (record) => String(record.id) !== witness.id,
                        ),
                        'second',
                    ),
                );
        }
        if (section === 'outcome')
            required('outcome', result, 'Choose what you found.');
        if (section === 'notifications' && flags.police)
            required(
                'police_reference',
                result,
                'Enter the police event number.',
            );
        if (
            (section === 'notifications' ||
                (action === 'resolve' && values.outcome === 'loss')) &&
            flags.regulator
        )
            required(
                'regulator_reference',
                result,
                'Enter the Medicines Control reference.',
            );
        if (section === 'details') {
            if (needsWitness && medicine?.balance === null)
                result.client_medication_id =
                    'Balance not configured. Record a witnessed receipt before changing or counting this stock.';
            if (
                needsMedicine &&
                (!medicine ||
                    (!medicine.can_record && action !== 'class_review'))
            )
                result.client_medication_id =
                    'Choose a permitted medicine you can record.';
            if (['movement', 'loss_report', 'destruction'].includes(action))
                positive('quantity', result);
            if (
                [
                    'void',
                    'destruction',
                    'destruction_void',
                    'override_request',
                    'witness_request',
                ].includes(action)
            )
                required('reason', result, 'Say why this is needed.');
            if (
                [
                    'movement',
                    'void',
                    'destruction_void',
                    'loss_note',
                    'resolve',
                    'override_decide',
                ].includes(action)
            )
                required(
                    'notes',
                    result,
                    'Say what happened or what you found.',
                );
            if (action === 'movement') {
                required('movement_type', result, 'Choose the movement.');
                if (
                    values.actual_balance?.trim() === '' ||
                    values.actual_balance === undefined ||
                    Number(values.actual_balance) < 0 ||
                    !Number.isFinite(Number(values.actual_balance))
                )
                    result.actual_balance =
                        'Enter how much is physically left.';
                if (['breakage', 'spillage'].includes(values.movement_type))
                    required(
                        'immediate_action_taken',
                        result,
                        'Say what you did straight away.',
                    );
                if (
                    medicine?.balance !== null &&
                    medicine?.balance !== undefined &&
                    !result.actual_balance &&
                    !result.quantity &&
                    values.quantity
                ) {
                    const predicted =
                        Math.round(
                            (medicine.balance +
                                Number(values.quantity) *
                                    (values.direction === 'in' ? 1 : -1)) *
                                100,
                        ) / 100;
                    if (Number(values.actual_balance) !== predicted)
                        result.actual_balance =
                            'The physical balance does not match this movement. Record a witnessed count first so the difference is kept as a discrepancy.';
                }
            }
            if (action === 'void' && flags.correct) {
                positive('correction_quantity', result);
                required(
                    'correction_direction',
                    result,
                    'Choose a correction direction.',
                );
            }
            if (action === 'resolve') {
                required('outcome', result, 'Choose what you found.');
                if (
                    values.outcome === 'recount' &&
                    (values.recount_balance?.trim() === '' ||
                        !Number.isFinite(Number(values.recount_balance)) ||
                        Number(values.recount_balance) < 0)
                )
                    result.recount_balance = 'Enter the witnessed recount.';
                if (['found', 'loss'].includes(values.outcome))
                    positive('quantity', result);
                if (values.outcome === 'recording') {
                    required('entry_id', result, 'Choose the wrong entry.');
                    if (flags.correct) {
                        positive('correction_quantity', result);
                        required(
                            'correction_direction',
                            result,
                            'Choose a correction direction.',
                        );
                    }
                }
                if (values.outcome === 'loss') {
                    required('circumstances', result, 'Say what happened.');
                    required(
                        'immediate_action_taken',
                        result,
                        'Say what you did straight away.',
                    );
                    if (flags.police)
                        required(
                            'police_reference',
                            result,
                            'Enter the police event number.',
                        );
                }
            }
            if (action === 'loss_report') {
                required('circumstances', result, 'Say what happened.');
                required(
                    'immediate_action_taken',
                    result,
                    'Say what you did straight away.',
                );
                required(
                    'discovered_at',
                    result,
                    'Choose when the loss was discovered.',
                );
            }
            if (action === 'loss_notify') {
                required('authority', result, 'Choose who has been told.');
                required(
                    'reference',
                    result,
                    'Enter the notification reference.',
                );
                required('notified_at', result, 'Choose when they were told.');
                required(
                    'notes',
                    result,
                    'Record who was told and what was reported.',
                );
            }
            if (action === 'loss_close') {
                required('outcome', result, 'Choose the finding.');
                required('resolution_notes', result, 'Record the finding.');
                if (!flags.checked)
                    result.checked = 'Confirm who needs to know.';
                if (
                    values.outcome === 'theft' &&
                    !targetLoss?.reported_to_police
                )
                    result.outcome =
                        'Record the police event number before closing a theft.';
            }
            if (
                action === 'destruction' &&
                values.method === 'denaturing' &&
                !payload.on_site_destruction_allowed
            )
                result.method = 'On-site destruction is not allowed.';
            if (action === 'destruction_receipt') {
                required(
                    'pharmacist_name',
                    result,
                    'Enter the pharmacist’s name.',
                );
                required(
                    'pharmacist_registration',
                    result,
                    'Enter their registration number.',
                );
                required(
                    'received_at',
                    result,
                    'Choose when the pharmacy received it.',
                );
            }
            if (action === 'class_review') {
                required('nz_class', result, 'Choose the reviewed NZ class.');
                required('source', result, 'Record the reviewed source.');
                required('review_notes', result, 'Explain the class review.');
            }
            if (action === 'witness_request')
                required(
                    'witnessed_by',
                    result,
                    'Choose the colleague you are asking.',
                );
            if (action === 'witness_answer') {
                required('response', result, 'Choose your answer.');
                if (values.response === 'cant_come')
                    required('reason', result, 'Say why you can’t come now.');
            }
            if (
                ['override_request', 'override_decide'].includes(action) &&
                (action === 'override_request' ||
                    values.decision === 'approved') &&
                !medicineIds.length
            )
                result.medicine_ids = 'Choose the medicines covered.';
            if (action === 'override_decide') {
                required('decision', result, 'Choose approve or decline.');
                if (values.decision === 'approved') {
                    required('starts_at', result, 'Choose a start time.');
                    required('expires_at', result, 'Choose an end time.');
                }
            }
        }
        return result;
    };
    const next = () => {
        const result = validate(key);
        setErrors(result);
        if (Object.keys(result).length) {
            focusErrors(result);
            return;
        }
        setStep(step + 1);
    };
    const requestClose = () => {
        if (!saving) {
            if (saved) onClose();
            else setDiscard(true);
        }
    };
    const submit = async () => {
        const invalid = stepKeys
            .slice(0, -1)
            .map((section) => ({ section, result: validate(section) }))
            .find((section) => Object.keys(section.result).length);
        if (!uncertain && invalid) {
            setStep(stepKeys.indexOf(invalid.section));
            setErrors(invalid.result);
            focusErrors(invalid.result);
            return;
        }
        setSaving(true);
        setMessage('');
        setConfirm(false);
        const request =
            retryRequest.current ??
            buildControlledActionValues({
                action,
                medicine,
                targetId: spec.targetId,
                values,
                flags,
                medicineIds,
                witness,
                secondWitness,
                needsWitness,
                photo,
            });
        retryRequest.current = request;
        try {
            const result = await act(action, request, uuid.current);
            retryRequest.current = null;
            setUncertain(false);
            setMessage(result.message ?? 'The server confirmed this record.');
            setSaved(true);
            setWitness(emptyWitness());
            setSecondWitness(emptyWitness());
        } catch (cause) {
            const error =
                cause instanceof ControlledApiError
                    ? cause
                    : new ControlledApiError(
                          'This was not confirmed. Try again.',
                      );
            setMessage(error.message);
            setErrors(error.errors);
            setUncertain(error.saveUncertain);
            if (error.saveUncertain) {
                setStep(stepKeys.indexOf('review'));
            } else {
                retryRequest.current = null;
                if (
                    Object.keys(error.errors).some((name) =>
                        name.includes('witness'),
                    )
                ) {
                    setStep(Math.max(0, stepKeys.indexOf('witness')));
                    setWitness((previous) => ({ ...previous, pin: '' }));
                    setSecondWitness((previous) => ({ ...previous, pin: '' }));
                } else {
                    setStep(0);
                    if (error.status === 409)
                        setMessage(
                            `${error.message} Close this draft and reopen the action against the current register before recording.`,
                        );
                }
                focusErrors(error.errors);
            }
        } finally {
            setSaving(false);
        }
    };
    const notifications = (
        <div className="space-y-4">
            <p className="text-sm">
                Record notifications already made. This does not contact the
                police or Medicines Control.
            </p>
            {check('police', 'The police have been told')}
            {flags.police
                ? field('police_reference', 'Police event number', {
                      required: true,
                  })
                : null}
            {check('regulator', 'Medicines Control has been told')}
            {flags.regulator
                ? field('regulator_reference', 'Medicines Control reference', {
                      required: true,
                  })
                : null}
        </div>
    );
    const correction = (
        <div className="space-y-4">
            {check('correct', 'A correcting entry is needed')}
            {flags.correct ? (
                <>
                    {field('correction_quantity', 'Correct amount', {
                        required: true,
                        numeric: true,
                    })}
                    <Tiles
                        name="correction_direction"
                        label="The correcting entry"
                        value={values.correction_direction ?? ''}
                        onChange={(value) =>
                            update('correction_direction', value)
                        }
                        error={errors.correction_direction}
                        options={[
                            {
                                key: 'in',
                                label: 'Stock in',
                                description: 'Adds this amount',
                            },
                            {
                                key: 'out',
                                label: 'Stock out',
                                description: 'Removes this amount',
                            },
                        ]}
                    />
                </>
            ) : (
                <p className="text-caption">
                    No correction will be added. The void itself reverses the
                    original entry.
                </p>
            )}
        </div>
    );
    const medicineSelection =
        needsMedicine && !initialMedicineId ? (
            <Picker
                id="client_medication_id"
                label="Controlled medicine"
                required
                value={medicineId}
                onChange={(id) => {
                    setMedicineId(id);
                    setSnapshot(medicineFor(payload, Number(id)));
                    setWitness(emptyWitness());
                    setSecondWitness(emptyWitness());
                }}
                options={payload.medicines.map((record) => ({
                    id: String(record.id),
                    name: `${record.name} · ${record.client_name}`,
                    description: `${record.site_name} · ${quantity(record.balance, record.unit)}`,
                    disabled:
                        !record.can_record && action !== 'class_review'
                            ? (record.record_reason ??
                              'You cannot record at this house')
                            : null,
                }))}
                error={errors.client_medication_id}
            />
        ) : null;
    const coveredMedicines = (
        <fieldset className="space-y-2">
            <legend className="text-sm font-medium">
                Medicines covered{' '}
                <span className="text-status-critical">*</span>
            </legend>
            {payload.medicines
                .filter((record) => record.site_id === siteId)
                .map((record) => (
                    <div
                        key={record.id}
                        className="flex min-h-11 items-center gap-3 rounded-lg border p-3"
                    >
                        <Checkbox
                            id={`controlled-med-${record.id}`}
                            checked={medicineIds.includes(record.id)}
                            onCheckedChange={(checked) =>
                                setMedicineIds((previous) =>
                                    checked
                                        ? [...previous, record.id]
                                        : previous.filter(
                                              (id) => id !== record.id,
                                          ),
                                )
                            }
                        />
                        <Label htmlFor={`controlled-med-${record.id}`}>
                            {record.name} · {record.client_name}
                        </Label>
                    </div>
                ))}
            {errors.medicine_ids ? (
                <p role="alert" className="text-caption text-status-critical">
                    {errors.medicine_ids}
                </p>
            ) : null}
        </fieldset>
    );
    let details: ReactNode;
    if (action === 'movement')
        details = (
            <>
                <Tiles
                    name="movement_type"
                    label="What is happening?"
                    value={values.movement_type ?? ''}
                    onChange={(value) => {
                        update('movement_type', value);
                        update(
                            'direction',
                            value === 'coming_back' ? 'in' : 'out',
                        );
                    }}
                    error={errors.movement_type}
                    options={[
                        {
                            key: 'going_out',
                            label: 'Going out',
                            description: 'Leaves the cupboard temporarily',
                        },
                        {
                            key: 'coming_back',
                            label: 'Coming back',
                            description: 'Returns to the cupboard',
                        },
                        {
                            key: 'breakage',
                            label: 'Breakage',
                            description: 'Stock lost through breakage',
                        },
                        {
                            key: 'spillage',
                            label: 'Spillage',
                            description: 'Stock lost through spillage',
                        },
                    ]}
                />
                {field('quantity', 'Amount', { required: true, numeric: true })}
                {field('notes', 'What happened?', {
                    required: true,
                    multiline: true,
                })}
                {['breakage', 'spillage'].includes(values.movement_type)
                    ? field(
                          'immediate_action_taken',
                          'What did you do straight away?',
                          { required: true, multiline: true },
                      )
                    : null}
                {field(
                    'actual_balance',
                    'How much is physically left in the cupboard?',
                    {
                        required: true,
                        numeric: true,
                        hint:
                            medicine &&
                            medicine.balance !== null &&
                            values.quantity
                                ? `The movement would leave ${quantity(medicine.balance + Number(values.quantity) * (values.direction === 'in' ? 1 : -1), medicine.unit)}.`
                                : undefined,
                    },
                )}
            </>
        );
    else if (action === 'void')
        details = (
            <>
                <Notice title="The original stays in the register">
                    The entry is reversed and remains visible with the reason,
                    recorder and witness. A correcting entry follows if needed.
                    Counts are not voided.
                </Notice>
                <Tiles
                    name="reason"
                    label="What was wrong?"
                    value={values.reason ?? ''}
                    onChange={(value) => update('reason', value)}
                    error={errors.reason}
                    options={[
                        {
                            key: 'Wrong amount',
                            label: 'Wrong amount',
                            description: 'Record the correct amount',
                        },
                        {
                            key: 'Wrong medicine or person',
                            label: 'Wrong medicine or person',
                            description: 'Keep the reason linked to this entry',
                        },
                        {
                            key: 'Recorded twice',
                            label: 'Recorded twice',
                            description: 'No correcting entry needed',
                        },
                        {
                            key: 'Other',
                            label: 'Other',
                            description: 'Explain what happened',
                        },
                    ]}
                />
                {field('notes', 'What happened?', {
                    required: true,
                    multiline: true,
                })}
                {values.reason === 'Wrong medicine or person' ? (
                    <Notice title="Check the medicine being corrected">
                        Voiding restores this medicine’s register. Any
                        correcting entry here also belongs to this medicine. If
                        the stock change belongs to another medicine or person,
                        record a separate witnessed movement in their register
                        and reference entry {targetEntry?.id ?? spec.targetId}{' '}
                        in its notes.
                    </Notice>
                ) : null}
                {correction}
            </>
        );
    else if (action === 'resolve')
        details = (
            <>
                <Notice title="Independent resolution">
                    The person who counted or witnessed the original count
                    cannot resolve this discrepancy. Resolving it does not close
                    the linked incident.
                </Notice>
                {values.outcome === 'recount'
                    ? field('recount_balance', 'Witnessed recount', {
                          required: true,
                          numeric: true,
                          hint: `Original expected balance: ${targetDiscrepancy?.expected_balance ?? '—'}`,
                      })
                    : null}
                {['found', 'loss'].includes(values.outcome)
                    ? field(
                          'quantity',
                          values.outcome === 'found'
                              ? 'Amount found'
                              : 'Amount missing',
                          { required: true, numeric: true },
                      )
                    : null}
                {values.outcome === 'recording' ? (
                    <>
                        <Picker
                            id="entry_id"
                            label="Wrong entry"
                            required
                            value={values.entry_id ?? ''}
                            onChange={(value) => update('entry_id', value)}
                            error={errors.entry_id}
                            options={payload.entries
                                .filter(
                                    (entry) =>
                                        entry.client_medication_id ===
                                            medicine?.id &&
                                        !entry.voided_at &&
                                        !['count', 'balance_check'].includes(
                                            entry.entry_type,
                                        ),
                                )
                                .map((entry) => ({
                                    id: String(entry.id),
                                    name: `${entry.entry_type.replace(/_/g, ' ')} · entry ${entry.id}`,
                                    description: `Quantity ${entry.quantity} · ${entry.recorded_by_name ?? '—'}`,
                                }))}
                        />
                        {correction}
                    </>
                ) : null}
                {values.outcome === 'loss' ? (
                    <>
                        {field('circumstances', 'What happened?', {
                            required: true,
                            multiline: true,
                        })}
                        {field(
                            'immediate_action_taken',
                            'What did you do straight away?',
                            { required: true, multiline: true },
                        )}
                        {notifications}
                    </>
                ) : null}
                {field(
                    'notes',
                    values.outcome === 'escalate'
                        ? 'Why does a manager need to review this?'
                        : 'What did you find?',
                    { required: true, multiline: true },
                )}
            </>
        );
    else if (action === 'loss_report')
        details = (
            <>
                {field('quantity', 'Amount missing', {
                    required: true,
                    numeric: true,
                })}
                <LocalDateTimeField
                    name="discovered_at"
                    label="When was it discovered?"
                    value={values.discovered_at}
                    onChange={(value) => update('discovered_at', value)}
                    error={errors.discovered_at}
                />
                {field('circumstances', 'What happened?', {
                    required: true,
                    multiline: true,
                })}
                {field(
                    'immediate_action_taken',
                    'What did you do straight away?',
                    { required: true, multiline: true },
                )}
            </>
        );
    else if (action === 'loss_note')
        details = (
            <>
                {field('notes', 'Add to the investigation', {
                    required: true,
                    multiline: true,
                })}
                <p className="text-caption">
                    Earlier investigation notes are kept unchanged.
                </p>
                {check(
                    'ready',
                    'The investigation is ready for a manager to close',
                )}
            </>
        );
    else if (action === 'loss_notify')
        details = (
            <>
                <Tiles
                    name="authority"
                    label="Who has been told?"
                    value={values.authority ?? ''}
                    onChange={(value) => update('authority', value)}
                    error={errors.authority}
                    options={[
                        {
                            key: 'police',
                            label: 'Police',
                            description: 'Record the police event number',
                        },
                        {
                            key: 'regulator',
                            label: 'Medicines Control',
                            description: 'Record their notification reference',
                        },
                        {
                            key: 'pharmacy',
                            label: 'Pharmacy',
                            description:
                                'Record the pharmacy name and reference',
                        },
                    ]}
                />
                {field(
                    'reference',
                    values.authority === 'police'
                        ? 'Police event number'
                        : 'Notification reference',
                    { required: true },
                )}
                <LocalDateTimeField
                    name="notified_at"
                    label="When were they told?"
                    value={values.notified_at}
                    onChange={(value) => update('notified_at', value)}
                    error={errors.notified_at}
                />
                {field('notes', 'Who was told and what was reported?', {
                    required: true,
                    multiline: true,
                })}
                <p className="text-caption">
                    This records a notification already made. It does not
                    contact the organisation.
                </p>
            </>
        );
    else if (action === 'loss_close')
        details = (
            <>
                <Tiles
                    name="outcome"
                    label="Finding"
                    value={values.outcome ?? ''}
                    onChange={(value) => update('outcome', value)}
                    error={errors.outcome}
                    options={[
                        {
                            key: 'accidental',
                            label: 'Accidental',
                            description: 'The loss is explained',
                        },
                        {
                            key: 'unexplained',
                            label: 'Not explained',
                            description: 'Could not be accounted for',
                        },
                        {
                            key: 'theft',
                            label: 'Theft',
                            description: 'Police notification must be recorded',
                        },
                    ]}
                />
                {field('resolution_notes', 'Record the finding', {
                    required: true,
                    multiline: true,
                })}
                {check(
                    'checked',
                    'I have checked who needs to know and the recorded notifications',
                )}
                {errors.checked ? (
                    <p
                        role="alert"
                        className="text-caption text-status-critical"
                    >
                        {errors.checked}
                    </p>
                ) : null}
                <p className="text-caption">
                    Closing the loss retains the register entry and
                    investigation. Medication errors manages the linked
                    incident.
                </p>
            </>
        );
    else if (action === 'destruction')
        details = (
            <>
                {field('quantity', 'Amount being returned or destroyed', {
                    required: true,
                    numeric: true,
                })}
                <Tiles
                    name="reason"
                    label="Why is it leaving?"
                    value={values.reason ?? ''}
                    options={DESTRUCTION_REASONS}
                    onChange={(value) => update('reason', value)}
                    error={errors.reason}
                />
                <Tiles
                    name="method"
                    label="How?"
                    value={values.method}
                    onChange={(value) => update('method', value)}
                    error={errors.method}
                    options={[
                        {
                            key: 'pharmacy_return',
                            label: 'Return to the pharmacy',
                            description: 'Wait for the pharmacist’s receipt',
                        },
                        {
                            key: 'denaturing',
                            label: 'Denature on site',
                            description: 'Two independent witnesses',
                            disabled: payload.on_site_destruction_allowed
                                ? null
                                : 'Your organisation does not allow on-site destruction',
                        },
                    ]}
                />
                {field('notes', 'Notes (optional)', { multiline: true })}
                <div className="space-y-2">
                    <Label id="controlled-photo-label">Photo (optional)</Label>
                    <FileDropzone
                        id="controlled-photo"
                        aria-labelledby="controlled-photo-label"
                        onFiles={(files) => {
                            const candidate = files[0];
                            if (!candidate) return;
                            if (
                                ![
                                    'image/jpeg',
                                    'image/png',
                                    'image/webp',
                                ].includes(candidate.type) ||
                                candidate.size > 5 * 1024 * 1024
                            ) {
                                setErrors({
                                    photo: 'Choose a JPEG, PNG or WebP image up to 5 MB.',
                                });
                                return;
                            }
                            setPhoto(candidate);
                            setErrors({});
                        }}
                        accept="image/jpeg,image/png,image/webp"
                        multiple={false}
                        title="Add a photo of the return or destruction"
                        hint="JPEG, PNG or WebP · up to 5 MB · saved with this record"
                    />
                    {photo ? (
                        <StagedFileCard
                            file={photo}
                            onRemove={() => setPhoto(null)}
                        />
                    ) : null}
                    {errors.photo ? (
                        <p
                            role="alert"
                            className="text-caption text-status-critical"
                        >
                            {errors.photo}
                        </p>
                    ) : null}
                </div>
            </>
        );
    else if (action === 'destruction_receipt')
        details = (
            <>
                {field('pharmacist_name', 'Pharmacist’s name', {
                    required: true,
                })}
                {field(
                    'pharmacist_registration',
                    'Pharmacist’s registration number',
                    { required: true },
                )}
                <LocalDateTimeField
                    name="received_at"
                    label="Received by the pharmacy"
                    value={values.received_at}
                    onChange={(value) => update('received_at', value)}
                    error={errors.received_at}
                />
                {field('notes', 'Receipt reference or notes (optional)', {
                    multiline: true,
                })}
            </>
        );
    else if (action === 'destruction_void')
        details = (
            <>
                <Notice title="This reverses the destruction entry">
                    The original record stays visible with the reason and
                    witness. Use this for a wrongly recorded destruction or
                    return.
                </Notice>
                {field('reason', 'Why is this record wrong?', {
                    required: true,
                    multiline: true,
                })}
                {field('notes', 'What happened?', {
                    required: true,
                    multiline: true,
                })}
            </>
        );
    else if (action === 'class_review')
        details = (
            <>
                <Notice title="Use a reviewed NZ source">
                    Legacy schedule values are flagged for review. They are not
                    automatically mapped to NZ classes.
                </Notice>
                <Tiles
                    name="nz_class"
                    label="Reviewed NZ class"
                    value={values.nz_class}
                    onChange={(value) => update('nz_class', value)}
                    error={errors.nz_class}
                    options={['A', 'B', 'C'].map((className) => ({
                        key: className,
                        label: `Class ${className}`,
                        description: 'Choose only after reviewing the source',
                    }))}
                />
                {field('source', 'Reviewed source', { required: true })}
                {field('review_notes', 'Class review notes', {
                    required: true,
                    multiline: true,
                })}
            </>
        );
    else if (action === 'witness_request')
        details = (
            <>
                <Picker
                    id="witnessed_by"
                    label="Colleague to ask"
                    required
                    value={values.witnessed_by ?? ''}
                    onChange={(value) => update('witnessed_by', value)}
                    options={candidates.map((record) => ({
                        id: String(record.id),
                        name: record.name,
                        description: record.reason ?? 'Eligible witness',
                        disabled:
                            record.eligible && record.pin_status === 'set'
                                ? null
                                : (record.reason ?? 'Not eligible to witness'),
                    }))}
                    error={errors.witnessed_by}
                />
                {field('reason', 'What do you need a witness for?', {
                    required: true,
                    multiline: true,
                })}
                <p className="text-caption">
                    A reply confirms their availability. The witnessed action
                    still needs their PIN at the cupboard.
                </p>
            </>
        );
    else if (action === 'witness_answer')
        details = (
            <>
                <Tiles
                    name="response"
                    label="Can you come to witness?"
                    value={values.response}
                    onChange={(value) => update('response', value)}
                    error={errors.response}
                    options={[
                        {
                            key: 'on_my_way',
                            label: 'On my way',
                            description: 'I can come now',
                        },
                        {
                            key: 'cant_come',
                            label: 'Can’t come now',
                            description: 'Let the requester know why',
                        },
                    ]}
                />
                {values.response === 'cant_come'
                    ? field('reason', 'Why can’t you come now?', {
                          required: true,
                          multiline: true,
                      })
                    : null}
            </>
        );
    else if (action === 'witness_cancel')
        details = (
            <p className="text-sm">
                The request to {targetRequest?.witness_name ?? 'your colleague'}{' '}
                will be cancelled. No witnessed medication action is changed.
            </p>
        );
    else if (action === 'override_request')
        details = (
            <>
                <Notice title="An override covers doses, not register checks">
                    Counts and register changes still need a witness. A lead
                    must later count the affected medicines with a witness and
                    sign off each dose.
                </Notice>
                {coveredMedicines}
                {field('reason', 'Why is no eligible witness available?', {
                    required: true,
                    multiline: true,
                })}
                {field('notes', 'What have you tried? (optional)', {
                    multiline: true,
                })}
            </>
        );
    else if (action === 'override_decide')
        details = (
            <>
                <Notice title="Review the requested coverage">
                    {targetOverride?.reason}
                </Notice>
                <Tiles
                    name="decision"
                    label="Decision"
                    value={values.decision}
                    onChange={(value) => update('decision', value)}
                    error={errors.decision}
                    options={[
                        {
                            key: 'approved',
                            label: 'Approve',
                            description:
                                'Set explicit medicine and time coverage',
                        },
                        {
                            key: 'declined',
                            label: 'Decline',
                            description: 'Explain the next action',
                        },
                    ]}
                />
                {values.decision === 'approved' ? (
                    <>
                        {coveredMedicines}
                        <LocalDateTimeField
                            name="starts_at"
                            label="Starts at"
                            value={values.starts_at}
                            onChange={(value) => update('starts_at', value)}
                            error={errors.starts_at}
                        />
                        <LocalDateTimeField
                            name="expires_at"
                            label="Ends at"
                            value={values.expires_at}
                            onChange={(value) => update('expires_at', value)}
                            error={errors.expires_at}
                        />
                    </>
                ) : null}
                {field('notes', 'Decision notes and next action', {
                    required: true,
                    multiline: true,
                })}
                <p className="text-caption">
                    Approval requires a witnessed count and sign-off for every
                    dose given without a witness.
                </p>
            </>
        );
    else details = <p>Open the follow-up workflow to sign off each dose.</p>;
    const allowedFields: Record<string, string[]> = {
        movement: [
            'movement_type',
            'direction',
            'quantity',
            'notes',
            'immediate_action_taken',
            'actual_balance',
        ],
        void: [
            'reason',
            'notes',
            'correction_quantity',
            'correction_direction',
        ],
        resolve: [
            'outcome',
            'quantity',
            'recount_balance',
            'notes',
            'circumstances',
            'immediate_action_taken',
            'correction_quantity',
            'correction_direction',
        ],
        loss_report: [
            'quantity',
            'discovered_at',
            'circumstances',
            'immediate_action_taken',
        ],
        loss_note: ['notes'],
        loss_notify: ['authority', 'reference', 'notified_at', 'notes'],
        loss_close: ['outcome', 'resolution_notes'],
        destruction: ['quantity', 'reason', 'method', 'notes'],
        destruction_receipt: [
            'pharmacist_name',
            'pharmacist_registration',
            'received_at',
            'notes',
        ],
        destruction_void: ['reason', 'notes'],
        class_review: ['nz_class', 'source', 'review_notes'],
        witness_request: ['reason'],
        witness_answer: ['response', 'reason'],
        override_request: ['reason', 'notes'],
        override_decide: ['decision', 'starts_at', 'expires_at', 'notes'],
    };
    const review = (
        <>
            <ReviewCard
                title="Record"
                icon={FileText}
                onEdit={() => setStep(0)}
            >
                {medicine ? (
                    <ReviewRow
                        label="Medicine"
                        value={`${medicine.name} · ${medicine.client_name}`}
                    />
                ) : null}
                {action === 'destruction' && photo ? (
                    <ReviewRow label="Photo" value={photo.name} />
                ) : null}
                <ReviewRow label="Action" value={title} />
                {(allowedFields[action] ?? [])
                    .filter(
                        (name) =>
                            !!values[name] &&
                            (!name.startsWith('correction_') || flags.correct),
                    )
                    .map((name) => (
                        <ReviewRow
                            key={name}
                            label={LABELS[name]}
                            value={values[name].replace(/_/g, ' ')}
                        />
                    ))}
                {action === 'resolve' && values.entry_id ? (
                    <ReviewRow
                        label="Wrong entry"
                        value={`Entry ${values.entry_id}`}
                    />
                ) : null}
                {action === 'witness_request' ? (
                    <ReviewRow
                        label="Colleague"
                        value={
                            candidates.find(
                                (record) =>
                                    String(record.id) === values.witnessed_by,
                            )?.name ?? '—'
                        }
                    />
                ) : null}
                {['override_request', 'override_decide'].includes(action) ? (
                    <ReviewRow
                        label="Medicines covered"
                        value={medicineIds
                            .map((id) => medicineLabel(payload, id))
                            .join('; ')}
                    />
                ) : null}
                {['loss_report'].includes(action) ||
                (action === 'resolve' && values.outcome === 'loss') ? (
                    <>
                        <ReviewRow
                            label="Police told"
                            value={
                                flags.police
                                    ? `Yes · ${values.police_reference}`
                                    : 'No new notification recorded'
                            }
                        />
                        <ReviewRow
                            label="Medicines Control told"
                            value={
                                flags.regulator
                                    ? 'Yes'
                                    : 'No new notification recorded'
                            }
                        />
                    </>
                ) : null}
            </ReviewCard>
            {needsWitness ? (
                <ReviewCard
                    title="Witness"
                    icon={Users}
                    onEdit={() => setStep(stepKeys.indexOf('witness'))}
                >
                    <ReviewRow
                        label="Witness"
                        value={
                            candidates.find(
                                (record) => String(record.id) === witness.id,
                            )?.name ?? '—'
                        }
                    />
                    {action === 'destruction' &&
                    values.method === 'denaturing' ? (
                        <ReviewRow
                            label="Second witness"
                            value={
                                candidates.find(
                                    (record) =>
                                        String(record.id) === secondWitness.id,
                                )?.name ?? '—'
                            }
                        />
                    ) : null}
                    <ReviewRow
                        label="PIN"
                        value="Checked by the server when recorded"
                    />
                </ReviewCard>
            ) : null}
            <p className="text-caption">
                Recorded as {payload.current_user_name}. Timezone:{' '}
                {WORKER_TIMEZONE}.
            </p>
        </>
    );
    const guarded = [
        'void',
        'destruction_void',
        'loss_close',
        'override_decide',
        'witness_cancel',
    ].includes(action);
    return (
        <>
            <WizardShell
                open
                onClose={requestClose}
                title={title}
                description="Enter the required details, review them, then record."
                railIcon={ShieldCheck}
                railTitle={title}
                railSub={
                    medicine?.site_name ??
                    payload.sites.find((site) => site.id === siteId)?.name ??
                    'Controlled medicines'
                }
                steps={steps.map((item, index) => ({
                    ...item,
                    disabled: saving || uncertain || index > step,
                }))}
                stepIndex={Math.min(step, steps.length - 1)}
                onStepClick={(index) => {
                    if (!saving && !uncertain && index < step) setStep(index);
                }}
                pct={Math.round((step / Math.max(1, steps.length - 1)) * 100)}
                footerStart={
                    <Button
                        variant="outline"
                        className="min-h-11"
                        disabled={saving || uncertain}
                        onClick={
                            step > 0 ? () => setStep(step - 1) : requestClose
                        }
                    >
                        {step > 0 ? 'Back' : 'Cancel'}
                    </Button>
                }
                footerEnd={
                    <Button
                        className="min-h-11 max-w-full whitespace-normal"
                        variant={
                            ['void', 'destruction_void'].includes(action) &&
                            key === 'review'
                                ? 'destructive'
                                : 'default'
                        }
                        disabled={saving}
                        onClick={
                            key === 'review'
                                ? guarded
                                    ? () => setConfirm(true)
                                    : () => void submit()
                                : next
                        }
                    >
                        {saving ? (
                            <Loader2 className="size-4 animate-spin motion-reduce:animate-none" />
                        ) : (
                            <Check className="size-4" />
                        )}
                        {saving
                            ? 'Recording…'
                            : key === 'review'
                              ? uncertain
                                  ? 'Retry the same record'
                                  : title
                              : 'Continue'}
                    </Button>
                }
                success={
                    saved ? (
                        <WizardSuccessPane
                            title="Recorded"
                            blurb={message}
                            actions={
                                <Button className="min-h-11" onClick={onClose}>
                                    Done
                                </Button>
                            }
                        />
                    ) : undefined
                }
            >
                <WizardStepPane>
                    <fieldset
                        disabled={saving || uncertain}
                        className="min-w-0 space-y-5"
                    >
                        {message ? (
                            <Notice
                                title="This action needs your attention"
                                critical
                            >
                                {message}
                            </Notice>
                        ) : null}
                        {medicine ? (
                            <MedicineContext medicine={medicine} />
                        ) : null}
                        {key === 'witness' ? (
                            <>
                                <WitnessField
                                    value={witness}
                                    onChange={setWitness}
                                    candidates={candidates}
                                    errors={errors}
                                />
                                {action === 'destruction' &&
                                values.method === 'denaturing' ? (
                                    <WitnessField
                                        value={secondWitness}
                                        onChange={setSecondWitness}
                                        candidates={candidates}
                                        errors={errors}
                                        prefix="second"
                                        excludeId={witness.id}
                                    />
                                ) : null}
                            </>
                        ) : key === 'review' ? (
                            review
                        ) : key === 'notifications' ? (
                            notifications
                        ) : key === 'outcome' ? (
                            <Tiles
                                name="outcome"
                                label="What did you find?"
                                value={
                                    (values.outcome ?? '') as
                                        | ResolutionOutcome
                                        | ''
                                }
                                onChange={(value) => update('outcome', value)}
                                options={OUTCOMES}
                                error={errors.outcome}
                            />
                        ) : (
                            <>
                                {medicineSelection}
                                {details}
                            </>
                        )}
                        {errors.notifications ? (
                            <p
                                role="alert"
                                className="text-caption text-status-critical"
                            >
                                {errors.notifications}
                            </p>
                        ) : null}
                    </fieldset>
                </WizardStepPane>
            </WizardShell>
            <ConfirmDialog
                open={confirm}
                onClose={() => setConfirm(false)}
                onConfirm={() => void submit()}
                title={`${title}?`}
                description="This records the reviewed action and keeps its audit history. Check the details before continuing."
                confirmText={title}
                variant={
                    ['void', 'destruction_void'].includes(action)
                        ? 'destructive'
                        : 'default'
                }
                processing={saving}
            />
            <ConfirmDialog
                open={discard}
                onClose={() => setDiscard(false)}
                onConfirm={onClose}
                title="Discard this draft?"
                description="The entered details will be lost. Any action already accepted by the server stays recorded."
                confirmText="Discard draft"
                cancelText="Keep editing"
            />
        </>
    );
}
