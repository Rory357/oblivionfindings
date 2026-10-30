import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { StatusBadge } from '@/components/ui/status-badge';
import { Textarea } from '@/components/ui/textarea';
import { ReviewCard, ReviewRow } from '@/components/wizard/shell';
import { formatDateTime } from '@/lib/datetime';
import { cn } from '@/lib/utils';
import { FileCheck2, Gauge, Radio, SlidersHorizontal } from 'lucide-react';
import { useState } from 'react';
import { uploadSummary, useEvidenceUpload } from './evidence-upload';
import { isJsonObject, useVehicleRecordCommand } from './record-command';
import './studio.css';
import type { MileageFeedState, VehicleWorkspace } from './types';
import {
    fieldProps,
    RecordDialog,
    StagedFilesField,
    StudioNotice,
    WizardField,
    WizardSuccess,
    WorkspaceWizard,
} from './wizard-kit';
import { formatKm } from './workspace-model';

const signedKm = (value: number) =>
    `${value >= 0 ? '+' : '−'}${formatKm(Math.abs(value))}`;

/**
 * The tracker distance feed: the recorded dashboard reading beside the
 * tracker's estimate, and whether planning may use the tracker.
 */
export function MileageFeed({
    workspace,
    compact = false,
    onChanged,
}: {
    workspace: VehicleWorkspace;
    compact?: boolean;
    onChanged: () => void;
}) {
    const feed = workspace.mileage_feed;
    const [dialog, setDialog] = useState<'reconcile' | 'pause' | null>(null);
    if (!feed) return null;
    if (!feed.available)
        return (
            <StudioNotice title="Manual mileage available">
                No tracker distance is reported for this vehicle. Dashboard
                readings still support service planning.
            </StudioNotice>
        );
    const discrepancy =
        feed.difference_km !== null &&
        Math.abs(feed.difference_km) > feed.tolerance_km;
    const manage = workspace.can.manage;

    return (
        <section
            className={cn('studio-card mileage-feed', compact && 'compact')}
        >
            <div className="feed-heading">
                <span className="feature-icon">
                    <Radio className="size-[21px]" />
                </span>
                <div>
                    <span className="studio-eyebrow">
                        {(feed.device_label ?? 'Tracker').toUpperCase()} ·
                        DISTANCE FEED
                    </span>
                    <h3>
                        {feed.usable
                            ? 'Automatic planning is on'
                            : feed.automatic
                              ? 'Planning feed needs review'
                              : 'Connect mileage to service planning'}
                    </h3>
                </div>
                <StatusBadge variant={feed.usable ? 'success' : 'warning'}>
                    {feed.usable
                        ? 'Estimated'
                        : !feed.fresh
                          ? 'Stale / unavailable'
                          : 'Cross-check'}
                </StatusBadge>
            </div>
            <div className="feed-values">
                <div>
                    <small>Dashboard record</small>
                    <strong>
                        {feed.verified_km !== null
                            ? formatKm(feed.verified_km)
                            : 'Not recorded'}
                    </strong>
                    <span>Retained original observation</span>
                </div>
                <div>
                    <small>Tracker estimate</small>
                    <strong>
                        {feed.estimate_km !== null
                            ? formatKm(feed.estimate_km)
                            : 'Not reported'}
                    </strong>
                    <span>
                        {feed.tracker_observed_at
                            ? formatDateTime(feed.tracker_observed_at)
                            : 'Unknown time'}{' '}
                        · {feed.fresh ? 'sample' : 'last known'}
                    </span>
                </div>
                <div>
                    <small>Difference since dashboard record</small>
                    <strong
                        className={discrepancy ? 'text-status-warning' : ''}
                    >
                        {feed.difference_km !== null
                            ? signedKm(feed.difference_km)
                            : '—'}
                    </strong>
                    <span>
                        {discrepancy
                            ? 'Cross-check threshold exceeded'
                            : 'May include travel since observation'}
                    </span>
                </div>
            </div>
            {feed.reconciled_at && !feed.reconciled && (
                <p className="feed-warning">
                    A newer dashboard reading or correction needs
                    reconciliation. Automatic estimates are paused.
                </p>
            )}
            <div className="feed-footer">
                <p>
                    {feed.usable && feed.planning_km !== null
                        ? `Service and RUC planning use ${formatKm(feed.planning_km)}. `
                        : 'Planning uses the recorded dashboard reading. '}
                    Tracker distance is an estimate; verify the dashboard and
                    source documents. WoF and registration dates come from their
                    own evidence.
                </p>
                {manage && (
                    <Button
                        variant="outline"
                        disabled={!feed.can.reconcile}
                        title={
                            feed.can.reconcile
                                ? undefined
                                : 'A current tracker sample is needed to cross-check.'
                        }
                        onClick={() => setDialog('reconcile')}
                    >
                        Cross-check &amp; configure
                    </Button>
                )}
                {feed.can.pause && (
                    <Button variant="ghost" onClick={() => setDialog('pause')}>
                        Pause feed
                    </Button>
                )}
            </div>
            {dialog === 'reconcile' && (
                <ReconcileFeedWizard
                    workspace={workspace}
                    feed={feed}
                    onClose={() => setDialog(null)}
                    onSaved={onChanged}
                />
            )}
            {dialog === 'pause' && (
                <PauseFeedDialog
                    vehicleId={workspace.vehicle.id}
                    feed={feed}
                    onClose={() => setDialog(null)}
                    onSaved={onChanged}
                />
            )}
        </section>
    );
}

