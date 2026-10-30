import { DateTimeField } from '@/components/fleet-assets/maintenance/date-time-field';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { StatusBadge } from '@/components/ui/status-badge';
import { Textarea } from '@/components/ui/textarea';
import { ReviewCard, ReviewRow } from '@/components/wizard/shell';
import {
    formatDateOnly,
    formatDateTime,
    toDateInput,
    toDatetimeLocal,
} from '@/lib/datetime';
import {
    ArrowRight,
    ArrowUpRight,
    Bell,
    Gauge,
    Paperclip,
    Pencil,
    Plus,
    ShieldCheck,
} from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { AddEvidenceDialog } from './add-evidence-dialog';
import { uploadSummary, useEvidenceUpload } from './evidence-upload';
import { MileageFeed } from './mileage-feed';
import {
    useVehicleCollectionView,
    VehicleCollectionToggle,
    VehicleRecordCollection,
} from './record-collection';
import { isJsonObject, useVehicleRecordCommand } from './record-command';
import { ReminderDialog } from './service-reminders';
import type { OdometerReading, VehicleWorkspace } from './types';
import {
    fieldProps,
    StagedFilesField,
    WizardField,
    WizardSuccess,
    WorkspaceWizard,
} from './wizard-kit';
import {
    formatKm,
    SOURCE_KIND_LABELS,
    type WorkspaceLocation,
} from './workspace-model';

type Filter = 'all' | 'corrections' | 'evidence';

