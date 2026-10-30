import {
    act,
    cleanup,
    fireEvent,
    render,
    screen,
    waitFor,
} from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { AddressSearch } from './address-search';
import { request } from './api';

vi.mock('./api', async (original) => ({
    ...(await original<typeof import('./api')>()),
    request: vi.fn(),
}));
const capabilities = {
    enabled: true,
    autocomplete: false,
    attribution: '© OpenStreetMap contributors · Nominatim',
    attribution_url: 'https://www.openstreetmap.org/copyright',
};
const hit = {
    display_name: 'Parliament, Wellington, New Zealand',
    lat: -41.2784,
    lng: 174.7767,
};
beforeEach(() => vi.mocked(request).mockReset());
afterEach(cleanup);

describe('submitted OpenStreetMap address lookup', () => {
    it('waits for explicit submission and fills the selected result without another provider request', async () => {
        vi.mocked(request).mockResolvedValue({ results: [hit] });
        const onSelect = vi.fn();
        render(
            <AddressSearch capabilities={capabilities} onSelect={onSelect} />,
        );
        fireEvent.change(screen.getByLabelText('Search an address'), {
            target: { value: 'Parliament Wellington' },
        });
        await act(async () => {
            await new Promise((resolve) => setTimeout(resolve, 400));
        });
        expect(request).not.toHaveBeenCalled();
        fireEvent.click(
            screen.getByRole('button', { name: 'Search addresses' }),
        );
        fireEvent.click(
            await screen.findByRole('button', { name: hit.display_name }),
        );
        expect(onSelect).toHaveBeenCalledWith(hit);
        expect(screen.getByLabelText('Search an address')).toHaveValue(
            hit.display_name,
        );
        expect(
            screen.getByRole('link', { name: capabilities.attribution }),
        ).toHaveAttribute('href', capabilities.attribution_url);
        await act(async () => {
            await new Promise((resolve) => setTimeout(resolve, 400));
        });
        expect(request).toHaveBeenCalledTimes(1);
    });

    it('ignores an obsolete result after the query changes and supports Enter submission', async () => {
        let resolve!: (data: unknown) => void;
        vi.mocked(request).mockReturnValueOnce(
            new Promise((yes) => {
                resolve = yes;
            }),
        );
        render(
            <AddressSearch capabilities={capabilities} onSelect={vi.fn()} />,
        );
        const input = screen.getByLabelText('Search an address');
        fireEvent.change(input, { target: { value: 'Parliament' } });
        fireEvent.keyDown(input, { key: 'Enter' });
        await waitFor(() => expect(request).toHaveBeenCalledTimes(1));
        fireEvent.change(input, { target: { value: 'Library' } });
        await act(async () => resolve({ results: [hit] }));
        expect(
            screen.queryByRole('button', { name: hit.display_name }),
        ).not.toBeInTheDocument();
        expect(request).toHaveBeenCalledTimes(1);
        vi.mocked(request).mockResolvedValueOnce({ results: [] });
        fireEvent.keyDown(input, { key: 'Enter' });
        expect(
            await screen.findByText(/No address matches/),
        ).toBeInTheDocument();
        expect(request).toHaveBeenLastCalledWith(
            expect.stringContaining('/address-search'),
            'POST',
            { q: 'Library' },
            expect.any(AbortSignal),
        );
    });
});
