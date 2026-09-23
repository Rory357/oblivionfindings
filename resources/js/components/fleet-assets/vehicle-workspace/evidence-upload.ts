import { isJsonObject, useVehicleRecordCommand } from './record-command';
import { todayInAuckland } from './workspace-model';

export type EvidenceSource =
    | 'compliance_version'
    | 'odometer_observation'
    | 'service_completion'
    | 'service_schedule'
    | 'booking'
    | 'unavailable_period';

export type UploadOutcome = {
    uploaded: number;
    waiting: number;
    blocked: number;
};

/**
 * Upload evidence files owned by a record that has just been saved. The
 * record stands on its own; a failed upload can be retried from its row.
 */
export function useEvidenceUpload(vehicleId: number) {
    const command = useVehicleRecordCommand(isJsonObject);

    const upload = async (
        files: File[],
        meta: {
            category: string;
            reason: string;
            sourceType?: EvidenceSource;
            sourceId?: number;
            documentDate?: string;
        },
    ): Promise<UploadOutcome | null> => {
        if (!files.length) return { uploaded: 0, waiting: 0, blocked: 0 };
        const form = new FormData();
        form.append('category', meta.category);
        form.append('document_date', meta.documentDate ?? todayInAuckland());
        form.append('reason', meta.reason);
        if (meta.sourceType) form.append('source_type', meta.sourceType);
        if (meta.sourceId) form.append('source_id', String(meta.sourceId));
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

export function uploadSummary(
    outcome: UploadOutcome | null,
    total: number,
): string {
    if (!total) return '';
    if (!outcome)
        return ' The files could not be uploaded; add them again from this record.';
    const parts: string[] = [];
    if (outcome.uploaded)
        parts.push(
            `${outcome.uploaded} ${outcome.uploaded === 1 ? 'file is' : 'files are'} available`,
        );
    if (outcome.waiting)
        parts.push(
            `${outcome.waiting} ${outcome.waiting === 1 ? 'file is' : 'files are'} stored privately and waiting for a virus check`,
        );
    if (outcome.blocked) parts.push(`${outcome.blocked} could not be accepted`);

    return parts.length ? ` ${parts.join('; ')}.` : '';
}
