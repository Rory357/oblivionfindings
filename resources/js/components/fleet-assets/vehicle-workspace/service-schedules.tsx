import { DatePicker } from '@/components/fleet-assets/maintenance/date-picker';
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
import { Switch } from '@/components/ui/switch';
import { Textarea } from '@/components/ui/textarea';
import { ReviewCard, ReviewRow } from '@/components/wizard/shell';
import { formatDateOnly } from '@/lib/datetime';
import {
    Bell,
    CalendarClock,
    CalendarDays,
    ClipboardCheck,
    History,
    Paperclip,
    Pencil,
    Upload,
    Wrench,
} from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { AddEvidenceDialog } from './add-evidence-dialog';
import { CataloguePicker, formatChoice, PersonPicker } from './choice-picker';
import { uploadSummary, useEvidenceUpload } from './evidence-upload';
import {
    useVehicleCollectionView,
    VehicleCollectionToggle,
    VehicleRecordCollection,
} from './record-collection';
import { isJsonObject, useVehicleRecordCommand } from './record-command';
import {
    PlanAppointmentDialog,
    SectionHeading,
    SourceRecordDialog,
} from './studio-kit';
import type { ServiceSchedule, VehicleWorkspace } from './types';
import {
    fieldProps,
    StagedFilesField,
    WizardField,
    WizardSuccess,
    WorkspaceWizard,
} from './wizard-kit';
import {
    formatKm,
    intervalText,
    todayInAuckland,
    type WorkspaceLocation,
} from './workspace-model';

/** Calendar months without overflow: 31 Aug + 6 months = 28 Feb. */
export function addMonthsNoOverflow(date: string, months: number): string {
    const [year, month, day] = date.split('-').map(Number);
    const target = new Date(Date.UTC(year, month - 1 + months, 1));
    const lastDay = new Date(
        Date.UTC(target.getUTCFullYear(), target.getUTCMonth() + 1, 0),
    ).getUTCDate();
    target.setUTCDate(Math.min(day, lastDay));
    return target.toISOString().slice(0, 10);
}

function addDays(date: string, days: number): string {
    const [year, month, day] = date.split('-').map(Number);
    return new Date(Date.UTC(year, month - 1, day + days))
        .toISOString()
        .slice(0, 10);
}

function dueSoonByDistance(schedule: ServiceSchedule): boolean {
    return (
        !schedule.overdue &&
        schedule.km_remaining !== null &&
        schedule.reminder_km_before !== null &&
        schedule.km_remaining <= schedule.reminder_km_before
    );
}

