import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
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
    const [reason, setReason] = useState('');
    const [doseGiven, setDoseGiven] = useState('');
    const [notes, setNotes] = useState('');
    const [administeredAt, setAdministeredAt] = useState('');
    const [witnessedBy, setWitnessedBy] = useState('');
    const [outcome, setOutcome] = useState('');
    const [site, setSite] = useState('');
    const [showOverride, setShowOverride] = useState(false);
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

    useEffect(() => {
        if (isOpen && medication) {
            setStatus('given');
            setReason('');
            setDoseGiven(medication.dosage || '');
            setNotes('');
            setAdministeredAt(new Date().toISOString().slice(0, 16));
            setWitnessedBy('');
            setOutcome('');
            setSite('');
            setShowOverride(false);
            setSpecialistFields({});
            setScanCode('');
            setScanStatus('idle');
            setScanMessage('');
            setScanMatchSource(null);
        }
    }, [isOpen, medication?.id, medication?.dosage]);

    const needsReason = useMemo(() => {
        if (status !== 'given') return true;
        if (medication?.is_prn) return true;
        return false;
    }, [status, medication]);

    const needsWitness = useMemo(() => {
        if (status !== 'given') return false;
        return (
            medication?.controlled_drug || medication?.witness_required || false
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
        if (needsReason && !reason.trim()) return false;
        if (needsWitness && !witnessedBy) return false;
        if (needsScanVerification && scanStatus !== 'verified') return false;
        return true;
    }, [
        needsReason,
        needsScanVerification,
        needsWitness,
        reason,
        safetyCheck,
        scanStatus,
        showOverride,
        witnessedBy,
    ]);

    const handleSubmit = () => {
        if (!canSubmit) return;

        const data: Record<string, unknown> = {
            status,
            reason: reason || null,
            dose_given: doseGiven || null,
            notes: notes || null,
            administered_at: administeredAt
                ? new Date(administeredAt).toISOString()
                : null,
            witnessed_by: witnessedBy ? parseInt(witnessedBy, 10) : null,
            scheduled_for: scheduledTime || null,
            outcome: outcome || null,
            site: site || null,
            ...specialistFields,
        };

        if (showOverride) {
            data.override_safety = true;
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
                            safetyCheck?.blocked
                                ? () => setShowOverride(true)
                                : undefined
                        }
                    />

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
                                                        className="h-auto max-w-full justify-start whitespace-normal break-all px-2 py-1 text-left font-mono text-xs"
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

                        {needsReason && (
                            <div>
                                <Label>
                                    Reason / Indication *
                                    {medication.is_prn &&
                                        status === 'given' && (
                                            <span className="ml-1 text-xs text-muted-foreground">
                                                (PRN indication required)
                                            </span>
                                        )}
                                </Label>
                                <Textarea
                                    value={reason}
                                    onChange={(e) => setReason(e.target.value)}
                                    placeholder={
                                        status === 'given' && medication.is_prn
                                            ? 'Why is this PRN being given?'
                                            : 'Why was medication not given?'
                                    }
                                    className="min-h-[60px]"
                                />
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
                                    A witness is required for controlled drugs
                                </p>
                            </div>
                        )}

                        {status === 'given' && (
                            <>
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
