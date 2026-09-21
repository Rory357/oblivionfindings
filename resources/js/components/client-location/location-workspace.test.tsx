import {
    act,
    cleanup,
    fireEvent,
    render,
    screen,
    waitFor,
} from '@testing-library/react';
import { afterEach, expect, it, vi } from 'vitest';
import LocationHistory from './location-history';

afterEach(() => {
    cleanup();
    vi.unstubAllGlobals();
});
const response = (locations: unknown[] = [], fingerprint = 'one') => ({
    ok: true,
    status: 200,
    json: async () => ({ locations, access_fingerprint: fingerprint }),
});
const props = {
    url: '/one/history',
    fingerprint: 'one',
    onAccessEnded: vi.fn(),
    onSelect: vi.fn(),
};

it('distinguishes history failure from an empty result and retries the failed range', async () => {
    const fetchMock = vi
        .fn()
        .mockRejectedValueOnce(new Error('Offline'))
        .mockResolvedValueOnce(response());
    vi.stubGlobal('fetch', fetchMock);
    render(<LocationHistory {...props} />);
    fireEvent.click(screen.getByRole('button', { name: 'Show observations' }));
    expect(await screen.findByRole('alert')).toHaveTextContent('Offline');
    expect(
        screen.queryByText('No observations in this range'),
    ).not.toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: 'Retry this range' }));
    expect(
        await screen.findByText('No observations in this range'),
    ).toBeVisible();
    expect(fetchMock.mock.calls[0][0]).toBe(fetchMock.mock.calls[1][0]);
});

it('ignores old history when the client route changes with a request pending', async () => {
    let resolve!: (value: unknown) => void;
    vi.stubGlobal(
        'fetch',
        vi.fn().mockReturnValue(
            new Promise((done) => {
                resolve = done;
            }),
        ),
    );
    const { rerender } = render(<LocationHistory {...props} />);
    fireEvent.click(screen.getByRole('button', { name: 'Show observations' }));
    rerender(
        <LocationHistory {...props} url="/two/history" fingerprint="two" />,
    );
    await act(async () => {
        resolve(
            response([
                {
                    lat: 1,
                    lng: 2,
                    timestamp: '2026-09-21T00:00:00Z',
                    display_location: 'Old private location',
                },
            ]),
        );
    });
    expect(screen.queryByText('Old private location')).not.toBeInTheDocument();
});

it('ends access when a history response carries a different assignment', async () => {
    const ended = vi.fn();
    vi.stubGlobal(
        'fetch',
        vi
            .fn()
            .mockResolvedValue(
                response(
                    [{ lat: 1, lng: 2, timestamp: '2026-09-21T00:00:00Z' }],
                    'replacement',
                ),
            ),
    );
    render(<LocationHistory {...props} onAccessEnded={ended} />);
    fireEvent.click(screen.getByRole('button', { name: 'Show observations' }));
    await waitFor(() => expect(ended).toHaveBeenCalledOnce());
    expect(screen.queryByText('View on map')).not.toBeInTheDocument();
});
