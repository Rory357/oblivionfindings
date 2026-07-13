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
import { submitEmarMutation } from '@/lib/emar-offline';
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
import { useMemo, useState } from 'react';

export type TransportMedicationOption = {
    id: number;
    name: string;
    dosage: string | null;
    frequency: string | null;
    is_prn: boolean;
    controlled_drug: boolean;
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
    packed_witness_name?: string | null;
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
        label: 'Witness & notes',
        blurb: 'Complete administration checks',
        icon: ShieldCheck,
    },
    {
        key: 'review',
        label: 'Review',
        blurb: 'Confirm the administration record',
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
    witnessName,
    notes,
    scan,
}: {
    clientId: number;
    medication: TransportMedicationOption;
    witnessName: string;
    notes: string;
    scan: MedicationScanCapture;
}) {
    return {
        client_id: clientId,
        medication_id: medication.id,
        medication_name: medication.name,
        is_controlled_drug: medication.controlled_drug,
        witness_name: witnessName.trim() || null,
        notes: notes.trim() || null,
        ...toMedicationScanPayload(scan),
    };
}

export function buildAdministerMedicationPayload({
    witnessedByUserId,
    notes,
    scan,
}: {
    witnessedByUserId: string;
    notes: string;
    scan: MedicationScanCapture;
}) {
    return {
        witnessed_by_user_id: witnessedByUserId
            ? Number(witnessedByUserId)
            : null,
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
    onClose,
    onCompleted,
}: {
    open: boolean;
    transportId: number;
    client: { id: number; name: string } | null;
    residentName: string;
    medications: TransportMedicationOption[];
    onClose: () => void;
    onCompleted: MutationCompleted;
}) {
    const [stepIndex, setStepIndex] = useState(0);
    const [scanCapture, setScanCapture] =
        useState<MedicationScanCapture>(emptyMedicationScanCapture());
    const [submitting, setSubmitting] = useState(false);
    const form = useForm({
        medication_id: '',
        witness_name: '',
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
    const requiresWitness = !!selectedMedication?.controlled_drug;
    const requiresScan = !!selectedMedication?.scan_verification;
    const canContinue =
        !!client &&
        !!selectedMedication &&
        (!requiresWitness || !!form.data.witness_name.trim()) &&
        (!requiresScan || hasVerifiedMedicationScan(scanCapture));

    const reset = () => {
        setStepIndex(0);
        form.reset();
        form.clearErrors();
        setScanCapture(emptyMedicationScanCapture());
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
            const result = await submitEmarMutation(
                `/fleet-assets/transports/${transportId}/pack-medication`,
                buildPackMedicationPayload({
                    clientId: client.id,
                    medication: selectedMedication,
                    witnessName: form.data.witness_name,
                    notes: form.data.notes,
                    scan: scanCapture,
                }),
                {
                    successMessage: 'Medication packed for transit.',
                    queuedMessage:
                        'Medication packing saved offline and will sync automatically when the device reconnects.',
                },
            );

            if (result.status === 'conflict') return;
            const queued = result.status === 'queued';
            reset();
            onClose();
            onCompleted(queued);
        } catch (error: unknown) {
            applyFormRequestErrors(
                error,
                (field, value) =>
                    (
                        form.setError as (field: string, value: string) => void
                    )(field, value),
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
            onStepClick={(index) => index === 0 || canContinue ? setStepIndex(index) : undefined}
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
                            Pack medication
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
                                Select an active medication and complete any custody checks.
                            </div>
                        </div>

                        <div className="space-y-2">
                            <Label htmlFor="pack-medication-id">Medication</Label>
                            <Select
                                value={form.data.medication_id || 'none'}
                                onValueChange={(value) => {
                                    form.clearErrors('medication_id');
                                    form.clearErrors('scan_code');
                                    form.setData(
                                        'medication_id',
                                        value === 'none' ? '' : value,
                                    );
                                    setScanCapture(emptyMedicationScanCapture());
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
                                    <Badge variant={selectedMedication.is_prn ? 'secondary' : 'outline'}>
                                        {selectedMedication.is_prn ? 'PRN' : 'Scheduled'}
                                    </Badge>
                                    {selectedMedication.controlled_drug ? (
                                        <Badge variant="destructive">Controlled</Badge>
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
                            <div className="space-y-2">
                                <Label htmlFor="pack-witness-name">
                                    Witness name
                                </Label>
                                <Input
                                    id="pack-witness-name"
                                    value={form.data.witness_name}
                                    onChange={(event) => {
                                        form.clearErrors('witness_name');
                                        form.setData('witness_name', event.target.value);
                                    }}
                                    placeholder="Required for controlled drugs"
                                />
                                {form.errors.witness_name ? (
                                    <p className="text-sm text-destructive">
                                        {form.errors.witness_name}
                                    </p>
                                ) : null}
                            </div>
                        ) : null}

                        {selectedMedication?.scan_verification ? (
                            <MedicationScanVerificationPanel
                                clientId={client?.id ?? null}
                                medicationId={selectedMedication.id}
                                scanVerification={selectedMedication.scan_verification}
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
                                onChange={(event) => form.setData('notes', event.target.value)}
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
                        <h3 className="text-lg font-semibold">Review medication pack</h3>
                        <div className="grid gap-3 sm:grid-cols-2">
                            <ReviewItem label="Resident" value={client?.name ?? residentName} />
                            <ReviewItem
                                label="Medication"
                                value={selectedMedication?.name ?? 'Not selected'}
                            />
                            <ReviewItem
                                label="Controlled drug"
                                value={selectedMedication?.controlled_drug ? 'Yes' : 'No'}
                            />
                            <ReviewItem
                                label="Witness"
                                value={form.data.witness_name.trim() || 'Not required'}
                            />
                        </div>
                        <ReviewItem label="Notes" value={form.data.notes.trim() || 'No notes added'} />
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
    const [scanCapture, setScanCapture] =
        useState<MedicationScanCapture>(emptyMedicationScanCapture());
    const [submitting, setSubmitting] = useState(false);
    const form = useForm({
        witnessed_by_user_id: '',
        notes: '',
        scan_code: '',
    });
    const requiresWitness = !!log?.is_controlled_drug;
    const requiresScan = !!log?.scan_verification;
    const canContinue =
        !!log &&
        (!requiresWitness || !!form.data.witnessed_by_user_id) &&
        (!requiresScan || hasVerifiedMedicationScan(scanCapture));

    const reset = () => {
        setStepIndex(0);
        form.reset();
        form.clearErrors();
        setScanCapture(emptyMedicationScanCapture());
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
            const result = await submitEmarMutation(
                `/fleet-assets/medication-transit/${log.id}/administer`,
                buildAdministerMedicationPayload({
                    witnessedByUserId: form.data.witnessed_by_user_id,
                    notes: form.data.notes,
                    scan: scanCapture,
                }),
                {
                    successMessage: 'Medication administration recorded.',
                    queuedMessage:
                        'Medication transit administration saved offline and will sync automatically when the device reconnects.',
                },
            );
            if (result.status === 'conflict') return;
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
            description="Complete witness and verification checks, then review before recording this administration."
            railIcon={CheckCircle}
            railTitle="Administration"
            railSub={log?.client?.name ?? 'Medication transit'}
            steps={administerSteps}
            stepIndex={stepIndex}
            onStepClick={(index) => index === 0 || canContinue ? setStepIndex(index) : undefined}
            pct={stepIndex === 0 ? (canContinue ? 50 : 25) : 100}
            footerStart={
                <Button type="button" variant="ghost" onClick={close}>
                    Cancel
                </Button>
            }
            footerEnd={
                stepIndex === 0 ? (
                    <Button type="button" onClick={() => setStepIndex(1)} disabled={!canContinue}>
                        Continue
                    </Button>
                ) : (
                    <>
                        <Button type="button" variant="outline" onClick={() => setStepIndex(0)}>
                            <ArrowLeft className="mr-2 h-4 w-4" />
                            Back
                        </Button>
                        <Button type="button" onClick={submit} disabled={submitting || !canContinue}>
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
                        {requiresWitness ? (
                            <div className="space-y-2">
                                <Label htmlFor="administer-witness">Witness</Label>
                                <Select
                                    value={form.data.witnessed_by_user_id || 'none'}
                                    onValueChange={(value) => {
                                        form.clearErrors('witnessed_by_user_id');
                                        form.setData(
                                            'witnessed_by_user_id',
                                            value === 'none' ? '' : value,
                                        );
                                    }}
                                >
                                    <SelectTrigger id="administer-witness">
                                        <SelectValue placeholder="Select witness" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="none">Select witness</SelectItem>
                                        {witnesses.map((witness) => (
                                            <SelectItem key={witness.id} value={String(witness.id)}>
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
                            <p className="text-sm text-destructive">{form.errors.scan_code}</p>
                        ) : null}
                        <div className="space-y-2">
                            <Label htmlFor="administer-notes">Notes</Label>
                            <Textarea
                                id="administer-notes"
                                value={form.data.notes}
                                onChange={(event) => form.setData('notes', event.target.value)}
                                placeholder="Add any transport administration notes..."
                            />
                            {form.errors.notes ? (
                                <p className="text-sm text-destructive">{form.errors.notes}</p>
                            ) : null}
                        </div>
                    </div>
                ) : (
                    <div className="space-y-4">
                        <h3 className="text-lg font-semibold">Review administration</h3>
                        <div className="grid gap-3 sm:grid-cols-2">
                            <ReviewItem label="Medication" value={log?.medication_name ?? '---'} />
                            <ReviewItem label="Resident" value={log?.client?.name ?? '---'} />
                            <ReviewItem
                                label="Witness"
                                value={
                                    witnesses.find(
                                        (witness) =>
                                            String(witness.id) === form.data.witnessed_by_user_id,
                                    )?.name ?? 'Not required'
                                }
                            />
                            <ReviewItem label="Verification" value={requiresScan ? 'Verified' : 'Not required'} />
                        </div>
                        <ReviewItem label="Notes" value={form.data.notes.trim() || 'No notes added'} />
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
    const [scanCapture, setScanCapture] =
        useState<MedicationScanCapture>(emptyMedicationScanCapture());
    const [submitting, setSubmitting] = useState(false);
    const form = useForm({ notes: '', scan_code: '' });
    const requiresScan = !!log?.scan_verification;
    const canContinue =
        !!log && (!requiresScan || hasVerifiedMedicationScan(scanCapture));

    const reset = () => {
        setStepIndex(0);
        form.reset();
        form.clearErrors();
        setScanCapture(emptyMedicationScanCapture());
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
            const result = await submitEmarMutation(
                `/fleet-assets/medication-transit/${log.id}/return`,
                buildReturnMedicationPayload({
                    notes: form.data.notes,
                    scan: scanCapture,
                }),
                {
                    successMessage: 'Medication return recorded.',
                    queuedMessage:
                        'Medication return saved offline and will sync automatically when the device reconnects.',
                },
            );
            if (result.status === 'conflict') return;
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
            onStepClick={(index) => index === 0 || canContinue ? setStepIndex(index) : undefined}
            pct={stepIndex === 0 ? (canContinue ? 50 : 25) : 100}
            footerStart={
                <Button type="button" variant="ghost" onClick={close}>
                    Cancel
                </Button>
            }
            footerEnd={
                stepIndex === 0 ? (
                    <Button type="button" onClick={() => setStepIndex(1)} disabled={!canContinue}>
                        Continue
                    </Button>
                ) : (
                    <>
                        <Button type="button" variant="outline" onClick={() => setStepIndex(0)}>
                            <ArrowLeft className="mr-2 h-4 w-4" />
                            Back
                        </Button>
                        <Button type="button" onClick={submit} disabled={submitting || !canContinue}>
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
                            <p className="text-sm text-destructive">{form.errors.scan_code}</p>
                        ) : null}
                        <div className="space-y-2">
                            <Label htmlFor="return-notes">Return notes</Label>
                            <Textarea
                                id="return-notes"
                                value={form.data.notes}
                                onChange={(event) => form.setData('notes', event.target.value)}
                                placeholder="Add any hand-back or chain-of-custody notes..."
                            />
                            {form.errors.notes ? (
                                <p className="text-sm text-destructive">{form.errors.notes}</p>
                            ) : null}
                        </div>
                    </div>
                ) : (
                    <div className="space-y-4">
                        <h3 className="text-lg font-semibold">Review medication return</h3>
                        <div className="grid gap-3 sm:grid-cols-2">
                            <ReviewItem label="Medication" value={log?.medication_name ?? '---'} />
                            <ReviewItem label="Resident" value={log?.client?.name ?? '---'} />
                            <ReviewItem label="Destination" value="House stock" />
                            <ReviewItem label="Verification" value={requiresScan ? 'Verified' : 'Not required'} />
                        </div>
                        <ReviewItem label="Return notes" value={form.data.notes.trim() || 'No notes added'} />
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