export function SchedulesPanel({
    workspace,
    onNavigate,
    onChanged,
    createRequested,
    onCreateHandled,
}: {
    workspace: VehicleWorkspace;
    onNavigate: (location: WorkspaceLocation) => void;
    onChanged: () => void;
    createRequested?: boolean;
    onCreateHandled?: () => void;
}) {
    const { view, setView } = useVehicleCollectionView('schedules');
    const [editing, setEditing] = useState<{
        schedule: ServiceSchedule | null;
    } | null>(null);
    const [recording, setRecording] = useState<ServiceSchedule | null>(null);
    const [evidenceFor, setEvidenceFor] = useState<ServiceSchedule | null>(
        null,
    );
    const [viewing, setViewing] = useState<ServiceSchedule | null>(null);
    const [planning, setPlanning] = useState<ServiceSchedule | null>(null);
    const { can, vehicle, odometer } = workspace;
    const today = todayInAuckland();

    useEffect(() => {
        if (createRequested) {
            setEditing({ schedule: null });
            onCreateHandled?.();
        }
    }, [createRequested, onCreateHandled]);

    const planStart = (schedule: ServiceSchedule) =>
        schedule.next_due_at && schedule.next_due_at > today
            ? `${schedule.next_due_at}T09:00`
            : undefined;

    return (
        <div className="studio-page">
            <SectionHeading
                eyebrow="PLANNED MAINTENANCE"
                title="Service schedules"
            >
                <VehicleCollectionToggle
                    label="Service schedules"
                    view={view}
                    onChange={setView}
                />
                {can.manage_schedules && (
                    <Button onClick={() => setEditing({ schedule: null })}>
                        Add schedule
                    </Button>
                )}
            </SectionHeading>
            <VehicleRecordCollection
                label="Service schedules"
                view={view}
                columns={[
                    { label: 'Next due / remaining', width: '1.2fr' },
                    { label: 'Interval & responsibility', width: '1.1fr' },
                    { label: 'Evidence & actions', width: '1.5fr' },
                ]}
                empty={{
                    title: 'No service schedules yet',
                    description:
                        'Add a vehicle or component requirement and supporting documents.',
                    action: can.manage_schedules ? (
                        <Button onClick={() => setEditing({ schedule: null })}>
                            Set up service schedule
                        </Button>
                    ) : undefined,
                }}
                records={workspace.schedules.map((schedule) => {
                    const soon = dueSoonByDistance(schedule);
                    const lastKm = schedule.last_completed_km;
                    const progress =
                        schedule.next_due_km !== null &&
                        lastKm !== null &&
                        odometer.current_km !== null &&
                        schedule.next_due_km > lastKm
                            ? Math.max(
                                  0,
                                  Math.min(
                                      100,
                                      ((odometer.current_km - lastKm) /
                                          (schedule.next_due_km - lastKm)) *
                                          100,
                                  ),
                              )
                            : 0;
                    const daysRemaining = schedule.next_due_at
                        ? Math.max(
                              0,
                              Math.round(
                                  (Date.parse(
                                      `${schedule.next_due_at}T12:00Z`,
                                  ) -
                                      Date.parse(`${today}T12:00Z`)) /
                                      86_400_000,
                              ),
                          )
                        : null;
                    const owner = schedule.owner?.name ?? 'No owner recorded';
                    return {
                        id: schedule.id,
                        name: schedule.name,
                        subline: schedule.is_active
                            ? intervalText(schedule)
                            : 'Paused',
                        icon: Wrench,
                        tone: schedule.overdue
                            ? 'critical'
                            : soon
                              ? 'warning'
                              : undefined,
                        onOpen: () => setViewing(schedule),
                        fields: [
                            <>
                                <strong>
                                    {schedule.next_due_at
                                        ? formatDateOnly(schedule.next_due_at)
                                        : schedule.next_due_km !== null
                                          ? formatKm(schedule.next_due_km)
                                          : 'No next due point'}
                                </strong>
                                <StatusBadge
                                    variant={
                                        !schedule.is_active
                                            ? 'neutral'
                                            : schedule.overdue
                                              ? 'critical'
                                              : soon
                                                ? 'warning'
                                                : 'info'
                                    }
                                >
                                    {!schedule.is_active
                                        ? 'Paused'
                                        : schedule.overdue
                                          ? 'Overdue'
                                          : soon
                                            ? 'Due soon'
                                            : 'Scheduled'}
                                </StatusBadge>
                                {schedule.next_due_km !== null &&
                                schedule.km_remaining !== null ? (
                                    <>
                                        <span>
                                            {formatKm(
                                                Math.max(
                                                    0,
                                                    schedule.km_remaining,
                                                ),
                                            )}{' '}
                                            remaining
                                        </span>
                                        <progress
                                            value={progress}
                                            max={100}
                                            aria-label={`${schedule.name} distance interval used`}
                                        />
                                        <small>
                                            Due at{' '}
                                            {formatKm(schedule.next_due_km)} ·
                                            recorded odometer
                                        </small>
                                    </>
                                ) : daysRemaining !== null ? (
                                    <small>
                                        {daysRemaining} days remaining
                                    </small>
                                ) : null}
                            </>,
                            <>
                                <strong>{intervalText(schedule)}</strong>
                                <span>{owner}</span>
                                <small>
                                    Last service{' '}
                                    {schedule.last_completed_at
                                        ? formatDateOnly(
                                              schedule.last_completed_at,
                                          )
                                        : 'not recorded'}
                                </small>
                            </>,
                            <>
                                {(can.schedule_service ||
                                    can.manage_documents) && (
                                    <div className="collection-actions">
                                        {can.schedule_service && (
                                            <Button
                                                onClick={() =>
                                                    setPlanning(schedule)
                                                }
                                            >
                                                Plan service
                                            </Button>
                                        )}
                                        {can.manage_documents && (
                                            <Button
                                                variant="outline"
                                                onClick={() =>
                                                    setEvidenceFor(schedule)
                                                }
                                            >
                                                <Upload className="size-[14px]" />
                                                Upload evidence
                                            </Button>
                                        )}
                                    </div>
                                )}
                                <small>
                                    {schedule.files.length} supporting{' '}
                                    {schedule.files.length === 1
                                        ? 'file'
                                        : 'files'}
                                </small>
                                {schedule.files.map((file) =>
                                    file.url ? (
                                        <a
                                            className="inline-evidence"
                                            key={file.id}
                                            href={file.url}
                                            target="_blank"
                                            rel="noreferrer"
                                        >
                                            <Paperclip className="size-[13px]" />
                                            {file.name}
                                        </a>
                                    ) : (
                                        <span
                                            className="inline-evidence"
                                            key={file.id}
                                        >
                                            <Paperclip className="size-[13px]" />
                                            {file.name} · waiting for a virus
                                            check
                                        </span>
                                    ),
                                )}
                            </>,
                        ],
                        actions: [
                            {
                                label: 'View schedule',
                                icon: Wrench,
                                onClick: () => setViewing(schedule),
                            },
                            ...(can.manage_schedules
                                ? [
                                      {
                                          label: 'Manage schedule',
                                          icon: Pencil,
                                          onClick: () =>
                                              setEditing({ schedule }),
                                      },
                                      {
                                          label: 'Record service',
                                          icon: ClipboardCheck,
                                          onClick: () => setRecording(schedule),
                                      },
                                  ]
                                : []),
                            ...(can.schedule_service
                                ? [
                                      {
                                          label: 'Plan service',
                                          icon: CalendarDays,
                                          onClick: () => setPlanning(schedule),
                                      },
                                  ]
                                : []),
                            ...(can.manage_documents
                                ? [
                                      {
                                          label: 'Upload evidence',
                                          icon: Upload,
                                          onClick: () =>
                                              setEvidenceFor(schedule),
                                      },
                                  ]
                                : []),
                            {
                                label: 'Reminder history',
                                icon: Bell,
                                onClick: () =>
                                    onNavigate({
                                        tab: 'service',
                                        view: 'reminders',
                                    }),
                            },
                            {
                                label: 'View completed work',
                                icon: History,
                                onClick: () =>
                                    onNavigate({
                                        tab: 'service',
                                        view: 'history',
                                    }),
                            },
                        ],
                        footer: {
                            personName: schedule.owner?.name,
                            primary: owner,
                            secondary: 'Schedule owner',
                        },
                    };
                })}
            />
            <p className="studio-footnote">
                The first reached date or distance trigger needs action.
                Intervals are recorded for this vehicle from its own source;
                they are not a built-in policy. Recording a service never
                releases a maintenance hold.
            </p>
            {viewing && (
                <SourceRecordDialog
                    title={viewing.name}
                    description={`${[vehicle.asset_tag, vehicle.name].filter(Boolean).join(' · ')} · Service schedule`}
                    rows={[
                        ['Interval', intervalText(viewing)],
                        ['Owner', viewing.owner?.name ?? 'No owner recorded'],
                        [
                            'Next due',
                            viewing.next_due_at
                                ? formatDateOnly(viewing.next_due_at)
                                : 'Not set',
                        ],
                        [
                            'Distance trigger',
                            viewing.next_due_km !== null
                                ? formatKm(viewing.next_due_km)
                                : 'Not set',
                        ],
                        [
                            'Last service',
                            viewing.last_completed_at
                                ? `${formatDateOnly(viewing.last_completed_at)}${viewing.last_completed_km !== null ? ` · ${formatKm(viewing.last_completed_km)}` : ''}`
                                : 'Not recorded',
                        ],
                    ]}
                    onClose={() => setViewing(null)}
                />
            )}
            {planning && (
                <PlanAppointmentDialog
                    vehicle={vehicle}
                    presetType={planning.name}
                    startLocal={planStart(planning)}
                    onClose={() => setPlanning(null)}
                    onSaved={onChanged}
                />
            )}
            {editing && (
                <ScheduleDialog
                    workspace={workspace}
                    schedule={editing.schedule}
                    onClose={() => setEditing(null)}
                    onSaved={onChanged}
                />
            )}
            {recording && (
                <ServiceRecordDialog
                    workspace={workspace}
                    schedule={recording}
                    onClose={() => setRecording(null)}
                    onSaved={onChanged}
                />
            )}
            {evidenceFor && (
                <AddEvidenceDialog
                    vehicle={workspace.vehicle}
                    title={`Add evidence to ${evidenceFor.name}`}
                    category="Service schedule evidence"
                    sourceType="service_schedule"
                    sourceId={evidenceFor.id}
                    onClose={() => setEvidenceFor(null)}
                    onSaved={onChanged}
                />
            )}
        </div>
    );
}

