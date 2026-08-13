import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
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
import axios from 'axios';
import {
    AlertTriangle,
    Clock,
    Pill,
    QrCode,
    ShieldCheck,
    User,
} from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import PrnHistoryPanel from './PrnHistoryPanel';
import SafetyCheckPanel, { type SafetyCheck } from './SafetyCheckPanel';
import SpecialistAdminFields from './SpecialistAdminFields';

interface Witness {
    id: number;
    name: string;
}

interface ScanVerification {
    primary_code: string;
    primary_label: string;
    primary_source: string;
    internal_code: string;
    vendor_barcode?: string | null;
    nzulm_code?: string | null;
    requires_internal_code: boolean;
    svg_url: string;
    code_options: Array<{
        source: string;
        label: string;
        value: string;
    }>;
}

interface Medication {
    id: number;
    name: string;
    dosage: string;
    route?: string;
    form?: string;
    is_prn: boolean;
    prn_reason?: string;
    controlled_drug: boolean;
    high_risk: boolean;
    witness_required: boolean;
    admin_rules?: {
        requires_countersign?: boolean;
        required_observations?: string[];
    };
    instructions?: string;
    stock?: { on_hand: number; unit: string } | null;
    scan_verification?: ScanVerification | null;
}

interface PrnData {
    history: Array<{
        id: number;
        administered_at: string;
        dose_given?: string;
        reason?: string;
        administered_by?: string;
    }>;
    count: number;
    max_per_day?: string;
    remaining_today?: number;
}

interface Props {
    isOpen: boolean;
    onClose: () => void;
    onSubmit: (data: Record<string, unknown>) => void;
    medication: Medication | null;
    clientId: number | null;
    scheduledTime?: string | null;
    witnesses: Witness[];
    currentUserId: number;
    safetyCheck: SafetyCheck | null;
    prnData?: PrnData | null;
    isLoading?: boolean;
}

const statusOptions = [
    { value: 'given', label: 'Given', color: 'text-status-success' },
    { value: 'refused', label: 'Refused', color: 'text-status-warning' },
    { value: 'withheld', label: 'Withheld', color: 'text-status-warning' },
    { value: 'missed', label: 'Missed', color: 'text-status-critical' },
];

const notGivenReasonOptions = [
    { value: 'absent', label: 'Absent' },
    { value: 'destroyed', label: 'Destroyed' },
    { value: 'doctors_instruction', label: "Doctor's instruction" },
    { value: 'fasting', label: 'Fasting' },
    { value: 'transferred', label: 'Transferred' },
    { value: 'refused', label: 'Refused' },
    { value: 'social_leave', label: 'Social leave' },
    { value: 'hospitalised', label: 'Hospitalised' },
    { value: 'medication_unavailable', label: 'Medication unavailable' },
    { value: 'vomit_or_nausea', label: 'Vomit or nausea' },
    { value: 'self_administered', label: 'Self-administered' },
    { value: 'withheld', label: 'Withheld' },
    { value: 'other', label: 'Other' },
];

