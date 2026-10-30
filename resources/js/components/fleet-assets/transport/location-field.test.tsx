import '@testing-library/jest-dom/vitest';
import {
    act,
    cleanup,
    fireEvent,
    render,
    screen,
} from '@testing-library/react';
import { afterEach, expect, it, vi } from 'vitest';
import { TransportLocationField } from './location-field';

afterEach(() => {
    cleanup();
    vi.unstubAllGlobals();
});
const props = {
    label: 'Collection point',
    value: 'Keep this location',
    clientId: '7',
    onChange: vi.fn(),
    locations: [{ id: 1, name: 'Aurora House', address: '10 Example Road' }],
};
const open = () =>
    fireEvent.click(screen.getByRole('combobox', { name: 'Collection point' }));
const query = () =>
    screen.getByRole('combobox', { name: 'Search collection point' });
const reply = (name: string) => ({
    ok: true,
    status: 200,
    json: async () => ({ results: [{ display_name: name }] }),
});

it('filters saved sites without external lookup and preserves selection on Escape', () => {
    const fetcher = vi.fn();
    vi.stubGlobal('fetch', fetcher);
    const onChange = vi.fn();
    render(<TransportLocationField {...props} onChange={onChange} />);
    open();
    fireEvent.change(query(), { target: { value: 'Example' } });
    expect(
        screen.getByRole('option', { name: /Aurora House/ }),
    ).toBeInTheDocument();
    expect(fetcher).not.toHaveBeenCalled();
    fireEvent.keyDown(query(), { key: 'Escape' });
    expect(onChange).not.toHaveBeenCalled();
    expect(
        screen.getByRole('combobox', { name: 'Collection point' }),
    ).toHaveTextContent('Keep this location');
});

it('only explicitly searches and selecting a result fills the full address', async () => {
    const fetcher = vi
        .fn()
        .mockResolvedValue(reply('Public library, Auckland'));
    vi.stubGlobal('fetch', fetcher);
    const onChange = vi.fn();
    render(<TransportLocationField {...props} onChange={onChange} />);
    open();
    fireEvent.change(query(), { target: { value: 'Public library' } });
    expect(fetcher).not.toHaveBeenCalled();
    fireEvent.click(
        screen.getByRole('button', { name: 'Search OpenStreetMap' }),
    );
    fireEvent.click(
        await screen.findByRole('option', { name: 'Public library, Auckland' }),
    );
    expect(onChange).toHaveBeenCalledWith('Public library, Auckland');
    expect(JSON.parse(fetcher.mock.calls[0][1].body)).toEqual({
        q: 'Public library',
        client_id: 7,
    });
});

it('discards obsolete search results after editing and offers manual entry after failure', async () => {
    let resolve!: (value: ReturnType<typeof reply>) => void;
    const fetcher = vi
        .fn()
        .mockImplementationOnce(
            () =>
                new Promise((done) => {
                    resolve = done;
                }),
        )
        .mockResolvedValue({ ok: false, status: 503 });
    vi.stubGlobal('fetch', fetcher);
    const onChange = vi.fn();
    render(<TransportLocationField {...props} onChange={onChange} />);
    open();
    fireEvent.change(query(), { target: { value: 'Old address' } });
    fireEvent.click(
        screen.getByRole('button', { name: 'Search OpenStreetMap' }),
    );
    fireEvent.change(query(), { target: { value: 'New meeting point' } });
    expect(fetcher.mock.calls[0][1].signal.aborted).toBe(true);
    await act(async () => {
        resolve(reply('Obsolete address'));
    });
    expect(screen.queryByText('Obsolete address')).not.toBeInTheDocument();
    fireEvent.click(
        screen.getByRole('button', { name: 'Search OpenStreetMap' }),
    );
    expect(await screen.findByRole('status')).toHaveTextContent('unavailable');
    fireEvent.click(
        screen.getByRole('option', {
            name: 'Use entered location: New meeting point',
        }),
    );
    expect(onChange).toHaveBeenCalledWith('New meeting point');
});
