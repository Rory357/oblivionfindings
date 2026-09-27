import { ConfirmDialog } from '@/components/confirm-dialog';
import {
    DateTimeField,
    localDateTimeLabel,
} from '@/components/fleet-assets/maintenance/date-time-field';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import {
    ReviewCard,
    ReviewRow,
    WizardSuccessPane,
} from '@/components/wizard/shell';
import { toDatetimeLocal } from '@/lib/datetime';
import {
    CheckCircle2,
    FileText,
    KeyRound,
    Loader2,
    Route,
    Users,
} from 'lucide-react';
import { useEffect, useState } from 'react';
import {
    isJsonObject,
    useVehicleRecordCommand,
} from '../vehicle-workspace/record-command';
import { VehicleSearchSelect } from '../vehicle-workspace/search-select';
import {
    WorkspaceWizard,
    type CommandState,
} from '../vehicle-workspace/wizard-kit';
import type { Intent, Person, TransportRecord } from './types';
import { Notice } from './ui';
export function DemandDialog({
    row,
    mode,
    start,
    onClose,
    onSaved,
}: {
    row?: TransportRecord;
    mode: 'create' | 'assess' | 'respond';
    start?: string;
    onClose: () => void;
    onSaved: () => void;
}) {
    const [step, setStep] = useState(0),
        [saved, setSaved] = useState('');
    const [clients, setClients] = useState<
            (Person & { can_request: boolean })[]
        >([]),
        [optionsError, setOptionsError] = useState('');
    const [initial] = useState(() => ({
        client: String(row?.client_id || ''),
        purpose: row?.purpose || '',
        destination: row?.destination || '',
        pickup: row?.pickup || '',
        start: row?.start ? toDatetimeLocal(row.start) : start || '',
        end: row?.end ? toDatetimeLocal(row.end) : '',
        seats: row?.required_seats ? String(row.required_seats) : '',
        wheelchair:
            row?.wheelchair_required === null || !row
                ? ''
                : String(row.wheelchair_required),
        escort: row ? String(row.escort_required) : '',
        returnTrip: row?.return_trip ?? true,
        equipment: row?.equipment_required.join('\n') || '',
        notes: row?.operational_notes || '',
    }));
    const [form, setForm] = useState(initial),
        [errors, setErrors] = useState<Record<string, string>>({});
    const command = useVehicleRecordCommand(isJsonObject);
    const update = <K extends keyof typeof form>(
        key: K,
        value: (typeof form)[K],
    ) => {
        setForm((f) => ({ ...f, [key]: value }));
        setErrors({});
    };
    useEffect(() => {
        if (row) return;
        const controller = new AbortController();
        fetch('/fleet-assets/transports/workspace/options', {
            headers: { Accept: 'application/json' },
            signal: controller.signal,
        })
            .then(async (response) => {
                if (!response.ok)
                    throw new Error(
                        'Permitted clients could not load. Close and reopen the request to retry.',
                    );
                const data = await response.json();
                setClients(
                    data.clients.filter(
                        (c: Person & { can_request: boolean }) => c.can_request,
                    ),
                );
            })
            .catch((error) => {
                if (!controller.signal.aborted) setOptionsError(error.message);
            });
        return () => controller.abort();
    }, [row]);
    const validate = () => {
        const found: Record<string, string> = {};
        if (!form.client) found.client = 'Choose a client.';
        if (
            !form.purpose.trim() ||
            !form.pickup.trim() ||
            !form.destination.trim()
        )
            found.route = 'Add the purpose, collection point and destination.';
        if (!form.start || !form.end || form.end <= form.start)
            found.window = 'Choose departure and a later expected return.';
        if (
            step > 0 &&
            (!form.seats ||
                Number(form.seats) < 2 ||
                !form.wheelchair ||
                !form.escort)
        )
            found.needs =
                'Confirm occupant count, accessibility and escort needs.';
        setErrors(found);
        return !Object.keys(found).length;
    };
    const submit = async () => {
        if (!validate()) {
            setStep(
                !form.client ||
                    !form.purpose.trim() ||
                    !form.pickup.trim() ||
                    !form.destination.trim() ||
                    !form.start ||
                    !form.end ||
                    form.end <= form.start
                    ? 0
                    : 1,
            );
            return;
        }
        const result = await command.submit(
            row
                ? `/fleet-assets/transports/requests/${row.id}/commands`
                : '/fleet-assets/transports/requests',
            {
                action: mode,
                expected_version: row?.version,
                client_id: Number(form.client),
                purpose: form.purpose,
                destination: form.destination,
                pickup_location: form.pickup,
                starts_local: form.start,
                ends_local: form.end,
                required_seats: Number(form.seats),
                wheelchair_required: form.wheelchair === 'true',
                escort_required: form.escort === 'true',
                return_trip: form.returnTrip,
                equipment_required: [
                    ...new Set(
                        form.equipment
                            .split('\n')
                            .map((s) => s.trim())
                            .filter(Boolean),
                    ),
                ],
                operational_notes: form.notes,
            },
        );
        if (result)
            setSaved(String(result.message || 'Transport request saved.'));
    };
    const allErrors = { ...errors, ...command.errors },
        title =
            mode === 'create'
                ? 'Request transport'
                : mode === 'assess'
                  ? 'Assess transport needs'
                  : 'Update requested information';
    return (
        <WorkspaceWizard
            title={title}
            description="Capture the passenger’s needs before choosing a vehicle."
            railIcon={Route}
            railSub={row?.reference || 'New transport request'}
            steps={[
                {
                    key: 'route',
                    label: 'Route & time',
                    blurb: 'Where and when',
                    icon: Route,
                },
                {
                    key: 'needs',
                    label: 'Passenger needs',
                    blurb: 'People, access and equipment',
                    icon: Users,
                },
                {
                    key: 'review',
                    label: 'Review',
                    blurb: 'Confirm the request',
                    icon: CheckCircle2,
                },
            ]}
            step={step}
            setStep={setStep}
            freeNavigation
            pct={Math.round(
                ([
                    form.client,
                    form.purpose.trim(),
                    form.pickup.trim(),
                    form.destination.trim(),
                    form.start,
                    form.end,
                    form.seats,
                    form.wheelchair,
                    form.escort,
                    form.equipment.trim(),
                    form.notes.trim(),
                ].filter(Boolean).length /
                    11) *
                    100,
            )}
            context={{
                name:
                    row?.person ||
                    clients.find((c) => String(c.id) === form.client)?.name ||
                    'Choose a passenger',
                detail: row?.site.name || 'Permitted client records',
            }}
            command={command}
            dirty={JSON.stringify(form) !== JSON.stringify(initial)}
            saved={!!saved}
            submitLabel={
                mode === 'assess'
                    ? 'Confirm needs · ready to plan'
                    : 'Save request'
            }
            onValidateStep={validate}
            onSubmit={submit}
            onClose={() => {
                if (saved) onSaved();
                onClose();
            }}
            onReload={() => {
                onSaved();
                onClose();
            }}
            success={
                <WizardSuccessPane
                    title="Transport request saved"
                    blurb={saved}
                    actions={
                        <Button
                            onClick={() => {
                                onSaved();
                                onClose();
                            }}
                        >
                            Done
                        </Button>
                    }
                />
            }
            errorKey={JSON.stringify(allErrors)}
        >
            <div className="tr-form">
                {optionsError && <Notice>{optionsError}</Notice>}
                {Object.keys(allErrors).length > 0 && (
                    <div role="alert" className="tr-errors">
                        {Object.entries(allErrors).map(([key, value]) => (
                            <p key={key}>{value}</p>
                        ))}
                    </div>
                )}
                {step === 0 && (
                    <>
                        {!row && (
                            <VehicleSearchSelect
                                label="Client"
                                value={form.client}
                                onChange={(v) => update('client', v)}
                                options={clients.map((c) => ({
                                    value: String(c.id),
                                    label: c.name,
                                }))}
                            />
                        )}
                        <label>
                            Purpose
                            <Input
                                value={form.purpose}
                                onChange={(e) =>
                                    update('purpose', e.target.value)
                                }
                            />
                        </label>
                        <div className="tr-plan-fields">
                            <label>
                                Collection point
                                <Input
                                    value={form.pickup}
                                    onChange={(e) =>
                                        update('pickup', e.target.value)
                                    }
                                />
                            </label>
                            <label>
                                Destination
                                <Input
                                    value={form.destination}
                                    onChange={(e) =>
                                        update('destination', e.target.value)
                                    }
                                />
                            </label>
                        </div>
                        <DateTimeField
                            id="demand-start"
                            label="Departure"
                            value={form.start}
                            onChange={(v) => update('start', v)}
                        />
                        <DateTimeField
                            id="demand-end"
                            label="Expected return"
                            value={form.end}
                            onChange={(v) => update('end', v)}
                        />
                        <label className="tr-check">
                            <input
                                type="checkbox"
                                checked={form.returnTrip}
                                onChange={(e) =>
                                    update('returnTrip', e.target.checked)
                                }
                            />
                            Passenger needs a return trip
                        </label>
                    </>
                )}
                {step === 1 && (
                    <>
                        <label>
                            Total occupants, including driver and escort
                            <Input
                                type="number"
                                min={2}
                                max={50}
                                value={form.seats}
                                onChange={(e) =>
                                    update('seats', e.target.value)
                                }
                            />
                        </label>
                        <VehicleSearchSelect
                            label="Wheelchair access"
                            value={form.wheelchair}
                            onChange={(v) => update('wheelchair', v)}
                            options={[
                                {
                                    value: 'true',
                                    label: 'Wheelchair access required',
                                },
                                {
                                    value: 'false',
                                    label: 'Standard seating meets the needs',
                                },
                            ]}
                        />
                        <VehicleSearchSelect
                            label="Escort needs"
                            value={form.escort}
                            onChange={(v) => update('escort', v)}
                            options={[
                                {
                                    value: 'true',
                                    label: 'An escort is required',
                                },
                                { value: 'false', label: 'No escort required' },
                            ]}
                        />
                        <label>
                            Equipment to take and receive back · one item per
                            line
                            <Textarea
                                value={form.equipment}
                                onChange={(e) =>
                                    update('equipment', e.target.value)
                                }
                            />
                        </label>
                        <label>
                            Operational instructions
                            <Textarea
                                value={form.notes}
                                onChange={(e) =>
                                    update('notes', e.target.value)
                                }
                                placeholder="Only the information needed for this transport. Clinical details remain in the client record."
                            />
                        </label>
                    </>
                )}
                {step === 2 && (
                    <>
                        <ReviewCard
                            icon={Route}
                            title="Route & time"
                            onEdit={() => setStep(0)}
                        >
                            <ReviewRow
                                label="Passenger"
                                value={
                                    row?.person ||
                                    clients.find(
                                        (c) => String(c.id) === form.client,
                                    )?.name
                                }
                            />
                            <ReviewRow
                                label="Route"
                                value={`${form.pickup} → ${form.destination}`}
                            />
                            <ReviewRow label="Purpose" value={form.purpose} />
                            <ReviewRow
                                label="Departure"
                                value={
                                    form.start
                                        ? localDateTimeLabel(form.start)
                                        : 'Choose a time'
                                }
                            />
                            <ReviewRow
                                label="Expected return"
                                value={
                                    form.end
                                        ? localDateTimeLabel(form.end)
                                        : 'Choose a time'
                                }
                            />
                        </ReviewCard>
                        <ReviewCard
                            icon={Users}
                            title="Passenger needs"
                            onEdit={() => setStep(1)}
                        >
                            <ReviewRow label="Occupants" value={form.seats} />
                            <ReviewRow
                                label="Access"
                                value={
                                    form.wheelchair === 'true'
                                        ? 'Wheelchair access'
                                        : form.wheelchair === 'false'
                                          ? 'Standard seating'
                                          : 'Needs assessment'
                                }
                            />
                            <ReviewRow
                                label="Escort"
                                value={
                                    form.escort === 'true'
                                        ? 'Required'
                                        : form.escort === 'false'
                                          ? 'Not required'
                                          : 'Needs assessment'
                                }
                            />
                            <ReviewRow
                                label="Equipment"
                                value={form.equipment || 'None requested'}
                            />
                            <ReviewRow
                                label="Instructions"
                                value={form.notes || 'None added'}
                            />
                        </ReviewCard>
                        <p className="tr-caption">
                            {mode === 'assess'
                                ? 'This confirms the assessed needs and places the request in the Planner queue.'
                                : 'A transport coordinator will assess this request before allocating a vehicle.'}
                        </p>
                        {form.equipment && (
                            <p>
                                Equipment:{' '}
                                {form.equipment.split('\n').join(', ')}
                            </p>
                        )}
                    </>
                )}
            </div>
        </WorkspaceWizard>
    );
}
export const intentTitles: Record<Intent, string> = {
    assess: 'Assess transport needs',
    information: 'Request more information',
    respond: 'Provide information',
    cancel: 'Cancel transport request',
    note: 'Add operational note',
    depart: 'Record departure',
    arrive: 'Record passenger arrival',
    account: 'Confirm passengers accounted for',
    complete: 'Complete passenger journey',
    receive_items: 'Receive missing items',
    store_keys: 'Receive and store keys',
    approve: 'Approve Fleet booking',
    decline: 'Decline Fleet booking',
    out: 'Check out vehicle',
    return: 'Record vehicle return',
    cancel_booking: 'Cancel Fleet booking',
};
export function ActionDialog({
    row,
    intent,
    onClose,
    onSaved,
}: {
    row: TransportRecord;
    intent: Intent;
    onClose: () => void;
    onSaved: () => void;
}) {
    const [step, setStep] = useState(0),
        [saved, setSaved] = useState('');
    const [initial] = useState({
        message: '',
        odometer: '',
        condition: '',
        room: row.key_return_room_id ? String(row.key_return_room_id) : '',
        keys: false,
        items: [] as string[],
        confirmed: false,
    });
    const [form, setForm] = useState(initial);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const command = useVehicleRecordCommand(isJsonObject);
    const update = <K extends keyof typeof form>(
        key: K,
        value: (typeof form)[K],
    ) => {
        setForm((f) => ({ ...f, [key]: value }));
        setErrors({});
    };
    const custody = intent === 'out' || intent === 'return';
    const source = [
        'approve',
        'decline',
        'out',
        'return',
        'cancel_booking',
    ].includes(intent);
    const validate = () => {
        const found: Record<string, string> = {};
        if (
            [
                'information',
                'note',
                'cancel',
                'decline',
                'cancel_booking',
            ].includes(intent) &&
            !form.message.trim()
        )
            found.message = 'Add the reason or details.';
        if (custody && (!form.odometer || !form.condition))
            found.condition =
                'Record the odometer and observed vehicle condition.';
        if (
            custody &&
            form.condition === 'Concern recorded' &&
            !form.message.trim()
        )
            found.message = 'Describe the observed concern.';
        if (intent === 'out' && !form.keys)
            found.keys =
                'Confirm the keys have been handed to the assigned driver.';
        if (intent === 'store_keys' && !form.room)
            found.room = 'Choose the actual site storage location.';
        if (intent === 'receive_items' && !form.items.length)
            found.items = 'Select the items you received.';
        if (step === 1 && !form.confirmed)
            found.confirm = 'Confirm the observation before saving.';
        setErrors(found);
        return !Object.keys(found).length;
    };
    const submit = async () => {
        if (!validate()) return;
        const operation = {
            approve: 'approve',
            decline: 'reject',
            out: 'checkout',
            return: 'return',
            cancel_booking: 'cancel',
        }[intent as 'approve'];
        const body = source
            ? {
                  expected_version: row.booking?.version,
                  transport_request_id: row.id,
                  readiness_reviewed: form.confirmed,
                  decision_notes: form.message,
                  rejection_reason: form.message,
                  reason: form.message,
                  ...(intent === 'out'
                      ? {
                            odometer_out: Number(form.odometer),
                            checkout_condition: form.condition,
                            checkout_notes: form.message,
                            keys_handed: form.keys,
                        }
                      : {}),
                  ...(intent === 'return'
                      ? {
                            odometer_in: Number(form.odometer),
                            condition_on_return: form.condition,
                            return_notes: form.message,
                            keys_received: form.keys,
                        }
                      : {}),
              }
            : {
                  action: intent,
                  expected_version: row.version,
                  message: form.message,
                  items: form.items,
                  site_room_id: form.room ? Number(form.room) : null,
              };
        const result = await command.submit(
            source
                ? `/fleet-assets/bookings/${row.booking!.id}/${operation}`
                : `/fleet-assets/transports/requests/${row.id}/commands`,
            body,
        );
        if (result) setSaved(String(result.message || 'Observation saved.'));
    };
    const description =
        intent === 'complete'
            ? 'Passenger return and required medication handoffs must be resolved. Vehicle return and item custody remain separate.'
            : intent === 'store_keys'
              ? 'Confirm the keys are physically at the selected location. Record the observation only after it happens.'
              : intent === 'return'
                ? 'Record the vehicle’s actual return. This does not complete the passenger journey or prove that keys have been stored.'
                : 'Review the current source record and confirm the change.';
    const allErrors = { ...errors, ...command.errors };
    const dirty = JSON.stringify(form) !== JSON.stringify(initial);
    if (intent === 'note' || intent === 'information')
        return (
            <SimpleTransportAction
                title={intentTitles[intent]}
                description={`${row.person} · ${row.reference} · ${row.site.name}`}
                value={form.message}
                onChange={(value) => update('message', value)}
                command={command}
                dirty={dirty}
                saved={saved}
                errors={allErrors}
                onSubmit={submit}
                onClose={() => {
                    if (saved) onSaved();
                    onClose();
                }}
                onReload={() => {
                    onClose();
                    onSaved();
                }}
            />
        );
    return (
        <WorkspaceWizard
            title={intentTitles[intent]}
            description={description}
            railIcon={KeyRound}
            railSub={row.reference}
            steps={[
                {
                    key: 'record',
                    label: 'Record details',
                    blurb: 'What actually happened',
                    icon: FileText,
                },
                {
                    key: 'review',
                    label: 'Confirm',
                    blurb: 'Review the source change',
                    icon: CheckCircle2,
                },
            ]}
            step={step}
            setStep={setStep}
            freeNavigation
            pct={step ? 100 : 50}
            context={{
                name: row.person,
                detail: `${row.site.name} · ${row.booking?.vehicle.name || row.purpose}`,
            }}
            command={command}
            dirty={dirty}
            saved={!!saved}
            submitLabel={intentTitles[intent]}
            onValidateStep={validate}
            onSubmit={submit}
            onClose={() => {
                if (saved) onSaved();
                onClose();
            }}
            onReload={() => {
                onSaved();
                onClose();
            }}
            errorKey={JSON.stringify(allErrors)}
            success={
                <WizardSuccessPane
                    title="Transport record updated"
                    blurb={saved}
                    actions={
                        <Button
                            onClick={() => {
                                onSaved();
                                onClose();
                            }}
                        >
                            Done
                        </Button>
                    }
                />
            }
        >
            <div className="tr-form">
                {Object.keys(allErrors).length > 0 && (
                    <div className="tr-errors" role="alert">
                        {Object.entries(allErrors).map(([key, value]) => (
                            <p key={key}>{value}</p>
                        ))}
                    </div>
                )}
                {step === 0 && (
                    <>
                        {custody && (
                            <>
                                <label>
                                    Observed odometer (km)
                                    <Input
                                        type="number"
                                        min={row.booking?.odometer_out || 0}
                                        value={form.odometer}
                                        onChange={(e) =>
                                            update('odometer', e.target.value)
                                        }
                                    />
                                </label>
                                <VehicleSearchSelect
                                    label="Vehicle condition"
                                    value={form.condition}
                                    onChange={(v) => update('condition', v)}
                                    options={[
                                        {
                                            value: 'No new concern',
                                            label: 'No new concern observed',
                                        },
                                        {
                                            value: 'Concern recorded',
                                            label: 'Concern recorded — describe below',
                                        },
                                    ]}
                                />
                                <label className="tr-check">
                                    <input
                                        type="checkbox"
                                        checked={form.keys}
                                        onChange={(e) =>
                                            update('keys', e.target.checked)
                                        }
                                    />
                                    {intent === 'out'
                                        ? 'Keys physically handed to the assigned driver'
                                        : 'Keys physically received (storage confirmed separately)'}
                                </label>
                            </>
                        )}
                        {intent === 'store_keys' && (
                            <VehicleSearchSelect
                                label="Actual key storage location"
                                value={form.room}
                                onChange={(v) => update('room', v)}
                                options={(row.rooms || []).map((r) => ({
                                    value: String(r.id),
                                    label: r.name,
                                }))}
                            />
                        )}
                        {intent === 'receive_items' &&
                            row.missing_items.map((item) => (
                                <label key={item} className="tr-check">
                                    <input
                                        type="checkbox"
                                        checked={form.items.includes(item)}
                                        onChange={(e) =>
                                            update(
                                                'items',
                                                e.target.checked
                                                    ? [...form.items, item]
                                                    : form.items.filter(
                                                          (i) => i !== item,
                                                      ),
                                            )
                                        }
                                    />
                                    {item} · physically received
                                </label>
                            ))}
                        <label>
                            Details / reason
                            <Textarea
                                value={form.message}
                                onChange={(e) =>
                                    update('message', e.target.value)
                                }
                            />
                        </label>
                        <Notice>{description}</Notice>
                    </>
                )}
                {step === 1 && (
                    <>
                        <ReviewCard
                            icon={KeyRound}
                            title={intentTitles[intent]}
                            onEdit={() => setStep(0)}
                        >
                            <ReviewRow label="Passenger" value={row.person} />
                            <ReviewRow
                                label="Details"
                                value={form.message || description}
                            />
                            {custody && (
                                <>
                                    <ReviewRow
                                        label="Odometer"
                                        value={`${form.odometer} km`}
                                    />
                                    <ReviewRow
                                        label="Condition"
                                        value={form.condition}
                                    />
                                    <ReviewRow
                                        label="Keys physically received / handed over"
                                        value={
                                            form.keys
                                                ? 'Confirmed'
                                                : 'Not confirmed'
                                        }
                                    />
                                </>
                            )}
                            {intent === 'store_keys' && (
                                <ReviewRow
                                    label="Actual storage location"
                                    value={
                                        row.rooms?.find(
                                            (room) =>
                                                String(room.id) === form.room,
                                        )?.name
                                    }
                                />
                            )}
                            {form.items.length > 0 && (
                                <ReviewRow
                                    label="Received items"
                                    value={form.items.join(', ')}
                                />
                            )}
                        </ReviewCard>
                        <label className="tr-check">
                            <input
                                type="checkbox"
                                checked={form.confirmed}
                                onChange={(e) =>
                                    update('confirmed', e.target.checked)
                                }
                            />
                            I confirm these details and understand the change
                            will be recorded under my name.
                        </label>
                    </>
                )}
            </div>
        </WorkspaceWizard>
    );
}

