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
import type { SharedData } from '@/types';
import { usePage } from '@inertiajs/react';
import {
    CalendarClock,
    CalendarDays,
    FileCheck2,
    Lock,
    ShieldCheck,
    UserRound,
} from 'lucide-react';
import { useState } from 'react';
import type {
    BookingRow,
    CalendarDriver,
    UnavailableRow,
    VehicleCalendarSummary,
} from './calendar-types';
import { uploadSummary, useEvidenceUpload } from './evidence-upload';
import { isJsonObject, useVehicleRecordCommand } from './record-command';
import type { VehicleProfile } from './types';
import {
    fieldProps,
    StagedFilesField,
    StudioNotice,
    WizardField,
    WizardSuccess,
    WorkspaceWizard,
} from './wizard-kit';

export type BookingWizardMode =
    | { kind: 'request'; startLocal?: string }
    | { kind: 'change'; row: BookingRow }
    | { kind: 'block'; startLocal?: string }
    | { kind: 'change-block'; row: UnavailableRow };

const LIVE_BOOKING = ['pending', 'approved', 'checked_out'];

/** Wall-clock arithmetic on Auckland "YYYY-MM-DDTHH:mm" strings. */
export function addLocalMinutes(local: string, minutes: number): string {
    const date = new Date(`${local}:00Z`);
    date.setUTCMinutes(date.getUTCMinutes() + minutes);
    return date.toISOString().slice(0, 16);
}

export function localLabel(local: string): string {
    if (!/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/.test(local)) return local || '—';
    return new Intl.DateTimeFormat('en-NZ', {
        timeZone: 'UTC',
        weekday: 'short',
        day: 'numeric',
        month: 'short',
        hour: 'numeric',
        minute: '2-digit',
    }).format(new Date(`${local}:00Z`));
}

/** The next whole hour from now, or 9 am on a later chosen day. */
export function defaultBookingStart(day?: string): string {
    const now = toDatetimeLocal(new Date().toISOString());
    if (!day || day <= now.slice(0, 10)) {
        const nextHour = addLocalMinutes(`${now.slice(0, 13)}:00`, 60);
        return nextHour;
    }
    return `${day}T09:00`;
}

function minutesBetween(start: string, end: string): number {
    return (
        (new Date(`${end}:00Z`).getTime() -
            new Date(`${start}:00Z`).getTime()) /
        60000
    );
}

/** A clash with another live booking or an unavailable period, in wall time. */
export function conflictWith(
    summary: VehicleCalendarSummary,
    start: string,
    end: string,
    ignore?: { kind: 'booking' | 'unavailable'; id: number },
): string {
    const clash = summary.bookings.find((row) => {
        if (ignore && row.kind === ignore.kind && row.id === ignore.id)
            return false;
        const live =
            row.kind === 'booking'
                ? LIVE_BOOKING.includes(row.status)
                : row.status === 'active';
        if (!live) return false;
        return (
            start < toDatetimeLocal(row.ends_at) &&
            end > toDatetimeLocal(row.starts_at)
        );
    });
    if (!clash) return '';
    return clash.kind === 'booking'
        ? 'Conflicts with another booking or unavailable period.'
        : 'Conflicts with an unavailable period for this vehicle.';
}

function driverLabel(driver: CalendarDriver): string {
    if (!driver.licence_status) return `${driver.name} · licence not recorded`;
    return driver.licence_expires_at
        ? `${driver.name} · licence ${driver.licence_status}, to ${driver.licence_expires_at}`
        : `${driver.name} · licence ${driver.licence_status}`;
}

