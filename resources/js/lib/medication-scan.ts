import axios from 'axios';

export type MedicationScanVerification = {
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
};

export type MedicationScanCapture = {
    code: string;
    status: 'idle' | 'verified' | 'mismatch';
    message: string;
    matchSource: string | null;
    scanSource: 'manual' | 'scanner';
};

export function emptyMedicationScanCapture(): MedicationScanCapture {
    return {
        code: '',
        status: 'idle',
        message: '',
        matchSource: null,
        scanSource: 'manual',
    };
}

export async function verifyMedicationScan(
    clientId: number,
    medicationId: number,
    code: string,
    source: 'manual' | 'scanner' = 'manual',
) {
    const response = await axios.post(
        `/api/medications/clients/${clientId}/medications/${medicationId}/scan-verify`,
        {
            code,
            source,
        },
    );

    return response.data as {
        matched: boolean;
        message?: string;
        match_source?: string | null;
        match_label?: string | null;
    };
}

export function hasVerifiedMedicationScan(scan: MedicationScanCapture): boolean {
    return scan.status === 'verified' && scan.code.trim().length > 0;
}

export function toMedicationScanPayload(scan: MedicationScanCapture) {
    if (!hasVerifiedMedicationScan(scan)) {
        return {
            scan_code: null,
            scan_source: null,
            scan_verified: false,
            scan_match_source: null,
        };
    }

    return {
        scan_code: scan.code.trim(),
        scan_source: scan.scanSource,
        scan_verified: true,
        scan_match_source: scan.matchSource,
    };
}
