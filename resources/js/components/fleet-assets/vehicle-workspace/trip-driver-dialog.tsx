import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { ReviewCard, ReviewRow } from '@/components/wizard/shell';
import { formatDateOnly, formatDateTime, formatTime } from '@/lib/datetime';
import { FileCheck2, UserRound } from 'lucide-react';
import { useEffect, useState } from 'react';
import { PersonPicker } from './choice-picker';
import { isJsonObject, useVehicleRecordCommand } from './record-command';
import {
    driverCaption,
    driverName,
    endLabel,
    formatDistance,
    plural,
    startLabel,
    tripHistoryUrl,
} from './trip-model';
import type { TripDetail } from './trip-types';
import type { VehicleWorkspace } from './types';
import {
    fieldProps,
    WizardField,
    WizardSuccess,
    WorkspaceWizard,
} from './wizard-kit';

const STEPS = [
    {
        key: 'driver',
        label: 'Driver and trip',
        blurb: 'Who drove and the evidence',
        icon: UserRound,
    },
    {
        key: 'review',
        label: 'Review',
        blurb: 'Confirm the attribution',
        icon: FileCheck2,
    },
];

const SOURCE_LABELS = {
    booking: 'Booked driver confirmed',
    handover: 'Handover from the booked driver',
    manual: 'Confirmed without a booking',
} as const;

function tripWhen(detail: TripDetail): string {
    const trip = detail.trip;
    return `${formatDateOnly(trip.local_date)}, ${trip.started_at ? formatTime(trip.started_at) : '—'}–${trip.ended_at ? formatTime(trip.ended_at) : 'in progress'}`;
}

/**
 * Confirm who actually drove a trip (approved "Confirm driver & handover").
 * A booking or sign-in is kept as evidence and never treated as proof.
 */