export function MileagePanel({
    workspace,
    onNavigate,
    onChanged,
}: {
    workspace: VehicleWorkspace;
    onNavigate: (location: WorkspaceLocation) => void;
    onChanged: () => void;
}) {
    const { view, setView } = useVehicleCollectionView('mileage');
    const [filter, setFilter] = useState<Filter>('all');
    const [query, setQuery] = useState('');
    const [expanded, setExpanded] = useState<number | null>(null);
    const [recording, setRecording] = useState<{
        corrects: OdometerReading | null;
    } | null>(null);
    const [evidenceFor, setEvidenceFor] = useState<OdometerReading | null>(
        null,
    );
    const [reminding, setReminding] = useState(false);
    const { can, odometer } = workspace;
    const all = odometer.readings;
    const latest = all.find((reading) => reading.is_current) ?? all[0];
    const prior = latest
        ? latest.corrects_observation_id
            ? all.find(
                  (reading) => reading.id === latest.corrects_observation_id,
              )
            : all.find(
                  (reading) =>
                      reading.id !== latest.id && !reading.is_corrected,
              )
        : undefined;
    const planningKm = workspace.mileage_feed?.usable
        ? workspace.mileage_feed.planning_km
        : odometer.current_km;
    const nextDistance = workspace.schedules
        .filter(
            (schedule) => schedule.is_active && schedule.next_due_km !== null,
        )
        .sort((a, b) => (a.next_due_km ?? 0) - (b.next_due_km ?? 0))[0];
    const rows = useMemo(() => {
        const needle = query.trim().toLowerCase();
        return all.filter((reading) => {
            if (filter === 'corrections' && !reading.corrects_observation_id)
                return false;
            if (filter === 'evidence' && reading.files.length === 0)
                return false;
            if (!needle) return true;
            return [
                reading.value_km.toString(),
                reading.recorded_by,
                reading.source_reference,
                SOURCE_KIND_LABELS[reading.source_kind],
            ]
                .filter(Boolean)
                .some((text) => String(text).toLowerCase().includes(needle));
        });
    }, [all, filter, query]);
    // Readings that still count, oldest first, for the progression chart.
    const active = all
        .filter((reading) => !reading.is_corrected && reading.observed_at)
        .slice()
        .reverse();
    const low = Math.min(...active.map((reading) => reading.value_km));
    const high = Math.max(...active.map((reading) => reading.value_km));
    const x = (index: number) =>
        20 + (index * 300) / Math.max(active.length - 1, 1);
    const y = (value: number) =>
        84 - ((value - low) / Math.max(high - low, 1)) * 60;
    const selected = rows.find((reading) => reading.id === expanded);
    const statusOf = (reading: OdometerReading) =>
        reading.is_corrected
            ? { label: 'Superseded', variant: 'neutral' as const }
            : reading.corrects_observation_id
              ? { label: 'Correction', variant: 'warning' as const }
              : reading.is_current
                ? { label: 'Current', variant: 'info' as const }
                : { label: 'Recorded', variant: 'neutral' as const };
    const canCorrect = (reading: OdometerReading) =>
        can.manage && !reading.is_corrected;

    return (
        <div className="activity-studio">
            <div className="activity-title">
                <div>
                    <span className="studio-eyebrow">
                        DISTANCE &amp; EVIDENCE
                    </span>
                    <h2 className="text-section-title">Mileage history</h2>
                    <p className="muted">
                        Dashboard readings, their sources and the service
                        distance they inform.
                    </p>
                </div>
                {can.manage && (
                    <Button onClick={() => setRecording({ corrects: null })}>
                        <Plus className="size-4" />
                        Add reading
                    </Button>
                )}
            </div>
            <MileageFeed workspace={workspace} compact onChanged={onChanged} />
            <div className="mileage-overview">
                <section className="studio-card mileage-current">
                    <span className="feature-icon">
                        <Gauge className="size-[23px]" />
                    </span>
                    <div>
                        <span className="studio-eyebrow">
                            CURRENT RECORDED ODOMETER
                        </span>
                        <strong>
                            {odometer.current_km !== null
                                ? odometer.current_km.toLocaleString('en-NZ')
                                : '—'}{' '}
                            <small>km</small>
                        </strong>
                        <p>
                            {latest?.observed_at
                                ? formatDateTime(latest.observed_at)
                                : 'No reading recorded'}
                        </p>
                        <StatusBadge variant={latest ? 'info' : 'warning'}>
                            {latest ? 'Recorded observation' : 'Reading needed'}
                        </StatusBadge>
                    </div>
                    <div className="mileage-mini">
                        <span>
                            {odometer.total}{' '}
                            {odometer.total === 1 ? 'record' : 'records'}
                        </span>
                        <strong>
                            {latest && prior
                                ? signedKm(latest.value_km - prior.value_km)
                                : '—'}
                        </strong>
                        <small>
                            {latest?.corrects_observation_id
                                ? 'Correction difference'
                                : 'Since previous reading'}
                        </small>
                    </div>
                </section>
                <section className="studio-card mileage-service">
                    <span className="studio-eyebrow">
                        NEXT DISTANCE TRIGGER
                    </span>
                    <h3>
                        {nextDistance
                            ? nextDistance.name
                            : 'No distance service scheduled'}
                    </h3>
                    <strong>
                        {nextDistance?.next_due_km != null &&
                        planningKm !== null
                            ? formatKm(
                                  Math.max(
                                      0,
                                      nextDistance.next_due_km - planningKm,
                                  ),
                              )
                            : '—'}
                    </strong>
                    <span>
                        {nextDistance?.next_due_km != null
                            ? `Due at ${formatKm(nextDistance.next_due_km)}${nextDistance.next_due_at ? ` · ${formatDateOnly(nextDistance.next_due_at)}` : ''}`
                            : 'Add a service schedule to track distance.'}
                    </span>
                    <Button
                        variant="ghost"
                        onClick={() =>
                            onNavigate({ tab: 'service', view: 'schedules' })
                        }
                    >
                        View service schedule{' '}
                        <ArrowUpRight className="size-[15px]" />
                    </Button>
                </section>
            </div>
            <div className="mileage-layout">
                <section className="studio-card">
                    <div className="activity-toolbar">
                        <Input
                            aria-label="Search mileage readings"
                            placeholder="Reading, person or source…"
                            value={query}
                            onChange={(event) => setQuery(event.target.value)}
                        />
                        <Select
                            value={filter}
                            onValueChange={(value) =>
                                setFilter(value as Filter)
                            }
                        >
                            <SelectTrigger
                                className="w-44"
                                aria-label="Mileage history filter"
                            >
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">
                                    All readings
                                </SelectItem>
                                <SelectItem value="corrections">
                                    Corrections
                                </SelectItem>
                                <SelectItem value="evidence">
                                    With evidence
                                </SelectItem>
                            </SelectContent>
                        </Select>
                        <VehicleCollectionToggle
                            label="Mileage readings"
                            view={view}
                            onChange={setView}
                        />
                    </div>
                    <VehicleRecordCollection
                        label="Mileage readings"
                        view={view}
                        total={
                            filter === 'all' && !query
                                ? odometer.total
                                : undefined
                        }
                        columns={[
                            { label: 'Observed', width: '1.3fr' },
                            { label: 'Source / recorded by', width: '1.3fr' },
                            { label: 'Status', width: '.8fr' },
                        ]}
                        empty={{
                            title: all.length
                                ? 'No matching readings'
                                : 'No readings yet',
                            description: all.length
                                ? 'Change the filter or add a dashboard observation.'
                                : 'Record the dashboard odometer to support service planning and RUC coverage.',
                        }}
                        records={rows.map((reading) => {
                            const status = statusOf(reading);
                            const toggle = () =>
                                setExpanded(
                                    expanded === reading.id ? null : reading.id,
                                );
                            return {
                                id: reading.id,
                                name: formatKm(reading.value_km),
                                subline:
                                    reading.source_reference ??
                                    SOURCE_KIND_LABELS[reading.source_kind] ??
                                    undefined,
                                icon: Gauge,
                                tone:
                                    reading.corrects_observation_id &&
                                    !reading.is_corrected
                                        ? 'warning'
                                        : undefined,
                                onOpen: toggle,
                                fields: [
                                    <>
                                        {reading.observed_at
                                            ? formatDateTime(
                                                  reading.observed_at,
                                              )
                                            : 'Unknown time'}
                                    </>,
                                    <>
                                        <strong>
                                            {SOURCE_KIND_LABELS[
                                                reading.source_kind
                                            ] ?? reading.source_kind}
                                        </strong>
                                        <small>
                                            {reading.recorded_by ??
                                                'Earlier record'}
                                        </small>
                                        <small>
                                            {reading.files.length} evidence{' '}
                                            {reading.files.length === 1
                                                ? 'file'
                                                : 'files'}
                                        </small>
                                    </>,
                                    <>
                                        <StatusBadge variant={status.variant}>
                                            {status.label}
                                        </StatusBadge>
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            onClick={toggle}
                                        >
                                            {expanded === reading.id
                                                ? 'Hide details'
                                                : 'View details'}
                                        </Button>
                                    </>,
                                ],
                                footer: {
                                    personName:
                                        reading.recorded_by ?? undefined,
                                    primary:
                                        reading.recorded_by ?? 'Earlier record',
                                    secondary: 'Recorded observation',
                                },
                                actions: [
                                    {
                                        label: 'View details',
                                        icon: Gauge,
                                        onClick: toggle,
                                    },
                                    ...(can.manage_documents &&
                                    reading.source_kind !== 'legacy_unverified'
                                        ? [
                                              {
                                                  label: 'Add evidence',
                                                  icon: Paperclip,
                                                  onClick: () =>
                                                      setEvidenceFor(reading),
                                              },
                                          ]
                                        : []),
                                    ...(canCorrect(reading)
                                        ? [
                                              {
                                                  label:
                                                      reading.source_kind ===
                                                      'legacy_unverified'
                                                          ? 'Replace with a checked reading'
                                                          : 'Correct reading',
                                                  icon: Pencil,
                                                  onClick: () =>
                                                      setRecording({
                                                          corrects: reading,
                                                      }),
                                              },
                                          ]
                                        : []),
                                ],
                            };
                        })}
                    />
                    {selected && (
                        <div className="reading-selected-detail">
                            <div className="studio-section-heading">
                                <h3>
                                    {formatKm(selected.value_km)}
                                    {selected.source_reference
                                        ? ` · ${selected.source_reference}`
                                        : ''}
                                </h3>
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    onClick={() => setExpanded(null)}
                                >
                                    Close details
                                </Button>
                            </div>
                            {selected.corrects_observation_id && (
                                <p>
                                    Corrects an earlier reading
                                    {selected.correction_reason
                                        ? `: ${selected.correction_reason}`
                                        : '.'}{' '}
                                    The original remains in this history.
                                </p>
                            )}
                            <p>
                                {selected.notes ||
                                    'No additional observation notes.'}
                            </p>
                            <div className="reading-files">
                                {selected.files.map((file) =>
                                    file.url ? (
                                        <a
                                            href={file.url}
                                            target="_blank"
                                            rel="noreferrer"
                                            key={file.id}
                                        >
                                            <Paperclip className="size-[14px]" />
                                            {file.name}
                                        </a>
                                    ) : (
                                        <span key={file.id}>
                                            <Paperclip className="size-[14px]" />
                                            {file.name} · waiting for a virus
                                            check
                                        </span>
                                    ),
                                )}
                            </div>
                            <div className="library-actions">
                                {can.manage_documents &&
                                    selected.source_kind !==
                                        'legacy_unverified' && (
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            onClick={() =>
                                                setEvidenceFor(selected)
                                            }
                                        >
                                            <Paperclip className="size-[14px]" />
                                            Add evidence
                                        </Button>
                                    )}
                                {canCorrect(selected) && (
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        onClick={() =>
                                            setRecording({ corrects: selected })
                                        }
                                    >
                                        Correct reading
                                    </Button>
                                )}
                            </div>
                        </div>
                    )}
                </section>
                <aside className="studio-card mileage-insight">
                    <span className="studio-eyebrow">READING PROGRESSION</span>
                    <h3>Recorded distance</h3>
                    {active.length > 1 ? (
                        <>
                            <svg
                                viewBox="0 0 340 105"
                                role="img"
                                aria-label={`Odometer readings from ${formatKm(low)} to ${formatKm(high)}`}
                            >
                                <path d="M20 90H320" stroke="var(--border)" />
                                <polyline
                                    points={active
                                        .map(
                                            (reading, index) =>
                                                `${x(index)},${y(reading.value_km)}`,
                                        )
                                        .join(' ')}
                                    fill="none"
                                    stroke="var(--primary)"
                                    strokeWidth="3"
                                />
                                {active.map((reading, index) => (
                                    <circle
                                        key={reading.id}
                                        cx={x(index)}
                                        cy={y(reading.value_km)}
                                        r="4"
                                        fill="var(--primary)"
                                    />
                                ))}
                            </svg>
                            <div className="spark-labels">
                                <span>
                                    {formatDateOnly(
                                        toDateInput(active[0].observed_at),
                                    )}
                                </span>
                                <span>
                                    {formatDateOnly(
                                        toDateInput(active.at(-1)!.observed_at),
                                    )}
                                </span>
                            </div>
                        </>
                    ) : (
                        <p>Two readings will show a progression.</p>
                    )}
                    <p className="studio-footnote">
                        Observations in time order. Corrections replace the
                        plotted original; this is not a GPS route or a
                        daily-distance estimate.
                    </p>
                    <div className="mileage-insight-note">
                        <ShieldCheck className="size-[18px]" />
                        <p>
                            Original observations stay intact. Every correction
                            requires a reason.
                        </p>
                    </div>
                    {can.manage && (
                        <Button
                            variant="outline"
                            onClick={() => setReminding(true)}
                        >
                            <Bell className="size-[15px]" />
                            Remind me to check mileage
                        </Button>
                    )}
                    <Button
                        variant="ghost"
                        onClick={() => onNavigate({ tab: 'trips' })}
                    >
                        Open trip history <ArrowRight className="size-[15px]" />
                    </Button>
                </aside>
            </div>
            {recording && (
                <ReadingDialog
                    workspace={workspace}
                    corrects={recording.corrects}
                    onClose={() => setRecording(null)}
                    onSaved={onChanged}
                />
            )}
            {evidenceFor && (
                <AddEvidenceDialog
                    vehicle={workspace.vehicle}
                    title={`Add evidence to ${formatKm(evidenceFor.value_km)}`}
                    category="Odometer evidence"
                    sourceType="odometer_observation"
                    sourceId={evidenceFor.id}
                    onClose={() => setEvidenceFor(null)}
                    onSaved={onChanged}
                />
            )}
            {reminding && (
                <ReminderDialog
                    workspace={workspace}
                    reminder={null}
                    presetSource="vehicle"
                    onClose={() => setReminding(false)}
                    onSaved={onChanged}
                />
            )}
        </div>
    );
}

