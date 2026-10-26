import { actualEventInstant } from '@/components/clinical/actual-event-time-field';
import { DrillTimeField } from '@/components/health-safety/drills/drill-time-field';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { Field, SelectInput } from '@/components/wizard/primitives';
import { useSafetyForm } from '@/hooks/use-safety-workspace-drafts';
import { toDatetimeLocal, workerTimeOffset } from '@/lib/datetime';
import { useState } from 'react';
import {
    PlannedLocationFields,
    plannedLocationDraft,
    plannedLocationInput,
} from './planned-location-fields';
import type {
    SafetySession,
    SessionCommand,
    SessionPickers,
    SessionSection,
} from './types';

export const SESSION_COMMAND_LABELS: Record<SessionCommand, string> = {
    edit: 'Edit session',
    'check-in': 'Record check-in',
    end: 'End session',
    panic: 'Request help',
    locate: 'Manage location refresh',
    'acknowledge-panic': 'Acknowledge tracker panic',
    remove: 'Remove ended session',
};
export const SESSION_COMMAND_SECTIONS: Record<SessionCommand, SessionSection> =
    {
        edit: 'overview',
        'check-in': 'check-ins',
        end: 'overview',
        panic: 'response',
        locate: 'location',
        'acknowledge-panic': 'response',
        remove: 'overview',
    };
export function sessionCommandAllowed(
    d: SafetySession,
    command: SessionCommand,
): boolean {
    return d.can[
        command === 'check-in'
            ? 'check_in'
            : command === 'acknowledge-panic'
              ? 'acknowledge_panic'
              : command
    ];
}
const RECEIPT_COMMAND: Record<SessionCommand, string> = {
    edit: 'update',
    'check-in': 'checkIn',
    end: 'end',
    panic: 'emergency',
    locate: 'locationManagementOpened',
    'acknowledge-panic': 'trackerPanicAcknowledged',
    remove: 'delete',
};
export function confirmedSessionCommand(
    value: unknown,
    expected: {
        id: number;
        version: number;
        key: string;
        command: SessionCommand;
    },
): boolean {
    if (!value || typeof value !== 'object') return false;
    const receipt = value as Record<string, unknown>;
    return (
        receipt.id === expected.id &&
        receipt.lock_version === expected.version + 1 &&
        receipt.idempotency_key === expected.key &&
        receipt.command === RECEIPT_COMMAND[expected.command] &&
        (expected.command !== 'remove' || receipt.removed === true)
    );
}

