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
import { formatDateTime, toDatetimeLocal } from '@/lib/datetime';
import { CalendarDays, CheckCircle2, Undo2 } from 'lucide-react';
import { useState } from 'react';
import {
    isJsonObject,
    useVehicleRecordCommand,
} from '../vehicle-workspace/record-command';
import { VehicleSearchSelect } from '../vehicle-workspace/search-select';
import { WorkspaceWizard } from '../vehicle-workspace/wizard-kit';
import { aucklandTimeChoices } from './calendar-actions';
import type { TransportRecord } from './types';
import { Notice } from './ui';

/** Dragging proposes a change; the existing Fleet command rechecks availability and approval. */
export function RescheduleDialog({
    row,
    start,
    end,
    startOffset,
    endOffset,
    onClose,
    onSaved,
    onUndo,
}: {
    row: TransportRecord;
    start: string;
    end: string;
    startOffset: string;
    endOffset: string;
    onClose: () => void;
    onSaved: () => void;
    onUndo?: (start: string, end: string) => void;
}) {
    const [form, setForm] = useState({
        start,
        end,
        startOffset,
        endOffset,
        reason: '',
        confirmed: false,
    });
    const [step, setStep] = useState(0);
    const [saved, setSaved] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const command = useVehicleRecordCommand(isJsonObject);
    const booking = row.booking!;
    const starts = aucklandTimeChoices(form.start),
        ends = aucklandTimeChoices(form.end);
    const chosenStart =
        starts.length === 1
            ? starts[0]
            : starts.find((choice) => choice.offset === form.startOffset);
    const chosenEnd =
        ends.length === 1
            ? ends[0]
            : ends.find((choice) => choice.offset === form.endOffset);
    const validate = (review = false) => {
        const next: Record<string, string> = {};
        if (!chosenStart || !chosenEnd)
            next.window =
                'Choose valid Auckland times. For a repeated hour, choose its UTC offset below.';
        else if (chosenEnd.instant <= chosenStart.instant)
            next.window = 'Choose a departure and a later expected return.';
        if (review && !form.reason.trim())
            next.reason = 'Explain why this transport needs a different time.';
        if (review && !form.confirmed)
            next.confirmed = 'Confirm the proposed time and team arrangements.';
        setErrors(next);
        if (review && next.window) setStep(0);
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
                reschedule_only: true,
                starts_local: form.start,
                ends_local: form.end,
                starts_offset: chosenStart?.offset,
                ends_offset: chosenEnd?.offset,
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
            maxWidth="min(92vw, 1100px)"
            description="Review a proposed time change to the existing Fleet booking."
            railIcon={CalendarDays}
            railSub={row.reference}
            steps={[
                {
                    key: 'time',
                    label: 'New time',
                    blurb: 'Departure and expected return',
                    icon: CalendarDays,
                },
                {
                    key: 'review',
                    label: 'Review change',
                    blurb: 'Check the team and record a reason',
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
            dirty={
                form.start !== toDatetimeLocal(booking.start) ||
                form.end !== toDatetimeLocal(booking.end) ||
                !!form.reason ||
                form.confirmed ||
                form.startOffset !== startOffset ||
                form.endOffset !== endOffset
            }
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
                                    onClick={() =>
                                        onUndo(
                                            new Date(
                                                chosenStart!.instant,
                                            ).toISOString(),
                                            new Date(
                                                chosenEnd!.instant,
                                            ).toISOString(),
                                        )
                                    }
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
                        <DateTimeField
                            id="transport-reschedule-start"
                            label="Proposed departure"
                            value={form.start}
                            onChange={(value) =>
                                setForm({
                                    ...form,
                                    start: value,
                                    startOffset: '',
                                    confirmed: false,
                                })
                            }
                        />
                        {starts.length > 1 && (
                            <VehicleSearchSelect
                                label="Departure occurs twice · choose offset"
                                value={form.startOffset}
                                onChange={(value) =>
                                    setForm({
                                        ...form,
                                        startOffset: value,
                                        confirmed: false,
                                    })
                                }
                                options={starts.map((choice, index) => ({
                                    value: choice.offset,
                                    label: `${index === 0 ? 'First' : 'Second'} occurrence · UTC${choice.offset}`,
                                }))}
                            />
                        )}
                        <DateTimeField
                            id="transport-reschedule-end"
                            label="Proposed return"
                            value={form.end}
                            onChange={(value) =>
                                setForm({
                                    ...form,
                                    end: value,
                                    endOffset: '',
                                    confirmed: false,
                                })
                            }
                        />
                        {ends.length > 1 && (
                            <VehicleSearchSelect
                                label="Return occurs twice · choose offset"
                                value={form.endOffset}
                                onChange={(value) =>
                                    setForm({
                                        ...form,
                                        endOffset: value,
                                        confirmed: false,
                                    })
                                }
                                options={ends.map((choice, index) => ({
                                    value: choice.offset,
                                    label: `${index === 0 ? 'First' : 'Second'} occurrence · UTC${choice.offset}`,
                                }))}
                            />
                        )}
                    </>
                ) : (
                    <>
                        <Notice>
                            Current booking: {formatDateTime(booking.start)} –{' '}
                            {formatDateTime(booking.end)}. The calendar changes
                            only after you save.
                        </Notice>
                        <ReviewCard
                            icon={CalendarDays}
                            title="Proposed time · Pacific/Auckland"
                            onEdit={() => setStep(0)}
                        >
                            <ReviewRow
                                label="Departure"
                                value={`${localDateTimeLabel(form.start)} · UTC${chosenStart?.offset || 'offset required'}`}
                            />
                            <ReviewRow
                                label="Expected return"
                                value={`${localDateTimeLabel(form.end)} · UTC${chosenEnd?.offset || 'offset required'}`}
                            />
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
                        <label>
                            Reason for rescheduling
                            <Textarea
                                value={form.reason}
                                maxLength={2000}
                                aria-invalid={!!allErrors.reason}
                                onChange={(event) =>
                                    setForm({
                                        ...form,
                                        reason: event.target.value,
                                    })
                                }
                            />
                        </label>
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