const SCHEDULE_STEPS = [
    {
        key: 'requirement',
        label: 'Service requirement',
        blurb: 'Service type and interval',
        icon: Wrench,
    },
    {
        key: 'due',
        label: 'Next due & responsibility',
        blurb: 'First trigger and owner',
        icon: CalendarClock,
    },
    {
        key: 'reminder',
        label: 'Reminder plan',
        blurb: 'When it shows as due',
        icon: Bell,
    },
    {
        key: 'review',
        label: 'Review',
        blurb: 'Confirm the schedule',
        icon: ClipboardCheck,
    },
];

export function ScheduleDialog({
    workspace,
    schedule,
    onClose,
    onSaved,
}: {
    workspace: VehicleWorkspace;
    schedule: ServiceSchedule | null;
    onClose: () => void;
    onSaved: () => void;
}) {
    const vehicle = workspace.vehicle;
    const [initial] = useState(() => ({
        name: schedule?.name ?? '',
        interval_months: schedule?.interval_months
            ? String(schedule.interval_months)
            : '',
        interval_km: schedule?.interval_km ? String(schedule.interval_km) : '',
        next_due_at: schedule?.next_due_at ?? '',
        next_due_km:
            schedule?.next_due_km !== null &&
            schedule?.next_due_km !== undefined
                ? String(schedule.next_due_km)
                : '',
        owner_user_id: schedule?.owner?.id ?? vehicle.responsible?.id ?? null,
        reminder_days_before:
            schedule?.reminder_days_before !== null &&
            schedule?.reminder_days_before !== undefined
                ? String(schedule.reminder_days_before)
                : '7',
        reminder_km_before:
            schedule?.reminder_km_before !== null &&
            schedule?.reminder_km_before !== undefined
                ? String(schedule.reminder_km_before)
                : '',
        is_active: schedule?.is_active ?? true,
    }));
    const [form, setForm] = useState(initial);
    const [step, setStep] = useState(0);
    const [localErrors, setLocalErrors] = useState<Record<string, string>>({});
    const [saved, setSaved] = useState(false);
    const command = useVehicleRecordCommand(isJsonObject);
    const errors = { ...command.errors, ...localErrors };
    const update = <K extends keyof typeof form>(
        key: K,
        value: (typeof form)[K],
    ) => {
        setForm((old) => ({ ...old, [key]: value }));
        setLocalErrors((old) => {
            const next = { ...old };
            delete next[key as string];
            return next;
        });
        command.clearError(key as string);
    };
    useEffect(() => {
        const field = Object.keys(command.errors)[0];
        if (!field) return;
        setStep(
            ['name', 'interval_months', 'interval_km'].includes(field)
                ? 0
                : ['reminder_days_before', 'reminder_km_before'].includes(field)
                  ? 2
                  : 1,
        );
    }, [command.errors]);

    const validateStep = (at: number): boolean => {
        const found: Record<string, string> = {};
        if (at === 0) {
            if (!form.name.trim()) found.name = 'Choose the service type.';
            if (
                !form.interval_months &&
                !form.interval_km &&
                !schedule?.interval_days
            )
                found.interval_months =
                    'Record at least one positive interval.';
        }
        if (at === 1) {
            if (!form.next_due_at && form.next_due_km === '')
                found.next_due_at = 'Record at least one next-due trigger.';
            if (!form.owner_user_id)
                found.owner_user_id = 'Choose the responsible person.';
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
        const body = {
            name: form.name.trim(),
            interval_months: form.interval_months
                ? Number(form.interval_months)
                : null,
            interval_km: form.interval_km ? Number(form.interval_km) : null,
            next_due_at: form.next_due_at || null,
            next_due_km:
                form.next_due_km === '' ? null : Number(form.next_due_km),
            owner_user_id: form.owner_user_id,
            reminder_days_before:
                form.reminder_days_before === ''
                    ? null
                    : Number(form.reminder_days_before),
            reminder_km_before:
                form.reminder_km_before === ''
                    ? null
                    : Number(form.reminder_km_before),
            is_active: form.is_active,
            ...(schedule ? { expected_version: schedule.lock_version } : {}),
        };
        const result = schedule
            ? await command.submit(
                  `/fleet-assets/vehicles/${vehicle.id}/service-schedules/${schedule.id}`,
                  body,
                  { method: 'PUT' },
              )
            : await command.submit(
                  `/fleet-assets/vehicles/${vehicle.id}/service-schedules`,
                  body,
              );
        if (result) {
            setSaved(true);
            onSaved();
        }
    };
    const owner = workspace.people.find(
        (person) => person.id === form.owner_user_id,
    );

    return (
        <WorkspaceWizard
            title={
                schedule ? 'Manage service schedule' : 'Set up service schedule'
            }
            description={`${vehicle.name}: intervals come from this vehicle's own service source.`}
            railIcon={Wrench}
            railSub={vehicle.registration_number ?? vehicle.asset_tag ?? ''}
            steps={SCHEDULE_STEPS}
            step={step}
            setStep={setStep}
            pct={Math.round(
                ([
                    !!form.name,
                    !!(
                        form.interval_months ||
                        form.interval_km ||
                        schedule?.interval_days
                    ),
                    !!(form.next_due_at || form.next_due_km),
                    !!form.owner_user_id,
                ].filter(Boolean).length /
                    4) *
                    100,
            )}
            context={{
                name: vehicle.name,
                detail: [vehicle.registration_number, vehicle.site?.name]
                    .filter(Boolean)
                    .join(' · '),
            }}
            command={command}
            dirty={JSON.stringify(form) !== JSON.stringify(initial)}
            saved={saved}
            submitLabel={schedule ? 'Save schedule' : 'Create schedule'}
            onValidateStep={validateStep}
            onSubmit={submit}
            onClose={onClose}
            onReload={onClose}
            errorKey={JSON.stringify(errors)}
            success={
                <WizardSuccess
                    title={schedule ? 'Schedule saved' : 'Schedule created'}
                    blurb="The schedule, its due point and its owner now show on this vehicle, in All Tasks and on the site calendar."
                    onClose={onClose}
                />
            }
        >
            {step === 0 && (
                <div className="space-y-5">
                    <p className="text-subtle">
                        Use this vehicle&apos;s own service source. The example
                        intervals are suggestions, not policy.
                    </p>
                    <WizardField
                        id="name"
                        label="Service type"
                        error={errors.name}
                    >
                        <CataloguePicker
                            id="name"
                            kind="service_type"
                            label="Service type"
                            value={form.name}
                            onChange={(value) => update('name', value)}
                            invalid={!!errors.name}
                        />
                    </WizardField>
                    <div className="vehicle-wizard-fields">
                        <WizardField
                            id="interval_months"
                            label="Interval in months"
                            optional
                            error={errors.interval_months}
                        >
                            <CataloguePicker
                                id="interval_months"
                                kind="interval_months"
                                label="Interval in months"
                                optional
                                value={form.interval_months}
                                onChange={(value) =>
                                    update('interval_months', value)
                                }
                                invalid={!!errors.interval_months}
                            />
                        </WizardField>
                        <WizardField
                            id="interval_km"
                            label="Interval in kilometres"
                            optional
                            error={errors.interval_km}
                        >
                            <CataloguePicker
                                id="interval_km"
                                kind="interval_km"
                                label="Interval in kilometres"
                                optional
                                value={form.interval_km}
                                onChange={(value) =>
                                    update('interval_km', value)
                                }
                            />
                        </WizardField>
                    </div>
                    {schedule?.interval_days && !form.interval_months && (
                        <p className="text-caption">
                            This schedule uses a {schedule.interval_days}-day
                            interval from the earlier schedules list. Choosing
                            months replaces it.
                        </p>
                    )}
                </div>
            )}
            {step === 1 && (
                <div className="space-y-5">
                    <p className="text-subtle">
                        The first trigger reached needs action. A stale odometer
                        reading can&apos;t prove distance remaining.
                    </p>
                    <div className="vehicle-wizard-fields">
                        <WizardField
                            id="next_due_at"
                            label="Next due date"
                            optional
                            error={errors.next_due_at}
                        >
                            <DatePicker
                                id="next_due_at"
                                label="Next due date"
                                value={form.next_due_at}
                                onChange={(value) =>
                                    update('next_due_at', value)
                                }
                                invalid={!!errors.next_due_at}
                            />
                        </WizardField>
                        <WizardField
                            id="next_due_km"
                            label="Next due odometer (km)"
                            optional
                            error={errors.next_due_km}
                        >
                            <Input
                                {...fieldProps(
                                    'next_due_km',
                                    errors.next_due_km,
                                )}
                                type="number"
                                inputMode="numeric"
                                min="0"
                                value={form.next_due_km}
                                onChange={(event) =>
                                    update('next_due_km', event.target.value)
                                }
                            />
                        </WizardField>
                    </div>
                    <WizardField
                        id="owner_user_id"
                        label="Responsible person"
                        error={errors.owner_user_id}
                    >
                        <PersonPicker
                            id="owner_user_id"
                            label="Responsible person"
                            value={form.owner_user_id}
                            people={workspace.people}
                            onChange={(value) => update('owner_user_id', value)}
                            invalid={!!errors.owner_user_id}
                        />
                    </WizardField>
                </div>
            )}
            {step === 2 && (
                <div className="space-y-5">
                    <p className="text-subtle">
                        The schedule appears in All Tasks and on the site
                        calendar ahead of its due date. No email or text is
                        sent.
                    </p>
                    <div className="vehicle-wizard-fields">
                        <WizardField
                            id="reminder_days_before"
                            label="Show as due this many days before"
                            optional
                            error={errors.reminder_days_before}
                        >
                            <CataloguePicker
                                id="reminder_days_before"
                                kind="reminder_days_before"
                                label="Days before due"
                                optional
                                value={form.reminder_days_before}
                                onChange={(value) =>
                                    update('reminder_days_before', value)
                                }
                            />
                        </WizardField>
                        <WizardField
                            id="reminder_km_before"
                            label="Flag as due soon within"
                            optional
                            error={errors.reminder_km_before}
                        >
                            <CataloguePicker
                                id="reminder_km_before"
                                kind="reminder_km_before"
                                label="Kilometres before due"
                                optional
                                value={form.reminder_km_before}
                                onChange={(value) =>
                                    update('reminder_km_before', value)
                                }
                            />
                        </WizardField>
                    </div>
                    {schedule && (
                        <label className="flex items-center gap-3 rounded-lg border p-3">
                            <Switch
                                checked={form.is_active}
                                onCheckedChange={(value) =>
                                    update('is_active', value)
                                }
                                aria-label="Schedule is active"
                            />
                            <span>
                                <span className="block font-medium">
                                    Schedule is active
                                </span>
                                <span className="text-caption">
                                    Pause a schedule that no longer applies; its
                                    history is kept.
                                </span>
                            </span>
                        </label>
                    )}
                </div>
            )}
            {step === 3 && (
                <div className="grid gap-4">
                    <ReviewCard
                        icon={Wrench}
                        title="Service requirement"
                        onEdit={() => setStep(0)}
                    >
                        <ReviewRow
                            label="Service type"
                            value={form.name || undefined}
                        />
                        <ReviewRow
                            label="Interval"
                            value={
                                [
                                    form.interval_months
                                        ? formatChoice(
                                              'interval_months',
                                              form.interval_months,
                                          )
                                        : schedule?.interval_days
                                          ? `${schedule.interval_days} days`
                                          : null,
                                    form.interval_km
                                        ? formatChoice(
                                              'interval_km',
                                              form.interval_km,
                                          )
                                        : null,
                                ]
                                    .filter(Boolean)
                                    .join(' or ') || undefined
                            }
                        />
                    </ReviewCard>
                    <ReviewCard
                        icon={CalendarClock}
                        title="Next due & responsibility"
                        onEdit={() => setStep(1)}
                    >
                        <ReviewRow
                            label="Next due date"
                            value={
                                form.next_due_at
                                    ? formatDateOnly(form.next_due_at)
                                    : undefined
                            }
                        />
                        <ReviewRow
                            label="Next due odometer"
                            value={
                                form.next_due_km
                                    ? formatKm(Number(form.next_due_km))
                                    : undefined
                            }
                        />
                        <ReviewRow
                            label="Responsible person"
                            value={owner?.name}
                        />
                    </ReviewCard>
                    <ReviewCard
                        icon={Bell}
                        title="Reminder plan"
                        onEdit={() => setStep(2)}
                    >
                        <ReviewRow
                            label="Shows as due"
                            value={
                                form.reminder_days_before !== ''
                                    ? `${form.reminder_days_before} days before`
                                    : undefined
                            }
                        />
                        <ReviewRow
                            label="Due soon within"
                            value={
                                form.reminder_km_before
                                    ? formatKm(Number(form.reminder_km_before))
                                    : undefined
                            }
                        />
                    </ReviewCard>
                </div>
            )}
        </WorkspaceWizard>
    );
}

const RECORD_STEPS = [
    {
        key: 'actual',
        label: 'Actual service & evidence',
        blurb: 'What was done and when',
        icon: ClipboardCheck,
    },
    {
        key: 'next',
        label: 'Next service',
        blurb: 'The following due point',
        icon: CalendarClock,
    },
    {
        key: 'review',
        label: 'Review',
        blurb: 'Confirm the service record',
        icon: History,
    },
];

function ServiceRecordDialog({
    workspace,
    schedule,
    onClose,
    onSaved,
}: {
    workspace: VehicleWorkspace;
    schedule: ServiceSchedule;
    onClose: () => void;
    onSaved: () => void;
}) {
    const vehicle = workspace.vehicle;
    const today = todayInAuckland();
    const [form, setForm] = useState({
        completed_on: today,
        odometer_km:
            workspace.odometer.current_km !== null
                ? String(workspace.odometer.current_km)
                : '',
        work_order_id: '',
        provider: '',
        evidence_reference: '',
        notes: '',
        next_due_at: '',
        next_due_km: '',
        nextTouched: false,
    });
    const [files, setFiles] = useState<File[]>([]);
    const [step, setStep] = useState(0);
    const [localErrors, setLocalErrors] = useState<Record<string, string>>({});
    const [savedText, setSavedText] = useState<string | null>(null);
    const command = useVehicleRecordCommand(isJsonObject);
    const uploads = useEvidenceUpload(vehicle.id);
    const errors = { ...command.errors, ...localErrors };
    const suggested = useMemo(() => {
        if (!form.completed_on) return { date: '', km: '' };
        const date = schedule.interval_months
            ? addMonthsNoOverflow(form.completed_on, schedule.interval_months)
            : schedule.interval_days
              ? addDays(form.completed_on, schedule.interval_days)
              : '';
        const km =
            schedule.interval_km && form.odometer_km !== ''
                ? String(Number(form.odometer_km) + schedule.interval_km)
                : '';
        return { date, km };
    }, [form.completed_on, form.odometer_km, schedule]);
    const nextDueAt = form.nextTouched ? form.next_due_at : suggested.date;
    const nextDueKm = form.nextTouched ? form.next_due_km : suggested.km;
    const update = (key: keyof typeof form, value: string | boolean) => {
        setForm((old) => ({ ...old, [key]: value }));
        setLocalErrors((old) => {
            const next = { ...old };
            delete next[key];
            return next;
        });
        command.clearError(key);
    };
    useEffect(() => {
        const field = Object.keys(command.errors)[0];
        if (field)
            setStep(['next_due_at', 'next_due_km'].includes(field) ? 1 : 0);
    }, [command.errors]);

    const validateStep = (at: number): boolean => {
        const found: Record<string, string> = {};
        if (at === 0) {
            if (!form.completed_on)
                found.completed_on = 'Choose the date the service was done.';
            else if (form.completed_on > today)
                found.completed_on =
                    'The service can’t be recorded as done in the future.';
            if (!form.notes.trim())
                found.notes = 'Record the work performed and its outcome.';
        }
        if (at === 1) {
            if (
                nextDueAt &&
                form.completed_on &&
                nextDueAt <= form.completed_on
            )
                found.next_due_at =
                    'The next service must follow the completed service.';
            if (
                nextDueKm !== '' &&
                form.odometer_km !== '' &&
                Number(nextDueKm) <= Number(form.odometer_km)
            )
                found.next_due_km =
                    'The next service distance must be above the completion odometer.';
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
            `/fleet-assets/vehicles/${vehicle.id}/service-schedules/${schedule.id}/completions`,
            {
                completed_on: form.completed_on,
                odometer_km:
                    form.odometer_km === '' ? null : Number(form.odometer_km),
                work_order_id: form.work_order_id
                    ? Number(form.work_order_id)
                    : null,
                provider: form.provider || null,
                evidence_reference: form.evidence_reference || null,
                notes: form.notes.trim(),
                next_due_at: nextDueAt || null,
                next_due_km: nextDueKm === '' ? null : Number(nextDueKm),
                expected_version: schedule.lock_version,
            },
        );
        if (!result) return;
        const completion = isJsonObject(result.completion)
            ? result.completion
            : null;
        const outcome =
            files.length && completion
                ? await uploads.upload(files, {
                      category: 'Service report',
                      reason: `${schedule.name} completed ${form.completed_on}`,
                      sourceType: 'service_completion',
                      sourceId: Number(completion.id),
                  })
                : null;
        setSavedText(
            `${schedule.name} is recorded for ${formatDateOnly(form.completed_on)}.${uploadSummary(outcome, files.length)}`,
        );
        onSaved();
    };

    return (
        <WorkspaceWizard
            title={`Record ${schedule.name}`}
            description={`${vehicle.name}: a recorded service keeps its history and moves the next due point.`}
            railIcon={ClipboardCheck}
            railSub={vehicle.registration_number ?? vehicle.asset_tag ?? ''}
            steps={RECORD_STEPS}
            step={step}
            setStep={setStep}
            pct={Math.round(
                ([
                    !!form.completed_on,
                    !!form.notes.trim(),
                    !!(nextDueAt || nextDueKm),
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
                !!form.notes || files.length > 0 || !!form.evidence_reference
            }
            saved={savedText !== null}
            submitLabel="Record service"
            onValidateStep={validateStep}
            onSubmit={submit}
            onClose={onClose}
            onReload={onClose}
            errorKey={JSON.stringify(errors)}
            success={
                <WizardSuccess
                    title="Service recorded"
                    blurb={`${savedText ?? ''} This does not release a maintenance hold or approve any cost.`}
                    onClose={onClose}
                />
            }
        >
            {step === 0 && (
                <div className="space-y-5">
                    <div className="vehicle-wizard-fields">
                        <WizardField
                            id="completed_on"
                            label="Date completed"
                            error={errors.completed_on}
                        >
                            <DatePicker
                                id="completed_on"
                                label="Date completed"
                                value={form.completed_on}
                                onChange={(value) =>
                                    update('completed_on', value)
                                }
                                invalid={!!errors.completed_on}
                            />
                        </WizardField>
                        <WizardField
                            id="odometer_km"
                            label="Odometer at service (km)"
                            optional
                            error={errors.odometer_km}
                            hint="Leave blank if it wasn’t read; the next distance trigger then stays for you to set."
                        >
                            <Input
                                {...fieldProps(
                                    'odometer_km',
                                    errors.odometer_km,
                                )}
                                type="number"
                                inputMode="decimal"
                                min="0"
                                step="0.1"
                                value={form.odometer_km}
                                onChange={(event) =>
                                    update('odometer_km', event.target.value)
                                }
                            />
                        </WizardField>
                        <WizardField
                            id="provider"
                            label="Provider"
                            optional
                            error={errors.provider}
                        >
                            <Input
                                {...fieldProps('provider', errors.provider)}
                                maxLength={160}
                                value={form.provider}
                                onChange={(event) =>
                                    update('provider', event.target.value)
                                }
                            />
                        </WizardField>
                        <WizardField
                            id="evidence_reference"
                            label="Invoice or report reference"
                            optional
                            error={errors.evidence_reference}
                        >
                            <Input
                                {...fieldProps(
                                    'evidence_reference',
                                    errors.evidence_reference,
                                )}
                                maxLength={160}
                                value={form.evidence_reference}
                                onChange={(event) =>
                                    update(
                                        'evidence_reference',
                                        event.target.value,
                                    )
                                }
                            />
                        </WizardField>
                    </div>
                    {workspace.work.can_view &&
                        workspace.work.open.length > 0 && (
                            <WizardField
                                id="work_order_id"
                                label="Related maintenance work"
                                optional
                                error={errors.work_order_id}
                            >
                                <Select
                                    value={form.work_order_id || undefined}
                                    onValueChange={(value) =>
                                        update(
                                            'work_order_id',
                                            value === 'none' ? '' : value,
                                        )
                                    }
                                >
                                    <SelectTrigger
                                        {...fieldProps(
                                            'work_order_id',
                                            errors.work_order_id,
                                        )}
                                    >
                                        <SelectValue placeholder="No related work" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="none">
                                            No related work
                                        </SelectItem>
                                        {workspace.work.open.map((order) => (
                                            <SelectItem
                                                key={order.id}
                                                value={String(order.id)}
                                            >
                                                {[order.reference, order.title]
                                                    .filter(Boolean)
                                                    .join(' · ')}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </WizardField>
                        )}
                    <WizardField
                        id="notes"
                        label="Work performed and outcome"
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
                    {workspace.can.manage_documents && (
                        <StagedFilesField
                            label="Service report or invoice"
                            files={files}
                            onChange={setFiles}
                        />
                    )}
                </div>
            )}
            {step === 1 && (
                <div className="space-y-5">
                    <p className="text-subtle">
                        Suggested from the completion date and reading using
                        this schedule&apos;s interval ({intervalText(schedule)}
                        ). Change them if the service source says otherwise.
                    </p>
                    <div className="vehicle-wizard-fields">
                        <WizardField
                            id="next_due_at"
                            label="Next due date"
                            optional
                            error={errors.next_due_at}
                        >
                            <DatePicker
                                id="next_due_at"
                                label="Next due date"
                                value={nextDueAt}
                                onChange={(value) =>
                                    setForm((old) => ({
                                        ...old,
                                        next_due_at: value,
                                        next_due_km: old.nextTouched
                                            ? old.next_due_km
                                            : nextDueKm,
                                        nextTouched: true,
                                    }))
                                }
                                invalid={!!errors.next_due_at}
                            />
                        </WizardField>
                        <WizardField
                            id="next_due_km"
                            label="Next due odometer (km)"
                            optional
                            error={errors.next_due_km}
                        >
                            <Input
                                {...fieldProps(
                                    'next_due_km',
                                    errors.next_due_km,
                                )}
                                type="number"
                                inputMode="numeric"
                                min="0"
                                value={nextDueKm}
                                onChange={(event) =>
                                    setForm((old) => ({
                                        ...old,
                                        next_due_km: event.target.value,
                                        next_due_at: old.nextTouched
                                            ? old.next_due_at
                                            : nextDueAt,
                                        nextTouched: true,
                                    }))
                                }
                            />
                        </WizardField>
                    </div>
                </div>
            )}
            {step === 2 && (
                <div className="grid gap-4">
                    <ReviewCard
                        icon={ClipboardCheck}
                        title="Actual service & evidence"
                        onEdit={() => setStep(0)}
                    >
                        <ReviewRow
                            label="Completed"
                            value={
                                form.completed_on
                                    ? formatDateOnly(form.completed_on)
                                    : undefined
                            }
                        />
                        <ReviewRow
                            label="Odometer"
                            value={
                                form.odometer_km
                                    ? formatKm(Number(form.odometer_km))
                                    : 'Not read'
                            }
                        />
                        <ReviewRow
                            label="Provider"
                            value={form.provider || undefined}
                        />
                        <ReviewRow
                            label="Reference"
                            value={form.evidence_reference || undefined}
                        />
                        <ReviewRow
                            label="Work performed"
                            value={form.notes || undefined}
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
                    <ReviewCard
                        icon={CalendarClock}
                        title="Next service"
                        onEdit={() => setStep(1)}
                    >
                        <ReviewRow
                            label="Next due date"
                            value={
                                nextDueAt
                                    ? formatDateOnly(nextDueAt)
                                    : undefined
                            }
                        />
                        <ReviewRow
                            label="Next due odometer"
                            value={
                                nextDueKm
                                    ? formatKm(Number(nextDueKm))
                                    : undefined
                            }
                        />
                    </ReviewCard>
                    <p className="text-caption">
                        Recording a service does not release a maintenance hold,
                        complete other work or approve Finance.
                    </p>
                </div>
            )}
        </WorkspaceWizard>
    );
}
