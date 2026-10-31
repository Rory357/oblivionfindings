import {
    act,
    cleanup,
    fireEvent,
    render,
    screen,
} from '@testing-library/react';
import { useState } from 'react';
import { afterEach, expect, it, vi } from 'vitest';
import {
    PlannedLocationFields,
    plannedLocationDraft,
    plannedLocationInput,
    type PlannedLocationDraft,
} from './planned-location-fields';

vi.mock('@/components/wizard/primitives', () => ({
    Field: ({
        label,
        children,
    }: {
        label: string;
        children: React.ReactNode;
    }) => (
        <label>
            {label}
            {children}
        </label>
    ),
    SelectInput: ({
        options,
        value,
        onChange,
        placeholder,
    }: {
        options: { value: string; label: string; disabled?: boolean }[];
        value: string;
        onChange: (value: string) => void;
        placeholder: string;
    }) => (
        <select
            aria-label={placeholder}
            value={value}
            onChange={(event) => onChange(event.target.value)}
        >
            {options.map((item) => (
                <option
                    key={item.value}
                    value={item.value}
                    disabled={item.disabled}
                >
                    {item.label}
                </option>
            ))}
        </select>
    ),
}));
afterEach(() => {
    cleanup();
    vi.unstubAllGlobals();
});
const zero: PlannedLocationDraft = {
    method: 'manual',
    description: 'Intended meeting point',
    latitude: '0',
    longitude: '0',
};

it('preserves a real zero coordinate pair and explicit clearing', () => {
    expect(plannedLocationInput(zero)).toEqual({
        ok: true,
        value: {
            method: 'manual',
            description: 'Intended meeting point',
            latitude: 0,
            longitude: 0,
        },
    });
    expect(plannedLocationInput(plannedLocationDraft())).toEqual({
        ok: true,
        value: null,
    });
    expect(
        plannedLocationDraft({
            kind: 'planned',
            method: 'manual',
            description: null,
            lat: 0,
            lng: 0,
            recorded_at: null,
            recorded_by: null,
            source_site_id: null,
            source_shift_id: null,
        }),
    ).toMatchObject({ latitude: '0', longitude: '0' });
});
it.each([
    { latitude: '' },
    { longitude: ' ' },
    { latitude: '91' },
    { longitude: '-181' },
    { latitude: 'NaN' },
])('rejects an incomplete or invalid pair: %s', (invalid) => {
    expect(plannedLocationInput({ ...zero, ...invalid }).ok).toBe(false);
});
it.each(['site_address', 'shift_address'] as const)(
    'leaves %s identity, address and coordinates to the approved server source',
    (method) => {
        expect(plannedLocationInput({ ...zero, method })).toEqual({
            ok: true,
            value: { method },
        });
    },
);
function Editor({
    method = 'selected_address',
    canGeocode = true,
}: {
    method?: PlannedLocationDraft['method'];
    canGeocode?: boolean;
}) {
    const [value, setValue] = useState({ ...plannedLocationDraft(), method });
    return (
        <PlannedLocationFields
            value={value}
            onChange={setValue}
            canGeocode={canGeocode}
            site={{ address: 'Site address', latitude: 0, longitude: 0 }}
            shift={{ address: 'Shift address' }}
        />
    );
}
const lookup = () =>
    fireEvent.click(screen.getByRole('button', { name: 'Find address' }));
const query = (value: string) =>
    fireEvent.change(
        screen.getByRole('textbox', { name: 'Address to look up' }),
        { target: { value } },
    );
it('retains a failed query for retry and records only the explicit selected result', async () => {
    const fetcher = vi
        .fn()
        .mockResolvedValueOnce({ ok: false })
        .mockResolvedValueOnce({
            ok: true,
            json: async () => ({
                results: [{ display_name: 'Selected point', lat: 0, lng: 0 }],
            }),
        });
    vi.stubGlobal('fetch', fetcher);
    render(<Editor />);
    query('Some address');
    lookup();
    expect(await screen.findByRole('alert')).toHaveTextContent(
        'lookup is unavailable',
    );
    expect(
        screen.getByRole('textbox', { name: 'Address to look up' }),
    ).toHaveValue('Some address');
    lookup();
    const choice = await screen.findByRole('button', {
        name: 'Use Selected point',
    });
    choice.focus();
    expect(choice).toHaveFocus();
    fireEvent.click(choice);
    expect(
        screen.getByRole('spinbutton', { name: 'Planned latitude' }),
    ).toHaveValue(0);
    expect(screen.getByRole('combobox')).toHaveValue('selected_address');
    fireEvent.change(
        screen.getByRole('spinbutton', { name: 'Planned longitude' }),
        { target: { value: '1' } },
    );
    expect(screen.getByRole('combobox')).toHaveValue('manual');
});
it('does not restore a stale result or coordinates after the query is edited', async () => {
    let resolve!: (value: unknown) => void;
    const fetcher = vi.fn().mockImplementation(
        () =>
            new Promise((done) => {
                resolve = done;
            }),
    );
    vi.stubGlobal('fetch', fetcher);
    render(<Editor />);
    query('Old place');
    lookup();
    query('New place');
    expect(fetcher.mock.calls[0][1].signal.aborted).toBe(true);
    await act(async () => {
        resolve({
            ok: true,
            json: async () => ({
                results: [{ display_name: 'Old answer', lat: -36, lng: 174 }],
            }),
        });
    });
    expect(
        screen.queryByRole('button', { name: 'Use Old answer' }),
    ).not.toBeInTheDocument();
    expect(
        screen.getByRole('textbox', { name: 'Address to look up' }),
    ).toHaveValue('New place');
    expect(
        screen.getByRole('spinbutton', { name: 'Planned latitude' }),
    ).toHaveValue(null);
});
it('distinguishes a successful empty lookup from transport failure', async () => {
    vi.stubGlobal(
        'fetch',
        vi
            .fn()
            .mockResolvedValue({
                ok: true,
                json: async () => ({ results: [] }),
            }),
    );
    render(<Editor />);
    query('No matching place');
    lookup();
    expect(await screen.findByRole('status')).toHaveTextContent(
        'No matching places found',
    );
    expect(screen.queryByRole('alert')).not.toBeInTheDocument();
});
it('allows an address-only shift plan without inferring GPS or expanding lookup permission', () => {
    render(<Editor method="" canGeocode={false} />);
    expect(
        screen.getByRole('option', { name: 'Find an address' }),
    ).toBeDisabled();
    fireEvent.change(screen.getByRole('combobox'), {
        target: { value: 'shift_address' },
    });
    expect(
        screen.getByRole('textbox', { name: 'Planned address or description' }),
    ).toHaveValue('Shift address');
    expect(
        screen.getByText('Address only; no planned map point is recorded.'),
    ).toBeInTheDocument();
    expect(screen.queryByRole('spinbutton')).not.toBeInTheDocument();
});
