import {
    act,
    cleanup,
    fireEvent,
    render,
    screen,
    waitFor,
} from '@testing-library/react';
import { afterEach, expect, it, vi } from 'vitest';
import ZoneAddressSearch from './zone-address-search';

afterEach(() => {
    cleanup();
    vi.unstubAllGlobals();
});
const props = {
    url: '/zones',
    fingerprint: 'current',
    onSelect: vi.fn(),
    onAccessEnded: vi.fn(),
};
const place = {
    display_name: 'Synthetic community centre',
    lat: -36.85,
    lng: 174.76,
};
const response = (results = [place]) => ({
    ok: true,
    status: 200,
    json: async () => ({ results }),
});
const query = () => screen.getByLabelText('Find an address or place');

it('searches only on explicit submission and selects an address by keyboard', async () => {
    const fetcher = vi.fn().mockResolvedValue(response());
    vi.stubGlobal('fetch', fetcher);
    const select = vi.fn();
    render(<ZoneAddressSearch {...props} onSelect={select} />);
    fireEvent.change(query(), { target: { value: 'Synthetic place' } });
    expect(fetcher).not.toHaveBeenCalled();
    fireEvent.keyDown(query(), { key: 'Enter' });
    const result = await screen.findByRole('button', {
        name: place.display_name,
    });
    fireEvent.keyDown(query(), { key: 'ArrowDown' });
    expect(result).toHaveFocus();
    fireEvent.click(result);
    expect(select).toHaveBeenCalledWith({
        point: { lat: place.lat, lng: place.lng },
        label: place.display_name,
    });
    expect(JSON.parse(fetcher.mock.calls[0][1].body)).toEqual({
        q: 'Synthetic place',
        access_fingerprint: 'current',
    });
    expect(fetcher.mock.calls[0][0]).toBe('/zones/address-search');
});

it('discards late results after editing the query and aborts on unmount', async () => {
    let resolve!: (value: ReturnType<typeof response>) => void;
    const fetcher = vi.fn().mockImplementation(
        () =>
            new Promise<ReturnType<typeof response>>((done) => {
                resolve = done;
            }),
    );
    vi.stubGlobal('fetch', fetcher);
    const view = render(<ZoneAddressSearch {...props} />);
    fireEvent.change(query(), { target: { value: 'Old place' } });
    fireEvent.keyDown(query(), { key: 'Enter' });
    fireEvent.change(query(), { target: { value: 'New place' } });
    expect(fetcher.mock.calls[0][1].signal.aborted).toBe(true);
    await act(async () => resolve(response()));
    expect(
        screen.queryByRole('button', { name: place.display_name }),
    ).not.toBeInTheDocument();
    fireEvent.keyDown(query(), { key: 'Enter' });
    view.unmount();
    expect(fetcher.mock.calls[1][1].signal.aborted).toBe(true);
});

it('distinguishes an empty search from a service error and permits retry', async () => {
    vi.stubGlobal(
        'fetch',
        vi
            .fn()
            .mockResolvedValueOnce(response([]))
            .mockResolvedValueOnce({
                ok: false,
                status: 503,
                json: async () => ({
                    message: 'Address search is temporarily unavailable.',
                }),
            }),
    );
    render(<ZoneAddressSearch {...props} />);
    fireEvent.change(query(), { target: { value: 'Missing place' } });
    fireEvent.click(screen.getByRole('button', { name: 'Search' }));
    await waitFor(() =>
        expect(screen.getByRole('status')).toHaveTextContent(
            'No addresses found',
        ),
    );
    fireEvent.click(screen.getByRole('button', { name: 'Search' }));
    expect(await screen.findByRole('alert')).toHaveTextContent(
        'temporarily unavailable',
    );
    expect(screen.getByRole('button', { name: 'Search' })).toBeEnabled();
});

it('ends access and clears results after permission loss', async () => {
    vi.stubGlobal(
        'fetch',
        vi.fn().mockResolvedValue({ ok: false, status: 403 }),
    );
    const end = vi.fn();
    render(<ZoneAddressSearch {...props} onAccessEnded={end} />);
    fireEvent.change(query(), { target: { value: 'Example address' } });
    fireEvent.keyDown(query(), { key: 'Enter' });
    await waitFor(() => expect(end).toHaveBeenCalledOnce());
    expect(screen.queryByRole('status')).not.toBeInTheDocument();
});
