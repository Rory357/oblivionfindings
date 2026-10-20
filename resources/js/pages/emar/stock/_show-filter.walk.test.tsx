import { router } from '@inertiajs/react';
import {
    cleanup,
    fireEvent,
    render,
    screen,
    within,
} from '@testing-library/react';
import type { AnchorHTMLAttributes, ReactNode } from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import StockHub from './StockHub';
import { stockMeters } from './__fixtures__/meters';
import type { StockItem, SupplyOrder } from './_types';

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

afterEach(() => {
    cleanup();
    vi.clearAllMocks();
});

const row = (overrides: Partial<StockItem>): StockItem => ({
    id: 1,
    client_id: 1,
    site_id: 1,
    name: 'Cetirizine 10 mg tablet',
    client_name: 'Sam Tuilagi',
    site_name: 'Kōwhai House',
    controlled: false,
    active: true,
    receivable: true,
    stock_id: 1,
    on_hand: 20,
    usable_display: '20',
    physical_display: '20',
    reorder_display: null,
    unit: 'tablets',
    reorder_level: null,
    last_counted_at: null,
    lots_started: true,
    pack_count: 1,
    usable_packs: 1,
    next_batch: 'CT100',
    next_expiry: '2027-06-30',
    next_quantity: '20',
    next_expiry_in_days: 250,
    expired_packs: 0,
    expired_lots: [],
    state: 'ok',
    days_supply: null,
    open_order: null,
    count_to_sign_off: null,
    self_managed: false,
    ...overrides,
});
const late: SupplyOrder = {
    id: 52,
    client_medication_id: 2,
    medication_name: 'Losartan 50 mg tablet',
    client_name: 'Tama Walker',
    pharmacy_name: 'Kōwhai Pharmacy',
    status: 'submitted',
    controlled: false,
    quantity_ordered: 28,
    quantity_received: '0.00',
    quantity_dispensed: null,
    needed_by: null,
    expected_delivery: '2026-10-15',
    order_notes: null,
    communication_method: null,
    communication_reference: null,
    communication_recorded_at: null,
    closure_reason: null,
    batch_number: null,
    batch_expiry: null,
    overdue: true,
};
const empty = { data: [], total: 0, links: [], last_page: 1 };
const hub = (show: string, data: StockItem[]) =>
    render(
        <StockHub
            items={{ data, total: data.length, links: [], last_page: 1 }}
            orders={empty}
            counts={empty}
            movements={empty}
            sites={[{ id: 1, name: 'Kōwhai House' }]}
            pharmacies={[]}
            filters={{ view: 'stock', search: '', site_id: null, show }}
            metrics={stockMeters()}
            item_people={data.length}
            can={{ manage: true, receive: true, controlled: true }}
            lots_enabled
        />,
    );

// The approved P06 Show filter (1440-02, 1440-04; Main, 21 Oct).
describe('Stock hub › Show (P06)', () => {
    it('offers the approved choices', () => {
        hub('all', [row({})]);
        fireEvent.click(screen.getByRole('button', { name: 'Show' }));
        expect(
            [
                'All medicines',
                'Needs attention',
                'Running low or out',
                'Self-managed',
            ].map((label) => screen.getByRole('button', { name: label })),
        ).toHaveLength(4);
        fireEvent.click(
            screen.getByRole('button', { name: 'Needs attention' }),
        );
        expect(router.get).toHaveBeenLastCalledWith(
            '/emar/stock/packs',
            expect.objectContaining({ show: 'attention' }),
            expect.anything(),
        );
    });

    it('names a chosen view and lets it be cleared', () => {
        hub('attention', [
            row({ id: 2, name: 'Losartan 50 mg tablet', open_order: late }),
        ]);
        expect(
            screen.getByRole('heading', { name: 'Needs attention' }),
        ).toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: 'Clear Show' }));
        expect(router.get).toHaveBeenLastCalledWith(
            '/emar/stock/packs',
            expect.not.objectContaining({ show: expect.anything() }),
            expect.anything(),
        );
    });

    it('says when an order is overdue', () => {
        hub('attention', [
            row({ id: 2, name: 'Losartan 50 mg tablet', open_order: late }),
        ]);
        expect(
            screen.getByText(/^Delivery overdue — expected .+ \(#52\)$/),
        ).toBeInTheDocument();
    });

    it('marks a medicine the person manages themselves', () => {
        hub('self', [row({ self_managed: true })]);
        const table = screen.getByRole('table');
        expect(within(table).getByText('Self-managed')).toBeInTheDocument();
        expect(
            within(table).getByText(
                'Sam Tuilagi manages this medicine themselves',
            ),
        ).toBeInTheDocument();
    });
});
