import ConfirmDialog from '@/components/confirm-dialog';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { StatusBadge } from '@/components/ui/status-badge';
import {
    ReviewCard,
    ReviewRow,
    WizardShell,
    WizardStepPane,
    WizardSuccessPane,
} from '@/components/wizard/shell';
import {
    Check,
    ClipboardCheck,
    ClipboardList,
    Loader2,
    Users,
} from 'lucide-react';
import { useRef, useState } from 'react';
import { ControlledApiError, type ControlledWorkspace } from './product-client';
import type {
    ControlledActionValues,
    ControlledMedicine,
    ControlledProductPayload,
} from './product-types';
import {
    emptyWitness,
    Field,
    focusErrors,
    MedicineContext,
    Notice,
    quantity,
    witnessErrors,
    WitnessField,
} from './product-ui';

interface CountLine {
    first: string;
    recount: string;
    notes: string;
    immediate: string;
}
const blankLine = (): CountLine => ({
    first: '',
    recount: '',
    notes: '',
    immediate: '',
});
export function countLineResult(line: CountLine, balance: number | null) {
    const first = Number(line.first);
    const actual = first !== balance ? Number(line.recount) : first;
    return {
        first,
        actual,
        needsRecount:
            line.first !== '' &&
            Number.isFinite(first) &&
            first >= 0 &&
            first !== balance,
        discrepancy: line.recount !== '' && actual !== balance,
    };
}
export function validateCountLine(
    line: CountLine,
    balance: number | null,
    prefix: string,
): Record<string, string> {
    const errors: Record<string, string> = {};
    if (balance === null)
        return {
            [`${prefix}_actual_balance`]:
                'Balance not configured. Record a witnessed receipt before counting this stock.',
        };
    const result = countLineResult(line, balance);
    if (
        line.first.trim() === '' ||
        !Number.isFinite(result.first) ||
        result.first < 0
    )
        errors[`${prefix}_actual_balance`] = 'Enter a count of 0 or more.';
    if (
        result.needsRecount &&
        (line.recount.trim() === '' ||
            !Number.isFinite(result.actual) ||
            result.actual < 0)
    )
        errors[`${prefix}_recount_balance`] =
            'Count it again before you carry on.';
    if (result.discrepancy) {
        if (!line.notes.trim())
            errors[`${prefix}_notes`] = 'Say what you found.';
        if (!line.immediate.trim())
            errors[`${prefix}_immediate_action_taken`] =
                'Say what you did straight away.';
    }
    return errors;
}
export function CountDialog({
    medicines,
    payload,
    act,
    refresh,
    onClose,
    onRecorded,
}: {
    medicines: ControlledMedicine[];
    payload: ControlledProductPayload;
    act: ControlledWorkspace['act'];
    refresh: ControlledWorkspace['refresh'];
    onClose: () => void;
    onRecorded?: (medicineId: number, countedEntryId: number) => void;
}) {
    const [snapshots, setSnapshots] = useState(() =>
        medicines.map((medicine) => ({ ...medicine })),
    );
    const [lines, setLines] = useState<Record<number, CountLine>>({});
    const [witness, setWitness] = useState(emptyWitness);
    const [step, setStep] = useState(0);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [message, setMessage] = useState('');
    const [saving, setSaving] = useState(false);
    const [uncertain, setUncertain] = useState(false);
    const [done, setDone] = useState(false);
    const [discard, setDiscard] = useState(false);
    const [saved, setSaved] = useState<Record<number, number | null>>({});
    const [discrepancies, setDiscrepancies] = useState<number[]>([]);
    const uuids = useRef<Record<number, string>>({});
    const retryRequests = useRef<Record<number, ControlledActionValues>>({});
    const candidates = (
        payload.witnesses_by_site[String(medicines[0].site_id)] ?? []
    ).filter((candidate) => candidate.id !== payload.current_user_id);
    const dirty =
        Object.values(lines).some(
            (line) => line.first !== '' || line.notes !== '',
        ) || !!witness.id;
    const lineFor = (id: number) => lines[id] ?? blankLine();
    const edit = (id: number, patch: Partial<CountLine>) => {
        setLines((previous) => ({
            ...previous,
            [id]: { ...lineFor(id), ...patch },
        }));
        setErrors({});
    };
    const requestClose = () => {
        if (saving) return;
        if (done || !dirty) onClose();
        else setDiscard(true);
    };
    const title =
        medicines.length > 1 ? 'Shift-change count' : 'Count one medicine';
    const steps = [
        {
            key: 'count',
            label: 'Count',
            blurb: 'Each controlled medicine',
            icon: ClipboardCheck,
        },
        {
            key: 'witness',
            label: 'Witness',
            blurb: 'A colleague and their PIN',
            icon: Users,
        },
        {
            key: 'review',
            label: 'Review & sign',
            blurb: 'Check before recording',
            icon: ClipboardList,
        },
    ];
    const validateCounts = () =>
        Object.assign(
            {},
            ...snapshots
                .filter((medicine) => !(medicine.id in saved))
                .map((medicine) =>
                    validateCountLine(
                        lineFor(medicine.id),
                        medicine.balance,
                        String(medicine.id),
                    ),
                ),
        );
    const next = () => {
        const nextErrors =
            step === 0 ? validateCounts() : witnessErrors(witness, candidates);
        setErrors(nextErrors);
        if (Object.keys(nextErrors).length) {
            focusErrors(nextErrors);
            return;
        }
        setStep(step + 1);
    };
    const save = async () => {
        const counts = validateCounts();
        const witnessProblems = witnessErrors(witness, candidates);
        if (
            !uncertain &&
            (Object.keys(counts).length || Object.keys(witnessProblems).length)
        ) {
            setErrors({ ...counts, ...witnessProblems });
            setStep(Object.keys(counts).length ? 0 : 1);
            focusErrors({ ...counts, ...witnessProblems });
            return;
        }
        setSaving(true);
        setMessage('');
        const recorded = { ...saved };
        const discrepancyIds = [...discrepancies];
        let current: ControlledMedicine | undefined;
        try {
            for (const medicine of snapshots) {
                if (medicine.id in recorded) continue;
                current = medicine;
                const line = lineFor(medicine.id);
                const result = countLineResult(line, medicine.balance);
                uuids.current[medicine.id] ??= crypto.randomUUID();
                const request = retryRequests.current[medicine.id] ?? {
                    client_medication_id: medicine.id,
                    expected_balance: medicine.balance,
                    expected_entry_id: medicine.entry_version,
                    actual_balance: result.first,
                    recount_balance: result.needsRecount ? result.actual : null,
                    notes: line.notes.trim(),
                    immediate_action_taken: line.immediate.trim(),
                    witnessed_by: Number(witness.id),
                    witness_credential: witness.pin,
                };
                retryRequests.current[medicine.id] = request;
                const receipt = await act(
                    'count',
                    request,
                    uuids.current[medicine.id],
                );
                delete retryRequests.current[medicine.id];
                const entryId =
                    receipt.counted_entry_id ?? receipt.entry_id ?? null;
                recorded[medicine.id] = entryId;
                setSaved({ ...recorded });
                if (receipt.discrepancy_id || result.discrepancy) {
                    discrepancyIds.push(medicine.id);
                    setDiscrepancies([...discrepancyIds]);
                }
                if (
                    entryId !== null &&
                    !result.discrepancy &&
                    !receipt.discrepancy_id
                )
                    onRecorded?.(medicine.id, entryId);
            }
            setDone(true);
            setUncertain(false);
            setWitness((previous) => ({ ...previous, pin: '' }));
        } catch (cause) {
            const error =
                cause instanceof ControlledApiError
                    ? cause
                    : new ControlledApiError(
                          'This count was not confirmed. Try again.',
                      );
            setMessage(
                `${Object.keys(recorded).length ? `${Object.keys(recorded).length} medicine count(s) are recorded. The remaining counts are still here. ` : ''}${error.message}`,
            );
            setUncertain(error.saveUncertain);
            if (error.saveUncertain) {
                setStep(2);
            } else {
                if (current) delete retryRequests.current[current.id];
                if (error.status === 409 && current) {
                    const fresh = await fetch('/emar/controlled/product', {
                        headers: { Accept: 'application/json' },
                        credentials: 'same-origin',
                    })
                        .then((response) =>
                            response.ok
                                ? (response.json() as Promise<ControlledProductPayload>)
                                : null,
                        )
                        .catch(() => null);
                    const updated = fresh?.medicines.find(
                        (medicine) => medicine.id === current!.id,
                    );
                    if (updated) {
                        setSnapshots((previous) =>
                            previous.map((medicine) =>
                                medicine.id === updated.id ? updated : medicine,
                            ),
                        );
                        setLines((previous) => ({
                            ...previous,
                            [updated.id]: blankLine(),
                        }));
                        delete uuids.current[updated.id];
                        setMessage(
                            `The register changed while you were counting. Count ${updated.name} again against the current balance of ${quantity(updated.balance, updated.unit)}. ${Object.keys(recorded).length ? 'Already saved counts stay recorded.' : 'Nothing was saved.'}`,
                        );
                    }
                    setStep(0);
                    void refresh();
                } else {
                    setErrors(error.errors);
                    if (
                        'witness_credential' in error.errors ||
                        'witnessed_by' in error.errors
                    ) {
                        setStep(1);
                        setWitness((previous) => ({ ...previous, pin: '' }));
                    }
                }
            }
        } finally {
            setSaving(false);
        }
    };
    return (
        <>
            <WizardShell
                frontline
                open
                onClose={requestClose}
                title={`${title} — ${medicines[0].site_name}`}
                description="Count, choose a witness, then review and record."
                railIcon={ClipboardCheck}
                railTitle={title}
                railSub={`${medicines[0].site_name} · ${medicines.length} medicine(s)`}
                steps={steps.map((item, index) => ({
                    ...item,
                    disabled: saving || uncertain || index > step,
                }))}
                stepIndex={step}
                onStepClick={(index) => {
                    if (!saving && !uncertain && index < step) setStep(index);
                }}
                pct={Math.round(
                    ([
                        ...snapshots.map(
                            (medicine) => lineFor(medicine.id).first !== '',
                        ),
                        !!witness.id,
                        /^\d{6}$/.test(witness.pin),
                    ].filter(Boolean).length /
                        (snapshots.length + 2)) *
                        100,
                )}
                railExtra={
                    <p className="text-caption">
                        Recorded by {payload.current_user_name}. Witness PINs
                        are never stored on this device.
                    </p>
                }
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
                        className="min-h-11"
                        disabled={saving}
                        onClick={step < 2 ? next : () => void save()}
                    >
                        {saving ? (
                            <Loader2 className="size-4 animate-spin motion-reduce:animate-none" />
                        ) : (
                            <Check className="size-4" />
                        )}
                        {saving
                            ? 'Recording…'
                            : step < 2
                              ? 'Continue'
                              : uncertain
                                ? 'Retry the same count'
                                : 'Record count'}
                    </Button>
                }
                success={
                    done ? (
                        <WizardSuccessPane
                            title={
                                discrepancies.length
                                    ? 'Count recorded — discrepancy started'
                                    : 'Count recorded'
                            }
                            blurb={
                                <div className="space-y-3">
                                    <p>
                                        {Object.keys(saved).length} medicine
                                        count(s) confirmed by the server.
                                    </p>
                                    {discrepancies.length ? (
                                        <Notice title="A difference remains after the second count">
                                            A discrepancy is recorded for{' '}
                                            {snapshots
                                                .filter((medicine) =>
                                                    discrepancies.includes(
                                                        medicine.id,
                                                    ),
                                                )
                                                .map(
                                                    (medicine) => medicine.name,
                                                )
                                                .join(', ')}
                                            . It needs an independent follow-up.
                                        </Notice>
                                    ) : (
                                        <p>The counts match the register.</p>
                                    )}
                                </div>
                            }
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
                            <Notice title="Check before continuing" critical>
                                {message}
                            </Notice>
                        ) : null}
                        {step === 0 ? (
                            <>
                                {snapshots.map((medicine) => {
                                    const line = lineFor(medicine.id);
                                    const result = countLineResult(
                                        line,
                                        medicine.balance,
                                    );
                                    const prefix = String(medicine.id);
                                    return (
                                        <Card
                                            key={medicine.id}
                                            className="gap-4 p-4"
                                        >
                                            <MedicineContext
                                                medicine={medicine}
                                            />
                                            {medicine.id in saved ? (
                                                <StatusBadge variant="success">
                                                    Count recorded
                                                </StatusBadge>
                                            ) : (
                                                <>
                                                    <Field
                                                        name={`${prefix}_actual_balance`}
                                                        label={`How many ${medicine.unit} did you count?`}
                                                        required
                                                        numeric
                                                        value={line.first}
                                                        onChange={(first) =>
                                                            edit(medicine.id, {
                                                                first,
                                                                recount: '',
                                                                notes: '',
                                                                immediate: '',
                                                            })
                                                        }
                                                        error={
                                                            errors[
                                                                `${prefix}_actual_balance`
                                                            ] ??
                                                            errors.actual_balance
                                                        }
                                                    />
                                                    {result.needsRecount ? (
                                                        <>
                                                            <Notice title="That differs from the register">
                                                                Count this
                                                                medicine again
                                                                before
                                                                continuing.
                                                            </Notice>
                                                            <Field
                                                                name={`${prefix}_recount_balance`}
                                                                label="Second count"
                                                                required
                                                                numeric
                                                                value={
                                                                    line.recount
                                                                }
                                                                onChange={(
                                                                    recount,
                                                                ) =>
                                                                    edit(
                                                                        medicine.id,
                                                                        {
                                                                            recount,
                                                                        },
                                                                    )
                                                                }
                                                                error={
                                                                    errors[
                                                                        `${prefix}_recount_balance`
                                                                    ] ??
                                                                    errors.recount_balance
                                                                }
                                                            />
                                                        </>
                                                    ) : null}
                                                    {result.discrepancy ? (
                                                        <>
                                                            <Field
                                                                name={`${prefix}_notes`}
                                                                label="What did you find?"
                                                                required
                                                                multiline
                                                                value={
                                                                    line.notes
                                                                }
                                                                onChange={(
                                                                    notes,
                                                                ) =>
                                                                    edit(
                                                                        medicine.id,
                                                                        {
                                                                            notes,
                                                                        },
                                                                    )
                                                                }
                                                                error={
                                                                    errors[
                                                                        `${prefix}_notes`
                                                                    ] ??
                                                                    errors.notes
                                                                }
                                                            />
                                                            <Field
                                                                name={`${prefix}_immediate_action_taken`}
                                                                label="What did you do straight away?"
                                                                required
                                                                multiline
                                                                value={
                                                                    line.immediate
                                                                }
                                                                onChange={(
                                                                    immediate,
                                                                ) =>
                                                                    edit(
                                                                        medicine.id,
                                                                        {
                                                                            immediate,
                                                                        },
                                                                    )
                                                                }
                                                                error={
                                                                    errors[
                                                                        `${prefix}_immediate_action_taken`
                                                                    ] ??
                                                                    errors.immediate_action_taken
                                                                }
                                                            />
                                                            <p className="text-caption">
                                                                Saving starts a
                                                                discrepancy and
                                                                keeps both
                                                                counts. It does
                                                                not close the
                                                                linked incident.
                                                            </p>
                                                        </>
                                                    ) : null}
                                                </>
                                            )}
                                        </Card>
                                    );
                                })}
                                <p className="text-caption">
                                    Count what is physically present. A count of
                                    one medicine does not complete the scheduled
                                    counts for the others.
                                </p>
                            </>
                        ) : step === 1 ? (
                            <WitnessField
                                value={witness}
                                onChange={setWitness}
                                candidates={candidates}
                                errors={errors}
                            />
                        ) : (
                            <>
                                <ReviewCard
                                    title="Counts"
                                    icon={ClipboardCheck}
                                    onEdit={() => setStep(0)}
                                >
                                    {snapshots.map((medicine) => {
                                        const result = countLineResult(
                                            lineFor(medicine.id),
                                            medicine.balance,
                                        );
                                        return (
                                            <ReviewRow
                                                key={medicine.id}
                                                label={`${medicine.name} · ${medicine.client_name}`}
                                                value={
                                                    <div>
                                                        <p>
                                                            {quantity(
                                                                result.actual,
                                                                medicine.unit,
                                                            )}{' '}
                                                            ·{' '}
                                                            {medicine.id in
                                                            saved
                                                                ? 'Recorded'
                                                                : result.discrepancy
                                                                  ? 'Discrepancy starts when recorded'
                                                                  : 'Matches'}
                                                        </p>
                                                        {result.needsRecount ? (
                                                            <p className="text-caption">
                                                                First count{' '}
                                                                {result.first};
                                                                second count{' '}
                                                                {result.actual};
                                                                register{' '}
                                                                {
                                                                    medicine.balance
                                                                }
                                                            </p>
                                                        ) : null}
                                                        {result.discrepancy ? (
                                                            <p className="text-caption">
                                                                {
                                                                    lineFor(
                                                                        medicine.id,
                                                                    ).notes
                                                                }{' '}
                                                                ·{' '}
                                                                {
                                                                    lineFor(
                                                                        medicine.id,
                                                                    ).immediate
                                                                }
                                                            </p>
                                                        ) : null}
                                                    </div>
                                                }
                                            />
                                        );
                                    })}
                                </ReviewCard>
                                <ReviewCard
                                    title="Witness"
                                    icon={Users}
                                    onEdit={() => setStep(1)}
                                >
                                    <ReviewRow
                                        label="Witness"
                                        value={
                                            candidates.find(
                                                (candidate) =>
                                                    String(candidate.id) ===
                                                    witness.id,
                                            )?.name ?? '—'
                                        }
                                    />
                                    <ReviewRow
                                        label="PIN"
                                        value="Checked by the server when recorded"
                                    />
                                    <ReviewRow
                                        label="Recorder"
                                        value={payload.current_user_name}
                                    />
                                </ReviewCard>
                            </>
                        )}
                    </fieldset>
                </WizardStepPane>
            </WizardShell>
            <ConfirmDialog
                frontline
                open={discard}
                onClose={() => setDiscard(false)}
                onConfirm={onClose}
                title="Discard the remaining counts?"
                description={
                    Object.keys(saved).length
                        ? 'Recorded counts stay in the register. Unsaved entries will be lost.'
                        : 'The entered counts will be lost. Any count already accepted by the server stays recorded.'
                }
                confirmText="Discard remaining entries"
                cancelText="Keep counting"
            />
        </>
    );
}