export default function RecordAdministrationDialog({
    isOpen,
    onClose,
    onSubmit,
    medication,
    clientId,
    scheduledTime,
    witnesses,
    currentUserId,
    safetyCheck,
    prnData,
    isLoading,
}: Props) {
    const [status, setStatus] = useState('given');
    const [reasonCode, setReasonCode] = useState('');
    const [reason, setReason] = useState('');
    const [doseGiven, setDoseGiven] = useState('');
    const [notes, setNotes] = useState('');
    const [administeredAt, setAdministeredAt] = useState('');
    const [witnessedBy, setWitnessedBy] = useState('');
    const [witnessCredential, setWitnessCredential] = useState('');
    const [quantityAdministered, setQuantityAdministered] = useState('');
    const [outcome, setOutcome] = useState('');
    const [site, setSite] = useState('');
    const [bloodGlucoseLevel, setBloodGlucoseLevel] = useState('');
    const [pulseBpm, setPulseBpm] = useState('');
    const [bloodPressureSystolic, setBloodPressureSystolic] = useState('');
    const [bloodPressureDiastolic, setBloodPressureDiastolic] = useState('');
    const [showOverride, setShowOverride] = useState(false);
    const [overrideReasonCode, setOverrideReasonCode] = useState('');
    const [overrideReason, setOverrideReason] = useState('');
    const [specialistFields, setSpecialistFields] = useState<
        Record<string, unknown>
    >({});
    const [scanCode, setScanCode] = useState('');
    const [scanStatus, setScanStatus] = useState<
        'idle' | 'verified' | 'mismatch'
    >('idle');
    const [scanMessage, setScanMessage] = useState('');
    const [scanMatchSource, setScanMatchSource] = useState<string | null>(null);
    const [verifyingScan, setVerifyingScan] = useState(false);

    const medicationId = medication?.id;
    const medicationDosage = medication?.dosage;

    useEffect(() => {
        if (isOpen && medicationId != null) {
            setStatus('given');
            setReasonCode('');
            setReason('');
            setDoseGiven(medicationDosage || '');
            setNotes('');
            setAdministeredAt(new Date().toISOString().slice(0, 16));
            setWitnessedBy('');
            setWitnessCredential('');
            setQuantityAdministered('');
            setOutcome('');
            setSite('');
            setBloodGlucoseLevel('');
            setPulseBpm('');
            setBloodPressureSystolic('');
            setBloodPressureDiastolic('');
            setShowOverride(false);
            setOverrideReasonCode('');
            setOverrideReason('');
            setSpecialistFields({});
            setScanCode('');
            setScanStatus('idle');
            setScanMessage('');
            setScanMatchSource(null);
        }
    }, [isOpen, medicationId, medicationDosage]);

    const needsReason = useMemo(
        () => status !== 'given' || !!medication?.is_prn,
        [status, medication],
    );

    const requiredObservations = useMemo(
        () => medication?.admin_rules?.required_observations ?? [],
        [medication],
    );

    const needsWitness = useMemo(() => {
        if (status !== 'given') return false;
        return (
            medication?.controlled_drug ||
            medication?.witness_required ||
            medication?.admin_rules?.requires_countersign ||
            false
        );
    }, [status, medication]);

    const needsScanVerification = useMemo(() => {
        return status === 'given' && !!medication?.scan_verification;
    }, [medication, status]);

    const availableWitnesses = useMemo(() => {
        return witnesses.filter((w) => w.id !== currentUserId);
    }, [witnesses, currentUserId]);

    const canSubmit = useMemo(() => {
        if (!safetyCheck) return false;
        if (safetyCheck.blocked && !showOverride) return false;
        if (
            showOverride &&
            (!safetyCheck.can_override_safety ||
                !overrideReasonCode ||
                overrideReason.trim().length < 10)
        )
            return false;
        if (status !== 'given' && !reasonCode) return false;
        if (reasonCode === 'other' && !reason.trim()) return false;
        if (status === 'given' && medication?.is_prn && !reason.trim())
            return false;
        if (needsWitness && (!witnessedBy || !witnessCredential.trim()))
            return false;
        if (
            status === 'given' &&
            medication?.controlled_drug &&
            quantityAdministered.trim() === ''
        )
            return false;
        if (
            status === 'given' &&
            requiredObservations.includes('bsl') &&
            bloodGlucoseLevel.trim() === ''
        )
            return false;
        if (
            status === 'given' &&
            requiredObservations.includes('pulse') &&
            pulseBpm.trim() === ''
        )
            return false;
        if (
            status === 'given' &&
            requiredObservations.includes('blood_pressure') &&
            (bloodPressureSystolic.trim() === '' ||
                bloodPressureDiastolic.trim() === '')
        )
            return false;
        if (needsScanVerification && scanStatus !== 'verified') return false;
        return true;
    }, [
        bloodGlucoseLevel,
        bloodPressureDiastolic,
        bloodPressureSystolic,
        medication,
        needsScanVerification,
        needsWitness,
        overrideReason,
        overrideReasonCode,
        pulseBpm,
        quantityAdministered,
        reason,
        reasonCode,
        requiredObservations,
        safetyCheck,
        scanStatus,
        showOverride,
        status,
        witnessCredential,
        witnessedBy,
    ]);

    const handleSubmit = () => {
        if (!canSubmit) return;

        const data: Record<string, unknown> = {
            status,
            reason: reason || null,
            reason_code: status !== 'given' ? reasonCode : null,
            dose_given: doseGiven || null,
            quantity_administered: quantityAdministered
                ? Number(quantityAdministered)
                : null,
            notes: notes || null,
            administered_at: administeredAt
                ? new Date(administeredAt).toISOString()
                : null,
            witnessed_by: witnessedBy ? parseInt(witnessedBy, 10) : null,
            witness_credential: witnessCredential || null,
            scheduled_for: scheduledTime || null,
            outcome: outcome || null,
            site: site || null,
            blood_glucose_level: bloodGlucoseLevel || null,
            pulse_bpm: pulseBpm || null,
            blood_pressure_systolic: bloodPressureSystolic || null,
            blood_pressure_diastolic: bloodPressureDiastolic || null,
            ...specialistFields,
        };

        if (showOverride) {
            data.safety_override = {
                reason_code: overrideReasonCode,
                reason: overrideReason.trim(),
            };
        }

        if (
            needsScanVerification &&
            scanStatus === 'verified' &&
            scanCode.trim()
        ) {
            data.scan_code = scanCode.trim();
            data.scan_source = 'manual';
            data.scan_verified = true;
            data.scan_match_source = scanMatchSource;
        }

        onSubmit(data);
    };

    async function handleVerifyScan() {
        if (!clientId || !medication || !scanCode.trim()) {
            return;
        }

        setVerifyingScan(true);

        try {
            const response = await axios.post(
                `/api/medications/clients/${clientId}/medications/${medication.id}/scan-verify`,
                {
                    code: scanCode.trim(),
                    source: 'manual',
                },
            );

            setScanStatus('verified');
            setScanMessage(
                response.data.message ?? 'Medication code verified.',
            );
            setScanMatchSource(response.data.match_source ?? null);
        } catch (error: unknown) {
            const message = axios.isAxiosError(error)
                ? error.response?.data?.message ||
                  error.response?.data?.error ||
                  'This code does not match the selected medication.'
                : 'This code does not match the selected medication.';

            setScanStatus('mismatch');
            setScanMessage(message);
            setScanMatchSource(null);
        } finally {
            setVerifyingScan(false);
        }
    }

    if (!medication) return null;

    return (
        <Dialog open={isOpen} onOpenChange={onClose}>
            <DialogContent
                className="grid max-h-[90dvh] w-[calc(100vw-1rem)] max-w-[calc(100vw-1rem)] grid-rows-[auto_minmax(0,1fr)_auto] overflow-hidden sm:max-w-3xl"
                data-test="record-administration-dialog"
            >
                <DialogHeader>
                    <DialogTitle className="flex items-center gap-2">
                        <Pill className="h-5 w-5" />
                        Record Administration
                    </DialogTitle>
                    <DialogDescription>
                        Record the medication outcome, required checks, and any
                        observations needed for this dose.
                    </DialogDescription>
                </DialogHeader>

                <div className="min-h-0 space-y-4 overflow-y-auto pr-1">
                    <div className="rounded-md bg-muted p-3">
                        <div className="font-medium">{medication.name}</div>
                        <div className="text-sm text-muted-foreground">
                            {medication.dosage}
                            {medication.route && ` • ${medication.route}`}
                            {medication.form && ` • ${medication.form}`}
                        </div>
                        {medication.is_prn && (
                            <Badge
                                variant="outline"
                                className="mt-2 bg-primary/10 text-primary"
                            >
                                PRN
                            </Badge>
                        )}
                        {medication.controlled_drug && (
                            <Badge
                                variant="outline"
                                className="mt-2 ml-2 bg-status-critical-bg text-status-critical"
                            >
                                Controlled
                            </Badge>
                        )}
                        {medication.high_risk && (
                            <Badge
                                variant="outline"
                                className="mt-2 ml-2 bg-status-warning-bg text-status-warning"
                            >
                                High Risk
                            </Badge>
                        )}
                        {scheduledTime && (
                            <div className="mt-2 flex items-center gap-1 text-xs text-muted-foreground">
                                <Clock className="h-3 w-3" />
                                Scheduled:{' '}
                                {new Date(scheduledTime).toLocaleTimeString(
                                    [],
                                    { hour: '2-digit', minute: '2-digit' },
                                )}
                            </div>
                        )}
                    </div>

                    <SafetyCheckPanel
                        safetyCheck={safetyCheck}
                        onOverride={
                            safetyCheck?.blocked &&
                            safetyCheck.can_override_safety
                                ? () => setShowOverride(true)
                                : undefined
                        }
                    />

                    {showOverride && (
                        <div className="space-y-3 rounded-md border border-status-critical/30 bg-status-critical-bg p-4">
                            <div>
                                <div className="font-medium text-status-critical">
                                    Safety override authorisation
                                </div>
                                <p className="text-sm text-muted-foreground">
                                    Record the clinical basis for proceeding
                                    despite this specific blocked check.
                                </p>
                            </div>
                            <div className="space-y-2">
                                <Label>Override reason *</Label>
                                <Select
                                    value={overrideReasonCode}
                                    onValueChange={setOverrideReasonCode}
                                >
                                    <SelectTrigger>
                                        <SelectValue placeholder="Select reason..." />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {safetyCheck?.override_reason_options?.map(
                                            (option) => (
                                                <SelectItem
                                                    key={option.value}
                                                    value={option.value}
                                                >
                                                    {option.label}
                                                </SelectItem>
                                            ),
                                        )}
                                    </SelectContent>
                                </Select>
                            </div>
                            <div className="space-y-2">
                                <Label>Clinical rationale *</Label>
                                <Textarea
                                    value={overrideReason}
                                    onChange={(event) =>
                                        setOverrideReason(event.target.value)
                                    }
                                    placeholder="Who provided direction and why is administration clinically necessary?"
                                    className="min-h-[72px]"
                                />
                            </div>
                        </div>
                    )}

                    {medication.is_prn && prnData && (
                        <PrnHistoryPanel
                            history={prnData.history}
                            count24h={prnData.count}
                            maxPerDay={prnData.max_per_day}
                            remainingToday={prnData.remaining_today}
                        />
                    )}

                    {medication.scan_verification && status === 'given' && (
                        <div className="space-y-3 rounded-md border p-4">
                            <div className="flex items-center gap-2">
                                <QrCode className="h-4 w-4" />
                                <div className="font-medium">
                                    Medication Scan Verification
                                </div>
                            </div>

                            <p className="text-sm text-muted-foreground">
                                {medication.scan_verification
                                    .requires_internal_code
                                    ? 'No supplier barcode is on file for this medication. Use the internal eMAR QR/code below.'
                                    : 'Scan the pack barcode or enter one of the registered medication codes before recording a given dose.'}
                            </p>

                            <div className="grid min-w-0 gap-4 md:grid-cols-[1fr_140px]">
                                <div className="min-w-0 space-y-3">
                                    <div className="space-y-2">
                                        <Label>Scanned or entered code</Label>
                                        <div className="flex min-w-0 gap-2">
                                            <Input
                                                className="min-w-0"
                                                value={scanCode}
                                                onChange={(event) => {
                                                    setScanCode(
                                                        event.target.value,
                                                    );
                                                    setScanStatus('idle');
                                                    setScanMessage('');
                                                    setScanMatchSource(null);
                                                }}
                                                placeholder={`Enter ${medication.scan_verification.primary_label.toLowerCase()}...`}
                                            />
                                            <Button
                                                type="button"
                                                variant="outline"
                                                onClick={handleVerifyScan}
                                                disabled={
                                                    !scanCode.trim() ||
                                                    verifyingScan
                                                }
                                                data-test="record-administration-scan-verify"
                                            >
                                                {verifyingScan
                                                    ? 'Checking...'
                                                    : 'Verify'}
                                            </Button>
                                        </div>
                                        {scanMessage ? (
                                            <div
                                                className={`flex items-center gap-2 text-xs ${
                                                    scanStatus === 'verified'
                                                        ? 'text-status-success'
                                                        : 'text-status-critical'
                                                }`}
                                            >
                                                {scanStatus === 'verified' ? (
                                                    <ShieldCheck className="h-3.5 w-3.5" />
                                                ) : (
                                                    <AlertTriangle className="h-3.5 w-3.5" />
                                                )}
                                                {scanMessage}
                                            </div>
                                        ) : (
                                            <div className="text-xs text-muted-foreground">
                                                Verification is required before
                                                recording a given dose.
                                            </div>
                                        )}
                                    </div>

                                    <div className="space-y-2">
                                        <Label>Codes on file</Label>
                                        <div className="flex flex-wrap gap-2">
                                            {medication.scan_verification.code_options.map(
                                                (option) => (
                                                    <Button
                                                        key={`${option.source}-${option.value}`}
                                                        type="button"
                                                        variant="secondary"
                                                        size="sm"
                                                        className="h-auto max-w-full justify-start px-2 py-1 text-left font-mono text-xs break-all whitespace-normal"
                                                        data-test="record-administration-scan-code"
                                                        onClick={() => {
                                                            setScanCode(
                                                                option.value,
                                                            );
                                                            setScanStatus(
                                                                'idle',
                                                            );
                                                            setScanMessage('');
                                                            setScanMatchSource(
                                                                null,
                                                            );
                                                        }}
                                                    >
                                                        {option.label}:{' '}
                                                        {option.value}
                                                    </Button>
                                                ),
                                            )}
                                        </div>
                                    </div>
                                </div>

                                <div className="space-y-2">
                                    {/* eslint-disable-next-line no-restricted-syntax -- QR code needs a tight white scan surface inside the medication dialog. */}
                                    <div className="rounded-md border bg-white p-2">
                                        <img
                                            src={
                                                medication.scan_verification
                                                    .svg_url
                                            }
                                            alt="Medication QR code"
                                            className="h-28 w-28"
                                        />
                                    </div>
                                    <div className="text-center text-[11px] text-muted-foreground">
                                        Internal eMAR QR
                                    </div>
                                </div>
                            </div>
                        </div>
                    )}

                    <div className="space-y-3">
                        <div>
                            <Label>Status *</Label>
                            <Select value={status} onValueChange={setStatus}>
                                <SelectTrigger>
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {statusOptions.map((opt) => (
                                        <SelectItem
                                            key={opt.value}
                                            value={opt.value}
                                        >
                                            <span className={opt.color}>
                                                {opt.label}
                                            </span>
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>

                        <div>
                            <Label>Administered At</Label>
                            <Input
                                type="datetime-local"
                                value={administeredAt}
                                onChange={(e) =>
                                    setAdministeredAt(e.target.value)
                                }
                            />
                        </div>

                        <div>
                            <Label>Dose Given</Label>
                            <Input
                                value={doseGiven}
                                onChange={(e) => setDoseGiven(e.target.value)}
                                placeholder={
                                    medication.dosage || 'e.g., 1 tablet'
                                }
                            />
                        </div>

                        {status !== 'given' && (
                            <div className="space-y-2">
                                <Label>Reason Not Given *</Label>
                                <div className="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                                    {notGivenReasonOptions.map((option) => (
                                        <Button
                                            key={option.value}
                                            type="button"
                                            variant={
                                                reasonCode === option.value
                                                    ? 'default'
                                                    : 'outline'
                                            }
                                            className="h-auto min-h-10 justify-start text-left whitespace-normal"
                                            onClick={() =>
                                                setReasonCode(option.value)
                                            }
                                        >
                                            {option.label}
                                        </Button>
                                    ))}
                                </div>
                                {reasonCode === 'other' && (
                                    <Textarea
                                        value={reason}
                                        onChange={(e) =>
                                            setReason(e.target.value)
                                        }
                                        placeholder="Add the reason not covered by the standard list."
                                        className="min-h-[60px]"
                                    />
                                )}
                            </div>
                        )}

                        {status === 'given' && medication.is_prn && (
                            <div>
                                <Label>PRN Indication *</Label>
                                <Textarea
                                    value={reason}
                                    onChange={(e) => setReason(e.target.value)}
                                    placeholder="Why is this PRN being given?"
                                    className="min-h-[60px]"
                                />
                            </div>
                        )}

                        {status === 'given' && medication.controlled_drug && (
                            <div>
                                <Label>Units Given *</Label>
                                <Input
                                    type="number"
                                    min={0.25}
                                    step={0.25}
                                    value={quantityAdministered}
                                    onChange={(event) =>
                                        setQuantityAdministered(
                                            event.target.value,
                                        )
                                    }
                                    placeholder="e.g. 1"
                                    className="max-w-[220px]"
                                />
                                <p className="mt-1 text-xs text-muted-foreground">
                                    Removed from CD stock — the register entry
                                    uses this quantity.
                                </p>
                            </div>
                        )}

                        {needsWitness && (
                            <div>
                                <Label className="flex items-center gap-1">
                                    <User className="h-3 w-3" />
                                    Witness *
                                </Label>
                                <Select
                                    value={witnessedBy}
                                    onValueChange={setWitnessedBy}
                                >
                                    <SelectTrigger>
                                        <SelectValue placeholder="Select witness..." />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {availableWitnesses.map((w) => (
                                            <SelectItem
                                                key={w.id}
                                                value={String(w.id)}
                                            >
                                                {w.name}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                <p className="mt-1 text-xs text-muted-foreground">
                                    The second checker must enter their own
                                    password or PIN before the dose is saved.
                                </p>
                                <div className="mt-3">
                                    <Label>Witness Password / PIN *</Label>
                                    <Input
                                        type="password"
                                        value={witnessCredential}
                                        onChange={(event) =>
                                            setWitnessCredential(
                                                event.target.value,
                                            )
                                        }
                                        placeholder="Second checker credential"
                                    />
                                </div>
                            </div>
                        )}

                        {status === 'given' && (
                            <>
                                {requiredObservations.length > 0 && (
                                    <div className="space-y-3 rounded-md border p-3">
                                        <div className="font-medium">
                                            Required observations
                                        </div>
                                        {requiredObservations.includes(
                                            'bsl',
                                        ) && (
                                            <div>
                                                <Label>BSL *</Label>
                                                <Input
                                                    type="number"
                                                    step="0.1"
                                                    value={bloodGlucoseLevel}
                                                    onChange={(event) =>
                                                        setBloodGlucoseLevel(
                                                            event.target.value,
                                                        )
                                                    }
                                                    placeholder="e.g., 6.4"
                                                />
                                            </div>
                                        )}
                                        {requiredObservations.includes(
                                            'pulse',
                                        ) && (
                                            <div>
                                                <Label>Pulse *</Label>
                                                <Input
                                                    type="number"
                                                    inputMode="numeric"
                                                    value={pulseBpm}
                                                    onChange={(event) =>
                                                        setPulseBpm(
                                                            event.target.value,
                                                        )
                                                    }
                                                    placeholder="e.g., 72"
                                                />
                                            </div>
                                        )}
                                        {requiredObservations.includes(
                                            'blood_pressure',
                                        ) && (
                                            <div className="grid gap-3 sm:grid-cols-2">
                                                <div>
                                                    <Label>Systolic *</Label>
                                                    <Input
                                                        type="number"
                                                        inputMode="numeric"
                                                        value={
                                                            bloodPressureSystolic
                                                        }
                                                        onChange={(event) =>
                                                            setBloodPressureSystolic(
                                                                event.target
                                                                    .value,
                                                            )
                                                        }
                                                        placeholder="e.g., 120"
                                                    />
                                                </div>
                                                <div>
                                                    <Label>Diastolic *</Label>
                                                    <Input
                                                        type="number"
                                                        inputMode="numeric"
                                                        value={
                                                            bloodPressureDiastolic
                                                        }
                                                        onChange={(event) =>
                                                            setBloodPressureDiastolic(
                                                                event.target
                                                                    .value,
                                                            )
                                                        }
                                                        placeholder="e.g., 80"
                                                    />
                                                </div>
                                            </div>
                                        )}
                                    </div>
                                )}

                                <div>
                                    <Label>Outcome (Optional)</Label>
                                    <Select
                                        value={outcome}
                                        onValueChange={setOutcome}
                                    >
                                        <SelectTrigger>
                                            <SelectValue placeholder="Select outcome..." />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="effective">
                                                Effective
                                            </SelectItem>
                                            <SelectItem value="ineffective">
                                                Ineffective
                                            </SelectItem>
                                            <SelectItem value="adverse_reaction">
                                                Adverse Reaction
                                            </SelectItem>
                                        </SelectContent>
                                    </Select>
                                </div>

                                <div>
                                    <Label>Site (Optional)</Label>
                                    <Input
                                        value={site}
                                        onChange={(e) =>
                                            setSite(e.target.value)
                                        }
                                        placeholder="e.g., Left arm, Oral"
                                    />
                                </div>
                            </>
                        )}

                        <div>
                            <Label>Notes (Optional)</Label>
                            <Textarea
                                value={notes}
                                onChange={(e) => setNotes(e.target.value)}
                                placeholder="Additional notes..."
                                className="min-h-[60px]"
                            />
                        </div>

                        {status === 'given' && (
                            <SpecialistAdminFields
                                medication={medication}
                                form={specialistFields}
                                errors={{}}
                                onChange={(field, value) =>
                                    setSpecialistFields((prev) => ({
                                        ...prev,
                                        [field]: value,
                                    }))
                                }
                            />
                        )}
                    </div>
                </div>

                <DialogFooter className="gap-2 border-t pt-4">
                    <Button
                        variant="outline"
                        onClick={onClose}
                        disabled={isLoading}
                    >
                        Cancel
                    </Button>
                    <Button
                        onClick={handleSubmit}
                        disabled={!canSubmit || isLoading}
                        data-test="record-administration-submit"
                    >
                        {isLoading ? 'Saving...' : 'Record Administration'}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