export function ConfirmDriverWizard({
    workspace,
    detail,
    onClose,
    onSaved,
}: {
    workspace: VehicleWorkspace;
    detail: TripDetail;
    onClose: () => void;
    onSaved: () => void;
}) {
    const vehicle = workspace.vehicle;
    const { trip, driver, source } = detail;
    const candidates = detail.driver_candidates;
    const known = (id: number | null) =>
        id !== null && candidates.some((person) => person.id === id);
    const [initial] = useState(() => ({
        driver: known(driver.id)
            ? driver.id
            : known(driver.booked_driver_id)
              ? driver.booked_driver_id
              : null,
        reason: '',
        verified: false,
    }));
    const [form, setForm] = useState(initial);
    const [step, setStep] = useState(0);
    const [localErrors, setLocalErrors] = useState<Record<string, string>>({});
    const [saved, setSaved] = useState(false);
    const command = useVehicleRecordCommand(isJsonObject);
    const serverErrors = command.errors;
    const errors = {
        driver: localErrors.driver ?? serverErrors.driver_user_id,
        reason: localErrors.reason ?? serverErrors.reason,
        verified: localErrors.verified ?? serverErrors.verified,
    };
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
        command.clearError(key === 'driver' ? 'driver_user_id' : key);
    };
    useEffect(() => {
        if (Object.keys(serverErrors).length) setStep(0);
    }, [serverErrors]);

    const validate = (): boolean => {
        const found: Record<string, string> = {};
        if (!form.driver) found.driver = 'Choose who actually drove this trip.';
        if (!form.reason.trim())
            found.reason = 'Record the checkout or handover evidence.';
        if (!form.verified)
            found.verified =
                'Confirm that you checked who drove and any handover.';
        setLocalErrors(found);
        return Object.keys(found).length === 0;
    };
    const submit = async () => {
        if (!command.uncertain && !validate()) {
            setStep(0);
            return;
        }
        const result = await command.submit(
            tripHistoryUrl(vehicle.id, `/${trip.id}/driver`),
            {
                driver_user_id: form.driver,
                reason: form.reason.trim(),
                verified: form.verified,
                expected_version: driver.version,
            },
        );
        if (result) {
            setSaved(true);
            onSaved();
        }
    };
    const chosen = candidates.find((person) => person.id === form.driver);
    const recordedAs = !source.booking
        ? SOURCE_LABELS.manual
        : driver.booked_driver_id === null
          ? `Checked against booking ${source.booking.reference}`
          : form.driver === driver.booked_driver_id
            ? SOURCE_LABELS.booking
            : SOURCE_LABELS.handover;

    return (
        <WorkspaceWizard
            title="Confirm driver & handover"
            description={`${vehicle.name}: record who actually drove ${trip.reference}.`}
            railIcon={UserRound}
            railSub={vehicle.registration_number ?? vehicle.asset_tag ?? ''}
            steps={STEPS}
            step={step}
            setStep={setStep}
            pct={Math.round(
                ([!!form.driver, !!form.reason.trim(), form.verified].filter(
                    Boolean,
                ).length /
                    3) *
                    100,
            )}
            context={{
                name: vehicle.name,
                detail: `${trip.reference} · ${tripWhen(detail)}`,
            }}
            command={command}
            dirty={JSON.stringify(form) !== JSON.stringify(initial)}
            saved={saved}
            submitLabel="Save driver evidence"
            onValidateStep={(at) => (at === 0 ? validate() : true)}
            onSubmit={submit}
            onClose={onClose}
            onReload={() => {
                // The trip changed or access did: show its latest record.
                onSaved();
                onClose();
            }}
            errorKey={JSON.stringify(errors)}
            success={
                <WizardSuccess
                    title="Driver confirmed"
                    blurb={`${chosen?.name ?? 'The driver'} is now the confirmed driver for ${trip.reference}. The booking and any earlier attribution stay in the trip's history.`}
                    onClose={onClose}
                />
            }
        >
            {step === 0 && (
                <div className="space-y-5">
                    <p className="text-subtle">
                        Confirm the person who actually drove this trip. If the
                        vehicle was handed over to someone other than the booked
                        driver, record the handover and its evidence.
                    </p>
                    <div className="rounded-lg border bg-muted/40 p-4 text-sm">
                        <p className="text-caption font-semibold tracking-wide uppercase">
                            Recorded assignment
                        </p>
                        <dl className="mt-2 grid gap-2">
                            <div>
                                <dt className="text-caption">Shown now as</dt>
                                <dd>
                                    {driverName(driver)} ·{' '}
                                    {driverCaption(driver)}
                                </dd>
                            </div>
                            <div>
                                <dt className="text-caption">Booking</dt>
                                <dd>
                                    {source.booking
                                        ? `${source.booking.reference} · ${driver.booked_driver ?? 'booked driver not shown at your sites'} · checked out ${formatDateTime(source.booking.checked_out_at)}${source.booking.returned_at ? ` · returned ${formatDateTime(source.booking.returned_at)}` : ' · not yet returned'}`
                                        : 'No checked-out booking covered this trip.'}
                                </dd>
                            </div>
                        </dl>
                    </div>
                    <WizardField
                        id="trip-driver"
                        label="Actual driver"
                        error={errors.driver}
                        hint={
                            candidates.length
                                ? "Current staff placed at this vehicle's site."
                                : "No current staff are placed at this vehicle's site, so a driver can't be confirmed here yet."
                        }
                    >
                        <PersonPicker
                            id="trip-driver"
                            label="Actual driver"
                            value={form.driver}
                            people={candidates}
                            onChange={(value) => update('driver', value)}
                            invalid={!!errors.driver}
                            describedBy={
                                errors.driver ? 'trip-driver-error' : undefined
                            }
                        />
                    </WizardField>
                    <WizardField
                        id="trip-driver-reason"
                        label="Checkout or handover evidence"
                        error={errors.reason}
                        hint="For example the booking checkout, the key log, the roster or a handover note."
                    >
                        <Textarea
                            {...fieldProps('trip-driver-reason', errors.reason)}
                            rows={3}
                            maxLength={2000}
                            value={form.reason}
                            onChange={(event) =>
                                update('reason', event.target.value)
                            }
                        />
                    </WizardField>
                    <div className="space-y-1">
                        <div className="flex items-start gap-2">
                            <Checkbox
                                id="trip-driver-verified"
                                checked={form.verified}
                                aria-invalid={!!errors.verified}
                                aria-describedby={
                                    errors.verified
                                        ? 'trip-driver-verified-error'
                                        : undefined
                                }
                                onCheckedChange={(value) =>
                                    update('verified', value === true)
                                }
                            />
                            <Label
                                htmlFor="trip-driver-verified"
                                className="leading-snug font-normal"
                            >
                                I verified the actual driver and handover
                            </Label>
                        </div>
                        {errors.verified && (
                            <p
                                id="trip-driver-verified-error"
                                role="alert"
                                className="text-xs text-status-critical"
                            >
                                {errors.verified}
                            </p>
                        )}
                    </div>
                </div>
            )}
            {step === 1 && (
                <ReviewCard
                    icon={UserRound}
                    title="Driver and trip"
                    onEdit={() => setStep(0)}
                >
                    <ReviewRow
                        label="Trip"
                        value={`${trip.reference} · ${tripWhen(detail)}`}
                    />
                    <ReviewRow label="Actual driver" value={chosen?.name} />
                    <ReviewRow
                        label="Booked driver"
                        value={
                            source.booking
                                ? (driver.booked_driver ??
                                  'Not shown at your sites')
                                : 'No booking'
                        }
                    />
                    <ReviewRow label="Recorded as" value={recordedAs} />
                    <ReviewRow
                        label="Evidence"
                        value={form.reason.trim() || undefined}
                    />
                </ReviewCard>
            )}
        </WorkspaceWizard>
    );
}

