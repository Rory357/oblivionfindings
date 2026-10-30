import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import { StatusBadge } from '@/components/ui/status-badge';
import { Textarea } from '@/components/ui/textarea';
import { useState } from 'react';
import type { CheckRun } from './checks-types';
import { isJsonObject, useVehicleRecordCommand } from './record-command';
import type { ReadinessReason, VehicleWorkspace } from './types';
import {
    fieldProps,
    RecordDialog,
    StudioNotice,
    WizardField,
} from './wizard-kit';

const REASON_ID = 'check-assessment-reason';
const CONFIRM_ID = 'check-assessment-confirmed';

/**
 * Readiness items a check release can't clear: the vehicle's own record and
 * compliance evidence. The server applies the same gate as any Maintenance
 * release, so these are resolved first.
 */
export function releaseBlockers(
    workspace: VehicleWorkspace,
): ReadinessReason[] {
    return workspace.readiness.reasons.filter(
        (reason) =>
            reason.blocks_decision && !reason.code.startsWith('maintenance.'),
    );
}

/** Maintenance holds and other checks that keep the vehicle unavailable afterwards. */
export function otherHolds(workspace: VehicleWorkspace, run: CheckRun): number {
    const { restriction_ids, check_run_ids } = workspace.readiness;
    return (
        restriction_ids.length +
        check_run_ids.filter((id) => id !== run.id).length
    );
}

/**
 * "No issue found — release for use": Maintenance's decision on one check
 * that holds the vehicle. The check's answers and outcome stay as recorded;
 * holds still need repair, a retest and an independent release.
 */
export function AssessCheckDialog({
    workspace,
    run,
    onClose,
    onSaved,
}: {
    workspace: VehicleWorkspace;
    run: CheckRun;
    onClose: () => void;
    onSaved: () => void;
}) {
    const [reason, setReason] = useState('');
    const [confirmed, setConfirmed] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const command = useVehicleRecordCommand(isJsonObject);
    const blockers = releaseBlockers(workspace);
    const holds = otherHolds(workspace, run);
    const issues = run.assess?.issues ?? [];
    const reasonError = errors.reason || command.errors.reason;
    const confirmError = errors.confirmed || command.errors.confirmed;
    const refusal = command.errors.run;

    const save = async () => {
        const found: Record<string, string> = {};
        if (!reason.trim())
            found.reason = 'Record why the vehicle is safe to use.';
        if (!confirmed)
            found.confirmed =
                'Confirm that you assessed this check and found nothing that stops safe use.';
        setErrors(found);
        if (Object.keys(found).length > 0) return;
        const result = await command.submit(
            `/fleet-assets/vehicles/${workspace.vehicle.id}/checks/${run.id}/assessments`,
            {
                decision: 'no_issue_release',
                reason: reason.trim(),
                confirmed: true,
            },
        );
        if (!result) return;
        onSaved();
        onClose();
    };

    return (
        <RecordDialog
            title="No issue found — release for use"
            description={`${run.reference} · ${run.template}${run.recorded_by ? ` · recorded by ${run.recorded_by}` : ''}. Your decision and reason are kept with the check; its answers and outcome stay as recorded.`}
            command={command}
            submitLabel="Release for use"
            submitDisabled={blockers.length > 0}
            onSubmit={save}
            onClose={onClose}
        >
            {blockers.length > 0 && (
                <div className="grid gap-2">
                    <StudioNotice title="Resolve these first" tone="warning">
                        A check can be released for use only while the vehicle’s
                        own record and evidence are current, as for any
                        Maintenance release.
                    </StudioNotice>
                    <ul
                        className="grid list-disc gap-1 pl-5 text-sm"
                        aria-label="Resolve these first"
                    >
                        {blockers.map((blocker) => (
                            <li
                                key={`${blocker.code}-${blocker.version_id ?? ''}`}
                            >
                                {blocker.message}
                            </li>
                        ))}
                    </ul>
                </div>
            )}
            {refusal && (
                <StudioNotice
                    title="This check can’t be released here"
                    tone="critical"
                >
                    {refusal}
                </StudioNotice>
            )}
            {issues.length > 0 && (
                <div className="grid gap-2">
                    <p className="text-subtle">
                        This check recorded the following. Release it only if
                        none of them stops safe use.
                    </p>
                    <ul className="grid gap-1.5" aria-label="Recorded issues">
                        {issues.map((issue) => (
                            <li
                                key={issue.id}
                                className="flex items-center justify-between gap-3 text-sm"
                            >
                                <span>{issue.label}</span>
                                <StatusBadge variant="warning">
                                    {issue.value}
                                </StatusBadge>
                            </li>
                        ))}
                    </ul>
                </div>
            )}
            {holds > 0 && (
                <StudioNotice title="Other holds stay in force">
                    {holds === 1
                        ? 'Another maintenance hold or check still stops'
                        : `${holds} other maintenance holds or checks still stop`}{' '}
                    the vehicle being used. This releases {run.reference} only.
                </StudioNotice>
            )}
            <WizardField
                id={REASON_ID}
                label="Reason"
                error={reasonError}
                hint="What you checked, and why the vehicle is safe to use."
            >
                <Textarea
                    {...fieldProps(REASON_ID, reasonError)}
                    rows={3}
                    maxLength={2000}
                    value={reason}
                    onChange={(event) => {
                        setReason(event.target.value);
                        setErrors((current) => ({ ...current, reason: '' }));
                        command.clearError('reason');
                    }}
                />
            </WizardField>
            <div className="grid gap-1">
                <div className="flex items-start gap-2">
                    <Checkbox
                        id={CONFIRM_ID}
                        checked={confirmed}
                        aria-invalid={!!confirmError}
                        aria-describedby={
                            confirmError ? `${CONFIRM_ID}-error` : undefined
                        }
                        onCheckedChange={(value) => {
                            setConfirmed(value === true);
                            setErrors((current) => ({
                                ...current,
                                confirmed: '',
                            }));
                            command.clearError('confirmed');
                        }}
                    />
                    <Label htmlFor={CONFIRM_ID} className="leading-snug">
                        I assessed this check and found nothing that stops safe
                        use.
                    </Label>
                </div>
                {confirmError && (
                    <p
                        id={`${CONFIRM_ID}-error`}
                        className="text-xs text-status-critical"
                        role="alert"
                    >
                        {confirmError}
                    </p>
                )}
            </div>
        </RecordDialog>
    );
}
