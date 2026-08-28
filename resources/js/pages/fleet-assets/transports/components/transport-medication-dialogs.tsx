import MedicationScanVerificationPanel from '@/components/medications/MedicationScanVerificationPanel';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
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
import {
    WizardShell,
    WizardStepPane,
    type WizardStep,
} from '@/components/wizard/shell';
import {
    emarMutationWasAccepted,
    submitEmarMutation,
} from '@/lib/emar-offline';
import { applyFormRequestErrors } from '@/lib/form-request-errors';
import {
    emptyMedicationScanCapture,
    hasVerifiedMedicationScan,
    toMedicationScanPayload,
    type MedicationScanCapture,
    type MedicationScanVerification,
} from '@/lib/medication-scan';
import { useForm } from '@inertiajs/react';
import {
    ArrowLeft,
    ArrowLeftRight,
    CheckCircle,
    ClipboardCheck,
    Loader2,
    Package,
    Pill,
    ShieldCheck,
} from 'lucide-react';
import { useMemo, useRef, useState } from 'react';

import {
    createTransportMedicationReplayState,
    prepareTransportMedicationReplayState,
} from '../transport-medication-replay';

export type TransportMedicationOption = {
    id: number;
    name: string;
    dosage: string | null;
    frequency: string | null;
    is_prn: boolean;
    controlled_drug: boolean;
    witness_required: boolean;
    dose_times: string[] | null;
    route: string | null;
    instructions: string | null;
    scan_verification?: MedicationScanVerification | null;
};

export type TransportMedicationLog = {
    id: number;
    transport?: {
        id: number;
        resident_name: string;
        transport_type: string;
        status: string;
        departed_at: string | null;
        arrived_at: string | null;
        asset?: { id: number; name: string; asset_tag?: string | null } | null;
    } | null;
    client: { id: number; name: string } | null;
    medication_id: number | null;
    medication_name: string;
    is_controlled_drug: boolean;
    witness_required: boolean;
    packed_witness_name?: string | null;
    packed_witness?: { id: number; name: string } | null;
    packed_witnessed_at?: string | null;
    packing_witness_method?: string | null;
    packing_attestation_event_id?: number | null;
    packing_attestation_state?: string | null;
    packed_by: { id: number; name: string } | null;
    packed_at: string | null;
    administered_by: { id: number; name: string } | null;
    administered_at: string | null;
    witnessed_by: { id: number; name: string } | null;
    returned_to_house_at: string | null;
    status: string;
    notes: string | null;
    scan_verification?: MedicationScanVerification | null;
};

type MutationCompleted = (queued: boolean) => void;

const packSteps = [
    {
        key: 'medication',
        label: 'Medication & custody',
        blurb: 'Select medication and complete checks',
        icon: Pill,
    },
    {
        key: 'review',
        label: 'Review',
        blurb: 'Confirm the transit pack record',
        icon: ClipboardCheck,
    },
] as const satisfies readonly WizardStep[];

const administerSteps = [
    {
        key: 'checks',
        label: 'Dose & checks',
        blurb: 'Record the amount and complete checks',
        icon: ShieldCheck,
    },
    {
        key: 'review',
        label: 'Review',
        blurb: 'Confirm the administration record',
        icon: ClipboardCheck,
    },
] as const satisfies readonly WizardStep[];

const packingCorrectionSteps = [
    {
        key: 'correction',
        label: 'Correct witness',
        blurb: 'Authenticate the correct second checker',
        icon: ShieldCheck,
    },
    {
        key: 'review',
        label: 'Review',
        blurb: 'Keep the original and append the correction',
        icon: ClipboardCheck,
    },
] as const satisfies readonly WizardStep[];

const returnSteps = [
    {
        key: 'checks',
        label: 'Return checks',
        blurb: 'Verify medication and add notes',
        icon: ArrowLeftRight,
    },
    {
        key: 'review',
        label: 'Review',
        blurb: 'Confirm the return record',
        icon: ClipboardCheck,
    },
] as const satisfies readonly WizardStep[];

export function buildPackMedicationPayload({
    clientId,
    medication,
    attestationState,
    witnessedByUserId,
    witnessCredential,
    attestationReason,
    notes,
    scan,
}: {
    clientId: number;
    medication: TransportMedicationOption;
    attestationState: 'accepted' | 'refused' | 'unavailable';
    witnessedByUserId: string;
    witnessCredential: string;
    attestationReason: string;
    notes: string;
    scan: MedicationScanCapture;
}) {
    return {
        client_id: clientId,
        medication_id: medication.id,
        medication_name: medication.name,
        is_controlled_drug: medication.controlled_drug,
        attestation_state: attestationState,
        witnessed_by_user_id: witnessedByUserId
            ? Number(witnessedByUserId)
            : null,
        witness_credential: witnessCredential.trim() || null,
        attestation_reason: attestationReason.trim() || null,
        notes: notes.trim() || null,
        ...toMedicationScanPayload(scan),
    };
}

export function buildCorrectPackingAttestationPayload({
    witnessedByUserId,
    witnessCredential,
    correctionReason,
}: {
    witnessedByUserId: string;
    witnessCredential: string;
    correctionReason: string;
}) {
    return {
        witnessed_by_user_id: witnessedByUserId
            ? Number(witnessedByUserId)
            : null,
        witness_credential: witnessCredential.trim() || null,
        correction_reason: correctionReason.trim() || null,
    };
}

export function buildAdministerMedicationPayload({
    quantityAdministered,
    witnessedByUserId,
    witnessCredential,
    notes,
    scan,
}: {
    quantityAdministered: string;
    witnessedByUserId: string;
    witnessCredential: string;
    notes: string;
    scan: MedicationScanCapture;
}) {
    return {
        quantity_administered: quantityAdministered.trim(),
        witnessed_by_user_id: witnessedByUserId
            ? Number(witnessedByUserId)
            : null,
        witness_credential: witnessCredential.trim() || null,
        notes: notes.trim() || null,
        ...toMedicationScanPayload(scan),
    };
}

