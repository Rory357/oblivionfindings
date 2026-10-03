import ConfirmDialog from '@/components/confirm-dialog';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
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
    FileText,
    Loader2,
    ShieldCheck,
} from 'lucide-react';
import { useRef, useState } from 'react';
import { CountDialog } from './count-dialog';
import { ControlledApiError, type ControlledWorkspace } from './product-client';
import type {
    ControlledActionValues,
    ControlledOverride,
    ControlledProductPayload,
} from './product-types';
import {
    dateTime,
    Field,
    medicineFor,
    medicineLabel,
    Notice,
} from './product-ui';

export function OverrideFollowupDialog({
    override,
    payload,
    act,
    refresh,
    onClose,
}: {
    override: ControlledOverride;
    payload: ControlledProductPayload;
    act: ControlledWorkspace['act'];
    refresh: ControlledWorkspace['refresh'];
    onClose: () => void;
}) {
    const [step, setStep] = useState(0);
    const [counting, setCounting] = useState(false);
    const [counts, setCounts] = useState<Record<number, number>>({});
    const [checked, setChecked] = useState<number[]>([]);
    const [signed, setSigned] = useState<number[]>([]);
    const [notes, setNotes] = useState('');
    const [message, setMessage] = useState('');
    const [saving, setSaving] = useState(false);
    const [uncertain, setUncertain] = useState(false);
    const [done, setDone] = useState(false);
    const [discard, setDiscard] = useState(false);
    const uuids = useRef<Record<number, string>>({});
    const retryRequests = useRef<Record<number, ControlledActionValues>>({});
    const doses = override.doses.filter((dose) => !dose.signed_off_at);
    const medicines = [
        ...new Set(doses.map((dose) => dose.client_medication_id)),
    ]
        .map((id) => medicineFor(payload, id))
        .filter((medicine) => medicine !== undefined);
    const countFor = (dose: (typeof doses)[number]) =>
        counts[dose.client_medication_id] ?? dose.counted_entry_id;
    const completeCount = doses.every((dose) => !!countFor(dose));
    const close = () => {
        if (!saving) {
            if (done || (!checked.length && !notes)) onClose();
            else setDiscard(true);
        }
    };
    const sign = async () => {
        if (!doses.length) {
            setMessage(
                'No unsigned doses remain in this snapshot. Refresh to check the current follow-up.',
            );
            return;
        }
        if (
            !completeCount ||
            doses.some((dose) => !checked.includes(dose.administration_id))
        ) {
            setMessage(
                'A qualifying witnessed count and a check of every dose are required.',
            );
            return;
        }
        setSaving(true);
        setMessage('');
        const completed = [...signed];
        let currentDose: number | undefined;
        try {
            for (const dose of doses) {
                if (completed.includes(dose.administration_id)) continue;
                currentDose = dose.administration_id;
                uuids.current[dose.administration_id] ??= crypto.randomUUID();
                const request = retryRequests.current[
                    dose.administration_id
                ] ?? {
                    target_id: override.id,
                    administration_id: dose.administration_id,
                    counted_entry_id: countFor(dose)!,
                    client_medication_id: dose.client_medication_id,
                    expected_entry_id:
                        medicineFor(payload, dose.client_medication_id)
                            ?.entry_version ?? null,
                    notes:
                        notes.trim() ||
                        'Each dose checked against the chart and matching witnessed count.',
                    dose_checked: true,
                };
                retryRequests.current[dose.administration_id] = request;
                await act(
                    'override_signoff',
                    request,
                    uuids.current[dose.administration_id],
                );
                delete retryRequests.current[dose.administration_id];
                completed.push(dose.administration_id);
                setSigned([...completed]);
            }
            setDone(true);
            setUncertain(false);
        } catch (cause) {
            const error =
                cause instanceof ControlledApiError
                    ? cause
                    : new ControlledApiError(
                          'We could not confirm this sign-off.',
                      );
            setMessage(
                `${completed.length ? `${completed.length} dose(s) are signed off. The remaining doses still need sign-off. ` : ''}${error.message}`,
            );
            setUncertain(error.saveUncertain);
            if (!error.saveUncertain && currentDose !== undefined)
                delete retryRequests.current[currentDose];
        } finally {
            setSaving(false);
        }
    };
    if (counting)
        return (
            <CountDialog
                medicines={medicines}
                payload={payload}
                act={act}
                refresh={refresh}
                onClose={() => setCounting(false)}
                onRecorded={(medicineId, entryId) =>
                    setCounts((previous) => ({
                        ...previous,
                        [medicineId]: entryId,
                    }))
                }
            />
        );
    const steps = [
        {
            key: 'doses',
            label: 'The doses',
            blurb: 'Given without a witness',
            icon: FileText,
        },
        {
            key: 'count',
            label: 'Witnessed count',
            blurb: 'After the affected doses',
            icon: ClipboardCheck,
        },
        {
            key: 'sign',
            label: 'Sign off',
            blurb: 'Check each dose',
            icon: Check,
        },
    ];
    return (
        <>
            <WizardShell
                open
                onClose={close}
                title="Check doses given without a witness"
                description="Review each dose, count the affected medicines with a witness, then sign off each dose."
                railIcon={ShieldCheck}
                railTitle="Witness override follow-up"
                railSub={override.site_name}
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
                        step > 0,
                        completeCount,
                        doses.length > 0 && checked.length === doses.length,
                    ].filter(Boolean).length /
                        3) *
                        100,
                )}
                footerStart={
                    <Button
                        variant="outline"
                        className="min-h-11"
                        disabled={saving || uncertain}
                        onClick={step ? () => setStep(step - 1) : close}
                    >
                        {step ? 'Back' : 'Cancel'}
                    </Button>
                }
                footerEnd={
                    <Button
                        className="min-h-11"
                        disabled={
                            saving ||
                            !override.can_signoff ||
                            (step === 1 && !completeCount) ||
                            (step === 2 && !doses.length)
                        }
                        onClick={
                            step < 2
                                ? () => setStep(step + 1)
                                : () => void sign()
                        }
                    >
                        {saving ? (
                            <Loader2 className="size-4 animate-spin motion-reduce:animate-none" />
                        ) : (
                            <Check className="size-4" />
                        )}
                        {saving
                            ? 'Signing off…'
                            : step < 2
                              ? 'Continue'
                              : 'Sign off checked doses'}
                    </Button>
                }
                success={
                    done ? (
                        <WizardSuccessPane
                            title="Follow-up signed off"
                            blurb={`${signed.length} dose(s) checked and signed off. The witnessed counts and sign-offs remain in the history.`}
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
                                title="Follow-up needs your attention"
                                critical
                            >
                                {message}
                            </Notice>
                        ) : null}
                        {!override.can_signoff ? (
                            <Notice title="You cannot sign off this follow-up">
                                {override.signoff_reason ??
                                    'A permitted lead must complete the witnessed count and sign-off.'}
                            </Notice>
                        ) : null}
                        <p className="text-caption">
                            Due {dateTime(override.followup_due_at)}. A count
                            with a discrepancy does not cover this follow-up.
                        </p>
                        {step === 0 ? (
                            doses.map((dose) => (
                                <ReviewCard
                                    key={dose.administration_id}
                                    title={medicineLabel(
                                        payload,
                                        dose.client_medication_id,
                                    )}
                                    icon={FileText}
                                >
                                    <ReviewRow
                                        label="Dose"
                                        value={`${dose.quantity ?? '—'} · ${dateTime(dose.administered_at)}`}
                                    />
                                    <ReviewRow
                                        label="Given by"
                                        value={dose.administered_by_name ?? '—'}
                                    />
                                    <ReviewRow
                                        label="Status"
                                        value="Given without a witness under this override"
                                    />
                                </ReviewCard>
                            ))
                        ) : step === 1 ? (
                            <>
                                <Card className="gap-3 p-4">
                                    <p className="text-sm">
                                        The lead must take part in a witnessed
                                        count made after the doses. The server
                                        checks the count, current balance and
                                        discrepancy state.
                                    </p>
                                    {medicines.map((medicine) => (
                                        <div
                                            key={medicine.id}
                                            className="flex flex-wrap items-center justify-between gap-2"
                                        >
                                            <span className="text-sm">
                                                {medicine.name} ·{' '}
                                                {medicine.client_name}
                                            </span>
                                            <StatusBadge
                                                variant={
                                                    doses
                                                        .filter(
                                                            (dose) =>
                                                                dose.client_medication_id ===
                                                                medicine.id,
                                                        )
                                                        .every((dose) =>
                                                            countFor(dose),
                                                        )
                                                        ? 'success'
                                                        : 'warning'
                                                }
                                            >
                                                {doses
                                                    .filter(
                                                        (dose) =>
                                                            dose.client_medication_id ===
                                                            medicine.id,
                                                    )
                                                    .every((dose) =>
                                                        countFor(dose),
                                                    )
                                                    ? 'Witnessed count available'
                                                    : 'Witnessed count needed'}
                                            </StatusBadge>
                                        </div>
                                    ))}
                                    {medicines.length &&
                                    override.can_signoff ? (
                                        <Button
                                            variant="outline"
                                            className="min-h-11"
                                            onClick={() => setCounting(true)}
                                            disabled={medicines.some(
                                                (medicine) =>
                                                    !medicine.can_record ||
                                                    medicine.balance === null,
                                            )}
                                        >
                                            Count affected medicines
                                        </Button>
                                    ) : null}
                                </Card>
                            </>
                        ) : (
                            <>
                                {doses.map((dose) => (
                                    <div
                                        key={dose.administration_id}
                                        className="flex min-h-11 items-start gap-3 rounded-lg border p-4"
                                    >
                                        <Checkbox
                                            id={`followup-dose-${dose.administration_id}`}
                                            checked={checked.includes(
                                                dose.administration_id,
                                            )}
                                            disabled={signed.includes(
                                                dose.administration_id,
                                            )}
                                            onCheckedChange={(value) =>
                                                setChecked((previous) =>
                                                    value
                                                        ? [
                                                              ...previous,
                                                              dose.administration_id,
                                                          ]
                                                        : previous.filter(
                                                              (id) =>
                                                                  id !==
                                                                  dose.administration_id,
                                                          ),
                                                )
                                            }
                                        />
                                        <Label
                                            htmlFor={`followup-dose-${dose.administration_id}`}
                                            className="text-sm font-normal"
                                        >
                                            I checked{' '}
                                            {medicineLabel(
                                                payload,
                                                dose.client_medication_id,
                                            )}{' '}
                                            at {dateTime(dose.administered_at)}{' '}
                                            against the chart and witnessed
                                            count
                                            {signed.includes(
                                                dose.administration_id,
                                            )
                                                ? ' · signed off'
                                                : ''}
                                            .
                                        </Label>
                                    </div>
                                ))}
                                <Field
                                    name="followup_notes"
                                    label="Follow-up notes (optional)"
                                    multiline
                                    value={notes}
                                    onChange={setNotes}
                                />
                            </>
                        )}
                    </fieldset>
                </WizardStepPane>
            </WizardShell>
            <ConfirmDialog
                open={discard}
                onClose={() => setDiscard(false)}
                onConfirm={onClose}
                title="Discard the remaining sign-off?"
                description="Confirmed counts and sign-offs stay recorded. Unsaved checks and notes will be lost."
                confirmText="Discard draft"
                cancelText="Keep checking"
            />
        </>
    );
}