function SimpleTransportAction({
    title,
    description,
    value,
    onChange,
    command,
    dirty,
    saved,
    errors,
    onSubmit,
    onClose,
    onReload,
}: {
    title: string;
    description: string;
    value: string;
    onChange: (value: string) => void;
    command: CommandState;
    dirty: boolean;
    saved: string;
    errors: Record<string, string>;
    onSubmit: () => void;
    onClose: () => void;
    onReload: () => void;
}) {
    const [discard, setDiscard] = useState(false);
    const close = () => {
        if (command.processing) return;
        if (!saved && (dirty || command.uncertain)) setDiscard(true);
        else onClose();
    };
    return (
        <>
            <Dialog
                open
                onOpenChange={(open) => {
                    if (!open) close();
                }}
            >
                <DialogContent
                    className="max-h-[90vh] overflow-y-auto"
                    style={{
                        width: 'min(92vw, 720px)',
                        maxWidth: 'min(92vw, 720px)',
                    }}
                >
                    <DialogHeader>
                        <DialogTitle className="flex items-center gap-2">
                            <FileText className="size-4 text-primary" />
                            {title}
                        </DialogTitle>
                        <DialogDescription>{description}</DialogDescription>
                    </DialogHeader>
                    {saved ? (
                        <p
                            role="status"
                            className="flex items-center gap-2 text-status-success"
                        >
                            <CheckCircle2 className="size-5" />
                            {saved}
                        </p>
                    ) : (
                        <div className="tr-form mt-3">
                            {command.message && (
                                <Notice>{command.message}</Notice>
                            )}
                            {!!Object.keys(errors).length && (
                                <div role="alert" className="tr-errors">
                                    {Object.entries(errors).map(
                                        ([key, message]) => (
                                            <p key={key}>{message}</p>
                                        ),
                                    )}
                                </div>
                            )}
                            <label>
                                Details
                                <Textarea
                                    autoFocus
                                    value={value}
                                    disabled={command.locked}
                                    aria-invalid={!!errors.message}
                                    onChange={(event) =>
                                        onChange(event.target.value)
                                    }
                                />
                            </label>
                        </div>
                    )}
                    <DialogFooter className="mt-4">
                        <Button
                            variant="outline"
                            disabled={command.processing}
                            onClick={close}
                        >
                            {saved ? 'Close' : 'Cancel'}
                        </Button>
                        {!saved && (
                            <Button
                                disabled={command.processing}
                                onClick={
                                    command.requiresReload ? onReload : onSubmit
                                }
                            >
                                {command.processing && (
                                    <Loader2 className="size-4 animate-spin" />
                                )}
                                {command.requiresReload
                                    ? 'Review latest record'
                                    : command.uncertain
                                      ? 'Retry this submission'
                                      : title}
                            </Button>
                        )}
                    </DialogFooter>
                </DialogContent>
            </Dialog>
            <ConfirmDialog
                open={discard}
                onClose={() => setDiscard(false)}
                title="Discard this draft?"
                description="Unsent details will be removed."
                confirmText="Discard draft"
                cancelText="Keep editing"
                onConfirm={onClose}
            />
        </>
    );
}
