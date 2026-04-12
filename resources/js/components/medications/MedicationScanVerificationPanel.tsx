import {
    emptyMedicationScanCapture,
    type MedicationScanCapture,
    type MedicationScanVerification,
    verifyMedicationScan,
} from '@/lib/medication-scan';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { AlertTriangle, QrCode, ShieldCheck } from 'lucide-react';
import axios from 'axios';
import { useEffect, useMemo, useState } from 'react';

type Props = {
    clientId: number | null;
    medicationId: number | null;
    scanVerification?: MedicationScanVerification | null;
    onChange?: (capture: MedicationScanCapture) => void;
    resetKey?: string | number;
    title?: string;
    requirementText?: string;
    className?: string;
    qrSizeClassName?: string;
};

export default function MedicationScanVerificationPanel({
    clientId,
    medicationId,
    scanVerification,
    onChange,
    resetKey,
    title = 'Medication scan verification',
    requirementText = 'Verification is required before saving this medication action.',
    className,
    qrSizeClassName = 'h-24 w-24',
}: Props) {
    const [capture, setCapture] = useState<MedicationScanCapture>(
        emptyMedicationScanCapture(),
    );
    const [verifying, setVerifying] = useState(false);

    useEffect(() => {
        setCapture(emptyMedicationScanCapture());
        setVerifying(false);
    }, [resetKey, medicationId]);

    useEffect(() => {
        onChange?.(capture);
    }, [capture, onChange]);

    const description = useMemo(() => {
        if (!scanVerification) {
            return '';
        }

        return scanVerification.requires_internal_code
            ? 'No supplier barcode is on file for this medication. Use the internal eMAR QR/code below.'
            : 'Verify the pack barcode or one of the registered medication codes before continuing.';
    }, [scanVerification]);

    if (!scanVerification) {
        return null;
    }

    async function handleVerifyScan() {
        if (!clientId || !medicationId || !capture.code.trim()) {
            return;
        }

        setVerifying(true);

        try {
            const response = await verifyMedicationScan(
                clientId,
                medicationId,
                capture.code.trim(),
                capture.scanSource,
            );

            setCapture((current) => ({
                ...current,
                status: 'verified',
                message: response.message ?? 'Medication code verified.',
                matchSource: response.match_source ?? null,
            }));
        } catch (error: unknown) {
            const message = axios.isAxiosError(error)
                ? error.response?.data?.message ||
                  error.response?.data?.error ||
                  'This code does not match the selected medication.'
                : 'This code does not match the selected medication.';

            setCapture((current) => ({
                ...current,
                status: 'mismatch',
                message,
                matchSource: null,
            }));
        } finally {
            setVerifying(false);
        }
    }

    return (
        <div className={className ?? 'space-y-3 rounded-md border p-4'}>
            <div className="flex items-center gap-2">
                <QrCode className="h-4 w-4" />
                <div className="text-sm font-medium">{title}</div>
            </div>

            <div className="text-xs text-muted-foreground">{description}</div>

            <div className="grid gap-4 md:grid-cols-[1fr_120px]">
                <div className="space-y-3">
                    <div className="space-y-1">
                        <Label>Scanned or entered code</Label>
                        <div className="flex gap-2">
                            <Input
                                value={capture.code}
                                onChange={(event) =>
                                    setCapture((current) => ({
                                        ...current,
                                        code: event.target.value,
                                        status: 'idle',
                                        message: '',
                                        matchSource: null,
                                    }))
                                }
                                placeholder={`Enter ${scanVerification.primary_label.toLowerCase()}...`}
                            />
                            <Button
                                type="button"
                                variant="outline"
                                onClick={handleVerifyScan}
                                disabled={!capture.code.trim() || verifying}
                            >
                                {verifying ? 'Checking...' : 'Verify'}
                            </Button>
                        </div>
                    </div>

                    {capture.message ? (
                        <div
                            className={`flex items-center gap-2 text-xs ${
                                capture.status === 'verified'
                                    ? 'text-green-700'
                                    : 'text-red-600'
                            }`}
                        >
                            {capture.status === 'verified' ? (
                                <ShieldCheck className="h-3.5 w-3.5" />
                            ) : (
                                <AlertTriangle className="h-3.5 w-3.5" />
                            )}
                            {capture.message}
                        </div>
                    ) : (
                        <div className="text-xs text-muted-foreground">
                            {requirementText}
                        </div>
                    )}

                    <div className="space-y-1">
                        <Label>Codes on file</Label>
                        <div className="flex flex-wrap gap-2">
                            {scanVerification.code_options.map((option) => (
                                <Button
                                    key={`${option.source}-${option.value}`}
                                    type="button"
                                    variant="secondary"
                                    size="sm"
                                    className="h-auto justify-start px-2 py-1 font-mono text-xs"
                                    onClick={() =>
                                        setCapture((current) => ({
                                            ...current,
                                            code: option.value,
                                            status: 'idle',
                                            message: '',
                                            matchSource: null,
                                        }))
                                    }
                                >
                                    {option.label}: {option.value}
                                </Button>
                            ))}
                        </div>
                    </div>
                </div>

                <div className="space-y-2">
                    <div className="rounded-md border bg-white p-2">
                        <img
                            src={scanVerification.svg_url}
                            alt="Medication QR code"
                            className={qrSizeClassName}
                        />
                    </div>
                    <div className="text-center text-[11px] text-muted-foreground">
                        Internal eMAR QR
                    </div>
                </div>
            </div>
        </div>
    );
}