function ReconcileFeedWizard({
    workspace,
    feed,
    onClose,
    onSaved,
}: {
    workspace: VehicleWorkspace;
    feed: MileageFeedState;
    onClose: () => void;
    onSaved: () => void;
}) {
    const vehicle = workspace.vehicle;
    const [initial] = useState(() => ({
        value:
            feed.estimate_km !== null
                ? String(Math.round(feed.estimate_km))
                : '',
        reason: '',
        automatic: feed.automatic,
        tolerance: String(feed.tolerance_km),
        confirmed: false,
    }));
    const [form, setForm] = useState(initial);
    const [files, setFiles] = useState<File[]>([]);
    const [step, setStep] = useState(0);
    const [localErrors, setLocalErrors] = useState<Record<string, string>>({});
    const [saved, setSaved] = useState<string | null>(null);
    const command = useVehicleRecordCommand(isJsonObject);
    const { upload } = useEvidenceUpload(vehicle.id);
    const errors: Record<string, string> = {
        ...command.errors,
        ...localErrors,
    };
    const update = <K extends keyof typeof form>(
        key: K,
        value: (typeof form)[K],
    ) => {
        setForm((old) => ({ ...old, [key]: value }));
        setLocalErrors({});
    };
    const error = (field: string, server: string) =>
        errors[field] ?? errors[server];

    const validateStep = (at: number): boolean => {
        const found: Record<string, string> = {};
        if (at === 0) {
            const value = Number(form.value);
            if (!form.value.trim() || !Number.isInteger(value) || value < 0)
                found.value =
                    'Enter the dashboard reading in whole kilometres.';
            else if (feed.verified_km !== null && value < feed.verified_km)
                found.value =
                    'Use a reading at least as high as the current record. Correct an incorrect earlier reading in Mileage history first.';
            if (!form.reason.trim())
                found.reason =
                    'Record the observation and explain any difference.';
        }
        if (at === 1) {
            const tolerance = Number(form.tolerance);
            if (!Number.isInteger(tolerance) || tolerance < 1)
                found.tolerance =
                    'Choose a positive whole kilometre review threshold.';
            if (!form.confirmed)
                found.confirmed =
                    'Confirm you checked the dashboard against this tracker sample.';
        }
        setLocalErrors(found);
        return Object.keys(found).length === 0;
    };

    const submit = async () => {
        if (!command.uncertain) {
            for (const at of [0, 1]) {
                if (!validateStep(at)) {
                    setStep(at);
                    return;
                }
            }
        }
        const result = await command.submit(
            `/fleet-assets/vehicles/${vehicle.id}/mileage-feed/reconcile`,
            {
                value_km: Number(form.value),
                reason: form.reason.trim(),
                automatic: form.automatic,
                tolerance_km: Number(form.tolerance),
                confirmed: form.confirmed,
            },
        );
        if (!result) return;
        let note = '';
        if (files.length && typeof result.observation_id === 'number') {
            const outcome = await upload(files, {
                category: 'Odometer evidence',
                reason: form.reason.trim(),
                sourceType: 'odometer_observation',
                sourceId: result.observation_id,
            });
            note = uploadSummary(outcome, files.length);
        }
        setSaved(
            `${typeof result.message === 'string' ? result.message : 'Tracker reconciled.'}${note}`,
        );
        onSaved();
    };

    const difference =
        feed.estimate_km !== null && form.value.trim()
            ? Number(form.value) - feed.estimate_km
            : null;

    return (
        <WorkspaceWizard
            title="Reconcile tracker mileage"
            description={[vehicle.name, vehicle.asset_tag]
                .filter(Boolean)
                .join(' · ')}
            railIcon={Gauge}
            railSub={
                [vehicle.registration_number, vehicle.site?.name]
                    .filter(Boolean)
                    .join(' · ') || vehicle.name
            }
            steps={[
                {
                    key: 'cross-check',
                    label: 'Dashboard cross-check',
                    blurb: 'Compare the dashboard with this sample',
                    icon: Gauge,
                },
                {
                    key: 'planning',
                    label: 'Automatic planning',
                    blurb: 'Whether planning uses the tracker',
                    icon: SlidersHorizontal,
                },
                {
                    key: 'review',
                    label: 'Review',
                    blurb: 'Confirm the reconciliation',
                    icon: FileCheck2,
                },
            ]}
            step={step}
            setStep={setStep}
            pct={Math.round(
                ([
                    !!form.value.trim(),
                    !!form.reason.trim(),
                    !!form.tolerance.trim(),
                    form.confirmed,
                ].filter(Boolean).length /
                    4) *
                    100,
            )}
            context={{
                name: vehicle.name,
                detail: [vehicle.asset_tag, vehicle.registration_number]
                    .filter(Boolean)
                    .join(' · '),
            }}
            command={command}
            dirty={
                JSON.stringify(form) !== JSON.stringify(initial) ||
                files.length > 0
            }
            saved={saved !== null}
            submitLabel="Save reconciliation"
            onValidateStep={validateStep}
            onSubmit={submit}
            onClose={onClose}
            onReload={() => {
                onSaved();
                onClose();
            }}
            errorKey={JSON.stringify(errors)}
            success={
                <WizardSuccess
                    title="Reconciliation saved"
                    blurb={
                        saved ??
                        'The dashboard observation is retained. Later tracker samples update planning only when enabled.'
                    }
                    onClose={onClose}
                />
            }
        >
            {step === 0 && (
                <div className="space-y-5">
                    <p className="text-subtle">
                        Compare the dashboard at this sample with the tracker
                        estimate of{' '}
                        {feed.estimate_km !== null
                            ? formatKm(feed.estimate_km)
                            : 'an unknown distance'}
                        {feed.tracker_observed_at
                            ? ` reported ${formatDateTime(feed.tracker_observed_at)}`
                            : ''}
                        .
                    </p>
                    <WizardField
                        id="feed-value"
                        label="Dashboard reading in kilometres"
                        error={error('value', 'value_km')}
                        hint={
                            difference !== null
                                ? `${signedKm(difference)} against the tracker estimate`
                                : undefined
                        }
                    >
                        <Input
                            {...fieldProps(
                                'feed-value',
                                error('value', 'value_km'),
                            )}
                            inputMode="numeric"
                            value={form.value}
                            onChange={(event) =>
                                update(
                                    'value',
                                    event.target.value.replace(/[^0-9]/g, ''),
                                )
                            }
                        />
                    </WizardField>
                    <WizardField
                        id="feed-reason"
                        label="Observation and discrepancy explanation"
                        error={error('reason', 'reason')}
                    >
                        <Textarea
                            {...fieldProps(
                                'feed-reason',
                                error('reason', 'reason'),
                            )}
                            rows={3}
                            maxLength={2000}
                            value={form.reason}
                            onChange={(event) =>
                                update('reason', event.target.value)
                            }
                        />
                    </WizardField>
                    <StagedFilesField
                        label="Dashboard photo or supporting document"
                        files={files}
                        onChange={setFiles}
                    />
                </div>
            )}
            {step === 1 && (
                <div className="space-y-5">
                    <p className="text-subtle">
                        New tracker distance can update service and RUC
                        planning. Recorded dashboard readings, expiry dates and
                        compliance evidence remain separate.
                    </p>
                    <label className="flex items-start gap-3 text-sm">
                        <Checkbox
                            id="feed-automatic"
                            checked={form.automatic}
                            onCheckedChange={(value) =>
                                update('automatic', value === true)
                            }
                        />
                        <span>Use calibrated tracker mileage for planning</span>
                    </label>
                    <WizardField
                        id="feed-tolerance"
                        label="Review difference in kilometres"
                        error={error('tolerance', 'tolerance_km')}
                    >
                        <Input
                            {...fieldProps(
                                'feed-tolerance',
                                error('tolerance', 'tolerance_km'),
                            )}
                            inputMode="numeric"
                            value={form.tolerance}
                            onChange={(event) =>
                                update(
                                    'tolerance',
                                    event.target.value.replace(/[^0-9]/g, ''),
                                )
                            }
                        />
                    </WizardField>
                    <label className="flex items-start gap-3 text-sm">
                        <Checkbox
                            id="feed-confirmed"
                            checked={form.confirmed}
                            aria-invalid={!!error('confirmed', 'confirmed')}
                            onCheckedChange={(value) =>
                                update('confirmed', value === true)
                            }
                        />
                        <span>
                            I checked this dashboard reading against the
                            selected tracker sample
                        </span>
                    </label>
                    {error('confirmed', 'confirmed') && (
                        <p
                            role="alert"
                            className="text-sm text-status-critical"
                        >
                            {error('confirmed', 'confirmed')}
                        </p>
                    )}
                </div>
            )}
            {step === 2 && (
                <div className="grid gap-4">
                    <ReviewCard
                        icon={Gauge}
                        title="Dashboard cross-check"
                        onEdit={() => setStep(0)}
                    >
                        <ReviewRow
                            label="Dashboard reading"
                            value={
                                form.value
                                    ? formatKm(Number(form.value))
                                    : undefined
                            }
                        />
                        <ReviewRow
                            label="Tracker estimate"
                            value={
                                feed.estimate_km !== null
                                    ? formatKm(feed.estimate_km)
                                    : 'Not reported'
                            }
                        />
                        <ReviewRow
                            label="Difference"
                            value={
                                difference !== null
                                    ? signedKm(difference)
                                    : undefined
                            }
                        />
                        <ReviewRow
                            label="Explanation"
                            value={form.reason || undefined}
                        />
                        <ReviewRow
                            label="Supporting files"
                            value={
                                files.map((file) => file.name).join(', ') ||
                                'No files attached'
                            }
                        />
                    </ReviewCard>
                    <ReviewCard
                        icon={SlidersHorizontal}
                        title="Automatic planning"
                        onEdit={() => setStep(1)}
                    >
                        <ReviewRow
                            label="Use tracker mileage for planning"
                            value={form.automatic ? 'Yes' : 'No'}
                        />
                        <ReviewRow
                            label="Review difference"
                            value={
                                form.tolerance
                                    ? formatKm(Number(form.tolerance))
                                    : undefined
                            }
                        />
                    </ReviewCard>
                    <StudioNotice title="What this changes">
                        The dashboard reading is kept as a recorded reading and
                        the tracker is calibrated to it.{' '}
                        {form.automatic
                            ? 'Later tracker distance moves service and RUC planning forward from here.'
                            : 'Planning keeps using recorded readings.'}{' '}
                        A new manual reading, a missing sample or a tracker
                        change needs another cross-check. Readiness always uses
                        recorded readings.
                    </StudioNotice>
                </div>
            )}
        </WorkspaceWizard>
    );
}

