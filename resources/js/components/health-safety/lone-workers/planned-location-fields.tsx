import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Field, SelectInput } from '@/components/wizard/primitives';
import { useEffect, useRef, useState } from 'react';

export type PlannedLocationMethod =
    | 'manual'
    | 'selected_address'
    | 'site_address'
    | 'shift_address';
export type PlannedLocationSnapshot = {
    kind: 'planned';
    method: PlannedLocationMethod;
    description: string | null;
    lat: number | null;
    lng: number | null;
    recorded_at: string | null;
    recorded_by: { id: number; name: string } | null;
    source_site_id: number | null;
    source_shift_id: number | null;
};
export type PlannedLocationDraft = {
    method: '' | PlannedLocationMethod;
    description: string;
    latitude: string;
    longitude: string;
};
export type PlannedLocationInput = {
    method: PlannedLocationMethod;
    description?: string;
    latitude?: number;
    longitude?: number;
};
type PlaceSource = {
    address?: string | null;
    latitude?: number | null;
    longitude?: number | null;
};
type AddressResult = {
    display_name: string;
    lat: number | null;
    lng: number | null;
};

export function plannedLocationDraft(
    snapshot?: PlannedLocationSnapshot | null,
): PlannedLocationDraft {
    return {
        method: snapshot?.method ?? '',
        description: snapshot?.description ?? '',
        latitude: snapshot?.lat != null ? String(snapshot.lat) : '',
        longitude: snapshot?.lng != null ? String(snapshot.lng) : '',
    };
}
export function plannedLocationInput(
    draft: PlannedLocationDraft,
):
    | { ok: true; value: PlannedLocationInput | null }
    | { ok: false; error: string } {
    if (!draft.method) return { ok: true, value: null };
    if (draft.method === 'site_address' || draft.method === 'shift_address')
        return { ok: true, value: { method: draft.method } };
    const latitude = Number(draft.latitude),
        longitude = Number(draft.longitude);
    if (
        !draft.latitude.trim() ||
        !draft.longitude.trim() ||
        !Number.isFinite(latitude) ||
        !Number.isFinite(longitude) ||
        latitude < -90 ||
        latitude > 90 ||
        longitude < -180 ||
        longitude > 180
    )
        return {
            ok: false,
            error: 'Enter both planned coordinates: latitude between −90 and 90, and longitude between −180 and 180.',
        };
    return {
        ok: true,
        value: {
            method: draft.method,
            description: draft.description,
            latitude,
            longitude,
        },
    };
}

export function PlannedLocationFields({
    value,
    onChange,
    site,
    shift,
    canGeocode = false,
}: {
    value: PlannedLocationDraft;
    onChange: (value: PlannedLocationDraft) => void;
    site?: PlaceSource | null;
    shift?: PlaceSource | null;
    canGeocode?: boolean;
}) {
    const select = (method: PlannedLocationDraft['method']) => {
        const source =
            method === 'site_address'
                ? site
                : method === 'shift_address'
                  ? shift
                  : null;
        onChange(
            source
                ? {
                      method,
                      description: source.address ?? '',
                      latitude:
                          source.latitude != null
                              ? String(source.latitude)
                              : '',
                      longitude:
                          source.longitude != null
                              ? String(source.longitude)
                              : '',
                  }
                : { ...plannedLocationDraft(), method },
        );
    };
    const derived =
        value.method === 'site_address' || value.method === 'shift_address';
    return (
        <section aria-label="Planned location" className="space-y-4">
            <h3 className="font-semibold">Planned location</h3>
            <p className="text-subtle">
                Where the work is meant to happen. This isn’t a check-in or a
                live GPS location.
            </p>
            <Field label="How do you want to set it?">
                <SelectInput
                    frontline
                    value={value.method || 'none'}
                    onChange={(method) =>
                        select(
                            method === 'none'
                                ? ''
                                : (method as PlannedLocationDraft['method']),
                        )
                    }
                    placeholder="Choose how to set the planned location"
                    options={[
                        { value: 'none', label: 'No planned location' },
                        { value: 'manual', label: 'Enter coordinates' },
                        {
                            value: 'selected_address',
                            label: 'Find an address',
                            disabled: !canGeocode,
                        },
                        {
                            value: 'site_address',
                            label: 'Use site address',
                            disabled: !site?.address,
                        },
                        {
                            value: 'shift_address',
                            label: 'Use shift address',
                            disabled: !shift?.address,
                        },
                    ]}
                />
            </Field>
            {value.method && (
                <>
                    {value.method === 'selected_address' && canGeocode ? (
                        <PlannedAddressLookup
                            value={value.description}
                            onEdit={(description) =>
                                onChange({
                                    ...value,
                                    description,
                                    latitude: '',
                                    longitude: '',
                                })
                            }
                            onSelect={(address) =>
                                onChange({
                                    ...value,
                                    description: address.display_name.slice(
                                        0,
                                        500,
                                    ),
                                    latitude:
                                        address.lat != null
                                            ? String(address.lat)
                                            : '',
                                    longitude:
                                        address.lng != null
                                            ? String(address.lng)
                                            : '',
                                })
                            }
                        />
                    ) : (
                        <Field label="Planned address or description">
                            <Input
                                className="frontline-tap"
                                maxLength={500}
                                readOnly={derived}
                                value={value.description}
                                onChange={(event) =>
                                    onChange({
                                        ...value,
                                        method: 'manual',
                                        description: event.target.value,
                                    })
                                }
                            />
                        </Field>
                    )}
                    {!derived ? (
                        <div className="grid gap-4 sm:grid-cols-2">
                            <Field label="Planned latitude" required>
                                <Input
                                    className="frontline-tap"
                                    inputMode="decimal"
                                    type="number"
                                    min={-90}
                                    max={90}
                                    step="any"
                                    required
                                    value={value.latitude}
                                    onChange={(event) =>
                                        onChange({
                                            ...value,
                                            method: 'manual',
                                            latitude: event.target.value,
                                        })
                                    }
                                />
                            </Field>
                            <Field label="Planned longitude" required>
                                <Input
                                    className="frontline-tap"
                                    inputMode="decimal"
                                    type="number"
                                    min={-180}
                                    max={180}
                                    step="any"
                                    required
                                    value={value.longitude}
                                    onChange={(event) =>
                                        onChange({
                                            ...value,
                                            method: 'manual',
                                            longitude: event.target.value,
                                        })
                                    }
                                />
                            </Field>
                        </div>
                    ) : (
                        <>
                            <p className="text-sm">
                                {value.latitude !== '' && value.longitude !== ''
                                    ? 'Planned map point: ' +
                                      value.latitude +
                                      ', ' +
                                      value.longitude
                                    : 'Address only; no planned map point is recorded.'}
                            </p>
                            <Button
                                type="button"
                                variant="outline"
                                className="frontline-tap"
                                disabled={
                                    value.method === 'site_address'
                                        ? !site?.address
                                        : !shift?.address
                                }
                                onClick={() => select(value.method)}
                            >
                                Use current{' '}
                                {value.method === 'site_address'
                                    ? 'site'
                                    : 'shift'}{' '}
                                address
                            </Button>
                            <p className="text-subtle">
                                We’ll save the address as it is now.
                            </p>
                        </>
                    )}
                </>
            )}
        </section>
    );
}

