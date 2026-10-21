import {
    cleanup,
    fireEvent,
    render,
    screen,
    waitFor,
    within,
} from '@testing-library/react';
import type { AnchorHTMLAttributes, ReactNode } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import StockHub from './StockHub';
import { stockMeters } from './__fixtures__/meters';
import type { ItemDetail, StockItem, SupplyOrder } from './_types';

vi.mock('@/layouts/app-layout', () => ({
    default: ({ children }: { children: ReactNode }) => <main>{children}</main>,
}));
vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: (props: AnchorHTMLAttributes<HTMLAnchorElement>) => <a {...props} />,
    usePage: () => ({
        url: '/emar/stock/packs',
        props: { auth: { can: { medications: {} } } },
    }),
    router: { get: vi.fn(), reload: vi.fn(), visit: vi.fn() },
}));
// The existing per-item receive dialog, unchanged: the picker only hands over.
vi.mock('./_receive', () => ({
    ReceiveWizard: ({
        item,
        order,
    }: {
        item: ItemDetail;
        order?: SupplyOrder;
    }) => (
        <div role="dialog" aria-label="Receive wizard">
            Receiving {item.name} {order ? `against order #${order.id}` : ''}
        </div>
    ),
}));

const medicine = (overrides: Partial<StockItem>): StockItem => ({
    id: 1,
    client_id: 1,
    site_id: 1,
    name: 'Synthetic medicine',
    client_name: 'Synthetic person',
    site_name: 'Kōwhai House',
    controlled: false,
    active: true,
    receivable: true,
    stock_id: 1,
    on_hand: 10,
    unit: 'tablets',
    reorder_level: null,
    last_counted_at: null,
    lots_started: true,
    pack_count: 1,
    next_batch: null,
    next_expiry: null,
    expired_packs: 0,
    state: 'ok',
    days_supply: null,
    ...overrides,
});
const order = (overrides: Partial<SupplyOrder>): SupplyOrder => ({
    id: 31,
    client_medication_id: 7,
    medication_name: 'Losartan 50 mg tablet',
    client_name: 'Aroha Ngata',
    pharmacy_name: 'Synthetic pharmacy',
    status: 'dispensed',
    controlled: false,
    quantity_ordered: 28,
    quantity_received: '0.00',
    quantity_dispensed: null,
    needed_by: null,
    expected_delivery: '2026-10-21',
    order_notes: null,
    communication_method: null,
    communication_reference: null,
    communication_recorded_at: null,
    closure_reason: null,
    batch_number: null,
    batch_expiry: null,
    ...overrides,
});
const choices = {
    orders: [order({})],
    medicines: [
        medicine({ id: 8, name: 'Metformin 500 mg tablet' }),
        medicine({
            id: 9,
            name: 'Old stock record',
            lots_started: false,
            stock_id: 4,
        }),
    ],
    more: false,
};
const empty = { data: [], total: 0, links: [], last_page: 1 };
const urls: string[] = [];

beforeEach(() => {
    urls.length = 0;
    vi.stubGlobal(
        'fetch',
        vi.fn(async (url: string) => {
            urls.push(url);
            const id = /medicine\/(\d+)/.exec(url)?.[1];
            return {
                ok: true,
                json: async () =>
                    id
                        ? {
                              ...medicine({
                                  id: Number(id),
                                  name:
                                      id === '7'
                                          ? 'Losartan 50 mg tablet'
                                          : 'Metformin 500 mg tablet',
                              }),
                              packs: [],
                              outward: [],
                          }
                        : choices,
            };
        }),
    );
});
afterEach(() => {
    cleanup();
    vi.unstubAllGlobals();
});

const hub = (receive: boolean, siteId: number | null = 2) =>
    render(
        <StockHub
            items={empty}
            orders={empty}
            counts={empty}
            movements={empty}
            sites={[
                { id: 1, name: 'Kōwhai House' },
                { id: 2, name: 'Rimu House' },
            ]}
            pharmacies={[]}
            filters={{ view: 'stock', search: '', site_id: siteId }}
            metrics={stockMeters()}
            can={{ manage: receive, receive, controlled: false }}
            lots_enabled
        />,
    );

describe('Stock hub › Receive a delivery (P06 header)', () => {
    it('is offered only to people who can receive', () => {
        hub(false);
        expect(
            screen.queryByRole('button', { name: 'Receive a delivery' }),
        ).toBeNull();
    });

    it('lists deliveries due first, then other medicines at the chosen house', async () => {
        hub(true);
        fireEvent.click(
            screen.getByRole('button', { name: 'Receive a delivery' }),
        );
        const picker = await screen.findByRole('dialog', {
            name: 'Receive a delivery',
        });
        await within(picker).findByText(
            'Losartan 50 mg tablet for Aroha Ngata',
        );
        expect(urls[0]).toBe('/emar/stock/packs/receive-choices?site_id=2');
        const options = within(picker).getAllByRole('option');
        expect(options.map((option) => option.textContent)).toEqual([
            expect.stringContaining('Losartan 50 mg tablet for Aroha Ngata'),
            expect.stringContaining('Metformin 500 mg tablet'),
            expect.stringContaining('Old stock record'),
        ]);
        expect(within(picker).getByText('Due from the pharmacy')).toBeVisible();
        expect(within(picker).getByText('Other medicines')).toBeVisible();
        // The same rule as the row menu: no receipt before packs are set up.
        expect(options[2]).toHaveAttribute('aria-disabled', 'true');
        expect(options[2]).toHaveTextContent(
            'Pack tracking has not been set up.',
        );
    });

    it('hands a due delivery to the existing receive dialog with its order', async () => {
        hub(true);
        fireEvent.click(
            screen.getByRole('button', { name: 'Receive a delivery' }),
        );
        const picker = await screen.findByRole('dialog', {
            name: 'Receive a delivery',
        });
        fireEvent.click(
            await within(picker).findByText(
                'Losartan 50 mg tablet for Aroha Ngata',
            ),
        );
        expect(
            await screen.findByText(
                'Receiving Losartan 50 mg tablet against order #31',
            ),
        ).toBeInTheDocument();
        expect(urls).toContain('/emar/stock/packs/medicine/7');
    });

    it('hands another medicine to the receive dialog without an order', async () => {
        hub(true, null);
        fireEvent.click(
            screen.getByRole('button', { name: 'Receive a delivery' }),
        );
        const picker = await screen.findByRole('dialog', {
            name: 'Receive a delivery',
        });
        await waitFor(() =>
            expect(urls[0]).toBe('/emar/stock/packs/receive-choices'),
        );
        fireEvent.click(
            await within(picker).findByText('Metformin 500 mg tablet'),
        );
        expect(
            await screen.findByText('Receiving Metformin 500 mg tablet'),
        ).toBeInTheDocument();
    });
});