export function buildReturnMedicationPayload({
    notes,
    scan,
}: {
    notes: string;
    scan: MedicationScanCapture;
}) {
    return {
        notes: notes.trim() || null,
        ...toMedicationScanPayload(scan),
    };
}

function ReviewItem({ label, value }: { label: string; value: string }) {
    return (
        <div className="rounded-lg border bg-muted/20 p-3">
            <div className="text-xs font-medium text-muted-foreground">
                {label}
            </div>
            <div className="mt-1 text-sm font-semibold text-foreground">
                {value}
            </div>
        </div>
    );
}

export function PackMedicationWizard({
    open,
    transportId,
    client,
    residentName,
    medications,
    witnesses,
    onClose,
    onCompleted,
}: {
    open: boolean;
    transportId: number;
    client: { id: number; name: string } | null;
    residentName: string;
    medications: TransportMedicationOption[];
    witnesses: Array<{ id: number; name: string }>;
    onClose: () => void;
    onCompleted: MutationCompleted;
}) {
    const [stepIndex, setStepIndex] = useState(0);
    const [scanCapture, setScanCapture] = useState<MedicationScanCapture>(
        emptyMedicationScanCapture(),
    );
    const [submitting, setSubmitting] = useState(false);
    const packReplay = useRef(createTransportMedicationReplayState());
    const form = useForm({
        medication_id: '',
        attestation_state: 'accepted' as 'accepted' | 'refused' | 'unavailable',
        witnessed_by_user_id: '',
        witness_credential: '',
        attestation_reason: '',
        notes: '',
        scan_code: '',
    });

    const selectedMedication = useMemo(
        () =>
            medications.find(
                (medication) =>
                    String(medication.id) === String(form.data.medication_id),
            ) ?? null,
        [form.data.medication_id, medications],
    );
    const requiresWitness = !!(
        selectedMedication?.witness_required ||
        selectedMedication?.controlled_drug
    );
    const requiresScan = !!selectedMedication?.scan_verification;
    const acceptedForPacking =
        !requiresWitness || form.data.attestation_state === 'accepted';
    const witnessDecisionReady =
        !requiresWitness ||
        (form.data.attestation_state === 'unavailable'
            ? !!form.data.attestation_reason.trim()
            : !!form.data.witnessed_by_user_id &&
              !!form.data.witness_credential.trim() &&
              (form.data.attestation_state !== 'refused' ||
                  !!form.data.attestation_reason.trim()));
    const canContinue =
        !!client &&
        !!selectedMedication &&
        witnessDecisionReady &&
        (!acceptedForPacking ||
            !requiresScan ||
            hasVerifiedMedicationScan(scanCapture));

    const reset = () => {
        setStepIndex(0);
        form.reset();
        form.clearErrors();
        setScanCapture(emptyMedicationScanCapture());
        packReplay.current = createTransportMedicationReplayState();
    };

    const close = () => {
        reset();
        onClose();
    };

    const submit = async () => {
        if (!client || !selectedMedication || !canContinue) return;

        form.clearErrors();
        setSubmitting(true);
        try {
            const initialPayload = {
                ...buildPackMedicationPayload({
                    clientId: client.id,
                    medication: selectedMedication,
                    attestationState: form.data.attestation_state,
                    witnessedByUserId: form.data.witnessed_by_user_id,
                    witnessCredential: form.data.witness_credential,
                    attestationReason: form.data.attestation_reason,
                    notes: form.data.notes,
                    scan: scanCapture,
                }),
                client_request_uuid: packReplay.current.uuid,
            };
            packReplay.current = prepareTransportMedicationReplayState(
                packReplay.current,
                {
                    action: 'pack',
                    transport_id: transportId,
                    ...initialPayload,
                },
            );
            const result = await submitEmarMutation(
                `/fleet-assets/transports/${transportId}/pack-medication`,
                {
                    ...initialPayload,
                    client_request_uuid: packReplay.current.uuid,
                },
                {
                    allowQueueWhenOffline: !requiresWitness,
                    successMessage:
                        form.data.attestation_state === 'refused'
                            ? 'Second-checker refusal recorded. Medication was not packed.'
                            : form.data.attestation_state === 'unavailable'
                              ? 'Second-checker unavailability recorded. Medication was not packed.'
                              : 'Medication packed for transit.',
                    queuedMessage:
                        'Medication packing saved offline and will sync automatically when the device reconnects.',
                },
            );

            if (!emarMutationWasAccepted(result.status)) return;
            const queued = result.status === 'queued';
            reset();
            onClose();
            onCompleted(queued);
        } catch (error: unknown) {
            applyFormRequestErrors(
                error,
                (field, value) =>
                    (form.setError as (field: string, value: string) => void)(
                        field,
                        value,
                    ),
                'Failed to pack medication for this transport.',
            );
        } finally {
            setSubmitting(false);
        }
    };

    return (
        <WizardShell
            open={open}
            onClose={close}
            title="Pack medication for transit"
            description="Select medication, complete custody checks, and review before packing it for this transport."
            railIcon={Package}
            railTitle="Pack medication"
            railSub={client?.name ?? residentName}
            steps={packSteps}
            stepIndex={stepIndex}
            onStepClick={(index) =>
                index === 0 || canContinue ? setStepIndex(index) : undefined
            }
            pct={stepIndex === 0 ? (canContinue ? 50 : 20) : 100}
            footerStart={
                <Button type="button" variant="ghost" onClick={close}>
                    Cancel
                </Button>
            }
            footerEnd={
                stepIndex === 0 ? (
                    <Button
                        type="button"
                        onClick={() => setStepIndex(1)}
                        disabled={!canContinue}
                    >
                        Continue
                    </Button>
                ) : (
                    <>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => setStepIndex(0)}
                        >
                            <ArrowLeft className="mr-2 h-4 w-4" />
                            Back
                        </Button>
                        <Button
                            type="button"
                            onClick={submit}
                            disabled={submitting || !canContinue}
                        >
                            {submitting ? (
                                <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                            ) : (
                                <Package className="mr-2 h-4 w-4" />
                            )}
                            {acceptedForPacking
                                ? 'Pack medication'
                                : 'Record decision'}
                        </Button>
                    </>
                )
            }
        >
            <WizardStepPane>
                {stepIndex === 0 ? (
                    <div className="space-y-5">
                        <div className="rounded-lg border bg-muted/20 p-4 text-sm">
                            <div className="font-semibold">
                                {client?.name ?? residentName}
                            </div>
                            <div className="mt-1 text-muted-foreground">
                                Select an active medication and complete any
                                custody checks.
                            </div>
                        </div>

                        <div className="space-y-2">
                            <Label htmlFor="pack-medication-id">
                                Medication
                            </Label>
                            <Select
                                value={form.data.medication_id || 'none'}
                                onValueChange={(value) => {
                                    form.clearErrors('medication_id');
                                    form.clearErrors('scan_code');
                                    form.setData(
                                        'medication_id',
                                        value === 'none' ? '' : value,
                                    );
                                    form.setData(
                                        'attestation_state',
                                        'accepted',
                                    );
                                    form.setData('witnessed_by_user_id', '');
                                    form.setData('witness_credential', '');
                                    form.setData('attestation_reason', '');
                                    setScanCapture(
                                        emptyMedicationScanCapture(),
                                    );
                                }}
                            >
                                <SelectTrigger id="pack-medication-id">
                                    <SelectValue placeholder="Select medication" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="none">
                                        Select medication
                                    </SelectItem>
                                    {medications.map((medication) => (
                                        <SelectItem
                                            key={medication.id}
                                            value={String(medication.id)}
                                        >
                                            {medication.name}
                                            {medication.dosage
                                                ? ` ${medication.dosage}`
                                                : ''}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            {form.errors.medication_id ? (
                                <p className="text-sm text-destructive">
                                    {form.errors.medication_id}
                                </p>
                            ) : null}
                        </div>

                        {selectedMedication ? (
                            <div className="rounded-lg border p-4">
                                <div className="flex flex-wrap items-center gap-2">
                                    <span className="text-sm font-semibold">
                                        {selectedMedication.name}
                                    </span>
                                    {selectedMedication.dosage ? (
                                        <span className="text-xs text-muted-foreground">
                                            {selectedMedication.dosage}
                                        </span>
                                    ) : null}
                                    <Badge
                                        variant={
                                            selectedMedication.is_prn
                                                ? 'secondary'
                                                : 'outline'
                                        }
                                    >
                                        {selectedMedication.is_prn
                                            ? 'PRN'
                                            : 'Scheduled'}
                                    </Badge>
                                    {selectedMedication.controlled_drug ? (
                                        <Badge variant="destructive">
                                            Controlled
                                        </Badge>
                                    ) : null}
                                </div>
                                {selectedMedication.route ? (
                                    <div className="mt-2 text-xs text-muted-foreground">
                                        Route: {selectedMedication.route}
                                    </div>
                                ) : null}
                                {selectedMedication.instructions ? (
                                    <div className="mt-1 text-xs text-muted-foreground">
                                        {selectedMedication.instructions}
                                    </div>
                                ) : null}
                            </div>
                        ) : null}

                        {requiresWitness ? (
                            <div className="space-y-3 rounded-lg border p-4">
                                <div className="space-y-2">
                                    <Label htmlFor="pack-attestation-state">
                                        Second-checker decision
                                    </Label>
                                    <Select
                                        value={form.data.attestation_state}
                                        onValueChange={(
                                            value:
                                                | 'accepted'
                                                | 'refused'
                                                | 'unavailable',
                                        ) => {
                                            form.clearErrors();
                                            form.setData(
                                                'attestation_state',
                                                value,
                                            );
                                            if (value === 'unavailable') {
                                                form.setData(
                                                    'witnessed_by_user_id',
                                                    '',
                                                );
                                                form.setData(
                                                    'witness_credential',
                                                    '',
                                                );
                                            }
                                            if (value === 'accepted') {
                                                form.setData(
                                                    'attestation_reason',
                                                    '',
                                                );
                                            }
                                        }}
                                    >
                                        <SelectTrigger id="pack-attestation-state">
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="accepted">
                                                Accepted in person
                                            </SelectItem>
                                            <SelectItem value="refused">
                                                Declined to attest
                                            </SelectItem>
                                            <SelectItem value="unavailable">
                                                No eligible checker available
                                            </SelectItem>
                                        </SelectContent>
                                    </Select>
                                </div>

                                {form.data.attestation_state !==
                                'unavailable' ? (
                                    <>
                                        <div className="space-y-2">
                                            <Label htmlFor="pack-witness">
                                                Second checker
                                            </Label>
                                            <Select
                                                value={
                                                    form.data
                                                        .witnessed_by_user_id ||
                                                    'none'
                                                }
                                                onValueChange={(value) => {
                                                    form.clearErrors(
                                                        'witnessed_by_user_id',
                                                    );
                                                    form.clearErrors(
                                                        'witness_credential',
                                                    );
                                                    form.setData(
                                                        'witnessed_by_user_id',
                                                        value === 'none'
                                                            ? ''
                                                            : value,
                                                    );
                                                    form.setData(
                                                        'witness_credential',
                                                        '',
                                                    );
                                                }}
                                            >
                                                <SelectTrigger id="pack-witness">
                                                    <SelectValue placeholder="Select second checker" />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    <SelectItem value="none">
                                                        Select second checker
                                                    </SelectItem>
                                                    {witnesses.map(
                                                        (witness) => (
                                                            <SelectItem
                                                                key={witness.id}
                                                                value={String(
                                                                    witness.id,
                                                                )}
                                                            >
                                                                {witness.name}
                                                            </SelectItem>
                                                        ),
                                                    )}
                                                </SelectContent>
                                            </Select>
                                            {form.errors
                                                .witnessed_by_user_id ? (
                                                <p className="text-sm text-destructive">
                                                    {
                                                        form.errors
                                                            .witnessed_by_user_id
                                                    }
                                                </p>
                                            ) : null}
                                        </div>
                                        <div className="space-y-2">
                                            <Label htmlFor="pack-witness-credential">
                                                Second checker password / PIN
                                            </Label>
                                            <Input
                                                id="pack-witness-credential"
                                                type="password"
                                                autoComplete="current-password"
                                                value={
                                                    form.data.witness_credential
                                                }
                                                onChange={(event) => {
                                                    form.clearErrors(
                                                        'witness_credential',
                                                    );
                                                    form.setData(
                                                        'witness_credential',
                                                        event.target.value,
                                                    );
                                                }}
                                            />
                                            <p className="text-xs text-muted-foreground">
                                                The second checker must be
                                                present and enter their own
                                                credential.
                                            </p>
                                            {form.errors.witness_credential ? (
                                                <p className="text-sm text-destructive">
                                                    {
                                                        form.errors
                                                            .witness_credential
                                                    }
                                                </p>
                                            ) : null}
                                        </div>
                                    </>
                                ) : null}

                                {form.data.attestation_state !== 'accepted' ? (
                                    <div className="space-y-2">
                                        <Label htmlFor="pack-attestation-reason">
                                            Reason
                                        </Label>
                                        <Textarea
                                            id="pack-attestation-reason"
                                            value={form.data.attestation_reason}
                                            onChange={(event) => {
                                                form.clearErrors(
                                                    'attestation_reason',
                                                );
                                                form.setData(
                                                    'attestation_reason',
                                                    event.target.value,
                                                );
                                            }}
                                            rows={3}
                                        />
                                        {form.errors.attestation_reason ? (
                                            <p className="text-sm text-destructive">
                                                {form.errors.attestation_reason}
                                            </p>
                                        ) : null}
                                    </div>
                                ) : null}
                            </div>
                        ) : null}

                        {acceptedForPacking &&
                        selectedMedication?.scan_verification ? (
                            <MedicationScanVerificationPanel
                                clientId={client?.id ?? null}
                                medicationId={selectedMedication.id}
                                scanVerification={
                                    selectedMedication.scan_verification
                                }
                                requirementText="Verification is required before packing this medication for transit."
                                resetKey={`pack-${selectedMedication.id}-${open}`}
                                onChange={(capture) => {
                                    form.clearErrors('scan_code');
                                    setScanCapture(capture);
                                }}
                            />
                        ) : null}
                        {form.errors.scan_code ? (
                            <p className="text-sm text-destructive">
                                {form.errors.scan_code}
                            </p>
                        ) : null}

                        <div className="space-y-2">
                            <Label htmlFor="pack-notes">Notes</Label>
                            <Textarea
                                id="pack-notes"
                                value={form.data.notes}
                                onChange={(event) =>
                                    form.setData('notes', event.target.value)
                                }
                                placeholder="Add any chain-of-custody or handling notes..."
                            />
                            {form.errors.notes ? (
                                <p className="text-sm text-destructive">
                                    {form.errors.notes}
                                </p>
                            ) : null}
                        </div>
                    </div>
                ) : (
                    <div className="space-y-4">
                        <h3 className="text-lg font-semibold">
                            {acceptedForPacking
                                ? 'Review medication pack'
                                : 'Review second-checker decision'}
                        </h3>
                        <div className="grid gap-3 sm:grid-cols-2">
                            <ReviewItem
                                label="Resident"
                                value={client?.name ?? residentName}
                            />
                            <ReviewItem
                                label="Medication"
                                value={
                                    selectedMedication?.name ?? 'Not selected'
                                }
                            />
                            <ReviewItem
                                label="Controlled drug"
                                value={
                                    selectedMedication?.controlled_drug
                                        ? 'Yes'
                                        : 'No'
                                }
                            />
                            <ReviewItem
                                label="Second checker"
                                value={
                                    form.data.attestation_state ===
                                    'unavailable'
                                        ? 'No eligible checker available'
                                        : (witnesses.find(
                                              (witness) =>
                                                  String(witness.id) ===
                                                  form.data
                                                      .witnessed_by_user_id,
                                          )?.name ?? 'Not required')
                                }
                            />
                            {requiresWitness ? (
                                <ReviewItem
                                    label="Decision"
                                    value={
                                        form.data.attestation_state ===
                                        'accepted'
                                            ? 'Accepted in person'
                                            : form.data.attestation_state ===
                                                'refused'
                                              ? 'Declined to attest'
                                              : 'Unavailable'
                                    }
                                />
                            ) : null}
                        </div>
                        <ReviewItem
                            label="Notes"
                            value={form.data.notes.trim() || 'No notes added'}
                        />
                        {form.data.attestation_state !== 'accepted' ? (
                            <ReviewItem
                                label="Decision reason"
                                value={form.data.attestation_reason.trim()}
                            />
                        ) : null}
                    </div>
                )}
            </WizardStepPane>
        </WizardShell>
    );
}

export function CorrectPackingAttestationWizard({
    log,
    witnesses,
    onClose,
    onCompleted,
}: {
    log: TransportMedicationLog | null;
    witnesses: Array<{ id: number; name: string }>;
    onClose: () => void;
    onCompleted: MutationCompleted;
}) {
    const [stepIndex, setStepIndex] = useState(0);
    const [submitting, setSubmitting] = useState(false);
    const correctionReplay = useRef(createTransportMedicationReplayState());
    const form = useForm({
        witnessed_by_user_id: '',
        witness_credential: '',
        correction_reason: '',
    });
    const correctionWitnesses = witnesses.filter(
        (witness) => witness.id !== log?.packed_witness?.id,
    );
    const canContinue =
        !!log &&
        !!form.data.witnessed_by_user_id &&
        !!form.data.witness_credential.trim() &&
        !!form.data.correction_reason.trim();
    const selectedWitness = correctionWitnesses.find(
        (witness) => String(witness.id) === form.data.witnessed_by_user_id,
    );

    const reset = () => {
        setStepIndex(0);
        form.reset();
        form.clearErrors();
        correctionReplay.current = createTransportMedicationReplayState();
    };
    const close = () => {
        reset();
        onClose();
    };
    const submit = async () => {
        if (!log || !canContinue) return;
        form.clearErrors();
        setSubmitting(true);
        try {
            const initialPayload = {
                ...buildCorrectPackingAttestationPayload({
                    witnessedByUserId: form.data.witnessed_by_user_id,
                    witnessCredential: form.data.witness_credential,
                    correctionReason: form.data.correction_reason,
                }),
                client_request_uuid: correctionReplay.current.uuid,
            };
            correctionReplay.current = prepareTransportMedicationReplayState(
                correctionReplay.current,
                {
                    action: 'correct_packing_attestation',
                    log_id: log.id,
                    ...initialPayload,
                },
            );
            const result = await submitEmarMutation(
                `/fleet-assets/medication-transit/${log.id}/correct-packing-attestation`,
                {
                    ...initialPayload,
                    client_request_uuid: correctionReplay.current.uuid,
                },
                {
                    allowQueueWhenOffline: false,
                    successMessage: 'Packing witness correction recorded.',
                },
            );
            if (!emarMutationWasAccepted(result.status)) return;
            reset();
            onClose();
            onCompleted(false);
        } catch (error: unknown) {
            applyFormRequestErrors(
                error,
                (field, value) =>
                    (form.setError as (field: string, value: string) => void)(
                        field,
                        value,
                    ),
                'Failed to correct the packing witness.',
            );
        } finally {
            setSubmitting(false);
        }
    };

    return (
        <WizardShell
            open={!!log}
            onClose={close}
            title="Correct packing witness"
            description="Authenticate the correct second checker and keep the original packing evidence in the journey history."
            railIcon={ShieldCheck}
            railTitle="Witness correction"
            railSub={log?.medication_name ?? 'Medication transit'}
            steps={packingCorrectionSteps}
            stepIndex={stepIndex}
            onStepClick={(index) =>
                index === 0 || canContinue ? setStepIndex(index) : undefined
            }
            pct={stepIndex === 0 ? (canContinue ? 50 : 25) : 100}
            footerStart={
                <Button type="button" variant="ghost" onClick={close}>
                    Cancel
                </Button>
            }
            footerEnd={
                stepIndex === 0 ? (
                    <Button
                        type="button"
                        onClick={() => setStepIndex(1)}
                        disabled={!canContinue}
                    >
                        Continue
                    </Button>
                ) : (
                    <>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => setStepIndex(0)}
                        >
                            <ArrowLeft className="mr-2 h-4 w-4" />
                            Back
                        </Button>
                        <Button
                            type="button"
                            onClick={submit}
                            disabled={submitting || !canContinue}
                        >
                            {submitting ? (
                                <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                            ) : (
                                <ShieldCheck className="mr-2 h-4 w-4" />
                            )}
                            Record correction
                        </Button>
                    </>
                )
            }
        >
            <WizardStepPane>
                {stepIndex === 0 ? (
                    <div className="space-y-5">
                        <MedicationSummary log={log} />
                        <div className="rounded-lg border bg-muted/20 p-3 text-sm">
                            <div className="text-xs text-muted-foreground">
                                Current packing witness
                            </div>
                            <div className="mt-1 font-medium">
                                {log?.packed_witness?.name ??
                                    (log?.packed_witness_name
                                        ? `${log.packed_witness_name} (legacy label only)`
                                        : 'No authenticated witness recorded')}
                            </div>
                        </div>
                        <div className="space-y-2">
                            <Label htmlFor="correct-pack-witness">
                                Correct second checker
                            </Label>
                            <Select
                                value={form.data.witnessed_by_user_id || 'none'}
                                onValueChange={(value) => {
                                    form.clearErrors('witnessed_by_user_id');
                                    form.clearErrors('witness_credential');
                                    form.setData(
                                        'witnessed_by_user_id',
                                        value === 'none' ? '' : value,
                                    );
                                    form.setData('witness_credential', '');
                                }}
                            >
                                <SelectTrigger id="correct-pack-witness">
                                    <SelectValue placeholder="Select second checker" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="none">
                                        Select second checker
                                    </SelectItem>
                                    {correctionWitnesses.map((witness) => (
                                        <SelectItem
                                            key={witness.id}
                                            value={String(witness.id)}
                                        >
                                            {witness.name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            {form.errors.witnessed_by_user_id ? (
                                <p className="text-sm text-destructive">
                                    {form.errors.witnessed_by_user_id}
                                </p>
                            ) : null}
                        </div>
                        <div className="space-y-2">
                            <Label htmlFor="correct-pack-witness-credential">
                                Second checker password / PIN
                            </Label>
                            <Input
                                id="correct-pack-witness-credential"
                                type="password"
                                autoComplete="current-password"
                                value={form.data.witness_credential}
                                onChange={(event) => {
                                    form.clearErrors('witness_credential');
                                    form.setData(
                                        'witness_credential',
                                        event.target.value,
                                    );
                                }}
                            />
                            {form.errors.witness_credential ? (
                                <p className="text-sm text-destructive">
                                    {form.errors.witness_credential}
                                </p>
                            ) : null}
                        </div>
                        <div className="space-y-2">
                            <Label htmlFor="packing-correction-reason">
                                Correction reason
                            </Label>
                            <Textarea
                                id="packing-correction-reason"
                                value={form.data.correction_reason}
                                onChange={(event) => {
                                    form.clearErrors('correction_reason');
                                    form.setData(
                                        'correction_reason',
                                        event.target.value,
                                    );
                                }}
                                rows={3}
                            />
                            {form.errors.correction_reason ? (
                                <p className="text-sm text-destructive">
                                    {form.errors.correction_reason}
                                </p>
                            ) : null}
                        </div>
                    </div>
                ) : (
                    <div className="space-y-4">
                        <h3 className="text-lg font-semibold">
                            Review packing witness correction
                        </h3>
                        <div className="grid gap-3 sm:grid-cols-2">
                            <ReviewItem
                                label="Medication"
                                value={log?.medication_name ?? '---'}
                            />
                            <ReviewItem
                                label="Correct second checker"
                                value={selectedWitness?.name ?? 'Not selected'}
                            />
                        </div>
                        <ReviewItem
                            label="Correction reason"
                            value={form.data.correction_reason.trim()}
                        />
                        <p className="text-sm text-muted-foreground">
                            The original evidence remains in the journey
                            history; this appends a linked correction.
                        </p>
                    </div>
                )}
            </WizardStepPane>
        </WizardShell>
    );
}

export function AdministerTransportMedicationWizard({
    log,
    witnesses,
    onClose,
    onCompleted,
}: {
    log: TransportMedicationLog | null;
    witnesses: Array<{ id: number; name: string }>;
    onClose: () => void;
    onCompleted: MutationCompleted;
}) {
    const [stepIndex, setStepIndex] = useState(0);
    const [scanCapture, setScanCapture] = useState<MedicationScanCapture>(
        emptyMedicationScanCapture(),
    );
    const [submitting, setSubmitting] = useState(false);
    const administrationReplay = useRef(createTransportMedicationReplayState());
    const form = useForm({
        quantity_administered: '',
        witnessed_by_user_id: '',
        witness_credential: '',
        notes: '',
        scan_code: '',
    });
    const requiresWitness = !!(
        log?.witness_required || log?.is_controlled_drug
    );
    const requiresScan = !!log?.scan_verification;
    const normalizedQuantity = form.data.quantity_administered.trim();
    const quantityIsValid =
        /^\d+(?:\.\d{1,2})?$/.test(normalizedQuantity) &&
        Number(normalizedQuantity) >= 0.01 &&
        Number(normalizedQuantity) <= 99_999_999.99;
    const canContinue =
        !!log &&
        quantityIsValid &&
        (!requiresWitness ||
            (!!form.data.witnessed_by_user_id &&
                !!form.data.witness_credential.trim())) &&
        (!requiresScan || hasVerifiedMedicationScan(scanCapture));

    const reset = () => {
        setStepIndex(0);
        form.reset();
        form.clearErrors();
        setScanCapture(emptyMedicationScanCapture());
        administrationReplay.current = createTransportMedicationReplayState();
    };
    const close = () => {
        reset();
        onClose();
    };
    const submit = async () => {
        if (!log || !canContinue) return;
        form.clearErrors();
        setSubmitting(true);
        try {
            const initialPayload = {
                ...buildAdministerMedicationPayload({
                    quantityAdministered: form.data.quantity_administered,
                    witnessedByUserId: form.data.witnessed_by_user_id,
                    witnessCredential: form.data.witness_credential,
                    notes: form.data.notes,
                    scan: scanCapture,
                }),
                client_request_uuid: administrationReplay.current.uuid,
            };
            administrationReplay.current =
                prepareTransportMedicationReplayState(
                    administrationReplay.current,
                    {
                        action: 'administer',
                        log_id: log.id,
                        ...initialPayload,
                    },
                );
            const result = await submitEmarMutation(
                `/fleet-assets/medication-transit/${log.id}/administer`,
                {
                    ...initialPayload,
                    client_request_uuid: administrationReplay.current.uuid,
                },
                {
                    allowQueueWhenOffline: !requiresWitness,
                    successMessage: 'Medication administration recorded.',
                    queuedMessage:
                        'Medication transit administration saved offline and will sync automatically when the device reconnects.',
                },
            );
            if (!emarMutationWasAccepted(result.status)) return;
            const queued = result.status === 'queued';
            reset();
            onClose();
            onCompleted(queued);
        } catch (error: unknown) {
            applyFormRequestErrors(
                error,
                (field, value) =>
                    (form.setError as (field: string, value: string) => void)(
                        field,
                        value,
                    ),
                'Failed to record transport administration.',
            );
        } finally {
            setSubmitting(false);
        }
    };

    return (
        <WizardShell
            open={!!log}
            onClose={close}
            title="Record transport administration"
            description="Record the amount given, complete witness and verification checks, then review this administration."
            railIcon={CheckCircle}
            railTitle="Administration"
            railSub={log?.client?.name ?? 'Medication transit'}
            steps={administerSteps}
            stepIndex={stepIndex}
            onStepClick={(index) =>
                index === 0 || canContinue ? setStepIndex(index) : undefined
            }
            pct={stepIndex === 0 ? (canContinue ? 50 : 25) : 100}
            footerStart={
                <Button type="button" variant="ghost" onClick={close}>
                    Cancel
                </Button>
            }
            footerEnd={
                stepIndex === 0 ? (
                    <Button
                        type="button"
                        onClick={() => setStepIndex(1)}
                        disabled={!canContinue}
                    >
                        Continue
                    </Button>
                ) : (
                    <>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => setStepIndex(0)}
                        >
                            <ArrowLeft className="mr-2 h-4 w-4" />
                            Back
                        </Button>
                        <Button
                            type="button"
                            onClick={submit}
                            disabled={submitting || !canContinue}
                        >
                            {submitting ? (
                                <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                            ) : (
                                <CheckCircle className="mr-2 h-4 w-4" />
                            )}
                            Record administration
                        </Button>
                    </>
                )
            }
        >
            <WizardStepPane>
                {stepIndex === 0 ? (
                    <div className="space-y-5">
                        <MedicationSummary log={log} />
                        <div className="space-y-2">
                            <Label htmlFor="administer-quantity">
                                Units given
                            </Label>
                            <Input
                                id="administer-quantity"
                                type="number"
                                inputMode="decimal"
                                min={0.01}
                                max={99_999_999.99}
                                step="0.01"
                                required
                                placeholder="e.g. 1 or 0.25"
                                value={form.data.quantity_administered}
                                onChange={(event) => {
                                    form.clearErrors('quantity_administered');
                                    form.setData(
                                        'quantity_administered',
                                        event.target.value,
                                    );
                                }}
                            />
                            <p className="text-xs text-muted-foreground">
                                Enter the amount actually given. This is removed
                                from medication stock where stock is tracked.
                            </p>
                            {form.errors.quantity_administered ? (
                                <p className="text-sm text-destructive">
                                    {form.errors.quantity_administered}
                                </p>
                            ) : null}
                        </div>
                        {requiresWitness ? (
                            <div className="space-y-2">
                                <Label htmlFor="administer-witness">
                                    Witness
                                </Label>
                                <Select
                                    value={
                                        form.data.witnessed_by_user_id || 'none'
                                    }
                                    onValueChange={(value) => {
                                        form.clearErrors(
                                            'witnessed_by_user_id',
                                        );
                                        form.clearErrors('witness_credential');
                                        form.setData(
                                            'witnessed_by_user_id',
                                            value === 'none' ? '' : value,
                                        );
                                        form.setData('witness_credential', '');
                                    }}
                                >
                                    <SelectTrigger id="administer-witness">
                                        <SelectValue placeholder="Select witness" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="none">
                                            Select witness
                                        </SelectItem>
                                        {witnesses.map((witness) => (
                                            <SelectItem
                                                key={witness.id}
                                                value={String(witness.id)}
                                            >
                                                {witness.name}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                {form.errors.witnessed_by_user_id ? (
                                    <p className="text-sm text-destructive">
                                        {form.errors.witnessed_by_user_id}
                                    </p>
                                ) : null}
                                <Label htmlFor="administer-witness-credential">
                                    Witness password / PIN
                                </Label>
                                <Input
                                    id="administer-witness-credential"
                                    type="password"
                                    autoComplete="current-password"
                                    value={form.data.witness_credential}
                                    onChange={(event) => {
                                        form.clearErrors('witness_credential');
                                        form.setData(
                                            'witness_credential',
                                            event.target.value,
                                        );
                                    }}
                                />
                                {form.errors.witness_credential ? (
                                    <p className="text-sm text-destructive">
                                        {form.errors.witness_credential}
                                    </p>
                                ) : null}
                            </div>
                        ) : null}
                        {requiresScan && log ? (
                            <MedicationScanVerificationPanel
                                clientId={log.client?.id ?? null}
                                medicationId={log.medication_id}
                                scanVerification={log.scan_verification ?? null}
                                requirementText="Verification is required before recording this administration."
                                resetKey={`administer-${log.id}`}
                                onChange={(capture) => {
                                    form.clearErrors('scan_code');
                                    setScanCapture(capture);
                                }}
                            />
                        ) : null}
                        {form.errors.scan_code ? (
                            <p className="text-sm text-destructive">
                                {form.errors.scan_code}
                            </p>
                        ) : null}
                        <div className="space-y-2">
                            <Label htmlFor="administer-notes">Notes</Label>
                            <Textarea
                                id="administer-notes"
                                value={form.data.notes}
                                onChange={(event) =>
                                    form.setData('notes', event.target.value)
                                }
                                placeholder="Add any transport administration notes..."
                            />
                            {form.errors.notes ? (
                                <p className="text-sm text-destructive">
                                    {form.errors.notes}
                                </p>
                            ) : null}
                        </div>
                    </div>
                ) : (
                    <div className="space-y-4">
                        <h3 className="text-lg font-semibold">
                            Review administration
                        </h3>
                        <div className="grid gap-3 sm:grid-cols-2">
                            <ReviewItem
                                label="Medication"
                                value={log?.medication_name ?? '---'}
                            />
                            <ReviewItem
                                label="Resident"
                                value={log?.client?.name ?? '---'}
                            />
                            <ReviewItem
                                label="Units given"
                                value={form.data.quantity_administered.trim()}
                            />
                            <ReviewItem
                                label="Witness"
                                value={
                                    witnesses.find(
                                        (witness) =>
                                            String(witness.id) ===
                                            form.data.witnessed_by_user_id,
                                    )?.name ?? 'Not required'
                                }
                            />
                            <ReviewItem
                                label="Verification"
                                value={
                                    requiresScan ? 'Verified' : 'Not required'
                                }
                            />
                        </div>
                        <ReviewItem
                            label="Notes"
                            value={form.data.notes.trim() || 'No notes added'}
                        />
                    </div>
                )}
            </WizardStepPane>
        </WizardShell>
    );
}

export function ReturnTransportMedicationWizard({
    log,
    onClose,
    onCompleted,
}: {
    log: TransportMedicationLog | null;
    onClose: () => void;
    onCompleted: MutationCompleted;
}) {
    const [stepIndex, setStepIndex] = useState(0);
    const [scanCapture, setScanCapture] = useState<MedicationScanCapture>(
        emptyMedicationScanCapture(),
    );
    const [submitting, setSubmitting] = useState(false);
    const returnReplay = useRef(createTransportMedicationReplayState());
    const form = useForm({ notes: '', scan_code: '' });
    const requiresScan = !!log?.scan_verification;
    const canContinue =
        !!log && (!requiresScan || hasVerifiedMedicationScan(scanCapture));

    const reset = () => {
        setStepIndex(0);
        form.reset();
        form.clearErrors();
        setScanCapture(emptyMedicationScanCapture());
        returnReplay.current = createTransportMedicationReplayState();
    };
    const close = () => {
        reset();
        onClose();
    };
    const submit = async () => {
        if (!log || !canContinue) return;
        form.clearErrors();
        setSubmitting(true);
        try {
            const initialPayload = {
                ...buildReturnMedicationPayload({
                    notes: form.data.notes,
                    scan: scanCapture,
                }),
                client_request_uuid: returnReplay.current.uuid,
            };
            returnReplay.current = prepareTransportMedicationReplayState(
                returnReplay.current,
                {
                    action: 'return',
                    log_id: log.id,
                    ...initialPayload,
                },
            );
            const result = await submitEmarMutation(
                `/fleet-assets/medication-transit/${log.id}/return`,
                {
                    ...initialPayload,
                    client_request_uuid: returnReplay.current.uuid,
                },
                {
                    successMessage: 'Medication return recorded.',
                    queuedMessage:
                        'Medication return saved offline and will sync automatically when the device reconnects.',
                },
            );
            if (!emarMutationWasAccepted(result.status)) return;
            const queued = result.status === 'queued';
            reset();
            onClose();
            onCompleted(queued);
        } catch (error: unknown) {
            applyFormRequestErrors(
                error,
                (field, value) =>
                    (form.setError as (field: string, value: string) => void)(
                        field,
                        value,
                    ),
                'Failed to record medication return.',
            );
        } finally {
            setSubmitting(false);
        }
    };

    return (
        <WizardShell
            open={!!log}
            onClose={close}
            title="Record medication return"
            description="Complete return verification and review before returning this medication to house stock."
            railIcon={ArrowLeftRight}
            railTitle="Medication return"
            railSub={log?.client?.name ?? 'Medication transit'}
            steps={returnSteps}
            stepIndex={stepIndex}
            onStepClick={(index) =>
                index === 0 || canContinue ? setStepIndex(index) : undefined
            }
            pct={stepIndex === 0 ? (canContinue ? 50 : 25) : 100}
            footerStart={
                <Button type="button" variant="ghost" onClick={close}>
                    Cancel
                </Button>
            }
            footerEnd={
                stepIndex === 0 ? (
                    <Button
                        type="button"
                        onClick={() => setStepIndex(1)}
                        disabled={!canContinue}
                    >
                        Continue
                    </Button>
                ) : (
                    <>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => setStepIndex(0)}
                        >
                            <ArrowLeft className="mr-2 h-4 w-4" />
                            Back
                        </Button>
                        <Button
                            type="button"
                            onClick={submit}
                            disabled={submitting || !canContinue}
                        >
                            {submitting ? (
                                <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                            ) : (
                                <ArrowLeftRight className="mr-2 h-4 w-4" />
                            )}
                            Record return
                        </Button>
                    </>
                )
            }
        >
            <WizardStepPane>
                {stepIndex === 0 ? (
                    <div className="space-y-5">
                        <MedicationSummary log={log} />
                        {requiresScan && log ? (
                            <MedicationScanVerificationPanel
                                clientId={log.client?.id ?? null}
                                medicationId={log.medication_id}
                                scanVerification={log.scan_verification ?? null}
                                requirementText="Verification is required before returning this medication to house stock."
                                resetKey={`return-${log.id}`}
                                onChange={(capture) => {
                                    form.clearErrors('scan_code');
                                    setScanCapture(capture);
                                }}
                            />
                        ) : null}
                        {form.errors.scan_code ? (
                            <p className="text-sm text-destructive">
                                {form.errors.scan_code}
                            </p>
                        ) : null}
                        <div className="space-y-2">
                            <Label htmlFor="return-notes">Return notes</Label>
                            <Textarea
                                id="return-notes"
                                value={form.data.notes}
                                onChange={(event) =>
                                    form.setData('notes', event.target.value)
                                }
                                placeholder="Add any hand-back or chain-of-custody notes..."
                            />
                            {form.errors.notes ? (
                                <p className="text-sm text-destructive">
                                    {form.errors.notes}
                                </p>
                            ) : null}
                        </div>
                    </div>
                ) : (
                    <div className="space-y-4">
                        <h3 className="text-lg font-semibold">
                            Review medication return
                        </h3>
                        <div className="grid gap-3 sm:grid-cols-2">
                            <ReviewItem
                                label="Medication"
                                value={log?.medication_name ?? '---'}
                            />
                            <ReviewItem
                                label="Resident"
                                value={log?.client?.name ?? '---'}
                            />
                            <ReviewItem
                                label="Destination"
                                value="House stock"
                            />
                            <ReviewItem
                                label="Verification"
                                value={
                                    requiresScan ? 'Verified' : 'Not required'
                                }
                            />
                        </div>
                        <ReviewItem
                            label="Return notes"
                            value={form.data.notes.trim() || 'No notes added'}
                        />
                    </div>
                )}
            </WizardStepPane>
        </WizardShell>
    );
}

function MedicationSummary({ log }: { log: TransportMedicationLog | null }) {
    return (
        <div className="rounded-lg border bg-muted/20 p-4 text-sm">
            <div className="font-semibold">{log?.medication_name ?? '---'}</div>
            <div className="mt-1 text-muted-foreground">
                {log?.client?.name ?? '---'}
            </div>
        </div>
    );
}
