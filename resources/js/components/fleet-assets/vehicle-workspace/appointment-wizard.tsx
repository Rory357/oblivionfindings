import { DateTimeField } from '@/components/fleet-assets/maintenance/date-time-field';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { ReviewCard, ReviewRow } from '@/components/wizard/shell';
import { toDatetimeLocal } from '@/lib/datetime';
import {
    CalendarDays,
    FileCheck2,
    ShieldCheck,
    UserRound,
    Wrench,
} from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';
import {
    addLocalMinutes,
    conflictWith,
    defaultBookingStart,
    localLabel,
} from './booking-wizard';
import type { VehicleCalendarSummary } from './calendar-types';
import { CataloguePicker } from './choice-picker';
import {
    isJsonObject,
    sendVehicleRecord,
    useVehicleRecordCommand,
} from './record-command';
import type { VehicleProfile } from './types';
import {
    fieldProps,
    StagedFilesField,
    StudioNotice,
    WizardField,
    WizardSuccess,
    WorkspaceWizard,
} from './wizard-kit';

const NEW_WORK = 'new';

type Operation = 'plan' | 'cancel' | 'overrun';

const OPERATIONS: Array<{ value: Operation; label: string }> = [
    { value: 'plan', label: 'Plan / reschedule' },
    { value: 'cancel', label: 'Cancel appointment' },
    { value: 'overrun', label: 'Record overrun' },
];

/** An appointment already planned on a work order, from the calendar item. */
export type PlannedAppointment = {
    start: string;
    end: string | null;
    provider?: string | null;
    unavailable?: boolean;
};

/**
 * Schedule a service or inspection from the vehicle calendar, or manage one
 * already planned (reschedule, cancel or record an overrun). Appointments
 * live on Maintenance work orders.
 */