export function SessionCommandForm({
    detail: d,
    command,
    pickers,
    returnTo,
    onSaved,
    onCancel,
}: {
    detail: SafetySession;
    command: SessionCommand;
    pickers?: SessionPickers;
    returnTo: string;
    onSaved: () => void;
    onCancel: () => void;
}) {
    const [expected] = useState(() => ({
        id: d.id,
        version: d.expected_version,
        key: crypto.randomUUID(),
        command,
    }));
    const [failure, setFailure] = useState<string | null>(null);
    const form = useSafetyForm({
        expected_end_at: toDatetimeLocal(d.expected_end_at),
        time_offset: d.expected_end_at
            ? workerTimeOffset(d.expected_end_at)
            : '',
        check_in_interval_minutes: String(d.check_in_interval_minutes),
        activity_description: d.activity_description ?? '',
        location: d.location ?? '',
        planned_location: plannedLocationDraft(d.planned_location),
        planned_location_changed: false,
        status: 'ok',
        notes: '',
        emergency_notes: '',
    });
    const allowed = sessionCommandAllowed(d, command);
    const label = SESSION_COMMAND_LABELS[command];
    return (
        <form
            className="space-y-5"
            onSubmit={(event) => {
                event.preventDefault();
                if (form.processing || !allowed) return;
                setFailure(null);
                const instant =
                    command === 'edit'
                        ? actualEventInstant(
                              form.data.expected_end_at,
                              form.data.time_offset,
                          )
                        : null;
                if (command === 'edit' && !instant) {
                    setFailure(
                        'Choose a valid New Zealand expected end and its clock occurrence if requested.',
                    );
                    return;
                }
                const plan = plannedLocationInput(form.data.planned_location);
                if (
                    command === 'edit' &&
                    form.data.planned_location_changed &&
                    !plan.ok
                ) {
                    setFailure(plan.error);
                    return;
                }
                form.transform((data) => ({
                    idempotency_key: expected.key,
                    expected_version: expected.version,
                    return_to: returnTo,
                    section: SESSION_COMMAND_SECTIONS[command],
                    action: command,
                    ...(command === 'edit'
                        ? {
                              expected_end_at: instant,
                              check_in_interval_minutes: Number(
                                  data.check_in_interval_minutes,
                              ),
                              activity_description:
                                  data.activity_description || null,
                              location: data.location || null,
                              ...(data.planned_location_changed && plan.ok
                                  ? { planned_location: plan.value }
                                  : {}),
                          }
                        : {}),
                    ...(command === 'check-in'
                        ? { status: data.status, notes: data.notes || null }
                        : {}),
                    ...(command === 'panic'
                        ? { emergency_notes: data.emergency_notes || null }
                        : {}),
                }));
                const suffix =
                    command === 'edit' || command === 'remove'
                        ? ''
                        : command === 'panic'
                          ? '/emergency'
                          : command === 'end'
                            ? d.can.manage
                                ? '/end'
                                : '/end-own'
                            : `/${command}`;
                let responded = false;
                form.submit(
                    command === 'edit'
                        ? 'patch'
                        : command === 'remove'
                          ? 'delete'
                          : 'post',
                    `/health-safety/lone-workers/sessions/${d.id}${suffix}`,
                    {
                        preserveScroll: true,
                        preserveState: true,
                        onSuccess: (page) => {
                            responded = true;
                            const error = (
                                page.props.flash as
                                    | { error?: string }
                                    | undefined
                            )?.error;
                            if (error) {
                                setFailure(error);
                                return;
                            }
                            // Locate hands over to the governed Devices page. It does not claim
                            // that a command was sent to hardware or that a position was received.
                            if (command === 'locate') return;
                            if (
                                !confirmedSessionCommand(
                                    page.props.lone_worker_confirmation,
                                    expected,
                                )
                            ) {
                                setFailure(
                                    'This change could not be confirmed. Your entries are still here. Check the session before trying again.',
                                );
                                return;
                            }
                            form.reset();
                            if (command !== 'remove') onSaved();
                        },
                        onError: () => {
                            responded = true;
                        },
                        onCancel: () => {
                            responded = true;
                            setFailure(
                                'Saving was interrupted. Your entries are still here. Check the session before trying again.',
                            );
                        },
                        onFinish: () => {
                            if (!responded)
                                setFailure(
                                    'The save could not be confirmed. Your entries are still here. Check the session before trying again.',
                                );
                        },
                    },
                );
            }}
        >
            <h2 className="text-section-title">{label}</h2>
            {!allowed && (
                <p role="alert" className="text-sm text-status-critical">
                    This action is no longer available. Your entries remain here
                    so you can review them before returning to the session.
                </p>
            )}
            {command === 'edit' && (
                <>
                    <DrillTimeField
                        label="Expected end"
                        value={form.data.expected_end_at}
                        offset={form.data.time_offset}
                        onChange={(value, offset) =>
                            form.setData((data) => ({
                                ...data,
                                expected_end_at: value,
                                time_offset: offset,
                            }))
                        }
                    />
                    <Field label="Check-in interval (minutes)" required>
                        <Input
                            className="frontline-tap"
                            type="number"
                            min={15}
                            max={480}
                            step={1}
                            required
                            value={form.data.check_in_interval_minutes}
                            onChange={(event) =>
                                form.setData(
                                    'check_in_interval_minutes',
                                    event.target.value,
                                )
                            }
                        />
                    </Field>
                    <Field label="Activity">
                        <Textarea
                            className="min-h-28"
                            maxLength={2000}
                            value={form.data.activity_description}
                            onChange={(event) =>
                                form.setData(
                                    'activity_description',
                                    event.target.value,
                                )
                            }
                        />
                    </Field>
                    <Field label="Location description">
                        <Textarea
                            className="min-h-24"
                            maxLength={500}
                            value={form.data.location}
                            onChange={(event) =>
                                form.setData('location', event.target.value)
                            }
                        />
                    </Field>
                    <p className="text-subtle">
                        Changing the schedule does not record a check-in. The
                        worker, Site and linked shift remain part of the
                        original session.
                    </p>
                    <PlannedLocationFields
                        value={form.data.planned_location}
                        onChange={(planned_location) =>
                            form.setData((data) => ({
                                ...data,
                                planned_location,
                                planned_location_changed: true,
                            }))
                        }
                        site={pickers?.sites.find(
                            (site) => site.id === d.site_id,
                        )}
                        shift={pickers?.shifts.find(
                            (shift) => shift.id === d.shift_id,
                        )}
                        canGeocode={pickers?.can_geocode}
                    />
                </>
            )}
            {command === 'check-in' && (
                <>
                    <Field label="Worker's reported status" required>
                        <SelectInput
                            frontline
                            value={form.data.status}
                            onChange={(value) => form.setData('status', value)}
                            placeholder="Choose the reported status"
                            options={[
                                { value: 'ok', label: 'Reported OK' },
                                {
                                    value: 'concern',
                                    label: 'Concern needs follow-up',
                                },
                            ]}
                        />
                    </Field>
                    <Field label="Check-in notes">
                        <Textarea
                            className="min-h-28"
                            maxLength={1000}
                            value={form.data.notes}
                            onChange={(event) =>
                                form.setData('notes', event.target.value)
                            }
                        />
                    </Field>
                    <p className="text-subtle">
                        The check-in will record the current time and the person
                        submitting it. Use Request help if an emergency response
                        is needed.
                    </p>
                </>
            )}
            {command === 'panic' && (
                <>
                    <Field label="What help is needed?">
                        <Textarea
                            className="min-h-28"
                            maxLength={2000}
                            value={form.data.emergency_notes}
                            onChange={(event) =>
                                form.setData(
                                    'emergency_notes',
                                    event.target.value,
                                )
                            }
                        />
                    </Field>
                    <p className="text-sm">
                        This records a help request. Check its delivery and
                        response on the session page. Follow your emergency
                        procedure if immediate help is needed.
                    </p>
                </>
            )}
            {command === 'end' && (
                <p className="text-sm">
                    This ends monitoring for this session. It does not clock the
                    worker out, resolve an incident or complete a safety review.
                </p>
            )}
            {command === 'remove' && (
                <p className="text-sm">
                    Remove this ended session from the register. Its recorded
                    safety history is retained.
                </p>
            )}
            {command === 'locate' && (
                <p className="text-sm">
                    Continue to device management to confirm your identity and
                    record the reason for a location refresh. Opening that page
                    does not send a command or confirm a location.
                </p>
            )}
            {command === 'acknowledge-panic' && (
                <p className="text-sm">
                    Acknowledge the tracker panic flag and its linked
                    operational alert. This does not confirm worker safety,
                    record a check-in or end the session.
                </p>
            )}
            {(failure || Object.keys(form.errors).length > 0) && (
                <p role="alert" className="text-sm text-status-critical">
                    {failure ?? Object.values(form.errors).join(' ')}
                </p>
            )}
            <div className="flex flex-wrap gap-3">
                <Button
                    className="frontline-tap"
                    type="submit"
                    disabled={form.processing || !allowed}
                >
                    {form.processing
                        ? 'Saving…'
                        : command === 'edit'
                          ? 'Save changes'
                          : label}
                </Button>
                <Button
                    type="button"
                    variant="outline"
                    className="frontline-tap"
                    disabled={form.processing}
                    onClick={onCancel}
                >
                    Back to session
                </Button>
            </div>
        </form>
    );
}