/** Read-only provenance for the selected trip ("Source" in the approved design). */
export function TripSourceDialog({
    detail,
    onClose,
}: {
    detail: TripDetail;
    onClose: () => void;
}) {
    const { trip, driver, source, vehicle } = detail;
    const booking = source.booking;
    const rows: Array<[string, string]> = [
        [
            'Vehicle',
            [vehicle.name, vehicle.registration_number, vehicle.asset_tag]
                .filter(Boolean)
                .join(' · '),
        ],
        ['Trip', `${trip.reference} · ${tripWhen(detail)} · Pacific/Auckland`],
        ['Route', `${startLabel(trip)} → ${endLabel(trip)}`],
        [
            'Observation source',
            source.recorded_points
                ? `${source.vendors.length ? `${source.vendors.join(', ')} tracker` : 'Tracker'} · ${plural(source.recorded_points, 'recorded position')}`
                : 'No tracker positions were kept for this trip',
        ],
        [
            'Distance',
            `${formatDistance(trip.distance_km)} · tracker GPS estimate, not a dashboard reading`,
        ],
        [
            'Driver',
            `${driverName(driver)} · ${driverCaption(driver)}${driver.confirmed_at ? ` · confirmed ${formatDateTime(driver.confirmed_at)}${driver.confirmed_by ? ` by ${driver.confirmed_by}` : ''}` : ''}`,
        ],
        [
            'Booking',
            booking
                ? `${booking.reference} · checked out ${formatDateTime(booking.checked_out_at)}${booking.returned_at ? ` · returned ${formatDateTime(booking.returned_at)}` : ' · not yet returned'}`
                : 'No checked-out booking covered this trip',
        ],
        [
            'Dashboard evidence',
            booking &&
            (booking.odometer_out !== null || booking.odometer_in !== null)
                ? `${booking.odometer_out !== null ? formatDistance(booking.odometer_out) : 'Not recorded'} → ${booking.odometer_in !== null ? formatDistance(booking.odometer_in) : 'Not recorded'} · booking checkout and return readings`
                : 'No odometer readings on a booking for this trip',
        ],
        [
            'Route line',
            'Lines join recorded positions in time order; they are not verified roads.',
        ],
        [
            'Privacy',
            trip.consent_blocked
                ? 'Tracking consent was not in place; positions were not kept'
                : trip.is_personal
                  ? 'Personal trip: not scored, totalled as driving events or exported'
                  : 'Business trip',
        ],
    ];

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent
                className="max-h-[90vh] overflow-y-auto"
                style={{
                    width: 'min(92vw, 560px)',
                    maxWidth: 'min(92vw, 560px)',
                }}
            >
                <DialogHeader>
                    <DialogTitle>Trip source record</DialogTitle>
                    <DialogDescription>
                        {vehicle.name} · {trip.reference}. Original telemetry is
                        kept unchanged.
                    </DialogDescription>
                </DialogHeader>
                <dl className="grid gap-3 text-sm">
                    {rows.map(([label, value]) => (
                        <div
                            key={label}
                            className="grid gap-1 border-b pb-3 last:border-0 sm:grid-cols-[150px_minmax(0,1fr)]"
                        >
                            <dt className="text-caption">{label}</dt>
                            <dd className="min-w-0 break-words">{value}</dd>
                        </div>
                    ))}
                </dl>
                {detail.driver_history.length > 0 && (
                    <div className="grid gap-2">
                        <h3 className="text-sm font-semibold">
                            Driver confirmations
                        </h3>
                        <ul className="grid gap-2 text-sm">
                            {detail.driver_history.map((row) => (
                                <li
                                    key={row.id}
                                    className="rounded-lg border p-3"
                                >
                                    <strong>
                                        {row.driver ?? 'Driver not shown'}
                                    </strong>{' '}
                                    · {SOURCE_LABELS[row.source]}
                                    <p className="text-caption mt-1">
                                        {formatDateTime(row.confirmed_at)}
                                        {row.confirmed_by
                                            ? ` · by ${row.confirmed_by}`
                                            : ''}
                                    </p>
                                    <p className="mt-1 whitespace-pre-line">
                                        {row.reason}
                                    </p>
                                </li>
                            ))}
                        </ul>
                    </div>
                )}
                <DialogFooter>
                    <Button variant="outline" onClick={onClose}>
                        Close
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