const signedKm = (value: number) =>
    `${value >= 0 ? '+' : '−'}${formatKm(Math.abs(value))}`;

const STEPS = [
    {
        key: 'reading',
        label: 'Odometer reading',
        blurb: 'The dashboard figure and when it was seen',
        icon: Gauge,
    },
    {
        key: 'source',
        label: 'Source & evidence',
        blurb: 'Notes, reason and supporting files',
        icon: Paperclip,
    },
    {
        key: 'review',
        label: 'Review',
        blurb: 'Confirm the reading',
        icon: Pencil,
    },
];

function ReadingDialog({
    workspace,
    corrects,
    onClose,
    onSaved,
}: {
    workspace: VehicleWorkspace;
    corrects: OdometerReading | null;
    onClose: () => void;
    onSaved: () => void;
}) {
    const vehicle = workspace.vehicle;
    const [form, setForm] = useState(() => ({
        value_km: corrects ? String(corrects.value_km) : '',
        observed_local: corrects?.observed_at
            ? toDatetimeLocal(corrects.observed_at)
            : toDatetimeLocal(new Date().toISOString()),
        notes: '',
        correction_reason: '',
    }));
    const [files, setFiles] = useState<File[]>([]);
    const [step, setStep] = useState(0);
    const [localErrors, setLocalErrors] = useState<Record<string, string>>({});
    const [savedText, setSavedText] = useState<string | null>(null);
    const command = useVehicleRecordCommand(isJsonObject);
    const uploads = useEvidenceUpload(vehicle.id);
    const errors = { ...command.errors, ...localErrors };
    const correcting = corrects !== null;
    const update = (key: keyof typeof form, value: string) => {
        setForm((old) => ({ ...old, [key]: value }));
        setLocalErrors((old) => {
            const next = { ...old };
            delete next[key];
            return next;
        });
        command.clearError(key === 'observed_local' ? 'observed_at' : key);
    };

    useEffect(() => {
        const field = Object.keys(command.errors)[0];
        if (field)
            setStep(['correction_reason', 'notes'].includes(field) ? 1 : 0);
    }, [command.errors]);

    const validateStep = (at: number): boolean => {
        const found: Record<string, string> = {};
        if (at === 0) {
            const value = Number(form.value_km);
            if (form.value_km === '' || !Number.isFinite(value) || value < 0)
                found.value_km = 'Enter a reading of zero or more kilometres.';
            if (!/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/.test(form.observed_local))
                found.observed_at = 'Choose when the reading was seen.';
        }
        if (at === 1 && correcting && !form.correction_reason.trim())
            found.correction_reason =
                'Record why this reading is being corrected.';
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
            `/fleet-assets/vehicles/${vehicle.id}/odometer-observations`,
            {
                value_km: Number(form.value_km),
                observed_local: form.observed_local,
                ...(form.notes.trim() ? { notes: form.notes.trim() } : {}),
                ...(correcting
                    ? {
                          corrects_observation_id: corrects.id,
                          correction_reason: form.correction_reason.trim(),
                      }
                    : {}),
            },
        );
        if (!result) return;
        const observation = isJsonObject(result.observation)
            ? result.observation
            : null;
        const outcome =
            files.length && observation
                ? await uploads.upload(files, {
                      category: 'Odometer evidence',
                      reason: correcting
                          ? 'Evidence for a corrected reading'
                          : 'Dashboard reading evidence',
                      sourceType: 'odometer_observation',
                      sourceId: Number(observation.id),
                  })
                : null;
        setSavedText(
            `${formatKm(Number(form.value_km))} is recorded.${uploadSummary(outcome, files.length)}`,
        );
        onSaved();
    };

    return (
        <WorkspaceWizard
            title={
                correcting
                    ? 'Correct odometer reading'
                    : 'Record odometer reading'
            }
            description={`${vehicle.name}: readings are kept, and a correction keeps the original visible.`}
            railIcon={Gauge}
            railSub={vehicle.registration_number ?? vehicle.asset_tag ?? ''}
            steps={STEPS}
            step={step}
            setStep={setStep}
            pct={Math.round(
                ([
                    form.value_km !== '',
                    !!form.observed_local,
                    !correcting || !!form.correction_reason.trim(),
                ].filter(Boolean).length /
                    3) *
                    100,
            )}
            context={{
                name: vehicle.name,
                detail: [vehicle.registration_number, vehicle.site?.name]
                    .filter(Boolean)
                    .join(' · '),
            }}
            command={command}
            dirty={
                form.notes !== '' ||
                form.correction_reason !== '' ||
                files.length > 0 ||
                (!correcting && form.value_km !== '')
            }
            saved={savedText !== null}
            submitLabel={correcting ? 'Save correction' : 'Save reading'}
            onValidateStep={validateStep}
            onSubmit={submit}
            onClose={onClose}
            onReload={onClose}
            errorKey={JSON.stringify(errors)}
            success={
                <WizardSuccess
                    title={correcting ? 'Correction saved' : 'Reading saved'}
                    blurb={`${savedText ?? ''} Service and RUC distance now use this reading; the original evidence is kept.`}
                    onClose={onClose}
                />
            }
        >
            {step === 0 && (
                <div className="space-y-5">
                    <p className="text-subtle">
                        {correcting
                            ? `Correct the reading of ${formatKm(corrects.value_km)}. The original stays visible in the history.`
                            : 'Record the dashboard reading and when it was seen. No tracker is needed.'}
                    </p>
                    <div className="space-y-5">
                        <WizardField
                            id="value_km"
                            label="Odometer (km)"
                            error={errors.value_km}
                        >
                            <Input
                                {...fieldProps('value_km', errors.value_km)}
                                type="number"
                                inputMode="decimal"
                                min="0"
                                step="0.1"
                                value={form.value_km}
                                onChange={(event) =>
                                    update('value_km', event.target.value)
                                }
                            />
                        </WizardField>
                        <DateTimeField
                            id="observed_at"
                            label="Seen at"
                            value={form.observed_local}
                            onChange={(value) =>
                                update('observed_local', value)
                            }
                            error={errors.observed_at}
                            hint="Pacific/Auckland time"
                        />
                    </div>
                </div>
            )}
            {step === 1 && (
                <div className="space-y-5">
                    <p className="text-subtle">
                        Attach a dashboard photo or supporting document if you
                        have one. Evidence stays with this reading.
                    </p>
                    {correcting ? (
                        <WizardField
                            id="correction_reason"
                            label="Reason for correction"
                            error={errors.correction_reason}
                        >
                            <Textarea
                                {...fieldProps(
                                    'correction_reason',
                                    errors.correction_reason,
                                )}
                                rows={3}
                                maxLength={5000}
                                value={form.correction_reason}
                                onChange={(event) =>
                                    update(
                                        'correction_reason',
                                        event.target.value,
                                    )
                                }
                            />
                        </WizardField>
                    ) : (
                        <WizardField
                            id="notes"
                            label="Observation notes"
                            optional
                            error={errors.notes}
                        >
                            <Textarea
                                {...fieldProps('notes', errors.notes)}
                                rows={3}
                                maxLength={5000}
                                value={form.notes}
                                onChange={(event) =>
                                    update('notes', event.target.value)
                                }
                            />
                        </WizardField>
                    )}
                    {workspace.can.manage_documents && (
                        <StagedFilesField
                            label="Odometer photo or document"
                            files={files}
                            onChange={setFiles}
                        />
                    )}
                </div>
            )}
            {step === 2 && (
                <div className="grid gap-4">
                    <ReviewCard
                        icon={Gauge}
                        title="Odometer reading"
                        onEdit={() => setStep(0)}
                    >
                        <ReviewRow
                            label="Reading"
                            value={
                                form.value_km
                                    ? formatKm(Number(form.value_km))
                                    : undefined
                            }
                        />
                        <ReviewRow
                            label="Seen at"
                            value={form.observed_local.replace('T', ' ')}
                        />
                    </ReviewCard>
                    <ReviewCard
                        icon={Paperclip}
                        title="Source & evidence"
                        onEdit={() => setStep(1)}
                    >
                        <ReviewRow
                            label={
                                correcting ? 'Reason for correction' : 'Notes'
                            }
                            value={
                                (correcting
                                    ? form.correction_reason
                                    : form.notes) || undefined
                            }
                        />
                        <ReviewRow
                            label="Files"
                            value={
                                files.length
                                    ? files.map((file) => file.name).join(', ')
                                    : 'No files attached'
                            }
                        />
                    </ReviewCard>
                </div>
            )}
        </WorkspaceWizard>
    );
}
