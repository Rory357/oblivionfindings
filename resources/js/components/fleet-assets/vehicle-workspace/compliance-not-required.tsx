import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Textarea } from '@/components/ui/textarea';
import { formatDateTime } from '@/lib/datetime';
import { useId, useState } from 'react';
import { isJsonObject, useVehicleRecordCommand } from './record-command';
import type { ComplianceRecord, VehicleProfile } from './types';
import { fieldProps, RecordDialog, WizardField } from './wizard-kit';

/**
 * Registration, WoF, CoF and RUC don't apply to every vehicle. Marking one
 * not required is a recorded decision: a new compliance version with the
 * person's reason, audited on the server. Nothing is inferred from the
 * vehicle's fuel or type.
 */
export function isNotRequired(record: ComplianceRecord): boolean {
    return record.current?.applicability === 'not_applicable';
}

/** Who recorded the not-required decision, and when. */
export function notRequiredByline(record: ComplianceRecord): string | null {
    const current = record.current;
    if (!current || current.applicability !== 'not_applicable') return null;
    return [
        current.recorded_by,
        current.created_at ? formatDateTime(current.created_at) : null,
    ]
        .filter(Boolean)
        .join(' · ');
}

/** The visible tick box. Ticking or unticking opens the reason dialog. */
export function NotRequiredToggle({
    record,
    onToggle,
}: {
    record: ComplianceRecord;
    onToggle: () => void;
}) {
    const id = useId();
    const checked = isNotRequired(record);
    return (
        <span className="compliance-not-required">
            <Checkbox
                id={id}
                checked={checked}
                data-compliance-toggle={record.kind}
                onCheckedChange={onToggle}
            />
            <label htmlFor={id}>
                Not required for this vehicle{' '}
                {/* Each row's box names its requirement for screen readers. */}
                <span className="sr-only">({record.label})</span>
            </label>
        </span>
    );
}

export function NotRequiredDialog({
    vehicle,
    record,
    onClose,
    onSaved,
    onRecordEvidence,
}: {
    vehicle: VehicleProfile;
    record: ComplianceRecord;
    onClose: () => void;
    onSaved: () => void;
    /** Opens the full evidence wizard instead (when marking it required again). */
    onRecordEvidence?: () => void;
}) {
    const marking = !isNotRequired(record);
    const label = record.label;
    const [reason, setReason] = useState('');
    const [error, setError] = useState('');
    const command = useVehicleRecordCommand(isJsonObject);
    const fieldError = error || command.errors.applicability_basis;

    const save = async () => {
        if (!reason.trim()) {
            setError(
                marking
                    ? `Record why ${label} isn’t required for this vehicle.`
                    : `Record why ${label} is required for this vehicle.`,
            );
            return;
        }
        const result = await command.submit(
            `/fleet-assets/vehicles/${vehicle.id}/compliance/${record.kind}`,
            {
                applicability: marking ? 'not_applicable' : 'applicable',
                applicability_basis: reason.trim(),
                // Marking it required again leaves the evidence to record next.
                outcome: marking ? 'recorded' : 'needs_assessment',
                expected_current_version_id: record.current?.id ?? null,
            },
        );
        if (!result) return;
        onSaved();
        onClose();
    };

    return (
        <RecordDialog
            title={
                marking
                    ? `${label} not required for this vehicle`
                    : `${label} is required for this vehicle`
            }
            description={
                marking
                    ? `Readiness stops asking for ${label} evidence for ${vehicle.name}. This is saved as a new version with your name and reason, and earlier evidence is kept.`
                    : `Readiness will ask for current ${label} evidence again before ${vehicle.name} can be booked or checked out. This is saved as a new version with your reason.`
            }
            command={command}
            submitLabel={marking ? 'Mark not required' : 'Mark as required'}
            onSubmit={save}
            onClose={onClose}
            destructive={
                !marking && onRecordEvidence ? (
                    <Button
                        variant="ghost"
                        disabled={command.processing}
                        onClick={onRecordEvidence}
                    >
                        Record evidence instead
                    </Button>
                ) : undefined
            }
        >
            <WizardField
                id="compliance-not-required-reason"
                label="Reason"
                error={fieldError}
                hint={
                    marking
                        ? 'For example, the vehicle class or the source you checked.'
                        : 'For example, what changed about this vehicle.'
                }
            >
                <Textarea
                    {...fieldProps(
                        'compliance-not-required-reason',
                        fieldError,
                    )}
                    rows={3}
                    maxLength={5000}
                    value={reason}
                    onChange={(event) => {
                        setReason(event.target.value);
                        setError('');
                        command.clearError('applicability_basis');
                    }}
                />
            </WizardField>
        </RecordDialog>
    );
}