function PlannedAddressLookup({
    value,
    onEdit,
    onSelect,
}: {
    value: string;
    onEdit: (value: string) => void;
    onSelect: (value: AddressResult) => void;
}) {
    const [busy, setBusy] = useState(false);
    const [results, setResults] = useState<AddressResult[] | null>(null);
    const [error, setError] = useState<string | null>(null);
    const request = useRef<AbortController | null>(null);
    useEffect(() => () => request.current?.abort(), []);
    const edit = (text: string) => {
        request.current?.abort();
        setBusy(false);
        setResults(null);
        setError(null);
        onEdit(text);
    };
    return (
        <div className="space-y-3">
            <Field label="Address to look up">
                <Input
                    className="frontline-tap"
                    maxLength={200}
                    autoComplete="off"
                    value={value}
                    onChange={(event) => edit(event.target.value)}
                />
            </Field>
            <Button
                type="button"
                variant="outline"
                className="frontline-tap"
                disabled={
                    busy || value.trim().length < 3 || value.trim().length > 200
                }
                onClick={async () => {
                    request.current?.abort();
                    const controller = new AbortController();
                    request.current = controller;
                    setBusy(true);
                    setError(null);
                    setResults(null);
                    try {
                        const response = await fetch(
                            '/health-safety/lone-workers/geocode/search?q=' +
                                encodeURIComponent(value.trim()),
                            {
                                signal: controller.signal,
                                headers: { Accept: 'application/json' },
                            },
                        );
                        if (!response.ok) throw new Error('Lookup unavailable');
                        const payload = await response.json();
                        if (!Array.isArray(payload.results))
                            throw new Error('Lookup response unavailable');
                        if (!controller.signal.aborted)
                            setResults(
                                payload.results.filter(
                                    (item: AddressResult) =>
                                        typeof item?.display_name ===
                                            'string' &&
                                        typeof item.lat === 'number' &&
                                        typeof item.lng === 'number' &&
                                        Number.isFinite(item.lat) &&
                                        Number.isFinite(item.lng) &&
                                        item.lat >= -90 &&
                                        item.lat <= 90 &&
                                        item.lng >= -180 &&
                                        item.lng <= 180,
                                ),
                            );
                    } catch {
                        if (!controller.signal.aborted)
                            setError(
                                'Address lookup is unavailable. Try again or choose Enter coordinates.',
                            );
                    } finally {
                        if (!controller.signal.aborted) setBusy(false);
                    }
                }}
            >
                {busy ? 'Finding address…' : 'Find address'}
            </Button>
            {error && (
                <p role="alert" className="text-sm text-status-critical">
                    {error}
                </p>
            )}
            {results && !results.length && (
                <p role="status" className="text-subtle">
                    No matching places found. Add a suburb or city, or choose
                    Enter coordinates.
                </p>
            )}
            {results && results.length > 0 && (
                <ul aria-label="Address suggestions" className="space-y-2">
                    {results.map((result, index) => (
                        <li key={index}>
                            <Button
                                className="frontline-tap h-auto w-full justify-start whitespace-normal"
                                variant="outline"
                                type="button"
                                onClick={() => {
                                    onSelect(result);
                                    setResults(null);
                                }}
                            >
                                Use {result.display_name}
                            </Button>
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}
