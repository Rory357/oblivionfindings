import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { cn } from '@/lib/utils';
import { AlertCircle } from 'lucide-react';
import type { ReactNode } from 'react';
import type { UploadOutcome } from './evidence-upload';
import type { FinanceReviewRequest } from './finance-types';
import { isJsonObject, useVehicleRecordCommand } from './record-command';
import type { VehicleProfile } from './types';
import { todayInAuckland } from './workspace-model';

const NOTICE_TONES = {
    info: 'border-status-info/30 bg-status-info-bg text-status-info',
    warning:
        'border-status-warning/30 bg-status-warning-bg text-status-warning',
    critical:
        'border-status-critical/30 bg-status-critical-bg text-status-critical',
} as const;

/** The approved design's notice: a toned panel with a title and a short body. */
export function FinanceNotice({
    title,
    children,
    tone = 'info',
}: {
    title: string;
    children?: ReactNode;
    tone?: keyof typeof NOTICE_TONES;
}) {
    return (
        <Alert
            role={tone === 'critical' ? 'alert' : 'status'}
            className={cn(
                'rounded-[10px] text-xs leading-relaxed',
                NOTICE_TONES[tone],
            )}
        >
            <AlertCircle aria-hidden />
            <AlertTitle className="font-semibold">{title}</AlertTitle>
            {children ? (
                <AlertDescription className="text-xs text-current">
                    {children}
                </AlertDescription>
            ) : null}
        </Alert>
    );
}

/** "VH-014": the short reference used in eyebrows and titles. */
export function vehicleReference(vehicle: VehicleProfile): string {
    return vehicle.asset_tag ?? vehicle.registration_number ?? vehicle.name;
}

/** The locked context card shown at the top of each Finance dialog. */
export function vehicleContext(vehicle: VehicleProfile): {
    name: string;
    detail: string;
} {
    return {
        name: vehicle.name,
        detail: [
            vehicle.asset_tag,
            vehicle.registration_number,
            vehicle.site?.name,
        ]
            .filter(Boolean)
            .join(' · '),
    };
}

/** "vehicle" or "work_order:12": the value a request's source was chosen with. */
export function requestSourceValue(request: FinanceReviewRequest): string {
    return request.source.type === 'vehicle'
        ? 'vehicle'
        : `${request.source.type}:${String(request.source.id)}`;
}

/**
 * Upload supporting files owned by a Finance review request. They are
 * private vehicle documents: stored under a random name, virus-checked
 * before they open, and shown only to Finance viewers.
 */
export function useFinanceEvidenceUpload(vehicleId: number) {
    const command = useVehicleRecordCommand(isJsonObject);

    const upload = async (
        files: File[],
        meta: { category: string; reason: string; requestId: number },
    ): Promise<UploadOutcome | null> => {
        if (!files.length) return { uploaded: 0, waiting: 0, blocked: 0 };
        const form = new FormData();
        form.append('category', meta.category);
        form.append('document_date', todayInAuckland());
        form.append('reason', meta.reason);
        form.append('source_type', 'finance_review_request');
        form.append('source_id', String(meta.requestId));
        files.forEach((file) => form.append('files[]', file));
        const result = await command.submit(
            `/fleet-assets/vehicles/${vehicleId}/documents`,
            form,
        );
        if (!result || !Array.isArray(result.files)) return null;
        const states = result.files.map((file) =>
            isJsonObject(file) ? String(file.state) : '',
        );

        return {
            uploaded: states.filter((state) => state === 'available').length,
            waiting: states.filter((state) =>
                [
                    'scan_unavailable',
                    'publication_failed',
                    'stored',
                    'reserved',
                ].includes(state),
            ).length,
            blocked: states.filter((state) =>
                ['quarantined', 'storage_failed'].includes(state),
            ).length,
        };
    };

    return { upload, command };
}
