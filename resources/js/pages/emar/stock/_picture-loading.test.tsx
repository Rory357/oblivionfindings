import {
    cleanup,
    fireEvent,
    render,
    screen,
    waitFor,
    within,
} from '@testing-library/react';
import axios from 'axios';
import type { AnchorHTMLAttributes, ReactNode } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import StockHub from './StockHub';
import { stockMeters } from './__fixtures__/meters';
import type { ItemDetail, StockItem } from './_types';

vi.mock('@/layouts/app-layout', () => ({
    default: ({ children }: { children: ReactNode }) => <main>{children}</main>,
}));
vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: (props: AnchorHTMLAttributes<HTMLAnchorElement>) => <a {...props} />,
    usePage: () => ({ props: { auth: { can: { medications: {} } } } }),
    router: { get: vi.fn(), reload: vi.fn(), visit: vi.fn() },
}));

const items: StockItem[] = Array.from({ length: 25 }, (_, index) => ({
    id: index + 1,
    client_id: index + 1,
    site_id: 1,
    name: `Synthetic medicine ${index + 1}`,
    client_name: `Synthetic person ${index + 1}`,
    site_name: 'Synthetic house',
    controlled: false,
    active: true,
    stock_id: index + 1,
    on_hand: 20,
    physical_on_hand: 20,
    usable_on_hand: 20,
    unit: 'tablets',
    reorder_level: 5,
    last_counted_at: null,
    lots_started: true,
    pack_count: 0,
    next_batch: null,
    next_expiry: null,
    expired_packs: 0,
    state: 'ok',
    days_supply: null,
}));
const empty = { data: [], total: 0, links: [], last_page: 1 };
const detail: ItemDetail = { ...items[12], packs: [], outward: [] };

function showStock(view: 'stock' | 'expiring' = 'stock') {
    render(
        <StockHub
            items={{ data: items, total: 25, links: [], last_page: 1 }}
            orders={empty}
            counts={empty}
            movements={empty}
            sites={[]}
            pharmacies={[]}
            filters={{ view, search: '', site_id: null }}
            metrics={stockMeters({ tracked: 25 })}
            can={{ manage: false, receive: false, controlled: false }}
            lots_enabled
        />,
    );
}

beforeEach(() => {
    vi.spyOn(axios, 'get').mockResolvedValue({
        data: {
            status: 'matched',
            can_manage: false,
            medicine_version: 1,
            catalogue_configured: true,
            product: {
                id: 4,
                code_system: 'nzulm',
                code: '1',
                name: 'Synthetic medicine 13',
                strength: '5 mg',
                form: 'tablet',
                has_photo: true,
                photo_url: '/emar/catalogue/products/4/photo',
            },
            source: {
                source_name: 'Synthetic catalogue',
                source_version: '1',
                attribution: 'Synthetic',
                expires_at: '2027-01-01T00:00:00Z',
            },
        },
    });
    vi.stubGlobal(
        'fetch',
        vi.fn().mockResolvedValue({
            ok: true,
            json: async () => detail,
        }),
    );
});
afterEach(() => {
    cleanup();
    vi.restoreAllMocks();
    vi.unstubAllGlobals();
});

describe('Stock pictures load for the opened medicine', () => {
    // The approved Expiring view lists packs, not medicines (P06 1440-15).
    it.each(['stock'] as const)(
        '%s keeps 25 rows compact and fetches only the opened medicine picture',
        async (view) => {
            showStock(view);
            expect(
                within(screen.getByRole('table')).getAllByRole('row'),
            ).toHaveLength(26);
            expect(
                screen.queryByText('Reference image'),
            ).not.toBeInTheDocument();
            expect(axios.get).not.toHaveBeenCalled();
            expect(fetch).not.toHaveBeenCalled();

            fireEvent.click(
                within(screen.getByRole('table')).getByText(detail.name),
            );
            await screen.findByText('Reference image');
            expect(fetch).toHaveBeenCalledExactlyOnceWith(
                '/emar/stock/packs/medicine/13',
                expect.objectContaining({ credentials: 'same-origin' }),
            );
            expect(axios.get).toHaveBeenCalledExactlyOnceWith(
                '/emar/catalogue/medicines/13/binding',
                expect.objectContaining({ signal: expect.any(AbortSignal) }),
            );
            expect(
                await screen.findByText(
                    'Check the label — the picture is a guide only.',
                    {
                        exact: false,
                    },
                ),
            ).toBeVisible();
            const pictureSignal = vi.mocked(axios.get).mock.calls[0][1]?.signal;
            fireEvent.click(
                screen.getByText('Close', { selector: 'button', exact: true }),
            );
            await waitFor(() =>
                expect(screen.queryByRole('dialog')).not.toBeInTheDocument(),
            );
            expect(pictureSignal?.aborted).toBe(true);
            expect(axios.get).toHaveBeenCalledTimes(1);
        },
    );

    it('shows no catalogue UI when no catalogue is configured, even for a catalogue manager', async () => {
        vi.mocked(axios.get).mockResolvedValue({
            data: {
                status: 'unavailable',
                can_manage: true,
                medicine_version: 1,
                catalogue_configured: false,
                message:
                    'No package-label product has been confirmed for this medicine.',
            },
        });
        showStock();
        fireEvent.click(
            within(screen.getByRole('table')).getByText(detail.name),
        );
        await waitFor(() => expect(axios.get).toHaveBeenCalledTimes(1));
        await new Promise((resolve) => setTimeout(resolve, 0));
        expect(screen.queryByText('Reference image')).not.toBeInTheDocument();
        expect(
            screen.queryByText('Review product link'),
        ).not.toBeInTheDocument();
    });

    it('shows a configured but unbound catalogue only to someone who can link it', async () => {
        vi.mocked(axios.get).mockResolvedValue({
            data: {
                status: 'unavailable',
                can_manage: false,
                medicine_version: 1,
                catalogue_configured: true,
            },
        });
        showStock();
        fireEvent.click(
            within(screen.getByRole('table')).getByText(detail.name),
        );
        await waitFor(() => expect(axios.get).toHaveBeenCalledTimes(1));
        await new Promise((resolve) => setTimeout(resolve, 0));
        expect(screen.queryByText('Reference image')).not.toBeInTheDocument();
    });

    it('does not request a picture when the stock detail is denied', async () => {
        vi.mocked(fetch).mockResolvedValue({ ok: false } as Response);
        showStock();
        fireEvent.click(
            within(screen.getByRole('table')).getByText(detail.name),
        );
        await screen.findByText('We cannot show this record');
        expect(axios.get).not.toHaveBeenCalled();
        expect(screen.queryByText('Reference image')).not.toBeInTheDocument();
    });
});
