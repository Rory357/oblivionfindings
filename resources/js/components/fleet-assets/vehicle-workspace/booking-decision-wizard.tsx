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
import { formatDateTime } from '@/lib/datetime';
import { FileCheck2, KeyRound } from 'lucide-react';
import { useState } from 'react';
import type {
    BookingRow,
    UnavailableRow,
    VehicleCalendarSummary,
} from './calendar-types';
import { isJsonObject, useVehicleRecordCommand } from './record-command';
import type { VehicleWorkspace } from './types';
import {
    fieldProps,
    StudioNotice,
    WizardField,
    WizardSuccess,
    WorkspaceWizard,
} from './wizard-kit';
import { formatKm } from './workspace-model';

export type BookingDecision =
    | 'approve'
    | 'decline'
    | 'out'
    | 'return'
    | 'cancel';

const TITLES: Record<BookingDecision, [string, string]> = {
    approve: ['Review booking request', 'Approve booking'],
    decline: ['Decline booking request', 'Decline request'],
    out: ['Check out vehicle', 'Record checkout'],
    return: ['Return vehicle', 'Record return'],
    cancel: ['Cancel booking / block', 'Confirm cancellation'],
};

const CONDITIONS = ['No new concern', 'Concern recorded'];

export function BookingDecisionWizard({
    workspace,
    summary,
    row,
    decision,
    onClose,
    onSaved,
}: {
    workspace: VehicleWorkspace;
    summary: VehicleCalendarSummary;
    row: BookingRow | UnavailableRow;
    decision: BookingDecision;
    onClose: () => void;
    onSaved: () => void;
}) {
    const vehicle = workspace.vehicle;
    const booking = row.kind === 'booking' ? row : null;
    const currentKm = workspace.odometer.current_km;
    const [initial] = useState(() => ({
        odometer:
            decision === 'return' && booking?.odometer_out !== null && booking
                ? String(Math.max(booking.odometer_out ?? 0, currentKm ?? 0))
                : currentKm !== null
                  ? String(currentKm)
                  : '',
        condition: CONDITIONS[0],
        evidence: '',
        keys: false,
        ready: false,
        notes: '',
    }));
    const [form, setForm] = useState(initial);
    const [localErrors, setLocalErrors] = useState<Record<string, string>>({});
    const [step, setStep] = useState(0);
    const [savedText, setSavedText] = useState<string | null>(null);
    const [reportText, setReportText] = useState<string | null>(null);
    const command = useVehicleRecordCommand(isJsonObject);
    const errors: Record<string, string> = { ...command.errors, ...localErrors };
    const [title, verb] = TITLES[decision];
    const custody = decision === 'out' || decision === 'return';
    const update = <K extends keyof typeof form>(
        key: K,
        value: (typeof form)[K],
    ) => {
        setForm((old) => ({ ...old, [key]: value }));
        setLocalErrors({});
        command.clearError(key as string);
    };
    // Server field names for each form field, so its errors land in place.
    const serverKey: Record<string, string> = {
        odometer: decision === 'return' ? 'odometer_in' : 'odometer_out',
        condition: decision === 'return' ? 'condition_on_return' : 'checkout_condition',
        evidence:
            decision === 'return'
                ? 'return_evidence_reference'
                : 'checkout_evidence_reference',
        keys: decision === 'return' ? 'keys_received' : 'keys_handed',
        notes:
            decision === 'decline'
                ? 'rejection_reason'
                : decision === 'return'
                  ? 'return_notes'
                  : decision === 'out'
                    ? 'checkout_notes'
                    : decision === 'approve'
                      ? 'decision_notes'
                      : 'reason',
    };
    const error = (field: string) => errors[field] ?? errors[serverKey[field]];

    const validateStep = (): boolean => {
        const found: Record<string, string> = {};
        if (decision === 'approve') {
            if (!form.ready)
                found.ready =
                    'Confirm you reviewed readiness, driver authority and conflicts.';
            if (summary.use_problem) found.ready = summary.use_problem;
        }
        if (custody) {
            const km = Number(form.odometer);
            if (form.odometer === '' || !Number.isFinite(km) || km < 0)
                found.odometer = 'Record the odometer reading.';
            else if (
                decision === 'out' &&
                currentKm !== null &&
                km < currentKm
            )
                found.odometer =
                    'The checkout reading must not be below the current recorded reading.';
            else if (
                decision === 'return' &&
                booking?.odometer_out !== null &&
                booking &&
                km < (booking.odometer_out ?? 0)
            )
                found.odometer =
                    'The return reading must not be below the checkout reading.';
            if (!form.evidence.trim())
                found.evidence = 'Record the condition check or evidence reference.';
            if (!form.keys)
                found.keys =
                    decision === 'out'
                        ? 'Confirm the keys were handed to the recorded driver.'
                        : 'Confirm the keys and vehicle were received.';
            // Checkout is for a current booking; an hour's early pickup is fine.
            if (
                decision === 'out' &&
                booking &&
                new Date(booking.starts_at).getTime() - Date.now() >
                    60 * 60 * 1000
            )
                found.odometer =
                    'Pickup is more than an hour away. Checkout needs a current booking.';
        }
        if (!form.notes.trim())
            found.notes =
                decision === 'decline'
                    ? 'Record why the request is declined.'
                    : 'Record the decision or custody notes.';
        setLocalErrors(found);
        return Object.keys(found).length === 0;
    };

    const submit = async () => {
        if (!command.uncertain && !validateStep()) {
            setStep(0);
            return;
        }
        const notes = form.notes.trim();
        let url = `/fleet-assets/bookings/${row.id}`;
        let body: Record<string, unknown> = {};
        if (row.kind === 'unavailable') {
            url = `/fleet-assets/vehicles/${vehicle.id}/unavailable-periods/${row.id}/cancel`;
            body = { reason: notes, expected_version: row.lock_version };
        } else if (decision === 'approve') {
            url += '/approve';
            body = { readiness_reviewed: form.ready, decision_notes: notes };
        } else if (decision === 'decline') {
            url += '/reject';
            body = { rejection_reason: notes };
        } else if (decision === 'out') {
            url += '/checkout';
            body = {
                odometer_out: Number(form.odometer),
                checkout_condition: form.condition,
                checkout_evidence_reference: form.evidence.trim(),
                keys_handed: form.keys,
                checkout_notes: notes,
            };
        } else if (decision === 'return') {
            url += '/return';
            body = {
                odometer_in: Number(form.odometer),
                condition_on_return: form.condition,
                return_evidence_reference: form.evidence.trim(),
                keys_received: form.keys,
                return_notes: notes,
            };
        } else {
            url += '/cancel';
            body = { reason: notes };
        }
        const result = await command.submit(url, body);
        if (!result) return;
        setSavedText(
            typeof result.message === 'string' ? result.message : 'Saved.',
        );
        // A return with a concern also reports it to Maintenance when the Site
        // has an approved route; otherwise say how to report it.
        const report = isJsonObject(result.maintenance_report)
            ? result.maintenance_report
            : null;
        setReportText(
            report?.status === 'created'
                ? `The concern is with Maintenance as ${String(report.reference ?? `work #${String(report.work_order_id)}`)}.`
                : report?.status === 'not_routed'
                  ? `${typeof report.message === 'string' ? report.message : 'The concern could not be sent to Maintenance.'} Use Report a problem on the vehicle so it is followed up.`
                  : null,
        );
        onSaved();
    };

    const record = `${row.reference ?? (row.kind === 'booking' ? `Booking #${row.id}` : 'Unavailable period')} · ${formatDateTime(row.starts_at)} to ${formatDateTime(row.ends_at)}`;
    const steps = [
        {
            key: 'record',
            label: 'Booking record',
            blurb: record.split(' · ')[0],
            icon: KeyRound,
        },
        {
            key: 'review',
            label: 'Review',
            blurb: 'Confirm the resulting record',
            icon: FileCheck2,
        },
    ];

    return (
        <WorkspaceWizard
            title={title}
            description={record}
            railIcon={KeyRound}
            railSub={
                [vehicle.registration_number, vehicle.site?.name]
                    .filter(Boolean)
                    .join(' · ') || vehicle.name
            }
            steps={steps}
            step={step}
            setStep={setStep}
            pct={step === 1 ? 100 : 50}
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
            dirty={JSON.stringify(form) !== JSON.stringify(initial)}
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
                    title={savedText ?? 'Saved'}
                    blurb={`The booking record, calendar and custody history now show this decision.${reportText ? ` ${reportText}` : ''}`}
                    onClose={onClose}
                />
            }
        >
            {step === 0 ? (
                <div className="space-y-5">
                    <p className="text-subtle">{record}</p>
                    {booking && (
                        <p className="text-caption">
                            {[
                                booking.purpose,
                                booking.driver
                                    ? `Driver ${booking.driver.name}`
                                    : null,
                                booking.approval_route === 'not_required'
                                    ? 'Approval not required'
                                    : 'Approval required',
                            ]
                                .filter(Boolean)
                                .join(' · ')}
                        </p>
                    )}
                    {decision === 'approve' && (
                        <div className="space-y-1">
                            <label className="flex items-start gap-3 text-sm">
                                <Checkbox
                                    id="ready"
                                    checked={form.ready}
                                    aria-invalid={!!error('ready')}
                                    onCheckedChange={(value) =>
                                        update('ready', value === true)
                                    }
                                />
                                <span>
                                    Readiness, driver authority and conflicts
                                    reviewed
                                </span>
                            </label>
                            {error('ready') && (
                                <p
                                    role="alert"
                                    className="text-xs text-status-critical"
                                >
                                    {error('ready')}
                                </p>
                            )}
                        </div>
                    )}
                    {custody && (
                        <>
                            <div className="vehicle-wizard-fields">
                                <WizardField
                                    id="odometer"
                                    label="Recorded odometer"
                                    error={error('odometer')}
                                    hint={
                                        decision === 'out'
                                            ? `Current reading ${formatKm(currentKm)}`
                                            : `Checkout ${formatKm(booking?.odometer_out)}`
                                    }
                                >
                                    <Input
                                        {...fieldProps(
                                            'odometer',
                                            error('odometer'),
                                        )}
                                        type="number"
                                        inputMode="decimal"
                                        min="0"
                                        step="0.1"
                                        value={form.odometer}
                                        onChange={(event) =>
                                            update('odometer', event.target.value)
                                        }
                                    />
                                </WizardField>
                                <WizardField
                                    id="condition"
                                    label="Condition"
                                    error={error('condition')}
                                >
                                    <Select
                                        value={form.condition}
                                        onValueChange={(value) =>
                                            update('condition', value)
                                        }
                                    >
                                        <SelectTrigger
                                            {...fieldProps(
                                                'condition',
                                                error('condition'),
                                            )}
                                        >
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {CONDITIONS.map((option) => (
                                                <SelectItem
                                                    key={option}
                                                    value={option}
                                                >
                                                    {option}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                </WizardField>
                            </div>
                            <WizardField
                                id="evidence"
                                label="Condition check / evidence reference"
                                error={error('evidence')}
                            >
                                <Input
                                    {...fieldProps('evidence', error('evidence'))}
                                    maxLength={120}
                                    value={form.evidence}
                                    onChange={(event) =>
                                        update('evidence', event.target.value)
                                    }
                                />
                            </WizardField>
                            <div className="space-y-1">
                                <label className="flex items-start gap-3 text-sm">
                                    <Checkbox
                                        id="keys"
                                        checked={form.keys}
                                        aria-invalid={!!error('keys')}
                                        onCheckedChange={(value) =>
                                            update('keys', value === true)
                                        }
                                    />
                                    <span>
                                        {decision === 'out'
                                            ? 'Keys handed to recorded driver'
                                            : 'Keys and vehicle received'}
                                    </span>
                                </label>
                                {error('keys') && (
                                    <p
                                        role="alert"
                                        className="text-xs text-status-critical"
                                    >
                                        {error('keys')}
                                    </p>
                                )}
                            </div>
                        </>
                    )}
                    <WizardField
                        id="notes"
                        label="Decision / custody notes"
                        error={error('notes')}
                    >
                        <Textarea
                            {...fieldProps('notes', error('notes'))}
                            rows={3}
                            maxLength={
                                decision === 'decline'
                                    ? 500
                                    : decision === 'return'
                                      ? 1000
                                      : 2000
                            }
                            value={form.notes}
                            onChange={(event) =>
                                update('notes', event.target.value)
                            }
                        />
                    </WizardField>
                </div>
            ) : (
                <div className="grid gap-4">
                    <ReviewCard
                        icon={KeyRound}
                        title="Booking record"
                        onEdit={() => setStep(0)}
                    >
                        {decision === 'approve' && (
                            <ReviewRow
                                label="Readiness, driver authority and conflicts reviewed"
                                value={
                                    form.ready
                                        ? 'Reviewed / selected'
                                        : 'Not selected'
                                }
                            />
                        )}
                        {custody && (
                            <>
                                <ReviewRow
                                    label="Recorded odometer"
                                    value={
                                        form.odometer
                                            ? formatKm(Number(form.odometer))
                                            : undefined
                                    }
                                />
                                <ReviewRow
                                    label="Condition"
                                    value={form.condition}
                                />
                                <ReviewRow
                                    label="Condition check / evidence reference"
                                    value={form.evidence || undefined}
                                />
                                <ReviewRow
                                    label={
                                        decision === 'out'
                                            ? 'Keys handed to recorded driver'
                                            : 'Keys and vehicle received'
                                    }
                                    value={
                                        form.keys
                                            ? 'Reviewed / selected'
                                            : 'Not selected'
                                    }
                                />
                            </>
                        )}
                        <ReviewRow
                            label="Decision / custody notes"
                            value={form.notes || undefined}
                        />
                    </ReviewCard>
                    {decision === 'out' && (
                        <StudioNotice title="What this changes">
                            The checkout reading is kept as an odometer
                            observation and the key handover is recorded against
                            this booking. Readiness and conflicts are checked
                            again when you save.
                        </StudioNotice>
                    )}
                    {decision === 'cancel' && row.kind === 'unavailable' && (
                        <StudioNotice title="What this changes">
                            The vehicle becomes bookable again for this period.
                            The cancelled period stays in the calendar history,
                            and you can undo the cancellation straight after.
                        </StudioNotice>
                    )}
                    {(decision === 'decline' ||
                        (decision === 'cancel' && row.kind === 'booking')) && (
                        <StudioNotice title="This decision is final">
                            {decision === 'decline'
                                ? 'A declined request can’t be reopened; the person can make a new request.'
                                : 'A cancelled booking can’t be reopened; make a new request if the vehicle is still needed.'}{' '}
                            The reason stays in the booking history.
                        </StudioNotice>
                    )}
                    {decision === 'return' &&
                        form.condition === 'Concern recorded' && (
                            <StudioNotice title="What this changes">
                                The concern is also reported to Maintenance as
                                linked work when the vehicle’s Site has an
                                approved route.
                            </StudioNotice>
                        )}
                </div>
            )}
        </WorkspaceWizard>
    );
}
