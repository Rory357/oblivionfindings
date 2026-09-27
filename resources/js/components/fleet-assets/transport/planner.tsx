import { ConfirmDialog } from '@/components/confirm-dialog';
import {
    DateTimeField,
    localDateTimeLabel,
} from '@/components/fleet-assets/maintenance/date-time-field';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { toDatetimeLocal } from '@/lib/datetime';
import {
    ArrowLeft,
    ArrowRight,
    CalendarDays,
    Car,
    CheckCircle2,
    KeyRound,
    Loader2,
    Route,
    Users,
} from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import {
    isJsonObject,
    useVehicleRecordCommand,
} from '../vehicle-workspace/record-command';
import { VehicleSearchSelect } from '../vehicle-workspace/search-select';
import type { Person, PlanOptions, TransportRecord } from './types';
import { Empty, Notice, Panel, Stage } from './ui';
type Props = {
    records: TransportRecord[];
    canManage: boolean;
    onSaved: () => void;
    onCalendar: () => void;
    selectedId?: number;
    onDirty: (dirty: boolean) => void;
};
export function TransportPlanner({
    records,
    canManage,
    onSaved,
    onCalendar,
    selectedId,
    onDirty,
}: Props) {
    const [selected, setSelected] = useState<number | null>(
        selectedId || records[0]?.id || null,
    );
    const [dirty, setDirty] = useState(false),
        [pending, setPending] = useState<number | null>(null);
    const row = records.find((r) => r.id === selected);
    const choose = (id: number) => {
        if (dirty) setPending(id);
        else setSelected(id);
    };
    const setDraft = (value: boolean) => {
        setDirty(value);
        onDirty(value);
    };
    if (!records.length) return <Empty />;
    return (
        <div className="tr-builder">
            <Panel
                title="Choose a request"
                icon={Route}
                className="tr-plan-queue"
            >
                {records.map((r) => (
                    // eslint-disable-next-line no-restricted-syntax -- This complete selector card or shared tab uses its canonical layout styles.
                    <button
                        key={r.id}
                        aria-pressed={r.id === selected}
                        onClick={() => choose(r.id)}
                    >
                        <small>
                            {r.reference} · {r.site.name}
                        </small>
                        <strong>{r.person}</strong>
                        <small>
                            {localDateTimeLabel(
                                toDatetimeLocal(r.booking?.start || r.start),
                            )}
                        </small>
                        <Stage row={r} />
                    </button>
                ))}
            </Panel>
            <div>
                {row ? (
                    <PlanBuilder
                        key={`${row.id}-${row.version}`}
                        row={row}
                        canManage={canManage}
                        onDirty={setDraft}
                        onSaved={() => {
                            setDraft(false);
                            onSaved();
                        }}
                        onCalendar={onCalendar}
                    />
                ) : (
                    <Notice>Choose a request to begin.</Notice>
                )}
            </div>
            <ConfirmDialog
                open={pending !== null}
                onClose={() => setPending(null)}
                title="Leave this draft?"
                description="Your unsaved plan will be discarded. No Fleet booking has been changed."
                confirmText="Discard draft"
                onConfirm={() => {
                    setDraft(false);
                    setSelected(pending);
                    setPending(null);
                }}
            />
        </div>
    );
}
function PlanBuilder({
    row,
    canManage,
    onSaved,
    onCalendar,
    onDirty,
}: Omit<Props, 'records' | 'selectedId'> & { row: TransportRecord }) {
    const [peopleSearch, setPeopleSearch] = useState('');
    const [step, setStep] = useState(0),
        [query, setQuery] = useState('');
    const [form, setForm] = useState(() => ({
        start: toDatetimeLocal(row.booking?.start || row.start),
        end: toDatetimeLocal(row.booking?.end || row.end || ''),
        asset: row.booking ? String(row.booking.vehicle.id) : '',
        driver: row.booking ? String(row.booking.driver.id) : '',
        escort: row.escort ? String(row.escort.id) : '',
        pickupRoom: row.key_pickup_room_id
            ? String(row.key_pickup_room_id)
            : '',
        returnRoom: row.key_return_room_id
            ? String(row.key_return_room_id)
            : '',
        arrangement: row.key_delivery_arrangement || '',
        route: row.booking?.approval_route || 'required',
        reason: '',
        ready: false,
    }));
    const [options, setOptions] = useState<PlanOptions | null>(null),
        [loading, setLoading] = useState(false),
        [loadError, setLoadError] = useState('');
    const [refresh, setRefresh] = useState(0),
        [errors, setErrors] = useState<Record<string, string>>({});
    const command = useVehicleRecordCommand(isJsonObject);
    const [saved, setSaved] = useState('');
    const update = <K extends keyof typeof form>(
        key: K,
        value: (typeof form)[K],
    ) => {
        setForm((f) => ({
            ...f,
            [key]: value,
            ...(key === 'asset' ? { driver: '', escort: '' } : {}),
        }));
        onDirty(true);
        setErrors({});
    };
    useEffect(() => {
        const controller = new AbortController();
        setLoading(true);
        const timer = window.setTimeout(async () => {
            setLoading(true);
            setLoadError('');
            try {
                const params = new URLSearchParams({
                    transport_request_id: String(row.id),
                    starts_local: form.start,
                    ends_local: form.end,
                    ...(form.asset ? { asset_id: form.asset } : {}),
                    ...(form.driver ? { driver_user_id: form.driver } : {}),
                    ...(form.escort ? { escort_user_id: form.escort } : {}),
                    ...(peopleSearch ? { search: peopleSearch } : {}),
                });
                const response = await fetch(
                    `/fleet-assets/transports/workspace/options?${params}`,
                    {
                        signal: controller.signal,
                        headers: { Accept: 'application/json' },
                        cache: 'no-store',
                    },
                );
                const result = await response.json();
                if (!response.ok)
                    throw new Error(
                        result.message ||
                            'Available vehicles could not be loaded.',
                    );
                setOptions(result);
            } catch (error) {
                if (!controller.signal.aborted) {
                    setOptions(null);
                    setLoadError(
                        error instanceof Error
                            ? error.message
                            : 'Could not check availability.',
                    );
                }
            } finally {
                if (!controller.signal.aborted) setLoading(false);
            }
        }, 250);
        return () => {
            controller.abort();
            clearTimeout(timer);
        };
    }, [
        row.id,
        form.start,
        form.end,
        form.asset,
        form.driver,
        form.escort,
        peopleSearch,
        refresh,
    ]);
    const vehicle = options?.vehicles.find((v) => String(v.id) === form.asset);
    const people = vehicle?.staff || [];
    const choose = (
        label: string,
        value: string,
        choices: Person[],
        onChange: (value: string) => void,
        remote = false,
    ) => (
        <div>
            <label className="mb-2 block text-xs font-semibold">{label}</label>
            <VehicleSearchSelect
                label={label}
                value={value}
                options={choices.map((p) => ({
                    value: String(p.id),
                    label: p.name,
                }))}
                onChange={onChange}
                onSearchChange={remote ? setPeopleSearch : undefined}
            />
        </div>
    );
    const validate = () => {
        const found: Record<string, string> = {};
        if (loading || loadError || !options)
            found.availability =
                'Wait for the current availability check, or retry if it failed.';
        if (!form.start || !form.end || form.end <= form.start)
            found.window = 'Choose a departure and later expected return.';
        if (!vehicle?.fits)
            found.vehicle = 'Select a vehicle that meets the assessed needs.';
        if (step >= 1) {
            if (!form.driver) found.driver = 'Choose the driver.';
            if (
                row.escort_required &&
                (!form.escort || form.escort === form.driver)
            )
                found.escort = 'Choose a different worker as escort.';
            if (
                !form.pickupRoom ||
                !form.returnRoom ||
                !form.arrangement.trim()
            )
                found.keys =
                    'Choose both key locations and describe who collects and returns them.';
        }
        if (step === 2 && !form.ready)
            found.ready = 'Confirm you have reviewed the complete plan.';
        if (step === 2 && row.booking && !form.reason.trim())
            found.reason = 'Explain this change to the booking.';
        if (step === 2 && form.route === 'not_required' && !form.reason.trim())
            found.reason =
                'Record the authority reason for approval not required.';
        setErrors(found);
        return !Object.keys(found).length;
    };
    const save = async () => {
        if (!validate()) return;
        const result = await command.submit(
            row.booking
                ? `/fleet-assets/bookings/${row.booking.id}`
                : '/fleet-assets/bookings',
            {
                transport_request_id: row.id,
                transport_expected_version: row.version,
                client_id: row.client_id,
                expected_version: row.booking?.version,
                asset_id: Number(form.asset),
                starts_local: form.start,
                ends_local: form.end,
                purpose: row.booking ? row.booking.purpose : row.purpose,
                destination: row.booking
                    ? row.booking.destination
                    : row.destination,
                passengers: row.booking
                    ? row.booking.passengers
                    : row.required_seats,
                notes: row.booking?.notes ?? null,
                pickup_site_id: row.site.id,
                return_site_id: row.site.id,
                driver_user_id: Number(form.driver),
                escort_user_id: form.escort ? Number(form.escort) : null,
                key_pickup_room_id: Number(form.pickupRoom),
                key_return_room_id: Number(form.returnRoom),
                key_delivery_arrangement: form.arrangement,
                pickup_arrangement: row.booking
                    ? row.booking.pickup_arrangement
                    : form.arrangement,
                approval_route: form.route,
                approval_not_required_reason: form.reason || null,
                readiness_acknowledged: form.ready,
                reason: form.reason || null,
            },
            { method: row.booking ? 'PUT' : 'POST' },
        );
        if (result) {
            setSaved(
                typeof result.message === 'string'
                    ? result.message
                    : 'Plan saved.',
            );
            onDirty(false);
        }
    };
    const allErrors = useMemo(
        () => ({ ...errors, ...command.errors }),
        [errors, command.errors],
    );
    if (saved)
        return (
            <Panel title="Plan saved to Fleet" icon={CheckCircle2}>
                <Notice>{saved}</Notice>
                <p className="tr-caption my-4">
                    All Transport views use the same booking. Approval and
                    departure checks follow the current Fleet rules.
                </p>
                <Button onClick={onSaved}>
                    Return to planner
                    <ArrowRight className="size-4" />
                </Button>
                <Button variant="outline" className="ml-2" asChild>
                    <a href={row.links.request}>
                        Open saved transport
                        <ArrowRight className="size-4" />
                    </a>
                </Button>
            </Panel>
        );
    return (
        <div className="tr-plan-main">
            <div className="tr-steps" aria-label="Plan builder steps">
                {['Vehicle & time', 'People & keys', 'Review & save'].map(
                    (label, i) => (
                        // eslint-disable-next-line no-restricted-syntax -- This complete selector card or shared tab uses its canonical layout styles.
                        <button
                            key={label}
                            aria-current={step === i ? 'step' : undefined}
                            onClick={() => {
                                if (i < step || (i === step + 1 && validate()))
                                    setStep(i);
                            }}
                        >
                            <b>{i < step ? '✓' : i + 1}</b>
                            {label}
                        </button>
                    ),
                )}
            </div>
            <Panel
                title={
                    [
                        'Choose the vehicle and time',
                        'Set up the people and keys',
                        'Review the whole plan',
                    ][step]
                }
                icon={[Car, Users, CheckCircle2][step]}
                action={
                    <Button variant="ghost" size="sm" onClick={onCalendar}>
                        <CalendarDays className="size-4" />
                        Calendar
                    </Button>
                }
            >
                <div className="tr-plan-context">
                    <small>
                        {row.reference} · {row.site.name}
                    </small>
                    <h3>{row.person}</h3>
                    <p>
                        {row.pickup}{' '}
                        <ArrowRight className="mx-2 inline size-4 text-primary" />
                        {row.destination}
                    </p>
                    <p className="tr-caption mt-2">
                        {row.required_seats} occupants including staff ·{' '}
                        {row.wheelchair_required
                            ? 'Wheelchair access required'
                            : 'Standard seating'}{' '}
                        ·{' '}
                        {row.escort_required
                            ? 'Escort required'
                            : 'No escort required'}
                    </p>
                </div>
                {!canManage && (
                    <Notice>
                        A transport coordinator must save this plan.
                    </Notice>
                )}
                {loadError && (
                    <Notice>
                        {loadError}
                        <Button
                            variant="outline"
                            onClick={() => setRefresh((v) => v + 1)}
                        >
                            Retry availability
                        </Button>
                    </Notice>
                )}
                {command.message && (
                    <Notice>
                        {command.message}
                        {command.requiresReload && (
                            <Button variant="outline" onClick={onSaved}>
                                Reload latest record
                            </Button>
                        )}
                    </Notice>
                )}
                {!!Object.keys(allErrors).length && (
                    <div className="tr-errors" role="alert">
                        {Object.entries(allErrors).map(([key, message]) => (
                            <p key={key}>{message}</p>
                        ))}
                    </div>
                )}
                <fieldset
                    disabled={command.locked || !canManage}
                    className="tr-form mt-4"
                >
                    {step === 0 && (
                        <>
                            <div className="tr-plan-fields">
                                <DateTimeField
                                    id="transport-start"
                                    label="Departure"
                                    value={form.start}
                                    onChange={(v) => update('start', v)}
                                />
                                <DateTimeField
                                    id="transport-end"
                                    label="Expected return"
                                    value={form.end}
                                    onChange={(v) => update('end', v)}
                                />
                            </div>
                            <Input
                                aria-label="Search vehicles"
                                placeholder="Search vehicle or registration…"
                                value={query}
                                onChange={(e) => setQuery(e.target.value)}
                            />
                            {loading && (
                                <p className="tr-caption flex gap-2">
                                    <Loader2 className="size-4 animate-spin" />
                                    Checking the full transport window…
                                </p>
                            )}
                            <div className="tr-vehicle-grid">
                                {options?.vehicles
                                    .filter((v) =>
                                        `${v.name} ${v.registration}`
                                            .toLowerCase()
                                            .includes(query.toLowerCase()),
                                    )
                                    .map((v) => (
                                        // eslint-disable-next-line no-restricted-syntax -- This complete selector card or shared tab uses its canonical layout styles.
                                        <button
                                            type="button"
                                            key={v.id}
                                            className="tr-vehicle-choice"
                                            aria-pressed={
                                                form.asset === String(v.id)
                                            }
                                            disabled={!v.fits || loading}
                                            onClick={() =>
                                                update('asset', String(v.id))
                                            }
                                        >
                                            <Car className="size-5 text-primary" />
                                            <strong>{v.name}</strong>
                                            <small>
                                                {v.registration} ·{' '}
                                                {v.seats || 'Capacity unknown'}{' '}
                                                seats ·{' '}
                                                {v.wheelchair
                                                    ? 'Wheelchair access'
                                                    : 'Standard seating'}
                                            </small>
                                            <em>
                                                {!v.fits
                                                    ? 'Does not meet assessed needs'
                                                    : v.readiness.can_proceed
                                                      ? 'Current checks passed'
                                                      : v.readiness.reasons
                                                            .filter(
                                                                (r) =>
                                                                    r.blocks_decision,
                                                            )
                                                            .slice(0, 2)
                                                            .map(
                                                                (r) =>
                                                                    r.message,
                                                            )
                                                            .join(' ') ||
                                                        'Further checks required'}
                                            </em>
                                        </button>
                                    ))}
                            </div>
                            {options && !options.vehicles.length && (
                                <Notice>
                                    No permitted vehicle is available at this
                                    request’s site.
                                </Notice>
                            )}
                        </>
                    )}
                    {step === 1 && (
                        <>
                            <div className="tr-plan-fields">
                                {choose(
                                    'Driver',
                                    form.driver,
                                    people,
                                    (v) => update('driver', v),
                                    true,
                                )}
                                {choose(
                                    row.escort_required
                                        ? 'Required escort'
                                        : 'Escort (optional)',
                                    form.escort,
                                    people.filter(
                                        (p) => String(p.id) !== form.driver,
                                    ),
                                    (v) => update('escort', v),
                                    true,
                                )}
                            </div>
                            <Panel
                                title="Where do the keys go?"
                                icon={KeyRound}
                            >
                                <div className="tr-plan-fields">
                                    {choose(
                                        'Key collection location',
                                        form.pickupRoom,
                                        options?.rooms || [],
                                        (v) => update('pickupRoom', v),
                                    )}
                                    {choose(
                                        'Key return location',
                                        form.returnRoom,
                                        options?.rooms || [],
                                        (v) => update('returnRoom', v),
                                    )}
                                </div>
                                <label className="mt-4 block text-xs font-semibold">
                                    Collection and return arrangement
                                    <Textarea
                                        className="mt-2"
                                        value={form.arrangement}
                                        onChange={(e) =>
                                            update(
                                                'arrangement',
                                                e.target.value,
                                            )
                                        }
                                        placeholder="Who collects the keys, where they meet, and who puts them back."
                                    />
                                </label>
                                <p className="tr-caption mt-3">
                                    Locations come from this Site. Receipt and
                                    physical storage are confirmed separately
                                    after the trip.
                                </p>
                            </Panel>
                            {row.equipment_required.length > 0 && (
                                <Notice>
                                    Required equipment:{' '}
                                    {row.equipment_required.join(', ')}. Confirm
                                    the items are available before departure.
                                </Notice>
                            )}
                        </>
                    )}
                    {step === 2 && (
                        <>
                            <div className="tr-fact">
                                <span>Vehicle</span>
                                <strong>{vehicle?.name}</strong>
                            </div>
                            <div className="tr-fact">
                                <span>Departure</span>
                                <strong>
                                    {localDateTimeLabel(form.start)}
                                </strong>
                            </div>
                            <div className="tr-fact">
                                <span>Expected return</span>
                                <strong>{localDateTimeLabel(form.end)}</strong>
                            </div>
                            <div className="tr-fact">
                                <span>Driver / escort</span>
                                <strong>
                                    {
                                        people.find(
                                            (p) => String(p.id) === form.driver,
                                        )?.name
                                    }{' '}
                                    /{' '}
                                    {people.find(
                                        (p) => String(p.id) === form.escort,
                                    )?.name || 'Not required'}
                                </strong>
                            </div>
                            <div className="tr-fact">
                                <span>Keys</span>
                                <strong>{form.arrangement}</strong>
                            </div>
                            {!row.booking && (
                                <VehicleSearchSelect
                                    label="Approval route"
                                    value={form.route}
                                    onChange={(v) => update('route', v)}
                                    options={[
                                        {
                                            value: 'required',
                                            label: 'Approval required',
                                        },
                                        {
                                            value: 'not_required',
                                            label: 'Approval not required — record authority',
                                        },
                                    ]}
                                />
                            )}
                            {(row.booking || form.route === 'not_required') && (
                                <label>
                                    Reason / authority
                                    <Textarea
                                        value={form.reason}
                                        onChange={(e) =>
                                            update('reason', e.target.value)
                                        }
                                    />
                                </label>
                            )}
                            <Notice>
                                {vehicle?.readiness.reasons
                                    .filter((r) => r.blocks_decision)
                                    .map((r) => r.message)
                                    .join(' ') ||
                                    'Readiness will be checked again when saving and before departure.'}
                            </Notice>
                            <label className="tr-check">
                                <input
                                    type="checkbox"
                                    checked={form.ready}
                                    onChange={(e) =>
                                        update('ready', e.target.checked)
                                    }
                                />
                                I reviewed the vehicle, full time window, staff
                                and key arrangements.
                            </label>
                        </>
                    )}
                </fieldset>
                <div className="tr-plan-footer">
                    <Button
                        variant="outline"
                        disabled={step === 0 || command.locked}
                        onClick={() => setStep((s) => s - 1)}
                    >
                        <ArrowLeft className="size-4" />
                        Back
                    </Button>
                    <span className="tr-caption">Step {step + 1} of 3</span>
                    {step < 2 ? (
                        <Button
                            disabled={loading || !options}
                            onClick={() => {
                                if (validate()) setStep((s) => s + 1);
                            }}
                        >
                            Continue
                            <ArrowRight className="size-4" />
                        </Button>
                    ) : (
                        <Button
                            disabled={
                                !canManage ||
                                command.processing ||
                                command.requiresReload ||
                                loading
                            }
                            onClick={save}
                        >
                            {command.processing
                                ? 'Saving…'
                                : command.uncertain
                                  ? 'Retry same save'
                                  : 'Save transport plan'}
                        </Button>
                    )}
                </div>
            </Panel>
        </div>
    );
}