function PauseFeedDialog({
    vehicleId,
    feed,
    onClose,
    onSaved,
}: {
    vehicleId: number;
    feed: MileageFeedState;
    onClose: () => void;
    onSaved: () => void;
}) {
    const [reason, setReason] = useState('');
    const [error, setError] = useState('');
    const command = useVehicleRecordCommand(isJsonObject);
    const save = async () => {
        if (!reason.trim()) {
            setError('Record why automatic planning is paused.');
            return;
        }
        const result = await command.submit(
            `/fleet-assets/vehicles/${vehicleId}/mileage-feed/pause`,
            { reason: reason.trim(), expected_version: feed.lock_version },
        );
        if (!result) return;
        onSaved();
        onClose();
    };
    return (
        <RecordDialog
            title="Pause automatic planning"
            description="Planning goes back to the recorded dashboard reading. The tracker keeps reporting and you can cross-check again at any time."
            command={command}
            submitLabel="Pause feed"
            onSubmit={save}
            onClose={onClose}
        >
            <WizardField
                id="feed-pause-reason"
                label="Reason"
                error={error || command.errors.reason}
            >
                <Textarea
                    {...fieldProps(
                        'feed-pause-reason',
                        error || command.errors.reason,
                    )}
                    rows={3}
                    maxLength={2000}
                    value={reason}
                    onChange={(event) => {
                        setReason(event.target.value);
                        setError('');
                    }}
                />
            </WizardField>
        </RecordDialog>
    );
}
