import {
    DateTimeField,
    localDateTimeLabel,
} from '@/components/fleet-assets/maintenance/date-time-field';
import { Button } from '@/components/ui/button';
import { Textarea } from '@/components/ui/textarea';
import {
    ReviewCard,
    ReviewRow,
    WizardSuccessPane,
} from '@/components/wizard/shell';
import { formatDateTime } from '@/lib/datetime';
import { CalendarDays, CheckCircle2, Undo2 } from 'lucide-react';
import { useState } from 'react';
import {
    isJsonObject,
    useVehicleRecordCommand,
} from '../vehicle-workspace/record-command';
import { WorkspaceWizard } from '../vehicle-workspace/wizard-kit';
import type { TransportRecord } from './types';
import { Notice } from './ui';

/** Dragging proposes a change; the existing Fleet command rechecks availability and approval. */
export function RescheduleDialog({
    row,
    start,
    end,
    onClose,
    onSaved,
    onUndo,
}: {
    row: TransportRecord;
    start: string;
    end: string;
    onClose: () => void;
    onSaved: () => void;
    onUndo?: (start: string, end: string) => void;
}) {
    const [form, setForm] = useState({
        start,
        end,
        reason: '',
        confirmed: false,
    });
    const [step, setStep] = useState(0);
    const [saved, setSaved] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const command = useVehicleRecordCommand(isJsonObject);
    const booking = row.booking!;
    const validate = (review = false) => {
        const next: Record<string, string> = {};
        if (!form.start || !form.end || form.end <= form.start)
            next.window = 'Choose a departure and a later expected return.';
        if (!form.reason.trim())
            next.reason = 'Explain why this transport needs a different time.';
        if (review && !form.confirmed)
            next.confirmed = 'Confirm the proposed time and team arrangements.';
        setErrors(next);
        return Object.keys(next).length === 0;
    };
    const submit = async () => {
        if (!validate(true)) return;
        const result = await command.submit(
            `/fleet-assets/bookings/${booking.id}`,
            {
                transport_request_id: row.id,
                transport_expected_version: row.version,
                expected_version: booking.version,
                client_id: row.client_id,
                asset_id: booking.vehicle.id,
                starts_local: form.start,
                ends_local: form.end,
                purpose: row.purpose,
                destination: row.destination,
                passengers: row.required_seats,
                pickup_site_id: row.site.id,
                return_site_id: row.site.id,
                driver_user_id: booking.driver.id,
                escort_user_id: row.escort?.id || null,
                key_pickup_room_id: row.key_pickup_room_id,
                key_return_room_id: row.key_return_room_id,
                key_delivery_arrangement: row.key_delivery_arrangement,
                pickup_arrangement: row.key_delivery_arrangement,
                approval_route: booking.approval_route,
                approval_not_required_reason:
                    booking.approval_route === 'not_required'
                        ? form.reason
                        : null,
                readiness_acknowledged: true,
                reason: form.reason,
            },
            { method: 'PUT' },
        );
        if (result) setSaved(true);
    };
    const finish = () => {
        onClose();
        onSaved();
    };
    const allErrors = { ...errors, ...command.errors };
    return (
        <WorkspaceWizard
            title="Reschedule transport"
            description="Review a proposed time change to the existing Fleet booking."
            railIcon={CalendarDays}
            railSub={row.reference}
            steps={[
                {
                    key: 'time',
                    label: 'New time',
                    blurb: 'Departure, return and reason',
                    icon: CalendarDays,
                },
                {
                    key: 'review',
                    label: 'Review change',
                    blurb: 'Check the team and arrangements',
                    icon: CheckCircle2,
                },
            ]}
            step={step}
            setStep={setStep}
            freeNavigation
            pct={Math.round(
                ([
                    !!form.start,
                    !!form.end,
                    !!form.reason.trim(),
                    form.confirmed,
                ].filter(Boolean).length /
                    4) *
                    100,
            )}
            context={{
                name: row.person,
                detail: `${row.site.name} · ${booking.vehicle.name}`,
            }}
            command={command}
            dirty
            saved={saved}
            submitLabel="Save new time"
            onValidateStep={() => validate()}
            onSubmit={submit}
            onClose={saved ? finish : onClose}
            onReload={finish}
            errorKey={JSON.stringify(allErrors)}
            success={
                <WizardSuccessPane
                    title="Transport rescheduled"
                    blurb="The existing Fleet booking was updated. Required approvals and readiness follow the current Fleet rules."
                    actions={
                        <>
                            <Button onClick={finish}>Back to calendar</Button>
                            {onUndo && (
                                <Button
                                    variant="outline"
                                    onClick={() => onUndo(form.start, form.end)}
                                >
                                    <Undo2 className="size-4" />
                                    Undo · review previous time
                                </Button>
                            )}
                        </>
                    }
                />
            }
        >
            <div className="transport-workspace tr-form">
                {!!Object.keys(allErrors).length && (
                    <div role="alert" className="tr-errors">
                        {Object.entries(allErrors).map(([key, message]) => (
                            <p key={key}>{message}</p>
                        ))}
                    </div>
                )}
                {step === 0 ? (
                    <>
                        <Notice>
                            Current booking: {formatDateTime(booking.start)} –{' '}
                            {formatDateTime(booking.end)}. The calendar changes
                            only after you save.
                        </Notice>
                        <DateTimeField
                            id="transport-reschedule-start"
                            label="Proposed departure"
                            value={form.start}
                            onChange={(value) =>
                                setForm({
                                    ...form,
                                    start: value,
                                    confirmed: false,
                                })
                            }
                        />
                        <DateTimeField
                            id="transport-reschedule-end"
                            label="Proposed return"
                            value={form.end}
                            onChange={(value) =>
                                setForm({
                                    ...form,
                                    end: value,
                                    confirmed: false,
                                })
                            }
                        />
                        <label>
                            Reason for rescheduling
                            <Textarea
                                value={form.reason}
                                aria-invalid={!!allErrors.reason}
                                onChange={(event) =>
                                    setForm({
                                        ...form,
                                        reason: event.target.value,
                                    })
                                }
                            />
                        </label>
                    </>
                ) : (
                    <>
                        <ReviewCard
                            icon={CalendarDays}
                            title="Proposed time · Pacific/Auckland"
                            onEdit={() => setStep(0)}
                        >
                            <ReviewRow
                                label="Departure"
                                value={localDateTimeLabel(form.start)}
                            />
                            <ReviewRow
                                label="Expected return"
                                value={localDateTimeLabel(form.end)}
                            />
                            <ReviewRow label="Reason" value={form.reason} />
                        </ReviewCard>
                        <ReviewCard
                            icon={CheckCircle2}
                            title="Vehicle and team"
                        >
                            <ReviewRow
                                label="Vehicle"
                                value={booking.vehicle.name}
                            />
                            <ReviewRow
                                label="Driver"
                                value={booking.driver.name}
                            />
                            <ReviewRow
                                label="Escort"
                                value={row.escort?.name || 'Not required'}
                            />
                            <ReviewRow
                                label="Keys"
                                value={row.key_delivery_arrangement}
                            />
                        </ReviewCard>
                        <Notice>
                            Fleet will recheck vehicle and staff availability
                            for the full new window. Any required approval must
                            be obtained again. To change the vehicle, people or
                            keys, use Change transport plan.
                        </Notice>
                        <label className="tr-check">
                            <input
                                type="checkbox"
                                checked={form.confirmed}
                                aria-invalid={!!allErrors.confirmed}
                                onChange={(event) =>
                                    setForm({
                                        ...form,
                                        confirmed: event.target.checked,
                                    })
                                }
                            />
                            I have reviewed the new time and arrangements with
                            the team.
                        </label>
                    </>
                )}
            </div>
        </WorkspaceWizard>
    );
}