export function BookingWizard({
    vehicle,
    summary,
    mode,
    onClose,
    onSaved,
}: {
    vehicle: VehicleProfile;
    summary: VehicleCalendarSummary;
    mode: BookingWizardMode;
    onClose: () => void;
    onSaved: () => void;
}) {
    const auth = usePage<SharedData>().props.auth;
    const block = mode.kind === 'block' || mode.kind === 'change-block';
    const changing = mode.kind === 'change' || mode.kind === 'change-block';
    const booking = mode.kind === 'change' ? mode.row : null;
    const period = mode.kind === 'change-block' ? mode.row : null;
    const [initial] = useState(() => {
        const start =
            booking || period
                ? toDatetimeLocal((booking ?? period)!.starts_at)
                : ((mode.kind === 'request' || mode.kind === 'block'
                      ? mode.startLocal
                      : undefined) ?? defaultBookingStart());
        const end =
            booking || period
                ? toDatetimeLocal((booking ?? period)!.ends_at)
                : addLocalMinutes(start, 60);
        return {
            start,
            end,
            driver_user_id:
                booking?.driver?.id ?? (auth.user?.id as number | undefined) ?? null,
            purpose: booking?.purpose ?? period?.purpose ?? '',
            pickup: booking?.pickup_arrangement ?? '',
            reason: '',
            approval_route: 'required' as 'required' | 'not_required',
            exemption_reason: '',
            ready: false,
        };
    });
    const [form, setForm] = useState(initial);
    const [files, setFiles] = useState<File[]>([]);
    const [step, setStep] = useState(0);
    const [localErrors, setLocalErrors] = useState<Record<string, string>>({});
    const [conflict, setConflict] = useState('');
    const [savedText, setSavedText] = useState<string | null>(null);
    const command = useVehicleRecordCommand(isJsonObject);
    const evidence = useEvidenceUpload(vehicle.id);
    const errors: Record<string, string> = { ...command.errors, ...localErrors };
    // Server time errors land on the times step, whatever key they use.
    if (errors.asset_id) errors.starts_local = errors.asset_id;
    const drivers = summary.drivers;
    const today = toDatetimeLocal(new Date().toISOString());
    const authority = summary.can.authority;

    const stepList = block
        ? [
              {
                  key: 'times',
                  label: 'Vehicle & times',
                  blurb: 'When the vehicle is unavailable',
                  icon: CalendarDays,
              },
              {
                  key: 'reason',
                  label: 'Block reason',
                  blurb: 'Why it is unavailable',
                  icon: Lock,
              },
              {
                  key: 'review',
                  label: 'Review',
                  blurb: 'Confirm the resulting record',
                  icon: FileCheck2,
              },
          ]
        : [
              {
                  key: 'times',
                  label: 'Vehicle & times',
                  blurb: 'Pickup and return',
                  icon: CalendarDays,
              },
              {
                  key: 'people',
                  label: 'People & purpose',
                  blurb: 'Only operational journey details',
                  icon: UserRound,
              },
              ...(changing
                  ? []
                  : [
                        {
                            key: 'approval',
                            label: 'Approval & evidence',
                            blurb: 'Approval route and authority',
                            icon: ShieldCheck,
                        },
                    ]),
              {
                  key: 'review',
                  label: 'Review',
                  blurb: 'Confirm the resulting record',
                  icon: FileCheck2,
              },
          ];
    const reviewStep = stepList.length - 1;
    const stepKey = stepList[step]?.key;

    const update = <K extends keyof typeof form>(
        key: K,
        value: (typeof form)[K],
    ) => {
        setForm((old) => ({ ...old, [key]: value }));
        setLocalErrors((old) => {
            const next = { ...old };
            delete next[key as string];
            if (key === 'start' || key === 'end') {
                delete next.starts_local;
                delete next.ends_local;
            }
            return next;
        });
        if (key === 'start' || key === 'end') {
            setConflict('');
            command.clearError('starts_local');
            command.clearError('ends_local');
            command.clearError('asset_id');
        }
        command.clearError(key as string);
    };

    const ignore = booking
        ? { kind: 'booking' as const, id: booking.id }
        : period
          ? { kind: 'unavailable' as const, id: period.id }
          : undefined;
    const alternatives = (() => {
        if (!conflict) return [];
        const minutes = Math.max(30, minutesBetween(form.start, form.end));
        return [1, 2, 3]
            .map((days) => {
                const start = addLocalMinutes(form.start, days * 24 * 60);
                return { start, end: addLocalMinutes(start, minutes) };
            })
            .filter(
                (slot) => !conflictWith(summary, slot.start, slot.end, ignore),
            );
    })();

    const validateStep = (at: number): boolean => {
        const key = stepList[at]?.key;
        const found: Record<string, string> = {};
        if (key === 'times') {
            if (!/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/.test(form.start))
                found.starts_local = block
                    ? 'Choose when the vehicle becomes unavailable.'
                    : 'Choose the pickup date and time.';
            if (!/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/.test(form.end))
                found.ends_local = block
                    ? 'Choose when the vehicle is available again.'
                    : 'Choose the return date and time.';
            if (!found.starts_local && !found.ends_local) {
                if (form.end <= form.start)
                    found.ends_local = 'The end must be after the start.';
                else if (
                    form.start < today &&
                    (!changing || form.start !== initial.start)
                )
                    found.starts_local =
                        'Choose a current or future pickup / block start.';
            }
            if (Object.keys(found).length === 0) {
                const clash = conflictWith(summary, form.start, form.end, ignore);
                if (clash) {
                    setConflict(clash);
                    setLocalErrors({ starts_local: clash });
                    return false;
                }
            }
        }
        if (key === 'people') {
            if (!form.purpose.trim())
                found.purpose = 'Describe the purpose of the journey.';
            if (changing && !form.reason.trim())
                found.reason = 'Record the reason for this change.';
        }
        if (key === 'reason') {
            if (!form.purpose.trim())
                found.purpose = 'Record why the vehicle is unavailable.';
            if (changing && !form.reason.trim())
                found.change_reason = 'Record the reason for this change.';
        }
        if (key === 'approval' && form.approval_route === 'not_required') {
            if (!form.exemption_reason.trim() && files.length === 0)
                found.approval_not_required_reason =
                    'Add a reason or upload evidence for approval not required.';
            if (authority && !form.ready)
                found.readiness_acknowledged =
                    'Review readiness and driver authority for the approval-not-required path.';
        }
        setLocalErrors(found);
        return Object.keys(found).length === 0;
    };

    const submit = async () => {
        if (!command.uncertain) {
            for (let at = 0; at < reviewStep; at++) {
                if (!validateStep(at)) {
                    setStep(at);
                    return;
                }
            }
        }
        if (mode.kind === 'block') {
            const result = await command.submit(
                `/fleet-assets/vehicles/${vehicle.id}/unavailable-periods`,
                {
                    starts_local: form.start,
                    ends_local: form.end,
                    reason: form.purpose.trim(),
                },
            );
            if (!result) return;
            setSavedText(
                'The vehicle shows as unavailable for this period on its calendar. No safety restriction was created or cleared.',
            );
        } else if (mode.kind === 'change-block') {
            const result = await command.submit(
                `/fleet-assets/vehicles/${vehicle.id}/unavailable-periods/${mode.row.id}`,
                {
                    starts_local: form.start,
                    ends_local: form.end,
                    reason: form.purpose.trim(),
                    change_reason: form.reason.trim(),
                    expected_version: mode.row.lock_version,
                },
                { method: 'PUT' },
            );
            if (!result) return;
            setSavedText('The unavailable period is changed; its history is kept.');
        } else if (mode.kind === 'change') {
            const result = await command.submit(
                `/fleet-assets/bookings/${mode.row.id}`,
                {
                    expected_version: mode.row.lock_version,
                    starts_local: form.start,
                    ends_local: form.end,
                    purpose: form.purpose.trim(),
                    driver_user_id: form.driver_user_id,
                    pickup_arrangement: form.pickup.trim() || null,
                    destination: mode.row.destination,
                    passengers: mode.row.passengers,
                    notes: mode.row.notes,
                    reason: form.reason.trim(),
                },
                { method: 'PUT' },
            );
            if (!result) return;
            setSavedText(
                typeof result.message === 'string'
                    ? result.message
                    : 'Booking changed.',
            );
        } else {
            const result = await command.submit('/fleet-assets/bookings', {
                asset_id: vehicle.id,
                starts_local: form.start,
                ends_local: form.end,
                purpose: form.purpose.trim(),
                driver_user_id: form.driver_user_id,
                pickup_arrangement: form.pickup.trim() || null,
                approval_route: form.approval_route,
                approval_not_required_reason:
                    form.approval_route === 'not_required'
                        ? form.exemption_reason.trim() || null
                        : null,
                approval_not_required_evidence:
                    form.approval_route === 'not_required' && files.length
                        ? files
                              .map((file) => file.name)
                              .join(', ')
                              .slice(0, 250)
                        : null,
                readiness_acknowledged: form.ready,
            });
            if (!result) return;
            const created = isJsonObject(result.booking)
                ? Number(result.booking.id)
                : 0;
            let outcome = '';
            if (files.length && created) {
                const upload = await evidence.upload(files, {
                    category: 'Booking approval evidence',
                    reason: 'Approval evidence for the booking request',
                    sourceType: 'booking',
                    sourceId: created,
                });
                outcome = uploadSummary(upload, files.length);
            }
            setSavedText(
                `${typeof result.message === 'string' ? result.message : 'Booking request submitted.'}${outcome}`,
            );
        }
        onSaved();
    };

    const note = block
        ? 'This period marks the vehicle unavailable on the calendar. It does not create or clear a safety restriction.'
        : changing
          ? 'A confirmed booking whose time or driver changes returns to pending approval, unless approval is not required and readiness still passes. The change and its reason are kept in the booking history.'
          : summary.use_problem
            ? `${summary.use_problem} This request stays pending until readiness is resolved.`
            : authority
              ? 'Approval required → coordinator review. Approval not required → confirmed only when readiness and conflict checks pass. Keys, checkout and return are always recorded.'
              : 'You can request the approval-not-required path with a reason or evidence. A coordinator must verify your authority before confirmation.';
    const driverName =
        drivers.find((driver) => driver.id === form.driver_user_id)?.name ??
        booking?.driver?.name ??
        auth.user?.name ??
        'Not recorded';
    const requesterName = booking?.requester?.name ?? auth.user?.name ?? '—';
    const title =
        mode.kind === 'block'
            ? 'Add unavailable period'
            : mode.kind === 'change-block'
              ? 'Change unavailable period'
              : mode.kind === 'change'
                ? 'Change booking'
                : 'Request vehicle booking';
    const verb =
        mode.kind === 'block'
            ? 'Record block'
            : changing
              ? 'Save changes'
              : 'Save booking request';
    const requiredDone = [
        !!form.start,
        !!form.end,
        !!form.purpose.trim(),
        !changing || !!form.reason.trim(),
    ];

    return (
        <WorkspaceWizard
            title={title}
            description={[
                vehicle.name,
                vehicle.asset_tag,
                vehicle.site?.name,
                'Pacific/Auckland',
            ]
                .filter(Boolean)
                .join(' · ')}
            railIcon={CalendarClock}
            railSub={
                [vehicle.registration_number, vehicle.site?.name]
                    .filter(Boolean)
                    .join(' · ') || vehicle.name
            }
            steps={stepList}
            step={step}
            setStep={setStep}
            pct={Math.round(
                (requiredDone.filter(Boolean).length / requiredDone.length) *
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
            command={{
                ...command,
                processing: command.processing || evidence.command.processing,
                locked: command.locked || evidence.command.processing,
            }}
            dirty={
                JSON.stringify(form) !== JSON.stringify(initial) ||
                files.length > 0
            }
            saved={savedText !== null}
            submitLabel={verb}
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
                    title={
                        mode.kind === 'block'
                            ? 'Unavailable period recorded'
                            : changing
                              ? 'Changes saved'
                              : 'Booking saved'
                    }
                    blurb={savedText ?? ''}
                    onClose={onClose}
                />
            }
        >
            {conflict && alternatives.length > 0 && stepKey === 'times' && (
                <div
                    className="mb-4 flex flex-wrap gap-2"
                    aria-label="Alternative booking times"
                >
                    {alternatives.map((slot) => (
                        <Button
                            key={slot.start}
                            variant="outline"
                            onClick={() => {
                                setForm((old) => ({
                                    ...old,
                                    start: slot.start,
                                    end: slot.end,
                                }));
                                setConflict('');
                                setLocalErrors({});
                            }}
                        >
                            Try {localLabel(slot.start)}
                        </Button>
                    ))}
                </div>
            )}
            {stepKey === 'times' && (
                <div className="space-y-5">
                    <p className="text-subtle">
                        {[vehicle.name, vehicle.asset_tag, vehicle.site?.name]
                            .filter(Boolean)
                            .join(' · ')}{' '}
                        · Pacific/Auckland
                    </p>
                    <DateTimeField
                        id="starts_local"
                        label={block ? 'Block start' : 'Pickup / block start'}
                        value={form.start}
                        onChange={(value) => update('start', value)}
                        error={errors.starts_local}
                    />
                    <DateTimeField
                        id="ends_local"
                        label={block ? 'Block end' : 'Return / block end'}
                        value={form.end}
                        onChange={(value) => update('end', value)}
                        error={errors.ends_local}
                    />
                </div>
            )}
            {stepKey === 'people' && (
                <div className="space-y-5">
                    <p className="text-subtle">
                        Only operational journey details; no passenger health
                        information.
                    </p>
                    <div className="vehicle-wizard-fields">
                        <WizardField id="requester" label="Requester">
                            <Input id="requester" value={requesterName} readOnly />
                        </WizardField>
                        <WizardField
                            id="driver_user_id"
                            label="Driver"
                            error={errors.driver_user_id}
                            hint="Only current staff at this vehicle's site. Readiness checks the driver's licence."
                        >
                            <Select
                                value={
                                    form.driver_user_id
                                        ? String(form.driver_user_id)
                                        : undefined
                                }
                                onValueChange={(value) =>
                                    update('driver_user_id', Number(value))
                                }
                            >
                                <SelectTrigger
                                    {...fieldProps(
                                        'driver_user_id',
                                        errors.driver_user_id,
                                    )}
                                >
                                    <SelectValue placeholder="Choose the driver" />
                                </SelectTrigger>
                                <SelectContent>
                                    {drivers.map((driver) => {
                                        const expired =
                                            !!driver.licence_expires_at &&
                                            driver.licence_expires_at <
                                                today.slice(0, 10);
                                        return (
                                            <SelectItem
                                                key={driver.id}
                                                value={String(driver.id)}
                                                disabled={expired}
                                            >
                                                {driverLabel(driver)}
                                            </SelectItem>
                                        );
                                    })}
                                </SelectContent>
                            </Select>
                        </WizardField>
                    </div>
                    <WizardField id="purpose" label="Purpose" error={errors.purpose}>
                        <Textarea
                            {...fieldProps('purpose', errors.purpose)}
                            rows={2}
                            maxLength={255}
                            value={form.purpose}
                            onChange={(event) =>
                                update('purpose', event.target.value)
                            }
                        />
                    </WizardField>
                    <WizardField
                        id="pickup_arrangement"
                        label="Pickup / return and keys"
                        optional
                        error={errors.pickup_arrangement}
                    >
                        <Input
                            {...fieldProps(
                                'pickup_arrangement',
                                errors.pickup_arrangement,
                            )}
                            maxLength={255}
                            value={form.pickup}
                            onChange={(event) =>
                                update('pickup', event.target.value)
                            }
                        />
                    </WizardField>
                    {changing && (
                        <WizardField
                            id="reason"
                            label="Reason for change"
                            error={errors.reason}
                        >
                            <Textarea
                                {...fieldProps('reason', errors.reason)}
                                rows={2}
                                maxLength={2000}
                                value={form.reason}
                                onChange={(event) =>
                                    update('reason', event.target.value)
                                }
                            />
                        </WizardField>
                    )}
                </div>
            )}
            {stepKey === 'reason' && (
                <div className="space-y-5">
                    <p className="text-subtle">
                        The unavailable interval appears on the calendar.
                    </p>
                    <WizardField
                        id="purpose"
                        label="Reason for unavailable period"
                        error={errors.purpose ?? errors.reason}
                    >
                        <Textarea
                            {...fieldProps('purpose', errors.purpose ?? errors.reason)}
                            rows={3}
                            maxLength={2000}
                            value={form.purpose}
                            onChange={(event) =>
                                update('purpose', event.target.value)
                            }
                        />
                    </WizardField>
                    {changing && (
                        <WizardField
                            id="change_reason"
                            label="Reason for change"
                            error={errors.change_reason}
                        >
                            <Textarea
                                {...fieldProps(
                                    'change_reason',
                                    errors.change_reason,
                                )}
                                rows={2}
                                maxLength={2000}
                                value={form.reason}
                                onChange={(event) =>
                                    update('reason', event.target.value)
                                }
                            />
                        </WizardField>
                    )}
                </div>
            )}
            {stepKey === 'approval' && (
                <div className="space-y-5">
                    <p className="text-subtle">
                        An authorised coordinator can record that approval is
                        not required. Readiness and conflict checks still apply.
                    </p>
                    <fieldset className="vehicle-approval-choice">
                        <legend>Booking approval</legend>
                        {(
                            [
                                [
                                    'required',
                                    'Approval required',
                                    'Coordinator reviews before confirmation',
                                ],
                                [
                                    'not_required',
                                    'Approval not required',
                                    'Record the authority, reason or supporting evidence',
                                ],
                            ] as const
                        ).map(([value, label, hint]) => (
                            <label
                                key={value}
                                className={
                                    form.approval_route === value
                                        ? 'selected'
                                        : ''
                                }
                            >
                                <input
                                    type="radio"
                                    name="approval_route"
                                    checked={form.approval_route === value}
                                    onChange={() =>
                                        update('approval_route', value)
                                    }
                                />
                                <span>
                                    <strong>{label}</strong>
                                    <small>{hint}</small>
                                </span>
                            </label>
                        ))}
                    </fieldset>
                    {form.approval_route === 'not_required' && (
                        <>
                            <WizardField
                                id="approval_not_required_reason"
                                label="Reason / policy authority for approval not required"
                                optional
                                hint="Required when no evidence is attached for the approval-not-required path."
                                error={errors.approval_not_required_reason}
                            >
                                <Textarea
                                    {...fieldProps(
                                        'approval_not_required_reason',
                                        errors.approval_not_required_reason,
                                    )}
                                    rows={2}
                                    maxLength={2000}
                                    value={form.exemption_reason}
                                    onChange={(event) =>
                                        update(
                                            'exemption_reason',
                                            event.target.value,
                                        )
                                    }
                                />
                            </WizardField>
                            {authority && (
                                <div className="space-y-1">
                                    <label className="flex items-start gap-3 text-sm">
                                        <Checkbox
                                            id="readiness_acknowledged"
                                            checked={form.ready}
                                            aria-invalid={
                                                !!errors.readiness_acknowledged
                                            }
                                            onCheckedChange={(value) =>
                                                update('ready', value === true)
                                            }
                                        />
                                        <span>
                                            Readiness and driver authority
                                            reviewed for approval not required
                                        </span>
                                    </label>
                                    {errors.readiness_acknowledged && (
                                        <p
                                            role="alert"
                                            className="text-xs text-status-critical"
                                        >
                                            {errors.readiness_acknowledged}
                                        </p>
                                    )}
                                </div>
                            )}
                            <StagedFilesField
                                label="Approval evidence"
                                files={files}
                                onChange={(next) => {
                                    setFiles(next);
                                    setLocalErrors((old) => {
                                        const copy = { ...old };
                                        delete copy.approval_not_required_reason;
                                        return copy;
                                    });
                                }}
                            />
                        </>
                    )}
                </div>
            )}
            {step === reviewStep && (
                <div className="grid gap-4">
                    <ReviewCard
                        icon={CalendarDays}
                        title="Vehicle & times"
                        onEdit={() => setStep(0)}
                    >
                        <ReviewRow
                            label={block ? 'Block start' : 'Pickup'}
                            value={localLabel(form.start)}
                        />
                        <ReviewRow
                            label={block ? 'Block end' : 'Return'}
                            value={localLabel(form.end)}
                        />
                    </ReviewCard>
                    {block ? (
                        <ReviewCard
                            icon={Lock}
                            title="Block reason"
                            onEdit={() => setStep(1)}
                        >
                            <ReviewRow
                                label="Reason"
                                value={form.purpose || undefined}
                            />
                            {changing && (
                                <ReviewRow
                                    label="Reason for change"
                                    value={form.reason || undefined}
                                />
                            )}
                        </ReviewCard>
                    ) : (
                        <ReviewCard
                            icon={UserRound}
                            title="People & purpose"
                            onEdit={() => setStep(1)}
                        >
                            <ReviewRow label="Requester" value={requesterName} />
                            <ReviewRow label="Driver" value={driverName} />
                            <ReviewRow
                                label="Purpose"
                                value={form.purpose || undefined}
                            />
                            <ReviewRow
                                label="Pickup / return and keys"
                                value={form.pickup || 'Not provided'}
                            />
                            {changing && (
                                <ReviewRow
                                    label="Reason for change"
                                    value={form.reason || undefined}
                                />
                            )}
                        </ReviewCard>
                    )}
                    {!block && !changing && (
                        <ReviewCard
                            icon={ShieldCheck}
                            title="Approval & evidence"
                            onEdit={() => setStep(2)}
                        >
                            <ReviewRow
                                label="Booking approval"
                                value={
                                    form.approval_route === 'required'
                                        ? 'Approval required'
                                        : 'Approval not required'
                                }
                            />
                            {form.approval_route === 'not_required' && (
                                <>
                                    <ReviewRow
                                        label="Reason / authority"
                                        value={
                                            form.exemption_reason ||
                                            'Not provided'
                                        }
                                    />
                                    <ReviewRow
                                        label="Approval evidence"
                                        value={
                                            files
                                                .map((file) => file.name)
                                                .join(', ') ||
                                            'No files attached'
                                        }
                                    />
                                </>
                            )}
                        </ReviewCard>
                    )}
                    <StudioNotice title="What this changes">{note}</StudioNotice>
                </div>
            )}
        </WorkspaceWizard>
    );
}