export function AppointmentWizard({
    vehicle,
    summary,
    startLocal,
    workOrderId,
    appointment,
    presetType,
    source,
    onClose,
    onSaved,
}: {
    vehicle: VehicleProfile;
    summary: VehicleCalendarSummary;
    startLocal?: string;
    /** The work order that holds (or will hold) the appointment. */
    workOrderId?: number;
    /** Manage mode: the appointment already planned on that work order. */
    appointment?: PlannedAppointment;
    /** "Plan linked appointment": the service or due item it is for. */
    presetType?: string;
    /** "Plan linked appointment": the schedule or compliance record new work is reported from. */
    source?: { type: 'service_schedule' | 'compliance_record'; id: number };
    onClose: () => void;
    onSaved: () => void;
}) {
    const manage = !!appointment && !!workOrderId;
    const [workVersions] = useState(() =>
        Object.fromEntries(
            summary.open_work.map((work) => [work.id, work.version]),
        ),
    );
    const [initial] = useState(() => {
        const start = appointment
            ? toDatetimeLocal(appointment.start)
            : (startLocal ?? defaultBookingStart());
        return {
            work: workOrderId ? String(workOrderId) : NEW_WORK,
            type: presetType ?? '',
            provider: appointment?.provider ?? '',
            operation: 'plan' as Operation,
            start,
            end: appointment?.end
                ? toDatetimeLocal(appointment.end)
                : addLocalMinutes(start, 120),
            unavailable: appointment ? appointment.unavailable !== false : true,
            reference: '',
            notes: '',
            changeReason: '',
            impactReviewed: false,
        };
    });
    const [form, setForm] = useState(initial);
    const [files, setFiles] = useState<File[]>([]);
    const [step, setStep] = useState(0);
    const [localErrors, setLocalErrors] = useState<Record<string, string>>({});
    const [savedText, setSavedText] = useState<string | null>(null);
    const command = useVehicleRecordCommand(isJsonObject);
    const errors: Record<string, string> = {
        ...command.errors,
        ...localErrors,
    };
    const existing = summary.open_work.find(
        (work) => String(work.id) === form.work,
    );
    const today = toDatetimeLocal(new Date().toISOString());
    const cancelling = manage && form.operation === 'cancel';
    const overrun = manage && form.operation === 'overrun';
    const update = <K extends keyof typeof form>(
        key: K,
        value: (typeof form)[K],
    ) => {
        setForm((old) => ({ ...old, [key]: value }));
        setLocalErrors({});
        command.clearError(key as string);
    };
    // Server field names for each form field, so its errors land in place.
    const serverField: Record<string, string[]> = {
        type: ['title'],
        work: ['work_order_id', 'asset_id', 'status'],
        provider: ['provider_name'],
        start: ['starts_local', 'starts_at', 'time'],
        end: ['ends_local', 'ends_at'],
        reference: ['provider_reference'],
        notes: ['notes', 'note', 'description'],
        changeReason: ['change_reason', 'reason'],
        files: ['files'],
    };
    const error = (field: string): string | undefined =>
        errors[field] ??
        (serverField[field] ?? []).map((key) => errors[key]).find(Boolean);

    const validateStep = (at: number): boolean => {
        const found: Record<string, string> = {};
        if (at === 0) {
            if (!manage && form.work === NEW_WORK && !form.type.trim())
                found.type = 'Choose the service or inspection type.';
            if (!cancelling && !form.provider.trim())
                found.provider = 'Name the service provider.';
        }
        if (at === 1) {
            if (!cancelling) {
                if (form.end <= form.start)
                    found.end = 'The appointment end must be after its start.';
                else if (
                    overrun && appointment?.end
                        ? form.end <= toDatetimeLocal(appointment.end)
                        : false
                )
                    found.end = 'An overrun ends after the planned end.';
                else if (!overrun && form.start < today)
                    found.start = 'Choose a future appointment time.';
                else if (form.unavailable) {
                    const clash = conflictWith(summary, form.start, form.end, {
                        kind: 'unavailable',
                        id: heldPeriodId(summary, workOrderId),
                    });
                    if (clash) found.start = clash;
                }
            }
            if (!manage && !form.notes.trim())
                found.notes = 'Record the purpose or reason for scheduling.';
            if (
                manage &&
                form.operation !== 'plan' &&
                !form.changeReason.trim()
            )
                found.changeReason = cancelling
                    ? 'Record why the appointment is cancelled.'
                    : 'Record why the appointment ran over.';
        }
        if (at === 2 && manage && !form.impactReviewed)
            found.impactReviewed =
                'Confirm that the booking impact has been reviewed.';
        setLocalErrors(found);
        return Object.keys(found).length === 0;
    };

    const submit = async () => {
        if (!command.uncertain) {
            for (const at of manage ? [0, 1, 2] : [0, 1]) {
                if (!validateStep(at)) {
                    setStep(at);
                    return;
                }
            }
        }
        const body = new FormData();
        if (manage) body.append('operation', form.operation);
        if (form.changeReason.trim())
            body.append('change_reason', form.changeReason.trim());
        if (form.work !== NEW_WORK) {
            body.append('work_order_id', form.work);
            if (workVersions[Number(form.work)] !== undefined)
                body.append(
                    'expected_version',
                    String(workVersions[Number(form.work)]),
                );
        } else {
            body.append('title', form.type.trim());
            // New work is reported from the due date it plans for, so later
            // plans for the same due date reuse it instead of duplicating it.
            if (source) {
                body.append('source_type', source.type);
                body.append('source_id', String(source.id));
            }
        }
        if (!cancelling) {
            body.append('provider_name', form.provider.trim());
            body.append('starts_local', form.start);
            body.append('ends_local', form.end);
            body.append('unavailable', form.unavailable ? '1' : '0');
            if (!overrun && form.reference.trim())
                body.append('provider_reference', form.reference.trim());
            body.append(
                'notes',
                manage
                    ? 'Appointment rescheduled on the vehicle calendar.'
                    : form.notes.trim(),
            );
            files.forEach((file) => body.append('files[]', file));
        }
        const result = await command.submit(
            `/fleet-assets/vehicles/${vehicle.id}/appointments`,
            body,
        );
        if (!result) return;
        setSavedUndo(isJsonObject(result.undo) ? result.undo : null);
        setSavedText(
            typeof result.message === 'string'
                ? result.message
                : 'Appointment saved in Maintenance and on the vehicle calendar.',
        );
        onSaved();
    };
    const [savedUndo, setSavedUndo] = useState<Record<string, unknown> | null>(
        null,
    );
    const close = () => {
        onClose();
        if (savedUndo) {
            const undo = savedUndo;
            toast.success('Appointment changed', {
                duration: 10000,
                action: {
                    label: 'Undo',
                    onClick: () => {
                        sendVehicleRecord(
                            `/fleet-assets/vehicles/${vehicle.id}/appointments/undo`,
                            undo,
                        )
                            .then(() => {
                                onSaved();
                                toast.success(
                                    'Previous internal appointment restored',
                                );
                            })
                            .catch((error: Error) =>
                                toast.error(error.message),
                            );
                    },
                },
            });
        }
    };

    const steps = [
        manage
            ? {
                  key: 'provider',
                  label: 'Provider & owner',
                  blurb: 'Who carries out the work',
                  icon: UserRound,
              }
            : {
                  key: 'work',
                  label: 'Maintenance work',
                  blurb: 'Create a work order or reuse an open record',
                  icon: Wrench,
              },
        {
            key: 'appointment',
            label: 'Appointment & vehicle use',
            blurb: 'Pacific/Auckland',
            icon: CalendarDays,
        },
        ...(manage
            ? [
                  {
                      key: 'impact',
                      label: 'Booking impact',
                      blurb: 'Bookings affected by this change',
                      icon: ShieldCheck,
                  },
              ]
            : []),
        {
            key: 'review',
            label: 'Review',
            blurb: 'Confirm the resulting record',
            icon: FileCheck2,
        },
    ];
    const reviewStep = steps.length - 1;
    const recordLabel =
        form.work === NEW_WORK
            ? 'Create new work order'
            : `${existing?.reference ?? `Work #${form.work}`} · ${existing?.title ?? ''}`;
    const operationLabel =
        OPERATIONS.find((option) => option.value === form.operation)?.label ??
        'Plan / reschedule';

    return (
        <WorkspaceWizard
            title={
                manage
                    ? 'Manage appointment'
                    : presetType || workOrderId
                      ? 'Plan appointment'
                      : 'Schedule service or inspection'
            }
            description={[vehicle.name, vehicle.asset_tag, vehicle.site?.name]
                .filter(Boolean)
                .join(' · ')}
            railIcon={Wrench}
            railSub={
                [vehicle.registration_number, vehicle.site?.name]
                    .filter(Boolean)
                    .join(' · ') || vehicle.name
            }
            steps={steps}
            step={step}
            setStep={setStep}
            pct={Math.round(
                ([
                    manage || form.work !== NEW_WORK || !!form.type.trim(),
                    cancelling || !!form.provider.trim(),
                    cancelling || (!!form.start && !!form.end),
                    manage
                        ? form.operation === 'plan' ||
                          !!form.changeReason.trim()
                        : !!form.notes.trim(),
                ].filter(Boolean).length /
                    4) *
                    100,
            )}
            context={{
                name: vehicle.name,
                detail: [
                    vehicle.asset_tag,
                    vehicle.registration_number,
                    vehicle.site?.name,
                ]
                    .filter(Boolean)
                    .join(' · '),
            }}
            command={command}
            dirty={
                JSON.stringify(form) !== JSON.stringify(initial) ||
                files.length > 0
            }
            saved={savedText !== null}
            submitLabel={
                cancelling
                    ? 'Cancel appointment'
                    : overrun
                      ? 'Record overrun'
                      : 'Save appointment'
            }
            onValidateStep={validateStep}
            onSubmit={submit}
            onClose={close}
            onReload={() => {
                onSaved();
                onClose();
            }}
            errorKey={JSON.stringify(errors)}
            success={
                <WizardSuccess
                    title={
                        cancelling
                            ? 'Appointment cancelled'
                            : 'Appointment saved'
                    }
                    blurb={
                        <>
                            {savedText ?? ''}
                            {savedUndo && (
                                <Button
                                    className="mt-4"
                                    variant="outline"
                                    onClick={async () => {
                                        try {
                                            await sendVehicleRecord(
                                                `/fleet-assets/vehicles/${vehicle.id}/appointments/undo`,
                                                savedUndo,
                                            );
                                            setSavedUndo(null);
                                            setSavedText(
                                                'Previous internal appointment restored. Provider confirmation remains a separate action.',
                                            );
                                            onSaved();
                                        } catch (error) {
                                            setSavedText(
                                                error instanceof Error
                                                    ? error.message
                                                    : 'Reload to review the latest appointment.',
                                            );
                                        }
                                    }}
                                >
                                    Undo appointment change
                                </Button>
                            )}
                        </>
                    }
                    onClose={close}
                />
            }
        >
            {step === 0 && !manage && (
                <div className="space-y-5">
                    <p className="text-subtle">
                        Create a work order or reuse an open record. An existing
                        record keeps its source, evidence and history.
                    </p>
                    <WizardField
                        id="work"
                        label="Maintenance record"
                        error={error('work')}
                    >
                        <Select
                            value={form.work}
                            onValueChange={(value) => update('work', value)}
                        >
                            <SelectTrigger
                                {...fieldProps('work', error('work'))}
                            >
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                {summary.can.report_work && (
                                    <SelectItem value={NEW_WORK}>
                                        Create new work order · owned by
                                        Maintenance
                                    </SelectItem>
                                )}
                                {summary.open_work.map((work) => (
                                    <SelectItem
                                        key={work.id}
                                        value={String(work.id)}
                                    >
                                        {work.reference ?? `Work #${work.id}`} ·{' '}
                                        {work.title ?? 'Maintenance work'} ·{' '}
                                        {work.status.replace(/_/g, ' ')}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </WizardField>
                    <div className="vehicle-wizard-fields">
                        <WizardField
                            id="type"
                            label="Service or inspection type"
                            optional={form.work !== NEW_WORK}
                            error={error('type')}
                            hint="Used as the title for new work; an existing work order keeps its title."
                        >
                            <CataloguePicker
                                id="type"
                                kind="service_type"
                                label="Service or inspection type"
                                value={form.type}
                                onChange={(value) => update('type', value)}
                                invalid={!!error('type')}
                                disabled={form.work !== NEW_WORK}
                            />
                        </WizardField>
                        <WizardField
                            id="provider"
                            label="Service provider"
                            error={error('provider')}
                        >
                            <Input
                                {...fieldProps('provider', error('provider'))}
                                maxLength={255}
                                value={form.provider}
                                onChange={(event) =>
                                    update('provider', event.target.value)
                                }
                            />
                        </WizardField>
                    </div>
                    <WizardField id="owner" label="Responsible person or role">
                        <Input
                            id="owner"
                            readOnly
                            value={
                                form.work === NEW_WORK
                                    ? "The site's Maintenance coordinator"
                                    : 'The work order keeps its current owner'
                            }
                        />
                    </WizardField>
                </div>
            )}
            {step === 0 && manage && (
                <div className="space-y-5">
                    <p className="text-subtle">
                        {recordLabel} · {vehicle.asset_tag ?? vehicle.name}
                    </p>
                    <WizardField
                        id="provider"
                        label="Service provider"
                        optional={cancelling}
                        error={error('provider')}
                    >
                        <Input
                            {...fieldProps('provider', error('provider'))}
                            maxLength={255}
                            value={form.provider}
                            disabled={cancelling}
                            onChange={(event) =>
                                update('provider', event.target.value)
                            }
                        />
                    </WizardField>
                    <WizardField id="owner" label="Responsible person or role">
                        <Input
                            id="owner"
                            readOnly
                            value="The work order keeps its current owner"
                        />
                    </WizardField>
                </div>
            )}
            {step === 1 && (
                <div className="space-y-5">
                    <p className="text-subtle">
                        {manage
                            ? 'An internal plan is separate from provider confirmation. Actual unavailable periods are projected onto the calendar.'
                            : 'Pacific/Auckland. A calendar appointment is an internal plan until provider confirmation is recorded.'}
                    </p>
                    {manage && (
                        <WizardField id="operation" label="Appointment action">
                            <Select
                                value={form.operation}
                                onValueChange={(value) =>
                                    update('operation', value as Operation)
                                }
                            >
                                <SelectTrigger {...fieldProps('operation')}>
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {OPERATIONS.map((option) => (
                                        <SelectItem
                                            key={option.value}
                                            value={option.value}
                                        >
                                            {option.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </WizardField>
                    )}
                    {!cancelling && (
                        <>
                            {overrun ? (
                                <WizardField
                                    id="start"
                                    label="Appointment start"
                                >
                                    <Input
                                        id="start"
                                        readOnly
                                        value={localLabel(form.start)}
                                    />
                                </WizardField>
                            ) : (
                                <DateTimeField
                                    id="start"
                                    label="Appointment start"
                                    value={form.start}
                                    onChange={(value) => update('start', value)}
                                    error={error('start')}
                                />
                            )}
                            <DateTimeField
                                id="end"
                                label={
                                    overrun
                                        ? 'New appointment end'
                                        : 'Appointment end'
                                }
                                value={form.end}
                                onChange={(value) => update('end', value)}
                                error={error('end')}
                            />
                            <label className="flex items-start gap-3 text-sm">
                                <Checkbox
                                    id="unavailable"
                                    checked={form.unavailable}
                                    onCheckedChange={(value) =>
                                        update('unavailable', value === true)
                                    }
                                />
                                <span>
                                    Vehicle unavailable during this appointment
                                </span>
                            </label>
                            {!overrun && (
                                <WizardField
                                    id="reference"
                                    label="Provider confirmation reference"
                                    optional
                                    hint={
                                        manage
                                            ? 'Leave blank until confirmation is recorded.'
                                            : undefined
                                    }
                                    error={error('reference')}
                                >
                                    <Input
                                        {...fieldProps(
                                            'reference',
                                            error('reference'),
                                        )}
                                        maxLength={120}
                                        value={form.reference}
                                        onChange={(event) =>
                                            update(
                                                'reference',
                                                event.target.value,
                                            )
                                        }
                                    />
                                </WizardField>
                            )}
                        </>
                    )}
                    {manage ? (
                        <WizardField
                            id="changeReason"
                            label="Reason for change"
                            optional={form.operation === 'plan'}
                            error={error('changeReason')}
                        >
                            <Textarea
                                {...fieldProps(
                                    'changeReason',
                                    error('changeReason'),
                                )}
                                rows={3}
                                maxLength={2000}
                                value={form.changeReason}
                                onChange={(event) =>
                                    update('changeReason', event.target.value)
                                }
                            />
                        </WizardField>
                    ) : (
                        <>
                            <WizardField
                                id="notes"
                                label="Purpose / reason for scheduling"
                                error={error('notes')}
                            >
                                <Textarea
                                    {...fieldProps('notes', error('notes'))}
                                    rows={3}
                                    maxLength={5000}
                                    value={form.notes}
                                    onChange={(event) =>
                                        update('notes', event.target.value)
                                    }
                                />
                            </WizardField>
                            <StagedFilesField
                                label="Appointment evidence"
                                files={files}
                                onChange={setFiles}
                                error={error('files')}
                            />
                        </>
                    )}
                </div>
            )}
            {step === 2 && manage && (
                <div className="space-y-5">
                    <p className="text-subtle">
                        Busy-only bookings stay private. Any overlap requires
                        the coordinator to arrange an alternative.
                    </p>
                    <label className="flex items-start gap-3 text-sm">
                        <Checkbox
                            id="impactReviewed"
                            checked={form.impactReviewed}
                            aria-invalid={!!error('impactReviewed')}
                            onCheckedChange={(value) =>
                                update('impactReviewed', value === true)
                            }
                        />
                        <span>Booking impact reviewed</span>
                    </label>
                    {error('impactReviewed') && (
                        <p
                            role="alert"
                            className="text-sm text-status-critical"
                        >
                            {error('impactReviewed')}
                        </p>
                    )}
                </div>
            )}
            {step === reviewStep && (
                <div className="grid gap-4">
                    <ReviewCard
                        icon={manage ? UserRound : Wrench}
                        title={manage ? 'Provider & owner' : 'Maintenance work'}
                        onEdit={() => setStep(0)}
                    >
                        <ReviewRow
                            label="Maintenance record"
                            value={recordLabel}
                        />
                        {!manage && form.work === NEW_WORK && (
                            <ReviewRow
                                label="Service or inspection type"
                                value={form.type || undefined}
                            />
                        )}
                        {!cancelling && (
                            <ReviewRow
                                label="Service provider"
                                value={form.provider || undefined}
                            />
                        )}
                    </ReviewCard>
                    <ReviewCard
                        icon={CalendarDays}
                        title="Appointment & vehicle use"
                        onEdit={() => setStep(1)}
                    >
                        {manage && (
                            <ReviewRow
                                label="Appointment action"
                                value={operationLabel}
                            />
                        )}
                        {!cancelling && (
                            <>
                                <ReviewRow
                                    label="Appointment"
                                    value={`${localLabel(form.start)} – ${localLabel(form.end)}`}
                                />
                                <ReviewRow
                                    label="Vehicle unavailable during this appointment"
                                    value={form.unavailable ? 'Yes' : 'No'}
                                />
                            </>
                        )}
                        {!cancelling && !overrun && (
                            <ReviewRow
                                label="Provider confirmation reference"
                                value={form.reference || 'Not recorded yet'}
                            />
                        )}
                        {manage ? (
                            <ReviewRow
                                label="Reason for change"
                                value={form.changeReason || 'Not recorded'}
                            />
                        ) : (
                            <>
                                <ReviewRow
                                    label="Purpose / reason"
                                    value={form.notes || undefined}
                                />
                                <ReviewRow
                                    label="Appointment evidence"
                                    value={
                                        files
                                            .map((file) => file.name)
                                            .join(', ') || 'No files attached'
                                    }
                                />
                            </>
                        )}
                    </ReviewCard>
                    {manage && (
                        <ReviewCard
                            icon={ShieldCheck}
                            title="Booking impact"
                            onEdit={() => setStep(2)}
                        >
                            <ReviewRow
                                label="Booking impact reviewed"
                                value={form.impactReviewed ? 'Yes' : 'No'}
                            />
                        </ReviewCard>
                    )}
                    <StudioNotice title="What this changes">
                        {cancelling
                            ? 'The provider appointment is cancelled on the Maintenance work order and removed from this calendar. Any unavailable period it held is released. The work order stays open.'
                            : overrun
                              ? 'The appointment end moves later on the work order and this calendar.'
                              : 'The appointment is saved on the Maintenance work order and shown on this calendar.'}
                        {!cancelling &&
                            (form.unavailable
                                ? ' The vehicle is unavailable for bookings during the appointment.'
                                : ' Bookings are not blocked by this appointment.')}{' '}
                        Completing the service and releasing any restriction
                        stay separate steps in Maintenance.
                    </StudioNotice>
                </div>
            )}
        </WorkspaceWizard>
    );
}

/** The unavailable period a work order already holds, so a reschedule doesn't clash with itself. */
function heldPeriodId(
    summary: VehicleCalendarSummary,
    workOrderId?: number,
): number {
    if (!workOrderId) return 0;
    const held = summary.bookings.find(
        (row) =>
            row.kind === 'unavailable' &&
            row.status === 'active' &&
            row.work_order_id === workOrderId,
    );
    return held?.id ?? 0;
}
